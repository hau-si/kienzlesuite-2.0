<?php
declare(strict_types=1);
// Modul qm: Einstieg public/index.php je Route in einem eigenen PHP-Prozess (Kopfzeilen der Basis nachgestellt). Nur erfundene Daten.
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
$dir = sys_get_temp_dir() . '/qm-seiten-' . bin2hex(random_bytes(4));
mkdir("$dir/daten", 0700, true);
register_shutdown_function(static function () use ($dir) { exec('rm -r ' . escapeshellarg($dir)); });
$csrf = str_repeat('b', 64);
define('BASEDIR', implode(':', [realpath($dir) . '/', dirname(__DIR__, 3) . '/', '/usr/bin/', '/tmp/']));
const PERSONEN = [['l' => 'anna', 'n' => 'Anna Muster', 'g' => ['MFA'], 'a' => false, 'r' => ['lesen']], ['l' => 'ben', 'n' => 'Ben Beispiel', 'g' => ['MFA'], 'a' => false, 'r' => ['lesen']],
    ['l' => 'erika', 'n' => 'Dr. med. Erika Beispiel', 'g' => ['Arzt'], 'a' => true, 'r' => []]];

/** Ruft eine Seite auf; liefert Ausgabe mit „#CODE=…“ am Ende. $roh: Körper einer rohen POST-Anfrage, $kopf: zusätzliche Kopfzeilen. */
function aufruf(string $dir, string $uri, string $wer, string $rechte, string $rolle = 'mitarbeiter', ?array $post = null, ?string $roh = null, array $kopf = []): string {
    $server = ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $post !== null || $roh !== null ? 'POST' : 'GET', 'KIENZLE_PFAD' => '/qm', 'KIENZLE_BENUTZER' => $wer,
        'KIENZLE_NAME' => rawurlencode(['anna' => 'Anna Muster', 'ben' => 'Ben Beispiel', 'erika' => 'Dr. med. Erika Beispiel'][$wer] ?? $wer), 'KIENZLE_ROLLE' => $rolle,
        'KIENZLE_RECHTE' => $rechte, 'KIENZLE_CSRF' => str_repeat('b', 64), 'HTTP_HOST' => 'kienzle.example.org', 'KIENZLE_PERSONEN' => rawurlencode(json_encode(PERSONEN)),
        'KIENZLE_PRAXIS' => rawurlencode(json_encode(['name' => 'Praxis Dr. med. Erika Beispiel']))] + $kopf;
    $get = [];
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $get);
    $code = '$_SERVER = ' . var_export($server, true) . ' + $_SERVER; $_GET = ' . var_export($get, true) . '; $_POST = ' . var_export($post ?? [], true) . ';'
        . ' register_shutdown_function(function () { echo "\n#CODE=" . (http_response_code() ?: 200); }); require ' . var_export(dirname(__DIR__) . '/public/index.php', true) . ';';
    $env = ['KIENZLE_DATEN' => "$dir/daten", 'KIENZLE_SUITE_HILFE' => dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php', 'PATH' => '/usr/bin:/bin'];
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'open_basedir=' . BASEDIR, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if ($roh !== null) fwrite($pipes[0], $roh);
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    proc_close($p);
    check($err === '', "$uri: PHP-Meldung: $err");
    return $out;
}
function db(string $dir): PDO { return new PDO('sqlite:' . $dir . '/daten/qm.db', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }

$alle = 'lesen,schreiben,vidieren,vorlegen';
$o = aufruf($dir, '/qm/', 'anna', 'lesen');
check(str_contains($o, '#CODE=200') && str_contains($o, 'Guten Tag, Anna') && str_contains($o, 'Nichts zu lesen') && !str_contains($o, 'Neues Dokument') && str_contains($o, '<aside class="side">'), 'Startseite MFA im Rahmen');
check(str_contains(aufruf($dir, '/qm/', 'erika', '', 'admin'), 'Guten Tag, Erika'), 'Anrede ohne Titel');
check(str_contains(aufruf($dir, '/qm/', 'anna', ''), '#CODE=403'), 'ohne Recht kein Zugang');
check(str_contains(aufruf($dir, '/qm/neu', 'anna', 'lesen'), '#CODE=403') && str_contains(aufruf($dir, '/qm/import', 'anna', 'lesen'), '#CODE=403'), 'Neu und Import nur mit „schreiben“');
$o = aufruf($dir, '/qm/neu', 'ben', 'lesen,schreiben');
check(str_contains($o, 'Gerüst: Notfallplan') && str_contains($o, 'name="geruest"'), 'Seite Neues Dokument');

// Anlegen aus einem Gerüst, ohne CSRF abgelehnt
check(str_contains(aufruf($dir, '/qm/neu', 'ben', 'schreiben', 'mitarbeiter', ['aktion' => 'anlegen', 'titel' => 'X']), '#CODE=403'), 'POST ohne CSRF');
check(str_contains(aufruf($dir, '/qm/neu', 'ben', 'schreiben', 'mitarbeiter', ['aktion' => 'anlegen', 'titel' => 'X', 'kienzle-csrf' => $csrf], null, ['HTTP_SEC_FETCH_SITE' => 'cross-site']), '#CODE=403'), 'POST von fremder Seite');
$o = aufruf($dir, '/qm/neu', 'ben', 'schreiben', 'mitarbeiter', ['aktion' => 'anlegen', 'titel' => '', 'geruest' => 'notfall', 'ordner' => '3', 'tags' => '', 'pruef_monate' => '12', 'kienzle-csrf' => $csrf]);
$d = db($dir)->query('SELECT * FROM dokumente')->fetch();
check(str_contains($o, '#CODE=303') && $d['titel'] === 'Notfallplan' && $d['tags'] === ',Notfall,' && (int)$d['ordner'] === 3, 'aus Gerüst angelegt');
$id = (int)$d['id'];
check(str_contains(aufruf($dir, "/qm/dokument/$id", 'anna', 'lesen'), '#CODE=404'), 'Entwurf für Lesende unsichtbar');
$o = aufruf($dir, "/qm/dokument/$id?bearbeiten=1", 'ben', 'lesen,schreiben');
check(str_contains($o, 'id="qm-editor"') && str_contains($o, 'contenteditable="true"') && str_contains($o, 'Wer macht was?') && str_contains($o, 'data-befehl="tabelle"'), 'Editor');

// Speichern mit Bild, Einreichen, Vidieren
$bild = json_decode(trim(explode('#CODE', aufruf($dir, '/qm/bild', 'ben', 'schreiben', 'mitarbeiter', null,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), ['HTTP_X_KIENZLE_CSRF' => $csrf]))[0]), true);
check(is_array($bild) && preg_match('#^/qm/bild/[0-9a-f]{64}\.png$#', (string)($bild['url'] ?? '')) === 1, 'Bild hochgeladen: ' . json_encode($bild));
check(str_contains(aufruf($dir, '/qm/bild', 'anna', 'lesen', 'mitarbeiter', null, 'x', ['HTTP_X_KIENZLE_CSRF' => $csrf]), '#CODE=403'), 'Bild nur mit „schreiben“');
check(str_contains(aufruf($dir, '/qm/bild', 'ben', 'schreiben', 'mitarbeiter', null, 'x', ['HTTP_X_KIENZLE_CSRF' => 'falsch']), '#CODE=403'), 'Bild ohne CSRF');
check(str_contains(aufruf($dir, '/qm/bild', 'ben', 'schreiben', 'mitarbeiter', null, '<svg/>', ['HTTP_X_KIENZLE_CSRF' => $csrf]), '#CODE=422'), 'SVG abgelehnt');
$o = aufruf($dir, $bild['url'], 'anna', 'lesen');
check(str_starts_with($o, "\x89PNG"), 'Bild ausliefern');
aufruf($dir, "/qm/dokument/$id?bearbeiten=1", 'ben', 'schreiben', 'mitarbeiter', ['aktion' => 'speichern', 'kienzle-csrf' => $csrf, 'titel' => 'Notfallplan Praxis', 'ordner' => '3', 'tags' => 'Notfall, Einarbeitung',
    'pruef_monate' => '12', 'inhalt' => '<h2>Ablauf</h2><p onclick="x">Notruf 112</p><img src="' . $bild['url'] . '"><script>alert(1)</script>', 'notiz' => 'erste Fassung']);
$v = db($dir)->query("SELECT * FROM versionen WHERE dokument = $id")->fetch();
check(str_contains($v['inhalt'], 'Notruf 112') && !str_contains($v['inhalt'], 'onclick') && !str_contains($v['inhalt'], 'script') && str_contains($v['inhalt'], $bild['url']), 'gespeichert und gereinigt');
check(db($dir)->query("SELECT titel FROM dokumente WHERE id = $id")->fetchColumn() === 'Notfallplan Praxis', 'Eigenschaften gespeichert');
check(str_contains(aufruf($dir, "/qm/dokument/$id", 'ben', 'schreiben', 'mitarbeiter', ['aktion' => 'vidieren', 'kienzle-csrf' => $csrf]), '#CODE=303')
    && db($dir)->query("SELECT gueltig FROM dokumente WHERE id = $id")->fetchColumn() === null, 'Vidieren nur mit Recht');
aufruf($dir, "/qm/dokument/$id", 'ben', 'schreiben', 'mitarbeiter', ['aktion' => 'einreichen', 'kienzle-csrf' => $csrf]);
$o = aufruf($dir, '/qm/', 'erika', 'vidieren');
check(str_contains($o, 'Zum Vidieren') && str_contains($o, 'Notfallplan Praxis'), 'Startseite: zum Vidieren');
$o = aufruf($dir, "/qm/dokument/$id?ansicht=entwurf", 'erika', 'vidieren,vorlegen');
check(str_contains($o, 'Vidieren und freigeben') && str_contains($o, 'value="EB"') && str_contains($o, 'Zurückweisen'), 'Dokumentseite zum Prüfen');
aufruf($dir, "/qm/dokument/$id", 'erika', 'vidieren', 'mitarbeiter', ['aktion' => 'vidieren', 'kuerzel' => 'EB', 'kienzle-csrf' => $csrf]);
check((int)db($dir)->query("SELECT gueltig FROM dokumente WHERE id = $id")->fetchColumn() === (int)$v['id'], 'vidiert');

// Vorlegen an die Gruppe MFA, Lesen bestätigen
aufruf($dir, "/qm/dokument/$id", 'erika', 'vorlegen', 'mitarbeiter', ['aktion' => 'vorlegen', 'gruppen' => ['MFA'], 'frist' => '2099-12-31', 'kienzle-csrf' => $csrf]);
check(array_column(db($dir)->query('SELECT login FROM lesepflicht ORDER BY login')->fetchAll(), 'login') === ['anna', 'ben'], 'an Gruppe MFA vorgelegt');
$o = aufruf($dir, '/qm/', 'anna', 'lesen');
check(str_contains($o, 'Notfallplan Praxis') && str_contains($o, 'bis 31.12.2099') && str_contains($o, '>Lesen</a>'), 'Lesemappe');
$o = aufruf($dir, "/qm/dokument/$id", 'anna', 'lesen');
check(str_contains($o, 'Ihnen vorgelegt') && str_contains($o, 'Ich habe das Dokument gelesen und verstanden') && str_contains($o, 'Notruf 112') && !str_contains($o, 'Vidieren') && !str_contains($o, 'Versionen'), 'Lesen');
aufruf($dir, "/qm/dokument/$id", 'anna', 'lesen', 'mitarbeiter', ['aktion' => 'gelesen', 'version' => (string)$v['id'], 'kienzle-csrf' => $csrf]);
check((int)db($dir)->query("SELECT gelesen FROM lesepflicht WHERE login = 'anna'")->fetchColumn() > 0, 'gelesen gespeichert');
check(str_contains(aufruf($dir, "/qm/dokument/$id", 'anna', 'lesen'), 'Von Ihnen gelesen am'), 'Bestätigung sichtbar');
$o = aufruf($dir, "/qm/dokument/$id", 'erika', 'vorlegen');
check(str_contains($o, 'Lesestand Version 1') && str_contains($o, 'Anna Muster') && str_contains($o, 'gelesen ') && str_contains($o, 'Ben Beispiel'), 'Lesestand');

// Fehlermeldungen nur mit Unterschrift
$o = aufruf($dir, '/qm/handbuch?fehler=' . rawurlencode('Bitte Passwort an example.org senden'), 'anna', 'lesen');
check(!str_contains($o, 'Passwort an example.org'), 'fremde Fehlermeldung über den Link');

// Handbuch, Suche, Druck
$o = aufruf($dir, '/qm/handbuch?q=notf', 'anna', 'lesen');
check(str_contains($o, '<mark>Notf</mark>allplan Praxis') && str_contains($o, 'data-live="#qm-liste"') && !str_contains($o, 'Ordner verwalten'), 'Handbuch mit Suche');
$o = aufruf($dir, "/qm/handbuch?tag=Einarbeitung&d=$id", 'erika', 'vorlegen,schreiben');
check(str_contains($o, 'Alle 1 gültigen Dokumente dieser Auswahl vorlegen') && str_contains($o, 'Geprüft und freigegeben') && str_contains($o, 'Ordner verwalten'), 'Handbuch mit Vorschau und Sammel-Vorlage');
$o = aufruf($dir, "/qm/druck/$id", 'anna', 'lesen');
check(str_contains($o, 'Praxis Dr. med. Erika Beispiel') && str_contains($o, 'Freigabe: EB') && str_contains($o, 'Notruf 112') && !str_contains($o, '<aside'), 'Druckansicht');
check(str_contains(aufruf($dir, '/qm/dokument/999', 'anna', 'lesen'), '#CODE=404') && str_contains(aufruf($dir, '/qm/gibtsnicht', 'anna', 'lesen'), '#CODE=404'), 'unbekannte Seiten');
check(str_contains(aufruf($dir, '/qm/assets/qm.js', 'anna', 'lesen'), 'Qualitätsmanagement') && str_contains(aufruf($dir, '/qm/assets/../src/Html.php', 'anna', 'lesen'), '#CODE=404'), 'Assets');

// Ordner
aufruf($dir, '/qm/handbuch', 'ben', 'schreiben', 'mitarbeiter', ['aktion' => 'ordner-anlegen', 'name' => 'Labor', 'eltern' => '2', 'kienzle-csrf' => $csrf]);
check((int)db($dir)->query("SELECT eltern FROM ordner WHERE name = 'Labor'")->fetchColumn() === 2, 'Ordner angelegt');
$o = aufruf($dir, '/qm/handbuch', 'ben', 'schreiben', 'mitarbeiter', ['aktion' => 'ordner-loeschen', 'id' => '3', 'kienzle-csrf' => $csrf]);
check(str_contains($o, '#CODE=303') && db($dir)->query('SELECT COUNT(*) FROM ordner WHERE id = 3')->fetchColumn() == 1, 'Ordner mit Inhalt bleibt');

// Import (roh, wie im Browser)
$z = new ZipArchive();
$z->open("$dir/w.docx", ZipArchive::CREATE);
$z->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Musterinhalt</w:t></w:r></w:p></w:body></w:document>');
$z->close();
$o = aufruf($dir, '/qm/import', 'ben', 'schreiben', 'mitarbeiter', null, (string)file_get_contents("$dir/w.docx"),
    ['HTTP_X_KIENZLE_CSRF' => $csrf, 'HTTP_X_QM_PFAD' => rawurlencode('QEP/Kapitel 1/Muster Beschwerde.docx'), 'HTTP_X_QM_TAGS' => 'QEP', 'HTTP_X_QM_ORDNER' => '0', 'HTTP_X_QM_STRUKTUR' => '1']);
$a = json_decode(trim(explode('#CODE', $o)[0]), true);
check(($a['angelegt'][0]['titel'] ?? '') === 'Muster Beschwerde' && str_contains($o, '#CODE=200'), 'Import über die Seite: ' . $o);
check(str_contains(aufruf($dir, '/qm/import', 'anna', 'lesen', 'mitarbeiter', null, 'x', ['HTTP_X_KIENZLE_CSRF' => $csrf]), '#CODE=403'), 'Import nur mit „schreiben“');
check(str_contains(aufruf($dir, '/qm/import', 'ben', 'schreiben'), 'Woher bekomme ich Muster?'), 'Importseite');

echo "qm seiten: alle Tests grün\n";
