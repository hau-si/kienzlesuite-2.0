<?php
declare(strict_types=1);
namespace KienzleQm;

/**
 * Inhalt der Dokumente: HTML aus dem Editor oder aus dem Word-Import wird auf eine feste Liste von Elementen und Attributen
 * reduziert (keine Skripte, keine Stile, keine fremden Bilder). Gespeichert wird nur das gereinigte HTML.
 */
final class Html {
    public const MAX = 2_000_000;
    private const BLOCK = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'blockquote', 'hr'];
    private const INLINE = ['strong', 'em', 'u', 's', 'sub', 'sup', 'br', 'a', 'img'];
    private const UMBENENNEN = ['b' => 'strong', 'i' => 'em', 'strike' => 's', 'del' => 's', 'h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4', 'tfoot' => 'tbody', 'ins' => 'u'];
    private const WEG = ['script', 'style', 'iframe', 'object', 'embed', 'head', 'title', 'meta', 'link', 'template', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea', 'noscript', 'canvas', 'video', 'audio'];
    private const LEER = ['br', 'hr', 'img'];

    /** Bildadresse im Modul: /<pfad>/bild/<sha256>.<endung>. */
    public static function bildMuster(): string { return '#^/[a-z0-9-]{1,31}/bild/([0-9a-f]{64}\.(?:png|jpg|gif|webp))$#D'; }

    public static function reinigen(string $html): string {
        if (strlen($html) > self::MAX) throw new \InvalidArgumentException('Das Dokument ist zu groß (höchstens 2 MB Text).');
        if (!mb_check_encoding($html, 'UTF-8')) $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $html) ?? '';
        if (trim($html) === '') return '';
        $wurzel = self::laden($html);
        $out = '';
        $inline = '';
        foreach (iterator_to_array($wurzel->childNodes) as $n) {
            $teil = self::knoten($n, 0);
            if ($teil === '') continue;
            if (self::istBlockText($teil)) {
                if (trim(strip_tags($inline, '<img>')) !== '') $out .= '<p>' . trim($inline) . '</p>';
                $inline = '';
                $out .= $teil;
            } else {
                $inline .= $teil;
            }
        }
        if (trim(strip_tags($inline, '<img>')) !== '') $out .= '<p>' . trim($inline) . '</p>';
        return preg_replace('#<p>\s*</p>#', '', $out) ?? $out;   // leere Absätze aus dem Editor
    }

    /** Reiner Text für Suche und Vergleich. */
    public static function text(string $html): string {
        $t = preg_replace('#<(br|/p|/h[2-4]|/li|/tr|/td|/th|/blockquote)[^>]*>#i', "\n", $html) ?? '';
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t\u{00A0}]+/u", ' ', $t) ?? '';
        return trim(preg_replace("/\n\s*\n+/", "\n", $t) ?? '');
    }

    /**
     * Gültige Fassung mit Markierung der Abschnitte, die es in der Vorversion nicht gab (Klasse „neu“). Vergleich je Abschnitt der obersten Ebene
     * (Absatz, Überschrift, Liste, Tabelle) über den Text bzw. die Bilder; liefert [html, anzahl geändert, anzahl entfallen].
     * @return array{0:string,1:int,2:int}
     */
    public static function aenderungenMarkieren(string $neu, string $alt): array {
        if (trim($neu) === '') return [$neu, 0, 0];
        $alteSchluessel = [];
        foreach (self::laden($alt)->childNodes as $n) if ($n instanceof \DOMElement) $alteSchluessel[self::schluessel($n)] = ($alteSchluessel[self::schluessel($n)] ?? 0) + 1;
        $wurzel = self::laden($neu);
        $geaendert = 0;
        $gleich = 0;
        foreach ($wurzel->childNodes as $n) {
            if (!$n instanceof \DOMElement) continue;
            $k = self::schluessel($n);
            if (($alteSchluessel[$k] ?? 0) > 0) { $alteSchluessel[$k]--; $gleich++; continue; }
            $n->setAttribute('class', trim($n->getAttribute('class') . ' neu'));
            $geaendert++;
        }
        $html = '';
        foreach ($wurzel->childNodes as $n) $html .= $wurzel->ownerDocument->saveHTML($n);
        return [$html, $geaendert, array_sum($alteSchluessel)];
    }

    private static function schluessel(\DOMElement $n): string {
        $bilder = [];
        foreach ($n->getElementsByTagName('img') as $i) $bilder[] = $i->getAttribute('src');
        $t = mb_strtolower(preg_replace('/\s+/u', ' ', trim($n->textContent)) ?? '');
        return $n->nodeName . '|' . $t . '|' . implode(',', $bilder);
    }

    private static function laden(string $html): \DOMElement {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $alt = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><!DOCTYPE html><html><body><div id="qm-wurzel">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
        $w = $doc->getElementById('qm-wurzel');
        if (!$w) { $w = $doc->createElement('div'); $doc->appendChild($w); }
        return $w;
    }

    private static function istBlockText(string $teil): bool { return (bool)preg_match('#^<(p|h[2-4]|ul|ol|table|blockquote|hr)[\s>/]#', $teil); }

    private static function knoten(\DOMNode $n, int $tiefe): string {
        if ($tiefe > 40) return '';
        if ($n instanceof \DOMText) return htmlspecialchars($n->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        if (!$n instanceof \DOMElement) return '';
        $tag = strtolower($n->nodeName);
        if (in_array($tag, self::WEG, true)) return '';
        $tag = self::UMBENENNEN[$tag] ?? $tag;
        $kinder = '';
        foreach ($n->childNodes as $k) $kinder .= self::knoten($k, $tiefe + 1);
        if ($tag === 'div' || $tag === 'section' || $tag === 'article') {
            return self::istBlockText(ltrim($kinder)) || trim($kinder) === '' ? $kinder : '<p>' . $kinder . '</p>';
        }
        if (!in_array($tag, self::BLOCK, true) && !in_array($tag, self::INLINE, true)) return $kinder;   // span, font, … ohne Hülle
        $attr = '';
        if ($tag === 'a') {
            $href = trim($n->getAttribute('href'));
            if (!preg_match('#^(https?://[^\s"<>]{1,1500}|mailto:[^\s"<>]{1,300}|/[a-z0-9-]{1,31}/dokument/[0-9]{1,9})$#iD', $href)) return $kinder;
            $attr = ' href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' . (str_starts_with($href, '/') ? '' : ' rel="noopener noreferrer" target="_blank"');
        } elseif ($tag === 'img') {
            if (!preg_match(self::bildMuster(), $n->getAttribute('src'), $m)) return '';
            $attr = ' src="' . qm_basis() . '/bild/' . $m[1] . '" alt="' . htmlspecialchars(mb_substr($n->getAttribute('alt'), 0, 200), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        } elseif ($tag === 'td' || $tag === 'th') {
            foreach (['colspan', 'rowspan'] as $a) {
                $w = (int)$n->getAttribute($a);
                if ($w > 1 && $w <= 50) $attr .= ' ' . $a . '="' . $w . '"';
            }
        } elseif ($tag === 'ul' && preg_match('/\bcheckliste\b/', $n->getAttribute('class'))) {
            $attr = ' class="checkliste"';
        } elseif ($tag === 'li' && preg_match('/\berledigt\b/', $n->getAttribute('class'))) {
            $attr = ' class="erledigt"';
        }
        if (in_array($tag, self::LEER, true)) return '<' . $tag . $attr . '>';
        if ($tag === 'li' && !in_array(strtolower((string)$n->parentNode?->nodeName), ['ul', 'ol'], true)) return '<p>' . $kinder . '</p>';
        if (in_array($tag, ['tr', 'thead', 'tbody'], true)) $kinder = self::nurElemente($kinder);
        if (in_array($tag, ['p', 'h2', 'h3', 'h4'], true) && self::istBlockText(ltrim($kinder))) return $kinder;   // verschachtelte Blöcke auflösen
        if (in_array($tag, ['ul', 'ol'], true)) {
            $kinder = self::nurElemente($kinder);
            if (trim($kinder) === '') return '';
        }
        if ($tag === 'table') {
            $kinder = self::nurElemente($kinder);
            if (trim($kinder) === '') return '';
        }
        return '<' . $tag . $attr . '>' . $kinder . '</' . $tag . '>';
    }

    /** Text zwischen Tabellen- bzw. Listenelementen (Leerraum aus dem Original) entfernen. */
    private static function nurElemente(string $html): string { return preg_replace('#>\s+<#', '><', trim($html)) ?? $html; }
}
