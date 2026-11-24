<?php
declare(strict_types=1);
namespace KienzleUnterschrift;

/**
 * Fachlogik des Moduls unterschrift: Vorlagen mit Platzhaltern, Vorgänge mit Status. Die Seite selbst (Ansicht, index.php)
 * ruft nur diese Funktionen. Alle Eingaben werden hier geprüft; Fehler als InvalidArgumentException mit lesbarem Text.
 */
final class Seite {
    public const ARTEN = ['igel' => 'IGeL-Vertrag', 'aufklaerung' => 'Patientenaufklärung', 'datenschutz' => 'Datenschutzerklärung', 'sonstige' => 'Sonstiges Dokument'];
    public const STATI = ['wartet' => 'Wartet auf Unterschrift', 'unterschrieben' => 'Unterschrieben', 'uebertragen' => 'In T2med abgelegt', 'fehler' => 'Fehler', 'abgebrochen' => 'Abgebrochen'];
    /** Platzhalter in Vertragstexten: {{name}} wird beim Anlegen des Vorgangs eingesetzt. */
    public const PLATZHALTER = ['patient' => 'Name des Patienten', 'datum' => 'Heutiges Datum', 'praxis' => 'Name der Praxis', 'leistung' => 'Leistung oder Thema', 'betrag' => 'Betrag in Euro'];
    public const MAX_TEXT = 20000;
    /** Wie lange abgeschlossene Vorgänge (übertragen, abgebrochen) aufbewahrt werden, in Tagen. */
    public const AUFBEWAHRUNG_TAGE = 30;

    /** Mustertexte beim ersten Start. Nur Beispiele: vor Gebrauch von der Praxis (ggf. juristisch) prüfen lassen. */
    public static function muster(): array {
        return [
            ['igel', 'IGeL-Vertrag (Muster)', "Individuelle Gesundheitsleistung\n\nPatient/in: {{patient}}\nDatum: {{datum}}\nLeistung: {{leistung}}\nVoraussichtliche Kosten: {{betrag}} Euro\n\nIch wünsche die genannte Leistung, die nicht zum Leistungsumfang der gesetzlichen Krankenversicherung gehört. Ich wurde darüber informiert, dass ich die Kosten selbst trage und dass ich die Leistung auch ablehnen kann.\n\n{{praxis}}"],
            ['aufklaerung', 'Patientenaufklärung (Muster)', "Aufklärung und Einwilligung\n\nPatient/in: {{patient}}\nDatum: {{datum}}\nThema: {{leistung}}\n\nIch wurde über Ablauf, Nutzen und mögliche Risiken aufgeklärt und konnte Fragen stellen. Ich willige in die besprochene Maßnahme ein.\n\n{{praxis}}"],
            ['datenschutz', 'Datenschutzerklärung (Muster)', "Information zum Datenschutz\n\nPatient/in: {{patient}}\nDatum: {{datum}}\n\nIch habe die Informationen zur Verarbeitung meiner Gesundheitsdaten in der Praxis erhalten und zur Kenntnis genommen.\n\n{{praxis}}"],
        ];
    }

    private static function pdo(): \PDO { return Speicher::pdo(); }

    /** Einzeiliger Text ohne Steuerzeichen, mit Längengrenze. */
    public static function zeile(mixed $wert, string $name, int $max, bool $pflicht = true): string {
        $s = trim((string)$wert);
        if (preg_match('/[\x00-\x1f\x7f]/u', $s)) throw new \InvalidArgumentException("$name enthält ungültige Zeichen.");
        if (mb_strlen($s) > $max) throw new \InvalidArgumentException("$name ist zu lang (höchstens $max Zeichen).");
        if ($pflicht && $s === '') throw new \InvalidArgumentException("$name fehlt.");
        return $s;
    }

    /** Mehrzeiliger Vertragstext: Zeilenumbrüche vereinheitlicht, nur Tabulator und Zeilenumbruch als Steuerzeichen. */
    public static function text(mixed $wert, string $name): string {
        $s = trim(str_replace(["\r\n", "\r"], "\n", (string)$wert));
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $s)) throw new \InvalidArgumentException("$name enthält ungültige Zeichen.");
        if ($s === '') throw new \InvalidArgumentException("$name fehlt.");
        if (mb_strlen($s) > self::MAX_TEXT) throw new \InvalidArgumentException("$name ist zu lang (höchstens " . self::MAX_TEXT . ' Zeichen).');
        return $s;
    }

    /** Platzhalter eines Textes; unbekannte werden abgelehnt, damit Tippfehler nicht unbemerkt auf dem Pad landen. */
    public static function pruefePlatzhalter(string $text): void {
        preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/u', $text, $m);
        foreach ($m[1] as $name) {
            if (!isset(self::PLATZHALTER[$name])) throw new \InvalidArgumentException('Unbekannter Platzhalter {{' . $name . '}}. Erlaubt: ' . implode(', ', array_map(fn($k) => '{{' . $k . '}}', array_keys(self::PLATZHALTER))) . '.');
        }
        if (preg_match('/\{\{|\}\}/', preg_replace('/\{\{\s*[^{}]*?\s*\}\}/u', '', $text) ?? '')) throw new \InvalidArgumentException('Geschweifte Klammern {{ }} sind nur für Platzhalter erlaubt und müssen paarweise stehen.');
    }

    /** Setzt die Platzhalter ein. Nicht angegebene Werte bleiben als Lücke "…" stehen und fallen beim Anlegen auf. */
    public static function einsetzen(string $text, array $werte): string {
        return preg_replace_callback('/\{\{\s*([^{}]*?)\s*\}\}/u', static function (array $m) use ($werte): string {
            $w = (string)($werte[$m[1]] ?? '');
            return $w === '' ? '…' : $w;
        }, $text) ?? $text;
    }

    // ---- Vorlagen ----

    public static function vorlagen(bool $nurAktive = false): array {
        $sql = 'SELECT * FROM vorlagen' . ($nurAktive ? ' WHERE aktiv=1' : '') . ' ORDER BY art, titel COLLATE NOCASE';
        return self::pdo()->query($sql)->fetchAll();
    }

    public static function vorlage(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM vorlagen WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Legt an (id 0) oder ändert. Eine geänderte Mustervorlage gilt danach als von der Praxis geprüft. */
    public static function vorlageSpeichern(array $in): int {
        $id = (int)($in['id'] ?? 0);
        $art = (string)($in['art'] ?? '');
        if (!isset(self::ARTEN[$art])) throw new \InvalidArgumentException('Unbekannte Dokumentart.');
        $titel = self::zeile($in['titel'] ?? '', 'Titel', 80);
        $text = self::text($in['text'] ?? '', 'Text');
        self::pruefePlatzhalter($text);
        $aktiv = !empty($in['aktiv']) ? 1 : 0;
        $pdo = self::pdo();
        if ($id > 0) {
            if (!self::vorlage($id)) throw new \InvalidArgumentException('Vorlage nicht gefunden.');
            $pdo->prepare('UPDATE vorlagen SET art=?, titel=?, text=?, aktiv=?, muster=0, geaendert=? WHERE id=?')->execute([$art, $titel, $text, $aktiv, time(), $id]);
            return $id;
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM vorlagen')->fetchColumn() >= 100) throw new \InvalidArgumentException('Es sind höchstens 100 Vorlagen möglich.');
        $pdo->prepare('INSERT INTO vorlagen (art, titel, text, aktiv, muster, geaendert) VALUES (?, ?, ?, ?, 0, ?)')->execute([$art, $titel, $text, $aktiv, time()]);
        return (int)$pdo->lastInsertId();
    }

    public static function vorlageLoeschen(int $id): void {
        if (!self::vorlage($id)) throw new \InvalidArgumentException('Vorlage nicht gefunden.');
        // Bestehende Vorgänge behalten ihren fest eingesetzten Text; nur der Verweis wird gelöst.
        self::pdo()->prepare('UPDATE vorgaenge SET vorlage_id=NULL WHERE vorlage_id=?')->execute([$id]);
        self::pdo()->prepare('DELETE FROM vorlagen WHERE id=?')->execute([$id]);
    }

    // ---- Vorgänge ----

    /**
     * Legt einen Vorgang an. Der Text wird jetzt eingesetzt und fest gespeichert: Spätere Änderungen an der Vorlage
     * ändern nichts an dem, was der Patient unterschreibt. $kontext ist die T2med-Kontext-ID (leer, wenn nicht aus T2med).
     */
    public static function vorgangAnlegen(array $in, string $benutzer, array $praxis = [], string $kontext = '', ?int $jetzt = null): int {
        $jetzt ??= time();
        $vorlage = self::vorlage((int)($in['vorlage_id'] ?? 0));
        if (!$vorlage || !(int)$vorlage['aktiv']) throw new \InvalidArgumentException('Bitte eine aktive Vorlage wählen.');
        $patient = self::zeile($in['patient'] ?? '', 'Patientenname', 80);
        $leistung = self::zeile($in['leistung'] ?? '', 'Leistung', 120, false);
        $betrag = self::zeile($in['betrag'] ?? '', 'Betrag', 20, false);
        if ($betrag !== '' && !preg_match('/^\d{1,6}([.,]\d{1,2})?$/D', $betrag)) throw new \InvalidArgumentException('Der Betrag muss eine Zahl sein, z. B. 45,00.');
        if ($betrag !== '') $betrag = number_format((float)str_replace(',', '.', $betrag), 2, ',', '.');
        $kontext = self::zeile($kontext, 'Kontext', 100, false);
        $text = self::einsetzen((string)$vorlage['text'], [
            'patient' => $patient, 'datum' => date('d.m.Y', $jetzt), 'praxis' => (string)($praxis['name'] ?? ''), 'leistung' => $leistung, 'betrag' => $betrag,
        ]);
        self::aufraeumen($jetzt);
        self::pdo()->prepare('INSERT INTO vorgaenge (vorlage_id, art, titel, text, patient, kontext, kontext_bis, status, druck_vorher, druck_nachher, erstellt, erstellt_von)
            VALUES (?, ?, ?, ?, ?, ?, ?, "wartet", ?, ?, ?, ?)')
            ->execute([(int)$vorlage['id'], $vorlage['art'], $vorlage['titel'], $text, $patient, $kontext, $kontext === '' ? 0 : $jetzt + 4 * 3600,
                !empty($in['druck_vorher']) ? 1 : 0, !empty($in['druck_nachher']) ? 1 : 0, $jetzt, self::zeile($benutzer, 'Benutzer', 80, false)]);
        return (int)self::pdo()->lastInsertId();
    }

    public static function vorgang(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM vorgaenge WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Vorgänge, neueste zuerst. $offen: nur die, die noch bearbeitet werden müssen (wartet, unterschrieben, Fehler). */
    public static function vorgaenge(bool $offen = false, int $max = 200): array {
        $sql = 'SELECT * FROM vorgaenge' . ($offen ? ' WHERE status IN ("wartet","unterschrieben","fehler")' : '') . ' ORDER BY erstellt DESC, id DESC LIMIT ' . max(1, min(1000, $max));
        return self::pdo()->query($sql)->fetchAll();
    }

    public static function abbrechen(int $id): void {
        $v = self::vorgang($id);
        if (!$v) throw new \InvalidArgumentException('Vorgang nicht gefunden.');
        if (in_array($v['status'], ['uebertragen', 'abgebrochen'], true)) throw new \InvalidArgumentException('Dieser Vorgang ist schon abgeschlossen.');
        self::pdo()->prepare('UPDATE vorgaenge SET status="abgebrochen" WHERE id=?')->execute([$id]);
    }

    /** Entfernt abgeschlossene Vorgänge nach der Aufbewahrungszeit; Patientendaten sollen nicht länger als nötig liegen. */
    public static function aufraeumen(?int $jetzt = null): int {
        $grenze = ($jetzt ?? time()) - self::AUFBEWAHRUNG_TAGE * 86400;
        $st = self::pdo()->prepare('DELETE FROM vorgaenge WHERE status IN ("uebertragen","abgebrochen") AND erstellt < ?');
        $st->execute([$grenze]);
        return $st->rowCount();
    }

    /** Ob der T2med-Kontext eines Vorgangs noch gültig ist (T2med hält ihn höchstens 4 Stunden). */
    public static function kontextGueltig(array $vorgang, ?int $jetzt = null): bool {
        return $vorgang['kontext'] !== '' && (int)$vorgang['kontext_bis'] > ($jetzt ?? time());
    }

    // ---- Einrichtungs-Überblick ----

    public static function ueberblick(): array {
        $musterOffen = (int)self::pdo()->query('SELECT COUNT(*) FROM vorlagen WHERE muster=1 AND aktiv=1')->fetchColumn();
        return [
            ['title' => 'Vertragstexte der Praxis anpassen', 'state' => $musterOffen > 0 ? 'open' : 'done',
             'hint' => $musterOffen > 0 ? 'Die mitgelieferten Mustertexte sind nur Beispiele. Bitte prüfen und für Ihre Praxis ändern oder abschalten.' : 'Alle aktiven Vorlagen wurden von der Praxis bearbeitet.',
             'link' => '/unterschrift/#vorlagen'],
        ];
    }
}
