<?php
declare(strict_types=1);
namespace KienzleQm;

/**
 * Fachlogik des QM-Handbuchs. Ein Dokument hat höchstens eine gültige (vidierte) Version und höchstens einen Entwurf.
 * Ablauf: Entwurf → zur Prüfung → vidiert (gültig) → vorgelegt → gelesen; Wiedervorlage nach dem Prüfintervall.
 * Änderungen nach der Freigabe entstehen als neuer Entwurf; die gültige Version bleibt gültig, bis der Entwurf vidiert ist.
 */
final class Dokumente {
    public const STATUS = ['entwurf' => 'Entwurf', 'pruefung' => 'Zur Prüfung', 'freigegeben' => 'Freigegeben', 'ersetzt' => 'Ersetzt'];
    public const MAX_BILD = 5_000_000;
    private const BILDTYPEN = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

    private static function pdo(): \PDO { return Speicher::pdo(); }

    private static function eins(string $sql, array $p = []): ?array {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return is_array($r) ? $r : null;
    }

    private static function alle(string $sql, array $p = []): array {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    private static function tun(string $sql, array $p = []): void { self::pdo()->prepare($sql)->execute($p); }

    private static function transaktion(callable $f): mixed {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $f();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ---- Eingaben ---------------------------------------------------------------------------------------------------

    /** Einzeiliger Text ohne Steuerzeichen. */
    public static function zeile(mixed $wert, string $feld, int $max, bool $pflicht = true): string {
        $t = trim(is_string($wert) ? $wert : '');
        if (!mb_check_encoding($t, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $t)) throw new \InvalidArgumentException($feld . ': unzulässige Zeichen.');
        if ($pflicht && $t === '') throw new \InvalidArgumentException($feld . ' fehlt.');
        if (mb_strlen($t) > $max) throw new \InvalidArgumentException($feld . ': höchstens ' . $max . ' Zeichen.');
        return $t;
    }

    /** Tags: durch Komma getrennt, ohne Raute, höchstens 12 je Dokument, je 30 Zeichen. @return list<string> */
    public static function tagsLesen(mixed $wert): array {
        $out = [];
        foreach (preg_split('/[,;]+/u', is_string($wert) ? $wert : '') ?: [] as $t) {
            $t = trim(ltrim(trim($t), '#'));
            if ($t === '') continue;
            $t = self::zeile($t, 'Tag', 30);
            $k = mb_strtolower($t);
            if (!isset($out[$k])) $out[$k] = $t;
        }
        if (count($out) > 12) throw new \InvalidArgumentException('Höchstens 12 Tags je Dokument.');
        return array_values($out);
    }

    /** @return list<string> */
    public static function tags(array $dok): array { return array_values(array_filter(explode(',', trim((string)$dok['tags'], ',')))); }

    private static function tagsText(array $tags): string { return $tags ? ',' . implode(',', $tags) . ',' : ''; }

    private static function datum(mixed $wert, string $feld, bool $pflicht = false): string {
        $t = trim(is_string($wert) ? $wert : '');
        if ($t === '' && !$pflicht) return '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $t);
        if (!$d || $d->format('Y-m-d') !== $t) throw new \InvalidArgumentException($feld . ': bitte ein gültiges Datum angeben.');
        return $t;
    }

    /** Kürzel für die Vidierung aus dem Namen: Anfangsbuchstaben ohne Titel („Dr. med. Erika Beispiel“ → „EB“). */
    public static function kuerzel(string $name): string {
        $k = '';
        foreach (preg_split('/[\s,]+/u', $name) ?: [] as $w) {
            if ($w === '' || preg_match('/^(dr|med|prof|dipl|univ|habil|pd|mba|mfa)\.?$/iu', $w) || str_ends_with($w, '.')) continue;
            $k .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        return mb_substr($k !== '' ? $k : mb_strtoupper(mb_substr(trim($name), 0, 2)), 0, 4);
    }

    // ---- Ordner -----------------------------------------------------------------------------------------------------

    /** @return list<array{id:int,eltern:?int,name:string,pos:int}> */
    public static function ordnerListe(): array {
        return array_map(fn($o) => ['id' => (int)$o['id'], 'eltern' => $o['eltern'] === null ? null : (int)$o['eltern'], 'name' => $o['name'], 'pos' => (int)$o['pos']],
            self::alle('SELECT * FROM ordner ORDER BY pos, name COLLATE NOCASE'));
    }

    /** Ordner als flache Liste in Baumreihenfolge mit Tiefe. @return list<array{id:int,name:string,tiefe:int,pfad:string}> */
    public static function ordnerBaum(): array {
        $kinder = [];
        foreach (self::ordnerListe() as $o) $kinder[$o['eltern'] ?? 0][] = $o;
        $out = [];
        $gehe = function (int $eltern, int $tiefe, string $pfad) use (&$gehe, &$out, $kinder): void {
            if ($tiefe > 8) return;
            foreach ($kinder[$eltern] ?? [] as $o) {
                $p = $pfad === '' ? $o['name'] : $pfad . ' › ' . $o['name'];
                $out[] = ['id' => $o['id'], 'name' => $o['name'], 'tiefe' => $tiefe, 'pfad' => $p, 'eltern' => $o['eltern']];
                $gehe($o['id'], $tiefe + 1, $p);
            }
        };
        $gehe(0, 0, '');
        return $out;
    }

    public static function ordner(int $id): ?array { return self::eins('SELECT * FROM ordner WHERE id = ?', [$id]); }

    /** Ordner und alle Unterordner (IDs). @return list<int> */
    public static function ordnerMitUnterordnern(int $id): array {
        $out = [$id];
        for ($i = 0; $i < count($out) && $i < 1000; $i++) foreach (self::alle('SELECT id FROM ordner WHERE eltern = ?', [$out[$i]]) as $r) $out[] = (int)$r['id'];
        return $out;
    }

    public static function ordnerAnlegen(string $name, ?int $eltern): int {
        $name = self::ordnerName($name);
        if ($eltern !== null && !self::ordner($eltern)) throw new \InvalidArgumentException('Übergeordneter Ordner nicht gefunden.');
        if ($eltern !== null && self::ordnerTiefe($eltern) >= 6) throw new \InvalidArgumentException('Höchstens sieben Ebenen.');
        if (self::eins('SELECT id FROM ordner WHERE ' . ($eltern === null ? 'eltern IS NULL' : 'eltern = ?') . ' AND name = ? COLLATE NOCASE', $eltern === null ? [$name] : [$eltern, $name])) {
            throw new \InvalidArgumentException('Einen Ordner mit diesem Namen gibt es hier schon.');
        }
        $pos = (int)(self::eins('SELECT COALESCE(MAX(pos), -1) + 1 AS p FROM ordner WHERE ' . ($eltern === null ? 'eltern IS NULL' : 'eltern = ?'), $eltern === null ? [] : [$eltern])['p'] ?? 0);
        self::tun('INSERT INTO ordner (eltern, name, pos) VALUES (?, ?, ?)', [$eltern, $name, $pos]);
        return (int)self::pdo()->lastInsertId();
    }

    /** Ordnerkette für den Import („Kapitel 1/1.2 Hygiene“) unter $basis anlegen bzw. finden. */
    public static function ordnerPfad(?int $basis, array $teile): ?int {
        $id = $basis;
        foreach (array_slice($teile, 0, 6) as $t) {
            $name = self::ordnerName(mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', (string)$t)), 0, 80));
            $da = self::eins('SELECT id FROM ordner WHERE ' . ($id === null ? 'eltern IS NULL' : 'eltern = ?') . ' AND name = ? COLLATE NOCASE', $id === null ? [$name] : [$id, $name]);
            $id = $da ? (int)$da['id'] : self::ordnerAnlegen($name, $id);
        }
        return $id;
    }

    private static function ordnerTiefe(int $id): int {
        $t = 0;
        while (($o = self::ordner($id)) && $o['eltern'] !== null && $t < 20) { $id = (int)$o['eltern']; $t++; }
        return $t;
    }

    private static function ordnerName(string $name): string {
        $name = self::zeile($name, 'Ordnername', 80);
        if (str_contains($name, '/') || str_contains($name, '\\')) throw new \InvalidArgumentException('Ordnername: ohne Schrägstrich.');
        return $name;
    }

    public static function ordnerUmbenennen(int $id, string $name): void {
        $o = self::ordner($id) ?? throw new \InvalidArgumentException('Ordner nicht gefunden.');
        $name = self::ordnerName($name);
        $e = $o['eltern'];
        if (self::eins('SELECT id FROM ordner WHERE ' . ($e === null ? 'eltern IS NULL' : 'eltern = ?') . ' AND name = ? COLLATE NOCASE AND id <> ?', $e === null ? [$name, $id] : [(int)$e, $name, $id])) {
            throw new \InvalidArgumentException('Einen Ordner mit diesem Namen gibt es hier schon.');
        }
        self::tun('UPDATE ordner SET name = ? WHERE id = ?', [$name, $id]);
    }

    public static function ordnerVerschieben(int $id, ?int $eltern): void {
        if (!self::ordner($id)) throw new \InvalidArgumentException('Ordner nicht gefunden.');
        if ($eltern !== null && in_array($eltern, self::ordnerMitUnterordnern($id), true)) throw new \InvalidArgumentException('Ein Ordner kann nicht in sich selbst liegen.');
        if ($eltern !== null && !self::ordner($eltern)) throw new \InvalidArgumentException('Zielordner nicht gefunden.');
        self::tun('UPDATE ordner SET eltern = ? WHERE id = ?', [$eltern, $id]);
    }

    /** Nur leere Ordner (keine Dokumente, auch keine archivierten, keine Unterordner). */
    public static function ordnerLoeschen(int $id): void {
        if (!self::ordner($id)) throw new \InvalidArgumentException('Ordner nicht gefunden.');
        if (self::eins('SELECT id FROM ordner WHERE eltern = ?', [$id])) throw new \InvalidArgumentException('Der Ordner enthält Unterordner.');
        if (self::eins('SELECT id FROM dokumente WHERE ordner = ?', [$id])) throw new \InvalidArgumentException('Der Ordner enthält Dokumente (auch archivierte). Bitte zuerst verschieben.');
        self::tun('DELETE FROM ordner WHERE id = ?', [$id]);
    }

    // ---- Dokumente --------------------------------------------------------------------------------------------------

    public static function dokument(int $id): ?array { return self::eins('SELECT * FROM dokumente WHERE id = ?', [$id]); }

    public static function version(int $id): ?array { return self::eins('SELECT * FROM versionen WHERE id = ?', [$id]); }

    /** @return list<array> neueste zuerst */
    public static function versionen(int $dok): array { return self::alle('SELECT id, nummer, status, autor_name, erstellt, geaendert, notiz, vidiert_name, vidiert_kuerzel, vidiert_am FROM versionen WHERE dokument = ? ORDER BY nummer DESC', [$dok]); }

    /** @return list<array> neueste zuerst */
    public static function verlauf(int $dok, int $max = 50): array { return self::alle('SELECT * FROM verlauf WHERE dokument = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $max)), [$dok]); }

    private static function merken(int $dok, array $wer, string $was, ?int $zeit = null): void {
        self::tun('INSERT INTO verlauf (dokument, zeit, wer, was) VALUES (?, ?, ?, ?)', [$dok, $zeit ?? time(), $wer['name'] !== '' ? $wer['name'] : $wer['login'], mb_substr($was, 0, 500)]);
    }

    /**
     * Neues Dokument mit Version 1 als Entwurf.
     * @param array{login:string,name:string} $wer
     */
    public static function anlegen(array $daten, array $wer, string $quelle = '', ?int $zeit = null): int {
        $zeit ??= time();
        $titel = self::zeile($daten['titel'] ?? '', 'Titel', 150);
        $ordner = (int)($daten['ordner'] ?? 0) ?: null;
        if ($ordner !== null && !self::ordner($ordner)) throw new \InvalidArgumentException('Ordner nicht gefunden.');
        $tags = self::tagsLesen($daten['tags'] ?? '');
        $monate = self::pruefMonate($daten['pruef_monate'] ?? 12);
        $inhalt = Html::reinigen((string)($daten['inhalt'] ?? ''));
        return self::transaktion(function () use ($titel, $ordner, $tags, $monate, $inhalt, $wer, $quelle, $zeit): int {
            self::tun('INSERT INTO dokumente (ordner, titel, tags, pruef_monate, quelle, erstellt, geaendert) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$ordner, $titel, self::tagsText($tags), $monate, mb_substr($quelle, 0, 300), $zeit, $zeit]);
            $id = (int)self::pdo()->lastInsertId();
            $v = self::neueVersion($id, 1, $inhalt, $wer, $zeit);
            self::tun('UPDATE dokumente SET entwurf = ? WHERE id = ?', [$v, $id]);
            self::merken($id, $wer, $quelle !== '' && str_starts_with($quelle, 'import:') ? 'Aus Word-Datei übernommen (Entwurf)' : 'Angelegt (Entwurf Version 1)', $zeit);
            return $id;
        });
    }

    private static function neueVersion(int $dok, int $nummer, string $inhalt, array $wer, int $zeit): int {
        self::tun('INSERT INTO versionen (dokument, nummer, inhalt, text, status, autor, autor_name, erstellt, geaendert) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$dok, $nummer, $inhalt, Html::text($inhalt), 'entwurf', $wer['login'], $wer['name'], $zeit, $zeit]);
        return (int)self::pdo()->lastInsertId();
    }

    private static function pruefMonate(mixed $w): int {
        $m = (int)$w;
        if (!in_array($m, [0, 6, 12, 24, 36], true)) throw new \InvalidArgumentException('Prüfintervall: 6, 12, 24 oder 36 Monate oder keines.');
        return $m;
    }

    /** Eigenschaften (Titel, Ordner, Tags, Prüfintervall) gelten sofort und sind nicht versioniert. */
    public static function eigenschaftenSpeichern(int $id, array $daten, array $wer): void {
        $d = self::dokument($id) ?? throw new \InvalidArgumentException('Dokument nicht gefunden.');
        $titel = self::zeile($daten['titel'] ?? '', 'Titel', 150);
        $ordner = (int)($daten['ordner'] ?? 0) ?: null;
        if ($ordner !== null && !self::ordner($ordner)) throw new \InvalidArgumentException('Ordner nicht gefunden.');
        $tags = self::tagsLesen($daten['tags'] ?? '');
        $monate = self::pruefMonate($daten['pruef_monate'] ?? $d['pruef_monate']);
        $naechste = (string)$d['naechste_pruefung'];
        if ($monate !== (int)$d['pruef_monate'] && $d['gueltig'] !== null) {
            $v = self::version((int)$d['gueltig']);
            $naechste = $monate > 0 ? date('Y-m-d', strtotime('+' . $monate . ' months', (int)$v['vidiert_am'])) : '';
        }
        self::tun('UPDATE dokumente SET titel = ?, ordner = ?, tags = ?, pruef_monate = ?, naechste_pruefung = ?, geaendert = ? WHERE id = ?',
            [$titel, $ordner, self::tagsText($tags), $monate, $naechste, time(), $id]);
        $alt = [$d['titel'], (int)$d['ordner'], $d['tags'], (int)$d['pruef_monate']];
        if ($alt !== [$titel, (int)$ordner, self::tagsText($tags), $monate]) self::merken($id, $wer, 'Eigenschaften geändert');
    }

    /**
     * Inhalt speichern: Entwurf ändern oder (wenn es nur eine gültige Version gibt) neuen Entwurf anlegen. Ein Entwurf, der zur Prüfung lag,
     * wird dabei wieder Entwurf und muss erneut eingereicht werden.
     */
    public static function inhaltSpeichern(int $id, string $html, string $notiz, array $wer, ?int $zeit = null): int {
        $zeit ??= time();
        $d = self::dokument($id) ?? throw new \InvalidArgumentException('Dokument nicht gefunden.');
        if ((int)$d['archiviert'] === 1) throw new \InvalidArgumentException('Archivierte Dokumente bitte zuerst wiederherstellen.');
        $inhalt = Html::reinigen($html);
        $notiz = self::zeile($notiz, 'Änderungsnotiz', 300, false);
        return self::transaktion(function () use ($d, $id, $inhalt, $notiz, $wer, $zeit): int {
            if ($d['entwurf'] !== null) {
                $v = self::version((int)$d['entwurf']);
                $warPruefung = $v['status'] === 'pruefung';
                self::tun('UPDATE versionen SET inhalt = ?, text = ?, status = ?, geaendert = ?, notiz = ?, eingereicht = 0 WHERE id = ?',
                    [$inhalt, Html::text($inhalt), 'entwurf', $zeit, $notiz !== '' ? $notiz : $v['notiz'], (int)$v['id']]);
                self::merken($id, $wer, 'Entwurf Version ' . $v['nummer'] . ' gespeichert' . ($warPruefung ? ' (zurück in Bearbeitung, bitte erneut einreichen)' : ''), $zeit);
                $vid = (int)$v['id'];
            } else {
                $nr = (int)(self::eins('SELECT MAX(nummer) AS n FROM versionen WHERE dokument = ?', [$id])['n'] ?? 0) + 1;
                $vid = self::neueVersion($id, $nr, $inhalt, $wer, $zeit);
                if ($notiz !== '') self::tun('UPDATE versionen SET notiz = ? WHERE id = ?', [$notiz, $vid]);
                self::tun('UPDATE dokumente SET entwurf = ? WHERE id = ?', [$vid, $id]);
                self::merken($id, $wer, 'Neuer Entwurf Version ' . $nr . ' angelegt', $zeit);
            }
            self::tun('UPDATE dokumente SET geaendert = ? WHERE id = ?', [$zeit, $id]);
            return $vid;
        });
    }

    public static function einreichen(int $id, array $wer): void {
        $d = self::dokument($id) ?? throw new \InvalidArgumentException('Dokument nicht gefunden.');
        $v = $d['entwurf'] !== null ? self::version((int)$d['entwurf']) : null;
        if (!$v || $v['status'] !== 'entwurf') throw new \InvalidArgumentException('Es gibt keinen Entwurf zum Einreichen.');
        if (trim($v['text']) === '' && !str_contains($v['inhalt'], '<img')) throw new \InvalidArgumentException('Der Entwurf ist leer.');
        self::tun('UPDATE versionen SET status = ?, eingereicht = ? WHERE id = ?', ['pruefung', time(), (int)$v['id']]);
        self::merken($id, $wer, 'Version ' . $v['nummer'] . ' zur Prüfung eingereicht');
    }

    /**
     * Vidieren: Entwurf (auch ohne vorheriges Einreichen) wird gültig, die bisherige gültige Version „ersetzt“. Offene Lesepflichten der alten
     * Version gehen auf die neue über. Die nächste Prüfung ist das Vidierdatum plus Prüfintervall.
     */
    public static function vidieren(int $id, array $wer, string $kuerzel, ?int $zeit = null, bool $erneutVorlegen = false): void {
        $zeit ??= time();
        $d = self::dokument($id) ?? throw new \InvalidArgumentException('Dokument nicht gefunden.');
        $v = $d['entwurf'] !== null ? self::version((int)$d['entwurf']) : null;
        if (!$v) throw new \InvalidArgumentException('Es gibt keinen Entwurf zum Vidieren.');
        if (trim($v['text']) === '' && !str_contains($v['inhalt'], '<img')) throw new \InvalidArgumentException('Ein leeres Dokument kann nicht vidiert werden.');
        $kuerzel = self::zeile($kuerzel !== '' ? $kuerzel : self::kuerzel($wer['name']), 'Kürzel', 6);
        self::transaktion(function () use ($d, $v, $id, $wer, $kuerzel, $zeit, $erneutVorlegen): void {
            if ($d['gueltig'] !== null) {
                self::tun('UPDATE versionen SET status = ? WHERE id = ?', ['ersetzt', (int)$d['gueltig']]);
                self::tun('UPDATE OR IGNORE lesepflicht SET version = ? WHERE version = ? AND gelesen = 0', [(int)$v['id'], (int)$d['gueltig']]);
                self::tun('DELETE FROM lesepflicht WHERE version = ? AND gelesen = 0', [(int)$d['gueltig']]);
                if ($erneutVorlegen) {
                    // Alle, denen die bisherige Fassung vorgelegt war, bekommen die neue vorgelegt (auch wer die alte schon gelesen hat).
                    self::tun('INSERT OR IGNORE INTO lesepflicht (version, login, name, frist, von, am) SELECT ?, login, name, \'\', ?, ? FROM lesepflicht WHERE version = ?',
                        [(int)$v['id'], $wer['name'] !== '' ? $wer['name'] : $wer['login'], $zeit, (int)$d['gueltig']]);
                }
            }
            self::tun('UPDATE versionen SET status = ?, vidiert_von = ?, vidiert_name = ?, vidiert_kuerzel = ?, vidiert_am = ? WHERE id = ?',
                ['freigegeben', $wer['login'], $wer['name'], $kuerzel, $zeit, (int)$v['id']]);
            $naechste = (int)$d['pruef_monate'] > 0 ? date('Y-m-d', strtotime('+' . (int)$d['pruef_monate'] . ' months', $zeit)) : '';
            self::tun('UPDATE dokumente SET gueltig = ?, entwurf = NULL, naechste_pruefung = ?, geaendert = ? WHERE id = ?', [(int)$v['id'], $naechste, $zeit, $id]);
            self::merken($id, $wer, 'Version ' . $v['nummer'] . ' vidiert (' . $kuerzel . ')', $zeit);
        });
    }

    public static function zurueckweisen(int $id, string $grund, array $wer): void {
        $d = self::dokument($id) ?? throw new \InvalidArgumentException('Dokument nicht gefunden.');
        $v = $d['entwurf'] !== null ? self::version((int)$d['entwurf']) : null;
        if (!$v || $v['status'] !== 'pruefung') throw new \InvalidArgumentException('Das Dokument liegt nicht zur Prüfung vor.');
        $grund = self::zeile($grund, 'Grund', 300);
        self::tun('UPDATE versionen SET status = ?, notiz = ? WHERE id = ?', ['entwurf', 'Zurückgewiesen: ' . $grund, (int)$v['id']]);
        self::merken($id, $wer, 'Version ' . $v['nummer'] . ' zurückgewiesen: ' . $grund);
    }

    /** Entwurf verwerfen; ein Dokument ohne gültige Version wird dabei ganz gelöscht. Liefert true, wenn das Dokument gelöscht wurde. */
    public static function entwurfVerwerfen(int $id, array $wer): bool {
        $d = self::dokument($id) ?? throw new \InvalidArgumentException('Dokument nicht gefunden.');
        if ($d['entwurf'] === null) throw new \InvalidArgumentException('Es gibt keinen Entwurf.');
        return self::transaktion(function () use ($d, $id, $wer): bool {
            if ($d['gueltig'] === null) {
                self::tun('DELETE FROM dokumente WHERE id = ?', [$id]);
                return true;
            }
            $v = self::version((int)$d['entwurf']);
            self::tun('UPDATE dokumente SET entwurf = NULL WHERE id = ?', [$id]);
            self::tun('DELETE FROM versionen WHERE id = ?', [(int)$d['entwurf']]);
            self::merken($id, $wer, 'Entwurf Version ' . $v['nummer'] . ' verworfen');
            return false;
        });
    }

    /** Wiedervorlage ohne Änderung erledigt: nächste Prüfung ab heute. */
    public static function geprueft(int $id, array $wer, ?int $zeit = null): void {
        $zeit ??= time();
        $d = self::dokument($id) ?? throw new \InvalidArgumentException('Dokument nicht gefunden.');
        if ($d['gueltig'] === null) throw new \InvalidArgumentException('Nur gültige Dokumente können als geprüft bestätigt werden.');
        $m = max(1, (int)$d['pruef_monate']);
        self::tun('UPDATE dokumente SET naechste_pruefung = ?, geaendert = ? WHERE id = ?', [(int)$d['pruef_monate'] > 0 ? date('Y-m-d', strtotime('+' . $m . ' months', $zeit)) : '', $zeit, $id]);
        self::merken($id, $wer, 'Geprüft, inhaltlich unverändert gültig', $zeit);
    }

    public static function archivieren(int $id, bool $archiv, array $wer): void {
        if (!self::dokument($id)) throw new \InvalidArgumentException('Dokument nicht gefunden.');
        self::tun('UPDATE dokumente SET archiviert = ?, geaendert = ? WHERE id = ?', [$archiv ? 1 : 0, time(), $id]);
        self::merken($id, $wer, $archiv ? 'Archiviert (nicht mehr gültig)' : 'Aus dem Archiv geholt');
    }

    // ---- Vorlegen und Lesen -----------------------------------------------------------------------------------------

    /**
     * Gültige Fassung zur Kenntnis vorlegen. $personen: Liste der Personen mit Zugang (von der Basis); $logins die gewählten.
     * Bereits Vorgelegte behalten ihren Stand (gelesen bleibt gelesen, die Frist wird aktualisiert). Liefert die Zahl neuer Vorlagen.
     */
    public static function vorlegen(array $ids, array $logins, array $personen, string $frist, array $wer, ?int $zeit = null): int {
        $zeit ??= time();
        $frist = self::datum($frist, 'Frist');
        $namen = array_column($personen, 'name', 'login');
        $logins = array_values(array_unique(array_filter(array_map('strval', $logins), fn($l) => isset($namen[$l]))));
        if (!$logins) throw new \InvalidArgumentException('Bitte mindestens eine Person oder Gruppe wählen.');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids || count($ids) > 500) throw new \InvalidArgumentException('Keine Dokumente gewählt.');
        return self::transaktion(function () use ($ids, $logins, $namen, $frist, $wer, $zeit): int {
            $neu = 0;
            foreach ($ids as $id) {
                $d = self::dokument($id);
                if (!$d || $d['gueltig'] === null || (int)$d['archiviert'] === 1) throw new \InvalidArgumentException('Nur gültige (vidierte) Dokumente können vorgelegt werden.');
                $v = (int)$d['gueltig'];
                foreach ($logins as $l) {
                    $st = self::pdo()->prepare('INSERT OR IGNORE INTO lesepflicht (version, login, name, frist, von, am) VALUES (?, ?, ?, ?, ?, ?)');
                    $st->execute([$v, $l, $namen[$l], $frist, $wer['name'] !== '' ? $wer['name'] : $wer['login'], $zeit]);
                    if ($st->rowCount() > 0) $neu++;
                    else self::tun('UPDATE lesepflicht SET frist = ? WHERE version = ? AND login = ? AND gelesen = 0', [$frist, $v, $l]);
                }
                self::merken($id, $wer, 'Vorgelegt an ' . count($logins) . ' Person' . (count($logins) === 1 ? '' : 'en') . ($frist !== '' ? ' bis ' . date('d.m.Y', (int)strtotime($frist)) : ''), $zeit);
            }
            return $neu;
        });
    }

    /** Lesebestätigung für die gültige Fassung. */
    public static function gelesen(int $id, int $version, array $wer, ?int $zeit = null): void {
        $d = self::dokument($id) ?? throw new \InvalidArgumentException('Dokument nicht gefunden.');
        if ((int)$d['gueltig'] !== $version) throw new \InvalidArgumentException('Inzwischen gilt eine neuere Fassung. Bitte die Seite neu laden.');
        $st = self::pdo()->prepare('UPDATE lesepflicht SET gelesen = ? WHERE version = ? AND login = ? AND gelesen = 0');
        $st->execute([$zeit ?? time(), $version, $wer['login']]);
        if ($st->rowCount() === 0) throw new \InvalidArgumentException('Dieses Dokument wurde Ihnen nicht vorgelegt oder ist schon bestätigt.');
        $v = self::version($version);
        self::merken($id, $wer, 'Version ' . $v['nummer'] . ' gelesen und verstanden', $zeit);
    }

    /** Lesestand einer Version. @return list<array> */
    public static function lesestand(int $version): array { return self::alle('SELECT * FROM lesepflicht WHERE version = ? ORDER BY gelesen > 0, name COLLATE NOCASE', [$version]); }

    /** Offene Lesepflicht dieser Person für die gültige Fassung oder null. */
    public static function offeneLesepflicht(int $id, string $login): ?array {
        return self::eins('SELECT l.* FROM lesepflicht l JOIN dokumente d ON d.gueltig = l.version WHERE d.id = ? AND l.login = ? AND l.gelesen = 0', [$id, $login]);
    }

    /** Für mich zu lesen (gültig, nicht archiviert), Frist zuerst. @return list<array> */
    public static function lesemappe(string $login): array {
        return self::alle('SELECT d.id, d.titel, d.tags, v.nummer, l.frist, l.von, l.am FROM lesepflicht l JOIN dokumente d ON d.gueltig = l.version JOIN versionen v ON v.id = l.version
            WHERE l.login = ? AND l.gelesen = 0 AND d.archiviert = 0 ORDER BY l.frist = \'\', l.frist, l.am', [$login]);
    }

    // ---- Listen -----------------------------------------------------------------------------------------------------

    /** @return list<array> Dokumente mit Entwurf zur Prüfung */
    public static function zuVidieren(): array {
        return self::alle('SELECT d.id, d.titel, v.nummer, v.autor_name, v.eingereicht FROM dokumente d JOIN versionen v ON v.id = d.entwurf
            WHERE v.status = \'pruefung\' AND d.archiviert = 0 ORDER BY v.eingereicht');
    }

    /** @return list<array> Wiedervorlage fällig (bis $tag einschließlich) */
    public static function faellig(?string $tag = null): array {
        return self::alle('SELECT id, titel, naechste_pruefung FROM dokumente WHERE gueltig IS NOT NULL AND archiviert = 0 AND naechste_pruefung <> \'\' AND naechste_pruefung <= ? ORDER BY naechste_pruefung',
            [$tag ?? date('Y-m-d')]);
    }

    /** @return list<array> Entwürfe (nicht zur Prüfung) */
    public static function entwuerfe(): array {
        return self::alle('SELECT d.id, d.titel, v.nummer, v.autor_name, v.geaendert, v.notiz FROM dokumente d JOIN versionen v ON v.id = d.entwurf
            WHERE v.status = \'entwurf\' AND d.archiviert = 0 ORDER BY v.geaendert DESC');
    }

    /** @return list<array> zuletzt vidiert */
    public static function zuletztFreigegeben(int $max = 8): array {
        return self::alle('SELECT d.id, d.titel, v.nummer, v.vidiert_am, v.vidiert_kuerzel FROM dokumente d JOIN versionen v ON v.id = d.gueltig
            WHERE d.archiviert = 0 ORDER BY v.vidiert_am DESC LIMIT ' . max(1, min(50, $max)));
    }

    /** Alle Tags mit Anzahl. @return array<string,int> */
    public static function alleTags(): array {
        $out = [];
        foreach (self::alle('SELECT tags FROM dokumente WHERE archiviert = 0') as $r) foreach (self::tags($r) as $t) $out[$t] = ($out[$t] ?? 0) + 1;
        uksort($out, 'strnatcasecmp');
        return $out;
    }

    /** Anzeigestatus eines Dokuments. */
    public static function zustand(array $d, ?string $heute = null): string {
        if ((int)$d['archiviert'] === 1) return 'archiv';
        if ($d['gueltig'] === null) return ($d['entwurf_status'] ?? '') === 'pruefung' ? 'pruefung' : 'entwurf';
        if ((string)$d['naechste_pruefung'] !== '' && $d['naechste_pruefung'] <= ($heute ?? date('Y-m-d'))) return 'faellig';
        return 'freigegeben';
    }

    /**
     * Liste mit Filtern. $f: ordner (inkl. Unterordner), tag, zustand (entwurf, pruefung, freigegeben, faellig, archiv), q (Schnipsel im Titel und in den Tags),
     * text (auch im Inhalt suchen). $mitEntwuerfen: Entwürfe ohne gültige Version zeigen (nur wer schreiben oder vidieren darf).
     * @return list<array>
     */
    public static function liste(array $f, bool $mitEntwuerfen, ?string $heute = null): array {
        $rows = self::alle('SELECT d.*, g.nummer AS g_nummer, g.vidiert_am, g.vidiert_kuerzel, g.text AS g_text, e.nummer AS e_nummer, e.status AS entwurf_status, e.text AS e_text,
            (SELECT COUNT(*) FROM lesepflicht l WHERE l.version = d.gueltig) AS vorgelegt, (SELECT COUNT(*) FROM lesepflicht l WHERE l.version = d.gueltig AND l.gelesen > 0) AS gelesen
            FROM dokumente d LEFT JOIN versionen g ON g.id = d.gueltig LEFT JOIN versionen e ON e.id = d.entwurf ORDER BY d.titel COLLATE NOCASE');
        $ordner = isset($f['ordner']) && (int)$f['ordner'] > 0 ? self::ordnerMitUnterordnern((int)$f['ordner']) : null;
        $tag = mb_strtolower(trim((string)($f['tag'] ?? '')));
        $zustand = (string)($f['zustand'] ?? '');
        $q = mb_strtolower(trim((string)($f['q'] ?? '')));
        $out = [];
        foreach ($rows as $r) {
            $r['zustand'] = self::zustand($r, $heute);
            if ($zustand === 'archiv' ? $r['zustand'] !== 'archiv' : $r['zustand'] === 'archiv') continue;
            if (!$mitEntwuerfen && $r['gueltig'] === null) continue;
            if ($ordner !== null && !in_array((int)$r['ordner'], $ordner, true)) continue;
            if ($tag !== '' && !in_array($tag, array_map('mb_strtolower', self::tags($r)), true)) continue;
            if ($zustand !== '' && $zustand !== 'archiv') {
                $passt = $r['zustand'] === $zustand || ($zustand === 'freigegeben' && $r['zustand'] === 'faellig')
                    || ($mitEntwuerfen && $zustand === 'entwurf' && $r['entwurf_status'] === 'entwurf') || ($mitEntwuerfen && $zustand === 'pruefung' && $r['entwurf_status'] === 'pruefung');
                if (!$passt) continue;
            }
            $r['treffer'] = '';
            if ($q !== '') {
                $kopf = mb_strtolower($r['titel'] . ' ' . str_replace(',', ' ', $r['tags']));
                if (str_contains($kopf, $q)) $r['rang'] = str_starts_with(mb_strtolower($r['titel']), $q) ? 0 : 1;
                elseif (!empty($f['text']) && ($stelle = self::fundstelle(($r['gueltig'] !== null || !$mitEntwuerfen) ? (string)$r['g_text'] : (string)$r['e_text'], $q)) !== null) {
                    $r['rang'] = 2;
                    $r['treffer'] = $stelle;
                } else {
                    continue;
                }
            }
            unset($r['g_text'], $r['e_text']);
            $out[] = $r;
        }
        if ($q !== '') usort($out, fn($a, $b) => [$a['rang'], mb_strtolower($a['titel'])] <=> [$b['rang'], mb_strtolower($b['titel'])]);
        return $out;
    }

    /** Textausschnitt um den ersten Treffer. */
    private static function fundstelle(string $text, string $q): ?string {
        $p = mb_stripos($text, $q);
        if ($p === false) return null;
        $start = max(0, $p - 40);
        return ($start > 0 ? '…' : '') . trim(preg_replace('/\s+/u', ' ', mb_substr($text, $start, 120)) ?? '') . '…';
    }

    // ---- Bilder -----------------------------------------------------------------------------------------------------

    /** Bild prüfen und unter seiner Prüfsumme ablegen; liefert die Adresse im Modul. */
    public static function bildSpeichern(string $bytes): string {
        if ($bytes === '' || strlen($bytes) > self::MAX_BILD) throw new \InvalidArgumentException('Bilder höchstens 5 MB.');
        $info = @getimagesizefromstring($bytes);
        $endung = is_array($info) ? (self::BILDTYPEN[$info[2]] ?? null) : null;
        if ($endung === null || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 60_000_000) throw new \InvalidArgumentException('Nur Bilder im Format PNG, JPEG, GIF oder WebP.');
        $name = hash('sha256', $bytes) . '.' . $endung;
        $datei = Speicher::ordner('bilder') . '/' . $name;
        if (!is_file($datei)) {
            $tmp = $datei . '.' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !chmod($tmp, 0600) || !rename($tmp, $datei)) { @unlink($tmp); throw new \RuntimeException('Bild konnte nicht gespeichert werden.'); }
        }
        return qm_basis() . '/bild/' . $name;
    }

    /** Pfad eines gespeicherten Bildes oder null. */
    public static function bildDatei(string $name): ?string {
        if (!preg_match('/^[0-9a-f]{64}\.(png|jpg|gif|webp)$/D', $name)) return null;
        $f = Speicher::$data . '/bilder/' . $name;
        return is_file($f) ? $f : null;
    }

    // ---- Überblick --------------------------------------------------------------------------------------------------

    /** Einrichtungs-Überblick der Basis (alles optional). */
    public static function ueberblick(): array {
        $gueltig = (int)self::pdo()->query('SELECT COUNT(*) FROM dokumente WHERE gueltig IS NOT NULL AND archiviert = 0')->fetchColumn();
        $import = (int)self::pdo()->query('SELECT COUNT(*) FROM dokumente WHERE quelle LIKE \'import:%\'')->fetchColumn();
        $faellig = count(self::faellig());
        return [
            ['title' => 'Erste QM-Dokumente vidiert', 'optional' => true, 'state' => $gueltig > 0 ? 'done' : 'open',
             'hint' => $gueltig > 0 ? $gueltig . ' gültige Dokumente im Handbuch.' : 'Dokumente anlegen (Gerüste liegen bei) oder aus Word übernehmen und vidieren.', 'link' => '/qm/handbuch'],
            ['title' => 'Musterdokumente übernehmen (z. B. QEP der KBV)', 'optional' => true, 'state' => $import > 0 ? 'done' : 'open',
             'hint' => $import > 0 ? $import . ' Dokumente aus Word-Dateien übernommen.' : 'Word-Dateien (.docx) oder ein ZIP mit Ordnern hochladen; sie werden als Entwürfe angelegt.', 'link' => '/qm/import'],
            ['title' => 'Wiedervorlage', 'optional' => true, 'state' => $faellig > 0 ? 'open' : 'done',
             'hint' => $faellig > 0 ? $faellig . ' Dokumente sind zur Prüfung fällig.' : 'Keine Prüfung fällig.', 'link' => '/qm/handbuch?zustand=faellig'],
        ];
    }
}
