<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Console\Simulator;

/**
 * Stan symulowanego connectora dla jednej firmy: klucz, kursory z ostatnich odpowiedzi, bieżący przebieg.
 * Kursor zapamiętujemy dopiero po potwierdzeniu paczki — tak jak prawdziwy connector.
 */
final class Connector
{
    /** @var array<string, ?string> */
    public array $cursors = [];
    public ?string $runId = null;
    /** Identyfikator klucza w bazie — potrzebny scenariuszom, które klucz wymieniają albo unieważniają. */
    public int $keyId = 0;
    /** @var list<float> */
    public array $times = [];

    public function __construct(private readonly HttpClient $http, public readonly string $key, public readonly string $nip)
    {
    }

    /**
     * Klucz wymieniony u administratora: ta sama instalacja, ten sam przebieg i te same kursory,
     * tylko nowe poświadczenie — connector po prostu wkleja nowy klucz do konfiguracji.
     */
    public function withKey(string $key, int $keyId): self
    {
        $next = new self($this->http, $key, $this->nip);
        $next->runId = $this->runId;
        $next->cursors = $this->cursors;
        $next->keyId = $keyId;

        return $next;
    }

    /**
     * @param array<string, mixed> $body
     * @param string|false|null $idempotency null = nowy klucz, false = bez nagłówka
     * @param array<string, string> $headers dodatkowe/nadpisane nagłówki
     * @return array{status: int, body: string, json: mixed, headers: array<string, string>, ms: float}
     */
    public function post(string $operation, array $body, string|false|null $idempotency = null, array $headers = [], ?string $raw = null): array
    {
        $base = $this->headers();
        if ($idempotency !== false) {
            $base['X-Idempotency-Key'] = $idempotency ?? self::uuid();
        }
        $base['Content-Type'] = 'application/json; charset=utf-8';
        $r = $this->http->request('POST', '/api/v1/sync/' . $operation, $raw ?? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), array_merge($base, $headers));
        $this->times[] = $r['ms'];

        return $r;
    }

    /**
     * @param array<string, scalar> $query
     * @return array{status: int, body: string, json: mixed, headers: array<string, string>, ms: float}
     */
    public function get(string $operation, array $query): array
    {
        $r = $this->http->request('GET', '/api/v1/sync/' . $operation . '?' . http_build_query($query), null, $this->headers());
        $this->times[] = $r['ms'];

        return $r;
    }

    /**
     * Otwarcie przebiegu z kursorami, które connector ma u siebie.
     *
     * @param array<string, mixed> $extra
     * @return array{status: int, body: string, json: mixed, headers: array<string, string>, ms: float}
     */
    public function start(array $extra = []): array
    {
        $body = [
            'nip' => $this->nip, 'wersjaConnectora' => '1.0.0', 'wersjaR2' => '10.10.33',
            'ostatniUdanyCykl' => null, 'stan' => 'ok', 'stanOpis' => null, 'kursory' => (object) array_filter($this->cursors, 'is_string'),
        ];
        $r = $this->post('start', array_merge($body, $extra));
        if ($r['status'] === 200 && is_array($r['json'])) {
            $this->runId = (string) $r['json']['przebiegId'];
            foreach ($r['json']['zbiory'] as $zbior => $z) {
                // Tryb pełny: connector zaczyna zbiór od zera (jego kursor przestaje obowiązywać).
                if ($z['tryb'] === 'pelny') {
                    $this->cursors[$zbior] = null;
                }
            }
        }

        return $r;
    }

    /**
     * Wysłanie zbioru w paczkach po $size; kursor zapamiętany po każdej potwierdzonej paczce.
     *
     * @param list<array<string, mixed>> $records
     * @return list<array{status: int, body: string, json: mixed, headers: array<string, string>, ms: float}>
     */
    public function send(string $zbior, array $records, int $size = 500, string $operation = 'dane'): array
    {
        $out = [];
        $chunks = $records === [] ? [[]] : array_chunk($records, $size);
        foreach ($chunks as $i => $chunk) {
            $body = [
                'nip' => $this->nip, 'przebiegId' => $this->runId, 'zbior' => $zbior, 'paczka' => $i + 1,
                'ostatnia' => $i === count($chunks) - 1, ($operation === 'dane' ? 'rekordy' : 'pozycje') => $chunk,
            ];
            $r = $this->post($operation, $body);
            if ($r['status'] === 200 && is_array($r['json'])) {
                $this->cursors[$zbior] = (string) $r['json']['kursor'];
            }
            $out[] = $r;
        }

        return $out;
    }

    /** @return array{status: int, body: string, json: mixed, headers: array<string, string>, ms: float} */
    public function koniec(): array
    {
        return $this->post('koniec', [
            'nip' => $this->nip, 'przebiegId' => $this->runId, 'wyslano' => (object) [], 'usuniete' => (object) [], 'odrzucone' => [],
            'zadaniaPobrane' => 0, 'wynikiWyslane' => 0, 'pominietePola' => 0, 'czasMs' => 0,
        ]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->key, 'X-Api-Key' => $this->key, 'X-Protokol' => '1', 'Accept' => 'application/json'];
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
