<?php
declare(strict_types=1);
namespace KienzleSignatur;

/**
 * Unterschriebenes PDF in die T2med-Akte (läuft im Hintergrund, angestoßen vom Pad-Dienst, als ks-signatur):
 *  - Vorgang aus der Kasse (quelle „kasse“): PDF über den gemeinsamen Austauschordner an die Kasse; die Kasse hält den T2med-Zugang
 *    dieses Patienten und schreibt es in die Akte (Entscheidung des Nutzers). Ihre Antwort steht in von-kasse/<vorgang>.json.
 *  - Vorgang mit Start aus T2med (Kontext und Zugriffstoken, höchstens 4 Stunden): Signatur schreibt selbst.
 *  - sonst: kein Weg in die Akte („manuell“), das PDF bleibt in der Signatur abrufbar.
 * Spalte vorgaenge.ablage: '' = offen, an_kasse, erledigt, fehler, manuell.
 */
class Ablage {
    /** Austauschordner mit der Kasse (Basis: /var/spool/kienzle/austausch/<a>_<b>, IDs sortiert). */
    public static string $austausch = '/var/spool/kienzle/austausch/kasse_signatur';

    public const STATI = ['an_kasse' => 'An die Kasse übergeben – wird in T2med abgelegt …', 'erledigt' => 'In T2med abgelegt',
        'fehler' => 'Ablage in T2med fehlgeschlagen', 'manuell' => 'Nicht automatisch in T2med abgelegt (kein T2med-Aufruf)'];

    /**
     * Ein Durchlauf: Antworten der Kasse übernehmen, dann offene unterschriebene Vorgänge ablegen.
     * @param callable():Fhir $fhir liefert den T2med-Zugang (erst bei Bedarf)
     * @return list<string> Protokollzeilen (ohne Patientendaten)
     */
    public static function lauf(callable $fhir, ?int $jetzt = null): array {
        $jetzt ??= time();
        $log = [];
        foreach (self::antworten() as [$vid, $ok, $meldung]) {
            $v = Seite::vorgang($vid);
            if (!$v || $v['ablage'] !== 'an_kasse') continue;
            if ($ok) self::setzen($vid, 'uebertragen', 'erledigt', '');
            else self::setzen($vid, $v['status'], 'fehler', 'Kasse: ' . $meldung);
            $log[] = "Vorgang $vid: Antwort der Kasse " . ($ok ? 'abgelegt' : 'Fehler');
        }
        $st = Speicher::pdo()->query("SELECT * FROM vorgaenge WHERE status='unterschrieben' AND ablage='' ORDER BY id LIMIT 20");
        foreach ($st->fetchAll() as $v) {
            $vid = (int)$v['id'];
            $pdf = Seite::pdfPfad($vid);
            if ($pdf === null) continue; // PDF entsteht noch (Pad-Dienst)
            try {
                if ($v['quelle'] === 'kasse') {
                    if (!self::anKasse($v, (string)file_get_contents($pdf), $jetzt)) { self::setzen($vid, 'unterschrieben', 'manuell', 'Die Kasse ist nicht erreichbar (Austauschordner fehlt). Das PDF liegt in der Signatur.'); continue; }
                    self::setzen($vid, 'unterschrieben', 'an_kasse', '');
                    $log[] = "Vorgang $vid: an die Kasse übergeben";
                } elseif ($v['kontext'] !== '') {
                    $token = Speicher::tokenHolen((string)$v['kontext'], $jetzt);
                    if ($token === null) { self::setzen($vid, 'unterschrieben', 'fehler', 'T2med-Zugang abgelaufen (höchstens 4 Stunden nach dem Aufruf). Bitte die Akte frisch aus T2med öffnen und „Erneut ablegen“ wählen.'); continue; }
                    $fhir()->dokumentAblegen((string)$v['kontext'], $token, (string)file_get_contents($pdf), (string)$v['titel'], (int)$v['unterschrieben'] ?: $jetzt);
                    self::setzen($vid, 'uebertragen', 'erledigt', '');
                    $log[] = "Vorgang $vid: in T2med abgelegt";
                } else {
                    self::setzen($vid, 'unterschrieben', 'manuell', '');
                }
            } catch (\Throwable $e) {
                self::setzen($vid, 'unterschrieben', 'fehler', mb_substr($e->getMessage(), 0, 300));
                $log[] = "Vorgang $vid: Ablage fehlgeschlagen";
            }
        }
        return $log;
    }

    /** Nach einem Fehler erneut versuchen (Knopf in der Liste). */
    public static function erneut(int $id): void {
        $v = Seite::vorgang($id);
        if (!$v || $v['status'] !== 'unterschrieben' || !in_array($v['ablage'], ['fehler', 'manuell'], true)) throw new \InvalidArgumentException('Dieser Vorgang kann nicht erneut abgelegt werden.');
        self::setzen($id, 'unterschrieben', '', '');
    }

    /** Gibt es etwas zu tun? (Pad-Dienst ruft den Lauf nur dann auf.) */
    public static function offen(): bool {
        return (int)Speicher::pdo()->query("SELECT COUNT(*) FROM vorgaenge WHERE status='unterschrieben' AND ablage IN ('','an_kasse')")->fetchColumn() > 0;
    }

    private static function setzen(int $id, string $status, string $ablage, string $meldung): void {
        Speicher::pdo()->prepare('UPDATE vorgaenge SET status=?, ablage=?, meldung=? WHERE id=?')->execute([$status, $ablage, $meldung, $id]);
    }

    /** PDF und Auftrag in an-kasse/ (die JSON-Datei zuletzt: Sie ist das Zeichen für die Kasse). */
    private static function anKasse(array $v, string $pdf, int $jetzt): bool {
        $ordner = self::$austausch . '/an-kasse';
        if (!is_dir(self::$austausch)) return false;
        if (!is_dir($ordner) && !@mkdir($ordner, 0770)) return false;
        @chmod($ordner, 0770);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', (string)$v['quelle_ref'])) throw new \RuntimeException('Der Vorgang hat keinen gültigen Verweis auf die Kasse.');
        $vid = (int)$v['id'];
        self::schreiben("$ordner/$vid.pdf", $pdf);
        self::schreiben("$ordner/$vid.json", json_encode(['vorgang' => $vid, 'ref' => (string)$v['quelle_ref'], 'titel' => (string)$v['titel'],
            'zeit' => (int)$v['unterschrieben'] ?: $jetzt, 'erstellt' => $jetzt], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return true;
    }

    private static function schreiben(string $ziel, string $inhalt): void {
        $tmp = dirname($ziel) . '/.' . basename($ziel) . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $inhalt) !== strlen($inhalt)) { @unlink($tmp); throw new \RuntimeException('Austauschordner nicht beschreibbar.'); }
        chmod($tmp, 0660); // Die Kasse muss lesen und löschen dürfen (ACL des Austauschordners).
        if (!rename($tmp, $ziel)) { @unlink($tmp); throw new \RuntimeException('Austauschordner nicht beschreibbar.'); }
    }

    /** @return list<array{int,bool,string}> Antworten der Kasse (werden gelesen und gelöscht). */
    private static function antworten(): array {
        $out = [];
        foreach (glob(self::$austausch . '/von-kasse/*.json') ?: [] as $datei) {
            if (is_link($datei) || !preg_match('#/([1-9][0-9]{0,9})\.json$#D', $datei, $m) || filesize($datei) > 4096) { @unlink($datei); continue; }
            $d = json_decode((string)file_get_contents($datei), true);
            @unlink($datei);
            if (!is_array($d) || (int)($d['vorgang'] ?? 0) !== (int)$m[1]) continue;
            $meldung = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string)($d['meldung'] ?? '')) ?? ''), 0, 250);
            $out[] = [(int)$m[1], ($d['ok'] ?? false) === true, $meldung !== '' ? $meldung : 'unbekannter Fehler'];
        }
        return $out;
    }
}
