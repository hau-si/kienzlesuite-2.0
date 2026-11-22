<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Kleiner, strenger CBOR-Leser (RFC 8949) für WebAuthn: ganze Zahlen, Byte- und Textfolgen, Listen, Tabellen,
 * true/false/null. Keine unbestimmten Längen, keine Gleitkommazahlen, keine Tags; Tiefe und Größe begrenzt.
 * Byte-Folgen kommen als Objekt CborBytes zurück, damit sie sich von Text unterscheiden lassen.
 */
final class Cbor {
    private const MAX_DEPTH = 8;
    private const MAX_ITEMS = 256;

    /** Liest genau ein Element ab $offset. @return array{0:mixed, 1:int} Wert und Position danach */
    public static function decode(string $data, int $offset = 0): array {
        return self::item($data, $offset, 0);
    }

    /** Ganze Eingabe muss genau ein Element sein. */
    public static function decodeAll(string $data): mixed {
        [$value, $end] = self::decode($data);
        if ($end !== strlen($data)) throw new \UnexpectedValueException('CBOR: überzählige Daten.');
        return $value;
    }

    private static function item(string $d, int $o, int $depth): array {
        if ($depth > self::MAX_DEPTH) throw new \UnexpectedValueException('CBOR: zu tief verschachtelt.');
        if ($o >= strlen($d)) throw new \UnexpectedValueException('CBOR: Daten zu kurz.');
        $first = ord($d[$o++]);
        $major = $first >> 5;
        $info = $first & 0x1f;
        if ($major === 7) {
            return match ($info) {
                20 => [false, $o], 21 => [true, $o], 22 => [null, $o],
                default => throw new \UnexpectedValueException('CBOR: nicht unterstützter einfacher Wert.'),
            };
        }
        [$n, $o] = self::length($d, $o, $info);
        switch ($major) {
            case 0: return [$n, $o];
            case 1: return [-1 - $n, $o];
            case 2:
            case 3:
                if ($n > strlen($d) - $o) throw new \UnexpectedValueException('CBOR: Daten zu kurz.');
                $s = substr($d, $o, $n);
                if ($major === 3 && !mb_check_encoding($s, 'UTF-8')) throw new \UnexpectedValueException('CBOR: Text ist kein UTF-8.');
                return [$major === 2 ? new CborBytes($s) : $s, $o + $n];
            case 4:
                if ($n > self::MAX_ITEMS) throw new \UnexpectedValueException('CBOR: zu viele Einträge.');
                $list = [];
                for ($i = 0; $i < $n; $i++) { [$list[], $o] = self::item($d, $o, $depth + 1); }
                return [$list, $o];
            case 5:
                if ($n > self::MAX_ITEMS) throw new \UnexpectedValueException('CBOR: zu viele Einträge.');
                $map = [];
                for ($i = 0; $i < $n; $i++) {
                    [$k, $o] = self::item($d, $o, $depth + 1);
                    if (!is_int($k) && !is_string($k)) throw new \UnexpectedValueException('CBOR: ungültiger Schlüssel.');
                    if (array_key_exists($k, $map)) throw new \UnexpectedValueException('CBOR: doppelter Schlüssel.');
                    [$map[$k], $o] = self::item($d, $o, $depth + 1);
                }
                return [$map, $o];
            default:
                throw new \UnexpectedValueException('CBOR: Tags werden nicht unterstützt.');
        }
    }

    private static function length(string $d, int $o, int $info): array {
        if ($info < 24) return [$info, $o];
        $bytes = match ($info) { 24 => 1, 25 => 2, 26 => 4, 27 => 8, default => throw new \UnexpectedValueException('CBOR: unbestimmte Länge nicht unterstützt.') };
        if ($o + $bytes > strlen($d)) throw new \UnexpectedValueException('CBOR: Daten zu kurz.');
        $n = 0;
        for ($i = 0; $i < $bytes; $i++) $n = ($n << 8) | ord($d[$o + $i]);
        if ($n < 0) throw new \UnexpectedValueException('CBOR: Zahl zu groß.');
        return [$n, $o + $bytes];
    }
}
