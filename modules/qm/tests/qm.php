<?php
declare(strict_types=1);
// Modul qm: HTML-Reinigung, Word-Import, Ordner, Ablauf Entwurf → Prüfung → Vidieren → Vorlegen → Gelesen, Suche, Wiedervorlage. Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleQm\{Docx, Dokumente, Gerueste, Html, Import, Speicher};

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
function wirft(callable $f, string $msg): void { try { $f(); } catch (InvalidArgumentException) { return; } fwrite(STDERR, "FEHLER: $msg\n"); exit(1); }

$dir = sys_get_temp_dir() . '/qm-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
Speicher::$data = $dir;
register_shutdown_function(static function () use ($dir) { Speicher::zuruecksetzen(); exec('rm -r ' . escapeshellarg($dir)); });
$_SERVER['KIENZLE_PFAD'] = '/qm';
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
$anna = ['login' => 'anna', 'name' => 'Anna Muster'];
$arzt = ['login' => 'erika', 'name' => 'Dr. med. Erika Beispiel'];
$personen = [['login' => 'anna', 'name' => 'Anna Muster', 'gruppen' => ['MFA'], 'admin' => false, 'rechte' => ['lesen']],
    ['login' => 'ben', 'name' => 'Ben Beispiel', 'gruppen' => ['MFA'], 'admin' => false, 'rechte' => ['lesen']],
    ['login' => 'erika', 'name' => 'Dr. med. Erika Beispiel', 'gruppen' => ['Arzt'], 'admin' => true, 'rechte' => []]];

// ---- HTML-Reinigung ----
$h = Html::reinigen('<script>alert(1)</script><h1 onclick="x()">Titel</h1><p style="color:red">A <b>fett</b> <span>und</span> <i>kursiv</i> <a href="javascript:alert(1)">böse</a> <a href="https://example.org/x">gut</a></p>'
    . '<img src="https://example.org/x.png"><img src="/qm/bild/' . str_repeat('a', 64) . '.png" onerror="x()"><table><tr><td colspan="2" style="x">Z</td></tr></table>lose<iframe src="x"></iframe><ul class="checkliste evil"><li class="erledigt x">P</li></ul>');
check(!str_contains($h, 'script') && !str_contains($h, 'onclick') && !str_contains($h, 'style') && !str_contains($h, 'javascript') && !str_contains($h, 'iframe') && !str_contains($h, 'onerror'), 'gefährliches HTML entfernt: ' . $h);
check(str_contains($h, '<h2>Titel</h2>') && str_contains($h, '<strong>fett</strong>') && str_contains($h, '<em>kursiv</em>') && str_contains($h, 'und') && !str_contains($h, '<span'), 'Auszeichnungen übersetzt: ' . $h);
check(str_contains($h, '<a href="https://example.org/x" rel="noopener noreferrer" target="_blank">gut</a>') && str_contains($h, ' böse ') && !str_contains($h, 'example.org/x.png'), 'Links und fremde Bilder: ' . $h);
check(str_contains($h, '<img src="/qm/bild/' . str_repeat('a', 64) . '.png" alt="">') && str_contains($h, '<td colspan="2">Z</td>') && str_contains($h, '<p>lose</p>'), 'eigene Bilder, Tabellen, lose Texte: ' . $h);
check(str_contains($h, '<ul class="checkliste"><li class="erledigt">P</li></ul>'), 'Checkliste: ' . $h);
check(Html::reinigen(Html::reinigen($h)) === Html::reinigen($h), 'Reinigung ist stabil');
check(Html::reinigen('<div><p>A</p><p>B</p></div><div>C</div>') === '<p>A</p><p>B</p><p>C</p>', 'div aufgelöst: ' . Html::reinigen('<div><p>A</p><p>B</p></div><div>C</div>'));
check(Html::text('<h2>Ä&amp;Ö</h2><p>x <strong>y</strong></p>') === "Ä&Ö\nx y", 'Text für die Suche');
wirft(fn() => Html::reinigen(str_repeat('a', Html::MAX + 1)), 'zu großer Inhalt');
[$m, $neu, $weg] = Html::aenderungenMarkieren('<h2>Ziel</h2><p>Neu dazu</p><p>Bleibt</p>', '<h2>Ziel</h2><p>Bleibt</p><p>Fällt weg</p>');
check($neu === 1 && $weg === 1 && str_contains($m, '<p class="neu">Neu dazu</p>') && str_contains($m, '<p>Bleibt</p>'), 'Änderungen markiert: ' . $m);

// ---- Ordner ----
$baum = Dokumente::ordnerBaum();
check(count($baum) === count(Speicher::ORDNER) && $baum[0]['name'] === Speicher::ORDNER[0], 'Ordner beim ersten Start');
$hyg = $baum[1]['id'];
$sub = Dokumente::ordnerAnlegen('Labor', $hyg);
wirft(fn() => Dokumente::ordnerAnlegen('labor', $hyg), 'doppelter Ordnername');
wirft(fn() => Dokumente::ordnerAnlegen('a/b', null), 'Schrägstrich im Ordnernamen');
wirft(fn() => Dokumente::ordnerVerschieben($hyg, $sub), 'Ordner in sich selbst');
check(Dokumente::ordnerMitUnterordnern($hyg) === [$hyg, $sub], 'Unterordner');
check(Dokumente::ordnerPfad($hyg, ['Labor', 'Proben']) !== $sub && Dokumente::ordnerPfad($hyg, ['labor']) === $sub, 'Ordnerpfad findet vorhandene Ordner');

// ---- Ablauf ----
check(Dokumente::kuerzel('Dr. med. Erika Beispiel') === 'EB' && Dokumente::kuerzel('anna') === 'A', 'Kürzel aus dem Namen');
$t0 = 1767225600; // 01.01.2026
$id = Dokumente::anlegen(['titel' => 'Händedesinfektion', 'ordner' => $hyg, 'tags' => '#Hygiene, IfSG, hygiene', 'inhalt' => '<p>Vor jedem Patientenkontakt.</p>'], $anna, '', $t0);
$d = Dokumente::dokument($id);
check($d['tags'] === ',Hygiene,IfSG,' && $d['gueltig'] === null && $d['entwurf'] !== null && Dokumente::version((int)$d['entwurf'])['status'] === 'entwurf', 'Dokument als Entwurf angelegt');
wirft(fn() => Dokumente::anlegen(['titel' => ''], $anna), 'Titel fehlt');
wirft(fn() => Dokumente::anlegen(['titel' => 'X', 'tags' => implode(',', range(1, 13))], $anna), 'zu viele Tags');
wirft(fn() => Dokumente::anlegen(['titel' => 'X', 'pruef_monate' => 7], $anna), 'Prüfintervall');
wirft(fn() => Dokumente::vorlegen([$id], ['anna'], $personen, '', $arzt), 'Entwurf vorlegen');
check(Dokumente::liste([], false) === [] && count(Dokumente::liste([], true)) === 1, 'Entwürfe nur für Schreibende');
Dokumente::einreichen($id, $anna);
check(count(Dokumente::zuVidieren()) === 1, 'liegt zur Prüfung');
wirft(fn() => Dokumente::einreichen($id, $anna), 'doppelt einreichen');
Dokumente::zurueckweisen($id, 'Einwirkzeit fehlt', $arzt);
check(Dokumente::version((int)$d['entwurf'])['notiz'] === 'Zurückgewiesen: Einwirkzeit fehlt' && !Dokumente::zuVidieren(), 'zurückgewiesen');
Dokumente::inhaltSpeichern($id, '<p>Vor jedem Patientenkontakt.</p><p>Einwirkzeit 30 Sekunden.</p>', 'Einwirkzeit ergänzt', $anna, $t0 + 60);
Dokumente::einreichen($id, $anna);
Dokumente::vidieren($id, $arzt, '', $t0 + 120);
$d = Dokumente::dokument($id);
$v1 = Dokumente::version((int)$d['gueltig']);
check($d['entwurf'] === null && $v1['status'] === 'freigegeben' && $v1['vidiert_kuerzel'] === 'EB' && $d['naechste_pruefung'] === '2027-01-01', 'vidiert, Wiedervorlage in 12 Monaten');
wirft(fn() => Dokumente::vidieren($id, $arzt, ''), 'ohne Entwurf vidieren');

// Vorlegen und Lesen
$n = Dokumente::vorlegen([$id], ['anna', 'ben', 'fremd'], $personen, '2026-01-15', $arzt, $t0 + 200);
check($n === 2 && count(Dokumente::lesestand((int)$v1['id'])) === 2, 'vorgelegt nur an Personen mit Zugang');
check(Dokumente::vorlegen([$id], ['anna'], $personen, '2026-01-20', $arzt) === 0 && Dokumente::lesestand((int)$v1['id'])[0]['frist'] === '2026-01-20', 'erneut vorlegen ändert nur die Frist');
wirft(fn() => Dokumente::vorlegen([$id], [], $personen, '', $arzt), 'niemand gewählt');
wirft(fn() => Dokumente::vorlegen([$id], ['anna'], $personen, '15.01.2026', $arzt), 'Frist im falschen Format');
check(count(Dokumente::lesemappe('anna')) === 1 && Dokumente::offeneLesepflicht($id, 'anna') !== null, 'Lesemappe');
Dokumente::gelesen($id, (int)$v1['id'], $anna, $t0 + 300);
check(!Dokumente::lesemappe('anna') && (int)Dokumente::lesestand((int)$v1['id'])[1]['gelesen'] === $t0 + 300, 'gelesen bestätigt');
wirft(fn() => Dokumente::gelesen($id, (int)$v1['id'], $anna), 'doppelt bestätigen');
wirft(fn() => Dokumente::gelesen($id, (int)$v1['id'], ['login' => 'carla', 'name' => 'Carla']), 'ohne Vorlage bestätigen');

// Neue Version: gültige bleibt, bis vidiert; offene Lesepflicht geht über; erneut vorlegen
$v2id = Dokumente::inhaltSpeichern($id, '<p>Vor jedem Patientenkontakt.</p><p>Einwirkzeit 30 Sekunden.</p><p>Nach dem Ablegen der Handschuhe.</p>', 'Handschuhe', $anna);
check((int)Dokumente::dokument($id)['gueltig'] === (int)$v1['id'] && Dokumente::version($v2id)['nummer'] === 2, 'neuer Entwurf neben gültiger Fassung');
check(Dokumente::liste(['zustand' => 'entwurf'], true)[0]['id'] === $id && Dokumente::liste(['zustand' => 'freigegeben'], true)[0]['id'] === $id, 'Filter Entwurf und Freigegeben');
Dokumente::vidieren($id, $arzt, 'EB', $t0 + 86400, true);
$d = Dokumente::dokument($id);
check((int)$d['gueltig'] === $v2id && Dokumente::version((int)$v1['id'])['status'] === 'ersetzt', 'Version 2 gültig, Version 1 ersetzt');
$ls = Dokumente::lesestand($v2id);
check(count($ls) === 2 && !array_filter($ls, fn($l) => (int)$l['gelesen'] > 0), 'neue Fassung allen erneut vorgelegt');
[$markiert, $neu] = Html::aenderungenMarkieren(Dokumente::version($v2id)['inhalt'], $v1['inhalt']);
check($neu === 1 && str_contains($markiert, 'class="neu">Nach dem Ablegen'), 'Änderung zur Vorversion');
wirft(fn() => Dokumente::gelesen($id, (int)$v1['id'], ['login' => 'ben', 'name' => 'Ben']), 'alte Fassung bestätigen');

// Wiedervorlage, Archiv
check(count(Dokumente::faellig('2027-01-02')) === 1 && !Dokumente::faellig('2026-06-01'), 'Wiedervorlage fällig');
check(Dokumente::liste(['zustand' => 'faellig'], false, '2027-01-02')[0]['zustand'] === 'faellig', 'Filter fällig');
Dokumente::geprueft($id, $arzt, strtotime('2027-01-05'));
check(Dokumente::dokument($id)['naechste_pruefung'] === '2028-01-05', 'geprüft: neue Frist');
Dokumente::archivieren($id, true, $arzt);
check(!Dokumente::liste([], true) && count(Dokumente::liste(['zustand' => 'archiv'], true)) === 1 && !Dokumente::lesemappe('ben'), 'archiviert');
Dokumente::archivieren($id, false, $arzt);
wirft(fn() => Dokumente::ordnerLoeschen($hyg), 'Ordner mit Inhalt löschen');

// Suche nach Schnipseln
$id2 = Dokumente::anlegen(['titel' => 'Flächendesinfektion Behandlungsraum', 'tags' => 'Hygiene', 'inhalt' => '<p>Täglich wischen.</p>'], $anna);
$id3 = Dokumente::anlegen(['titel' => 'Notfallplan', 'tags' => 'Notfall', 'inhalt' => '<p>Desinfektion des Notfallkoffers monatlich.</p>'], $anna);
$treffer = array_column(Dokumente::liste(['q' => 'DESINF'], true), 'id');
check($treffer === [$id2, $id], 'Schnipsel im Titel, unabhängig von Groß/Klein: ' . json_encode($treffer));
$treffer = Dokumente::liste(['q' => 'desinf', 'text' => '1'], true);
check(array_column($treffer, 'id') === [$id2, $id, $id3] && str_contains($treffer[2]['treffer'], 'Desinfektion des Notfallkoffers'), 'auch im Text');
check(array_column(Dokumente::liste(['tag' => 'notfall'], true), 'id') === [$id3] && Dokumente::alleTags() === ['Hygiene' => 2, 'IfSG' => 1, 'Notfall' => 1], 'Tags');
check(array_column(Dokumente::liste(['ordner' => $hyg], true), 'id') === [$id], 'Ordner mit Unterordnern');
check(Dokumente::entwurfVerwerfen($id3, $anna) === true && Dokumente::dokument($id3) === null, 'Entwurf ohne gültige Fassung verworfen = gelöscht');

// Bilder
$url = Dokumente::bildSpeichern(base64_decode(PNG));
check(preg_match('#^/qm/bild/[0-9a-f]{64}\.png$#', $url) === 1 && Dokumente::bildDatei(basename($url)) !== null, 'Bild gespeichert');
check(substr(sprintf('%o', fileperms(Dokumente::bildDatei(basename($url)))), -3) === '600', 'Bild 0600');
wirft(fn() => Dokumente::bildSpeichern('<svg onload="x"/>'), 'SVG abgelehnt');
check(Dokumente::bildDatei('../qm.db') === null, 'Bildpfad geprüft');

// ---- Word-Import ----
function docx(string $datei, string $body, array $extra = []): void {
    $z = new ZipArchive();
    $z->open($datei, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"';
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document ' . $w . '><w:body>' . $body . '<w:sectPr/></w:body></w:document>');
    $z->addFromString('word/styles.xml', '<?xml version="1.0"?><w:styles ' . $w . '><w:style w:type="paragraph" w:styleId="berschrift1"><w:name w:val="heading 1"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Eigene2"><w:name w:val="Meine Zwischenzeile"/><w:basedOn w:val="berschrift2"/></w:style><w:style w:type="paragraph" w:styleId="berschrift2"><w:name w:val="heading 2"/></w:style></w:styles>');
    $z->addFromString('word/numbering.xml', '<?xml version="1.0"?><w:numbering ' . $w . '><w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>'
        . '<w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum><w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num></w:numbering>');
    $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
        . '<Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image2.emf"/>'
        . '<Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.org/hygiene" TargetMode="External"/>'
        . '<Relationship Id="rId8" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="javascript:alert(1)" TargetMode="External"/></Relationships>');
    $z->addFromString('word/media/image1.png', base64_decode(PNG));
    $z->addFromString('word/media/image2.emf', 'EMF');
    $z->addFromString('docProps/core.xml', '<?xml version="1.0"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>PRAXISINTERNES QM-Dokument:</dc:title></cp:coreProperties>');
    foreach ($extra as $n => $inhalt) $z->addFromString($n, $inhalt);
    $z->close();
}
$p = fn(string $inhalt, string $ppr = '') => '<w:p>' . ($ppr !== '' ? '<w:pPr>' . $ppr . '</w:pPr>' : '') . $inhalt . '</w:p>';
$r = fn(string $t, string $rpr = '') => '<w:r>' . ($rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '') . '<w:t xml:space="preserve">' . htmlspecialchars($t, ENT_XML1) . '</w:t></w:r>';
$num = fn(int $id, int $lvl) => '<w:numPr><w:ilvl w:val="' . $lvl . '"/><w:numId w:val="' . $id . '"/></w:numPr>';
$bild = fn(string $rid) => '<w:r><w:drawing><wp:inline><a:graphic><a:graphicData><a:blip r:embed="' . $rid . '"/></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
$body = $p($r('Aufbereitung von Medizinprodukten'), '<w:pStyle w:val="berschrift1"/>')
    . $p($r('Ziel: ') . $r('sicher', '<w:b/>') . $r(' und ') . $r('nachvollziehbar', '<w:i/><w:u w:val="single"/>') . $r(' alt', '<w:strike/>') . '<w:hyperlink r:id="rId7">' . $r(' Leitlinie') . '</w:hyperlink><w:hyperlink r:id="rId8">' . $r(' X') . '</w:hyperlink>')
    . $p($r('Zwischenteil'), '<w:pStyle w:val="Eigene2"/>')
    . $p($r('Punkt A'), $num(1, 0)) . $p($r('Unterpunkt 1'), $num(1, 1)) . $p($r('Unterpunkt 2'), $num(1, 1)) . $p($r('Punkt B'), $num(1, 0))
    . $p($r('Schritt 1'), $num(2, 0))
    . '<w:sdt><w:sdtContent>' . $p('<w:r><w:sym w:font="Wingdings" w:char="F0A8"/></w:r>' . $r(' Kontrolle')) . '</w:sdtContent></w:sdt>'
    . '<w:tbl><w:tr><w:trPr><w:tblHeader/></w:trPr><w:tc>' . $p($r('Was')) . '</w:tc><w:tc>' . $p($r('Wer')) . '</w:tc></w:tr><w:tr><w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr>' . $p($r('Zeile')) . $p($r('zwei')) . '</w:tc></w:tr></w:tbl>'
    . $p($bild('rId5') . $bild('rId6')) . '<w:p><w:del><w:r><w:delText>gelöscht</w:delText></w:r></w:del></w:p>';
docx("$dir/muster.docx", $body);
$erg = Docx::lesen("$dir/muster.docx", 'qep/muster-aufbereitung.docx', fn($b, $e) => Dokumente::bildSpeichern($b));
$html = $erg['html'];
check($erg['titel'] === 'Aufbereitung von Medizinprodukten', 'Titel aus der ersten Überschrift: ' . $erg['titel']);
check(str_contains($html, '<h2>Aufbereitung von Medizinprodukten</h2>') && str_contains($html, '<h3>Zwischenteil</h3>'), 'Überschriften (auch über basedOn): ' . $html);
check(str_contains($html, '<strong>sicher</strong>') && str_contains($html, '<em><u>nachvollziehbar</u></em>') && str_contains($html, '<s> alt</s>'), 'fett, kursiv, unterstrichen, durchgestrichen: ' . $html);
check(str_contains($html, '<a href="https://example.org/hygiene" rel="noopener noreferrer" target="_blank"> Leitlinie</a>') && !str_contains($html, 'javascript'), 'Links: ' . $html);
check(str_contains($html, '<ul><li>Punkt A<ol><li>Unterpunkt 1</li><li>Unterpunkt 2</li></ol></li><li>Punkt B</li></ul><ol><li>Schritt 1</li></ol>'), 'verschachtelte Listen: ' . $html);
check(str_contains($html, '<p>☐ Kontrolle</p>') && str_contains($html, '<th>Was</th>') && str_contains($html, '<td colspan="2">Zeile<br>zwei</td>'), 'Symbol, Inhaltssteuerelement, Tabelle: ' . $html);
check(str_contains($html, '<img src="/qm/bild/') && $erg['bilder_uebersprungen'] === 1 && !str_contains($html, 'gelöscht'), 'Bilder (EMF weggelassen), gelöschter Text: ' . $html);
docx("$dir/leer.docx", $p($r('Nur Text')));
check(Docx::lesen("$dir/leer.docx", 'Ordner/Hygiene_Plan Küche.docx', fn() => '')['titel'] === 'Hygiene Plan Küche', 'Titel aus dem Dateinamen statt „PRAXISINTERNES QM-Dokument“');
$wns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
docx("$dir/qep.docx", '<w:tbl><w:tr><w:tc>' . $p($r('PRAXISINTERNES QM-Dokument:')) . '</w:tc><w:tc>' . $p($r('Hygieneplan Labor')) . '</w:tc></w:tr></w:tbl>' . $p($r('Punkt'), '<w:pStyle w:val="ListeP"/>'),
    ['word/styles.xml' => '<?xml version="1.0"?><w:styles ' . $wns . '><w:style w:type="paragraph" w:styleId="ListeP"><w:name w:val="List Bullet"/><w:pPr><w:numPr><w:numId w:val="1"/></w:numPr></w:pPr></w:style></w:styles>']);
$erg = Docx::lesen("$dir/qep.docx", 'x.docx', fn() => '');
check($erg['titel'] === 'Hygieneplan Labor' && str_contains($erg['html'], '<ul><li>Punkt</li></ul>'), 'QEP-Kopf als Titel, Liste aus der Formatvorlage: ' . json_encode($erg, JSON_UNESCAPED_UNICODE));
docx("$dir/boese.docx", '', ['word/document.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>&a;</w:body></w:document>']);
wirft(fn() => Docx::lesen("$dir/boese.docx", 'b.docx', fn() => ''), 'DOCTYPE in Word-Datei');
file_put_contents("$dir/kaputt.docx", 'kein zip');
wirft(fn() => Docx::lesen("$dir/kaputt.docx", 'k.docx', fn() => ''), 'kaputte Datei');

// Import einer Datei und eines ZIPs mit Ordnern
$e = Import::datei((string)file_get_contents("$dir/muster.docx"), 'QEP-Muster/Kapitel 2 Hygiene/muster-aufbereitung.docx', null, ['QEP'], $anna);
check(count($e['angelegt']) === 1 && $e['hinweise'] !== [], 'Import einer Datei mit Hinweis zu EMF');
$imp = Dokumente::dokument($e['angelegt'][0]['id']);
$kap = Dokumente::ordner((int)$imp['ordner']);
check($imp['tags'] === ',QEP,' && $kap['name'] === 'Kapitel 2 Hygiene' && Dokumente::ordner((int)$kap['eltern'])['name'] === 'QEP-Muster' && $imp['gueltig'] === null, 'Import: Ordner, Tag, Entwurf');
check(str_contains(implode(' ', Import::datei((string)file_get_contents("$dir/muster.docx"), 'QEP-Muster/Kapitel 2 Hygiene/muster-aufbereitung.docx', null, [], $anna)['hinweise']), 'schon übernommen'), 'nicht doppelt übernommen');
$z = new ZipArchive();
$z->open("$dir/paket.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFile("$dir/muster.docx", 'Muster/Kapitel 1/eins.docx');
$z->addFile("$dir/leer.docx", 'Muster/Kapitel 3/drei.docx');
$z->addFromString('Muster/liesmich.pdf', '%PDF');
$z->addFromString('__MACOSX/Muster/._eins.docx', 'x');
$z->addFromString('Muster/Kapitel 1/~$eins.docx', 'x');
$z->close();
$e = Import::datei((string)file_get_contents("$dir/paket.zip"), 'paket.zip', $hyg, ['QEP'], $anna, false);
check(count($e['angelegt']) === 2 && str_contains(implode(' ', $e['hinweise']), '1 andere Dateien'), 'ZIP: zwei Word-Dateien, PDF übersprungen: ' . json_encode($e, JSON_UNESCAPED_UNICODE));
check((int)Dokumente::dokument($e['angelegt'][0]['id'])['ordner'] === $hyg, 'ZIP ohne Ordnerstruktur in den Zielordner');
wirft(fn() => Import::datei('PK' . "\x03\x04" . 'x', 'a.pdf', null, [], $anna), 'falsche Endung');
wirft(fn() => Import::datei('%PDF', 'a.docx', null, [], $anna), 'keine Word-Datei');
check(glob("$dir/tmp/*") === [], 'Zwischendateien aufgeräumt');

// Gerüste, Überblick
foreach (Gerueste::alle() as $k => $g) check(Html::reinigen($g['inhalt']) !== '' && in_array($g['ordner'], Speicher::ORDNER, true), "Gerüst $k");
$u = Dokumente::ueberblick();
check(count($u) === 3 && $u[0]['state'] === 'done' && $u[1]['state'] === 'done' && array_filter($u, fn($i) => !$i['optional']) === [], 'Überblick');
check((int)Speicher::pdo()->query('PRAGMA user_version')->fetchColumn() === Speicher::VERSION && substr(sprintf('%o', fileperms("$dir/qm.db")), -3) === '600', 'Schema und Rechte der Datenbank');

echo "qm: alle Tests grün\n";
