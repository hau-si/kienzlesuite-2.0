<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** Fehler mit Meldung für die Oberfläche und HTTP-Status. Übernommen aus kienzle-suite-beta (src/Problem.php). */
final class Problem extends \RuntimeException {
    public function __construct(string $message, public readonly int $http = 400) { parent::__construct($message); }
}
