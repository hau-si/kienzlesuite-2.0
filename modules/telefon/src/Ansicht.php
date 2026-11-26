<?php
declare(strict_types=1);
namespace KienzleTelefon;

/** HTML der Seiten des Moduls Telefon (Rahmen, Karten, Ausklappzeilen und Knöpfe kommen aus core.css). */
final class Ansicht {
    public static function kopf(string $base): string {
        $v = substr((string)@md5_file(dirname(__DIR__) . '/public/assets/style.css'), 0, 8);
        return '<link rel="stylesheet" href="' . tf_h($base) . '/assets/style.css?v=' . $v . '">';
    }

    /** Link „Sprachmenü“ im Seitenkopf zeigen (Recht vorhanden und Sprachmenü eingeschaltet). */
    public static bool $sprachmenue = false;

    private static function tag(string $art, string $text): string { return '<span class="tag ' . $art . '">' . tf_h($text) . '</span>'; }

    private static function kopfzeile(string $route, bool $einstellungen): string {
        $b = tf_h(tf_basis());
        $titel = ['/einstellungen' => 'Einstellungen', '/sprachmenue' => 'Sprachmenü'][$route] ?? 'Telefonanlage';
        $link = $route !== '/' ? '<a class="button secondary" href="' . $b . '/">← Übersicht</a>'
            : (self::$sprachmenue ? '<a class="button secondary" href="' . $b . '/sprachmenue">Sprachmenü</a>' : '')
              . ($einstellungen ? '<a class="button secondary" href="' . $b . '/einstellungen">Einstellungen</a>' : '');
        return '<header class="seitenkopf"><div><p class="eyebrow">Telefon</p><h1>' . $titel . '</h1></div><span class="start-tools">' . $link . '</span></header>';
    }

    private static function meldungen(?string $meldung, ?string $fehler): string {
        return ($meldung ? '<p class="ok" role="status">' . tf_h($meldung) . '</p>' : '') . ($fehler ? '<p class="fehler" role="alert">' . tf_h($fehler) . '</p>' : '');
    }

    private static function zweckeVon(array $cfg, string $id): array {
        $z = [];
        if ($cfg['fax']['leitung'] === $id) $z[] = 'Fax';
        if ($cfg['telefon']['leitung_ein'] === $id) $z[] = 'Telefon eingehend';
        if (($cfg['telefon']['leitung_aus'] ?: $cfg['telefon']['leitung_ein']) === $id && $cfg['telefon']['leitung_ein'] !== '') $z[] = 'Telefon ausgehend';
        if ($cfg['ueberlauf']['leitung'] === $id) $z[] = 'Überlauf';
        return $z;
    }

    private static function regTag(?string $s): string {
        return match ($s) {
            'Registered' => self::tag('ok', 'angemeldet'),
            null, '' => self::tag('warn', 'unbekannt'),
            'Rejected' => self::tag('err', 'abgelehnt (Zugangsdaten?)'),
            default => self::tag('err', 'nicht angemeldet'),
        };
    }

    public static function uebersicht(array $cfg, array $status, bool $einstellungen, bool $faxBereit, ?string $meldung, ?string $fehler): string {
        $h = 'tf_h';
        $o = '<div class="tf">' . self::kopfzeile('/', $einstellungen) . self::meldungen($meldung, $fehler);
        $laeuft = (bool)($status['laeuft'] ?? false);
        $o .= '<section class="card"><h2>Anlage</h2><p>' . ($laeuft ? self::tag('ok', 'läuft') : self::tag('err', 'läuft nicht'))
            . ($laeuft ? ' <span class="muted">Gespräche gerade: ' . (int)($status['gespraeche'] ?? 0) . ' · in der Warteschlange: ' . (int)($status['wartend'] ?? 0) . '</span>' : '')
            . '</p>' . ($laeuft ? '' : '<p class="muted">Prüfen auf dem Server: <code>sudo journalctl -u kienzle-telefon-asterisk -n 30 --no-pager</code></p>') . '</section>';
        $o .= '<section class="card"><h2>Leitungen</h2>';
        if (!$cfg['leitungen']) {
            $o .= '<p class="muted">Noch keine Leitung eingetragen.' . ($einstellungen ? ' <a href="' . $h(tf_basis()) . '/einstellungen#leitungen">Jetzt eintragen</a>' : '') . '</p>';
        } else {
            $o .= '<table class="tf-tabelle"><tr><th>Leitung</th><th>Anbieter</th><th>Verwendet für</th><th>Zustand</th></tr>';
            foreach ($cfg['leitungen'] as $l) {
                $z = self::zweckeVon($cfg, $l['id']);
                $o .= '<tr><td>' . $h($l['name'] ?: $l['id']) . '</td><td>' . $h(Anlage::ANBIETER[$l['anbieter']] ?? $l['anbieter']) . '</td><td>' . $h($z ? implode(', ', $z) : '–')
                    . '</td><td>' . ($laeuft ? self::regTag($status['leitungen'][$l['id']] ?? null) : '–') . '</td></tr>';
            }
            $o .= '</table>';
        }
        $o .= '</section><section class="card"><h2>Telefone</h2>';
        if (!$cfg['nebenstellen']) {
            $o .= '<p class="muted">Noch keine Telefone angelegt.</p>';
        } else {
            $o .= '<table class="tf-tabelle"><tr><th>Nebenstelle</th><th>Name</th><th>Warteschlange</th><th>Angemeldet</th></tr>';
            foreach ($cfg['nebenstellen'] as $n) {
                $s = $status['telefone'][$n['nummer']] ?? '';
                $o .= '<tr><td>' . $h($n['nummer']) . '</td><td>' . $h($n['name']) . '</td><td>' . ($n['warteschlange'] ? 'ja' : 'nein') . '</td><td>'
                    . (!$laeuft ? '–' : ($s === 'Avail' ? self::tag('ok', 'ja') : ($s === '' ? self::tag('warn', 'nein') : self::tag('warn', $s)))) . '</td></tr>';
            }
            $o .= '</table>';
        }
        $o .= '</section>';
        if ($cfg['fax']['leitung'] !== '') {
            $o .= '<section class="card"><h2>Fax</h2><p>Faxnummer ' . $h($cfg['fax']['nummer']) . ' · '
                . ($faxBereit ? self::tag('ok', 'Modul Fax verbunden') : self::tag('warn', 'Modul Fax fehlt (Verwaltung → Module)')) . '</p></section>';
        }
        return $o . '</div>';
    }

    private static function feld(string $name, string $label, string $wert, string $hilfe = '', string $typ = 'text', string $extra = ''): string {
        return '<label>' . tf_h($label) . '<input type="' . $typ . '" name="' . $name . '" value="' . tf_h($wert) . '"' . $extra . '>'
            . ($hilfe !== '' ? '<small class="muted">' . tf_h($hilfe) . '</small>' : '') . '</label>';
    }

    private static function auswahl(string $name, string $label, array $optionen, string $wert): string {
        $o = '<label>' . tf_h($label) . '<select name="' . $name . '">';
        foreach ($optionen as $k => $v) $o .= '<option value="' . tf_h((string)$k) . '"' . ((string)$k === $wert ? ' selected' : '') . '>' . tf_h($v) . '</option>';
        return $o . '</select></label>';
    }

    private static function haken(string $name, string $label, bool $an): string {
        return '<label class="check"><input type="checkbox" name="' . $name . '" value="1"' . ($an ? ' checked' : '') . '> ' . tf_h($label) . '</label>';
    }

    private static function formular(string $csrf, string $aktion, string $inhalt, string $knopf, string $klasse = 'tf-form'): string {
        return '<form method="post" class="' . $klasse . '"><input type="hidden" name="kienzle-csrf" value="' . tf_h($csrf) . '"><input type="hidden" name="aktion" value="' . $aktion . '">'
            . $inhalt . '<button class="primary" type="submit">' . tf_h($knopf) . '</button></form>';
    }

    private static function fold(string $id, string $titel, string $zustand, string $inhalt, bool $offen = false): string {
        return '<details class="fold" id="' . $id . '"' . ($offen ? ' open' : '') . '><summary><h2>' . tf_h($titel) . '</h2> ' . $zustand . '</summary><div class="tf-body">' . $inhalt . '</div></details>';
    }

    /** Ansagen: Piper (optional nachgeladen) einrichten/entfernen, Ansagen erzeugen, Stand des letzten Auftrags. */
    private static function ansagen(string $csrf, string $tts): string {
        $st = Anlage::auftragStatus();
        $namen = ['piper-einrichten' => 'Piper einrichten', 'piper-entfernen' => 'Piper entfernen', 'kokoro-einrichten' => 'Kokoro einrichten', 'kokoro-entfernen' => 'Kokoro entfernen',
            'erzeugen' => 'Ansagen erzeugen', '' => 'Auftrag'];
        $stand = match ($st['stand'] ?? '') {
            'laeuft', 'wartet' => self::tag('warn', 'läuft'), 'fertig' => self::tag('ok', 'fertig'), 'fehler' => self::tag('err', 'Fehler'), default => '',
        };
        $knopf = static fn(string $aktion, string $text, string $klasse = '', string $frage = '', array $extra = []) => '<form method="post"' . ($frage !== '' ? ' data-confirm="' . tf_h($frage) . '"' : '')
            . '><input type="hidden" name="kienzle-csrf" value="' . tf_h($csrf) . '"><input type="hidden" name="aktion" value="' . $aktion . '">'
            . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . $k . '" value="' . tf_h($v) . '">', array_keys($extra), $extra))
            . '<button class="' . $klasse . '" type="submit">' . tf_h($text) . '</button></form>';
        $o = '<h3>Ansagen</h3><p>' . (Anlage::piperBereit() ? self::tag('ok', 'Piper eingerichtet') : self::tag('opt', 'Piper nicht geladen'))
            . ' ' . (Anlage::kokoroBereit() ? self::tag('ok', 'Kokoro eingerichtet') : '') . (Anlage::googleBereit() ? ' ' . self::tag('ok', 'Google-Schlüssel gespeichert') : '')
            . ' <span class="muted">Erzeugte Ansagen: ' . Anlage::ansagenAnzahl() . '</span></p>'
            . '<p class="muted">Piper wird nur bei Bedarf geladen (Python-Pakete mit festen Versionen und die Stimme „Thorsten“, zusammen etwa 200 MB), damit das Modul klein bleibt. '
            . 'Eigene Aufnahmen über die Nebenstelle 777 haben Vorrang vor erzeugten Ansagen.</p><div class="tf-knoepfe tf-links">';
        if (!Anlage::piperBereit()) $o .= $knopf('piper-einrichten', 'Piper einrichten', 'primary', 'Piper und die Stimme jetzt aus dem Internet laden (etwa 200 MB)?');
        else $o .= $knopf('piper-entfernen', 'Piper entfernen', 'danger', 'Piper und die Stimme löschen? Erzeugte Ansagen bleiben.');
        if ($tts === 'kokoro' || Anlage::kokoroBereit()) $o .= Anlage::kokoroBereit() ? $knopf('kokoro-entfernen', 'Kokoro entfernen', 'danger', 'Kokoro löschen? Erzeugte Ansagen bleiben.')
            : $knopf('kokoro-einrichten', 'Kokoro einrichten', 'primary', 'Kokoro-Thorsten jetzt laden? Das sind mehrere GB (PyTorch, Modell) und dauert lange.');
        if (Anlage::ttsBereit()) $o .= $knopf('ansagen-erzeugen', 'Geänderte Ansagen erzeugen', 'primary') . $knopf('ansagen-erzeugen', 'Alle neu erzeugen', 'secondary', 'Alle Ansagen neu erzeugen? Das dauert einige Minuten.', ['alle' => '1']);
        $o .= '</div>';
        if ($stand !== '') $o .= '<p>' . tf_h($namen[$st['aktion'] ?? ''] ?? 'Auftrag') . ': ' . $stand . ' <span class="muted">' . tf_h((string)($st['meldung'] ?? '')) . '</span></p>';
        return $o;
    }

    private const TAGE = ['montag' => 'Mo', 'dienstag' => 'Di', 'mittwoch' => 'Mi', 'donnerstag' => 'Do', 'freitag' => 'Fr', 'samstag' => 'Sa', 'sonntag' => 'So'];
    private const ZEIT_HILFE = [
        'oeffnungszeiten' => 'Wann die Praxis geöffnet ist. Danach richten sich Begrüßung und Ansage der Öffnungszeiten.',
        'telefonzeiten' => 'Wann Anrufer zur Praxis durchgestellt werden. Außerhalb nimmt das Sprachmenü nur Anliegen auf.',
        'apothekenzeiten' => 'Wann Apotheken und Pflegedienste die eigene Taste nutzen können.',
        'fachstellenzeiten' => 'Wann Krankenhäuser, Rettungsdienst, Notärzte und Kollegen das rote Telefon erreichen.',
    ];
    /** Abschnitte der Seite: Titel und die Gruppen bzw. Namen der Ansagen von Kienzlefon. */
    private const TEXTE = [
        'begruessung' => ['Begrüßung und Notfall', ['greeting_open', 'greeting_closed', 'emergency', 'urgent_help']],
        'menue' => ['Menü und Tasten', ['menu_open', 'menu_closed', 'opening_hours_choice', 'zeiten_und_menue']],
        'anliegen' => ['Aufnahme von Anliegen', ['datenerfassung', 'abschluss_und_fehler']],
        'warteschlange' => ['Warteschlange', ['warteschlange']],
        'verwaltung' => ['Ansagen-Telefon (777)', ['interne_verwaltung']],
        'weitere' => ['Weitere Texte', ['weitere_ansagen']],
    ];

    private static function verborgen(string $csrf, string $stand): string {
        return '<input type="hidden" name="kienzle-csrf" value="' . tf_h($csrf) . '"><input type="hidden" name="aktion" value="sprachmenue-texte"><input type="hidden" name="stand" value="' . tf_h($stand) . '">';
    }

    private static function ansageFeld(array $a, bool $anhoeren): string {
        $h = 'tf_h';
        $n = (string)$a['name'];
        $o = '<div class="tf-ansage"><label for="a-' . $h($n) . '">' . $h((string)$a['label']) . '</label>';
        if ($a['erzeugt']) {
            $o .= '<textarea id="a-' . $h($n) . '" rows="2" disabled>' . $h((string)$a['text']) . '</textarea><small class="muted">Wird aus den Menütasten erzeugt, weil die Tasten geändert sind.</small>';
        } else {
            $zeilen = max(1, min(6, (int)ceil(mb_strlen((string)$a['text']) / 90)));
            $o .= '<textarea id="a-' . $h($n) . '" name="ansage[' . $h($n) . ']" rows="' . $zeilen . '" maxlength="5000">' . $h((string)$a['text']) . '</textarea>';
            if ((string)$a['text'] !== (string)$a['standard']) $o .= '<small class="muted">Vorgabe: ' . $h((string)$a['standard']) . '</small>';
        }
        $o .= '<div class="tf-ansage-zeile">';
        if ($a['modus'] !== null) {
            $o .= '<label class="tf-inline">Spielen: <select name="modus[' . $h((string)$a['modus_name']) . ']">';
            foreach (Anlage::MODI as $k => $v) $o .= '<option value="' . $k . '"' . ($k === $a['modus'] ? ' selected' : '') . '>' . $h($v) . '</option>';
            $o .= '</select></label>';
        }
        $hoer = preg_replace('/^(opening_hours|phone_hours)_.*$/', '$1', $n);
        if ($anhoeren && Anlage::ansageDatei((string)$hoer) !== null) $o .= '<audio controls preload="none" src="' . $h(tf_basis()) . '/ansage/' . $h((string)$hoer) . '.wav"></audio>';
        return $o . '</div></div>';
    }

    /** Seite „Sprachmenü“: Zeiten, Ansagetexte und Menütasten als Ausklappzeilen. */
    /** Wartemusik: Klingelzeichen, Musik von Johannes Plachetka oder eigene Datei (Upload per fetch, assets/app.js). */
    private static function wartemusik(string $csrf, string $modus): string {
        $h = 'tf_h';
        $w = Anlage::wartemusik();
        $wahl = $modus === 'musik' && $w['bereit'] ? $w['quelle'] : 'klingelzeichen';
        $marke = match ($wahl) { 'plachetka' => self::tag('ok', 'Musik von Johannes Plachetka'), 'eigene' => self::tag('ok', 'eigene Musik'), default => self::tag('opt', 'Klingelzeichen') };
        $punkt = static fn(string $wert, string $text, bool $an = true) => $an ? '<label class="check"><input type="radio" name="wahl" value="' . $wert . '"' . ($wahl === $wert ? ' checked' : '') . '> ' . $text . '</label>' : '';
        $o = '<p class="muted">Was Anrufer in der Warteschlange hören. Am Telefon anhören: Nebenstelle 8805 (sobald Musik eingerichtet ist).</p>'
            . '<form method="post" class="tf-form">' . '<input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"><input type="hidden" name="aktion" value="wartemusik">'
            . $punkt('klingelzeichen', 'Klingelzeichen')
            . $punkt('plachetka', 'Wartemelodie von Johannes Plachetka (41 Sekunden, frei nutzbar unter MIT-Lizenz)')
            . $punkt('eigene', 'Eigene Musik (hochgeladen' . ($w['dauer'] ? ', ' . $h(gmdate($w['dauer'] >= 3600 ? 'G:i:s' : 'i:s', $w['dauer'])) . ' min' : '') . ')', $w['quelle'] === 'eigene')
            . '<button class="primary" type="submit">Speichern</button></form>';
        if ($w['bereit']) $o .= '<p class="tf-ansage-zeile">Eingerichtet: <audio controls preload="none" src="' . $h(tf_basis()) . '/wartemusik.wav"></audio></p>';
        $o .= '<h3>Eigene Musik hochladen</h3><p class="muted">MP3, WAV, OGG, FLAC oder M4A, höchstens 7 MB und 30 Minuten. Die Lautstärke wird angeglichen. '
            . '<strong>Nur Musik verwenden, für die die Praxis die Rechte hat</strong> (z. B. GEMA-freie Musik); urheberrechtlich geschützte Musik in der Warteschleife ist meldepflichtig.</p>'
            . '<div class="tf-ansage-zeile"><input type="file" id="wm-datei" accept="audio/*,.mp3,.wav,.ogg,.flac,.m4a"><button type="button" class="secondary" id="wm-los" data-ziel="' . $h(tf_basis()) . '/wartemusik" data-csrf="' . $h($csrf)
            . '" data-max="' . Anlage::WARTEMUSIK_MAX . '">Hochladen und einschalten</button></div><p class="muted" id="wm-status" role="status"></p>';
        if ($w['bereit']) $o .= '<form method="post" data-confirm="Eingerichtete Wartemusik löschen? Danach hören Anrufer das Klingelzeichen."><input type="hidden" name="kienzle-csrf" value="' . $h($csrf)
            . '"><input type="hidden" name="aktion" value="wartemusik"><input type="hidden" name="wahl" value="entfernen"><button class="small danger" type="submit">Wartemusik löschen</button></form>';
        return self::fold('wartemusik', 'Wartemusik', $marke, $o);
    }

    public static function sprachmenue(array $d, string $csrf, ?string $meldung, ?string $fehler, string $wartemusik = 'klingelzeichen'): string {
        $h = 'tf_h';
        $o = '<div class="tf">' . self::kopfzeile('/sprachmenue', true) . self::meldungen($meldung, $fehler);
        if (!($d['bereit'] ?? false)) {
            return $o . '<section class="card"><p>Das Sprachmenü ist noch nicht eingeschaltet.</p><p class="muted">Ein Admin schaltet es unter Einstellungen → Sprachmenü ein.</p></section></div>';
        }
        $st = Anlage::auftragStatus();
        $tts = Anlage::ttsBereit();
        $o .= '<p class="muted">Änderungen gelten sofort. Geänderte Texte werden danach mit der Sprachausgabe neu erzeugt'
            . ($tts ? '' : ' – dafür muss sie zuerst unter Einstellungen → Sprachmenü eingerichtet sein') . '. Platzhalter: {praxisname}.</p>';
        if (in_array($st['stand'] ?? '', ['laeuft', 'wartet'], true)) $o .= '<p>' . self::tag('warn', 'Ansagen werden erzeugt') . ' <span class="muted">' . $h((string)($st['meldung'] ?? '')) . '</span></p>';
        elseif (($st['stand'] ?? '') === 'fehler') $o .= '<p>' . self::tag('err', 'Fehler beim Erzeugen') . ' <span class="muted">' . $h((string)($st['meldung'] ?? '')) . '</span></p>';
        $stand = (string)$d['stand'];
        foreach ($d['zeiten'] as $s => $z) {
            $tage = array_keys(array_filter($z['tage'], fn($v) => trim((string)$v) !== ''));
            $pflicht = in_array($s, ['oeffnungszeiten', 'telefonzeiten'], true);
            $marke = $tage ? self::tag('ok', implode(', ', array_map(fn($t) => self::TAGE[$t] ?? $t, $tage))) : ($pflicht ? self::tag('warn', 'keine Zeiten') : self::tag('opt', 'aus'));
            $f = '<p class="muted">' . $h(self::ZEIT_HILFE[$s] ?? '') . ' Mehrere Zeiträume mit Komma trennen, z. B. 08:00-12:00, 14:00-18:00; leer = geschlossen.</p><div class="tf-zeiten">';
            foreach ($z['tage'] as $tag => $v) {
                $f .= '<label for="z-' . $h($s . '-' . $tag) . '">' . $h(ucfirst((string)$tag)) . '</label><input id="z-' . $h($s . '-' . $tag) . '" name="zeit[' . $h($s) . '][' . $h((string)$tag) . ']" value="' . $h((string)$v)
                    . '" maxlength="200" placeholder="geschlossen" pattern="[0-9:\-, ;]*" inputmode="numeric">';
            }
            $f .= '</div>';
            $o .= self::fold('z-' . $s, (string)$z['titel'], $marke, '<form method="post" class="tf-form tf-breit">' . self::verborgen($csrf, $stand) . $f . '<button class="primary" type="submit">Speichern</button></form>');
        }
        $gruppen = [];
        foreach ($d['ansagen'] as $a) $gruppen[$a['gruppe']][] = $a;
        $nachWarteschlange = self::wartemusik($csrf, $wartemusik);
        $nachName = array_column($d['ansagen'], null, 'name');
        $vergeben = [];
        foreach (self::TEXTE as $id => [$titel, $teile]) {
            $liste = [];
            foreach ($teile as $t) {
                foreach (isset($nachName[$t]) ? [$nachName[$t]] : ($gruppen[$t] ?? []) as $a) {
                    if (isset($vergeben[$a['name']])) continue;
                    $vergeben[$a['name']] = true;
                    $liste[] = $a;
                }
            }
            if (!$liste && $id !== 'menue') continue;
            $f = '';
            if ($id === 'menue') {
                $f .= '<p class="muted">Tasten der Auswahlmenüs. Leer = Auswahl aus. Ändert man Tasten, werden die Menütexte daraus erzeugt.</p><div class="tf-tasten">';
                foreach ($d['tasten'] as $m) {
                    $f .= '<fieldset><legend>' . $h((string)$m['titel']) . '</legend>';
                    foreach ($m['tasten'] as $t) {
                        $f .= '<label>' . $h((string)$t['label']) . '<input name="taste[' . $h((string)$t['id']) . ']" value="' . $h((string)$t['taste']) . '" maxlength="1" pattern="[0-9*#]?" class="tf-taste" title="Vorgabe: '
                            . $h((string)$t['standard'] ?: 'keine') . '"></label>';
                    }
                    $f .= '</fieldset>';
                }
                $f .= '</div>';
            }
            $zahlen = array_filter($liste, fn($a) => str_starts_with((string)$a['name'], 'queue_digit_'));
            $haupt = array_filter($liste, fn($a) => !str_starts_with((string)$a['name'], 'queue_digit_'));
            foreach ($haupt as $a) $f .= self::ansageFeld($a, true);
            if ($zahlen) {
                $f .= '<details class="how"><summary>Zahlwörter für die Warteposition (' . count($zahlen) . ')</summary><div class="tf-zahlen">';
                foreach ($zahlen as $a) $f .= '<label>' . $h((string)$a['label']) . '<input name="ansage[' . $h((string)$a['name']) . ']" value="' . $h((string)$a['text']) . '" maxlength="200"></label>';
                $f .= '</div></details>';
            }
            $marke = self::tag('ok', count($liste) . ' ' . (count($liste) === 1 ? 'Text' : 'Texte'));
            $o .= self::fold('t-' . $id, $titel, $marke, '<form method="post" class="tf-form tf-breit">' . self::verborgen($csrf, $stand) . $f . '<button class="primary" type="submit">Speichern</button></form>');
            if ($id === 'warteschlange') { $o .= $nachWarteschlange; $nachWarteschlange = ''; }
        }
        return $o . $nachWarteschlange . '</div>';
    }

    public static function einstellungen(array $cfg, array $status, string $csrf, bool $admin, ?string $leitung, ?array $zugang, string $server, ?string $meldung, ?string $fehler): string {
        $h = 'tf_h';
        $o = '<div class="tf">' . self::kopfzeile('/einstellungen', true) . self::meldungen($meldung, $fehler);
        $leitungen = ['' => '– keine –'];
        foreach ($cfg['leitungen'] as $l) $leitungen[$l['id']] = $l['name'] ?: $l['id'];
        if ($admin) {
            // Leitungen
            $liste = '';
            foreach ($cfg['leitungen'] as $l) {
                $z = self::zweckeVon($cfg, $l['id']);
                $liste .= '<div class="tf-zeile"><div><strong>' . $h($l['name'] ?: $l['id']) . '</strong><div class="muted">' . $h(Anlage::ANBIETER[$l['anbieter']] ?? $l['anbieter'])
                    . ' · ' . $h($l['benutzer']) . ($z ? ' · ' . $h(implode(', ', $z)) : '') . '</div></div><div class="tf-knoepfe"><a class="button small secondary" href="' . $h(tf_basis()) . '/einstellungen?leitung=' . $h($l['id']) . '#leitungen">Bearbeiten</a>'
                    . '<form method="post" data-confirm="Leitung „' . $h($l['name'] ?: $l['id']) . '“ löschen?"><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"><input type="hidden" name="aktion" value="leitung-loeschen"><input type="hidden" name="id" value="' . $h($l['id']) . '"><button class="small danger" type="submit">Löschen</button></form></div></div>';
            }
            $e = ['id' => '', 'name' => '', 'anbieter' => '1und1', 'benutzer' => '', 'domain' => '', 'nummer' => '', 'proxy' => '', 'identify' => '', 'client_uri' => '', 'from_user' => '', 'ablauf' => ''];
            foreach ($cfg['leitungen'] as $l) if ($l['id'] === $leitung) $e = $l + $e;
            $neu = $e['id'] === '';
            $form = '<h3>' . ($neu ? 'Neue Leitung' : 'Leitung bearbeiten') . '</h3><input type="hidden" name="id" value="' . $h($e['id']) . '">'
                . self::feld('name', 'Name (z. B. „1&1 Hauptnummer“)', (string)$e['name'])
                . self::auswahl('anbieter', 'Anbieter', Anlage::ANBIETER, (string)$e['anbieter'])
                . self::feld('benutzer', 'SIP-Benutzername', (string)$e['benutzer'], 'Steht in den Zugangsdaten des Anbieters, oft die Rufnummer.', 'text', ' autocomplete="off"')
                . self::feld('passwort', 'SIP-Passwort', '', $neu ? '' : 'Leer lassen, um das gespeicherte Passwort zu behalten.', 'password', ' autocomplete="new-password"')
                . self::feld('nummer', 'Rufnummer der Leitung', (string)$e['nummer'], 'Für voip2gsm und Fonial Pflicht (z. B. 4930123456).')
                . '<details class="how"><summary>Weitere Angaben (nur bei Bedarf)</summary>'
                . self::feld('domain', 'SIP-Server', (string)$e['domain'], 'Leer = Vorgabe des Anbieters.')
                . self::feld('proxy', 'Outbound-Proxy', (string)$e['proxy'], 'Fonial: sbc.sip.plusnet.de (Vorgabe).')
                . self::feld('identify', 'Absender-Netz des Anbieters', (string)$e['identify'], 'Leer = Vorgabe; „-“ = keines.')
                . self::feld('client_uri', 'Client-URI', (string)$e['client_uri'], 'Leer = sip:<Benutzer>@<Server>.')
                . self::feld('from_user', 'From-User', (string)$e['from_user'], 'Leer = Benutzername.')
                . self::feld('ablauf', 'Registrierung erneuern nach (Sekunden)', (string)($e['ablauf'] ?? ''), 'Leer = 300.', 'number', ' min="60" max="3600"')
                . '</details>';
            $inhalt = ($liste !== '' ? '<div class="tf-liste">' . $liste . '</div>' : '<p class="muted">Noch keine Leitung.</p>')
                . self::formular($csrf, 'leitung-speichern', $form, $neu ? 'Leitung hinzufügen' : 'Leitung speichern')
                . (!$neu ? '<p><a href="' . $h(tf_basis()) . '/einstellungen#leitungen">Abbrechen</a></p>' : '');
            $o .= self::fold('leitungen', 'Leitungen', $cfg['leitungen'] ? self::tag('ok', count($cfg['leitungen']) . ' eingetragen') : self::tag('err', 'fehlt'), $inhalt, !$cfg['leitungen'] || $leitung !== null);

            // Rufnummern und Zwecke
            $f = $cfg['fax'];
            $t = $cfg['telefon'];
            $u = $cfg['ueberlauf'];
            $form = '<fieldset><legend>Telefon</legend>' . self::auswahl('tel_leitung_ein', 'Leitung für eingehende Anrufe', $leitungen, (string)$t['leitung_ein'])
                . self::feld('tel_nummer_ein', 'Telefonnummer der Praxis (wie der Anbieter sie schickt)', (string)$t['nummer_ein'], 'z. B. 4930123456')
                . self::auswahl('tel_leitung_aus', 'Leitung für ausgehende Anrufe', ['' => 'wie eingehend'] + array_slice($leitungen, 1, null, true), (string)$t['leitung_aus'])
                . self::feld('tel_kennung_aus', 'Absendernummer beim Anrufen', (string)$t['kennung_aus'], 'Leer = Telefonnummer der Praxis.')
                . self::haken('tel_aus_zaehlt', 'Ausgehende Gespräche zählen zur Kanalgrenze', (bool)$t['aus_zaehlt'])
                . self::haken('tel_ausland', 'Auslandsgespräche (00…) erlauben', (bool)$t['ausland'])
                . self::haken('tel_sonder', 'Sonderrufnummern (0900, 0137, 118…) erlauben', (bool)$t['sonderrufnummern']) . '</fieldset>'
                . '<fieldset><legend>Fax</legend>' . self::auswahl('fax_leitung', 'Leitung für Fax', $leitungen, (string)$f['leitung'])
                . self::feld('fax_nummer', 'Faxnummer (wie der Anbieter sie schickt)', (string)$f['nummer'], 'z. B. 4930123459')
                . self::feld('fax_kennung', 'Absendernummer für Faxe', (string)$f['kennung'], 'Leer = Faxnummer.')
                . self::feld('fax_name', 'Faxkennung (Absenderkennung im Faxkopf, Anzeige beim Empfänger)', (string)$f['name'], 'Leer = Praxisname aus Verwaltung → Praxis. Bis 60 Zeichen; als Kopfzeile jeder Seite. Im Faxprotokoll (Stationskennung) sind technisch nur 20 Zeichen möglich, dort steht die gekürzte Form.') . '</fieldset>'
                . '<fieldset><legend>Überlauf (sipgate trunking, erprobt)</legend>' . self::auswahl('ueb_leitung', 'Leitung für den Überlauf', $leitungen, (string)$u['leitung'])
                . self::feld('ueb_nummer', 'Rufnummer des Überlaufs', (string)$u['nummer']) . '</fieldset>';
            $zweck = $t['leitung_ein'] !== '' || $f['leitung'] !== '';
            $o .= self::fold('rufnummern', 'Rufnummern', $zweck ? self::tag('ok', 'zugeordnet') : self::tag('err', 'fehlt'), self::formular($csrf, 'zwecke-speichern', $form, 'Rufnummern speichern'));

            // Kanäle und Warteschlange
            $k = $cfg['kanaele'];
            $form = self::feld('k_gesamt', 'Gleichzeitige Gespräche insgesamt (Hauptanschluss)', (string)$k['gesamt'], 'Empfehlung 4.', 'number', ' min="1" max="60"')
                . self::feld('k_telefon', 'davon höchstens Telefon', (string)$k['telefon'], 'Empfehlung 3.', 'number', ' min="1" max="60"')
                . self::feld('k_fax', 'davon höchstens Fax', (string)$k['fax'], 'Empfehlung 3.', 'number', ' min="1" max="60"')
                . self::feld('k_ueberlauf', 'Gespräche über den Überlauf', (string)$k['ueberlauf'], 'Zusätzlich zum Hauptanschluss, Empfehlung 2.', 'number', ' min="1" max="30"')
                . self::feld('max_wartend', 'Höchstens wartende Anrufer', (string)$cfg['warteschlange']['max_wartend'], 'Weitere Anrufer hören „besetzt“, Empfehlung 5.', 'number', ' min="1" max="50"');
            $o .= self::fold('kanaele', 'Kanäle und Warteschlange', self::tag('ok', $k['gesamt'] . ' Kanäle'), '<p class="muted">Die Warteschlange ruft zuerst ein Telefon, alle 20 Sekunden kommt das nächste hinzu. Die Warteposition wird beim Eintritt und danach jede Minute angesagt.</p>'
                . self::formular($csrf, 'kanaele-speichern', $form, 'Speichern'));
        }

        if ($admin) {
            // Sprachmenü (Kienzlefon 2.7)
            $kz = $cfg['kienzlefon'];
            $form = self::haken('aktiv', 'Sprachmenü einschalten (Anrufer hören Begrüßung und Menü, statt direkt in der Warteschlange zu landen)', (bool)$kz['aktiv'])
                . self::auswahl('tts', 'Sprachausgabe der Ansagen', Anlage::SPRACHAUSGABE, (string)$kz['tts'])
                . '<details class="how"' . ($kz['tts'] === 'google' ? ' open' : '') . '><summary>Google (wahlweise)</summary><p class="muted">Nur die festen Ansagetexte der Praxis gehen an Google, keine Anrufer- oder Patientendaten. '
                . 'Schlüssel: Google Cloud Console → APIs & Dienste → Anmeldedaten (Text-to-Speech API freigeben).</p>'
                . self::feld('google_schluessel', 'Google-API-Schlüssel', '', ($kz['google']['schluessel'] ?? '') !== '' ? 'Gespeichert. Leer lassen, um ihn zu behalten.' : 'Noch keiner gespeichert.', 'password', ' autocomplete="new-password"')
                . (($kz['google']['schluessel'] ?? '') !== '' ? self::haken('google_entfernen', 'Gespeicherten Schlüssel löschen', false) : '')
                . self::feld('google_stimme', 'Stimme', (string)($kz['google']['stimme'] ?? ''), 'Leer = de-DE-Chirp3-HD-Enceladus.')
                . self::feld('google_tempo', 'Sprechtempo', (string)($kz['google']['tempo'] ?? ''), 'Leer = 0,93 (0,25 bis 2).') . '</details>';
            $o .= self::fold('sprachmenue', 'Sprachmenü', $kz['aktiv'] ? self::tag('ok', 'an') : self::tag('opt', 'aus'),
                '<p class="muted">Sprachmenü aus Kienzlefon: Öffnungs- und Telefonzeiten, Begrüßung, Menü (Praxis, Rezept, Überweisung, Termin), Apotheken- und Fachstellenweg. '
                . 'Erst einschalten, wenn die Ansagen erzeugt sind. Noch in Arbeit: Aufnahme von Anliegen (Whisper).</p>'
                . ($kz['aktiv'] ? '<p><a class="button secondary" href="' . $h(tf_basis()) . '/sprachmenue">Zeiten, Ansagetexte, Menütasten und Wartemusik bearbeiten →</a></p>' : '')
                . self::formular($csrf, 'sprachmenue-speichern', $form, 'Speichern') . self::ansagen($csrf, (string)$kz['tts']));
        }

        // Telefone
        $liste = '<table class="tf-tabelle"><tr><th>Nr.</th><th>Name</th><th>Warteschlange</th><th>Angemeldet</th><th></th></tr>';
        foreach ($cfg['nebenstellen'] as $n) {
            $s = $status['telefone'][$n['nummer']] ?? '';
            $liste .= '<tr><td>' . $h($n['nummer']) . '</td><td colspan="2"><form method="post" class="tf-inline"><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"><input type="hidden" name="aktion" value="nebenstelle-speichern">'
                . '<input type="hidden" name="nummer" value="' . $h($n['nummer']) . '"><input name="name" value="' . $h($n['name']) . '" maxlength="30" aria-label="Name">'
                . '<label class="check"><input type="checkbox" name="warteschlange" value="1"' . ($n['warteschlange'] ? ' checked' : '') . '> ja</label><button class="small" type="submit">Speichern</button></form></td>'
                . '<td>' . ($s === 'Avail' ? self::tag('ok', 'ja') : self::tag('warn', 'nein')) . '</td><td class="tf-knoepfe">';
            if ($admin) {
                foreach ([['zugang-zeigen', 'Zugang', 'small secondary', ''], ['passwort-neu', 'Neues Passwort', 'small', 'Neues Passwort für ' . $n['nummer'] . '? Das Telefon muss danach neu eingerichtet werden.'],
                          ['nebenstelle-loeschen', 'Löschen', 'small danger', 'Nebenstelle ' . $n['nummer'] . ' löschen?']] as [$a, $t, $kl, $frage]) {
                    $liste .= '<form method="post"' . ($frage !== '' ? ' data-confirm="' . $h($frage) . '"' : '') . '><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"><input type="hidden" name="aktion" value="' . $a . '">'
                        . '<input type="hidden" name="nummer" value="' . $h($n['nummer']) . '"><button class="' . $kl . '" type="submit">' . $t . '</button></form>';
                }
            }
            $liste .= '</td></tr>';
        }
        $liste .= '</table>';
        $inhalt = ($cfg['nebenstellen'] ? $liste : '<p class="muted">Noch keine Telefone.</p>');
        if ($zugang) {
            $inhalt .= '<div class="card tf-zugang"><h3>Zugang für Telefon ' . $h($zugang['nummer']) . '</h3><dl><dt>Server (Registrar)</dt><dd><code>' . $h($server) . '</code>, Port 5060, UDP</dd>'
                . '<dt>Benutzer</dt><dd><code>' . $h($zugang['nummer']) . '</code></dd><dt>Passwort</dt><dd><code>' . $h($zugang['passwort']) . '</code></dd></dl>'
                . '<p class="muted">Nur hier sichtbar und nach dem Neuladen wieder verborgen. Nicht aufschreiben oder weitergeben.</p></div>';
        }
        if ($admin) {
            $inhalt .= self::formular($csrf, 'nebenstellen-anlegen', self::feld('anzahl', 'Neue Telefone anlegen (Anzahl)', '1', 'Nummern ab 201; jedes bekommt ein eigenes Passwort.', 'number', ' min="1" max="20"'), 'Anlegen', 'tf-form tf-schmal');
        }
        $o .= self::fold('telefone', 'Telefone', $cfg['nebenstellen'] ? self::tag('ok', count($cfg['nebenstellen']) . ' angelegt') : self::tag('err', 'fehlt'), $inhalt, !$admin || $zugang !== null);

        if ($admin) {
            $nz = $cfg['netz'];
            $form = self::feld('lokal', 'Netz der Telefone', (string)$nz['lokal'], 'Netz, in dem die Telefone stehen, z. B. 192.0.2.0/24.')
                . self::feld('erlaubt', 'Anmelden erlaubt aus', (string)$nz['erlaubt'], 'Leer = nur aus dem Netz der Telefone. 0.0.0.0/0 nur mit VPN oder zusätzlichem Schutz.')
                . self::feld('extern', 'Öffentliche Adresse (nur bei Bedarf)', (string)$nz['extern'], 'Für Anbieter, die ohne sie keinen Ton haben (z. B. praxis.example.org).');
            $o .= self::fold('netz', 'Netz', $nz['lokal'] !== '' ? self::tag('ok', $nz['lokal']) : self::tag('warn', 'offen'), self::formular($csrf, 'netz-speichern', $form, 'Netz speichern')
                . '<p class="muted">Firewall: Unter Verwaltung → Firewall die Einträge „Telefon: …“ freischalten (Telefone 5060/UDP aus dem Praxisnetz, Anbieter 5070/UDP und Sprache 12000–12999/UDP von überall).</p>');
        }
        return $o . '</div>';
    }
}
