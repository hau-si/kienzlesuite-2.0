<?php
declare(strict_types=1);
namespace KienzleSignatur;

/**
 * Patientenübergabe an ein anderes Modul (zuerst: Kasse), samt T2med-Zugang, damit dort z. B. eine Zahlung in die Akte geschrieben wird.
 * Weg: gemeinsamer Austauschordner (nur ks-signatur und ks-kasse) → uebergabe-kasse/<code>.json (0660, höchstens 5 Minuten gültig);
 * der Browser geht im selben Tab zu /kasse/#uebergabe=<code>, die Kasse löst einmalig ein und löscht die Datei.
 * Format und Regeln: docs/MODUL-SCHNITTSTELLE.md („Patientenübergabe zwischen Modulen“).
 */
class Uebergabe {
    public const GUELTIG = 300;

    public static function ordner(): string { return Ablage::$austausch . '/uebergabe-kasse'; }

    /** Ist die Kasse für eine Übergabe da (Austauschordner beider Module vorhanden)? */
    public static function kasseBereit(): bool { return is_dir(Ablage::$austausch); }

    /** Legt die Übergabe an und liefert den Code. Wirft, wenn der T2med-Zugang abgelaufen ist. */
    public static function anKasse(string $kontext, ?int $jetzt = null): string {
        $jetzt ??= time();
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $kontext)) throw new \InvalidArgumentException('Ungültiger T2med-Kontext.');
        $token = Speicher::tokenHolen($kontext, $jetzt);
        if ($token === null) throw new \InvalidArgumentException('Der T2med-Zugang ist abgelaufen (höchstens 4 Stunden nach dem Aufruf). Bitte die Akte frisch aus T2med öffnen.');
        if (!self::kasseBereit()) throw new \RuntimeException('Die Kasse ist nicht eingerichtet (gemeinsamer Ordner fehlt).');
        $ordner = self::ordner();
        if (!is_dir($ordner) && !@mkdir($ordner, 0770)) throw new \RuntimeException('Übergabeordner nicht anlegbar.');
        @chmod($ordner, 0770);
        foreach (glob($ordner . '/*.json') ?: [] as $alt) if (is_file($alt) && filemtime($alt) < $jetzt - self::GUELTIG) @unlink($alt);
        $code = bin2hex(random_bytes(16));
        $daten = json_encode(['kontext' => $kontext, 'token' => $token, 'bis' => Speicher::tokenBis($kontext), 'zeit' => $jetzt, 'von' => 'signatur'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $ordner . '/.' . $code . '.tmp';
        if (file_put_contents($tmp, $daten) !== strlen($daten)) { @unlink($tmp); throw new \RuntimeException('Übergabe nicht schreibbar.'); }
        chmod($tmp, 0660);   // die Kasse muss lesen und löschen dürfen (ACL des Austauschordners)
        rename($tmp, $ordner . '/' . $code . '.json');
        return $code;
    }
}
