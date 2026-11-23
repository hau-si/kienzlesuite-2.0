<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Gruppen für Benutzer (Vorlagen für Modulrechte): „Admin“, „Arzt“ und „MFA“ sind voreingestellt und änderbar.
 * Eine Gruppe nennt, in welchen Modulen ihre Mitglieder Admin sind und welche Rechte sie haben. Sie wird beim Speichern
 * eines Benutzers angewendet (dort einmal gewählt); spätere Änderungen an der Gruppe ändern bestehende Benutzer nicht.
 * Gespeichert wird nur, was ein Admin geändert hat; ohne Änderung gilt die Vorgabe, die neue Module von selbst einschließt.
 */
final class Groups {
    public const KEY = 'benutzer.gruppen';
    /** Rechte, die die Gruppe MFA von Haus aus bekommt (Kasse im Alltag); alle anderen Gruppen siehe defaults(). */
    private const MFA_RIGHTS = ['kassieren', 'paid', 'inbox'];

    public function __construct(private Database $db, private Modules $modules) {}

    /** @return array<string, array{admin:list<string>, rechte:list<string>}> Rechte als „modul:recht“ */
    public function defaults(): array {
        $all = [];
        $mfa = [];
        foreach (array_keys($this->modules->all()) as $m) {
            foreach ($this->modules->rights($m) as $r) {
                $all[] = $m . ':' . $r['id'];
                if (in_array($r['id'], self::MFA_RIGHTS, true)) $mfa[] = $m . ':' . $r['id'];
            }
        }
        return ['Admin' => ['admin' => array_keys($this->modules->all()), 'rechte' => $all], 'Arzt' => ['admin' => [], 'rechte' => $all], 'MFA' => ['admin' => [], 'rechte' => $mfa]];
    }

    /** Vorgaben, überlagert mit dem, was ein Admin gespeichert hat. */
    public function all(): array {
        $stored = json_decode((string)$this->db->setting(self::KEY), true);
        $out = $this->defaults();
        if (is_array($stored)) {
            foreach ($stored as $name => $g) {
                if (!is_string($name) || !$this->validName($name) || !is_array($g)) continue;
                $out[$name] = ['admin' => $this->only((array)($g['admin'] ?? []), array_keys($this->modules->all())), 'rechte' => $this->only((array)($g['rechte'] ?? []), $this->known())];
            }
        }
        return $out;
    }

    public function get(string $name): ?array { return $this->all()[$name] ?? null; }

    private function stored(): array {
        $d = json_decode((string)$this->db->setting(self::KEY), true);
        return is_array($d) ? $d : [];
    }

    public function set(string $name, array $admin, array $rechte): void {
        $name = trim($name);
        if (!$this->validName($name)) throw new Problem('Gruppenname: 1–30 Buchstaben, Ziffern, Leerzeichen oder Bindestrich.', 422);
        $stored = $this->stored();
        $stored[$name] = ['admin' => $admin, 'rechte' => $rechte];
        $this->save($stored);   // nur Geändertes speichern: unveränderte Vorgaben schließen neue Module weiter ein
    }

    /** Eigene Änderungen verwerfen: Vorgabe-Gruppen gelten wieder in der Vorgabe, eigene Gruppen verschwinden. */
    public function reset(string $name): void {
        $stored = $this->stored();
        unset($stored[$name]);
        $this->db->store(self::KEY, json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, array{admin:list<string>, rechte:list<string>}> $groups */
    public function save(array $groups): void {
        $clean = [];
        foreach ($groups as $name => $g) {
            $name = trim((string)$name);
            if (!$this->validName($name)) throw new Problem('Gruppenname: 1–30 Buchstaben, Ziffern, Leerzeichen oder Bindestrich.', 422);
            $clean[$name] = ['admin' => $this->only((array)($g['admin'] ?? []), array_keys($this->modules->all())), 'rechte' => $this->only((array)($g['rechte'] ?? []), $this->known())];
        }
        if (count($clean) > 30) throw new Problem('Höchstens 30 Gruppen.', 422);
        $this->db->store(self::KEY, json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** Alle Rechte aller Module als „modul:recht“. */
    public function known(): array {
        $out = [];
        foreach (array_keys($this->modules->all()) as $m) foreach ($this->modules->rights($m) as $r) $out[] = $m . ':' . $r['id'];
        return $out;
    }

    public function validName(string $name): bool { return (bool)preg_match('/^[\p{L}\p{N}][\p{L}\p{N} \-]{0,29}$/u', $name); }

    private function only(array $values, array $allowed): array { return array_values(array_intersect($allowed, array_map('strval', $values))); }
}
