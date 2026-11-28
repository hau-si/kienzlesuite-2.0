<?php
declare(strict_types=1);
namespace KienzleSignatur;

/**
 * Sprachfassungen von Vorlagen mit DeepL (Verbindung „uebersetzung“ der Basis; Gratis- oder Pro-Schlüssel).
 * Es gehen nur Vorlagentexte an DeepL, keine Patientendaten. Platzhalter ({{patient}}) und Auszeichnung (**fett**, *kursiv*,
 * __unterstrichen__) werden als XML-Marken geschützt; Zeilenanfänge („# “, „- “, „1. “) bleiben unübersetzt stehen.
 * Das Ergebnis ist eine eigene, zunächst inaktive Vorlage (Prüfen vor dem Freigeben). Einträge für die Akte bleiben deutsch.
 */
class Uebersetzung {
    /** DeepL-Zielsprachen (Englisch britisch). */
    public const ZIEL = ['en' => 'EN-GB', 'tr' => 'TR', 'ru' => 'RU', 'uk' => 'UK', 'pl' => 'PL', 'fr' => 'FR', 'it' => 'IT', 'es' => 'ES', 'ro' => 'RO', 'el' => 'EL'];

    /** @param array<string,mixed> $v Verbindung „uebersetzung“ der Basis (api_key, url) */
    public function __construct(private array $v) {}

    public function bereit(): bool { return (string)($this->v['api_key'] ?? '') !== '' && str_starts_with((string)($this->v['url'] ?? ''), 'https://'); }

    /** Klartext mit Platzhaltern und Auszeichnung → XML für DeepL. */
    public static function schuetzen(string $s): string {
        $s = htmlspecialchars($s, ENT_NOQUOTES | ENT_XML1, 'UTF-8');
        $s = preg_replace('/\{\{\s*([a-z_]+)\s*\}\}/u', '<ph>{{$1}}</ph>', $s) ?? $s;
        $s = preg_replace('/\*\*(.+?)\*\*/us', '<b>$1</b>', $s) ?? $s;
        $s = preg_replace('/__(.+?)__/us', '<u>$1</u>', $s) ?? $s;
        return preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/us', '<i>$1</i>', $s) ?? $s;
    }

    /** Antwort von DeepL → wieder Klartext mit Platzhaltern und Auszeichnung. Unbekannte Marken fallen weg. */
    public static function zurueck(string $s): string {
        $s = preg_replace('#<ph>\s*(\{\{[a-z_]+\}\})\s*</ph>#u', '$1', $s) ?? $s;
        $s = str_replace(['<b>', '</b>', '<u>', '</u>', '<i>', '</i>'], ['**', '**', '__', '__', '*', '*'], $s);
        $s = strip_tags($s);
        return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Zeilenanfang (Überschrift, Aufzählung, Nummer) abtrennen: [Anfang, Rest]. */
    private static function anfang(string $zeile): array {
        return preg_match('/^(\s*(?:#\s+|[-•]\s+|\d{1,3}\.\s+))(.*)$/us', $zeile, $m) ? [$m[1], $m[2]] : ['', $zeile];
    }

    /**
     * Texte übersetzen (Reihenfolge bleibt). Leere Texte werden nicht geschickt.
     * @param list<string> $texte @return list<string>
     */
    public function texte(array $texte, string $sprache): array {
        if (!isset(self::ZIEL[$sprache])) throw new \InvalidArgumentException('Diese Sprache wird nicht angeboten.');
        if (!$this->bereit()) throw new \RuntimeException('Die Übersetzung ist nicht eingerichtet (Verwaltung → Verbindungen → Übersetzung (DeepL)).');
        $aus = $texte;
        $offen = [];
        foreach ($texte as $i => $t) if (trim($t) !== '') $offen[$i] = self::schuetzen($t);
        foreach (array_chunk($offen, 50, true) as $teil) {
            $felder = ['source_lang' => 'DE', 'target_lang' => self::ZIEL[$sprache], 'tag_handling' => 'xml', 'ignore_tags' => 'ph', 'formality' => 'prefer_more', 'preserve_formatting' => '1'];
            $koerper = http_build_query($felder, '', '&', PHP_QUERY_RFC3986);
            foreach ($teil as $t) $koerper .= '&text=' . rawurlencode($t);
            $a = $this->anfrage($koerper);
            $liste = is_array($a['body']['translations'] ?? null) ? $a['body']['translations'] : null;
            if ($a['status'] !== 200 || $liste === null || count($liste) !== count($teil)) {
                throw new \RuntimeException(match (true) {
                    $a['status'] === 403 => 'DeepL lehnt den Schlüssel ab. Bitte unter Verwaltung → Verbindungen prüfen.',
                    $a['status'] === 456 => 'Das Zeichenkontingent bei DeepL ist für diesen Monat aufgebraucht.',
                    $a['status'] === 429 => 'DeepL ist gerade ausgelastet. Bitte in einer Minute noch einmal versuchen.',
                    default => 'DeepL hat nicht wie erwartet geantwortet (HTTP ' . $a['status'] . ').',
                });
            }
            foreach (array_keys($teil) as $n => $i) $aus[$i] = self::zurueck((string)($liste[$n]['text'] ?? ''));
        }
        return $aus;
    }

    /** @return array{status:int,body:mixed} */
    protected function anfrage(string $koerper): array {
        $roh = '';
        $curl = curl_init(rtrim((string)$this->v['url'], '/') . '/translate');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $koerper, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $this->v['api_key'], 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json', 'User-Agent: praxisherz-signatur'],
            CURLOPT_WRITEFUNCTION => static function ($ch, string $c) use (&$roh): int { if (strlen($roh) + strlen($c) > 4 * 1024 * 1024) return 0; $roh .= $c; return strlen($c); }]);
        curl_exec($curl);
        $fehler = curl_errno($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curl = null;
        if ($fehler) throw new \RuntimeException('DeepL ist nicht erreichbar (cURL ' . $fehler . ').');
        return ['status' => $status, 'body' => json_decode($roh, true)];
    }

    /**
     * Sprachfassung einer deutschen Vorlage anlegen oder erneuern (inaktiv, zum Prüfen). @return int ID der Sprachfassung
     */
    public function vorlage(int $id, string $sprache): int {
        $v = Seite::vorlage($id);
        if (!$v) throw new \InvalidArgumentException('Vorlage nicht gefunden.');
        if ((string)$v['sprache'] !== 'de') throw new \InvalidArgumentException('Bitte die deutsche Vorlage übersetzen, nicht eine Sprachfassung.');
        if (!isset(self::ZIEL[$sprache])) throw new \InvalidArgumentException('Diese Sprache wird nicht angeboten.');
        $bloecke = Seite::bloeckeVon($v);
        // Alle Texte sammeln: [Pfad im Baustein, Zeilenanfang] → Text
        $texte = [(string)$v['titel']]; $wo = [['titel', '']];
        foreach ($bloecke as $n => $b) {
            if (($b['typ'] ?? '') === 'text') {
                foreach (explode("\n", (string)$b['text']) as $z => $zeile) { [$a, $rest] = self::anfang($zeile); $texte[] = $rest; $wo[] = [[$n, 'text', $z], $a]; }
            }
            foreach (['frage'] as $f) if (isset($b[$f]) && $b[$f] !== '') { $texte[] = (string)$b[$f]; $wo[] = [[$n, $f], '']; }
            if (($b['typ'] ?? '') === 'ankreuz' || ($b['typ'] ?? '') === 'bild') if (($b['text'] ?? '') !== '') { $texte[] = (string)$b['text']; $wo[] = [[$n, 'text'], '']; }
            foreach (['optionen', 'eintraege'] as $f) foreach ((array)($b[$f] ?? []) as $k => $o) { $texte[] = (string)$o; $wo[] = [[$n, $f, $k], '']; }
        }
        $neu = $this->texte($texte, $sprache);
        $titel = mb_substr(trim($neu[0]), 0, 62) ?: (string)$v['titel'];
        $zeilen = [];
        foreach ($wo as $i => [$pfad, $a]) {
            if ($pfad === 'titel') continue;
            if ($pfad[1] === 'text' && isset($pfad[2])) { $zeilen[$pfad[0]][$pfad[2]] = $a . $neu[$i]; continue; }
            if (isset($pfad[2])) $bloecke[$pfad[0]][$pfad[1]][$pfad[2]] = mb_substr(trim($neu[$i]), 0, 300);
            else $bloecke[$pfad[0]][$pfad[1]] = $neu[$i];
        }
        foreach ($zeilen as $n => $z) { ksort($z); $bloecke[$n]['text'] = implode("\n", $z); }
        $name = Seite::SPRACHEN[$sprache];
        $felder = ['art' => (string)$v['art'], 'titel' => $titel . ' (' . $name . ')', 'aktiv' => false, 'kuerzel' => (string)$v['kuerzel'], 'gruppen' => (string)$v['gruppen'],
            'ablauf' => (bool)(int)$v['ablauf'], 'zweite' => (string)$v['zweite'], 'bloecke' => json_encode($bloecke, JSON_UNESCAPED_UNICODE)];
        $st = Speicher::pdo()->prepare('SELECT id FROM vorlagen WHERE original=? AND sprache=?');
        $st->execute([$id, $sprache]);
        $alt = (int)($st->fetchColumn() ?: 0);
        if ($alt > 0) return Seite::vorlageSpeichern(['id' => $alt] + $felder);
        return Seite::vorlageSpeichern($felder + ['sprache' => $sprache, 'original' => $id]);
    }
}
