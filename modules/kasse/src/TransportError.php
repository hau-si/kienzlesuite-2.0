<?php
declare(strict_types=1);
namespace KienzleKasse;

final class TransportError extends \RuntimeException {
    public function __construct(string $message, public readonly bool $ambiguous = true, public readonly int $http = 0) { parent::__construct($message); }
}
