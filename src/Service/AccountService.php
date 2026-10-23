<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Db\Connection;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EventLogRepository;

/**
 * Blokada i odblokowanie konta — **jedyne miejsce, w którym ta decyzja jest podejmowana**
 * (zlecenie `ostatni-administrator-i-przyciski-zapisz` §2).
 *
 * ⚠ Dwie reguły, bez których tenant traci zarządzanie, i obie muszą stać tutaj, a nie
 * w kontrolerze:
 *  1. **nie wolno zablokować konta, na którym się jest zalogowanym** — administrator wylatuje
 *     z aplikacji w trakcie operacji i nie ma jak wrócić,
 *  2. **nie wolno zablokować ostatniego AKTYWNEGO administratora tenanta** — inaczej tenant
 *     zostaje bez zarządzania i jedyną drogą powrotu jest telefon do dostawcy.
 *
 * ⚠ **Konto `invited` i `niezaproszone` NIE liczy się jako zabezpieczenie.** Nie ustawiło hasła
 * i może tego nigdy nie zrobić, a wiadomość mogła nie dojść — dziś na pewno nie doszła, bo
 * poczta jeszcze nie wychodzi (etap 7.2). Zaproszony administrator, który nie aktywował konta,
 * to zabezpieczenie, którego nie ma.
 *
 * ⚠ Sprawdzenie i zapis są JEDNĄ transakcją, a liczba administratorów czytana pod `FOR UPDATE`:
 * bez tego dwóch administratorów blokujących się nawzajem w tej samej sekundzie zostawiłoby
 * zero. Ta sama pułapka wyścigu co przy limicie licencji.
 *
 * ⚠ Wpis w dzienniku idzie także przy **odrzuconej** próbie: próba pozbawienia tenanta
 * zarządzania jest zdarzeniem, o którym chce się wiedzieć.
 */
final class AccountService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountRepository $accounts,
        private readonly EventLogRepository $events,
        // ⚠ Blokada konta przelicza reguły widoczności (Z80 B3, droga 5): zablokowane konto
        // przestaje być kandydatem na podmiot, więc jego reguła nie daje już nic.
        private readonly WidocznoscService $widocznosc,
    ) {
    }

    public const POWOD_SIEBIE = 'nie można zablokować konta, na którym jesteś zalogowany';
    /**
     * ⚠ **Ta reguła broni PRZYSZŁEJ roli albo drogi API, nie dzisiejszego ekranu — i dlatego
     * zostaje** (zmierzone 20.10.2026, Z63 część E, na bazie testowej).
     *
     * Uprawnienie `admin.users` ma dziś **wyłącznie rola `tenant_admin`**. Czyli każdy, kto
     * w ogóle może zablokować konto, sam jest aktywnym administratorem — a wtedy aktywnych
     * administratorów jest co najmniej dwóch (on i cel) albo celem jest on sam, i wtedy
     * rozstrzyga `POWOD_SIEBIE`, bo stoi wyżej. **Z interfejsu nie da się więc dojść do tego
     * powodu.** Zmierzone wprost: jeden aktywny administrator + wywołanie z konta bez roli
     * administracyjnej → `blockRefusal()` i `block()` odmawiają tym powodem, a konto zostaje
     * `active`.
     *
     * ⚠ Nie kasować jako „nieużywanej": zadziała w dniu, w którym `admin.users` dostanie druga
     * rola (kadry, administrator firmowy) albo blokada pójdzie ścieżką API — czyli wtedy, gdy
     * nikt nie będzie pamiętał, że tego warunku brakuje.
     */
    public const POWOD_OSTATNI_ADMIN = 'to jedyne aktywne konto administratora tenanta';
    public const POWOD_NIE_MA = 'konta nie ma albo jest już w tym stanie';

    /**
     * Blokada konta przez człowieka.
     *
     * @return string|null powód odmowy albo null, gdy zablokowano
     */
    public function block(int $accountId, int $byAccountId, ?string $ipHash, ?string $actorLabel): ?string
    {
        // ⚠ Sprawdzenie „czy to ja" jest przed transakcją, bo nie zależy od stanu bazy —
        // i jest to jedyna reguła, którą można rozstrzygnąć bez blokady wiersza.
        if ($accountId === $byAccountId) {
            $this->odmowa('account.block_refused', $accountId, $byAccountId, $ipHash, $actorLabel, self::POWOD_SIEBIE);

            return self::POWOD_SIEBIE;
        }

        $sesje = 0;
        $powod = $this->db->transaction(function () use ($accountId, &$sesje): ?string {
            // ⚠ Liczymy administratorów POD BLOKADĄ i dopiero wtedy decydujemy. Kolejność
            // odwrotna (policz, potem zapisz) to wyścig, w którym dwie równoległe blokady
            // widzą ten sam stan „jest jeszcze dwóch" i obie przechodzą.
            $aktywni = $this->accounts->activeTenantAdminIdsForUpdate();
            if (in_array($accountId, $aktywni, true) && count($aktywni) <= 1) {
                return self::POWOD_OSTATNI_ADMIN;
            }
            $uciete = $this->accounts->blockManually($accountId);
            $sesje = $uciete ?? 0;

            return $uciete === null ? self::POWOD_NIE_MA : null;
        });

        if ($powod !== null) {
            $this->odmowa('account.block_refused', $accountId, $byAccountId, $ipHash, $actorLabel, $powod);

            return $powod;
        }
        // ⚠ Kto blokował, zapisujemy PO zatwierdzeniu transakcji — dziennik nie może opisywać
        // zmiany, która się wycofała.
        $this->accounts->setBlockedBy($accountId, $byAccountId);
        $this->events->record('account.blocked_manually', $byAccountId, $ipHash, $actorLabel, 'account', $accountId);
        // ⚠ Osobny wpis o przerwanej pracy: `account.blocked_manually` mówi, że konto zablokowano,
        // ale nie mówi, że komuś zabrano ekran w środku wniosku — a to jest pierwsze pytanie
        // zablokowanego („dlaczego wyleciałem?"). Jeden wpis z licznikiem, nie jeden na sesję.
        if ($sesje > 0) {
            $this->events->record('session.revoked', $byAccountId, $ipHash, $actorLabel, 'account', $accountId, null,
                ['count' => $sesje, 'powod' => 'blokada', 'zrodlo' => 'reczna']);
        }
        // ⚠ **Przeliczenie PO zatwierdzeniu blokady, nie w jej transakcji** (Z80 B3, droga 5).
        // Blokada ma się udać także wtedy, gdy przeliczanie reguł padnie: odebranie dostępu
        // zablokowanemu kontu jest ważniejsze od statusu reguły, a sama blokada i tak odcina
        // wgląd natychmiast (`regulyWidza()` pyta o kandydaturę przy każdym odczycie).
        $this->widocznosc->przeliczDlaKonta($accountId, $byAccountId, $actorLabel);

        return null;
    }

    /**
     * Odblokowanie konta przez człowieka — wraca stan sprzed blokady.
     *
     * ⚠ Człowiek może zdjąć także blokadę założoną przez synchronizację (wie np., że umowa
     * wraca od poniedziałku, a R2 jeszcze tego nie przysłało). Odwrotnie nie: automat nie cofa
     * decyzji człowieka.
     *
     * @return string|null powód odmowy albo null, gdy odblokowano
     */
    public function unblock(int $accountId, int $byAccountId, ?string $ipHash, ?string $actorLabel): ?string
    {
        if (!$this->accounts->unblockManually($accountId)) {
            return self::POWOD_NIE_MA;
        }
        $this->events->record('account.unblocked_manually', $byAccountId, $ipHash, $actorLabel, 'account', $accountId);

        return null;
    }

    /**
     * Czy to konto wolno zablokować — **do pytania z interfejsu**, żeby przycisk i ekran
     * potwierdzenia mówiły prawdę, zanim ktoś kliknie.
     *
     * ⚠ To nie jest zabezpieczenie, tylko ta sama reguła zadana pytająco: decyduje `block()`,
     * w transakcji. Interfejs, który rysuje czynny przycisk i dopiero `POST` mówi „nie wolno",
     * to atrapa — a przy operacji bez drogi powrotu atrapa szczególnie kosztowna.
     *
     * @return string|null powód, dla którego nie wolno, albo null gdy wolno
     */
    public function blockRefusal(int $accountId, int $byAccountId): ?string
    {
        if ($accountId === $byAccountId) {
            return self::POWOD_SIEBIE;
        }
        $aktywni = $this->accounts->activeTenantAdminIds();

        return in_array($accountId, $aktywni, true) && count($aktywni) <= 1
            ? self::POWOD_OSTATNI_ADMIN
            : null;
    }

    private function odmowa(string $kod, int $accountId, int $byAccountId, ?string $ipHash, ?string $actorLabel, string $powod): void
    {
        $this->events->record($kod, $byAccountId, $ipHash, $actorLabel, 'account', $accountId, null, ['powod' => $powod]);
    }
}
