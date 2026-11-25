<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Abgelehnte Rechnungsdrucke: Zeit, Grund und eine maskierte Zeilenübersicht (nur Beschriftungen und
 * Beträge, nie Namen, Geburtsdaten oder Rechnungs-PDFs). Dient der Einrichtung neuer T2med-Vorlagen.
 * Die abgelehnte PDF selbst bleibt für die Nachbearbeitung (Kasse → Einstellungen → Rechnungseingang) höchstens
 * KEEP_DAYS Tage in einem privaten Ordner (Rechte 0700/0600) und wird danach, beim Leeren der Liste und nach
 * der Übernahme gelöscht.
 */
final class InboxLog {
    private const MAX_ENTRIES = 30;
    public const KEEP_DAYS = 7;
    public const MAX_PDF = 5 * 1024 * 1024;

    public function __construct(private string $stateDir) {}

    public function path(): string { return rtrim($this->stateDir, '/') . '/invoice-rejected.json'; }
    private function folder(): string { return rtrim($this->stateDir, '/') . '/rejected'; }

    /** Pfad der gemerkten PDF; null bei ungültiger Kennung oder fehlender Datei. */
    public function pdfPath(string $id): ?string {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
        $file = $this->folder() . '/' . $id . '.pdf';
        return is_file($file) ? $file : null;
    }

    public function add(string $reason, string $source, array $lines, ?string $pdf = null): string {
        $this->purge();
        $id = bin2hex(random_bytes(16));
        $kept = false;
        if ($pdf !== null && $pdf !== '' && strlen($pdf) <= self::MAX_PDF && (is_dir($this->folder()) || @mkdir($this->folder(), 0700, true))) {
            $file = $this->folder() . '/' . $id . '.pdf';
            $kept = @file_put_contents($file, $pdf, LOCK_EX) === strlen($pdf);
            if ($kept) @chmod($file, 0600);
        }
        $entries = $this->entries();
        array_unshift($entries, ['id' => $id, 'at' => time(), 'reason' => mb_substr($reason, 0, 200), 'source' => mb_substr($source, 0, 40), 'pdf' => $kept,
            'lines' => array_map(fn($l) => mb_substr((string)$l, 0, 140), array_slice($lines, 0, 14))]);
        foreach (array_slice($entries, self::MAX_ENTRIES) as $old) $this->dropFile((string)($old['id'] ?? ''));
        $this->write(array_slice($entries, 0, self::MAX_ENTRIES));
        return $id;
    }

    /** Neueste zuerst; ohne Eintrag, dessen PDF inzwischen fehlt, wird "pdf" false. */
    public function recent(int $count = 10): array {
        $this->purge();
        $out = [];
        foreach (array_slice($this->entries(), 0, $count) as $e) { $e['pdf'] = !empty($e['pdf']) && $this->pdfPath((string)($e['id'] ?? '')) !== null; $out[] = $e; }
        return $out;
    }

    /** Einen Eintrag samt PDF entfernen (nach der Übernahme oder beim Verwerfen). */
    public function remove(string $id): void {
        $this->write(array_values(array_filter($this->entries(), fn($e) => ($e['id'] ?? '') !== $id)));
        $this->dropFile($id);
    }

    public function clear(): void {
        foreach (glob($this->folder() . '/*.pdf') ?: [] as $file) @unlink($file);
        @unlink($this->path());
    }

    /** Einträge und PDFs, die älter als KEEP_DAYS Tage sind, löschen (PDFs ohne Eintrag ebenfalls). */
    private function purge(): void {
        $limit = time() - self::KEEP_DAYS * 86400;
        $entries = $this->entries();
        $keep = array_values(array_filter($entries, fn($e) => (int)($e['at'] ?? 0) >= $limit));
        if (count($keep) !== count($entries)) $this->write($keep);
        $ids = array_column($keep, 'id');
        foreach (glob($this->folder() . '/*.pdf') ?: [] as $file) {
            if (!in_array(basename($file, '.pdf'), $ids, true) || (int)@filemtime($file) < $limit) @unlink($file);
        }
    }

    private function entries(): array {
        $data = is_file($this->path()) ? json_decode((string)@file_get_contents($this->path()), true) : [];
        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }
    private function write(array $entries): void {
        @file_put_contents($this->path(), json_encode($entries, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($this->path(), 0600);
    }
    private function dropFile(string $id): void {
        $file = $this->pdfPath($id);
        if ($file !== null) @unlink($file);
    }
}
