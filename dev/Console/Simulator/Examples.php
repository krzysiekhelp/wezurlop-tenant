<?php

declare(strict_types=1);

namespace Wezurlop\Dev\Console\Simulator;

use RuntimeException;

/**
 * Przykłady z docs/przyklady-api.md jako testy akceptacyjne: bloki ```json z każdego podrozdziału
 * („### 1.2 …”), w kolejności. Symulator wysyła żądania z dokumentu i porównuje odpowiedzi z dokumentem —
 * zmiana dokumentu zmienia test, bez przepisywania wartości do kodu.
 */
final class Examples
{
    /** @var array<string, list<mixed>> */
    private array $blocks = [];

    public function __construct(string $file)
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Brak pliku przykładów: ' . $file);
        }
        $section = null;
        $buffer = null;
        foreach ($lines as $no => $line) {
            if ($buffer === null && preg_match('/^###\s+(\d+\.\d+)/', $line, $m) === 1) {
                $section = $m[1];
            } elseif ($buffer === null && trim($line) === '```json') {
                $buffer = [];
            } elseif ($buffer !== null && trim($line) === '```') {
                $decoded = json_decode(implode("\n", $buffer), true);
                if ($decoded === null) {
                    throw new RuntimeException('Blok JSON w przykładzie ' . $section . ' (linia ' . $no . ') nie jest poprawnym JSON-em');
                }
                $this->blocks[(string) $section][] = $decoded;
                $buffer = null;
            } elseif ($buffer !== null) {
                $buffer[] = $line;
            }
        }
    }

    /** @return array<string, mixed> */
    public function get(string $section, int $index = 0): array
    {
        $block = $this->blocks[$section][$index] ?? null;
        if (!is_array($block)) {
            throw new RuntimeException('Brak bloku ' . $index . ' w przykładzie ' . $section);
        }

        return $block;
    }

    /**
     * Skrócone wartości z dokumentu („sha256:3b1f…”) → pełne 64 znaki, deterministycznie: ta sama
     * skrócona suma zawsze daje tę samą pełną, więc przegląd (4.2) zgadza się z danymi (1.2).
     */
    public static function fullSum(string $value): string
    {
        if (preg_match('/^sha256:([0-9a-f]*)…?$/u', $value, $m) === 1 && strlen($value) !== 71) {
            return 'sha256:' . hash('sha256', 'przyklad:' . $m[1]);
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function expandSums(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::expandSums($v);
            } elseif ($k === 'suma' && is_string($v)) {
                $data[$k] = self::fullSum($v);
            }
        }

        return $data;
    }
}
