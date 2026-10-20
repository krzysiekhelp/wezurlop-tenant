<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Poczta;

/**
 * **Przykładowe dane każdego rodzaju wiadomości** — jedno miejsce dla podglądu i dla testu
 * (zlecenie Z60).
 *
 * ⚠ Dwa zestawy danych rozjechałyby się przy pierwszym nowym rodzaju wiadomości, a wtedy test
 * świeciłby zielono na rodzaju, którego podgląd nie pokazuje — albo odwrotnie. Katalog jest
 * więc jeden i obie drogi pytają o niego tutaj.
 *
 * ⚠ **Dane FIKCYJNE** (CLAUDE.md §18): nazwiska zastępcze, adresy w domenie `przyklad.invalid`,
 * nazwa firmy „ALFA sp. z o.o.". Nic nie pochodzi z bazy.
 *
 * ⚠ Katalog nie jedzie do paczki klienta (`dev/`, patrz `dev/package-allowlist.txt`).
 */
final class PrzykladyWiadomosci
{
    /**
     * Rodzaje wiadomości: nazwa => [szablon, temat bez prefiksu, zmienne].
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function wszystkie(): array
    {
        $firma = 'ALFA sp. z o.o.';
        /** Każdy rodzaj: nazwa pliku => [szablon, temat, dane]. */
        $rodzaje = [
            'zaproszenie' => ['zaproszenie', 'Zaproszenie do aplikacji — ustaw hasło', [
                'podglad' => 'Ustaw hasło i zaloguj się do systemu urlopowego.',
                'imie_w_wolaczu' => 'Alicjo',
                'firma' => $firma,
                'akcja' => ['napis' => 'Ustaw hasło i zaloguj się',
                    'link' => 'https://urlopy.przyklad.invalid/zaproszenie?token=PRZYKLADOWY'],
                'login' => 'alicja@przyklad.invalid',
                'zapraszajacy' => 'Jan Próbny',
                'waznosc_dni' => '7 dni',
            ]],
            'haslo-reset' => ['haslo-reset', 'Ustawienie nowego hasła', [
                'podglad' => 'Link do ustawienia nowego hasła, ważny 30 minut.',
                'login' => 'alicja@przyklad.invalid',
                'akcja' => ['napis' => 'Ustaw nowe hasło',
                    'link' => 'https://urlopy.przyklad.invalid/haslo/nowe?token=PRZYKLADOWY'],
                'waznosc' => '30 minut',
                'firma' => $firma,
            ]],
            'proba-poczta' => ['proba', 'Wiadomość próbna', [
                'podglad' => 'Wysyłka z tej instalacji działa.',
                'naglowek' => 'Serwer poczty działa',
                'zdanie' => 'Ta wiadomość przeszła przez serwer poczty skonfigurowany w tej instalacji.',
                'wiersze' => [
                    ['etykieta' => 'Odbiorca', 'wartosc' => 'admin@przyklad.invalid'],
                    ['etykieta' => 'Serwer poczty', 'wartosc' => 'smtp.przyklad.invalid:587 (tls)'],
                ],
                'firma' => $firma,
            ]],
            'proba-alerty' => ['proba', 'Wiadomość próbna', [
                'podglad' => 'Alerty systemowe będą przychodzić na ten adres.',
                'naglowek' => 'Skrzynka na alerty działa',
                'zdanie' => 'Alerty systemowe z tej instalacji będą przychodzić na ten adres.',
                'wiersze' => [['etykieta' => 'Odbiorca', 'wartosc' => 'alerty@przyklad.invalid']],
                'firma' => $firma,
            ]],
            'alert-cisza-connectora' => ['alert', 'Alert: connector przestał się odzywać', [
                'podglad' => 'Synchronizacja kadr stoi od ponad 24 godzin.',
                'naglowek' => 'connector przestał się odzywać',
                'zdania' => ['Synchronizacja kadr stoi.', 'Ta instalacja nie otrzymała żadnego żądania.'],
                'wiersze' => [['etykieta' => 'Cisza dłuższa niż', 'wartosc' => '24 h']],
                'kroki' => ['czy maszyna jest włączona,', 'czy usługa wstała po restarcie.'],
                'pilny' => true,
                'stopka_zdanie' => 'Kolejny alert dopiero wtedy, gdy connector się odezwie i zamilknie ponownie.',
                'akcja' => ['napis' => 'Otwórz ekran integracji', 'link' => 'https://urlopy.przyklad.invalid/admin/integracja'],
                'firma' => $firma,
            ]],
            'alert-klucz-nieuzyty' => ['alert', 'Alert: wydany klucz czeka na wklejenie', [
                'podglad' => 'Nic się nie zepsuło — synchronizacja działa na dotychczasowym kluczu.',
                'naglowek' => 'wydany klucz czeka na wklejenie',
                'zdania' => ['Nic się nie zepsuło — synchronizacja kadr działa na dotychczasowym kluczu.'],
                'wiersze' => [['etykieta' => 'Klucz „Biuro"', 'wartosc' => 'wydany 80 h temu, nigdy nieużyty']],
                'kroki' => [],
                'pilny' => false,
                'stopka_zdanie' => 'Kolejna wiadomość dopiero przy powtórzeniu sytuacji.',
                'akcja' => ['napis' => 'Otwórz ekran integracji', 'link' => 'https://urlopy.przyklad.invalid/admin/integracja'],
                'firma' => $firma,
            ]],
            'alert-bezpieczenstwa' => ['alert', 'Alert: nietypowa liczba nieudanych logowań', [
                'podglad' => '62 nieudanych logowań w ciągu 15 minut.',
                'naglowek' => 'nietypowa liczba nieudanych logowań',
                'zdania' => ['W ciągu ostatnich 15 minut odnotowaliśmy 62 nieudane próby logowania.',
                    'Żadne konto nie zostało zablokowane.'],
                'wiersze' => [['etykieta' => 'Nieudanych prób', 'wartosc' => '62'],
                    ['etykieta' => 'W ciągu', 'wartosc' => '15 minut']],
                'kroki' => [],
                'pilny' => true,
                'stopka_zdanie' => null,
                'akcja' => ['napis' => 'Otwórz dziennik zdarzeń', 'link' => 'https://urlopy.przyklad.invalid/admin/dziennik'],
                'firma' => $firma,
            ]],
            'alert-kadrowy' => ['alert', 'Alert: wniosek bez wpisu w kadrach', [
                'podglad' => 'Wniosek nr 118: wniosek bez wpisu w kadrach.',
                'naglowek' => 'wniosek bez wpisu w kadrach',
                'zdania' => ['Zmiana w programie kadrowym nie powiodła się, a przywrócenie wpisów też nie.',
                    'Urlop NIE jest zapisany w kadrach, a pracownik mógł już wyjechać.'],
                'wiersze' => [['etykieta' => 'Wniosek', 'wartosc' => 'nr 118'],
                    ['etykieta' => 'Kod z programu kadrowego', 'wartosc' => 'R2-409']],
                'kroki' => [],
                'pilny' => true,
                'stopka_zdanie' => null,
                'akcja' => ['napis' => 'Otwórz aplikację', 'link' => 'https://urlopy.przyklad.invalid/'],
                'firma' => $firma,
            ]],
        ];

        return $rodzaje;
    }
}
