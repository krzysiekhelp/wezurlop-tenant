<?php

declare(strict_types=1);

namespace Wezurlop\Service;

use Wezurlop\Console\HttpCronCommand;
use Wezurlop\Diagnostics\CronHttpProbe;
use Wezurlop\Support\Config;
// ServerProfile leży w tej samej przestrzeni (Wezurlop\Service) — import niepotrzebny.

/**
 * Stan crona i **gotowe polecenia dla tej instalacji** — jedno źródło dla dwóch ekranów
 * (zlecenie `link-hasla…` §7).
 *
 * ⚠ **Jedno miejsce prawdy, nie drugi mechanizm.** `/diagnostyka` i `Ustawienia → Cron` czytają
 * te same dane z tej samej klasy; różnią się wyłącznie tym, o co pytają. Diagnostyka odpowiada
 * „czy wszystko gra" i czyta ją ktoś, kto szuka usterki; ustawienia odpowiadają **„co mam wpisać
 * u swojego hostingodawcy"** i czyta je człowiek, który właśnie instaluje system i nie wie,
 * że coś takiego jest potrzebne.
 *
 * ⚠ Polecenia są **wyliczone z tej instalacji**, nie przepisane z podręcznika: ścieżka pochodzi
 * z `appRoot`, a częstotliwość z zapisu instalatora. Człowiek przepisujący ścieżkę z palca zrobi
 * literówkę, a objawem będzie „system nie wysyła maili" — czyli usterka, której nikt nie powiąże
 * z crontabem.
 *
 * ⚠ **Awaria to osobny stan, nie brak potwierdzenia.** „Cron niepotwierdzony" znaczy „jeszcze
 * nie wiemy"; „nie przebiegł od 40 minut" znaczy „poczta nie wychodzi" — i ma wyglądać na awarię,
 * bo nią jest.
 */
final class CronStan
{
    /** Domyślna ścieżka do PHP w poleceniu crontaba — u części hostingów inna (patrz uwaga na ekranie). */
    public const PHP_DOMYSLNY = '/usr/bin/php';

    /** Plik ze znacznikiem odrzuconego (przedwczesnego) wywołania crona po HTTP. */
    public const PLIK_ODRZUCEN = '/cron-http.odrzucone';

    /**
     * **Zapas na przebieg crona** — ile sekund po spodziewanym uruchomieniu wolno jeszcze czekać,
     * zanim sonda dostanie odpowiedź „nie odpowiedział" (zlecenie Z65).
     *
     * ⚠ Jedna stała, nie liczba w szablonie: ten sam zapas liczy odliczanie na ekranie i wyrok
     * serwera, a dwie kopie jednej liczby rozjadą się przy pierwszej zmianie. 60 s, bo przebieg
     * crona startuje z dokładnością do kilkudziesięciu sekund (crontab odpala pełną minutę,
     * a wariant po HTTP zależy od tego, kiedy ktoś wejdzie na stronę).
     */
    public const ZAPAS_SONDY_S = 60;

    public function __construct(
        private readonly Config $config,
        private readonly CronHeartbeat $heartbeat,
        private readonly \Wezurlop\Repository\TenantSettingRepository $settings,
        private readonly \Wezurlop\Repository\JobRepository $jobs,
        private readonly string $appRoot,
    ) {
    }

    /**
     * Postawienie sondy w kolejce — „sprawdź, czy cron w ogóle działa".
     *
     * ⚠ Nie uruchamia niczego w żądaniu. Zadanie ma podjąć **prawdziwy przebieg crona**;
     * dopóki go nie ma, sonda czeka i to jest właśnie odpowiedź.
     *
     * ⚠ Gdy sonda już czeka, drugie kliknięcie nie stawia kolejnej — jedna czekająca sonda
     * mówi dokładnie tyle samo co pięć, a pięć zaśmieca kolejkę.
     */
    public function wyslijSonde(): void
    {
        if ($this->jobs->hasPendingType(JobRunner::JOB_CRON_PROBE)) {
            return;
        }
        $this->settings->set(JobRunner::USTAWIENIE_SONDA_WYSLANA, gmdate('Y-m-d H:i:s'), null);
        $this->jobs->enqueue(JobRunner::JOB_CRON_PROBE, []);
    }

    /**
     * @return array{
     *     minuty: int, wiek: ?int, potwierdzony: bool, awaria: bool, nigdy: bool,
     *     ostatni_utc: ?string, najblizszy_utc: ?string,
     *     profil: string, po_http: bool, kolejka_po_zadaniu: bool, zapasowy_czynny: bool,
     *     polecenie_crontab: string, plik_http: string, sciezka_shim: string, tresc_shim: string,
     *     odstep_http_s: int, ostatni_przebieg_http: ?int, ostatnie_odrzucenie: ?int,
     *     sonda: array{stan: string, wyslana_utc: ?string, wynik_utc: ?string, termin_min: int,
     *         koniec_utc: ?string, czekalismy_s: ?int, trwa: bool}
     * }
     */
    public function stan(): array
    {
        $deploy = $this->config->deploy;
        $minuty = (int) ($deploy['cron_minutes'] ?? 0);
        $profil = (string) ($deploy['server_profile'] ?? '');
        $wiek = $this->heartbeat->ageMinutes();
        // ⚠ Próg awarii: DWA zadeklarowane okresy, ale nie mniej niż 15 minut. Przy cronie co
        // 5 minut jedno opóźnione uruchomienie nie jest awarią; brak dwóch pod rząd już jest.
        $progAwarii = max(2 * $minuty, 15);

        $ostatni = $this->heartbeat->ageMinutes() === null ? null : $this->heartbeat->ostatniUtc();

        return [
            'minuty'        => $minuty,
            'wiek'          => $wiek,
            // ⚠ Czas BEZWZGLĘDNY, nie tylko „ile temu": „1 min temu" jest prawdą w chwili
            // wyrenderowania, więc karta otwarta przed godziną kłamie — a zrzut ekranu
            // od klienta z „12 min temu" jest bezużyteczny, bo nie wiadomo, kiedy powstał.
            'ostatni_utc'   => $ostatni,
            // Odpowiedź na pytanie, które administrator zadaje w tej sekcji naprawdę:
            // „czy mam czekać, czy coś jest zepsute".
            'najblizszy_utc' => $ostatni === null || $minuty <= 0
                ? null
                : gmdate('Y-m-d H:i:s', (int) strtotime($ostatni . ' UTC') + $minuty * 60),
            'potwierdzony'  => $this->heartbeat->confirmed($minuty),
            'nigdy'         => $wiek === null,
            'awaria'        => $wiek === null || $wiek > $progAwarii,
            'profil'        => $profil,
            'po_http'       => ServerProfile::usesHttpCron($profil),
            'kolejka_po_zadaniu' => (bool) ($deploy['queue_on_request'] ?? false),
            // ⚠ Co jest CZYNNE, a nie co zadeklarował instalator — tą samą regułą, którą
            // decyduje `Kernel` (patrz `CronHeartbeat::kolejkaPoZadaniu()`).
            'zapasowy_czynny'    => CronHeartbeat::kolejkaPoZadaniu(
                (bool) ($deploy['queue_on_request'] ?? false),
                $this->heartbeat->confirmed($minuty),
            ),
            'polecenie_crontab'  => sprintf(
                '*/%d * * * * %s %s/bin/run-jobs.php >/dev/null 2>&1',
                $minuty > 0 ? $minuty : 5,
                self::PHP_DOMYSLNY,
                $this->appRoot,
            ),
            'plik_http'     => ltrim(CronHttpProbe::FILE, '/'),
            'sciezka_shim'  => $this->appRoot . '/bin/cron-http.php',
            // ⚠ Shim: jeden `require`, zero logiki i zero konfiguracji. To świadomy wyjątek
            // od reguły „w katalogu domeny tylko index.php" (CLAUDE.md §0) i ma nim zostać —
            // logika w pliku osiągalnym z internetu to druga powierzchnia ataku.
            'tresc_shim'    => "<?php require '" . $this->appRoot . "/bin/cron-http.php';",
            'odstep_http_s' => HttpCronCommand::MIN_INTERVAL_SECONDS,
            'ostatni_przebieg_http' => $this->znacznik('/cron-http.last', true),
            'ostatnie_odrzucenie'   => $this->znacznik(self::PLIK_ODRZUCEN, false),
            'sonda'                 => $this->sonda($minuty),
        ];
    }

    /**
     * Stan sondy crona — cztery możliwe odpowiedzi, bo trzy byłyby nieprawdziwe.
     *
     *  - `brak`        — nikt nie pytał,
     *  - `czeka`       — sonda w kolejce, termin jeszcze nie minął („czekaj"),
     *  - `potwierdzona` — wykonana po wysłaniu, czyli cron podejmuje zadania („działa"),
     *  - ⚠ `bez_odpowiedzi` — termin minął, a sonda nadal czeka. **To jest odpowiedź
     *    „cron nie jest ustawiony"**, i musi wyglądać inaczej niż „czekaj".
     *
     * ⚠ Termin: dwa zadeklarowane okresy, nie mniej niż 15 minut — tak samo jak próg awarii,
     * bo to ta sama zasada („jedno spóźnione uruchomienie to nie awaria, brak dwóch już tak").
     *
     * @return array{stan: string, wyslana_utc: ?string, wynik_utc: ?string, termin_min: int,
     *     koniec_utc: ?string, czekalismy_s: ?int, czekalismy_mmss: ?string,
     *     zapas_s: int, trwa: bool}
     */
    private function sonda(int $minuty): array
    {
        return self::stanSondy(
            $this->settings->get(JobRunner::USTAWIENIE_SONDA_WYSLANA),
            $this->settings->get(JobRunner::USTAWIENIE_SONDA_WYNIK),
            $this->heartbeat->ageMinutes() === null ? null : $this->heartbeat->ostatniUtc(),
            $minuty,
            time(),
        );
    }

    /**
     * **Decyzja o stanie sondy — funkcja czysta** (zlecenie Z65).
     *
     * ⚠ Wyjęta z metody czytającej bazę, żeby dała się sprawdzić bez bazy i bez przeglądarki:
     * trzy przypadki (świeża, potwierdzona, po terminie) to trzy wywołania z wpisanymi czasami.
     * Dopóki decyzja siedziała razem z odczytem ustawień, każdy test o niej był testem o bazie.
     *
     * ⚠ **Koniec odliczania = spodziewany przebieg PO postawieniu sondy, plus zapas**
     * (`ZAPAS_SONDY_S`). Nie „dwa okresy od wysłania", jak było do 20.10.2026: tamten termin
     * bywał dłuższy niż dwa przebiegi i człowiek czekał 15 minut na odpowiedź, którą można dać
     * po jednym. Spodziewany przebieg liczymy od OSTATNIEGO znanego przebiegu, dodając okres
     * tyle razy, ile potrzeba, by wypadł nie wcześniej niż sonda — bo sondę podejmuje pierwszy
     * przebieg po jej postawieniu, a nie ten, który właśnie minął.
     *
     * ⚠ Gdy cron nigdy nie przebiegł, nie ma od czego liczyć: wtedy terminem jest „okres
     * od postawienia sondy" plus zapas. To jedyny przypadek, w którym zgadujemy — i mówi o tym
     * `nigdy` w stanie crona, a nie samo odliczanie.
     *
     * @param string $wyslana znacznik UTC postawienia sondy (`''` = nigdy nie stawiano)
     * @param string $wynik znacznik UTC odhaczenia sondy przez przebieg crona
     * @param ?string $ostatniPrzebieg znacznik UTC ostatniego przebiegu albo `null`
     * @param int $minuty zadeklarowana częstotliwość w minutach (0 = nieznana)
     * @param int $teraz czas bieżący jako znacznik uniksowy
     * @return array{stan: string, wyslana_utc: ?string, wynik_utc: ?string, termin_min: int,
     *     koniec_utc: ?string, czekalismy_s: ?int, czekalismy_mmss: ?string,
     *     zapas_s: int, trwa: bool}
     */
    public static function stanSondy(
        string $wyslana,
        string $wynik,
        ?string $ostatniPrzebieg,
        int $minuty,
        int $teraz
    ): array {
        $okres = ($minuty > 0 ? $minuty : 5) * 60;
        $pusta = [
            'stan' => 'brak', 'wyslana_utc' => null, 'wynik_utc' => null,
            'termin_min' => (int) ceil(($okres + self::ZAPAS_SONDY_S) / 60),
            'koniec_utc' => null, 'czekalismy_s' => null, 'czekalismy_mmss' => null,
            // ⚠ Zapas podaje SERWER, razem ze stanem: szablon ma go wypisać, a nie znać.
            'zapas_s' => self::ZAPAS_SONDY_S, 'trwa' => false,
        ];
        if ($wyslana === '') {
            return $pusta;
        }
        $czasWyslania = (int) strtotime($wyslana . ' UTC');
        $czasWyniku = $wynik === '' ? 0 : (int) strtotime($wynik . ' UTC');
        if ($czasWyniku >= $czasWyslania) {
            return [
                'stan' => 'potwierdzona',
                'wyslana_utc' => $wyslana,
                'wynik_utc' => $wynik,
                'termin_min' => $pusta['termin_min'],
                'koniec_utc' => null,
                // Ile naprawdę czekaliśmy — liczba na ekran, nie szacunek.
                'czekalismy_s' => max(0, $czasWyniku - $czasWyslania),
                // Format mm:ss liczy serwer, bo to ta sama liczba, którą wypisuje — a szablon
                // nie jest miejscem na dzielenie z resztą (CLAUDE.md §5).
                'czekalismy_mmss' => sprintf(
                    '%d:%02d',
                    intdiv(max(0, $czasWyniku - $czasWyslania), 60),
                    max(0, $czasWyniku - $czasWyslania) % 60,
                ),
                'zapas_s' => self::ZAPAS_SONDY_S,
                'trwa' => false,
            ];
        }
        $spodziewany = $czasWyslania + $okres;
        if ($ostatniPrzebieg !== null) {
            $spodziewany = (int) strtotime($ostatniPrzebieg . ' UTC');
            // Pierwszy przebieg NIE WCZEŚNIEJSZY niż postawienie sondy.
            while ($spodziewany < $czasWyslania) {
                $spodziewany += $okres;
            }
        }
        $koniec = $spodziewany + self::ZAPAS_SONDY_S;

        return [
            'stan' => $teraz > $koniec ? 'bez_odpowiedzi' : 'czeka',
            'wyslana_utc' => $wyslana,
            'wynik_utc' => $wynik === '' ? null : $wynik,
            'termin_min' => $pusta['termin_min'],
            'koniec_utc' => gmdate('Y-m-d H:i:s', $koniec),
            'czekalismy_s' => null,
            'czekalismy_mmss' => null,
            'zapas_s' => self::ZAPAS_SONDY_S,
            'trwa' => $teraz <= $koniec,
        ];
    }

    /**
     * Znacznik czasu z pliku w `var/`.
     *
     * @param bool $zTresci `true` = czas zapisany w treści pliku, `false` = czas modyfikacji
     */
    private function znacznik(string $nazwa, bool $zTresci): ?int
    {
        $plik = $this->config->varPath . $nazwa;
        clearstatcache(true, $plik);
        if (!is_file($plik)) {
            return null;
        }
        if (!$zTresci) {
            $mtime = filemtime($plik);

            return $mtime === false ? null : $mtime;
        }
        $tresc = (int) file_get_contents($plik);

        return $tresc > 0 ? $tresc : null;
    }
}
