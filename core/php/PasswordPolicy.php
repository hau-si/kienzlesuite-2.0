<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** Passwortregeln, die ein Admin unter Verwaltung → Sicherheit einstellt (in `settings` gespeichert), samt Warnungen bei zu lockeren Werten. */
final class PasswordPolicy {
    public const DEFAULTS = ['min' => 10, 'klassen' => 1, 'staerke' => 0, 'schwach' => true, 'login' => true];
    public const STAERKE = ['Keine Mindeststärke', 'Mindestens „schwach“ (Stufe 1)', 'Mindestens „mittel“ (Stufe 2)', 'Mindestens „gut“ (Stufe 3)', 'Nur „sehr gut“ (Stufe 4)'];

    /** @return array{min:int,klassen:int,staerke:int,schwach:bool,login:bool} */
    public static function load(Database $db): array {
        $p = self::DEFAULTS;
        $raw = json_decode((string)$db->setting('password_policy'), true);
        if (is_array($raw)) $p = self::normalize($raw) ?? $p;
        return $p;
    }

    /** Prüft und bereinigt Eingaben; null, wenn ein Wert ungültig ist. */
    public static function normalize(array $in): ?array {
        $min = $in['min'] ?? self::DEFAULTS['min']; $klassen = $in['klassen'] ?? 1; $staerke = $in['staerke'] ?? 0;
        foreach ([$min, $klassen, $staerke] as $v) if (!is_int($v) && !(is_string($v) && preg_match('/^\d{1,3}$/D', $v))) return null;
        [$min, $klassen, $staerke] = [(int)$min, (int)$klassen, (int)$staerke];
        if ($min < 6 || $min > 64 || $klassen < 1 || $klassen > 4 || $staerke < 0 || $staerke > 4) return null;
        return ['min' => $min, 'klassen' => $klassen, 'staerke' => $staerke, 'schwach' => !empty($in['schwach']), 'login' => !empty($in['login'])];
    }

    public static function save(Database $db, array $in): void {
        $p = self::normalize($in);
        if ($p === null) throw new Problem('Ungültige Passwortregeln: Länge 6 bis 64, Zeichenarten 1 bis 4, Stärke 0 bis 4.', 422);
        $db->store('password_policy', json_encode($p, JSON_THROW_ON_ERROR));
    }

    /** @return list<array{stufe:string,text:string}> stufe: rot (deutlich zu locker) oder gelb (besser anders) */
    public static function warnings(array $p): array {
        $w = [];
        if ($p['min'] < 8) $w[] = ['stufe' => 'rot', 'text' => 'Mindestlänge unter 8 Zeichen: Solche Passwörter sind schnell zu erraten. Empfohlen sind mindestens 12 Zeichen.'];
        elseif ($p['min'] < 10) $w[] = ['stufe' => 'gelb', 'text' => 'Mindestlänge unter 10 Zeichen. Empfohlen sind mindestens 12 Zeichen.'];
        if (!$p['schwach']) $w[] = ['stufe' => 'rot', 'text' => 'Die Sperre häufiger Passwörter („passwort“, „123456“, „qwertz“ …) ist aus. Damit werden die ersten Versuche eines Angreifers wahrscheinlich erfolgreich.'];
        if (!$p['login']) $w[] = ['stufe' => 'gelb', 'text' => 'Der Anmeldename darf im Passwort vorkommen. Das ist leicht zu erraten.'];
        if ($p['staerke'] === 0 && $p['klassen'] === 1 && $p['min'] < 12) $w[] = ['stufe' => 'gelb', 'text' => 'Es gibt weder eine Mindeststärke noch Anforderungen an die Zeichenarten, und die Länge liegt unter 12. Besser: Mindeststärke „mittel“ oder mindestens 12 Zeichen.'];
        return $w;
    }
}
