<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Arbeitsplätze mit Kienzle-Agent (ein Programm am PC: Starter für T2med und Dienst mit Systemrechten).
 *
 * Der Agent meldet sich bei der Einrichtung mit dem Starter-Schlüssel an und bekommt eine eigene Kennung mit Zugang (nur der
 * SHA-256 liegt hier). Danach fragt er etwa alle zwei Sekunden nach Aufträgen (/agent/abholen) und bekommt dabei den Sollzustand
 * (Programmversion, Startkontexte, Drucker, Zertifizierungsstelle). Aufträge gibt es nur in festen Arten (zurzeit „gdt“: Akte in
 * T2med öffnen); der Agent führt nichts anderes aus.
 *
 * Zuordnung Browser → PC: gewählter Arbeitsplatz (Cookie) oder sonst der einzige Agent, der gerade von derselben Adresse fragt.
 */
final class Arbeitsplaetze {
    public const COOKIE = 'ks_platz';
    public const ONLINE = 120;          // Sekunden: so lange gilt ein Agent nach der letzten Abfrage als verbunden
    public const GDT_FRIST = 60;        // ein Aufruf „Akte öffnen“ verfällt, wenn der Agent ihn nicht in dieser Zeit abholt
    private const NAME = '/^[\p{L}\p{N} ._()-]{1,40}$/u';

    public function __construct(private Database $db) {}

    private static function hash(string $token): string { return hash('sha256', $token); }

    /** Neuer Agent (Starter-Schlüssel schon geprüft). Gleicher Rechnername von derselben Adresse ersetzt den alten Eintrag (Neuinstallation). */
    public function anmelden(string $rechner, string $system, string $ip): array {
        $rechner = mb_substr(trim((string)preg_replace('/[^\p{L}\p{N}._-]/u', '', $rechner)), 0, 40) ?: 'PC';
        $system = in_array($system, ['windows', 'macos', 'linux'], true) ? $system : 'windows';
        $token = bin2hex(random_bytes(32));
        return $this->db->transaction(function () use ($rechner, $system, $ip, $token): array {
            $alt = $this->db->one('SELECT id, name FROM arbeitsplaetze WHERE rechner=? AND ip=?', [$rechner, $ip]);
            if ($alt) {
                $this->db->query('UPDATE arbeitsplaetze SET token_hash=?, system=?, zuletzt=? WHERE id=?', [self::hash($token), $system, time(), $alt['id']]);
                return ['id' => $alt['id'], 'token' => $token, 'name' => $alt['name']];
            }
            if ((int)$this->db->one('SELECT COUNT(*) n FROM arbeitsplaetze')['n'] >= 200) throw new Problem('Zu viele Arbeitsplätze (höchstens 200). Bitte alte unter Arbeitsplätze entfernen.', 409);
            $id = bin2hex(random_bytes(8));
            $this->db->query('INSERT INTO arbeitsplaetze(id,name,rechner,system,token_hash,ip,zuletzt,erstellt) VALUES(?,?,?,?,?,?,?,?)',
                [$id, $rechner, $rechner, $system, self::hash($token), $ip, time(), time()]);
            return ['id' => $id, 'token' => $token, 'name' => $rechner];
        });
    }

    /** Agent zu „Authorization: Bearer <Zugang>“; null, wenn unbekannt. */
    public function pruefen(string $header): ?array {
        if (!preg_match('/^Bearer ([0-9a-f]{64})$/D', $header, $m)) return null;
        return $this->db->one('SELECT * FROM arbeitsplaetze WHERE token_hash=?', [self::hash($m[1])]);
    }

    /**
     * Abfrage eines Agents: Lebenszeichen merken (höchstens alle 20 Sekunden schreiben, sofort bei neuer Adresse, Version oder
     * Rückmeldung), offene Aufträge liefern und als abgeholt markieren. Verfallene Aufrufe werden verworfen.
     */
    public function abholen(array $platz, string $ip, string $version, string $zustand): array {
        $version = mb_substr((string)preg_replace('/[^0-9A-Za-z.+-]/', '', $version), 0, 30);
        $zustand = mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f]/u', ' ', $zustand)), 0, 300);
        $jetzt = time();
        if ($jetzt - (int)$platz['zuletzt'] >= 20 || $platz['ip'] !== $ip || $platz['version'] !== $version || ($zustand !== '' && $platz['zustand'] !== $zustand)) {
            $this->db->query('UPDATE arbeitsplaetze SET zuletzt=?, ip=?, version=?, zustand=CASE WHEN ?=\'\' THEN zustand ELSE ? END WHERE id=?',
                [$jetzt, $ip, $version, $zustand, $zustand, $platz['id']]);
        }
        return $this->db->transaction(function () use ($platz, $jetzt): array {
            $this->db->query("UPDATE arbeitsplatz_auftraege SET abgeholt=?, erledigt=?, ergebnis='verfallen' WHERE platz=? AND abgeholt=0 AND typ='gdt' AND erstellt<?",
                [$jetzt, $jetzt, $platz['id'], $jetzt - self::GDT_FRIST]);
            $offen = $this->db->query('SELECT id, typ, daten FROM arbeitsplatz_auftraege WHERE platz=? AND abgeholt=0 ORDER BY erstellt LIMIT 10', [$platz['id']])->fetchAll();
            foreach ($offen as $a) $this->db->query('UPDATE arbeitsplatz_auftraege SET abgeholt=? WHERE id=?', [$jetzt, $a['id']]);
            $this->db->query('DELETE FROM arbeitsplatz_auftraege WHERE erstellt<?', [$jetzt - 86400]);
            return array_map(fn($a) => ['id' => $a['id'], 'typ' => $a['typ'], 'daten' => json_decode($a['daten'], true)], $offen);
        });
    }

    public function ergebnis(array $platz, string $auftrag, bool $ok, string $text): void {
        $text = mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f]/u', ' ', $text)), 0, 200);
        $this->db->query('UPDATE arbeitsplatz_auftraege SET erledigt=?, ergebnis=? WHERE id=? AND platz=?', [time(), ($ok ? 'ok' : 'fehler') . ($text !== '' ? ': ' . $text : ''), $auftrag, $platz['id']]);
    }

    /** Auftrag für einen PC anlegen. Liefert die Kennung. */
    public function auftrag(string $platz, string $typ, array $daten): string {
        if (!in_array($typ, ['gdt'], true)) throw new Problem('Unbekannte Auftragsart.', 400);
        $id = bin2hex(random_bytes(8));
        $this->db->query('INSERT INTO arbeitsplatz_auftraege(id,platz,typ,daten,erstellt) VALUES(?,?,?,?,?)', [$id, $platz, $typ, json_encode($daten, JSON_UNESCAPED_UNICODE), time()]);
        return $id;
    }

    /** Stand eines Auftrags: offen, abgeholt, ok…, fehler…, verfallen. */
    public function stand(string $id, string $platz): string {
        $a = $this->db->one('SELECT abgeholt, erledigt, ergebnis FROM arbeitsplatz_auftraege WHERE id=? AND platz=?', [$id, $platz]);
        if (!$a) return 'unbekannt';
        if ((int)$a['erledigt'] > 0) return (string)$a['ergebnis'];
        return (int)$a['abgeholt'] > 0 ? 'abgeholt' : 'offen';
    }

    /** @return list<array> alle Arbeitsplätze, Name sortiert, mit „online“. */
    public function liste(): array {
        $out = $this->db->query('SELECT id, name, rechner, system, version, ip, zuletzt, zustand, erstellt FROM arbeitsplaetze ORDER BY name COLLATE NOCASE')->fetchAll();
        foreach ($out as &$p) $p['online'] = time() - (int)$p['zuletzt'] <= self::ONLINE;
        return $out;
    }

    public function holen(string $id): ?array {
        if (!preg_match('/^[0-9a-f]{16}$/D', $id)) return null;
        return $this->db->one('SELECT id, name, rechner, system, version, ip, zuletzt, zustand FROM arbeitsplaetze WHERE id=?', [$id]);
    }

    public function umbenennen(string $id, string $name): void {
        $name = trim($name);
        if (!preg_match(self::NAME, $name)) throw new Problem('Name: 1 bis 40 Zeichen (Buchstaben, Ziffern, Leerzeichen, . _ ( ) -).', 400);
        $this->db->query('UPDATE arbeitsplaetze SET name=? WHERE id=?', [$name, $id]);
    }

    public function entfernen(string $id): void { $this->db->query('DELETE FROM arbeitsplaetze WHERE id=?', [$id]); }

    /**
     * PC des Browsers: der gewählte Arbeitsplatz (Cookie), sonst der einzige verbundene Agent mit derselben Adresse. null, wenn das
     * nicht eindeutig ist (dann wählt der Benutzer einmal „Mein Arbeitsplatz“).
     */
    public function fuerBrowser(string $ip, string $cookie): ?array {
        if ($cookie !== '' && ($p = $this->holen($cookie))) return $p;
        if ($ip === '') return null;
        $treffer = $this->db->query('SELECT id FROM arbeitsplaetze WHERE ip=? AND zuletzt>=?', [$ip, time() - self::ONLINE])->fetchAll();
        return count($treffer) === 1 ? $this->holen($treffer[0]['id']) : null;
    }

    /**
     * GDT-Satz 6301 („Stammdaten übermitteln“, wie kienzlefon-t2med src/Gdt.php): öffnet in T2med den Patienten mit dieser Nummer.
     * Zeile = drei Ziffern Länge (Wert + 9) + vier Ziffern Feldkennung + Wert + CRLF; nur ASCII, Zeichensatz 2.
     */
    public static function gdt(string $patientennummer, string $empfaenger = 'T2MED_PX', string $absender = 'AUFRUF'): string {
        if (!preg_match('/^[1-9][0-9]{0,11}$/D', $patientennummer)) throw new Problem('Patientennummer ungültig.', 400);
        foreach ([$empfaenger, $absender] as $id) if (!preg_match('/^[A-Z0-9_]{1,8}$/D', $id)) throw new Problem('GDT-Kennung ungültig.', 400);
        $zeile = static fn(string $feld, string $wert): string => sprintf('%03d', strlen($wert) + 9) . $feld . $wert . "\r\n";
        $rest = $zeile('8315', $empfaenger) . $zeile('8316', $absender) . $zeile('9206', '2') . $zeile('9218', '02.10') . $zeile('3000', $patientennummer);
        $kopf = $zeile('8000', '6301');
        $gesamt = strlen($kopf) + strlen($zeile('8100', '00000')) + strlen($rest);      // Länge des ganzen Satzes einschließlich Zeile 8100
        return $kopf . $zeile('8100', sprintf('%05d', $gesamt)) . $rest;
    }
}
