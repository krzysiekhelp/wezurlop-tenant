<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Strażnik reguł (docs/szkielet-tenanta.md rozdz. 1): wyszukuje wzorce zakazane przez CLAUDE.md.
 * To siatka na ludzką pamięć, nie zabezpieczenie — przechodzący skrypt niczego nie dowodzi.
 */
final class RuleChecker
{
    /** Wzorce w szablonach Twig: regex => opis. Dopasowywane do całego pliku (tagi wielowierszowe). */
    private const TEMPLATE_PATTERNS = [
        '/<[a-z][^>]*?\son[a-z]+\s*=/is'   => 'atrybut on*= w szablonie (CLAUDE.md §6)',
        '/<[a-z][^>]*?\sstyle\s*=/is'      => 'atrybut style= w szablonie (CLAUDE.md §6)',
        '/<style\b/i'                      => '<style> w szablonie (CLAUDE.md §6)',
        '/\shx-on[:\-]/i'                  => 'hx-on w szablonie (CLAUDE.md §5)',
        '/\shx-vals\s*=\s*["\']\s*js:/i'   => 'hx-vals z js: w szablonie (CLAUDE.md §5)',
        '/href\s*=\s*["\']\s*javascript:/i' => 'href="javascript:" (CLAUDE.md §6)',
        // ⚠ Wersja zasobu wpisana w szablonie nie zmienia się przy zmianie pliku. Zmierzone
        // 22.09.2026: arkusze jechały pod `?v=20260917` — datą sprzed pięciu dni — więc
        // przeglądarka pracująca od rana pokazywała stary arkusz z nowym HTML-em, a pomiar
        // świeżą przeglądarką pokazywał stan poprawiony. Oba pomiary były poprawne.
        '/\?v=/'                          => 'wersja zasobu wpisana w szablonie — adres zasobu powstaje wyłącznie przez asset() (Support/AssetVersions)',
        // Adres zasobu z palca omija helper razem z wersją.
        '#["\'][^"\']*/assets/#'         => 'ścieżka do /assets/ wpisana w szablonie — użyj asset()',
        // ⚠ **Klasa układu KOMÓRKI na `<tr>`** (poprawka Z59 z 20.10.2026). `.osoba-wiersz` to
        // pudełko wewnątrz komórki (kółko z inicjałami + nazwisko) i ma `display: flex`;
        // postawione na wierszu tabeli zamieniło `<tr>` we flex, więc sześć komórek ścisnęło się
        // do szerokości jednej kolumny, a nagłówki E-MAIL i ROLA stały nad pustką (zmierzone
        // przy 1920 px). ⚠ Usterki nie widać ani w kodzie, ani na telefonie — tam reguły blokowe
        // i tak przestawiają wiersz — więc kryterium musi stać w bramce, nie w pamięci.
        // ⚠ Reguła jest wąska i taka ma zostać: `<tr>` wolno nieść wyłącznie klasy `table__*`,
        // bo tylko one opisują WIERSZ; każda inna rodzina opisuje pudełko w środku komórki.
        '/<tr[^>]*\sclass="[^"]*(?<![\w-])(?!table__)[a-z][a-z0-9_-]*/i'
            => 'klasa inna niż `table__*` na <tr> — klasa układu komórki postawiona na wierszu '
                . 'tabeli zamienia go we flex/grid i ściska kolumny (poprawka Z59)',
    ];

    /**
     * ⚠ Wzorce dla szablonów POCZTY (`templates/mail/**`) — **inny zestaw niż dla strony**,
     * bo poczta rządzi się innymi prawami (docs/system-wizualny.md, sekcja „Poczta").
     *
     * Co w poczcie WOLNO, a na stronie nie: `style=` w atrybucie (część klientów wycina
     * `<style>` z nagłówka) i tabele układu (Outlook rysuje wiadomość silnikiem Worda).
     * ⚠ To nie jest poluzowanie §6: tam chodzi o to, żeby dała się włączyć CSP bez
     * `unsafe-inline`, a w skrzynce odbiorcy nie ma ani CSP, ani naszego arkusza.
     *
     * Co w poczcie ZABRONIONE i czego pilnują te wzorce:
     *  - `<style>` i `class=` — wycinane albo ignorowane, więc wygląd zależałby od klienta,
     *  - obrazki (`<img`, `background=`) — klient blokuje je domyślnie, a piksel śledzący
     *    w wiadomości od pracodawcy jest nie do obrony,
     *  - `<script>`, `<form>`, `<iframe>` — wycinane, a obecność podnosi punkty spamowe,
     *  - `flex`/`grid`/`position` — w Outlooku nie działają, więc dają układ „raz tak, raz nie",
     *  - `url(` i `@media` — zależne od klienta; wygląd musi wynikać z atrybutów,
     *  - zmienne CSS (`var(--…)`) — w poczcie nie istnieją, wartości wpisujemy wprost.
     */
    private const MAIL_PATTERNS = [
        '/<style\b/i'                  => '<style> w szablonie poczty — część klientów wycina arkusz z nagłówka; style wyłącznie w atrybutach',
        '/\sclass\s*=/i'               => 'class= w szablonie poczty — nasz arkusz nie dociera do skrzynki; style wyłącznie w atrybutach',
        '/<img\b/i'                    => 'obrazek w szablonie poczty — klient blokuje obrazki domyślnie, logo jest tekstem',
        '/\sbackground\s*=/i'          => 'atrybut background= (obrazek tła) w szablonie poczty',
        '/<(script|form|iframe|video|audio)\b/i' => 'znacznik niedozwolony w poczcie (wycinany przez klientów, podnosi punkty spamowe)',
        '/display\s*:\s*(flex|grid)/i' => 'flex/grid w szablonie poczty — Outlook rysuje wiadomość silnikiem Worda; układ z tabel',
        '/position\s*:\s*(absolute|fixed|sticky)/i' => 'pozycjonowanie w szablonie poczty — nie działa w kliencie poczty',
        '/url\s*\(/i'                  => 'url() w szablonie poczty — zasoby zewnętrzne są blokowane',
        '/@media\b/i'                  => '@media w szablonie poczty — obsługa zależy od klienta; wygląd z atrybutów',
        '/var\s*\(\s*--/'              => 'zmienna CSS w szablonie poczty — w poczcie nie działa, wartość wpisujemy wprost',
    ];

    /** Wzorce w plikach JS. */
    private const JS_PATTERNS = [
        '/\.innerHTML\b/'                     => 'innerHTML (CLAUDE.md §6)',
        '/\.outerHTML\b/'                     => 'outerHTML (CLAUDE.md §6)',
        '/\binsertAdjacentHTML\b/'            => 'insertAdjacentHTML (CLAUDE.md §6)',
        '/\bdocument\.write(ln)?\s*\(/'       => 'document.write (CLAUDE.md §6)',
        '/(?<![\w.])eval\s*\(/'               => 'eval( (CLAUDE.md §6)',
        '/\bnew\s+Function\b/'                => 'new Function (CLAUDE.md §6)',
        '/\bset(Timeout|Interval)\s*\(\s*["\'`]/' => 'setTimeout/setInterval z tekstem (CLAUDE.md §6)',
    ];

    /** Wzorce w kodzie PHP w src/. */
    private const PHP_PATTERNS = [
        '/\bglobal\s+\$/'                                                   => 'global (CLAUDE.md §1)',
        '/\$GLOB' . 'ALS\b/'                                               => 'tablica GLOBALS (CLAUDE.md §1)',
        '/(["\'])\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b[^"\']*\1\s*\.\s*\$/i' => 'konkatenacja zmiennej w SQL (CLAUDE.md §4)',
        '/"\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b[^"]*(\$[a-z_]|\{\$)/i'      => 'interpolacja zmiennej w SQL (CLAUDE.md §4)',
        // ⚠ **Decyzja o widoczności danych kadrowych porównana do NAZWY ROLI.** Uprawnienia idą
        // przez `role_permission`, więc rola kadrowa z Etapu 3 może dostać te same kody i nic nie
        // trzeba scalać — ale tylko dopóki kod pyta o KOD UPRAWNIENIA. Jedno
        // porównanie nazwy roli w warunku `if` i ta obietnica przepada, a odebranie dostępu
        // przestaje być możliwe bez zmiany kodu (`docs/etap3-logika-urlopowa.md` §8b).
        // ⚠ Wzorzec łapie tylko PORÓWNANIA — nadawanie roli po nazwie jest w porządku
        // (`assignRole('calendar_viewer', …)`), bo to zapis, nie decyzja o dostępie.
        // ⚠ Nazwy ról sklejane z dwóch kawałków, żeby wzorzec nie łapał WŁASNEGO źródła —
        // ta sama sztuczka co przy tablicy globalnej wyżej.
        '/[!=]==?\s*[\'"](tenant_ad' . 'min|employ' . 'ee|calendar_view' . 'er|calendar_viewer_fu' . 'll|h' . 'r)[\'"]/'
            => 'porównanie do NAZWY ROLI zamiast do kodu uprawnienia (CLAUDE.md §10, etap3 §8b)',
        '/[\'"](tenant_ad' . 'min|employ' . 'ee|calendar_view' . 'er|calendar_viewer_fu' . 'll|h' . 'r)[\'"]\s*[!=]==?/'
            => 'porównanie do NAZWY ROLI zamiast do kodu uprawnienia (CLAUDE.md §10, etap3 §8b)',
    ];

    /**
     * Dane przykładowe mają być oczywiście fałszywe, nigdy skopiowane z działającej bazy.
     * Sprawdzane w szablonach i kodzie (templates/, src/, config/, bin/, JS, public/index.php) —
     * poza db/seeds i tests/. CSS pominięty: unicode-range ma ten sam format co kod zapasowy.
     */
    private const DATA_PATTERNS = [
        // Format kodu zapasowego 2FA; dozwolony wyłącznie wzór XXXX-XXXX.
        '/(?<![A-Za-z0-9-])(?!X{4}-X{4}(?![A-Za-z0-9-]))[A-Z0-9]{4}-[A-Z0-9]{4}(?![A-Za-z0-9-])/'
            => 'wartość w formacie kodu zapasowego — w danych przykładowych tylko XXXX-XXXX',
        '/@(?:[a-z0-9-]+\.)*wezurlop\.test\b/i'
            => 'adres w domenie wezurlop.test poza seedem i testami — dane przykładowe mają być fikcyjne',
    ];

    /**
     * System wizualny (docs/system-wizualny.md §1): wartość koloru istnieje wyłącznie w 00-tokens.css,
     * pozostałe arkusze biorą ją przez `var(--…)`. Kolor wpisany wprost w arkuszu warstwy wyższej
     * jest niewidoczny w przeglądzie tokenów, więc zmiana palety go pomija i zostaje jedna
     * niepasująca plama, której nikt nie potrafi znaleźć.
     */
    private const CSS_PATTERNS = [
        '/#[0-9A-Fa-f]{3,8}\b/'      => 'wartość koloru w arkuszu poza 00-tokens.css (system wizualny §1)',
        '/\b(?:rgba?|hsla?)\s*\(/i'  => 'wartość koloru w arkuszu poza 00-tokens.css (system wizualny §1)',
    ];

    /**
     * Jedyny arkusz, w którym wartość koloru wolno zapisać wprost.
     *
     * ⚠ Reguła koloru obowiązuje KAŻDY inny arkusz — bez wyjątków. Wyłączenie dla `tokens.css`,
     * `base.css` i `components.css` miało termin ważności i minął: te pliki nie istnieją już
     * w repozytorium, a razem z nimi znika powód, dla którego reguła patrzyła wyłącznie
     * na nazwy `NN-*.css`. Nowy arkusz dodany pod dowolną nazwą jest sprawdzany od pierwszego
     * dnia, a nie „gdy ktoś go dopisze do wzorca".
     */
    private const CSS_TOKENS = '00-tokens.css';

    /**
     * DDL w kodzie aplikacji (CLAUDE.md §4, §16 pkt 11): zmiana schematu wyłącznie w migracjach,
     * instalatorze i aktualizatorze. Dopasowanie do literału tekstowego zaczynającego się poleceniem DDL.
     */
    private const DDL_PATTERNS = [
        '/(["\'])\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME)\s+(TEMPORARY\s+)?(TABLE|DATABASE|SCHEMA|INDEX|UNIQUE|VIEW|USER|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i'
            => 'DDL poza migracjami, instalatorem i aktualizatorem (CLAUDE.md §4)',
        '/(["\'])\s*(GRANT|REVOKE)\s+\S+/i'
            => 'GRANT/REVOKE w kodzie aplikacji (CLAUDE.md §4)',
    ];

    /** Katalogi, w których DDL jest dozwolony (migracje są poza src/). */
    private const DDL_ALLOWED = ['src/Controller/Install/', 'src/Service/Install/', 'src/Service/Update/'];

    /**
     * ⚠ Metody niskiego poziomu, które wolno wołać **wyłącznie** z jednego, wskazanego pliku.
     *
     * Powód jest z doświadczenia, nie z teorii: `blockManually()` i `unblockManually()`
     * przestawiają stan konta **bez sprawdzania reguł** (blokada siebie, ostatni aktywny
     * administrator tenanta). Reguły stoją w `AccountService`, ale kontroler nadal ma
     * `AccountRepository` w konstruktorze, bo potrzebuje go do innych rzeczy — czyli technicznie
     * mógłby ominąć decyzję. Komentarz tego nie pilnuje; ⚠ **komentarz nie upada na czerwono**.
     *
     * Tu chodzi o inne ryzyko niż przy limicie licencji: tam zniknięcie zależności dało dowód
     * „nie ma czym zrobić źle", tutaj dowodem jest bramka — tańsza niż przebudowa ekranu
     * i obowiązująca na każde jutro.
     *
     * @var array<string, array{plik: string, opis: string}>
     */
    private const WYWOLANIA_TYLKO_Z = [
        'blockManually' => [
            'plik' => 'src/Service/AccountService.php',
            'opis' => 'blockManually() nie zna reguł blokady — wolno je wołać tylko z AccountService',
        ],
        'unblockManually' => [
            'plik' => 'src/Service/AccountService.php',
            'opis' => 'unblockManually() wolno wołać tylko z AccountService (zlecenie o ostatnim administratorze §2.4)',
        ],
    ];

    /**
     * ⚠ Metody, które muszą stać **w tym samym pliku** co inna metoda.
     *
     * `blockRefusal()` to ta sama reguła zadana pytająco — po to, żeby przycisk i ekran
     * potwierdzenia mówiły prawdę przed kliknięciem. Gdyby rozjechała się z `block()`
     * na dwa pliki, ktoś dopisałby regułę tylko do jednego z nich i wróciłaby atrapa:
     * czynny przycisk, który dopiero po kliknięciu mówi „nie wolno".
     *
     * @var array<string, array{razem_z: string, opis: string}>
     */
    private const METODY_RAZEM = [
        'blockRefusal' => [
            'razem_z' => 'function block(',
            'opis' => 'blockRefusal() musi stać w tym samym pliku co block() — pytanie i decyzja to jedna reguła',
        ],
    ];

    /** Pliki PHP dopuszczone w public/ (CLAUDE.md §0, §2): front controller i ścieżka aplikacji. */
    private const PUBLIC_PHP_ALLOWED = ['public/index.php', 'public/app-path.php'];

    /** @var list<string> */
    private array $hits = [];

    private int $filesChecked = 0;

    private ?\Twig\Environment $twig = null;

    public function __construct(private readonly string $root)
    {
    }

    /** @return list<string> trafienia w postaci „plik:linia: opis” */
    public function run(): array
    {
        $this->hits = [];
        $this->filesChecked = 0;

        foreach ($this->files('templates') as $file) {
            if ($file->getExtension() === 'php') {
                $this->hit($file, 1, 'plik .php w templates/ (CLAUDE.md §5)');
                continue;
            }
            if ($file->getExtension() === 'twig') {
                $this->checkTemplate($file);
            }
        }
        foreach ($this->files('public/assets/js') as $file) {
            if ($file->getExtension() === 'js' && !$this->isUnder($file, 'public/assets/js/vendor')) {
                $this->checkPatterns($file, self::JS_PATTERNS + self::DATA_PATTERNS);
            }
        }
        // dev/ (symulator, build paczki) nie jedzie do klienta, ale podlega tym samym regułom kodu.
        foreach ([...$this->files('src'), ...$this->files('dev')] as $file) {
            if ($file->getExtension() === 'php') {
                $ddl = $this->ddlAllowed($file) ? [] : self::DDL_PATTERNS;
                $this->checkPatterns($file, self::PHP_PATTERNS + self::DATA_PATTERNS + $ddl);
                $this->checkWywolania($file);
                $this->checkMetodyRazem($file);
            }
        }
        // ⚠ **Dwa przebiegi po arkuszach, nie jeden**: żeby sprawdzić, czy użyty token istnieje,
        // trzeba najpierw znać WSZYSTKIE definicje — a te stoją nie tylko w `00-tokens.css`
        // (`--kal-ramka` w komponencie kalendarza, `--team-days` w siatce zespołu). Sprawdzanie
        // pliku po pliku zapalałoby każdy token zdefiniowany w innym arkuszu niż użyty.
        $znaneTokeny = $this->cssTokeny();
        foreach ($this->files('public/assets/css') as $file) {
            if ($file->getExtension() !== 'css') {
                continue;
            }
            $bezKomentarzy = $this->withoutCssComments($this->read($file));
            $this->checkCssTokeny($file, $bezKomentarzy, $znaneTokeny);
            // ⚠ Nawiasy sprawdzamy w KAŻDYM arkuszu, także w `00-tokens.css`: wyjątek dotyczy
            // wartości kolorów, a nie składni. Niedomknięty blok w pliku tokenów wyłączyłby
            // wszystko, co po nim stoi, czyli połowę systemu wizualnego.
            $this->checkCssNawiasy($file, $bezKomentarzy);
            if ($file->getBasename() !== self::CSS_TOKENS) {
                $this->matchAll($file, $bezKomentarzy, self::CSS_PATTERNS);
            }
        }
        foreach (['config', 'bin'] as $dir) {
            foreach ($this->files($dir) as $file) {
                if ($file->getExtension() === 'php') {
                    $this->checkPatterns($file, self::DATA_PATTERNS + self::DDL_PATTERNS);
                }
            }
        }
        // ⚠ Zasób, którym instalator sprawdza prefiks, MUSI istnieć. Zależność jest niewidoczna
        // w szablonach — to adres składany w PHP — więc skasowanie pliku nie rzuciłoby się w oczy,
        // a u klienta dałoby instalator twierdzący, że nie widzi własnych zasobów.
        $sonda = $this->root . '/public' . \Wezurlop\Service\Install\PrefixDetector::PROBE_ASSET;
        if (!is_file($sonda)) {
            $this->hits[] = 'src/Service/Install/PrefixDetector.php:1: PROBE_ASSET wskazuje plik, '
                . 'którego nie ma: public' . \Wezurlop\Service\Install\PrefixDetector::PROBE_ASSET;
        }

        // Pliki .htaccess w repozytorium to wdrożony profil apache — muszą być równe szablonom z deploy/.
        foreach (['.htaccess' => 'deploy/htaccess/apache/root.htaccess', 'public/.htaccess' => 'deploy/htaccess/apache/public.htaccess'] as $live => $template) {
            $a = $this->root . '/' . $live;
            $b = $this->root . '/' . $template;
            if (is_file($a) && is_file($b) && file_get_contents($a) !== file_get_contents($b)) {
                $this->hits[] = $live . ':1: różni się od ' . $template . ' (profil apache) — zmień oba pliki razem';
            }
        }
        foreach ($this->files('public') as $file) {
            if (strtolower($file->getExtension()) === 'php' && !in_array($this->relative($file), self::PUBLIC_PHP_ALLOWED, true)) {
                $this->hit($file, 1, 'plik .php w public/ inny niż index.php i app-path.php (CLAUDE.md §2)');
            } elseif ($this->relative($file) === 'public/index.php') {
                $this->checkPatterns($file, self::DATA_PATTERNS);
            }
        }

        return $this->hits;
    }

    public function filesChecked(): int
    {
        return $this->filesChecked;
    }

    /**
     * Uruchomienie z bin/check-rules.php: wypisuje trafienia, zwraca kod wyjścia (1 = naruszenia).
     *
     * @param resource $out
     */
    public function report($out): int
    {
        $hits = $this->run();
        foreach ($hits as $hit) {
            fwrite($out, $hit . "\n");
        }
        fwrite($out, sprintf(
            "check-rules: %s (plików: %d, trafień: %d)\n",
            $hits === [] ? 'OK' : 'NARUSZENIA',
            $this->filesChecked,
            count($hits),
        ));

        return $hits === [] ? 0 : 1;
    }

    private function checkTemplate(SplFileInfo $file): void
    {
        $content = $this->read($file);
        // ⚠ Szablony poczty mają WŁASNY zestaw reguł — patrz `MAIL_PATTERNS`. Zmierzone
        // 22.09.2026: przy jednym zestawie bramka zgłaszała 40 trafień `style=` na trzech
        // szablonach wiadomości, czyli karała za jedyny sposób, w jaki poczta może działać.
        $wzorce = $this->isUnder($file, 'templates/mail')
            ? self::MAIL_PATTERNS
            : self::TEMPLATE_PATTERNS;
        // ⚠ Wzorce znaczników sprawdzamy na treści BEZ komentarzy Twiga: reguła dotyczy tego,
        // co wychodzi do przeglądarki albo do skrzynki, a nie tego, co napisaliśmy o regule.
        // Zmierzone: komentarz wyjaśniający, dlaczego w poczcie nie ma `<style>`, sam był
        // zgłaszany jako `<style>` w szablonie.
        // ⚠ Wzorce DANYCH PRZYKŁADOWYCH liczą się dalej na CAŁEJ treści — prawdziwy adres
        // e-mail w komentarzu jest w repozytorium tak samo jak w znaczniku.
        $this->matchAll($file, $this->withoutTwigComments($content), $wzorce);
        $this->matchAll($file, $content, self::DATA_PATTERNS);

        // |raw — wyjątek tylko z komentarzem {# raw-ok: ... #} w wierszu wyżej
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if (preg_match('/\|\s*raw\b/', $line) === 1
                && ($i === 0 || !str_contains($lines[$i - 1], '{# raw-ok:'))) {
                $this->hit($file, $i + 1, '|raw bez komentarza {# raw-ok: ... #} (CLAUDE.md §5)');
            }
        }

        $this->checkTwigSklada($file, $content);

        // <script> z treścią; dozwolony jedynie blok type="application/json" (CLAUDE.md §6)
        if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $content, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as $i => [$tag, $offset]) {
                $attrs = $m[1][$i][0];
                $inner = trim($m[2][$i][0]);
                if ($inner !== '' && preg_match('/type\s*=\s*["\']application\/json["\']/i', $attrs) !== 1) {
                    $this->hit($file, $this->lineAt($content, $offset), '<script> z treścią (CLAUDE.md §6)');
                }
            }
        }
    }

    /**
     * **Czy szablon w ogóle da się przeczytać Twigowi** (zlecenie Z80 D pkt 3).
     *
     * ⚠ Powód jest zmierzony, nie teoretyczny. Komentarz `{# … #}` postawiony WEWNĄTRZ wyrażenia
     * Twiga (np. w `include … with { … }`) nie jest komentarzem — lekser widzi w nim zwykłe znaki
     * i wywraca się na nich. Objaw u człowieka: **500 na ekranie**, bez ani jednego trafienia
     * w regułach, bo wzorce tekstowe widzą tam poprawny komentarz (zmierzone 23.10.2026, Z80 A).
     * Ta sama klasa błędu to niezamknięty `{%`, zły cudzysłów i `{{` bez `}}`.
     *
     * ⚠ **Lekser, nie kompilacja.** `tokenize()` sprawdza SKŁADNIĘ i nie potrzebuje ani
     * konfiguracji, ani bazy, ani listy naszych funkcji — a check-rules musi dać się puścić
     * na czystym katalogu. Pełne `parse()` złapałoby też nieznany znacznik, filtr i funkcję,
     * ale wymaga środowiska z zarejestrowanymi `url()`, `asset()`, `csrf_token()` i resztą
     * (patrz dwie listy funkcji w `Kernel`); bez nich zapaliłoby KAŻDY nasz szablon. Granica
     * jest więc tu, i to jest decyzja, nie przeoczenie: tokenizacja łapie błędy, których
     * nie widzi żaden wzorzec tekstowy, i nie zgłasza nic poza nimi.
     *
     * ⚠ Nazwa pliku idzie do `Source`, żeby komunikat Twiga mówił, GDZIE pękło — bez niej
     * błąd brzmi „in an unknown template".
     */
    private function checkTwigSklada(SplFileInfo $file, string $content): void
    {
        try {
            $this->twig()->tokenize(new \Twig\Source($content, $this->relative($file)));
        } catch (\Twig\Error\SyntaxError $e) {
            $linia = $e->getTemplateLine();
            $this->hit($file, $linia > 0 ? $linia : 1,
                'szablonu nie da się ztokenizować: ' . $e->getRawMessage() . ' (CLAUDE.md §5)');
        }
    }

    /**
     * Środowisko Twiga **tylko do leksera** — jedno na przebieg, bez ładowarki plików.
     *
     * ⚠ `ArrayLoader` z pustą tablicą wystarcza, bo tokenizujemy treść PODANĄ, a nie wczytywaną
     * po nazwie. Prawdziwa ładowarka kazałaby trzymać tu drugą ścieżkę do `templates/`.
     */
    private function twig(): \Twig\Environment
    {
        return $this->twig ??= new \Twig\Environment(new \Twig\Loader\ArrayLoader([]));
    }

    /** @param array<string, string> $patterns */
    private function checkPatterns(SplFileInfo $file, array $patterns): void
    {
        $this->matchAll($file, $this->read($file), $patterns);
    }

    /**
     * Treść arkusza bez komentarzy — z zachowaniem długości, żeby numery linii się nie przesunęły.
     *
     * ⚠ Bez tego reguła koloru zgłasza własny komentarz: opis „stara reguła dawała tło `#ebf9f1`"
     * jest dokumentacją decyzji, a nie kolorem w arkuszu. Zgłoszenie takiego trafienia uczy
     * usuwania uzasadnień z kodu, czyli dokładnie odwrotnie niż chcemy.
     */
    /**
     * **Nawiasy klamrowe w arkuszu muszą się parować** (kryterium z 20.10.2026, zlecenie Z54).
     *
     * ⚠ Powód jest zmierzony i zaskakujący: przeglądarka po nadmiarowym `}` odrzuca
     * **NASTĘPNĄ REGUŁĘ**, a nie sam nawias — czyta go jako początek preludium kolejnej reguły
     * i wyrzuca ją w całości razem z blokiem. Tak samo znika postawiony po nim `@media`.
     * Zmierzone na osobnym arkuszu 20.10.2026 i w produkcie: jeden taki nawias w
     * `30-components.css` wyłączał cały blok `@media (pointer: coarse)`, czyli wysokość celów
     * dotykowych i 16 px w polach — na każdym ekranie telefonu i tabletu. Nic tego nie zgłaszało:
     * arkusz ładował się bez błędu, a ekran wyglądał jak zawsze, tylko pola były za niskie.
     *
     * ⚠ Niedomknięty blok jest groźniejszy w drugą stronę: wciąga do siebie wszystko, co po nim
     * stoi. W tym samym pliku niedomknięty `@supports not selector(:has(*))` wciągnął cały blok
     * telefonowy `@media (max-width: 720px)`, więc na przeglądarce z `:has()` reguły dla 720 px
     * **nie istniały**, choć stały w pliku.
     *
     * ⚠ Komentarze i napisy w cudzysłowach schodzą PRZED liczeniem: `content: "}"` jest legalne
     * i nie jest nawiasem bloku.
     */
    /**
     * **Wszystkie tokeny zdefiniowane w arkuszach** — zbiór nazw, nie wartości.
     *
     * ⚠ Czytamy KAŻDY arkusz, nie tylko `00-tokens.css`: część tokenów to zmienne komponentu
     * (`--kal-ramka`, `--team-days`, `--przyklej-luka`) i mieszkają przy regule, która ich używa.
     * Wymaganie, żeby wszystkie stały w pliku tokenów, byłoby inną regułą niż ta — i nieprawdziwą,
     * bo zmienna układu nie jest wartością systemu wizualnego.
     *
     * @return array<string, true>
     */
    private function cssTokeny(): array
    {
        $znane = [];
        foreach ($this->files('public/assets/css') as $file) {
            if ($file->getExtension() !== 'css') {
                continue;
            }
            // Komentarze odpadają, bo w komentarzach stoją nazwy tokenów SKASOWANYCH
            // (np. „token `--c-dzis` zniknął razem z regułami") — definicją nie są.
            $tekst = $this->withoutCssComments($this->read($file));
            if (preg_match_all('/(--[a-z0-9-]+)\s*:/i', $tekst, $m) > 0) {
                foreach ($m[1] as $nazwa) {
                    $znane[$nazwa] = true;
                }
            }
        }

        return $znane;
    }

    /**
     * **Użyty token istnieje albo ma wartość zapasową** (zlecenie Z77, 22.10.2026).
     *
     * ⚠ Powód jest zmierzony, nie teoretyczny: nieznana nazwa w `var(--x)` **nie jest dla
     * przeglądarki błędem** — unieważnia całą deklarację po cichu, a wartość bierze się
     * z dziedziczenia. Taki błąd nie zapala się NIGDZIE: ani w przeglądarce, ani w phpcs,
     * ani (do dziś) tutaj. Dwa przykłady z tego repozytorium: `color: var(--c-text-1)`
     * w `.konto-pole__wartosc` siedziało od 19.10.2026 do 21.10.2026 i przez cały ten czas
     * nie robiło nic, a `font-size: var(--fs-sm)` w dwóch regułach ekranu hierarchii nie
     * ustawiało rozmiaru od chwili przepisania arkusza.
     *
     * ⚠ Wartość zapasowa (`var(--x, 8px)`) jest **dozwolona i konieczna**: tak czyta się tokeny
     * ustawiane w czasie pracy (`--sonda-postep` z JavaScriptu, `--przyklej-luka` z pomiaru).
     * Rozstrzyga znak po nazwie: przecinek znaczy „jest zapas", nawias zamykający — „musi istnieć".
     *
     * @param array<string, true> $znane
     */
    private function checkCssTokeny(SplFileInfo $file, string $bezKomentarzy, array $znane): void
    {
        if (preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*([,)])/i', $bezKomentarzy, $m, PREG_OFFSET_CAPTURE) < 1) {
            return;
        }
        foreach ($m[1] as $i => [$nazwa, $pozycja]) {
            if ($m[2][$i][0] === ',' || isset($znane[$nazwa])) {
                continue;
            }
            $this->hit($file, $this->lineAt($bezKomentarzy, $pozycja),
                "token {$nazwa} nie jest nigdzie zdefiniowany i nie ma wartości zapasowej — "
                . 'przeglądarka odrzuci CAŁĄ deklarację po cichu');
        }
    }

    private function checkCssNawiasy(SplFileInfo $file, string $bezKomentarzy): void
    {
        $tekst = $this->withoutCssStrings($bezKomentarzy);
        $stos = [];
        $dlugosc = strlen($tekst);
        for ($i = 0; $i < $dlugosc; $i++) {
            if ($tekst[$i] === '{') {
                $stos[] = $this->lineAt($tekst, $i);
                continue;
            }
            if ($tekst[$i] !== '}') {
                continue;
            }
            if ($stos === []) {
                $this->hit($file, $this->lineAt($tekst, $i),
                    'nadmiarowy `}` — przeglądarka odrzuci NASTĘPNĄ regułę, nie ten nawias');
                continue;
            }
            array_pop($stos);
        }
        foreach ($stos as $linia) {
            $this->hit($file, $linia, 'niezamknięty blok otwarty w tej linii — wciągnie do siebie wszystko, co po nim stoi');
        }
    }

    /**
     * Napisy w cudzysłowach zamienione na odstępy, z zachowaniem numerów linii.
     *
     * ⚠ Liczy się TYLKO po to, żeby nawias w napisie (`content: "}"`, `content: "{"`) nie udawał
     * nawiasu bloku. Ucieczka `\"` wewnątrz napisu jest uszanowana; napis niedomknięty do końca
     * linii kończymy na końcu linii, bo tak robi i przeglądarka.
     */
    private function withoutCssStrings(string $content): string
    {
        $wynik = $content;
        $dlugosc = strlen($content);
        $i = 0;
        while ($i < $dlugosc) {
            $znak = $content[$i];
            if ($znak !== '"' && $znak !== "'") {
                $i++;
                continue;
            }
            $i++;
            while ($i < $dlugosc && $content[$i] !== $znak && $content[$i] !== "\n") {
                if ($content[$i] === '\\') {
                    $wynik[$i] = ' ';
                    $i++;
                    if ($i < $dlugosc && $content[$i] !== "\n") {
                        $wynik[$i] = ' ';
                        $i++;
                    }
                    continue;
                }
                $wynik[$i] = ' ';
                $i++;
            }
            $i++;
        }

        return $wynik;
    }

    private function withoutCssComments(string $content): string
    {
        return (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? '',
            $content
        );
    }

    /** @param array<string, string> $patterns */
    private function matchAll(SplFileInfo $file, string $content, array $patterns): void
    {
        foreach ($patterns as $regex => $description) {
            if (preg_match_all($regex, $content, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [, $offset]) {
                    $this->hit($file, $this->lineAt($content, $offset), $description);
                }
            }
        }
    }

    /** @return iterable<SplFileInfo> */
    private function files(string $dir): iterable
    {
        $path = $this->root . '/' . $dir;
        if (!is_dir($path)) {
            return [];
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file;
            }
        }
        usort($files, static fn (SplFileInfo $a, SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()));

        return $files;
    }

    private function read(SplFileInfo $file): string
    {
        $this->filesChecked++;
        $content = file_get_contents($file->getPathname());

        return is_string($content) ? $content : '';
    }

    private function lineAt(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }

    private function relative(SplFileInfo $file): string
    {
        return ltrim(substr($file->getPathname(), strlen($this->root)), '/');
    }

    /**
     * Wywołanie metody niskiego poziomu spoza pliku, który jest jej jedynym właścicielem.
     *
     * ⚠ Definicja metody nie jest wywołaniem — dlatego wzorzec wymaga `->` albo `::` przed nazwą.
     */
    private function checkWywolania(SplFileInfo $file): void
    {
        $sciezka = $this->relative($file);
        foreach (self::WYWOLANIA_TYLKO_Z as $metoda => $regula) {
            if ($sciezka === $regula['plik']) {
                continue;
            }
            $this->matchAll($file, $this->read($file), [
                '/(->|::)\s*' . preg_quote($metoda, '/') . '\s*\(/' => $regula['opis'],
            ]);
        }
    }

    /** Metoda, która musi stać w jednym pliku z inną — patrz `METODY_RAZEM`. */
    private function checkMetodyRazem(SplFileInfo $file): void
    {
        $tresc = $this->read($file);
        foreach (self::METODY_RAZEM as $metoda => $regula) {
            if (!str_contains($tresc, 'function ' . $metoda . '(')) {
                continue;
            }
            if (!str_contains($tresc, $regula['razem_z'])) {
                $this->hit($file, 1, $regula['opis']);
            }
        }
    }

    /**
     * Treść szablonu bez komentarzy Twiga, z zachowaniem liczby wierszy.
     *
     * ⚠ Podmieniamy komentarz na tyle znaków nowej linii, ile miał — numer wiersza w trafieniu
     * musi dalej wskazywać to samo miejsce w pliku, inaczej bramka mówi „coś jest nie tak"
     * i podaje zły wiersz, co jest gorsze niż brak numeru.
     */
    private function withoutTwigComments(string $content): string
    {
        return (string) preg_replace_callback(
            '/\{#.*?#\}/s',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $content,
        );
    }

    private function ddlAllowed(SplFileInfo $file): bool
    {
        foreach (self::DDL_ALLOWED as $prefix) {
            if (str_starts_with($this->relative($file), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isUnder(SplFileInfo $file, string $dir): bool
    {
        return str_starts_with($this->relative($file), $dir . '/');
    }

    private function hit(SplFileInfo $file, int $line, string $description): void
    {
        $this->hits[] = $this->relative($file) . ':' . $line . ': ' . $description;
    }
}
