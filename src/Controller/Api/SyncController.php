<?php

declare(strict_types=1);

namespace Wezurlop\Controller\Api;

use Throwable;
use Wezurlop\Http\ApiKernel;
use Wezurlop\Http\Middleware\ApiKeyAuth;
use Wezurlop\Http\Request;
use Wezurlop\Http\Response;
use Wezurlop\Repository\SyncRepository;
use Wezurlop\Service\Sync\ApiBody;
use Wezurlop\Service\Sync\ApiError;
use Wezurlop\Service\Sync\SyncService;
use Wezurlop\Support\Logger;

/**
 * API dla connectora: /api/v1/sync/{stan,start,dane,przeglad,zadania,wyniki,koniec,zgloszenie-firmy}
 * (docs/api-connector.md). STREFA JAWNA. Kontroler tylko przyjmuje żądanie, woła SyncService i zwraca JSON.
 *
 * X-Idempotency-Key (tylko POST): powtórzona paczka zwraca tę samą odpowiedź bez ponownego zapisu.
 */
final class SyncController
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly SyncRepository $store,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params): Response
    {
        return $this->post($request, 'start', fn (array $key, array $body, bool $idem): array => $this->sync->start($key, $body, $idem));
    }

    /** @param array<string, string> $params */
    public function dane(Request $request, array $params): Response
    {
        return $this->post($request, 'dane', fn (array $key, array $body, bool $idem): array => $this->sync->dane($key, $body, $idem));
    }

    /** @param array<string, string> $params */
    public function przeglad(Request $request, array $params): Response
    {
        return $this->post($request, 'przeglad', fn (array $key, array $body, bool $idem): array => $this->sync->przeglad($key, $body, $idem));
    }

    /** @param array<string, string> $params */
    public function zadania(Request $request, array $params): Response
    {
        $key = $request->attribute(ApiKeyAuth::ATTRIBUTE);
        $query = [];
        foreach (['nip', 'przebiegId', 'limit'] as $name) {
            $value = $request->query($name);
            if (is_string($value)) {
                $query[$name] = $value;
            }
        }

        try {
            return Response::json($this->sync->zadania($key, $query));
        } catch (Throwable $e) {
            $this->countTransient($e, $query['przebiegId'] ?? null);
            throw $e;
        }
    }

    /**
     * Test połączenia (docs/api-connector.md 2.x): wąska odpowiedź o kluczu i firmie, bez przebiegu.
     *
     * @param array<string, string> $params
     */
    public function stan(Request $request, array $params): Response
    {
        $key = $request->attribute(ApiKeyAuth::ATTRIBUTE);
        $nip = $request->query('nip');

        return Response::json($this->sync->stan($key, is_string($nip) ? ['nip' => $nip] : []));
    }

    /**
     * 503 (baza zajęta) liczony w opisie przebiegu — transakcja paczki jest już wycofana, więc licznik idzie
     * osobnym poleceniem. Błąd licznika nie może zasłonić właściwej odpowiedzi.
     */
    private function countTransient(Throwable $e, mixed $runUuid): void
    {
        if (!ApiKernel::isTransient($e) || !is_string($runUuid) || strlen($runUuid) > 36) {
            return;
        }
        try {
            $this->store->countTransient($runUuid);
        } catch (Throwable $inner) {
            $this->logger->warning('API connectora: nie zapisano licznika 503 w przebiegu', ['error' => $inner->getMessage()]);
        }
    }

    /** @param array<string, string> $params */
    public function wyniki(Request $request, array $params): Response
    {
        return $this->post($request, 'wyniki', fn (array $key, array $body, bool $idem): array => $this->sync->wyniki($key, $body, $idem));
    }

    /** @param array<string, string> $params */
    public function koniec(Request $request, array $params): Response
    {
        return $this->post($request, 'koniec', fn (array $key, array $body, bool $idem): array => $this->sync->koniec($key, $body, $idem));
    }

    /** @param array<string, string> $params */
    public function zgloszenieFirmy(Request $request, array $params): Response
    {
        return $this->post($request, 'zgloszenie', fn (array $key, array $body, bool $idem): array => $this->sync->zgloszenieFirmy($key, $body));
    }

    /**
     * Wspólna obsługa POST: treść (limity, gzip), idempotencja, zapamiętanie odpowiedzi.
     *
     * @param callable(array{id: int, name: string}, array<string, mixed>, bool): array<string, mixed> $operation
     */
    private function post(Request $request, string $name, callable $operation, int $status = 200): Response
    {
        /** @var array{id: int, name: string} $key */
        $key = $request->attribute(ApiKeyAuth::ATTRIBUTE);
        $idem = $request->header('X-Idempotency-Key');
        $idem = is_string($idem) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', trim($idem)) === 1 ? trim($idem) : null;
        if ($idem !== null) {
            $stored = $this->store->storedResponse($key['id'], $idem);
            if ($stored !== null) {
                return (new Response($stored['body'], $stored['status'], ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']))
                    ->withHeader('X-Idempotent-Replay', 'true');
            }
        }
        $body = ApiBody::parse(ApiBody::readInput(), $request->header('Content-Encoding'));
        try {
            $result = $operation($key, $body, $idem !== null);
            // Zgłoszenie firmy: czeka na decyzję → 202; stan rozstrzygnięty (zatwierdzona/odrzucona) → 200.
            $response = Response::json($result, $name === 'zgloszenie' && ($result['status'] ?? null) === 'oczekuje' ? 202 : $status);
        } catch (ApiError $e) {
            // Odpowiedź błędna też jest zapamiętywana (np. 422) — powtórzona paczka dostaje tę samą.
            $eventId = bin2hex(random_bytes(4));
            $this->logger->warning('API connectora: odmowa',
                ['zdarzenie' => $eventId, 'operacja' => $name, 'kod' => $e->kod, 'status' => $e->status] + $e->log);
            $response = ApiErrorResponse::from($e, $eventId);
        } catch (Throwable $e) {
            $this->countTransient($e, $body['przebiegId'] ?? null);
            throw $e;
        }
        if ($idem !== null && $response->status() < 500 && $response->status() !== 429) {
            $this->store->storeResponse($key['id'], $idem, $name, $response->status(), $response->body());
        }

        return $response;
    }
}
