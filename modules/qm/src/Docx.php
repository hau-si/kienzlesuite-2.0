<?php
declare(strict_types=1);
namespace KienzleQm;

/**
 * Word-Dateien (.docx, Office Open XML) in den Inhalt eines QM-Dokuments übersetzen: Überschriften, Absätze, fett/kursiv/unterstrichen,
 * Listen, Tabellen, Links und eingebettete Bilder (PNG, JPEG, GIF). Kopf- und Fußzeilen, Felder, Kommentare, Formen und EMF/WMF-Grafiken
 * entfallen. Gedacht für die Musterdokumente aus QEP (KBV) und eigene Word-Vorlagen der Praxis.
 */
final class Docx {
    public const MAX_DATEI = 20_000_000;
    private const MAX_XML = 25_000_000;
    private const MAX_BILD = 5_000_000;
    private const MAX_EINTRAEGE = 3000;
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const SYMBOLE = ['F0A8' => '☐', 'F06F' => '☐', 'F071' => '☐', 'F0FE' => '☑', 'F078' => '☒', 'F0FD' => '☒', 'F0B7' => '•', 'F0A7' => '▪', 'F0D8' => '➢', 'F0E0' => '→', 'F0FC' => '✓'];

    private \ZipArchive $zip;
    private \DOMXPath $x;
    /** @var array<string,array{name:string,basis:string,ebene:int,numId:string,ilvl:int}> */
    private array $stile = [];
    /** @var array<string,array<int,bool>> numId → Ebene → nummeriert */
    private array $nummern = [];
    /** @var array<string,array{ziel:string,extern:bool}> */
    private array $bezuege = [];
    /** @var callable(string,string):string */
    private $bildSpeichern;
    public int $bilderUebersprungen = 0;
    private string $ersteUeberschrift = '';
    private string $kopfTitel = '';
    private bool $ersteTabelle = true;

    /**
     * @param callable(string $bytes, string $endung): string $bildSpeichern liefert die Adresse des gespeicherten Bildes
     * @return array{titel:string, html:string, bilder_uebersprungen:int}
     */
    public static function lesen(string $datei, string $dateiname, callable $bildSpeichern): array {
        if (!is_file($datei) || filesize($datei) > self::MAX_DATEI) throw new \InvalidArgumentException('Die Word-Datei ist zu groß (höchstens 20 MB).');
        $zip = new \ZipArchive();
        if ($zip->open($datei, \ZipArchive::RDONLY) !== true) throw new \InvalidArgumentException('Keine gültige Word-Datei (.docx). Ältere .doc-Dateien bitte in Word als .docx speichern.');
        try {
            if ($zip->numFiles > self::MAX_EINTRAEGE) throw new \InvalidArgumentException('Die Word-Datei hat zu viele Bestandteile.');
            $d = new self();
            $d->zip = $zip;
            $d->bildSpeichern = $bildSpeichern;
            $haupt = $d->xml('word/document.xml');
            if (!$haupt) throw new \InvalidArgumentException('Keine gültige Word-Datei (word/document.xml fehlt).');
            $d->stileLesen();
            $d->nummernLesen();
            $d->bezuegeLesen();
            $d->x = new \DOMXPath($haupt);
            $d->x->registerNamespace('w', self::W);
            $body = $d->x->query('/w:document/w:body')->item(0);
            $html = $body instanceof \DOMElement ? $d->bloecke($body) : '';
            $html = Html::reinigen($html);
            return ['titel' => $d->titel($dateiname), 'html' => $html, 'bilder_uebersprungen' => $d->bilderUebersprungen];
        } finally {
            $zip->close();
        }
    }

    /** Titel: Kopf der QEP-Muster, sonst erste Überschrift, sonst Titel aus den Dateieigenschaften, sonst der Dateiname (ohne „PRAXISINTERNES QM-Dokument“ u. ä.). */
    private function titel(string $dateiname): string {
        $gut = static fn(string $t): bool => mb_strlen($t) >= 3 && mb_strlen($t) <= 150 && !preg_match('/qm-dokument|^praxisintern|^dokument\s*\d*$|^titel:?$|^inhalt$/iu', $t);
        if ($gut($this->kopfTitel)) return $this->kopfTitel;
        $t = trim(preg_replace('/\s+/u', ' ', $this->ersteUeberschrift) ?? '');
        if ($gut($t)) return $t;
        $kern = $this->xml('docProps/core.xml');
        if ($kern) {
            $t = trim(preg_replace('/\s+/u', ' ', (string)$kern->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title')->item(0)?->textContent) ?? '');
            if ($gut($t)) return $t;
        }
        $t = preg_replace('/\.docx$/i', '', basename(str_replace('\\', '/', $dateiname))) ?? '';
        $t = trim(preg_replace('/[_]+|\s+/u', ' ', $t) ?? '');
        return $t !== '' ? mb_substr($t, 0, 150) : 'Importiertes Dokument';
    }

    private function xml(string $name): ?\DOMDocument {
        $st = $this->zip->statName($name);
        if ($st === false) return null;
        if ($st['size'] > self::MAX_XML) throw new \InvalidArgumentException('Die Word-Datei ist zu groß entpackt.');
        $roh = $this->zip->getFromName($name);
        if (!is_string($roh) || $roh === '') return null;
        if (stripos($roh, '<!DOCTYPE') !== false || stripos($roh, '<!ENTITY') !== false) throw new \InvalidArgumentException('Die Word-Datei enthält unzulässige Angaben.');
        $doc = new \DOMDocument();
        $alt = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($roh, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
        return $ok ? $doc : null;
    }

    private function stileLesen(): void {
        $doc = $this->xml('word/styles.xml');
        if (!$doc) return;
        foreach ($doc->getElementsByTagNameNS(self::W, 'style') as $s) {
            /** @var \DOMElement $s */
            $id = $s->getAttributeNS(self::W, 'styleId');
            $name = mb_strtolower((string)$this->kindWert($s, 'name'));
            $ebene = -1;
            if (preg_match('/^(heading|überschrift|berschrift)\s*([1-9])$/u', $name, $m)) $ebene = (int)$m[2];
            elseif ($name === 'title' || $name === 'titel') $ebene = 0;
            $ol = $s->getElementsByTagNameNS(self::W, 'outlineLvl')->item(0);
            if ($ebene < 0 && $ol instanceof \DOMElement) $ebene = (int)$ol->getAttributeNS(self::W, 'val') + 1;
            $numId = '';
            $ilvl = 0;
            $pPr = $this->kind($s, 'pPr');
            $numPr = $pPr ? $this->kind($pPr, 'numPr') : null;
            if ($numPr) { $numId = (string)$this->kindWert($numPr, 'numId'); $ilvl = (int)$this->kindWert($numPr, 'ilvl'); }
            $this->stile[$id] = ['name' => $name, 'basis' => (string)$this->kindWert($s, 'basedOn'), 'ebene' => $ebene, 'numId' => $numId, 'ilvl' => $ilvl];
        }
    }

    private function nummernLesen(): void {
        $doc = $this->xml('word/numbering.xml');
        if (!$doc) return;
        $abstrakt = [];
        foreach ($doc->getElementsByTagNameNS(self::W, 'abstractNum') as $a) {
            /** @var \DOMElement $a */
            $ebenen = [];
            foreach ($a->getElementsByTagNameNS(self::W, 'lvl') as $l) {
                /** @var \DOMElement $l */
                $fmt = (string)$this->kindWert($l, 'numFmt');
                $ebenen[(int)$l->getAttributeNS(self::W, 'ilvl')] = !in_array($fmt, ['bullet', 'none', ''], true);
            }
            $abstrakt[$a->getAttributeNS(self::W, 'abstractNumId')] = $ebenen;
        }
        foreach ($doc->getElementsByTagNameNS(self::W, 'num') as $n) {
            /** @var \DOMElement $n */
            $this->nummern[$n->getAttributeNS(self::W, 'numId')] = $abstrakt[(string)$this->kindWert($n, 'abstractNumId')] ?? [];
        }
    }

    private function bezuegeLesen(): void {
        $doc = $this->xml('word/_rels/document.xml.rels');
        if (!$doc) return;
        foreach ($doc->getElementsByTagName('Relationship') as $r) {
            /** @var \DOMElement $r */
            $this->bezuege[$r->getAttribute('Id')] = ['ziel' => $r->getAttribute('Target'), 'extern' => $r->getAttribute('TargetMode') === 'External'];
        }
    }

    /** Wert des Attributs w:val im ersten Kind mit diesem Namen. */
    private function kindWert(\DOMElement $e, string $name): ?string {
        foreach ($e->childNodes as $k) if ($k instanceof \DOMElement && $k->namespaceURI === self::W && $k->localName === $name) return $k->getAttributeNS(self::W, 'val');
        return null;
    }

    private function kind(\DOMElement $e, string $name): ?\DOMElement {
        foreach ($e->childNodes as $k) if ($k instanceof \DOMElement && $k->namespaceURI === self::W && $k->localName === $name) return $k;
        return null;
    }

    /** Aufzählung aus der Formatvorlage (z. B. „Liste mit Punkten“), falls der Absatz selbst keine hat. @return array{0:string,1:int} */
    private function stilListe(string $stil): array {
        for ($i = 0; $i < 10 && $stil !== '' && isset($this->stile[$stil]); $i++) {
            if ($this->stile[$stil]['numId'] !== '') return [$this->stile[$stil]['numId'], $this->stile[$stil]['ilvl']];
            $stil = $this->stile[$stil]['basis'];
        }
        return ['', 0];
    }

    private function ueberschriftEbene(string $stil): int {
        for ($i = 0; $i < 10 && $stil !== '' && isset($this->stile[$stil]); $i++) {
            if ($this->stile[$stil]['ebene'] >= 0) return $this->stile[$stil]['ebene'];
            $stil = $this->stile[$stil]['basis'];
        }
        return -1;
    }

    /** Absätze, Listen und Tabellen eines Containers (Textkörper, Inhaltssteuerelement). */
    private function bloecke(\DOMElement $container): string {
        $out = '';
        $listen = [];    // Stapel [nummeriert]
        $schliessen = function () use (&$listen, &$out): void {
            while ($listen) { $out .= '</li>' . (array_pop($listen) ? '</ol>' : '</ul>'); }
        };
        foreach ($this->elemente($container) as $e) {
            if ($e->localName === 'tbl') { $schliessen(); $out .= $this->tabelle($e); continue; }
            if ($e->localName !== 'p') continue;
            $pPr = $this->kind($e, 'pPr');
            $stil = $pPr ? (string)$this->kindWert($pPr, 'pStyle') : '';
            $inhalt = $this->laeufe($e);
            $numPr = $pPr ? $this->kind($pPr, 'numPr') : null;
            $numId = $numPr ? (string)$this->kindWert($numPr, 'numId') : '';
            $ilvl = $numPr ? (int)$this->kindWert($numPr, 'ilvl') : 0;
            if (!$numPr) [$numId, $ilvl] = $this->stilListe($stil);
            $ebene = $this->ueberschriftEbene($stil);
            if ($numId !== '' && $numId !== '0' && $ebene < 0) {
                $tiefe = min(8, max(0, $ilvl));
                $nummeriert = $this->nummern[$numId][$tiefe] ?? false;
                while (count($listen) > $tiefe + 1) $out .= '</li>' . (array_pop($listen) ? '</ol>' : '</ul>');
                if (count($listen) === $tiefe + 1) {
                    if (end($listen) === $nummeriert) $out .= '</li>';
                    else { $out .= '</li>' . (array_pop($listen) ? '</ol>' : '</ul>'); }
                }
                while (count($listen) < $tiefe + 1) { $listen[] = $nummeriert; $out .= $nummeriert ? '<ol>' : '<ul>'; }
                $out .= '<li>' . $inhalt;
                continue;
            }
            $schliessen();
            if (trim(strip_tags($inhalt, '<img>')) === '') continue;
            if ($ebene >= 0) {
                $tag = 'h' . min(4, max(2, $ebene + 1));
                if ($this->ersteUeberschrift === '') $this->ersteUeberschrift = trim(html_entity_decode(strip_tags($inhalt), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $out .= '<' . $tag . '>' . strip_tags($inhalt, '<img>') . '</' . $tag . '>';
            } else {
                $out .= '<p>' . $inhalt . '</p>';
            }
        }
        $schliessen();
        return $out;
    }

    /** Kinder eines Containers, Inhaltssteuerelemente und Einfügungen aufgelöst. @return list<\DOMElement> */
    private function elemente(\DOMElement $c): array {
        $out = [];
        foreach ($c->childNodes as $k) {
            if (!$k instanceof \DOMElement || $k->namespaceURI !== self::W) continue;
            if ($k->localName === 'sdt') { $inhalt = $this->kind($k, 'sdtContent'); if ($inhalt) array_push($out, ...$this->elemente($inhalt)); continue; }
            if (in_array($k->localName, ['ins', 'customXml', 'smartTag'], true)) { array_push($out, ...$this->elemente($k)); continue; }
            $out[] = $k;
        }
        return $out;
    }

    private function tabelle(\DOMElement $t): string {
        // Kopf der QEP-Muster: „PRAXISINTERNES QM-Dokument: | <Titel>“ in der ersten Tabelle
        if ($this->ersteTabelle) {
            $this->ersteTabelle = false;
            foreach ($this->elemente($t) as $tr) {
                if ($tr->localName !== 'tr') continue;
                $zellen = array_values(array_filter($this->elemente($tr), fn($e) => $e->localName === 'tc'));
                if (count($zellen) >= 2 && preg_match('/qm-dokument/iu', $zellen[0]->textContent)) $this->kopfTitel = trim(preg_replace('/\s+/u', ' ', $zellen[1]->textContent) ?? '');
                break;
            }
        }
        $zeilen = '';
        foreach ($this->elemente($t) as $tr) {
            if ($tr->localName !== 'tr') continue;
            $trPr = $this->kind($tr, 'trPr');
            $kopf = $trPr && $this->kind($trPr, 'tblHeader');
            $zellen = '';
            foreach ($this->elemente($tr) as $tc) {
                if ($tc->localName !== 'tc') continue;
                $tcPr = $this->kind($tc, 'tcPr');
                $span = $tcPr ? (int)$this->kindWert($tcPr, 'gridSpan') : 0;
                $vm = $tcPr ? $this->kind($tcPr, 'vMerge') : null;
                $inhalt = ($vm && $vm->getAttributeNS(self::W, 'val') !== 'restart') ? '' : $this->zelle($tc);
                $tag = $kopf ? 'th' : 'td';
                $zellen .= '<' . $tag . ($span > 1 ? ' colspan="' . min(50, $span) . '"' : '') . '>' . $inhalt . '</' . $tag . '>';
            }
            if ($zellen !== '') $zeilen .= '<tr>' . $zellen . '</tr>';
        }
        return $zeilen === '' ? '' : '<table><tbody>' . $zeilen . '</tbody></table>';
    }

    /** Zelleninhalt: Absätze als Zeilen, verschachtelte Tabellen als Text. */
    private function zelle(\DOMElement $tc): string {
        $teile = [];
        foreach ($this->elemente($tc) as $e) {
            if ($e->localName === 'p') {
                $pPr = $this->kind($e, 'pPr');
                $liste = $pPr && $this->kind($pPr, 'numPr');
                $i = $this->laeufe($e);
                if (trim(strip_tags($i, '<img>')) !== '') $teile[] = ($liste ? '• ' : '') . $i;
            } elseif ($e->localName === 'tbl') {
                $teile[] = htmlspecialchars(trim(preg_replace('/\s+/u', ' ', $e->textContent) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return implode('<br>', $teile);
    }

    /** Text eines Absatzes mit Auszeichnungen, Links und Bildern. */
    private function laeufe(\DOMElement $p): string {
        $out = '';
        foreach ($p->childNodes as $k) {
            if (!$k instanceof \DOMElement) continue;
            if ($k->namespaceURI !== self::W) continue;
            switch ($k->localName) {
                case 'r': $out .= $this->lauf($k); break;
                case 'hyperlink':
                    $innen = $this->laeufe($k);
                    $rid = $k->getAttributeNS(self::R, 'id');
                    $ziel = $this->bezuege[$rid] ?? null;
                    $out .= $ziel && $ziel['extern'] && preg_match('#^(https?://|mailto:)#i', $ziel['ziel'])
                        ? '<a href="' . htmlspecialchars($ziel['ziel'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . $innen . '</a>' : $innen;
                    break;
                case 'ins': case 'smartTag': case 'fldSimple': case 'customXml': $out .= $this->laeufe($k); break;
                case 'sdt': $c = $this->kind($k, 'sdtContent'); if ($c) $out .= $this->laeufe($c); break;
            }
        }
        foreach (['strong', 'em', 'u', 's'] as $t) $out = str_replace('</' . $t . '><' . $t . '>', '', $out);
        return $out;
    }

    private function lauf(\DOMElement $r): string {
        $text = '';
        foreach ($r->childNodes as $k) {
            if (!$k instanceof \DOMElement) continue;
            if ($k->namespaceURI === self::W) {
                switch ($k->localName) {
                    case 't': $text .= htmlspecialchars($k->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'); break;
                    case 'tab': $text .= ' '; break;
                    case 'br': case 'cr': $text .= $k->getAttributeNS(self::W, 'type') === 'page' ? '' : '<br>'; break;
                    case 'noBreakHyphen': $text .= '-'; break;
                    case 'sym': $text .= self::SYMBOLE[strtoupper($k->getAttributeNS(self::W, 'char'))] ?? ''; break;
                    case 'drawing': case 'pict': case 'object': $text .= $this->bild($k); break;
                }
            } elseif ($k->localName === 'AlternateContent') {
                foreach ($k->getElementsByTagNameNS(self::W, 'drawing') as $dr) { $text .= $this->bild($dr); break; }
            }
        }
        if ($text === '') return '';
        $rPr = $this->kind($r, 'rPr');
        if (!$rPr || str_starts_with($text, '<img')) return $text;
        $an = function (string $name) use ($rPr): bool {
            $e = $this->kind($rPr, $name);
            return $e !== null && !in_array($e->getAttributeNS(self::W, 'val'), ['0', 'false', 'none'], true);
        };
        if ($an('strike') || $an('dstrike')) $text = '<s>' . $text . '</s>';
        if ($an('u')) $text = '<u>' . $text . '</u>';
        if ($an('i')) $text = '<em>' . $text . '</em>';
        if ($an('b')) $text = '<strong>' . $text . '</strong>';
        $va = $this->kindWert($rPr, 'vertAlign');
        if ($va === 'superscript') $text = '<sup>' . $text . '</sup>';
        if ($va === 'subscript') $text = '<sub>' . $text . '</sub>';
        return $text;
    }

    /** Eingebettetes Bild (DrawingML a:blip r:embed oder VML v:imagedata r:id). */
    private function bild(\DOMElement $e): string {
        $rid = '';
        foreach ($e->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'blip') as $b) { $rid = $b->getAttributeNS(self::R, 'embed'); break; }
        if ($rid === '') foreach ($e->getElementsByTagNameNS('urn:schemas-microsoft-com:vml', 'imagedata') as $b) { $rid = $b->getAttributeNS(self::R, 'id'); break; }
        $ziel = $this->bezuege[$rid] ?? null;
        if (!$ziel || $ziel['extern']) return '';
        $pfad = $this->pfad('word/' . $ziel['ziel']);
        $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
        $st = $this->zip->statName($pfad);
        if (!in_array($endung, ['png', 'jpg', 'jpeg', 'gif'], true) || $st === false || $st['size'] > self::MAX_BILD) { $this->bilderUebersprungen++; return ''; }
        $bytes = $this->zip->getFromName($pfad);
        if (!is_string($bytes)) { $this->bilderUebersprungen++; return ''; }
        try {
            $url = ($this->bildSpeichern)($bytes, $endung);
        } catch (\InvalidArgumentException) {
            $this->bilderUebersprungen++;
            return '';
        }
        return '<img src="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" alt="">';
    }

    /** „word/../media/x.png“ → „media/x.png“; absolute Ziele beginnen im Archiv. */
    private function pfad(string $p): string {
        if (str_starts_with($p, 'word//')) $p = substr($p, 6);
        $teile = [];
        foreach (explode('/', $p) as $t) {
            if ($t === '' || $t === '.') continue;
            if ($t === '..') { array_pop($teile); continue; }
            $teile[] = $t;
        }
        return implode('/', $teile);
    }
}
