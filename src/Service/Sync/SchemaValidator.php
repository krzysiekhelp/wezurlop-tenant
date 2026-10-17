<?php

declare(strict_types=1);

namespace Wezurlop\Service\Sync;

use DateTimeImmutable;

/**
 * Walidacja rekordów paczki według config/sync-schema.php (docs/api-connector.md 3.2). STREFA JAWNA.
 *
 * - pole wymagane: brak klucza albo null → błąd POLE_WYMAGANE (422 dla całej paczki),
 * - pole opcjonalne: brak klucza = null,
 * - zły typ → ZLY_TYP; za długi tekst → ZA_DLUGIE,
 * - ⚠ liczby całkowite mogą być ujemne (korektyMin, pozostaloMin) — żadnego „≥ 0”,
 * - pole spoza listy → ignorowane i liczone (pominietePola); nigdy nie trafia dalej.
 *
 * Wynik zawiera wyłącznie pola z listy — to jest jedyna droga danych z connectora do aplikacji.
 */
final class SchemaValidator
{
    public const ID_MAX = 64;

    /** @param array<string, array<string, array{0: string, 1: bool, 2?: mixed}>> $schema */
    public function __construct(private readonly array $schema)
    {
    }

    public function knows(string $zbior): bool
    {
        return isset($this->schema[$zbior]);
    }

    /**
     * @param list<mixed> $records
     * @return array{records: list<array<string, mixed>>, errors: list<array{id: ?string, kod: string, pole: string}>, skipped: int}
     */
    public function validate(string $zbior, array $records): array
    {
        $fields = $this->schema[$zbior];
        $valid = [];
        $errors = [];
        $skipped = 0;
        foreach ($records as $record) {
            if (!is_array($record) || array_is_list($record) && $record !== []) {
                $errors[] = ['id' => null, 'kod' => 'ZLY_TYP', 'pole' => 'rekord'];
                continue;
            }
            $id = $record['id'] ?? null;
            if (!is_string($id) || $id === '' || mb_strlen($id) > self::ID_MAX) {
                $errors[] = ['id' => is_scalar($id) ? (string) $id : null, 'kod' => $id === null ? 'POLE_WYMAGANE' : 'ZLY_TYP', 'pole' => 'id'];
                continue;
            }

            // Usunięcie: { "id": "…", "usuniety": true } — bez sumy i pozostałych pól.
            if (array_key_exists('usuniety', $record)) {
                if ($record['usuniety'] !== true) {
                    $errors[] = ['id' => $id, 'kod' => 'ZLY_TYP', 'pole' => 'usuniety'];
                    continue;
                }
                $skipped += count(array_diff(array_keys($record), ['id', 'usuniety']));
                $valid[] = ['id' => $id, 'usuniety' => true];
                continue;
            }

            $suma = $record['suma'] ?? null;
            if ($suma === null) {
                $errors[] = ['id' => $id, 'kod' => 'POLE_WYMAGANE', 'pole' => 'suma'];
                continue;
            }
            if (!is_string($suma) || preg_match('/^sha256:[0-9a-f]{64}$/', $suma) !== 1) {
                $errors[] = ['id' => $id, 'kod' => 'ZLY_TYP', 'pole' => 'suma'];
                continue;
            }

            $clean = ['id' => $id, 'suma' => $suma];
            $recordErrors = [];
            $skipped += $this->fields($fields, $record, $clean, $recordErrors, $id, '', ['id', 'suma']);
            if ($recordErrors !== []) {
                array_push($errors, ...$recordErrors);
                continue;
            }
            $valid[] = $clean;
        }

        return ['records' => $valid, 'errors' => $errors, 'skipped' => $skipped];
    }

    /**
     * @param array<string, array{0: string, 1: bool, 2?: mixed}> $fields
     * @param array<string, mixed> $input
     * @param array<string, mixed> $out
     * @param list<array{id: ?string, kod: string, pole: string}> $errors
     * @param list<string> $reserved
     * @return int liczba pól pominiętych
     */
    private function fields(array $fields, array $input, array &$out, array &$errors, string $id, string $prefix, array $reserved = []): int
    {
        $skipped = count(array_diff(array_keys($input), array_keys($fields), $reserved));
        foreach ($fields as $name => $spec) {
            [$type, $required] = $spec;
            $value = $input[$name] ?? null;
            if ($value === null) {
                if ($required) {
                    $errors[] = ['id' => $id, 'kod' => 'POLE_WYMAGANE', 'pole' => $prefix . $name];
                }
                $out[$name] = null;
                continue;
            }
            if ($type === 'object') {
                if (!is_array($value) || (array_is_list($value) && $value !== [])) {
                    $errors[] = ['id' => $id, 'kod' => 'ZLY_TYP', 'pole' => $prefix . $name];
                    continue;
                }
                $nested = [];
                $skipped += $this->fields($spec[2], $value, $nested, $errors, $id, $prefix . $name . '.');
                $out[$name] = $nested;
                continue;
            }
            $error = $this->check($type, $value, $spec[2] ?? null);
            if ($error !== null) {
                $errors[] = ['id' => $id, 'kod' => $error, 'pole' => $prefix . $name];
                continue;
            }
            $out[$name] = $type === 'decimal' ? (float) $value : $value;
        }

        return $skipped;
    }

    private function check(string $type, mixed $value, mixed $param): ?string
    {
        return match ($type) {
            'string'    => !is_string($value) ? 'ZLY_TYP' : (is_int($param) && mb_strlen($value) > $param ? 'ZA_DLUGIE' : null),
            'int'       => is_int($value) ? null : 'ZLY_TYP',
            'decimal'   => is_int($value) || is_float($value) ? null : 'ZLY_TYP',
            'bool'      => is_bool($value) ? null : 'ZLY_TYP',
            'date'      => self::isDate($value) ? null : 'ZLY_TYP',
            'timestamp' => self::isTimestamp($value) ? null : 'ZLY_TYP',
            'date_list' => is_array($value) && array_is_list($value) && array_filter($value, static fn (mixed $d): bool => !self::isDate($d)) === [] ? null : 'ZLY_TYP',
            default     => 'ZLY_TYP',
        };
    }

    /** ISO 8601 z przesunięciem strefy, np. 2026-09-19T16:25:56+02:00 (docs/ksztalt-danych-r2.md). */
    public static function isTimestamp(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}:\d{2}$/', $value) !== 1) {
            return false;
        }

        return DateTimeImmutable::createFromFormat(DATE_ATOM, (string) preg_replace('/\.\d+/', '', $value)) !== false;
    }

    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
