<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** Byte-Folge aus CBOR (zur Unterscheidung von Text). */
final class CborBytes {
    public function __construct(public readonly string $bytes) {}
}
