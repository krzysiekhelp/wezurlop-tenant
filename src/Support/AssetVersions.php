<?php

declare(strict_types=1);

namespace Wezurlop\Support;

/**
 * Wersja zasobu w adresie (`?v=…`) — **wyliczana z pliku, nie wpisywana przez człowieka**.
 *
 * ⚠ Powód jest zmierzony i kosztował dwa dni nieporozumienia: `.env` miał
 * `ASSET_VERSION=20260917`, czyli datę wpisaną ręcznie. Przez pięć dni przeszły przez arkusze
 * tokeny, `page__head`, zebra, wysokości kontrolek i `[hidden] { display: none !important }`,
 * a adres `?v=20260917` nie zmienił się ani razu. Skutek: przeglądarka, która raz pobrała plik,
 * **nie pobierała go ponownie** — pomiar świeżą przeglądarką (Playwright) pokazywał poprawiony
 * stan, a przeglądarka pracująca od rana pokazywała stary arkusz z nowym HTML-em.
 * **Oba pomiary były poprawne; patrzyły na dwie różne wersje arkusza.**
 *
 * ⚠ U klienta to ten sam błąd z gorszym objawem: po aktualizacji część firmy widzi stary wygląd
 * z nowym HTML-em, część nowy — zależnie od tego, kto miał otwartą kartę. Zgłasza się to jako
 * „wszystko się rozjechało" i nie da się odtworzyć u siebie. W paczce kodowanej Encoderem
 * nie da się tego poprawić na serwerze klienta.
 *
 * Trzy źródła wersji, w tej kolejności:
 *  1. **`filemtime()`** — gdy `APP_ENV=dev`. Zmiana pliku = nowy adres w tej samej sekundzie,
 *     bez pamiętania o czymkolwiek.
 *  2. **manifest `wersje.json`** — skrót treści per plik, zapisywany przy budowaniu paczki.
 *     Nowy adres dostają **tylko zmienione** pliki, więc aktualizacja nie unieważnia całego
 *     cache'u klienta.
 *  3. **`ASSET_VERSION` z konfiguracji** — ścieżka zapasowa dla instalacji bez manifestu
 *     (np. sprzed tej zmiany). Lepsza niż brak wersji, ale nadal jedna liczba na wszystko.
 *
 * ⚠ Klasa nie rzuca wyjątków i nie loguje: brak pliku albo nieczytelny manifest ma dać
 * **działający adres**, a nie biały ekran. Zasób bez wersji i tak zadziała — najwyżej
 * z cache'u przeglądarki.
 */
final class AssetVersions
{
    /** Nazwa manifestu w katalogu zasobów; sam siebie nie wersjonuje. */
    public const MANIFEST = 'wersje.json';

    /**
     * Klucz z **numerem wydania** w manifeście.
     *
     * ⚠ Zasób, którego w manifeście nie ma, dostaje numer wydania — nie stały napis
     * (rozstrzygnięcie Krzysztofa 22.09.2026). Stały napis znaczy adres, który **nigdy się
     * nie zmienia**, czyli plik zamrożony w przeglądarce klienta na zawsze; a w paczce
     * kodowanej Encoderem nie naprawimy tego zdalnie. Numer wydania sprawia, że nawet
     * przeoczony zasób odświeża się przy każdej aktualizacji.
     */
    public const KLUCZ_WYDANIA = '_wydanie';

    /** @var array<string, string> policzone wersje w tym żądaniu */
    private array $pamiec = [];

    /** @var array<string, string>|null manifest wczytany leniwie; [] = wczytany i pusty */
    private ?array $manifest = null;

    /** Numer wydania z manifestu — wersja zapasowa dla zasobu spoza manifestu. */
    private ?string $wydanie = null;

    public function __construct(
        private readonly string $assetsPath,
        private readonly string $zapasowa,
        private readonly bool $zPliku,
    ) {
    }

    public function dla(string $sciezka): string
    {
        $sciezka = ltrim($sciezka, '/');
        if (isset($this->pamiec[$sciezka])) {
            return $this->pamiec[$sciezka];
        }

        return $this->pamiec[$sciezka] = $this->policz($sciezka);
    }

    private function policz(string $sciezka): string
    {
        // ⚠ Ścieżka pochodzi z szablonu, nie z żądania, ale sprawdzamy ją tak samo:
        // `..` w nazwie zasobu nie ma prawa wyprowadzić poza katalog zasobów (CLAUDE.md §11).
        if ($sciezka === '' || str_contains($sciezka, '..')) {
            return $this->zapasowa;
        }
        if ($this->zPliku) {
            $czas = @filemtime($this->assetsPath . '/' . $sciezka);
            if ($czas !== false) {
                return (string) $czas;
            }
        }
        $manifest = $this->manifest();
        if (isset($manifest[$sciezka])) {
            return $manifest[$sciezka];
        }

        // ⚠ Kolejność zapasowa: numer wydania z manifestu, a dopiero potem `ASSET_VERSION`
        // z konfiguracji (instalacje sprzed manifestu). Nigdy stały napis.
        return $this->wydanie ?? $this->zapasowa;
    }

    /** @return array<string, string> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        $plik = $this->assetsPath . '/' . self::MANIFEST;
        if (!is_file($plik)) {
            return $this->manifest = [];
        }
        $tresc = @file_get_contents($plik);
        // Manifest jest nasz, ale czytamy go jak dane obce: `json_validate()` (PHP 8.3) i tylko
        // pary tekst => tekst. Uszkodzony plik ma dać wersję zapasową, nie wyjątek w widoku.
        if (!is_string($tresc) || !json_validate($tresc)) {
            return $this->manifest = [];
        }
        $dane = json_decode($tresc, true);
        if (!is_array($dane)) {
            return $this->manifest = [];
        }
        $out = [];
        foreach ($dane as $klucz => $wartosc) {
            if (!is_string($klucz) || !is_string($wartosc) || $wartosc === '') {
                continue;
            }
            if ($klucz === self::KLUCZ_WYDANIA) {
                $this->wydanie = $wartosc;
                continue;
            }
            $out[$klucz] = $wartosc;
        }

        return $this->manifest = $out;
    }

    /**
     * Zbudowanie manifestu: skrót treści każdego pliku w katalogu zasobów.
     *
     * ⚠ Używane **wyłącznie przy budowaniu paczki** (`dev/build-package.php`), nigdy w żądaniu:
     * liczenie skrótów całego katalogu przy każdym wyświetleniu strony to praca bez powodu,
     * a na hostingu z limitem 30 s — praca ryzykowna.
     *
     * @param string $wydanie numer wydania paczki; wchodzi do manifestu pod `KLUCZ_WYDANIA`
     *                         i służy jako wersja zasobu, którego w manifeście nie ma
     * @return array<string, string> ścieżka względna => 12 znaków skrótu
     */
    public static function zbuduj(string $assetsPath, string $wydanie = ''): array
    {
        if (!is_dir($assetsPath)) {
            return [];
        }
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($assetsPath, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $plik) {
            if (!$plik instanceof \SplFileInfo || !$plik->isFile()) {
                continue;
            }
            $wzgledna = ltrim(str_replace($assetsPath, '', $plik->getPathname()), '/');
            if ($wzgledna === self::MANIFEST) {
                continue;
            }
            $skrot = @hash_file('sha256', $plik->getPathname());
            if (is_string($skrot)) {
                $out[$wzgledna] = substr($skrot, 0, 12);
            }
        }
        ksort($out);
        if ($wydanie !== '' && $out !== []) {
            $out[self::KLUCZ_WYDANIA] = $wydanie;
        }

        return $out;
    }
}
