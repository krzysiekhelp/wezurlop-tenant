<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Console\Simulator;

use RuntimeException;

/**
 * docs/openapi-sync-v1.json jako kontrakt: walidacja danych schematem (podzbiór OpenAPI 3.0 używany w tym
 * pliku: $ref, type, nullable, required, properties, additionalProperties, items, maxItems, enum, pattern,
 * minLength/maxLength, minimum/maximum, format date/date-time/uuid, anyOf, allOf). Plik jest w JSON-ie,
 * żeby dało się go czytać bez parsera YAML (bez nowej zależności).
 */
final class OpenApi
{
    /** @var array<string, mixed> */
    private array $doc;

    public function __construct(string $file)
    {
        $doc = json_decode((string) file_get_contents($file), true);
        if (!is_array($doc)) {
            throw new RuntimeException('Niepoprawny plik OpenAPI: ' . $file);
        }
        $this->doc = $doc;
    }

    /** @return list<string> błędy */
    public function validate(string $schemaName, mixed $data): array
    {
        $errors = [];
        $this->check(['$ref' => '#/components/schemas/' . $schemaName], $data, '', $errors);

        return $errors;
    }

    /**
     * Odpowiedź operacji: status musi być opisany, a treść zgodna ze schematem.
     *
     * @return list<string>
     */
    public function response(string $method, string $operation, int $status, mixed $json): array
    {
        $op = $this->doc['paths']['/api/v1/sync/' . $operation][strtolower($method)] ?? null;
        if (!is_array($op)) {
            return ['operacja ' . $operation . ' nie jest opisana'];
        }
        $schema = $op['responses'][(string) $status]['content']['application/json']['schema'] ?? null;
        if (!is_array($schema)) {
            return [$method . ' ' . $operation . ': status ' . $status . ' nie jest opisany'];
        }
        $errors = [];
        $this->check($schema, $json, '', $errors);

        return array_map(static fn (string $e): string => $method . ' ' . $operation . ' ' . $status . ': ' . $e, $errors);
    }

    /** @return array<string, mixed> */
    public function schema(string $name): array
    {
        return $this->doc['components']['schemas'][$name] ?? [];
    }

    /**
     * @param array<string, mixed> $s
     * @param list<string> $errors
     */
    private function check(array $s, mixed $v, string $path, array &$errors): void
    {
        $at = $path === '' ? '(korzeń)' : $path;
        if (isset($s['$ref'])) {
            $this->check($this->schema(substr((string) $s['$ref'], strlen('#/components/schemas/'))), $v, $path, $errors);

            return;
        }
        if ($v === null) {
            if (($s['nullable'] ?? false) !== true) {
                $errors[] = $at . ': null niedozwolony';
            }

            return;
        }
        foreach ($s['allOf'] ?? [] as $sub) {
            $this->check($sub, $v, $path, $errors);
        }
        if (isset($s['anyOf'])) {
            foreach ($s['anyOf'] as $sub) {
                $e = [];
                $this->check($sub, $v, $path, $e);
                if ($e === []) {
                    return;
                }
            }
            $errors[] = $at . ': nie pasuje do żadnego z wariantów';

            return;
        }
        $type = $s['type'] ?? null;
        $okType = match ($type) {
            'object'  => is_array($v) && ($v === [] || !array_is_list($v)),
            'array'   => is_array($v) && array_is_list($v),
            'string'  => is_string($v),
            'integer' => is_int($v),
            'number'  => is_int($v) || is_float($v),
            'boolean' => is_bool($v),
            default   => true,
        };
        if (!$okType) {
            $errors[] = $at . ': oczekiwano ' . $type . ', jest ' . get_debug_type($v);

            return;
        }
        if (isset($s['enum']) && !in_array($v, $s['enum'], true)) {
            $errors[] = $at . ': wartość ' . json_encode($v, JSON_UNESCAPED_UNICODE) . ' spoza ' . json_encode($s['enum'], JSON_UNESCAPED_UNICODE);
        }
        if (is_string($v)) {
            if (isset($s['pattern']) && preg_match('/' . str_replace('/', '\/', $s['pattern']) . '/u', $v) !== 1) {
                $errors[] = $at . ': „' . $v . '” nie pasuje do ' . $s['pattern'];
            }
            if (isset($s['maxLength']) && mb_strlen($v) > $s['maxLength']) {
                $errors[] = $at . ': dłuższe niż ' . $s['maxLength'];
            }
            if (isset($s['minLength']) && mb_strlen($v) < $s['minLength']) {
                $errors[] = $at . ': krótsze niż ' . $s['minLength'];
            }
            $format = $s['format'] ?? null;
            if (($format === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1)
                || ($format === 'date-time' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $v) !== 1)
                || ($format === 'uuid' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) !== 1)) {
                $errors[] = $at . ': „' . $v . '” to nie ' . $format;
            }
        }
        if (is_int($v) || is_float($v)) {
            if (isset($s['minimum']) && $v < $s['minimum']) {
                $errors[] = $at . ': mniej niż ' . $s['minimum'];
            }
            if (isset($s['maximum']) && $v > $s['maximum']) {
                $errors[] = $at . ': więcej niż ' . $s['maximum'];
            }
        }
        if ($type === 'array') {
            if (isset($s['maxItems']) && count($v) > $s['maxItems']) {
                $errors[] = $at . ': ponad ' . $s['maxItems'] . ' elementów';
            }
            foreach ($v as $i => $item) {
                $this->check($s['items'] ?? [], $item, $path . '.' . $i, $errors);
            }
        }
        if ($type === 'object') {
            foreach ($s['required'] ?? [] as $r) {
                if (!array_key_exists($r, $v)) {
                    $errors[] = $at . ': brak wymaganego pola ' . $r;
                }
            }
            $props = $s['properties'] ?? [];
            $extra = $s['additionalProperties'] ?? true;
            foreach ($v as $k => $item) {
                if (isset($props[$k])) {
                    $this->check($props[$k], $item, ltrim($path . '.' . $k, '.'), $errors);
                } elseif ($extra === false) {
                    $errors[] = $at . ': pole spoza schematu ' . $k;
                } elseif (is_array($extra)) {
                    $this->check($extra, $item, ltrim($path . '.' . $k, '.'), $errors);
                }
            }
        }
    }
}
