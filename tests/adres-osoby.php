<?php

declare(strict_types=1);

// Adres e-mail osoby: normalizacja i odmowa przy duplikacie (zlecenie Z40, 18.10.2026).
//
// ⚠ Sedno: logujemy się adresem e-mail, więc duplikat nie jest bałaganem, tylko dziurą —
// dwie osoby z jednym adresem to pytanie „czyje to konto", na które system nie ma odpowiedzi.
// Reguła stoi w trzech miejscach (normalizacja, decyzja o odmowie, klucz unikalny w bazie)
// i tutaj sprawdzamy dwa pierwsze; trzeciego pilnuje migracja `20261018120001`.
//
// ⚠ Drugi powód istnienia tego pliku: komunikat odmowy **nazywa osobę tylko wtedy, gdy
// administrator i tak ją widzi**. Wyciek nazwiska osoby spoza zakresu nie wywróciłby niczego
// na ekranie — byłby po prostu cichym pokazaniem danych kadrowych komuś, kto nie ma do nich
// dostępu. Takie rzeczy widać wyłącznie w teście, który o nie pyta wprost.
//
// Uruchomienie: php tests/adres-osoby.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\AuthService;
use Wezurlop\Support\AdresKontaktowy;

(static function (): void {
    $failures = 0;
    $check = static function (string $opis, bool $ok, string $widziane = '') use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $opis . ($ok || $widziane === '' ? '' : " — widziane: {$widziane}") . "\n";
        $failures += $ok ? 0 : 1;
    };

    // ——— normalizacja ———
    $n = static fn (string $s): string => AuthService::normalizeEmail($s);
    $check('wielkie litery schodzą do małych', $n('ABC@X.PL') === 'abc@x.pl', $n('ABC@X.PL'));
    $check('spacje z obu stron znikają', $n('  abc@x.pl  ') === 'abc@x.pl', '„' . $n('  abc@x.pl  ') . '”');
    // ⚠ To jest dokładnie para z warunków odbioru Z40: „ABC@x.pl ” i „abc@x.pl” to JEDEN adres.
    $check('„ABC@x.pl ” i „abc@x.pl” to ten sam adres', $n('ABC@x.pl ') === $n('abc@x.pl'));
    $check('pusty napis zostaje pusty (brak adresu to nie wartość)', $n('   ') === '', '„' . $n('   ') . '”');
    // ⚠ Znaki spoza ASCII: `mb_strtolower`, nie `strtolower` — inaczej adres z polską literą
    // normalizowałby się inaczej przy zapisie i przy wyszukaniu, czyli konto „znikałoby" przy logowaniu.
    $check('litery spoza ASCII też schodzą do małych', $n('ŁUKASZ@x.pl') === 'łukasz@x.pl', $n('ŁUKASZ@x.pl'));

    // ——— odmowa ———
    $check('wolny adres nie daje odmowy', AdresKontaktowy::odmowa(null) === null);
    $wZakresie = ['id' => 7, 'nazwa' => 'Jan Kowalski', 'w_zakresie' => true];
    $check('odmowa nazywa osobę, którą administrator i tak widzi',
        AdresKontaktowy::odmowa($wZakresie) === 'Ten adres ma już inna osoba: Jan Kowalski.',
        (string) AdresKontaktowy::odmowa($wZakresie));
    $pozaZakresem = ['id' => 7, 'nazwa' => 'Jan Kowalski', 'w_zakresie' => false];
    $check('odmowa pada także przy osobie spoza zakresu',
        AdresKontaktowy::odmowa($pozaZakresem) === AdresKontaktowy::ODMOWA_BEZ_NAZWISKA);
    $check('nazwisko osoby spoza zakresu NIE wycieka do komunikatu',
        !str_contains((string) AdresKontaktowy::odmowa($pozaZakresem), 'Kowalski'),
        (string) AdresKontaktowy::odmowa($pozaZakresem));
    $check('osoba bez nazwy nie daje komunikatu z dziurą',
        AdresKontaktowy::odmowa(['id' => 7, 'nazwa' => '   ', 'w_zakresie' => true]) === AdresKontaktowy::ODMOWA_BEZ_NAZWISKA);

    // ——— rozpoznanie duplikatu z bazy (wyścig dwóch zapisów) ———
    $wyjatek = static function (string $sqlstate, int $kod): PDOException {
        $e = new class ('błąd bazy') extends PDOException {
            public function ustaw(string $sqlstate, int $kod): PDOException
            {
                $this->code = $sqlstate;
                $this->errorInfo = [$sqlstate, $kod, 'komunikat sterownika'];

                return $this;
            }
        };

        return $e->ustaw($sqlstate, $kod);
    };
    $check('naruszenie klucza unikalnego to duplikat', AdresKontaktowy::duplikat($wyjatek('23000', 1062)));
    // ⚠ Pod SQLSTATE 23000 kryje się też klucz obcy i NULL w kolumnie NOT NULL. Gdyby liczył się
    // sam SQLSTATE, użytkownik dostawałby „ten adres ma już inna osoba" przy zupełnie innej usterce.
    $check('naruszenie klucza obcego to NIE duplikat adresu', !AdresKontaktowy::duplikat($wyjatek('23000', 1452)));
    $check('brak tabeli to NIE duplikat adresu', !AdresKontaktowy::duplikat($wyjatek('42S02', 1146)));

    echo $failures === 0 ? "Wszystkie sprawdzenia przeszły.\n" : "NIE PRZESZŁO: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
