<?php

declare(strict_types=1);

namespace Wezurlop\Http;

use Wezurlop\Entity\CurrentUser;
use Wezurlop\Support\EkranyBelki;
use Wezurlop\Support\Strefa;
use Wezurlop\Support\UrlGenerator;

/**
 * Dane bieżącego żądania dla funkcji Twiga current_user() i can(). Wyłącznie pola do wyświetlenia —
 * szablon nie dostaje obiektu konta (CLAUDE.md §3, §5).
 */
final class ViewContext
{
    private ?CurrentUser $user = null;
    private ?int $pendingSignups = null;
    /** @var (callable(): int)|null policzone leniwie, bo nie każda strona pyta o licznik */
    private $countPendingSignups = null;

    private ?string $timezone = null;
    /** @var (callable(): string)|null strefa tenanta czytana leniwie — zapytanie dopiero przy pierwszej dacie */
    private $readTimezone = null;

    /** Zakres ekranu z definicji trasy: `spolka` albo `tenant` (zlecenie 10.10.2026 §1). */
    private ?string $zakres = null;
    /** @var array<string, mixed>|null|false `false` = jeszcze nie liczone */
    private array|null|false $znacznik = false;
    /** @var (callable(CurrentUser): array<string, mixed>)|null */
    private $readZnacznik = null;

    public function __construct(
        private readonly string $path,
        private readonly UrlGenerator $urls,
        /**
         * ⚠ **Stan przeglądania, który musi przeżyć przełączenie spółki** — dziś tylko kalendarz
         * (widok i miesiąc). Bez tego przełączenie spółki na sierpniu wracało na miesiąc bieżący,
         * czyli kontrolka „gubiła" to, co człowiek oglądał. Wartości jadą do belki jako ukryte
         * pola i **są walidowane po stronie serwera** (`ContextController::powrot()`), więc to,
         * co tu przyjdzie z adresu, samo z siebie niczego nie ustala.
         *
         * @var array<string, string>
         */
        private readonly array $stanEkranu = [],
    ) {
    }

    /** @param callable(): int $counter */
    public function withSignupCounter(callable $counter): void
    {
        $this->countPendingSignups = $counter;
    }

    /** @param callable(): string $reader */
    public function withTimezone(callable $reader): void
    {
        $this->readTimezone = $reader;
    }

    /**
     * Zakres bieżącego ekranu — **z definicji trasy**, nigdy ze ścieżki w szablonie.
     *
     * ⚠ Powód stoi w `Route::ZAKRESY`: lista ścieżek zaszyta w layoucie zgniłaby przy pierwszej
     * nowej trasie, a znacznik spółki i tak by coś pokazał — czyli pokazałby nazwę spółki
     * na ekranie dotyczącym całego tenanta.
     */
    public function setZakres(?string $zakres): void
    {
        $this->zakres = $zakres;
    }

    /** @param callable(CurrentUser): array<string, mixed> $reader */
    public function withZnacznikSpolki(callable $reader): void
    {
        $this->readZnacznik = $reader;
    }

    /**
     * **Znacznik spółki do górnej belki** — jedna kontrolka na każdym ekranie (zlecenie §1).
     *
     * ⚠ Liczony **leniwie i raz na żądanie**: ekrany bez belki (logowanie, strona błędu,
     * instalator) nie pytają o nic, a ekran z belką pyta jeden raz, choćby znacznik był
     * w szablonie wywołany dwa razy.
     *
     * ⚠ Na ekranie o zakresie `tenant` znacznik **nie rozwija się** i mówi „Wszystkie firmy".
     * Bez tego kłamałby na Licencji i Integracji: sugerowałby, że widać dane jednej spółki,
     * a widać tenanta. Lista spółek jedzie do szablonu tylko wtedy, gdy wolno przełączać.
     *
     * @return array{zakres: string, przelaczalny: bool, nazwa: string, spolki: list<array<string, mixed>>,
     *     biezaca_id: ?int, ekran: string, stan: array<string, string>, fallback: bool}|null
     */
    public function znacznikSpolki(): ?array
    {
        if ($this->znacznik !== false) {
            return $this->znacznik;
        }
        if ($this->user === null || $this->readZnacznik === null || $this->zakres === null) {
            return $this->znacznik = null;
        }
        $dane = ($this->readZnacznik)($this->user);
        $spolkaTenanta = $this->zakres === 'tenant';
        $spolka = $dane['spolka'] ?? null;
        if (!$spolkaTenanta && $spolka === null) {
            // Konto bez ani jednej dostępnej spółki na ekranie spółkowym — nie ma czego pokazać;
            // ten stan obsługuje osobny ekran („konto bez przydzielonego dostępu").
            return $this->znacznik = null;
        }

        return $this->znacznik = [
            'zakres' => $this->zakres,
            // ⚠ Rozwija się **wyłącznie** na ekranie spółkowym i **tylko** gdy jest z czego
            // wybierać: kontrolka z jedną pozycją kłamie o wyborze (ta sama reguła co
            // w `combo-spolki.twig`, która właśnie znika).
            'przelaczalny' => !$spolkaTenanta && count($dane['spolki'] ?? []) > 1,
            'nazwa' => $spolkaTenanta ? 'Wszystkie firmy' : (string) $spolka['nazwa'],
            'spolki' => $spolkaTenanta ? [] : array_values($dane['spolki'] ?? []),
            'biezaca_id' => $spolkaTenanta ? null : (int) $spolka['id'],
            // ⚠ **Nazwa ekranu jedzie razem ze znacznikiem**, a nie zmienną layoutu: przełączenie
            // spółki ma wracać na ekran, na którym człowiek pracuje. Zmierzone przed tą zmianą:
            // `ekran_belki` nie było ustawiane nigdzie, więc każde przełączenie z belki lądowało
            // na pulpicie — kontrolka wyglądała na zepsutą. Biała lista nazw jest jedna
            // (`EkranyBelki`) i obsługuje ją ten sam kod, który z nazwy robi adres.
            'ekran' => EkranyBelki::nazwa($this->path),
            'stan' => $this->stanEkranu,
            // Zdanie o powrocie do innej spółki pokazujemy tylko tam, gdzie spółka ma znaczenie.
            'fallback' => !$spolkaTenanta && ($dane['fallback'] ?? false) === true,
        ];
    }

    /**
     * Strefa prezentacji tenanta (docs/system-wizualny.md §10). Czas w bazie zostaje w UTC —
     * ta wartość mówi wyłącznie, w czym go pokazać. Przed startem usług (instalator, strona błędu)
     * zostaje wartość domyślna, żeby widok nigdy nie zależał od dostępności bazy.
     */
    public function timezone(): string
    {
        $this->timezone ??= $this->readTimezone === null ? Strefa::DOMYSLNA : ($this->readTimezone)();

        return $this->timezone;
    }

    /** Czy bieżąca strona to podana sekcja (wyróżnienie w nawigacji) — porównanie ścieżek, bez zapytań. */
    public function active(string $section): bool
    {
        $current = preg_replace('#^/m(?=/|$)#', '', $this->path);
        $current = $current === '' ? '/' : $current;
        $section = '/' . ltrim($section, '/');

        return $section === '/' ? $current === '/' : ($current === $section || str_starts_with($current, $section . '/'));
    }

    public function variant(): string
    {
        return $this->urls->variant();
    }

    public function setUser(CurrentUser $user): void
    {
        $this->user = $user;
    }

    /** @return array{name: string, company: ?string, employment_count: int, two_factor: bool}|null */
    public function currentUser(): ?array
    {
        if ($this->user === null) {
            return null;
        }

        return [
            'name'             => $this->user->displayName,
            'company'          => $this->user->activeEmployment()?->companyName,
            'employment_count' => $this->user->employmentCount,
            'two_factor'       => $this->user->twoFactorEnabled,
        ];
    }

    /** Liczba zgłoszeń firm czekających na decyzję — liczona raz na żądanie, tylko gdy szablon o nią spyta. */
    public function pendingSignups(): int
    {
        $this->pendingSignups ??= $this->countPendingSignups === null ? 0 : ($this->countPendingSignups)();

        return $this->pendingSignups;
    }

    /** Ukrywa elementy interfejsu — NIE zabezpiecza akcji (to robi Authorize). */
    public function can(string $permission): bool
    {
        return $this->user !== null && $this->user->can($permission);
    }
}
