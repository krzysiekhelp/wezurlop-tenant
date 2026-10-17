<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Db\Connection;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Repository\PermissionRepository;

/**
 * Poziom widoczności cudzych kalendarzy — **jedno miejsce, w którym ta liczba powstaje**
 * (`docs/etap3-logika-urlopowa.md` §8b, decyzja Krzysztofa 23.09.2026).
 *
 * ```
 * 0 — nie widzi        brak uprawnień (DOMYŚLNY, także dla administratora tenanta)
 * 1 — rodzaj ogólny    calendar.view_all
 * 2 — całość           calendar.view_all + calendar.view_all_details
 * ```
 *
 * ⚠ **Kod sprawdza UPRAWNIENIE, nigdy nazwę roli.** To cała cena za to, że rola kadrowa
 * z Etapu 3 dostanie te same kody i nie trzeba będzie niczego scalać ręcznie. Jedno
 * porównanie do nazwy roli gdziekolwiek w aplikacji i ta obietnica przepada — dlatego
 * nazwy ról żyją wyłącznie w migracji i w `AdminAccountController` (tam, gdzie się NADAJE),
 * a wszystkie decyzje o widoczności czytają `poziom()`.
 *
 * ⚠ **Poziom liczy jedna funkcja, a nie trzy `if`-y po kontrolerach.** Gdyby ktoś miał obie role
 * albo samo `..._details` (np. po ręcznym grzebaniu w bazie), wynik musi być określony w tym
 * jednym miejscu. Po ostatnich turach wiadomo, czym kończą się dwa miejsca decydujące o tej
 * samej rzeczy: „pusta lista” znaczyła dwie różne rzeczy w dwóch drogach i zostawiała na ekranie
 * wiersz, którego nikt nie zaznaczył.
 *
 * ⚠ **Poziom i zasięg to dwie niezależne osie.** Tu jest wyłącznie **szczegółowość** (czy widać
 * nazwę rodzaju ze słownika). Kogo widać — rozstrzyga `ZasiegPodgladu`. Zlanie ich dałoby
 * przełożonego, który widzi szczegół, bo ma szeroki zasięg.
 */
final class CalendarVisibility
{
    /** Widzi tylko siebie i swoich podwładnych, jak każdy. */
    public const POZIOM_BRAK = 0;

    /** Widzi całą spółkę, ale wyłącznie „urlop" / „zwolnienie" / „inna nieobecność". */
    public const POZIOM_OGOLNY = 1;

    /** Widzi całą spółkę z nazwą rodzaju ze słownika — dane o zdrowiu. */
    public const POZIOM_PELNY = 2;

    public const UPRAWNIENIE_WIDOK = 'calendar.view_all';
    public const UPRAWNIENIE_SZCZEGOL = 'calendar.view_all_details';

    /**
     * Role systemowe nadające poziomy. ⚠ Nazwy stoją tu **wyłącznie po to, żeby nadawać** —
     * do pytania „co ten człowiek widzi" służy `poziom()`, które patrzy na uprawnienia.
     *
     * @var array<int, string>
     */
    public const ROLE_POZIOMOW = [
        self::POZIOM_OGOLNY => 'calendar_viewer',
        self::POZIOM_PELNY => 'calendar_viewer_full',
    ];

    public function __construct(
        private readonly PermissionRepository $permissions,
        private readonly Connection $db,
        private readonly EventLogRepository $events,
    ) {
    }

    /**
     * Ustawienie poziomu dla pary (konto, zakres) — **jedna transakcja, jeden wpis w dzienniku**.
     *
     * ⚠ Najpierw odbieramy OBIE role, potem nadajemy właściwą. Bez tego konto z rolą „ogólną",
     * któremu podniesiono poziom, miałoby dwa przypisania — a wtedy odebranie „pełnej" cofałoby
     * do „ogólnej" po cichu, czyli wybór trójstanowy zaczynałby mieć pamięć, o której nikt nie wie.
     *
     * ⚠ Nadanie i odebranie **idą do dziennika zdarzeń z osobą wykonującą** — jak reset 2FA przez
     * administratora (`etap3` §8b). Sam PODGLĄD kalendarza nie jest logowany: dziennik zapełniłby
     * się przewijaniem miesięcy i przestałby być czytelny (rozważone i odrzucone w zleceniu).
     *
     * @return array{poziom: int, przed: int} stan po zmianie i przed nią
     */
    public function ustaw(int $accountId, int $companyId, int $poziom, ?int $przez, ?string $aktor): array
    {
        $przed = $this->poziomNadany($accountId, $companyId);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach (self::ROLE_POZIOMOW as $rola) {
                $this->permissions->revokeRole($accountId, $rola, $companyId);
            }
            if (isset(self::ROLE_POZIOMOW[$poziom])) {
                $this->permissions->assignRole($accountId, self::ROLE_POZIOMOW[$poziom], $companyId, $przez);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        // ⚠ Kod zdarzenia zależy od KIERUNKU zmiany, nie od tego, którą rolę ruszyliśmy:
        // przy audycie pyta się „kto komu nadał uprawnienie", nie „która rola zmieniła stan".
        // ⚠ W zapisie są **sprawca i odbiorca**, także gdy to ta sama osoba (aneks 23.09 §4):
        // `account_id` to ten, kto nadał, `subject_id` — komu. Pierwsze nadanie sobie samemu
        // jest właśnie tym wpisem, dla którego ten dziennik istnieje.
        $this->events->record(
            $poziom > self::POZIOM_BRAK ? 'permission.granted' : 'permission.revoked',
            $przez,
            null,
            $aktor,
            'account',
            $accountId,
            $companyId > 0 ? $companyId : null,
            [
                // CZEGO dotyczy nadanie — rodzina zdarzeń jest ogólna, więc to musi być w treści.
                'uprawnienie' => 'kalendarz',
                'poziom' => $poziom,
                'poziom_przed' => $przed,
                'zakres' => $companyId === 0 ? 'tenant' : 'spolka',
            ],
        );

        return ['poziom' => $poziom, 'przed' => $przed];
    }

    /**
     * Poziom konta w konkretnej spółce — czytany z bazy przy każdym żądaniu, więc odebranie
     * uprawnienia działa natychmiast (CLAUDE.md §8).
     */
    public function poziomKonta(int $accountId, int $companyId): int
    {
        return self::poziom($this->permissions->permissionsFor($accountId, $companyId));
    }

    /**
     * Spółki, w których to konto ma poziom ≥ 1 — **biała lista** dla konta bez zatrudnienia.
     *
     * ⚠ Kontroler pyta tutaj, a nie o kod uprawnienia: gdyby znał `calendar.view_all`, ten kod
     * byłby w dwóch miejscach, a przy dodaniu roli kadrowej trzeba by pamiętać o obu.
     *
     * @return list<array{id: int, nazwa: string}>
     */
    public function spolkiZPoziomem(int $accountId): array
    {
        return $this->permissions->companiesWithPermission($accountId, self::UPRAWNIENIE_WIDOK);
    }

    /**
     * Poziom **nadany dokładnie w tym zakresie** — do kontrolki nadawania, nie do decyzji
     * o widoczności. Patrz `PermissionRepository::permissionsAssignedAt()`.
     */
    public function poziomNadany(int $accountId, int $companyId): int
    {
        return self::poziom($this->permissions->permissionsAssignedAt($accountId, $companyId));
    }

    /**
     * ⚠ **Ta jedna funkcja rozstrzyga poziom.** Czysta i statyczna, żeby dała się sprawdzić
     * testem na wszystkich kombinacjach, w tym na niemożliwych: samo `..._details` bez podstawy
     * to **poziom 0**, a nie 2 — szczegół bez prawa wglądu w ogóle nie jest szczegółem, na który
     * wolno patrzeć.
     *
     * @param list<string> $uprawnienia kody uprawnień konta w tej spółce
     */
    public static function poziom(array $uprawnienia): int
    {
        $widok = in_array(self::UPRAWNIENIE_WIDOK, $uprawnienia, true);
        if (!$widok) {
            return self::POZIOM_BRAK;
        }

        return in_array(self::UPRAWNIENIE_SZCZEGOL, $uprawnienia, true)
            ? self::POZIOM_PELNY
            : self::POZIOM_OGOLNY;
    }

    /** Czy poziom pozwala oglądać kalendarz całej spółki (zasięg „Firma"). */
    public static function widziSpolke(int $poziom): bool
    {
        return $poziom >= self::POZIOM_OGOLNY;
    }

    /** Czy poziom pozwala zobaczyć nazwę rodzaju ze słownika przy cudzej nieobecności. */
    public static function widziRodzaj(int $poziom): bool
    {
        return $poziom >= self::POZIOM_PELNY;
    }

    /**
     * Nazwa poziomu dla człowieka — w kontrolce nadawania i w zdaniu na kalendarzu.
     * ⚠ Trójstanowy wybór, nie „przypisanie roli": człowiek nie ma znać naszych tabel.
     *
     * @return array<int, string>
     */
    public static function nazwy(): array
    {
        return [
            self::POZIOM_BRAK => 'nie widzi',
            self::POZIOM_OGOLNY => 'rodzaj ogólny',
            self::POZIOM_PELNY => 'pełny szczegół',
        ];
    }

    /**
     * Opis poziomu do kontrolki — jedno zdanie, co ten wybór naprawdę otwiera.
     *
     * @return array<int, string>
     */
    public static function opisy(): array
    {
        return [
            self::POZIOM_BRAK => 'Widzi siebie i swoich podwładnych — tak jak każdy pracownik.',
            self::POZIOM_OGOLNY => 'Widzi kalendarz całej firmy: kto i kiedy, bez nazwy rodzaju '
                . '(„urlop", „zwolnienie", „inna nieobecność").',
            self::POZIOM_PELNY => 'Widzi kalendarz całej firmy z nazwami rodzajów ze słownika — '
                . 'w tym rodzajami zwolnień, czyli danymi o zdrowiu. Wymaga logowania dwuskładnikowego.',
        ];
    }
}
