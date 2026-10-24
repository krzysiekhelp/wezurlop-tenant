<?php

declare(strict_types=1);

// Regresja: „adres zajęty przez INNE konto" (naprawa z 22.09.2026).
// ⚠ Powód istnienia tego pliku: usterka nie była błędem zapytania, tylko błędem DECYZJI —
// `inviteExisting()` pytało „czy ktokolwiek ma ten adres" i odrzucało żądanie, zanim dojdzie
// do gałęzi `niezaproszone → invited`, która powstała właśnie na ten przypadek. Skutek
// u klienta: osoba, której nadano dostęp PRZED zaproszeniem, była nie do zaproszenia z listy
// kont i nie istniało obejście w interfejsie.
// Uruchomienie: php tests/zaproszenia.php — kod wyjścia 0 = wszystko przeszło.

require dirname(__DIR__) . '/vendor/autoload.php';

use Wezurlop\Service\AccessService;
use Wezurlop\Service\InvitationService;

(static function (): void {
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo ($ok ? 'OK    ' : 'BŁĄD  ') . $name . "\n";
        $failures += $ok ? 0 : 1;
    };
    // Wiersz konta tak, jak go zwraca `AccountRepository::findByEmail()`.
    $konto = static fn (int $id, int $osoba): array => ['id' => $id, 'person_id' => $osoba];
    $zajety = InvitationService::adresInnegoKonta(...);

    // --- adres wolny: nikt go nie ma
    $check('wolny adres nie jest zajęty', !$zajety(null, null, null));
    $check('wolny adres nie jest zajęty także przy pominięciach', !$zajety(null, 107, 12));

    // --- SEDNO REGRESJI: własne konto osoby nie jest „innym kontem"
    // (zapraszanie osoby 107, której konto #12 `niezaproszone` ma już ten adres)
    $check('konto TEJ osoby nie blokuje zaproszenia', !$zajety($konto(12, 107), 107, null));
    // --- i to samo dla zmiany adresu: konto wskazane identyfikatorem
    $check('to samo konto nie blokuje zmiany adresu', !$zajety($konto(12, 107), null, 12));

    // --- odmowa MUSI zostać odmową: `account.email` jest loginem i pozostaje UNIQUE
    $check('adres cudzego konta jest zajęty', $zajety($konto(12, 107), null, null));
    $check('adres cudzego konta zajęty przy pominięciu innej osoby', $zajety($konto(12, 107), 98, null));
    $check('adres cudzego konta zajęty przy pominięciu innego konta', $zajety($konto(12, 107), null, 11));
    // ⚠ Pominięcie jest wąskie: pasuje osoba ALBO konto, a nie „którekolwiek z dwóch pól".
    $check('pominięcie nie przecieka między osobą a kontem', $zajety($konto(12, 107), 12, null));
    $check('pominięcie konta nie łapie identyfikatora osoby', $zajety($konto(12, 107), null, 107));

    // --- wartości z bazy przychodzą jako tekst (PDO bez STRINGIFY_FETCHES też potrafi je oddać
    //     jako string w starszych sterownikach) — porównanie musi być liczbowe, nie luźne
    $check('identyfikatory jako tekst działają tak samo',
        !$zajety(['id' => '12', 'person_id' => '107'], 107, null));

    // ——— Z84 cz. C: ILE LICENCJI BRAKUJE NA PARTIĘ ZAPROSZEŃ ————————————————————
    // ⚠ Ta jedna liczba rozstrzyga DWIE rzeczy: ostrzeżenie w oknie potwierdzenia i to, czy
    // serwer przyjmie wysyłkę. Dlatego jest czystą funkcją i dlatego ma test bez bazy:
    // policzona w dwóch miejscach rozjechałaby się przy pierwszej zmianie, a wtedy okno
    // obiecywałoby wysyłkę, którą serwer odrzuca — albo, co gorsze, odwrotnie.
    $brakuje = AccessService::brakujeLicencji(...);
    $check('Z84: przy wolnych miejscach nie brakuje nic', $brakuje(3, 25, 20) === 0);
    $check('Z84: brakuje dokładnie tyle, ile nie mieści się w limicie', $brakuje(8, 25, 20) === 3);
    $check('Z84: pełny limit znaczy, że brakuje całej partii', $brakuje(4, 25, 25) === 4);
    // ⚠ **Licencja BEZ limitu nie brakuje NIGDY** — zero wolnych miejsc to inna rzecz niż brak
    // limitu, a zlepienie ich dałoby ostrzeżenie na instalacji, która limitu nie ma.
    $check('Z84: licencja bez limitu nie brakuje nigdy', $brakuje(1000, null, 999) === 0);
    // ⚠ Stan zastany gorszy od limitu (licencja zmniejszona po nadaniu dostępów) nie może dać
    // liczby ujemnej ani „ujemnych wolnych miejsc", które zmniejszyłyby brak.
    $check('Z84: przekroczony limit nie daje liczb ujemnych', $brakuje(2, 20, 25) === 2);
    $check('Z84: zero potrzebnych licencji to zero braku', $brakuje(0, 20, 25) === 0);

    echo $failures === 0 ? "\nWSZYSTKO PRZESZŁO\n" : "\nBŁĘDÓW: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
})();
