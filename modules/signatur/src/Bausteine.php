<?php
declare(strict_types=1);
namespace KienzleSignatur;

/**
 * Bausteine einer Vorlage (Editor C, Wunsch des Nutzers am 27.11.2026):
 * Text mit Formatierung, Patientendaten (E-Mail/Telefon, am Pad änderbar), Ankreuzfeld, Einzel- und Mehrfachauswahl,
 * Aufzählung, Bild, Trennlinie, Seitenumbruch. Gespeichert als JSON-Liste in vorlagen.bloecke; jede Eingabe wird hier
 * geprüft und vereinheitlicht. Formatierung als kleine Auszeichnung statt HTML (nichts Fremdes gelangt ins Dokument):
 * **fett**, *kursiv*, __unterstrichen__, Zeilen mit „- “ (Aufzählung), „1. “ (nummeriert), „# “ (Überschrift).
 */
final class Bausteine {
    public const TYPEN = ['text' => 'Text', 'patientendaten' => 'Patientendaten', 'ankreuz' => 'Ankreuzfeld', 'einzel' => 'Einzelauswahl',
        'mehrfach' => 'Mehrfachauswahl', 'liste' => 'Aufzählung', 'bild' => 'Bild', 'trenner' => 'Trennlinie', 'seite' => 'Seitenumbruch'];
    public const FELDER = ['email' => 'E-Mail', 'telefon' => 'Telefon'];
    public const MAX_BLOECKE = 100;
    public const MAX_OPTIONEN = 12;
    public const MAX_BILD = 3 * 1024 * 1024;

    /**
     * Prüft und vereinheitlicht die Bausteine aus dem Editor (JSON oder Liste).
     * @return list<array<string,mixed>>
     */
    public static function pruefen(mixed $eingabe): array {
        if (is_string($eingabe)) {
            if (strlen($eingabe) > 200000) throw new \InvalidArgumentException('Die Vorlage ist zu groß.');
            $eingabe = json_decode($eingabe, true);
        }
        if (!is_array($eingabe) || !array_is_list($eingabe)) throw new \InvalidArgumentException('Die Bausteine sind ungültig.');
        if (!$eingabe) throw new \InvalidArgumentException('Die Vorlage braucht mindestens einen Baustein.');
        if (count($eingabe) > self::MAX_BLOECKE) throw new \InvalidArgumentException('Höchstens ' . self::MAX_BLOECKE . ' Bausteine je Vorlage.');
        $out = []; $ids = [];
        foreach ($eingabe as $n => $b) {
            $wo = 'Baustein ' . ($n + 1);
            if (!is_array($b) || !isset(self::TYPEN[$b['typ'] ?? ''])) throw new \InvalidArgumentException("$wo: unbekannte Art.");
            $id = is_string($b['id'] ?? null) && preg_match('/^[a-z0-9]{1,16}$/D', $b['id']) && !isset($ids[$b['id']]) ? $b['id'] : 'b' . ($n + 1) . bin2hex(random_bytes(3));
            $ids[$id] = true;
            $typ = $b['typ'];
            $neu = ['id' => $id, 'typ' => $typ];
            switch ($typ) {
                case 'text':
                    $neu['text'] = Seite::text($b['text'] ?? '', "$wo (Text)");
                    break;
                case 'patientendaten':
                    $felder = array_values(array_intersect(array_keys(self::FELDER), is_array($b['felder'] ?? null) ? $b['felder'] : []));
                    if (!$felder) throw new \InvalidArgumentException("$wo: bitte E-Mail und/oder Telefon wählen.");
                    $neu += ['felder' => $felder, 'aenderbar' => !empty($b['aenderbar'])];
                    break;
                case 'ankreuz':
                    $neu += ['text' => Seite::zeile($b['text'] ?? '', "$wo (Text)", 500), 'pflicht' => !empty($b['pflicht'])];
                    break;
                case 'einzel':
                case 'mehrfach':
                    $opt = is_array($b['optionen'] ?? null) ? array_values(array_filter(array_map(fn($o) => Seite::zeile($o, "$wo (Möglichkeit)", 120, false), $b['optionen']), 'strlen')) : [];
                    if (count($opt) < 2) throw new \InvalidArgumentException("$wo: mindestens zwei Möglichkeiten angeben.");
                    if (count($opt) > self::MAX_OPTIONEN) throw new \InvalidArgumentException("$wo: höchstens " . self::MAX_OPTIONEN . ' Möglichkeiten.');
                    $neu += ['frage' => Seite::zeile($b['frage'] ?? '', "$wo (Frage)", 300), 'optionen' => $opt, 'pflicht' => !empty($b['pflicht'])];
                    break;
                case 'liste':
                    $e = is_array($b['eintraege'] ?? null) ? array_values(array_filter(array_map(fn($o) => Seite::zeile($o, "$wo (Eintrag)", 300, false), $b['eintraege']), 'strlen')) : [];
                    if (!$e) throw new \InvalidArgumentException("$wo: mindestens einen Eintrag angeben.");
                    if (count($e) > 30) throw new \InvalidArgumentException("$wo: höchstens 30 Einträge.");
                    $neu += ['eintraege' => $e, 'nummeriert' => !empty($b['nummeriert'])];
                    break;
                case 'bild':
                    $datei = (string)($b['datei'] ?? '');
                    if (!preg_match('/^[0-9a-f]{64}\.(png|jpg)$/D', $datei) || !is_file(self::bildOrdner() . '/' . $datei)) throw new \InvalidArgumentException("$wo: bitte ein Bild hochladen.");
                    $breite = (int)($b['breite'] ?? 100);
                    $neu += ['datei' => $datei, 'breite' => max(20, min(100, $breite)), 'text' => Seite::zeile($b['text'] ?? '', "$wo (Bildunterschrift)", 200, false)];
                    break;
            }
            foreach (['text', 'frage'] as $f) if (isset($neu[$f])) Seite::pruefePlatzhalter((string)$neu[$f]);
            foreach (['optionen', 'eintraege'] as $f) foreach ($neu[$f] ?? [] as $t) Seite::pruefePlatzhalter((string)$t);
            $out[] = $neu;
        }
        return $out;
    }

    /** Bisherige Vorlagen (nur Text) als ein Text-Baustein. */
    public static function ausAltemText(string $text): array {
        return [['id' => 'b1', 'typ' => 'text', 'text' => $text]];
    }

    /** Setzt Platzhalter in alle Texte der Bausteine ein (fester Stand des Vorgangs). */
    public static function einsetzen(array $bloecke, array $werte): array {
        foreach ($bloecke as &$b) {
            foreach (['text', 'frage'] as $f) if (isset($b[$f])) $b[$f] = Seite::einsetzen((string)$b[$f], $werte);
            foreach (['optionen', 'eintraege'] as $f) if (isset($b[$f])) $b[$f] = array_map(fn($t) => Seite::einsetzen((string)$t, $werte), $b[$f]);
            if ($b['typ'] === 'patientendaten') $b['werte'] = array_combine($b['felder'], array_map(fn($f) => (string)($werte[$f] ?? ''), $b['felder']));
        }
        return $bloecke;
    }

    /** Text ohne Auszeichnung (für Klartext, Pad und PDF bis zur eigenen Darstellung). */
    public static function ohneAuszeichnung(string $text): string {
        $t = preg_replace('/^# /mu', '', $text) ?? $text;
        $t = str_replace(['**', '__'], '', $t);
        return preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/u', '$1', $t) ?? $t;
    }

    /**
     * Klartext für Pad und PDF (bis der Pad-Dienst die Bausteine selbst zeichnet): Kästchen als „[ ]“, Auswahl als „( )“,
     * nur Zeichen, die Pad-Schrift und PDF (WinAnsi) sicher kennen.
     */
    public static function alsText(array $bloecke): string {
        $teile = [];
        foreach ($bloecke as $b) {
            $teile[] = match ($b['typ']) {
                'text' => self::ohneAuszeichnung((string)$b['text']),
                'patientendaten' => implode("\n", array_map(fn($f) => self::FELDER[$f] . ': ' . (($b['werte'][$f] ?? '') !== '' ? $b['werte'][$f] : '{{' . $f . '}}'), $b['felder'])),
                'ankreuz' => '[ ] ' . $b['text'] . ($b['pflicht'] ? ' *' : ''),
                'einzel' => $b['frage'] . ($b['pflicht'] ? ' *' : '') . "\n" . implode("\n", array_map(fn($o) => '( ) ' . $o, $b['optionen'])),
                'mehrfach' => $b['frage'] . ($b['pflicht'] ? ' *' : '') . "\n" . implode("\n", array_map(fn($o) => '[ ] ' . $o, $b['optionen'])),
                'liste' => implode("\n", array_map(fn($e, $i) => ($b['nummeriert'] ? ($i + 1) . '. ' : '- ') . $e, $b['eintraege'], array_keys($b['eintraege']))),
                'bild' => '[Bild' . ($b['text'] !== '' ? ': ' . $b['text'] : '') . ']',
                'trenner' => '———',
                default => '',
            };
        }
        return trim(implode("\n\n", array_filter($teile, fn($t) => $t !== '')));
    }

    /** Auszeichnung → sicheres HTML (erst maskieren, dann fett/kursiv/unterstrichen, Aufzählungen, Überschriften). */
    public static function html(string $text): string {
        $zeilen = explode("\n", str_replace("\r", '', $text));
        $out = ''; $liste = '';
        $inline = static function (string $s): string {
            $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
            $s = preg_replace('/__(.+?)__/u', '<u>$1</u>', $s) ?? $s;
            return preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/u', '<em>$1</em>', $s) ?? $s;
        };
        foreach ($zeilen as $z) {
            $art = preg_match('/^- (.*)$/u', $z, $m) ? 'ul' : (preg_match('/^\d{1,2}\. (.*)$/u', $z, $m) ? 'ol' : '');
            if ($liste !== '' && $art !== $liste) { $out .= "</$liste>"; $liste = ''; }
            if ($art !== '') {
                if ($liste === '') { $out .= "<$art>"; $liste = $art; }
                $out .= '<li>' . $inline($m[1]) . '</li>';
            } elseif (preg_match('/^# (.*)$/u', $z, $m)) {
                $out .= '<h3>' . $inline($m[1]) . '</h3>';
            } elseif (trim($z) === '') {
                $out .= '<br>';
            } else {
                $out .= '<p>' . $inline($z) . '</p>';
            }
        }
        return $out . ($liste !== '' ? "</$liste>" : '');
    }

    // ---- Bilder (eigener Ordner im Datenordner, Name = Prüfsumme) ----

    public static function bildOrdner(): string { return Speicher::$data . '/bilder'; }

    /** Speichert ein hochgeladenes Bild (nur PNG oder JPEG, höchstens 3 MB und 4000 × 4000 Bildpunkte); liefert den Dateinamen. */
    public static function bildSpeichern(string $bytes): string {
        if ($bytes === '' || strlen($bytes) > self::MAX_BILD) throw new \InvalidArgumentException('Das Bild darf höchstens 3 MB groß sein.');
        $info = @getimagesizefromstring($bytes);
        $typ = is_array($info) ? ($info[2] ?? 0) : 0;
        if (!in_array($typ, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) throw new \InvalidArgumentException('Bitte ein PNG- oder JPEG-Bild wählen.');
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 4000 || $info[1] > 4000) throw new \InvalidArgumentException('Das Bild ist zu groß (höchstens 4000 × 4000 Bildpunkte).');
        $name = hash('sha256', $bytes) . ($typ === IMAGETYPE_PNG ? '.png' : '.jpg');
        $ordner = self::bildOrdner();
        if (!is_dir($ordner) && !@mkdir($ordner, 0700, true)) throw new \RuntimeException('Der Bildordner lässt sich nicht anlegen.');
        $ziel = $ordner . '/' . $name;
        if (!is_file($ziel)) {
            $tmp = $ziel . '.' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $ziel)) { @unlink($tmp); throw new \RuntimeException('Das Bild ließ sich nicht speichern.'); }
            @chmod($ziel, 0600);
        }
        return $name;
    }

    /** Pfad eines gespeicherten Bildes oder null (nur gültige Namen, keine Verknüpfungen). */
    public static function bildPfad(string $name): ?string {
        if (!preg_match('/^[0-9a-f]{64}\.(png|jpg)$/D', $name)) return null;
        $p = self::bildOrdner() . '/' . $name;
        return is_file($p) && !is_link($p) ? $p : null;
    }
}
