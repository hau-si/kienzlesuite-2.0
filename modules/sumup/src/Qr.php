<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Kleiner QR-Code-Encoder (Byte-Modus, Fehlerkorrektur M, Version 1–13, bis 331 Bytes) ohne externe Bibliothek.
 * Reicht für den EPC-QR-Code („GiroCode“) der Überweisung (Nutzlast meist unter 200 Bytes).
 */
final class Qr {
    // Version => [ECC-Bytes je Block, Blöcke Gruppe 1, Daten je Block G1, Blöcke Gruppe 2, Daten je Block G2]
    private const BLOCKS = [1 => [10, 1, 16, 0, 0], 2 => [16, 1, 28, 0, 0], 3 => [26, 1, 44, 0, 0], 4 => [18, 2, 32, 0, 0], 5 => [24, 2, 43, 0, 0],
        6 => [16, 4, 27, 0, 0], 7 => [18, 4, 31, 0, 0], 8 => [22, 2, 38, 2, 39], 9 => [22, 3, 36, 2, 37], 10 => [26, 4, 43, 1, 44],
        11 => [30, 1, 50, 4, 51], 12 => [22, 6, 36, 2, 37], 13 => [22, 8, 37, 1, 38]];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42],
        9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62]];

    /** @return list<list<bool>> Matrix (true = dunkel) */
    public static function matrix(string $data, ?int $force = null): array {
        $length = strlen($data);
        $version = 0;
        foreach (self::BLOCKS as $v => [, $b1, $d1, $b2, $d2]) {
            $bits = 4 + ($v < 10 ? 8 : 16) + 8 * $length;
            if ($bits <= 8 * ($b1 * $d1 + $b2 * $d2)) { $version = $v; break; }
        }
        if ($version === 0) throw new Problem('Der QR-Code wäre zu groß. Bitte Angaben kürzen.');
        [$ecLen, $b1, $d1, $b2, $d2] = self::BLOCKS[$version];
        $capacity = $b1 * $d1 + $b2 * $d2;
        $bits = '0100' . str_pad(decbin($length), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        $bits .= str_repeat('0', min(4, 8 * $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $words = array_map('bindec', str_split($bits, 8));
        for ($pad = 0; count($words) < $capacity; $pad++) $words[] = $pad % 2 === 0 ? 0xEC : 0x11;
        // Blöcke bilden und Fehlerkorrektur berechnen.
        $blocks = []; $ecs = []; $pos = 0;
        foreach ([[$b1, $d1], [$b2, $d2]] as [$count, $size]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($words, $pos, $size); $pos += $size;
                $blocks[] = $block; $ecs[] = self::reedSolomon($block, $ecLen);
            }
        }
        $final = [];
        for ($i = 0, $max = max($d1, $d2); $i < $max; $i++) foreach ($blocks as $block) if (isset($block[$i])) $final[] = $block[$i];
        for ($i = 0; $i < $ecLen; $i++) foreach ($ecs as $ec) $final[] = $ec[$i];
        $stream = '';
        foreach ($final as $word) $stream .= str_pad(decbin($word), 8, '0', STR_PAD_LEFT);

        $size = 17 + 4 * $version;
        [$base, $reserved] = self::functionPatterns($version, $size);
        // Daten im Zickzack platzieren.
        $cells = $base; $index = 0; $total = strlen($stream); $up = true;
        for ($x = $size - 1; $x > 0; $x -= 2) {
            if ($x === 6) $x--;
            for ($step = 0; $step < $size; $step++) {
                $y = $up ? $size - 1 - $step : $step;
                for ($dx = 0; $dx < 2; $dx++) {
                    $cx = $x - $dx;
                    if ($reserved[$y][$cx]) continue;
                    $cells[$y][$cx] = $index < $total && $stream[$index] === '1'; $index++;
                }
            }
            $up = !$up;
        }
        $best = null; $bestScore = PHP_INT_MAX;
        for ($mask = $force ?? 0; $mask < ($force === null ? 8 : $force + 1); $mask++) {
            $candidate = $cells;
            for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
                if ($reserved[$y][$x]) continue;
                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0, 1 => $y % 2 === 0, 2 => $x % 3 === 0, 3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0, 5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0, default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($flip) $candidate[$y][$x] = !$candidate[$y][$x];
            }
            self::formatBits($candidate, $size, $mask);
            $score = self::penalty($candidate, $size);
            if ($score < $bestScore) { $bestScore = $score; $best = $candidate; }
        }
        return $best;
    }

    /** Pfad (Einheit: ein Modul, ohne Ruhezone) und Kantenlänge der Matrix. */
    public static function path(string $data): array {
        $matrix = self::matrix($data); $n = count($matrix); $path = '';
        foreach ($matrix as $y => $row) {
            for ($x = 0; $x < $n; $x++) {
                if (!$row[$x]) continue;
                $run = 1; while ($x + $run < $n && $row[$x + $run]) $run++;
                $path .= 'M' . $x . ' ' . $y . 'h' . $run . 'v1h-' . $run . 'z'; $x += $run - 1;
            }
        }
        return [$path, $n];
    }
    /** SVG (viereckige Module, 4 Module Ruhezone) als Zeichenkette, ohne Größenangaben außer viewBox. */
    public static function svg(string $data, string $attributes = ''): string {
        [$path, $n] = self::path($data); $box = $n + 8;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $box . ' ' . $box . '" shape-rendering="crispEdges" ' . $attributes . '><rect width="' . $box . '" height="' . $box . '" fill="#fff"/><path transform="translate(4 4)" d="' . $path . '" fill="#000"/></svg>';
    }

    private static function reedSolomon(array $data, int $ecLen): array {
        [$exp, $log] = self::tables();
        $generator = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= $coefficient === 0 ? 0 : $exp[($log[$coefficient] + $i) % 255];
            }
            $generator = $next;
        }
        $remainder = array_fill(0, $ecLen, 0);
        foreach ($data as $word) {
            $factor = $word ^ array_shift($remainder); $remainder[] = 0;
            if ($factor === 0) continue;
            foreach ($remainder as $i => $_) $remainder[$i] ^= $generator[$i + 1] === 0 ? 0 : $exp[($log[$generator[$i + 1]] + $log[$factor]) % 255];
        }
        return $remainder;
    }
    private static function tables(): array {
        static $tables = null;
        if ($tables) return $tables;
        $exp = []; $log = array_fill(0, 256, 0); $value = 1;
        for ($i = 0; $i < 255; $i++) { $exp[$i] = $value; $log[$value] = $i; $value <<= 1; if ($value & 0x100) $value ^= 0x11D; }
        return $tables = [$exp, $log];
    }
    /** @return array{0: list<list<bool>>, 1: list<list<bool>>} */
    private static function functionPatterns(int $version, int $size): array {
        $cells = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = $cells;
        $set = static function (int $x, int $y, bool $dark) use (&$cells, &$reserved, $size): void {
            if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) return;
            $cells[$y][$x] = $dark; $reserved[$y][$x] = true;
        };
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$ox, $oy]) {
            for ($dy = -1; $dy <= 7; $dy++) for ($dx = -1; $dx <= 7; $dx++) {
                $ring = max(abs($dx - 3), abs($dy - 3));
                $set($ox + $dx, $oy + $dy, $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6 && $ring !== 2);
            }
        }
        $positions = self::ALIGN[$version];
        foreach ($positions as $py) foreach ($positions as $px) {
            if (($px === 6 && $py === 6) || ($px === 6 && $py === end($positions)) || ($py === 6 && $px === end($positions))) continue;
            for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $set($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
        }
        for ($i = 8; $i < $size - 8; $i++) { $set($i, 6, $i % 2 === 0); $set(6, $i, $i % 2 === 0); }
        $set(8, $size - 8, true);
        for ($i = 0; $i < 9; $i++) { $reserved[8][$i] = true; $reserved[$i][8] = true; }
        for ($i = 0; $i < 8; $i++) { $reserved[8][$size - 1 - $i] = true; $reserved[$size - 1 - $i][8] = true; }
        if ($version >= 7) {
            $info = $version << 12; $rest = $info;
            for ($i = 17; $i >= 12; $i--) if ($rest & (1 << $i)) $rest ^= 0x1F25 << ($i - 12);
            $info |= $rest;
            for ($i = 0; $i < 18; $i++) {
                $bit = (bool)(($info >> $i) & 1); $a = $size - 11 + $i % 3; $b = intdiv($i, 3);
                $set($a, $b, $bit); $set($b, $a, $bit);
            }
        }
        return [$cells, $reserved];
    }
    private static function formatBits(array &$cells, int $size, int $mask): void {
        $data = (0b00 << 3) | $mask; $rest = $data << 10;
        for ($i = 14; $i >= 10; $i--) if ($rest & (1 << $i)) $rest ^= 0x537 << ($i - 10);
        $bits = (($data << 10) | $rest) ^ 0x5412;
        for ($i = 0; $i < 15; $i++) {
            $bit = (bool)(($bits >> $i) & 1);
            // Um das obere linke Suchmuster
            if ($i < 6) $cells[$i][8] = $bit; elseif ($i < 8) $cells[$i + 1][8] = $bit; elseif ($i === 8) $cells[8][7] = $bit; else $cells[8][14 - $i] = $bit;
            // Zweite Kopie
            if ($i < 8) $cells[8][$size - 1 - $i] = $bit; else $cells[$size - 15 + $i][8] = $bit;
        }
    }
    private static function penalty(array $cells, int $size): int {
        $score = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;
                for ($b = 1; $b < $size; $b++) {
                    $same = ($pass === 0 ? $cells[$a][$b] === $cells[$a][$b - 1] : $cells[$b][$a] === $cells[$b - 1][$a]);
                    if ($same) { $run++; if ($run === 5) $score += 3; elseif ($run > 5) $score++; } else $run = 1;
                }
            }
        }
        for ($y = 0; $y < $size - 1; $y++) for ($x = 0; $x < $size - 1; $x++) {
            if ($cells[$y][$x] === $cells[$y][$x + 1] && $cells[$y][$x] === $cells[$y + 1][$x] && $cells[$y][$x] === $cells[$y + 1][$x + 1]) $score += 3;
        }
        $dark = 0; foreach ($cells as $row) foreach ($row as $cell) if ($cell) $dark++;
        return $score + 10 * intdiv(abs(20 * $dark - 10 * $size * $size), $size * $size);
    }
}
