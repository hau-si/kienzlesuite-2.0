<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Zustand des Servers für die Startseite: freier Speicher, Arbeitsspeicher, Prozessorlast, Temperatur, Laufzeit.
 * Liest nur /proc und /sys (kein Befehl, keine Rechte nötig); fehlt ein Wert (z. B. Temperatur in einer VM), bleibt er null.
 */
final class SystemStatus {
    public function __construct(private string $proc = '/proc', private string $sys = '/sys', private string $dir = '/var/lib/kienzle') {}

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
