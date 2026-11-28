<?php
declare(strict_types=1);
namespace KienzleQm;

/**
 * Übernahme von Word-Dateien, z. B. der QEP-Musterdokumente der KBV: eine .docx-Datei oder ein ZIP mit Ordnern. Jede Datei wird ein Dokument
 * im Status Entwurf (muss angepasst und vidiert werden). Ordner im ZIP bzw. im gewählten Ordner werden als QM-Ordner angelegt. Eine Datei mit
 * gleichem Pfad wird nicht zweimal übernommen.
 */
final class Import {
    public const MAX_UPLOAD = 7_500_000;   // php post_max_size der Module: 8M
    private const MAX_DATEIEN = 500;

    /**
     * @param string $pfad relativer Pfad der Datei (aus der Ordnerauswahl des Browsers), bestimmt die Unterordner
     * @return array{angelegt:list<array{id:int,titel:string}>, hinweise:list<string>}
     */
    public static function datei(string $bytes, string $pfad, ?int $zielordner, array $tags, array $wer, bool $ordnerUebernehmen = true): array {
        if ($bytes === '' || strlen($bytes) > self::MAX_UPLOAD) throw new \InvalidArgumentException('Datei leer oder größer als 7,5 MB. Große Sammlungen bitte als Ordner wählen (Dateien werden einzeln übertragen).');
        $teile = self::pfadTeile($pfad);
        if (!$teile) throw new \InvalidArgumentException('Dateiname fehlt.');
        $name = array_pop($teile);
        $endung = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($zielordner !== null && !Dokumente::ordner($zielordner)) throw new \InvalidArgumentException('Zielordner nicht gefunden.');
        $tmp = tempnam(Speicher::ordner('tmp'), 'imp');
        if ($tmp === false || file_put_contents($tmp, $bytes) !== strlen($bytes)) throw new \RuntimeException('Zwischenablage nicht beschreibbar.');
        try {
            if (!str_starts_with($bytes, "PK\x03\x04")) throw new \InvalidArgumentException($name . ': keine Word-Datei (.docx) und kein ZIP. Ältere .doc-Dateien bitte in Word als .docx speichern.');
            if ($endung === 'zip') return self::zip($tmp, $teile, $zielordner, $tags, $wer, $ordnerUebernehmen);
            if ($endung !== 'docx') throw new \InvalidArgumentException($name . ': nur .docx und .zip werden übernommen.');
            $ergebnis = ['angelegt' => [], 'hinweise' => []];
            self::eine($tmp, array_merge($teile, [$name]), $zielordner, $tags, $wer, $ordnerUebernehmen, $ergebnis);
            return $ergebnis;
        } finally {
            @unlink($tmp);
        }
    }

    /** @return list<string> */
    private static function pfadTeile(string $pfad): array {
        $out = [];
        foreach (preg_split('#[/\\\\]+#', $pfad) ?: [] as $t) {
            $t = trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', $t));
            if ($t === '' || $t === '.' || $t === '..') continue;
            $out[] = mb_substr($t, 0, 120);
        }
        return array_slice($out, -8);
    }

    private static function zip(string $datei, array $vorne, ?int $zielordner, array $tags, array $wer, bool $ordnerUebernehmen): array {
        $zip = new \ZipArchive();
        if ($zip->open($datei, \ZipArchive::RDONLY) !== true) throw new \InvalidArgumentException('Das ZIP lässt sich nicht öffnen.');
        $ergebnis = ['angelegt' => [], 'hinweise' => []];
        try {
            $docx = [];
            $andere = 0;
            for ($i = 0; $i < $zip->numFiles && $i < 20000; $i++) {
                $st = $zip->statIndex($i);
                if ($st === false) continue;
                $n = (string)$st['name'];
                if (str_ends_with($n, '/') || str_contains($n, '__MACOSX') || preg_match('#(^|/)(~\$|\._|\.)#', $n)) continue;
                if (strtolower(pathinfo($n, PATHINFO_EXTENSION)) === 'docx') $docx[] = $i;
                else $andere++;
            }
            if (!$docx) throw new \InvalidArgumentException('Im ZIP liegen keine Word-Dateien (.docx).');
            if (count($docx) > self::MAX_DATEIEN) throw new \InvalidArgumentException('Höchstens ' . self::MAX_DATEIEN . ' Word-Dateien je ZIP.');
            if ($andere > 0) $ergebnis['hinweise'][] = $andere . ' andere Dateien (z. B. PDF, Excel) wurden nicht übernommen.';
            foreach ($docx as $i) {
                $st = $zip->statIndex($i);
                $name = self::zipName((string)$st['name']);
                if ($st['size'] > Docx::MAX_DATEI) { $ergebnis['hinweise'][] = basename($name) . ': zu groß, übersprungen.'; continue; }
                $tmp = tempnam(Speicher::ordner('tmp'), 'imp');
                try {
                    $roh = $zip->getFromIndex($i);
                    if (!is_string($roh) || $tmp === false || file_put_contents($tmp, $roh) !== strlen($roh)) { $ergebnis['hinweise'][] = basename($name) . ': nicht lesbar.'; continue; }
                    self::eine($tmp, array_merge($vorne, self::pfadTeile($name)), $zielordner, $tags, $wer, $ordnerUebernehmen, $ergebnis);
                } catch (\InvalidArgumentException $e) {
                    $ergebnis['hinweise'][] = basename($name) . ': ' . $e->getMessage();
                } finally {
                    if ($tmp !== false) @unlink($tmp);
                }
            }
        } finally {
            $zip->close();
        }
        return $ergebnis;
    }

    /** ZIP-Namen aus Windows kommen oft in CP437/CP850; nicht-UTF-8 umwandeln. */
    private static function zipName(string $n): string { return mb_check_encoding($n, 'UTF-8') ? $n : (string)mb_convert_encoding($n, 'UTF-8', 'CP850'); }

    private static function eine(string $datei, array $teile, ?int $zielordner, array $tags, array $wer, bool $ordnerUebernehmen, array &$ergebnis): void {
        $name = (string)array_pop($teile);
        $quelle = 'import:' . mb_substr(implode('/', array_merge($teile, [$name])), 0, 290);
        $st = Speicher::pdo()->prepare('SELECT id FROM dokumente WHERE quelle = ?');
        $st->execute([$quelle]);
        if ($st->fetchColumn()) { $ergebnis['hinweise'][] = $name . ': schon übernommen, übersprungen.'; return; }
        $d = Docx::lesen($datei, $name, fn(string $bytes, string $endung): string => Dokumente::bildSpeichern($bytes));
        if (trim(Html::text($d['html'])) === '' && !str_contains($d['html'], '<img')) { $ergebnis['hinweise'][] = $name . ': kein Inhalt gefunden, übersprungen.'; return; }
        $ordner = $ordnerUebernehmen && $teile ? Dokumente::ordnerPfad($zielordner, $teile) : $zielordner;
        $id = Dokumente::anlegen(['titel' => $d['titel'], 'ordner' => $ordner, 'tags' => implode(',', $tags), 'inhalt' => $d['html']], $wer, $quelle);
        $ergebnis['angelegt'][] = ['id' => $id, 'titel' => $d['titel']];
        if ($d['bilder_uebersprungen'] > 0) $ergebnis['hinweise'][] = $name . ': ' . $d['bilder_uebersprungen'] . ' Grafik(en) in einem Format, das der Browser nicht zeigt (z. B. EMF), weggelassen.';
    }
}
