<?php

declare(strict_types=1);

namespace Wezurlop\Support;

use ErrorException;
use Throwable;
use Twig\Environment;
use Wezurlop\Http\Response;
use Wezurlop\Http\SecurityHeaders;

/**
 * Globalna obsługa błędów (CLAUDE.md §14, docs/szkielet-tenanta.md rozdz. 13).
 *
 * Użytkownik widzi ogólny komunikat i identyfikator zdarzenia; szczegóły trafiają wyłącznie
 * do logu. Rejestrowana jako pierwsza, żeby błąd w dalszych krokach bootstrapu też trafił do logu.
 */
final class ErrorHandler
{
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    private ?Environment $twig = null;

    private SecurityHeaders $headers;

    private bool $https = false;

    public function __construct(
        private readonly Logger $logger,
        private readonly string $templatesPath,
        private readonly string $basePath,
        private readonly string $frontController,
    ) {
        $this->headers = SecurityHeaders::withoutHsts();
    }

    public function register(): void
    {
        // Szczegóły błędów nigdy na ekran — także na dev; od tego jest log.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleException(...));
        register_shutdown_function($this->handleShutdown(...));
    }

    /** Po wczytaniu konfiguracji: renderer strony błędu i nagłówki z właściwym HSTS. */
    public function configure(Environment $twig, SecurityHeaders $headers, bool $https): void
    {
        $this->twig = $twig;
        $this->headers = $headers;
        $this->https = $https;
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            // Przestarzałe konstrukcje (często w bibliotekach) zapisujemy, ale nie przerywamy żądania.
            $this->logger->warning('Deprecated', ['error' => $message, 'file' => $file, 'line' => $line]);
            return true;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleException(Throwable $e): void
    {
        $this->responseFor($e)->send();
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || ($error['type'] & self::FATAL) === 0) {
            return;
        }
        $eventId = $this->newEventId();
        $this->logger->error('Błąd krytyczny', [
            'event_id' => $eventId,
            'type'     => $error['type'],
            'error'    => $error['message'],
            'file'     => $error['file'],
            'line'     => $error['line'],
        ]);
        if (!headers_sent()) {
            // Po błędzie krytycznym nie ryzykujemy renderowania szablonu.
            $this->textPage(500, $eventId)->send();
        }
    }

    public function responseFor(Throwable $e, bool $htmx = false): Response
    {
        $eventId = $this->newEventId();
        $this->logger->error('Nieobsłużony wyjątek', [
            'event_id'  => $eventId,
            'exception' => $e::class,
            'error'     => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
            'previous'  => $e->getPrevious() !== null ? $e->getPrevious()::class : null,
        ]);

        return $this->errorPage(500, $eventId, $htmx);
    }

    /**
     * Strona błędu o danym kodzie (np. 404, 419, 503). Dla żądań htmx — fragment bez layoutu.
     *
     * @param array<string, string> $extra dodatkowe zmienne szablonu (np. back_url dla 419)
     */
    public function errorPage(int $status, ?string $eventId = null, bool $htmx = false, array $extra = []): Response
    {
        $this->headers->send($this->https);
        $twig = $this->twig ?? $this->fallbackTwig();
        $template = $htmx ? 'fragments/error.twig' : 'layout/error.twig';

        try {
            $html = $twig->render($template, [
                'status'   => $status,
                'event_id' => $eventId,
            ] + $extra);
        } catch (Throwable $renderError) {
            $this->logger->error('Nie udało się wyrenderować strony błędu', [
                'event_id'  => $eventId,
                'exception' => $renderError::class,
                'error'     => $renderError->getMessage(),
            ]);

            return $this->textPage($status, $eventId);
        }

        return Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
    }

    private function textPage(int $status, ?string $eventId): Response
    {
        $this->headers->send($this->https);
        $text = "Wystąpił błąd. Spróbuj ponownie za chwilę.\n";
        if ($eventId !== null) {
            $text .= 'Identyfikator zdarzenia: ' . $eventId . "\n";
        }

        return Response::text($text, $status)->withHeader('Cache-Control', 'no-store');
    }

    private function fallbackTwig(): Environment
    {
        // Konfiguracja jeszcze nieznana: bez cache, bez debug.
        // Bez konfiguracji: wariant web, funkcje widoku bez danych użytkownika.
        return TwigFactory::create(
            $this->templatesPath,
            null,
            false,
            new UrlGenerator($this->basePath . $this->frontController, $this->basePath, '0', UrlGenerator::WEB),
            [
                'csrf_token'   => static fn (): string => '',
                'current_user' => static fn (): ?array => null,
                'can'          => static fn (string $permission): bool => false,
                'active'       => static fn (string $section): bool => false,
                // Strona błędu nie pyta bazy o nic — znacznik spółki tam nie istnieje.
                'znacznik_spolki' => static fn (): ?array => null,
            ],
        );
    }

    private function newEventId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
