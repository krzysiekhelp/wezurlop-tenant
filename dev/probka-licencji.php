<?php

declare(strict_types=1);

/*
 * Zaślepka licencji na czas pomiaru — wejście dla pomiarów ekranu `/licencja`.
 * **Nie jedzie do paczki klienta** (katalog `dev/`).
 *
 * ⚠ Powód istnienia: ekran ma cztery barwy kropki i dziewięć kodów stanu, a instalacja
 * deweloperska zna dokładnie jeden z nich (`L001`). Reszty nie dało się zobaczyć ani razu —
 * a stan „nie do pokazania" to stan niesprawdzony.
 *
 * ⚠ **Podpisujemy WŁASNYM kluczem, a plik klucza publicznego panelu jest odkładany na bok
 * i przywracany.** W repozytorium leży zaślepka („BRAK-KLUCZA"), nie prawdziwy klucz panelu —
 * prywatnego nie ma tu i mieć nie może (CLAUDE.md §15). Próbka generuje własną parę, pokazuje
 * ekranowi licencję, którą sama podpisała, i sprząta po sobie.
 *
 * ⚠ Żaden stan nie jest zapisywany w bazie: licencja to plik, więc przywrócenie znaczy
 * skasowanie pliku i oddanie zaślepki klucza.
 *
 * Wywołanie:
 *   php dev/probka-licencji.php stan
 *   php dev/probka-licencji.php ustaw aktywna|karencja|wygasla|obcy-host|zly-podpis|brak-klucza
 *   php dev/probka-licencji.php przywroc
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use ParagonIE_Sodium_Compat as Sodium;
use Wezurlop\Support\Config;
use Wezurlop\Support\EnvFile;

$root = dirname(__DIR__);
$env = EnvFile::load(EnvFile::path($root, []));
$factory = require $root . '/config/config.php';
$config = Config::fromArray($factory($env, [], $root));
$plikLicencji = (string) ($config->license['file'] ?? '');
$plikKlucza = (string) ($config->license['public_key_file'] ?? '');
$host = $config->installationHost();
/** Kopia zaślepki klucza publicznego z repozytorium — oddajemy ją co do bajtu. */
$kopiaKlucza = $root . '/var/probka-licencji-klucz.oryginal';
$kopiaTajnego = $root . '/var/probka-licencji-klucz.tajny';

$tryb = $argv[1] ?? 'stan';
$wariant = $argv[2] ?? '';

if ($tryb === 'stan') {
    echo json_encode([
        'plik_licencji' => $plikLicencji,
        'licencja_istnieje' => is_file($plikLicencji),
        'klucz_podmieniony' => is_file($kopiaKlucza),
        'host_instalacji' => $host,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";

    exit(0);
}

if ($tryb === 'przywroc') {
    if (is_file($plikLicencji)) {
        unlink($plikLicencji);
        echo "usunięty plik licencji\n";
    }
    if (is_file($kopiaKlucza)) {
        copy($kopiaKlucza, $plikKlucza);
        unlink($kopiaKlucza);
        echo "klucz publiczny przywrócony z kopii\n";
    }
    if (is_file($kopiaTajnego)) {
        unlink($kopiaTajnego);
    }
    echo "stan: ", is_file($plikLicencji) ? 'licencja nadal jest (!)' : 'bez licencji — tryb deweloperski', "\n";

    exit(0);
}

if ($tryb !== 'ustaw') {
    fwrite(STDERR, "Nieznany tryb. Użycie: stan | ustaw <wariant> | przywroc\n");

    exit(2);
}

// ——— para kluczy próbki: raz wygenerowana, trzymana obok kopii oryginału ———
if (!is_file($kopiaKlucza)) {
    copy($plikKlucza, $kopiaKlucza);
}
if (is_file($kopiaTajnego)) {
    $para = (string) file_get_contents($kopiaTajnego);
} else {
    $para = Sodium::crypto_sign_keypair();
    file_put_contents($kopiaTajnego, $para);
    chmod($kopiaTajnego, 0600);
}
$tajny = Sodium::crypto_sign_secretkey($para);
$publiczny = Sodium::crypto_sign_publickey($para);
file_put_contents($plikKlucza, base64_encode($publiczny));

$teraz = time();
$dane = [
    'license_id'    => 'WZU-POMIAR-0001',
    'installation'  => $host,
    'issued_at'     => $teraz - 86400,
    'expires_at'    => $teraz + 365 * 86400,
    'updates_until' => $teraz + 180 * 86400,
    'limits'        => ['accounts' => 25],
    'features'      => ['sms' => true],
];
$psujPodpis = false;

switch ($wariant) {
    case 'aktywna':
        break;
    case 'karencja':
        // Wygasła dwa dni temu — przy karencji 7 dni daje `grace` / `L200`.
        $dane['expires_at'] = $teraz - 2 * 86400;
        break;
    case 'wygasla':
        // Poza karencją: tryb ograniczony, kod `L108`.
        $dane['expires_at'] = $teraz - 30 * 86400;
        break;
    case 'obcy-host':
        $dane['installation'] = 'inna-instalacja.example';
        break;
    case 'zly-podpis':
        $psujPodpis = true;
        break;
    case 'brak-klucza':
        // Licencja jest, a klucza publicznego NIE DA SIĘ odczytać: kod `L101`.
        // ⚠ Nie kopiujemy tu kopii zapasowej — na tej instalacji `.env` wskazuje PRAWDZIWY
        // klucz deweloperski w `var/`, a nie zaślepkę z repozytorium, więc kopia dawałaby
        // „podpis się nie zgadza" (L103), czyli inny stan niż mierzony. Zapisujemy tekst
        // taki sam jak zaślepka w paczce wydania — nie dekoduje się z base64.
        file_put_contents($plikKlucza, "BRAK-KLUCZA — plik zaslepki na czas pomiaru\n");
        break;
    default:
        fwrite(STDERR, "Warianty: aktywna, karencja, wygasla, obcy-host, zly-podpis, brak-klucza\n");

        exit(2);
}

$payload = json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$podpis = Sodium::crypto_sign_detached($payload, $tajny);
if ($psujPodpis) {
    $podpis[0] = $podpis[0] === "\x00" ? "\x01" : "\x00";
}
file_put_contents($plikLicencji, json_encode([
    'payload'   => base64_encode($payload),
    'signature' => base64_encode($podpis),
], JSON_THROW_ON_ERROR));

echo 'wariant „' . $wariant . '”: plik licencji zapisany, klucz publiczny '
    . ($wariant === 'brak-klucza' ? 'ZOSTAWIONY jako zaślepka z repozytorium' : 'podmieniony na próbkowy'), "\n";
echo "⚠ Na koniec pomiaru: php dev/probka-licencji.php przywroc\n";
