<?php
declare(strict_types=1);
namespace KienzleSignatur;

/**
 * Start aus T2med (Schema praxisherz-signatur://, zentraler Starter der Basis → POST /starter/signatur):
 * holt den Patienten aus T2med, legt einen einmaligen Startcode an und liefert den Startlink. Nach der Anmeldung
 * löst die Startseite den Code ein und füllt den Patienten in „Neuer Vorgang“.
 */
final class Start {
    public const GUELTIG = 300;

    /** @param array<string,mixed> $eingabe context_id, fhir_base_url, oauth_token (JSON des Starters) */
    public static function anlegen(array $eingabe, Fhir $fhir, string $host, ?int $jetzt = null): array {
        $jetzt ??= time();
        $kontext = (string)($eingabe['context_id'] ?? '');
        $token = (string)($eingabe['oauth_token'] ?? '');
        $url = (string)($eingabe['fhir_base_url'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $kontext)) throw new \InvalidArgumentException('Ungültiger T2med-Kontext.');
        if ($token === '' || strlen($token) > 16000) throw new \InvalidArgumentException('T2med hat kein Zugriffstoken übergeben.');
        if (!$fhir->erlaubt($url)) throw new \InvalidArgumentException('Die von T2med übergebene FHIR-Adresse ist in Praxisherz nicht freigegeben (Praxis → Verbindungen → T2med).');
        if (!preg_match('/^[A-Za-z0-9.-]{1,100}(:\d{1,5})?$/D', $host)) throw new \InvalidArgumentException('Ungültiger Servername.');
        $patient = $fhir->patient($kontext, $token);
        $code = bin2hex(random_bytes(32));
        $pdo = Speicher::pdo();
        $pdo->prepare('DELETE FROM starts WHERE bis < ?')->execute([$jetzt]);
        $pdo->prepare('INSERT INTO starts (hash, patient, geburtsdatum, kontext, bis, telefon, email, adresse, vorname, nachname, geschlecht, pid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $code), $patient['name'], $patient['geburtsdatum'], $kontext, $jetzt + self::GUELTIG, (string)($patient['telefon'] ?? ''), (string)($patient['email'] ?? ''), (string)($patient['adresse'] ?? ''),
                (string)($patient['vorname'] ?? ''), (string)($patient['nachname'] ?? ''), (string)($patient['geschlecht'] ?? 'unknown'), (string)($patient['id'] ?? '')]);
        // Für „In T2med übernehmen“: das Token bleibt verschlüsselt auf dem Server, so lange der Kontext gilt (4 Stunden wie bei T2med).
        Speicher::tokenMerken($kontext, $token, $jetzt + 4 * 3600);
        // Das Fragment wird weder an nginx übertragen noch protokolliert. &m: Modul für die Weiterleitung der Basis.
        return ['url' => 'https://' . $host . '/#launch=' . $code . '&m=signatur'];
    }

    /** Einmal einlösen (löscht den Code); null, wenn unbekannt, abgelaufen oder schon benutzt. */
    public static function einloesen(string $code, ?int $jetzt = null): ?array {
        if (!preg_match('/^[0-9a-f]{64}$/D', $code)) return null;
        $jetzt ??= time();
        $pdo = Speicher::pdo();
        $h = hash('sha256', $code);
        $st = $pdo->prepare('SELECT patient, geburtsdatum, kontext, telefon, email, adresse, vorname, nachname, geschlecht, pid AS patient_id FROM starts WHERE hash = ? AND bis >= ?');
        $st->execute([$h, $jetzt]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare('DELETE FROM starts WHERE hash = ?')->execute([$h]);
        return $z ?: null;
    }
}
