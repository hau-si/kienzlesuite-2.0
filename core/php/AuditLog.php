<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Änderungsprotokoll: wer wann von wo welchen sicherheitsrelevanten Vorgang ausgelöst hat (Anmeldung, Benutzer,
 * Rechte, Einstellungen). Enthält nie Passwörter, Codes, Schlüssel, Werte von Einstellungen oder Patientendaten.
 * Grundlage: kienzle-suite-beta (src/AuditLog.php), erweitert um den Benutzer.
 */
final class AuditLog {
    private const MAX_BYTES = 1048576;

    public function __construct(private string $directory) {}

    public function path(): string { return rtrim($this->directory, '/') . '/audit.log'; }

    /** $user = Anmeldename (oder '-'), $event = kurzer Vorgang, $detail = z. B. betroffener Benutzer oder Name der Einstellung. */
    public function write(string $user, string $event, string $detail = ''): void {
        $clean = static fn(string $s, int $n): string => (string)preg_replace('/[^A-Za-z0-9_.:,@\/ -]/', '', mb_substr($s, 0, $n));
        $line = date('Y-m-d H:i:s') . ' ' . Throttle::clientAddress() . ' ' . ($clean($user, 32) ?: '-') . ' '
            . $clean(str_replace(' ', '-', $event), 40) . ($detail === '' ? '' : ' ' . $clean($detail, 100)) . "\n";
        $path = $this->path();
        if (is_file($path) && (int)@filesize($path) > self::MAX_BYTES) {   // ältere Hälfte abschneiden
            $tail = (string)@file_get_contents($path, false, null, (int)(self::MAX_BYTES / 2));
            @file_put_contents($path, substr($tail, (int)strpos($tail, "\n") + 1), LOCK_EX);
        }
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        @chmod($path, 0600);
    }

    /** Die letzten Einträge, neueste zuerst. */
    public function recent(int $count = 50): array {
        $lines = is_file($this->path()) ? (array)@file($this->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        return array_reverse(array_slice($lines, -$count));
    }
}
