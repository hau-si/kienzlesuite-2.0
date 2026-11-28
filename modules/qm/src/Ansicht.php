<?php
declare(strict_types=1);
namespace KienzleQm;

/**
 * HTML des QM-Moduls im Rahmen von Praxisherz (Karten, Knöpfe, Tags aus core.css). Startseite = „Meine Lesemappe“, Handbuch = Ordner, Liste, Vorschau,
 * Dokumentseite = Inhalt bzw. Editor mit Freigabeweg, Lesestand und Versionen.
 */
final class Ansicht {
    public const MELDUNGEN = [
        'angelegt' => 'Dokument angelegt. Es ist ein Entwurf, bis es vidiert ist.', 'gespeichert' => 'Gespeichert.', 'eingereicht' => 'Zur Prüfung eingereicht.',
        'vidiert' => 'Vidiert. Diese Fassung gilt ab jetzt.', 'zurueck' => 'Zurückgewiesen. Der Entwurf liegt wieder in Bearbeitung.', 'verworfen' => 'Entwurf verworfen.',
        'geloescht' => 'Entwurf verworfen, das Dokument ist gelöscht.', 'vorgelegt' => 'Vorgelegt.', 'gelesen' => 'Danke, Ihre Lesebestätigung ist gespeichert.',
        'geprueft' => 'Als geprüft bestätigt, die nächste Prüfung ist eingetragen.', 'archiviert' => 'Archiviert.', 'wiederhergestellt' => 'Aus dem Archiv geholt.',
        'ordner' => 'Ordner gespeichert.', 'ordner-weg' => 'Ordner gelöscht.',
    ];

    /** @var array{lesen:bool,schreiben:bool,vidieren:bool,vorlegen:bool,admin:bool} */
    public static array $r = ['lesen' => true, 'schreiben' => false, 'vidieren' => false, 'vorlegen' => false, 'admin' => false];
    public static string $csrf = '';
    public static string $login = '';
    public static string $name = '';

    public static function kopf(string $base, string $csrf): string {
        $v = static fn(string $f): string => '?v=' . substr((string)@md5_file(dirname(__DIR__) . '/public/assets/' . $f), 0, 8);
        return '<meta name="kienzle-csrf" content="' . qm_h($csrf) . '"><meta name="qm-basis" content="' . qm_h($base) . '">'
            . '<link rel="stylesheet" href="' . qm_h($base) . '/assets/qm.css' . $v('qm.css') . '"><script src="' . qm_h($base) . '/assets/qm.js' . $v('qm.js') . '" defer></script>';
    }

    private static function u(string $pfad, array $q = []): string {
        $q = array_filter($q, fn($w) => $w !== '' && $w !== null && $w !== 0 && $w !== false);
        return qm_basis() . $pfad . ($q ? '?' . http_build_query($q) : '');
    }

    private static function csrfFeld(): string { return '<input type="hidden" name="kienzle-csrf" value="' . qm_h(self::$csrf) . '">'; }

    private static function knopf(string $aktion, array $felder, string $text, string $klasse = '', string $frage = '', string $ziel = ''): string {
        $o = '<form method="post" class="inline"' . ($ziel !== '' ? ' action="' . qm_h($ziel) . '"' : '') . '>' . self::csrfFeld() . '<input type="hidden" name="aktion" value="' . qm_h($aktion) . '">';
        foreach ($felder as $k => $w) $o .= '<input type="hidden" name="' . qm_h($k) . '" value="' . qm_h((string)$w) . '">';
        return $o . '<button' . ($klasse !== '' ? ' class="' . qm_h($klasse) . '"' : '') . ($frage !== '' ? ' data-frage="' . qm_h($frage) . '"' : '') . '>' . qm_h($text) . '</button></form>';
    }

    private static function tag(string $text, string $art = ''): string { return '<span class="tag' . ($art !== '' ? ' ' . $art : '') . '">' . qm_h($text) . '</span>'; }

    private static function d(int|string $t): string {
        if (is_string($t)) return $t === '' ? '' : date('d.m.Y', (int)strtotime($t));
        return $t > 0 ? date('d.m.Y', $t) : '';
    }

    private static function zustandTag(array $dok): string {
        return match ($dok['zustand']) {
            'freigegeben' => self::tag('Freigegeben' . ($dok['vidiert_kuerzel'] ?? '' ? ' · ' . $dok['vidiert_kuerzel'] : ''), 'ok'),
            'faellig' => self::tag('Prüfung fällig', 'err'),
            'pruefung' => self::tag('Zur Prüfung', 'warn'),
            'archiv' => self::tag('Archiviert'),
            default => self::tag('Entwurf'),
        } . ($dok['gueltig'] !== null && ($dok['entwurf_status'] ?? null) ? ' ' . self::tag(($dok['entwurf_status'] === 'pruefung' ? 'V' . $dok['e_nummer'] . ' zur Prüfung' : 'V' . $dok['e_nummer'] . ' in Arbeit'), $dok['entwurf_status'] === 'pruefung' ? 'warn' : '') : '');
    }

    private static function tagsZeigen(array $dok, bool $links = true): string {
        $o = '';
        foreach (Dokumente::tags($dok) as $t) $o .= $links ? '<a class="tag qm-tag" href="' . qm_h(self::u('/handbuch', ['tag' => $t])) . '">' . qm_h($t) . '</a> ' : self::tag($t, 'qm-tag') . ' ';
        return $o;
    }

    /** Seitenkopf mit Unterseiten. */
    private static function seitenkopf(string $route, string $eyebrow, string $titel, string $extra = ''): string {
        $link = static fn(string $ziel, string $text, string $r, string $klasse = 'secondary'): string => '<a class="button ' . ($route === $r ? 'primary' : $klasse) . '" href="' . qm_h(self::u($ziel)) . '"' . ($route === $r ? ' aria-current="page"' : '') . '>' . qm_h($text) . '</a>';
        $o = '<header class="qm-kopf"><div><p class="eyebrow">' . $eyebrow . '</p><h1>' . qm_h($titel) . '</h1>' . $extra . '</div><nav class="qm-werkzeuge" aria-label="Qualitätsmanagement">'
            . $link('/', 'Meine Lesemappe', 'start') . $link('/handbuch', 'Handbuch', 'handbuch');
        if (self::$r['schreiben']) $o .= $link('/import', 'Import', 'import') . $link('/neu', '+ Neues Dokument', 'neu', 'primary');
        return $o . '</nav></header>';
    }

    public static function meldung(?string $ok, ?string $fehler): string {
        $o = '';
        if ($ok !== null && isset(self::MELDUNGEN[$ok])) $o .= '<p class="notice" role="status">' . qm_h(self::MELDUNGEN[$ok]) . '</p>';
        if ($fehler !== null && $fehler !== '') $o .= '<p class="error" role="alert">' . qm_h($fehler) . '</p>';
        return $o;
    }

    // ---- Startseite (Lesemappe) -------------------------------------------------------------------------------------

    public static function start(string $meldung): string {
        $vorname = preg_split('/\s+/u', trim(preg_replace('/^(dr\.|prof\.|med\.|\s)+/iu', '', self::$name) ?? ''))[0] ?? '';
        $mappe = Dokumente::lesemappe(self::$login);
        $vid = self::$r['vidieren'] ? Dokumente::zuVidieren() : [];
        $faellig = (self::$r['vidieren'] || self::$r['schreiben']) ? Dokumente::faellig() : [];
        $entw = self::$r['schreiben'] ? Dokumente::entwuerfe() : [];
        $heute = date('Y-m-d');
        $o = '<div class="qm">' . self::seitenkopf('start', 'Qualitätsmanagement', 'Guten Tag' . ($vorname !== '' ? ', ' . $vorname : '')) . $meldung;
        $o .= '<div class="qm-zahlen"><a class="card qm-zahl" href="#mappe"><span class="muted">Für mich zu lesen</span><strong>' . count($mappe) . '</strong></a>';
        if (self::$r['vidieren']) $o .= '<a class="card qm-zahl" href="#vidieren"><span class="muted">Von mir zu vidieren</span><strong>' . count($vid) . '</strong></a>';
        if ($faellig || self::$r['vidieren']) $o .= '<a class="card qm-zahl" href="' . qm_h(self::u('/handbuch', ['zustand' => 'faellig'])) . '"><span class="muted">Prüfung fällig</span><strong' . ($faellig ? ' class="qm-rot"' : '') . '>' . count($faellig) . '</strong></a>';
        $o .= '<form class="card qm-suche" method="get" action="' . qm_h(self::u('/handbuch')) . '" role="search"><label class="qm-unsichtbar" for="qm-q">Dokument suchen</label>'
            . '<input id="qm-q" name="q" type="search" placeholder="Dokument suchen, z. B. „notf“ oder „hyg“" autocomplete="off"><div class="qm-chips">';
        foreach (array_slice(Dokumente::alleTags(), 0, 8, true) as $t => $n) $o .= '<a class="qm-chip" href="' . qm_h(self::u('/handbuch', ['tag' => $t])) . '">#' . qm_h($t) . '</a>';
        $o .= '</div></form></div>';

        $o .= '<div class="qm-zwei"><section class="card"><h2 id="mappe">Zur Kenntnisnahme vorgelegt</h2>';
        if (!$mappe) $o .= '<p class="muted">Nichts zu lesen. ✓</p>';
        foreach ($mappe as $m) {
            $spaet = $m['frist'] !== '' && $m['frist'] < $heute;
            $o .= '<div class="qm-eintrag"><div><a href="' . qm_h(self::u('/dokument/' . $m['id'])) . '"><strong>' . qm_h($m['titel']) . '</strong></a><br><span class="muted">Version ' . (int)$m['nummer']
                . ' · vorgelegt von ' . qm_h($m['von']) . ' am ' . self::d((int)$m['am']) . '</span>'
                . ($m['frist'] !== '' ? ' ' . ($spaet ? self::tag('überfällig seit ' . self::d($m['frist']), 'err') : '<span class="muted">· bis ' . self::d($m['frist']) . '</span>') : '')
                . '</div><a class="button" href="' . qm_h(self::u('/dokument/' . $m['id'])) . '">Lesen</a></div>';
        }
        if (self::$r['vidieren']) {
            $o .= '<h2 id="vidieren" class="qm-abstand">Zum Vidieren</h2>';
            if (!$vid) $o .= '<p class="muted">Nichts liegt zur Prüfung vor.</p>';
            foreach ($vid as $v) $o .= '<div class="qm-eintrag"><div><strong>' . qm_h($v['titel']) . '</strong><br><span class="muted">Version ' . (int)$v['nummer'] . ' von ' . qm_h($v['autor_name'])
                . ' · eingereicht am ' . self::d((int)$v['eingereicht']) . '</span></div><a class="button secondary" href="' . qm_h(self::u('/dokument/' . $v['id'], ['ansicht' => 'entwurf'])) . '">Prüfen</a></div>';
        }
        if ($faellig) {
            $o .= '<h2 class="qm-abstand">Prüfung fällig</h2>';
            foreach ($faellig as $f) $o .= '<div class="qm-eintrag"><div><strong>' . qm_h($f['titel']) . '</strong><br><span class="muted">fällig seit ' . self::d($f['naechste_pruefung'])
                . '</span></div><a class="button secondary" href="' . qm_h(self::u('/dokument/' . $f['id'])) . '">Öffnen</a></div>';
        }
        if ($entw) {
            $o .= '<h2 class="qm-abstand">Entwürfe in Arbeit</h2>';
            foreach (array_slice($entw, 0, 10) as $e) $o .= '<div class="qm-eintrag"><div><strong>' . qm_h($e['titel']) . '</strong><br><span class="muted">Version ' . (int)$e['nummer'] . ' · '
                . qm_h($e['autor_name']) . ' · ' . self::d((int)$e['geaendert']) . '</span>' . ($e['notiz'] !== '' ? '<br><span class="muted">' . qm_h($e['notiz']) . '</span>' : '')
                . '</div><a class="button secondary" href="' . qm_h(self::u('/dokument/' . $e['id'], ['ansicht' => 'entwurf'])) . '">Öffnen</a></div>';
        }
        $o .= '</section><section class="card"><h2>Zuletzt freigegeben</h2>';
        $neu = Dokumente::zuletztFreigegeben();
        if (!$neu) $o .= '<p class="muted">Noch keine vidierten Dokumente. ' . (self::$r['schreiben'] ? 'Legen Sie ein Dokument an oder übernehmen Sie Musterdokumente über „Import“.' : '') . '</p>';
        foreach ($neu as $n) $o .= '<div class="qm-eintrag"><div><a href="' . qm_h(self::u('/dokument/' . $n['id'])) . '">' . qm_h($n['titel']) . '</a><br><span class="muted">Version ' . (int)$n['nummer']
            . ' · ' . self::d((int)$n['vidiert_am']) . ' · ' . qm_h($n['vidiert_kuerzel']) . '</span></div></div>';
        return $o . '</section></div></div>';
    }

    // ---- Handbuch ---------------------------------------------------------------------------------------------------

    public static function handbuch(array $f, string $meldung, array $personen): string {
        $mitEntwuerfen = self::$r['schreiben'] || self::$r['vidieren'];
        $liste = Dokumente::liste($f, $mitEntwuerfen);
        $baum = Dokumente::ordnerBaum();
        $ordnerId = (int)($f['ordner'] ?? 0);
        $filter = ['ordner' => $ordnerId ?: '', 'tag' => (string)($f['tag'] ?? ''), 'zustand' => (string)($f['zustand'] ?? ''), 'q' => (string)($f['q'] ?? ''), 'text' => !empty($f['text']) ? '1' : ''];
        $titel = 'Handbuch';
        foreach ($baum as $b) if ($b['id'] === $ordnerId) $titel = $b['name'];
        $o = '<div class="qm">' . self::seitenkopf('handbuch', 'Qualitätsmanagement', $titel) . $meldung . '<div class="qm-drei">';

        // Ordner und Tags
        $o .= '<aside class="card qm-baum"><h2>Ordner</h2><nav aria-label="Ordner"><a class="qm-ordner' . ($ordnerId === 0 ? ' aktiv' : '') . '" href="' . qm_h(self::u('/handbuch', array_merge($filter, ['ordner' => '']))) . '">Alle Dokumente</a>';
        foreach ($baum as $b) $o .= '<a class="qm-ordner tiefe-' . min(4, $b['tiefe']) . ($b['id'] === $ordnerId ? ' aktiv' : '') . '" href="' . qm_h(self::u('/handbuch', array_merge($filter, ['ordner' => $b['id']]))) . '">' . qm_h($b['name']) . '</a>';
        $o .= '</nav>';
        if (self::$r['schreiben']) $o .= self::ordnerVerwalten($baum, $ordnerId);
        $tags = Dokumente::alleTags();
        if ($tags) {
            $o .= '<h2 class="qm-abstand">Tags</h2><div class="qm-chips">';
            foreach ($tags as $t => $n) $o .= '<a class="qm-chip' . (mb_strtolower($t) === mb_strtolower($filter['tag']) ? ' an' : '') . '" href="' . qm_h(self::u('/handbuch', array_merge($filter, ['tag' => mb_strtolower($t) === mb_strtolower($filter['tag']) ? '' : $t]))) . '">' . qm_h($t) . ' <small>' . $n . '</small></a>';
            $o .= '</div>';
        }
        $o .= '</aside>';

        // Suche und Liste
        $o .= '<section class="qm-mitte"><form class="card qm-filter" method="get" action="' . qm_h(self::u('/handbuch')) . '" role="search">';
        foreach (['ordner', 'tag', 'zustand'] as $k) if ($filter[$k] !== '') $o .= '<input type="hidden" name="' . $k . '" value="' . qm_h((string)$filter[$k]) . '">';
        $o .= '<div class="qm-suchzeile"><label class="qm-unsichtbar" for="qm-suche">Suche</label><input id="qm-suche" name="q" type="search" value="' . qm_h($filter['q']) . '" placeholder="Namensschnipsel, z. B. „desinf“" autocomplete="off" data-live="#qm-liste">'
            . '<button class="secondary">Suchen</button></div><label class="check"><input type="checkbox" name="text" value="1"' . ($filter['text'] ? ' checked' : '') . '> auch im Text der Dokumente suchen</label><div class="qm-chips">';
        $zustaende = ['' => 'Alle'] + ($mitEntwuerfen ? ['entwurf' => 'Entwurf', 'pruefung' => 'Zur Prüfung'] : []) + ['freigegeben' => 'Freigegeben', 'faellig' => 'Prüfung fällig', 'archiv' => 'Archiv'];
        foreach ($zustaende as $k => $t) $o .= '<a class="qm-chip' . ($filter['zustand'] === $k ? ' an' : '') . '" href="' . qm_h(self::u('/handbuch', array_merge($filter, ['zustand' => $k]))) . '">' . qm_h($t) . '</a>';
        $o .= '</div></form><div class="card"><table class="qm-liste" id="qm-liste"><thead><tr><th>Dokument</th><th>Stand</th><th>Status</th>' . (self::$r['vorlegen'] ? '<th>Gelesen</th>' : '') . '</tr></thead><tbody>';
        $gewaehlt = (int)($f['d'] ?? 0);
        foreach ($liste as $d) {
            $nr = $d['gueltig'] !== null ? (int)$d['g_nummer'] : (int)$d['e_nummer'];
            $datum = $d['gueltig'] !== null ? self::d((int)$d['vidiert_am']) : self::d((int)$d['geaendert']);
            $o .= '<tr data-suche="' . qm_h(mb_strtolower($d['titel'] . ' ' . str_replace(',', ' ', $d['tags']))) . '"' . ((int)$d['id'] === $gewaehlt ? ' class="gewaehlt"' : '') . '><td><a href="'
                . qm_h(self::u('/handbuch', array_merge($filter, ['d' => $d['id']]))) . '" class="qm-titel">' . self::markieren($d['titel'], $filter['q']) . '</a> ' . self::tagsZeigen($d)
                . ($d['treffer'] !== '' ? '<br><small class="muted">' . self::markieren($d['treffer'], $filter['q']) . '</small>' : '') . '</td><td>Version ' . $nr . '<br><small class="muted">' . $datum . '</small></td><td>'
                . self::zustandTag($d) . '</td>' . (self::$r['vorlegen'] ? '<td>' . ((int)$d['vorgelegt'] > 0 ? (int)$d['gelesen'] . ' / ' . (int)$d['vorgelegt'] : '–') . '</td>' : '') . '</tr>';
        }
        $o .= '</tbody></table>';
        if (!$liste) $o .= '<p class="muted">Keine Dokumente gefunden.' . ($filter['q'] !== '' && !$filter['text'] ? ' Tipp: „auch im Text der Dokumente suchen“ einschalten.' : '') . '</p>';
        $o .= '<p class="muted qm-leer" hidden>Keine Treffer in dieser Liste.</p>';
        $gueltige = array_values(array_filter($liste, fn($d) => $d['gueltig'] !== null && $d['zustand'] !== 'archiv'));
        if (self::$r['vorlegen'] && $gueltige && ($filter['tag'] !== '' || $filter['ordner'] !== '' || $filter['q'] !== '')) {
            $o .= '<details class="fold"><summary><strong>Alle ' . count($gueltige) . ' gültigen Dokumente dieser Auswahl vorlegen</strong></summary><p class="muted">Praktisch für die Einarbeitung: z. B. alle Dokumente mit dem Tag „Einarbeitung“ an eine neue Mitarbeiterin.</p>'
                . self::vorlegenFormular(array_map(fn($d) => (int)$d['id'], $gueltige), $personen, self::u('/handbuch', $filter)) . '</details>';
        }
        $o .= '</div></section>';

        // Vorschau
        if ($gewaehlt > 0 && ($dok = Dokumente::dokument($gewaehlt))) $o .= self::vorschau($dok);
        else $o .= '<aside class="card qm-vorschau qm-hinweis"><p class="muted">Ein Dokument anklicken, um es hier zu sehen.</p></aside>';
        return $o . '</div></div>';
    }

    private static function markieren(string $text, string $q): string {
        $h = qm_h($text);
        $q = trim($q);
        if ($q === '') return $h;
        return preg_replace('/(' . preg_quote(qm_h($q), '/') . ')/iu', '<mark>$1</mark>', $h) ?? $h;
    }

    private static function ordnerWahl(array $baum, string $name, ?int $wahl, string $leer): string {
        $o = '<select name="' . qm_h($name) . '"><option value="0">' . qm_h($leer) . '</option>';
        foreach ($baum as $b) $o .= '<option value="' . $b['id'] . '"' . ($wahl === $b['id'] ? ' selected' : '') . '>' . str_repeat('  ', $b['tiefe']) . qm_h($b['name']) . '</option>';
        return $o . '</select>';
    }

    private static function ordnerVerwalten(array $baum, int $ordnerId): string {
        $o = '<details class="fold qm-verwalten"><summary>Ordner verwalten</summary><form method="post">' . self::csrfFeld() . '<input type="hidden" name="aktion" value="ordner-anlegen">'
            . '<label>Neuer Ordner<input name="name" maxlength="80" required></label><label>in' . self::ordnerWahl($baum, 'eltern', $ordnerId ?: null, 'oberste Ebene') . '</label><button class="secondary">Anlegen</button></form>';
        if ($ordnerId > 0 && ($ord = Dokumente::ordner($ordnerId))) {
            $o .= '<form method="post">' . self::csrfFeld() . '<input type="hidden" name="aktion" value="ordner-aendern"><input type="hidden" name="id" value="' . $ordnerId . '">'
                . '<label>Gewählten Ordner umbenennen<input name="name" maxlength="80" required value="' . qm_h($ord['name']) . '"></label><label>liegt in'
                . self::ordnerWahl(array_values(array_filter($baum, fn($b) => !in_array($b['id'], Dokumente::ordnerMitUnterordnern($ordnerId), true))), 'eltern', $ord['eltern'] === null ? null : (int)$ord['eltern'], 'oberste Ebene')
                . '</label><button class="secondary">Speichern</button></form>'
                . self::knopf('ordner-loeschen', ['id' => $ordnerId], 'Ordner löschen', 'danger', 'Diesen Ordner löschen? Das geht nur, wenn er leer ist.');
        }
        return $o . '</details>';
    }

    private static function vorschau(array $dok): string {
        $darfEntwurf = self::$r['schreiben'] || self::$r['vidieren'];
        $vid = $dok['gueltig'] !== null ? (int)$dok['gueltig'] : ($darfEntwurf && $dok['entwurf'] !== null ? (int)$dok['entwurf'] : 0);
        $v = $vid ? Dokumente::version($vid) : null;
        $o = '<aside class="card qm-vorschau"><h2>' . qm_h($dok['titel']) . '</h2>';
        if (!$v) return $o . '<p class="muted">Noch nicht freigegeben.</p></aside>';
        $ordner = $dok['ordner'] !== null ? Dokumente::ordner((int)$dok['ordner']) : null;
        $o .= '<p class="muted">Version ' . (int)$v['nummer'] . ($ordner ? ' · ' . qm_h($ordner['name']) : '') . '</p><div class="qm-text qm-klein">' . $v['inhalt'] . '</div>';
        if ($v['status'] === 'freigegeben') {
            $o .= '<p><strong>Geprüft und freigegeben</strong><br>' . qm_h($v['vidiert_name']) . ' (' . qm_h($v['vidiert_kuerzel']) . '), ' . self::d((int)$v['vidiert_am'])
                . ($dok['naechste_pruefung'] !== '' ? '<br><span class="muted">Nächste Prüfung: ' . self::d($dok['naechste_pruefung']) . '</span>' : '') . '</p>';
            if (self::$r['vorlegen']) {
                $ls = Dokumente::lesestand((int)$v['id']);
                if ($ls) {
                    $o .= '<p><strong>Gelesen</strong><br>';
                    foreach ($ls as $l) $o .= self::tag($l['name'] . ((int)$l['gelesen'] > 0 ? ' ✓' : ' offen'), (int)$l['gelesen'] > 0 ? 'ok' : 'warn') . ' ';
                    $o .= '</p>';
                }
            }
        } else {
            $o .= '<p>' . self::tag(Dokumente::STATUS[$v['status']] ?? $v['status'], 'warn') . '</p>';
        }
        return $o . '<div class="actions"><a class="button" href="' . qm_h(self::u('/dokument/' . $dok['id'])) . '">Öffnen</a>'
            . ($v['status'] === 'freigegeben' ? '<a class="button secondary" href="' . qm_h(self::u('/druck/' . $dok['id'])) . '" target="_blank" rel="noopener">Drucken</a>' : '') . '</div></aside>';
    }

    // ---- Vorlegen ---------------------------------------------------------------------------------------------------

    private static function vorlegenFormular(array $ids, array $personen, string $ziel = ''): string {
        if (!$personen) return '<p class="error">Die Basis liefert keine Personenliste. Bitte die Basis aktualisieren (ab 0.22.0-beta.46).</p>';
        $gruppen = [];
        foreach ($personen as $p) foreach ($p['gruppen'] as $g) $gruppen[$g] = ($gruppen[$g] ?? 0) + 1;
        ksort($gruppen, SORT_NATURAL | SORT_FLAG_CASE);
        $o = '<form method="post" class="qm-vorlegen"' . ($ziel !== '' ? ' action="' . qm_h($ziel) . '"' : '') . '>' . self::csrfFeld() . '<input type="hidden" name="aktion" value="vorlegen">';
        foreach ($ids as $id) $o .= '<input type="hidden" name="ids[]" value="' . (int)$id . '">';
        $o .= '<fieldset><legend>Gruppen</legend><label class="check"><input type="checkbox" name="gruppen[]" value="*" data-gruppe="*"> Alle mit Zugang (' . count($personen) . ')</label>';
        foreach ($gruppen as $g => $n) $o .= '<label class="check"><input type="checkbox" name="gruppen[]" value="' . qm_h($g) . '" data-gruppe="' . qm_h($g) . '"> ' . qm_h($g) . ' (' . $n . ')</label>';
        $o .= '</fieldset><fieldset><legend>Personen</legend>';
        foreach ($personen as $p) $o .= '<label class="check"><input type="checkbox" name="personen[]" value="' . qm_h($p['login']) . '" data-gruppen="' . qm_h(implode("\n", $p['gruppen'])) . '"> ' . qm_h($p['name'])
            . ($p['gruppen'] ? ' <small>' . qm_h(implode(', ', $p['gruppen'])) . '</small>' : '') . '</label>';
        return $o . '</fieldset><label>Lesen bis<input type="date" name="frist" value="' . date('Y-m-d', strtotime('+14 days')) . '"></label><button>Vorlegen</button></form>';
    }

    /** Gewählte Gruppen und Personen in Anmeldenamen auflösen. @return list<string> */
    public static function empfaenger(array $post, array $personen): array {
        $gruppen = array_map('strval', is_array($post['gruppen'] ?? null) ? $post['gruppen'] : []);
        $logins = array_map('strval', is_array($post['personen'] ?? null) ? $post['personen'] : []);
        foreach ($personen as $p) {
            if (in_array('*', $gruppen, true) || array_intersect($p['gruppen'], $gruppen)) $logins[] = $p['login'];
        }
        return array_values(array_unique($logins));
    }

    // ---- Dokument ---------------------------------------------------------------------------------------------------

    public static function dokument(array $dok, array $q, string $meldung, array $personen): string {
        $darfEntwurf = self::$r['schreiben'] || self::$r['vidieren'];
        $darfAlt = $darfEntwurf || self::$r['vorlegen'];
        $id = (int)$dok['id'];
        $gueltig = $dok['gueltig'] !== null ? Dokumente::version((int)$dok['gueltig']) : null;
        $entwurf = $dok['entwurf'] !== null && $darfEntwurf ? Dokumente::version((int)$dok['entwurf']) : null;
        $bearbeiten = self::$r['schreiben'] && !empty($q['bearbeiten']) && (int)$dok['archiviert'] === 0;
        // Welche Fassung zeigen?
        $v = null;
        if (isset($q['v']) && $darfAlt) {
            $v = Dokumente::version((int)$q['v']);
            if ($v && ((int)$v['dokument'] !== $id || (!$darfEntwurf && in_array($v['status'], ['entwurf', 'pruefung'], true)))) $v = null;
        }
        if (!$v && ($q['ansicht'] ?? '') === 'entwurf' && $entwurf) $v = $entwurf;
        if ($bearbeiten) $v = $entwurf ?? $gueltig;
        $v ??= $gueltig ?? $entwurf;
        $ordner = $dok['ordner'] !== null ? Dokumente::ordner((int)$dok['ordner']) : null;
        $pfad = '';
        foreach (Dokumente::ordnerBaum() as $b) if ($ordner && $b['id'] === (int)$ordner['id']) $pfad = $b['pfad'];
        $eyebrow = '<a href="' . qm_h(self::u('/handbuch')) . '">QM</a>' . ($ordner ? ' › <a href="' . qm_h(self::u('/handbuch', ['ordner' => $ordner['id']])) . '">' . qm_h($pfad) . '</a>' : '');
        $o = '<div class="qm">' . self::seitenkopf('dokument', $eyebrow, $dok['titel'], '<p class="qm-tags">' . self::tagsZeigen($dok) . ((int)$dok['archiviert'] === 1 ? self::tag('Archiviert', 'err') : '') . '</p>') . $meldung;
        if (!$v) return $o . '<p class="muted">Dieses Dokument ist noch nicht freigegeben.</p></div>';
        $o .= self::schritte($dok, $gueltig, $entwurf);

        // Umschalter zwischen gültiger Fassung und Entwurf
        if (!$bearbeiten && $gueltig && $entwurf) {
            $o .= '<nav class="qm-reiter" aria-label="Fassung"><a href="' . qm_h(self::u('/dokument/' . $id)) . '"' . ((int)$v['id'] === (int)$gueltig['id'] ? ' class="an" aria-current="page"' : '') . '>Gültige Fassung (Version ' . (int)$gueltig['nummer'] . ')</a>'
                . '<a href="' . qm_h(self::u('/dokument/' . $id, ['ansicht' => 'entwurf'])) . '"' . ((int)$v['id'] === (int)$entwurf['id'] ? ' class="an" aria-current="page"' : '') . '>' . ($entwurf['status'] === 'pruefung' ? 'Zur Prüfung' : 'Entwurf') . ' Version ' . (int)$entwurf['nummer'] . '</a></nav>';
        }
        $o .= '<div class="qm-zwei-schmal"><div>';
        if ($bearbeiten) {
            $o .= self::editor($dok, $v);
        } else {
            $o .= '<section class="card">';
            if (!in_array($v['status'], ['freigegeben'], true)) $o .= '<p class="qm-hinweisbalken">' . ($v['status'] === 'ersetzt' ? 'Ältere Fassung (Version ' . (int)$v['nummer'] . '), nicht mehr gültig.' : (Dokumente::STATUS[$v['status']] . ' (Version ' . (int)$v['nummer'] . ') – noch nicht gültig.')) . ($v['notiz'] !== '' ? ' ' . qm_h($v['notiz']) : '') . '</p>';
            $pflicht = $v['status'] === 'freigegeben' ? Dokumente::offeneLesepflicht($id, self::$login) : null;
            $inhalt = $v['inhalt'];
            $vorher = self::vorversion($id, (int)$v['nummer']);
            $zeigeAenderungen = $vorher && ($pflicht || !empty($q['aenderungen']));
            $hinweis = '';
            if ($zeigeAenderungen) {
                [$inhalt, $neu, $weg] = Html::aenderungenMarkieren($inhalt, $vorher['inhalt']);
                $hinweis = 'Gegenüber Version ' . (int)$vorher['nummer'] . ': ' . ($neu === 0 && $weg === 0 ? 'keine inhaltlichen Änderungen erkannt.' : $neu . ' Abschnitt(e) neu oder geändert (markiert)' . ($weg > 0 ? ', ' . $weg . ' entfallen' : '') . '.');
            }
            if ($pflicht) {
                $spaet = $pflicht['frist'] !== '' && $pflicht['frist'] < date('Y-m-d');
                $o .= '<form method="post" class="qm-lesen' . ($spaet ? ' spaet' : '') . '">' . self::csrfFeld() . '<input type="hidden" name="aktion" value="gelesen"><input type="hidden" name="version" value="' . (int)$v['id'] . '">'
                    . '<p><strong>Ihnen vorgelegt</strong> von ' . qm_h($pflicht['von']) . ($pflicht['frist'] !== '' ? ', bitte lesen bis ' . self::d($pflicht['frist']) : '') . '.' . ($hinweis !== '' ? ' ' . qm_h($hinweis) : '') . '</p>'
                    . '<label class="check"><input type="checkbox" required> Ich habe das Dokument gelesen und verstanden.</label><button>Als gelesen bestätigen</button></form>';
            } elseif ($hinweis !== '') {
                $o .= '<p class="muted">' . qm_h($hinweis) . '</p>';
            } elseif ($vorher && $v['status'] === 'freigegeben') {
                $o .= '<p class="qm-rechts"><a href="' . qm_h(self::u('/dokument/' . $id, ['aenderungen' => 1])) . '">Änderungen zu Version ' . (int)$vorher['nummer'] . ' markieren</a></p>';
            }
            $o .= '<article class="qm-text">' . ($inhalt !== '' ? $inhalt : '<p class="muted">Noch kein Inhalt.</p>') . '</article></section>';
        }
        $o .= '</div><div class="qm-seite">' . self::aktionen($dok, $v, $gueltig, $entwurf, $bearbeiten, $personen);
        if ($gueltig && (self::$r['vorlegen'] || self::$r['vidieren'])) $o .= self::lesestandKarte($gueltig);
        elseif ($gueltig && ($mein = array_values(array_filter(Dokumente::lesestand((int)$gueltig['id']), fn($l) => $l['login'] === self::$login && (int)$l['gelesen'] > 0)))) {
            $o .= '<section class="card"><p class="ok">✓ Von Ihnen gelesen am ' . self::d((int)$mein[0]['gelesen']) . '.</p></section>';
        }
        if ($darfAlt) $o .= self::versionenKarte($dok, (int)$v['id']) . self::verlaufKarte($id);
        return $o . '</div></div></div>';
    }

    /** Letzte freigegebene Version vor $nummer (für die Markierung der Änderungen). */
    private static function vorversion(int $dok, int $nummer): ?array {
        $st = Speicher::pdo()->prepare('SELECT * FROM versionen WHERE dokument = ? AND nummer < ? AND vidiert_am > 0 ORDER BY nummer DESC LIMIT 1');
        $st->execute([$dok, $nummer]);
        $r = $st->fetch();
        return is_array($r) ? $r : null;
    }

    private static function schritte(array $dok, ?array $gueltig, ?array $entwurf): string {
        $akt = $entwurf ?? $gueltig;
        if (!$akt) return '';
        $ls = $gueltig ? Dokumente::lesestand((int)$gueltig['id']) : [];
        $gelesen = count(array_filter($ls, fn($l) => (int)$l['gelesen'] > 0));
        $s = [];
        if ($entwurf) {
            $s[] = ['Entwurf · ' . $entwurf['autor_name'], $entwurf['status'] === 'entwurf' ? 'jetzt' : 'fertig'];
            $s[] = ['Zur Prüfung', $entwurf['status'] === 'pruefung' ? 'jetzt' : ''];
            $s[] = ['Vidieren', ''];
            $s[] = ['Vorlegen', ''];
        } else {
            $s[] = ['Entwurf · ' . $gueltig['autor_name'], 'fertig'];
            $s[] = ['Vidiert · ' . $gueltig['vidiert_kuerzel'] . ' ' . date('d.m.', (int)$gueltig['vidiert_am']), 'fertig'];
            $s[] = [$ls ? 'Vorgelegt an ' . count($ls) : 'Noch nicht vorgelegt', $ls ? ($gelesen === count($ls) ? 'fertig' : 'jetzt') : 'jetzt'];
            $s[] = [$ls ? ($gelesen === count($ls) ? 'Alle gelesen' : $gelesen . ' von ' . count($ls) . ' gelesen') : 'Gelesen', $ls && $gelesen === count($ls) ? 'fertig' : ''];
        }
        $s[] = ['Wiedervorlage ' . ($dok['naechste_pruefung'] !== '' ? date('m/Y', (int)strtotime($dok['naechste_pruefung'])) : '–'), Dokumente::zustand($dok + ['entwurf_status' => null]) === 'faellig' ? 'faellig' : ''];
        $o = '<ol class="qm-schritte">';
        foreach ($s as $i => [$t, $k]) $o .= '<li class="' . $k . '">' . ($i + 1) . ' ' . qm_h($t) . '</li>';
        return $o . '</ol>';
    }

    private static function editor(array $dok, array $v): string {
        $baum = Dokumente::ordnerBaum();
        $o = '<form method="post" class="card qm-editor-form" id="qm-editor-form">' . self::csrfFeld() . '<input type="hidden" name="aktion" value="speichern">'
            . ($v['status'] === 'freigegeben' ? '<p class="qm-hinweisbalken">Sie bearbeiten eine Kopie der gültigen Fassung. Beim Speichern entsteht ein neuer Entwurf; die gültige Fassung bleibt gültig, bis der Entwurf vidiert ist.</p>' : '')
            . ($v['status'] === 'pruefung' ? '<p class="qm-hinweisbalken">Dieser Entwurf liegt zur Prüfung. Nach dem Speichern muss er erneut eingereicht werden.</p>' : '')
            . '<div class="qm-felder"><label>Titel<input name="titel" maxlength="150" required value="' . qm_h($dok['titel']) . '"></label><label>Ordner' . self::ordnerWahl($baum, 'ordner', $dok['ordner'] === null ? null : (int)$dok['ordner'], 'ohne Ordner') . '</label>'
            . '<label>Tags <small>mit Komma getrennt</small><input name="tags" maxlength="400" value="' . qm_h(implode(', ', Dokumente::tags($dok))) . '" list="qm-tagliste"></label>'
            . '<label>Prüfen alle<select name="pruef_monate">';
        foreach ([12 => '12 Monate', 24 => '24 Monate', 36 => '36 Monate', 6 => '6 Monate', 0 => 'keine Wiedervorlage'] as $m => $t) $o .= '<option value="' . $m . '"' . ((int)$dok['pruef_monate'] === $m ? ' selected' : '') . '>' . $t . '</option>';
        $o .= '</select></label></div><datalist id="qm-tagliste">';
        foreach (array_keys(Dokumente::alleTags()) as $t) $o .= '<option value="' . qm_h($t) . '">';
        $o .= '</datalist><div class="qm-leiste" role="toolbar" aria-label="Format">'
            . '<button type="button" data-befehl="formatBlock" data-wert="p" title="Absatz">¶</button><button type="button" data-befehl="formatBlock" data-wert="h2" title="Überschrift">H1</button>'
            . '<button type="button" data-befehl="formatBlock" data-wert="h3" title="Zwischenüberschrift">H2</button><span class="qm-trenner"></span>'
            . '<button type="button" data-befehl="bold" title="Fett (Strg+B)"><strong>F</strong></button><button type="button" data-befehl="italic" title="Kursiv (Strg+I)"><em>K</em></button>'
            . '<button type="button" data-befehl="underline" title="Unterstrichen (Strg+U)"><u>U</u></button><button type="button" data-befehl="strikeThrough" title="Durchgestrichen"><s>S</s></button><span class="qm-trenner"></span>'
            . '<button type="button" data-befehl="insertUnorderedList" title="Aufzählung">• Liste</button><button type="button" data-befehl="insertOrderedList" title="Nummerierung">1. Liste</button>'
            . '<button type="button" data-befehl="checkliste" title="Checkliste"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1"/><path d="M14 7h6M4 17l2 2 4-4M14 17h6"/></svg>Checkliste</button><button type="button" data-befehl="tabelle" title="Tabelle einfügen"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="1"/><path d="M3 10h18M3 15h18M9 4v16M15 4v16"/></svg>Tabelle</button>'
            . '<button type="button" data-befehl="zeile" title="Tabellenzeile unter der aktuellen einfügen">+ Zeile</button><button type="button" data-befehl="spalte" title="Spalte rechts einfügen">+ Spalte</button>'
            . '<button type="button" data-befehl="bild" title="Bild einfügen (oder einfügen/ziehen)"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 17l-5-5-9 8"/></svg>Bild</button><button type="button" data-befehl="link" title="Link"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 006 0l3-3a4 4 0 00-6-6l-1 1"/><path d="M14 10a4 4 0 00-6 0l-3 3a4 4 0 006 6l1-1"/></svg>Link</button>'
            . '<button type="button" data-befehl="removeFormat" title="Formatierung entfernen">Format löschen</button><span class="qm-trenner"></span>'
            . '<button type="button" data-befehl="undo" title="Rückgängig (Strg+Z)">↶</button><button type="button" data-befehl="redo" title="Wiederholen">↷</button>'
            . '<input type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden id="qm-bilddatei"></div>'
            . '<div class="qm-text qm-editor" id="qm-editor" contenteditable="true" spellcheck="true" lang="de" aria-label="Inhalt des Dokuments">' . $v['inhalt'] . '</div>'
            . '<textarea name="inhalt" id="qm-inhalt" hidden>' . qm_h($v['inhalt']) . '</textarea>'
            . '<label>Was wurde geändert? <small>kurz, für den Verlauf</small><input name="notiz" maxlength="300" value="' . ($v['status'] === 'freigegeben' ? '' : qm_h(str_starts_with($v['notiz'], 'Zurückgewiesen: ') ? '' : $v['notiz'])) . '"></label>'
            . '<div class="actions"><button>Speichern</button><a class="button secondary" href="' . qm_h(self::u('/dokument/' . $dok['id'], ['ansicht' => $dok['entwurf'] !== null ? 'entwurf' : ''])) . '">Abbrechen</a>'
            . '<span class="muted" id="qm-status" role="status"></span></div></form>';
        return $o;
    }

    private static function aktionen(array $dok, array $v, ?array $gueltig, ?array $entwurf, bool $bearbeiten, array $personen): string {
        $id = (int)$dok['id'];
        $o = '<section class="card qm-aktionen"><h2>' . ($v['status'] === 'freigegeben' ? 'Version ' . (int)$v['nummer'] . ' · gültig' : (Dokumente::STATUS[$v['status']] ?? '') . ' · Version ' . (int)$v['nummer']) . '</h2>';
        if ($v['status'] === 'freigegeben' || $v['status'] === 'ersetzt') {
            $o .= '<p>Vidiert von ' . qm_h($v['vidiert_name']) . ' (' . qm_h($v['vidiert_kuerzel']) . ') am ' . self::d((int)$v['vidiert_am']) . '</p>';
        } else {
            $o .= '<p class="muted">Bearbeitet von ' . qm_h($v['autor_name']) . ', ' . self::d((int)$v['geaendert']) . '</p>';
        }
        if ($v['status'] === 'freigegeben' && $dok['naechste_pruefung'] !== '') $o .= '<p class="muted">Nächste Prüfung: ' . self::d($dok['naechste_pruefung']) . '</p>';
        if ($bearbeiten) return $o . '<p class="muted">Bilder lassen sich auch einfügen (Strg+V) oder in den Text ziehen.</p></section>';
        $o .= '<div class="actions">';
        if (in_array($v['status'], ['freigegeben', 'ersetzt'], true)) $o .= '<a class="button secondary" href="' . qm_h(self::u('/druck/' . $id, ['v' => $v['status'] === 'ersetzt' ? $v['id'] : ''])) . '" target="_blank" rel="noopener">Drucken / PDF</a>';
        if (self::$r['schreiben'] && (int)$dok['archiviert'] === 0 && in_array($v['status'], ['freigegeben', 'entwurf', 'pruefung'], true)) {
            $o .= '<a class="button" href="' . qm_h(self::u('/dokument/' . $id, ['bearbeiten' => 1])) . '">' . ($entwurf ? 'Entwurf bearbeiten' : 'Bearbeiten') . '</a>';
        }
        $o .= '</div>';
        if ($entwurf && (int)$v['id'] === (int)$entwurf['id']) {
            if (self::$r['schreiben'] && $entwurf['status'] === 'entwurf') $o .= self::knopf('einreichen', [], 'Zur Prüfung einreichen', 'secondary');
            if (self::$r['vidieren']) {
                $o .= '<form method="post" class="qm-vidieren">' . self::csrfFeld() . '<input type="hidden" name="aktion" value="vidieren"><label>Ihr Kürzel<input name="kuerzel" maxlength="6" required value="' . qm_h(Dokumente::kuerzel(self::$name)) . '"></label>';
                if ($gueltig && Dokumente::lesestand((int)$gueltig['id'])) $o .= '<label class="check"><input type="checkbox" name="erneut" value="1" checked> Allen erneut vorlegen, denen Version ' . (int)$gueltig['nummer'] . ' vorgelegt war</label>';
                $o .= '<button data-frage="Version ' . (int)$entwurf['nummer'] . ' vidieren? Sie gilt dann sofort' . ($gueltig ? ' und ersetzt Version ' . (int)$gueltig['nummer'] : '') . '.">Vidieren und freigeben</button></form>';
                if ($entwurf['status'] === 'pruefung') {
                    $o .= '<details class="fold"><summary>Zurückweisen</summary><form method="post">' . self::csrfFeld() . '<input type="hidden" name="aktion" value="zurueckweisen"><label>Was fehlt oder ist falsch?<input name="grund" maxlength="300" required></label><button class="secondary">Zurückweisen</button></form></details>';
                }
            }
            if (self::$r['schreiben']) $o .= self::knopf('verwerfen', [], 'Entwurf verwerfen', 'danger', $gueltig ? 'Diesen Entwurf verwerfen? Die gültige Fassung bleibt.' : 'Diesen Entwurf verwerfen? Das Dokument wird gelöscht, weil es noch keine gültige Fassung hat.');
        }
        if ($gueltig && (int)$v['id'] === (int)$gueltig['id'] && (int)$dok['archiviert'] === 0) {
            if (self::$r['vorlegen']) $o .= '<details class="fold"><summary><strong>Zur Kenntnis vorlegen</strong></summary>' . self::vorlegenFormular([$id], $personen) . '</details>';
            if (self::$r['vidieren']) $o .= self::knopf('geprueft', [], 'Geprüft, bleibt unverändert gültig', 'secondary', 'Bestätigen, dass das Dokument geprüft wurde und unverändert gilt? Die nächste Prüfung wird neu berechnet.');
        }
        if (self::$r['vidieren']) $o .= (int)$dok['archiviert'] === 1 ? self::knopf('wiederherstellen', [], 'Aus dem Archiv holen', 'secondary')
            : ($gueltig ? self::knopf('archivieren', [], 'Archivieren', 'danger', 'Dokument archivieren? Es gilt dann nicht mehr und verschwindet aus dem Handbuch; Versionen und Lesebestätigungen bleiben erhalten.') : '');
        return $o . '</section>';
    }

    private static function lesestandKarte(array $gueltig): string {
        $ls = Dokumente::lesestand((int)$gueltig['id']);
        $o = '<section class="card"><h2>Lesestand Version ' . (int)$gueltig['nummer'] . '</h2>';
        if (!$ls) return $o . '<p class="muted">Noch niemandem vorgelegt.</p></section>';
        $heute = date('Y-m-d');
        $o .= '<table class="qm-klein-tabelle">';
        foreach ($ls as $l) {
            $tag = (int)$l['gelesen'] > 0 ? self::tag('gelesen ' . date('d.m.', (int)$l['gelesen']), 'ok') : ($l['frist'] !== '' && $l['frist'] < $heute ? self::tag('überfällig', 'err') : self::tag('offen' . ($l['frist'] !== '' ? ' bis ' . date('d.m.', (int)strtotime($l['frist'])) : ''), 'warn'));
            $o .= '<tr><td>' . qm_h($l['name']) . '</td><td>' . $tag . '</td></tr>';
        }
        return $o . '</table></section>';
    }

    private static function versionenKarte(array $dok, int $aktuell): string {
        $o = '<section class="card"><h2>Versionen</h2><ul class="qm-versionen">';
        foreach (Dokumente::versionen((int)$dok['id']) as $v) {
            $text = 'Version ' . (int)$v['nummer'] . ' · ' . (Dokumente::STATUS[$v['status']] ?? $v['status']) . ' · ' . ((int)$v['vidiert_am'] > 0 ? self::d((int)$v['vidiert_am']) . ' ' . $v['vidiert_kuerzel'] : self::d((int)$v['geaendert']));
            $o .= '<li>' . ((int)$v['id'] === $aktuell ? '<strong>' . qm_h($text) . '</strong>' : '<a href="' . qm_h(self::u('/dokument/' . $dok['id'], ['v' => $v['id']])) . '">' . qm_h($text) . '</a>')
                . ($v['notiz'] !== '' ? '<br><small class="muted">' . qm_h($v['notiz']) . '</small>' : '') . '</li>';
        }
        return $o . '</ul></section>';
    }

    private static function verlaufKarte(int $id): string {
        $o = '<section class="card"><details class="fold"><summary><h2>Verlauf</h2></summary><ul class="qm-versionen">';
        foreach (Dokumente::verlauf($id) as $e) $o .= '<li><small class="muted">' . date('d.m.Y H:i', (int)$e['zeit']) . ' · ' . qm_h($e['wer']) . '</small><br>' . qm_h($e['was']) . '</li>';
        return $o . '</ul></details></section>';
    }

    // ---- Neu, Import ------------------------------------------------------------------------------------------------

    public static function neu(int $ordner, string $meldung): string {
        $baum = Dokumente::ordnerBaum();
        $o = '<div class="qm">' . self::seitenkopf('neu', 'Qualitätsmanagement', 'Neues Dokument') . $meldung . '<form method="post" class="card qm-neu">' . self::csrfFeld() . '<input type="hidden" name="aktion" value="anlegen">'
            . '<div class="qm-felder"><label>Titel<input name="titel" maxlength="150" placeholder="z. B. Blutentnahme und Probenversand" data-geruest-titel></label><label>Ordner' . self::ordnerWahl($baum, 'ordner', $ordner ?: null, 'ohne Ordner') . '</label>'
            . '<label>Tags <small>mit Komma getrennt</small><input name="tags" maxlength="400" placeholder="z. B. Labor, Einarbeitung"></label><label>Prüfen alle<select name="pruef_monate"><option value="12">12 Monate</option><option value="24">24 Monate</option><option value="36">36 Monate</option><option value="6">6 Monate</option><option value="0">keine Wiedervorlage</option></select></label></div>'
            . '<fieldset><legend>Beginnen mit</legend><label class="check"><input type="radio" name="geruest" value="" checked> Leeres Dokument</label>';
        $namen = array_column($baum, 'id', 'name');
        foreach (Gerueste::alle() as $k => $g) $o .= '<label class="check"><input type="radio" name="geruest" value="' . qm_h($k) . '" data-titel="' . qm_h($g['titel']) . '" data-ordner="' . (int)($namen[$g['ordner']] ?? 0) . '" data-tags="' . qm_h($g['tags']) . '"> Gerüst: ' . qm_h($g['titel']) . '</label>';
        return $o . '</fieldset><p class="muted">Die Gerüste sind eigene, frei verwendbare Gliederungen mit Lücken in [eckigen Klammern]. Inhalte legt die Praxis fest; gültig wird ein Dokument erst durch das Vidieren.</p><button>Anlegen und bearbeiten</button></form></div>';
    }

    public static function import(string $meldung): string {
        $baum = Dokumente::ordnerBaum();
        $o = '<div class="qm">' . self::seitenkopf('import', 'Qualitätsmanagement', 'Musterdokumente übernehmen') . $meldung
            . '<div class="qm-zwei"><section class="card"><h2>Word-Dateien übernehmen (z. B. QEP)</h2>'
            . '<p>Die Musterdokumente aus QEP („Qualität und Entwicklung in Praxen“ der KBV) und andere Word-Dateien (.docx) werden als <strong>Entwürfe</strong> angelegt: anpassen, dann vidieren. '
            . 'Überschriften, Listen, Tabellen, Fettdruck, Links und Bilder werden übernommen; Kopf- und Fußzeilen nicht.</p>'
            . '<ol class="steps"><li>Musterdokumente herunterladen (bei QEP im Bereich „OnlinePlus“) und das ZIP entpacken.</li><li>Unten den entpackten <strong>Ordner</strong> wählen (die Unterordner werden zu QM-Ordnern) oder einzelne Dateien bzw. ein ZIP bis 7,5 MB.</li><li>„Übernehmen“ klicken.</li></ol>'
            . '<form id="qm-import" class="qm-import"><div class="qm-felder"><label>Ablegen in' . self::ordnerWahl($baum, 'ordner', null, 'oberste Ebene') . '</label><label>Tags für alle <small>mit Komma getrennt</small><input name="tags" value="QEP" maxlength="200"></label></div>'
            . '<label class="check"><input type="checkbox" name="struktur" value="1" checked> Ordnerstruktur übernehmen</label>'
            . '<label>Ganzen Ordner wählen<input type="file" name="ordnerwahl" webkitdirectory multiple></label><label>oder einzelne Dateien (.docx, .zip)<input type="file" name="dateien" multiple accept=".docx,.zip,application/zip,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></label>'
            . '<button id="qm-import-los">Übernehmen</button> <span class="muted" id="qm-import-stand" role="status"></span></form><ul class="qm-ergebnis" id="qm-import-ergebnis"></ul></section>'
            . '<section class="card"><h2>Woher bekomme ich Muster?</h2><ul class="qm-quellen">';
        foreach (Gerueste::QUELLEN as $q) $o .= '<li><a href="' . qm_h($q['url']) . '" target="_blank" rel="noopener noreferrer">' . qm_h($q['titel']) . '</a><br><small class="muted">' . qm_h($q['hinweis']) . '</small></li>';
        return $o . '</ul><p class="muted">Frei verwendbare Vorlagen unter offener Lizenz gibt es für Arztpraxen kaum. Die Muster der KBV und der KVen dürfen Praxen für sich nutzen und anpassen; '
            . 'das Modul liefert sie deshalb nicht mit, sondern übernimmt die Dateien, die die Praxis selbst herunterlädt. Eigene Gerüste liegen unter „Neues Dokument“.</p>'
            . '<p class="muted">Bitte keine Patientendaten in QM-Dokumente schreiben.</p></section></div></div>';
    }

    // ---- Druck ------------------------------------------------------------------------------------------------------

    public static function druck(array $dok, array $v, array $praxis): string {
        $ordner = $dok['ordner'] !== null ? Dokumente::ordner((int)$dok['ordner']) : null;
        $base = qm_basis();
        $css = '?v=' . substr((string)@md5_file(dirname(__DIR__) . '/public/assets/druck.css'), 0, 8);
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . qm_h($dok['titel']) . '</title>'
            . '<link rel="stylesheet" href="' . qm_h($base) . '/assets/druck.css' . $css . '"><script src="' . qm_h($base) . '/assets/qm.js" defer></script></head><body class="qm-druck">'
            . '<p class="nicht-drucken"><button type="button" data-drucken>Drucken oder als PDF speichern</button></p>'
            . '<table class="kopf"><tr><td rowspan="2" class="praxis">' . qm_h((string)($praxis['name'] ?? '')) . '<br><small>QM-Handbuch' . ($ordner ? ' · ' . qm_h($ordner['name']) : '') . '</small></td>'
            . '<td>Version ' . (int)$v['nummer'] . '</td><td>Freigabe: ' . qm_h($v['vidiert_kuerzel']) . ' am ' . self::d((int)$v['vidiert_am']) . '</td></tr>'
            . '<tr><td>' . ($v['status'] === 'freigegeben' ? 'gültig' : 'ersetzt, nicht mehr gültig') . '</td><td>' . ($dok['naechste_pruefung'] !== '' && $v['status'] === 'freigegeben' ? 'Nächste Prüfung: ' . self::d($dok['naechste_pruefung']) : '') . '</td></tr></table>'
            . '<h1>' . qm_h($dok['titel']) . '</h1><article class="qm-text">' . $v['inhalt'] . '</article>'
            . '<p class="fuss">Ausdruck vom ' . date('d.m.Y') . '. Gültig ist nur die aktuelle Fassung im QM-Handbuch.</p></body></html>';
    }
}
