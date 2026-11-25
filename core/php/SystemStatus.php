<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Zustand des Servers für die Startseite: freier Speicher, Arbeitsspeicher, Prozessorlast, Temperatur, Laufzeit.
 * Liest nur /proc und /sys (kein Befehl, keine Rechte nötig); fehlt ein Wert (z. B. Temperatur in einer VM), bleibt er null.
 */
final class SystemStatus {
    public function __construct(private string $proc = '/proc', private string $sys = '/sys', private string $dir = '/var/lib/kienzle/core') {}

    /** @return array{disk:?array{frei:float,gesamt:float},ram:?array{frei:float,gesamt:float},cpu:?array{last:float,kerne:int,prozent:int},temp:?float,laufzeit:?int} */
    public function read(): array {
        $path = is_dir($this->dir) ? $this->dir : '/';
        $frei = @disk_free_space($path);
        $gesamt = @disk_total_space($path);
        return ['disk' => $frei !== false && $gesamt ? ['frei' => (float)$frei, 'gesamt' => (float)$gesamt] : null,
            'ram' => $this->ram(), 'cpu' => $this->cpu(), 'temp' => $this->temperatur(), 'laufzeit' => $this->laufzeit()];
    }

    private function ram(): ?array {
        $t = (string)@file_get_contents($this->proc . '/meminfo', false, null, 0, 8192);
        if (!preg_match('/^MemTotal:\s+(\d+) kB/m', $t, $a) || !preg_match('/^MemAvailable:\s+(\d+) kB/m', $t, $b) || (int)$a[1] <= 0) return null;
        return ['frei' => (float)$b[1] * 1024, 'gesamt' => (float)$a[1] * 1024];
    }

    private function cpu(): ?array {
        $l = (string)@file_get_contents($this->proc . '/loadavg', false, null, 0, 200);
        if (!preg_match('/^(\d+(?:\.\d+)?)\s/', $l, $m)) return null;
        $kerne = max(1, preg_match_all('/^processor\s*:/m', (string)@file_get_contents($this->proc . '/cpuinfo', false, null, 0, 262144)));
        return ['last' => (float)$m[1], 'kerne' => $kerne, 'prozent' => (int)min(100, round(100 * (float)$m[1] / $kerne))];
    }

    /** Höchste gemeldete Temperatur in °C (thermal_zone oder hwmon), plausibel zwischen 1 und 150. */
    private function temperatur(): ?float {
        $werte = [];
        foreach ([...(glob($this->sys . '/class/thermal/thermal_zone*/temp') ?: []), ...(glob($this->sys . '/class/hwmon/hwmon*/temp*_input') ?: [])] as $f) {
            $v = trim((string)@file_get_contents($f, false, null, 0, 20));
            if (!preg_match('/^-?\d+$/', $v)) continue;
            $c = (int)$v / 1000;
            if ($c >= 1 && $c <= 150) $werte[] = $c;
        }
        return $werte ? max($werte) : null;
    }

    private function laufzeit(): ?int {
        return preg_match('/^(\d+)/', (string)@file_get_contents($this->proc . '/uptime', false, null, 0, 100), $m) ? (int)$m[1] : null;
    }

    /** Neuen Messpunkt (Tagesbeginn, belegte Bytes) anhängen, höchstens einen je Tag; Punkte älter als 400 Tage fallen weg. @param list<array{0:int,1:float}> $punkte @return list<array{0:int,1:float}> */
    public static function verlaufErgaenzen(array $punkte, float $belegt, int $jetzt): array {
        $tag = $jetzt - $jetzt % 86400;
        $punkte = array_values(array_filter($punkte, static fn($p) => is_array($p) && count($p) === 2 && $p[0] > $jetzt - 400 * 86400));
        if ($punkte && end($punkte)[0] >= $tag) { $punkte[count($punkte) - 1] = [$tag, $belegt]; return $punkte; }
        $punkte[] = [$tag, $belegt];
        return $punkte;
    }

    /**
     * Hochrechnung des Plattenbedarfs aus den Messpunkten der letzten 90 Tage (gerade Linie vom ersten zum letzten Punkt).
     * Null, solange der Verlauf kürzer als 3 Tage ist. @param list<array{0:int,1:float}> $punkte
     * @return ?array{pro_jahr:float,pro_monat:float,tage:int,monate_rest:?float}
     */
    public static function prognose(array $punkte, float $frei, int $jetzt): ?array {
        $p = array_values(array_filter($punkte, static fn($x) => is_array($x) && count($x) === 2 && $x[0] >= $jetzt - 90 * 86400));
        if (count($p) < 2) return null;
        $tage = (int)round(($p[count($p) - 1][0] - $p[0][0]) / 86400);
        if ($tage < 3) return null;
        $proTag = ($p[count($p) - 1][1] - $p[0][1]) / $tage;
        return ['pro_jahr' => $proTag * 365, 'pro_monat' => $proTag * 30.4, 'tage' => $tage, 'monate_rest' => $proTag > 0 ? $frei / ($proTag * 30.4) : null];
    }

    /** Größe lesbar: „123 GB“, „4,2 GB“, „512 MB“. */
    public static function bytes(float $b): string {
        $gb = $b / 1073741824;
        if ($gb >= 100) return number_format($gb, 0, ',', '.') . ' GB';
        if ($gb >= 1) return number_format($gb, 1, ',', '.') . ' GB';
        return number_format($b / 1048576, 0, ',', '.') . ' MB';
    }

    /** Laufzeit lesbar: „3 Tage 4 Std.“, „5 Std.“, „12 Min.“. */
    public static function dauer(int $s): string {
        if ($s >= 86400) return intdiv($s, 86400) . ($s >= 172800 ? ' Tage ' : ' Tag ') . intdiv($s % 86400, 3600) . ' Std.';
        if ($s >= 3600) return intdiv($s, 3600) . ' Std.';
        return max(1, intdiv($s, 60)) . ' Min.';
    }
}
