<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Console\Simulator;

/**
 * Asercje symulatora i porównanie odpowiedzi z przykładem „co do pola”: ten sam zestaw kluczy
 * na każdym poziomie, te same typy i wartości — poza polami z natury zmiennymi (identyfikatory
 * przebiegu, zdarzenia, requestId), które sprawdzamy wzorcem.
 */
final class Checker
{
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    public const EVENT_ID = '/^[0-9a-f]{8}$/';
    public const REQUEST_ID = '/^wu-[0-9A-Z]{26}$/';
    public const CURSOR = '/^c-\d+$/';

    private int $ok = 0;
    /** @var list<string> */
    private array $failures = [];
    private string $scope = '';

    /** @param resource $out */
    public function __construct(private $out)
    {
    }

    public function scope(string $scope): void
    {
        $this->scope = $scope;
        fwrite($this->out, "\n  ── " . $scope . "\n");
    }

    public function that(bool $condition, string $what, string $detail = ''): bool
    {
        if ($condition) {
            $this->ok++;
            fwrite($this->out, '    ✓ ' . $what . "\n");
        } else {
            $this->failures[] = $this->scope . ': ' . $what . ($detail !== '' ? ' — ' . $detail : '');
            fwrite($this->out, '    ✗ ' . $what . ($detail !== '' ? ' — ' . $detail : '') . "\n");
        }

        return $condition;
    }

    /**
     * @param array{status: int, json: mixed, body: string} $response
     * @param array<string, string> $variable ścieżka (z * dla indeksu listy) → wzorzec
     * @param list<string> $subset ścieżki obiektów skróconych w dokumencie („…”) — wystarczą klucze z przykładu
     */
    public function example(string $name, array $response, int $status, array $expected, array $variable = [], array $subset = []): bool
    {
        $errors = [];
        if ($response['status'] !== $status) {
            $errors[] = 'HTTP ' . $response['status'] . ' zamiast ' . $status;
        }
        if (!is_array($response['json'])) {
            $errors[] = 'odpowiedź nie jest JSON-em: ' . mb_substr($response['body'], 0, 200);
        } else {
            self::compare($expected, $response['json'], '', $variable, $subset, $errors);
        }

        return $this->that($errors === [], 'przykład ' . $name . ' — zgodny co do pola', implode('; ', array_slice($errors, 0, 5)));
    }

    /**
     * @param array<string, string> $variable
     * @param list<string> $subset
     * @param list<string> $errors
     */
    private static function compare(mixed $expected, mixed $actual, string $path, array $variable, array $subset, array &$errors): void
    {
        $pattern = $variable[preg_replace('/\.\d+(?=\.|$)/', '.*', $path) ?? $path] ?? null;
        if ($pattern !== null) {
            if (!is_string($actual) || preg_match($pattern, $actual) !== 1) {
                $errors[] = ($path === '' ? '(korzeń)' : $path) . ': ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' nie pasuje do ' . $pattern;
            }

            return;
        }
        if (is_array($expected)) {
            if (!is_array($actual)) {
                $errors[] = $path . ': oczekiwano ' . (array_is_list($expected) ? 'listy' : 'obiektu') . ', jest ' . json_encode($actual, JSON_UNESCAPED_UNICODE);

                return;
            }
            if ($expected !== [] && array_is_list($expected)) {
                if (!array_is_list($actual) || count($actual) !== count($expected)) {
                    $errors[] = $path . ': lista ' . count($actual) . ' elementów zamiast ' . count($expected);

                    return;
                }
            } else {
                $missing = array_diff(array_keys($expected), array_keys($actual));
                $extra = in_array(ltrim($path, '.'), $subset, true) ? [] : array_diff(array_keys($actual), array_keys($expected));
                if ($missing !== []) {
                    $errors[] = ($path === '' ? '(korzeń)' : $path) . ': brak pól ' . implode(', ', $missing);
                }
                if ($extra !== []) {
                    $errors[] = ($path === '' ? '(korzeń)' : $path) . ': nadmiarowe pola ' . implode(', ', $extra);
                }
            }
            foreach ($expected as $k => $v) {
                if (array_key_exists($k, $actual)) {
                    self::compare($v, $actual[$k], ltrim($path . '.' . $k, '.'), $variable, $subset, $errors);
                }
            }

            return;
        }
        if ($expected !== $actual) {
            $errors[] = $path . ': ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' zamiast ' . json_encode($expected, JSON_UNESCAPED_UNICODE);
        }
    }

    public function passed(): int
    {
        return $this->ok;
    }

    /** @return list<string> */
    public function failures(): array
    {
        return $this->failures;
    }
}
