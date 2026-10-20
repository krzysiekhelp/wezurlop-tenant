<?php

declare(strict_types=1);

namespace Wezurlop\Controller\App;

use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\AccountRepository;
use Wezurlop\Repository\EventLogRepository;
use Wezurlop\Support\KodyZdarzen;
use Wezurlop\Support\View;
use Wezurlop\Support\ZdanieDostepu;
use Wezurlop\Support\ZdanieZdarzenia;

/**
 * Dziennik zdarzeń — ekran audytowy (etap 6).
 *
 * ⚠ **To najbardziej wrażliwy ekran w aplikacji**: widać na nim, kto i kiedy dotykał czyich
 * danych kadrowych. Dlatego:
 *  - trasa ma uprawnienie `admin.event_log` sprawdzane przez `Authorize` **na każdym żądaniu**
 *    (CLAUDE.md §10), a nie tylko pozycję w menu ukrytą przez `can()`,
 *  - ⚠ ekran **nie ma endpointu fragmentowego**: stronicowanie i filtr to zwykłe `GET`
 *    z parametrami, więc nie istnieje druga droga do tych danych, którą trzeba by pilnować
 *    osobno („nie istnieje endpoint wewnętrzny, bo używa go tylko htmx" — §5),
 *  - do szablonu nie idzie `ip_hash` ani nic, czego ekran nie pokazuje (§3).
 *
 * ⚠ Filtr przyjmuje **wyłącznie** identyfikator konta z listy kont obecnych w dzienniku
 * (biała lista, §11) — wartość spoza niej znaczy „wszystkie", a nie „błąd": adres wklejony
 * po usunięciu konta ma pokazać dziennik, nie ekran awarii.
 */
final class EventLogController
{
    /** Wiersze na stronę — tyle samo, co lista pracowników z etapu 1. */
    private const PAGE = 50;

    public function __construct(
        private readonly View $view,
        private readonly EventLogRepository $events,
        private readonly AccountRepository $accounts,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $konta = $this->events->accountsInLog();
        $konto = $this->wybraneKonto($request, $konta);
        $osoba = $konto === null ? null : ($this->accounts->findById($konto)['person_id'] ?? null);
        // ⚠ Rodzaj z adresu jest TYLKO kluczem do białej listy w `KodyZdarzen` (§11): prefiksy
        // kodów pochodzą z kodu, nie z żądania, a klucz spoza listy znaczy „wszystkie".
        $rodzaj = is_string($request->query('rodzaj')) ? (string) $request->query('rodzaj') : null;
        $prefiksy = KodyZdarzen::prefiksyRodzaju($rodzaj);
        $rodzaj = $prefiksy === [] ? null : $rodzaj;

        $razem = $this->events->countPage($konto, $osoba === null ? null : (int) $osoba, $prefiksy);
        $stron = max(1, (int) ceil($razem / self::PAGE));
        $numer = filter_var($request->query('strona'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $stron]]);
        $numer = $numer === false ? 1 : $numer;
        $offset = ($numer - 1) * self::PAGE;

        $wpisy = $this->events->page($konto, $osoba === null ? null : (int) $osoba, self::PAGE, $offset, $prefiksy);
        $wpisy = $this->zNazwamiRegul($wpisy);

        return $this->view->render('page/web/event-log.twig', [
            'wpisy'  => array_map(self::opis(...), $wpisy),
            'konta'  => $konta,
            'konto'  => $konto,
            'rodzaj' => $rodzaj,
            'rodzaje' => KodyZdarzen::rodzaje(),
            'strona' => ['numer' => $numer, 'stron' => $stron, 'offset' => $offset, 'razem' => $razem],
        ]);
    }

    /**
     * Etykieta i waga kodu dokładane do wiersza — szablon nie liczy niczego sam (CLAUDE.md §5).
     *
     * ⚠ „Kto": osoba z konta, a gdy konta nie ma — `actor_label` (`sync`, `narzedzie`).
     * Gdy nie udaje się ani jedno, ani drugie, wychodzi **`konto #15 — nieznane`**, nigdy pusta
     * komórka: pusta komórka w audycie wygląda jak brak zdarzenia, a nie jak brak nazwy.
     *
     * @param array<string, mixed> $wpis
     * @return array<string, mixed>
     */
    private static function opis(array $wpis): array
    {
        $kod = (string) $wpis['kod'];
        $kto = $wpis['aktor'] ?? $wpis['aktor_etykieta'];
        if ($kto === null && $wpis['konto_id'] !== null) {
            $kto = 'konto #' . $wpis['konto_id'] . ' — nieznane';
        }

        // Zdanie, ikona i barwa kółka (Z36) — szablon dostaje gotowe części, bez składania treści.
        $zdanie = ZdanieZdarzenia::zbuduj($wpis);

        return $wpis + [
            'etykieta' => KodyZdarzen::etykieta($kod),
            'waga'     => KodyZdarzen::waga($kod),
            'kto'      => $kto,
            'kto_konto' => $wpis['aktor'] !== null ? $wpis['konto_id'] : null,
            'zdanie'   => $zdanie,
        ];
    }

    /**
     * **Nazwy w zdaniach o regułach widoczności** — składane przy ODCZYCIE (zlecenie Z57 U2).
     *
     * ⚠ W treści wpisu stoją identyfikatory (`account:4 › persons:12,15`) i tak ma zostać:
     * para „kto widzi czyje nieobecności" opisuje dostęp do danych o zdrowiu, więc nazwisk
     * nie zapisujemy do tabeli, z której się ich nie kasuje (Z53 §5, CLAUDE.md §18). Ekran
     * dokłada nazwy tutaj, z bazy, i dlatego usunięcie konta zmienia zdanie na „konto #4 —
     * usunięte", a nie wymazuje wpis.
     *
     * ⚠ Trzy zapytania na stronę dziennika, nie trzy na wiersz — i tylko wtedy, gdy na stronie
     * jest choć jeden wpis z tej rodziny.
     *
     * @param list<array<string, mixed>> $wpisy
     * @return list<array<string, mixed>>
     */
    private function zNazwamiRegul(array $wpisy): array
    {
        $ids = ZdanieDostepu::identyfikatory($wpisy);
        if ($ids === ['konta' => [], 'etaty' => [], 'grupy' => []]) {
            return $wpisy;
        }
        $nazwy = $this->events->nazwyDoZdan($ids['konta'], $ids['etaty'], $ids['grupy']);

        return array_map(static function (array $wpis) use ($nazwy): array {
            if (!ZdanieDostepu::dotyczy((string) $wpis['kod'])) {
                return $wpis;
            }
            $detale = is_array($wpis['detale'] ?? null) ? $wpis['detale'] : [];
            // ⚠ Nazwy NADPISUJĄ identyfikatory w wartościach zdania, a nie dokładają się obok:
            // zdanie ma jeden zestaw kluczy, a surowa treść wpisu zostaje w `title` wiersza
            // (`szczegoly`), czyli w miejscu, w którym audyt jej szuka.
            $wpis['detale'] = ZdanieDostepu::wartosci($detale, $nazwy) + $detale;

            return $wpis;
        }, $wpisy);
    }

    /**
     * @param list<array{id: int, nazwa: string}> $konta
     */
    private function wybraneKonto(Request $request, array $konta): ?int
    {
        $wartosc = filter_var($request->query('konto'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($wartosc === false) {
            return null;
        }
        foreach ($konta as $k) {
            if ($k['id'] === $wartosc) {
                return $wartosc;
            }
        }

        return null;
    }
}
