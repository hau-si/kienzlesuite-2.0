<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** Code passt zu einer anderen Uhrzeit: Die Uhr des Servers geht falsch (Abweichung in Sekunden, Telefon minus Server). */
final class ClockProblem extends Problem {
    public function __construct(public readonly int $skew) {
        $min = (int)round(abs($skew) / 60);
        parent::__construct('Der Code passt, aber die Uhr des Servers geht ' . ($min >= 1 ? 'um etwa ' . $min . ' Minute' . ($min === 1 ? '' : 'n') : 'um ' . abs($skew) . ' Sekunden')
            . ($skew > 0 ? ' nach' : ' vor') . '. Die Uhrzeit des Servers lässt sich hier korrigieren.', 422);
    }
}
