<?php
declare(strict_types=1);
namespace KienzleHomepage;

/** Vertretungspraxen: einmal angelegt, in Mitteilungen „Vertretung“ nur noch angekreuzt. */
final class Praxen {
    public const MAX = 100;
    private const FELDER = ['name' => 120, 'strasse' => 120, 'ort' => 80, 'telefon' => 40, 'zusatz' => 200];

    /** @return list<array<string,mixed>> nach Namen sortiert */
    public static function alle(): array {
        return Speicher::pdo()->query('SELECT * FROM praxen ORDER BY name COLLATE NOCASE, id')->fetchAll();
    }

    public static function eine(int $id): ?array {
        $s = Speicher::pdo()->prepare('SELECT * FROM praxen WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** Anlegen ($id = 0) oder ändern; liefert die ID. */
    public static function speichern(int $id, array $e): int {
        $d = [];
        foreach (self::FELDER as $f => $max) {
            $w = trim(preg_replace('/\s+/u', ' ', (string)($e[$f] ?? '')) ?? '');
            if (mb_strlen($w) > $max) throw new \InvalidArgumentException('Eingabe zu lang (höchstens ' . $max . ' Zeichen).');
            $d[$f] = $w;
        }
        if ($d['name'] === '') throw new \InvalidArgumentException('Bitte den Namen der Praxis eintragen.');
        if ($d['telefon'] !== '' && !preg_match('#^[0-9+()/ .\-]{3,40}$#D', $d['telefon'])) throw new \InvalidArgumentException('Die Telefonnummer enthält ungültige Zeichen.');
        $pdo = Speicher::pdo();
        if ($id > 0) {
            if (!self::eine($id)) throw new \InvalidArgumentException('Praxis nicht gefunden.');
            $pdo->prepare('UPDATE praxen SET name = ?, strasse = ?, ort = ?, telefon = ?, zusatz = ?, geaendert = ? WHERE id = ?')
                ->execute([$d['name'], $d['strasse'], $d['ort'], $d['telefon'], $d['zusatz'], time(), $id]);
            return $id;
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM praxen')->fetchColumn() >= self::MAX) throw new \InvalidArgumentException('Höchstens ' . self::MAX . ' Praxen.');
        $pdo->prepare('INSERT INTO praxen (name, strasse, ort, telefon, zusatz, erstellt, geaendert) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$d['name'], $d['strasse'], $d['ort'], $d['telefon'], $d['zusatz'], time(), time()]);
        return (int)$pdo->lastInsertId();
    }

    /** Löschen; nicht, solange eine noch nicht abgelaufene Mitteilung die Praxis nennt. */
    public static function loeschen(int $id): void {
        $p = self::eine($id);
        if (!$p) throw new \InvalidArgumentException('Praxis nicht gefunden.');
        foreach (Mitteilungen::alle() as $m) {
            if (in_array($id, $m['praxen'], true) && Mitteilungen::zustand($m) !== 'abgelaufen') {
                throw new \InvalidArgumentException('„' . $p['name'] . '“ steht noch in der Mitteilung „' . Mitteilungen::titel($m) . '“. Bitte dort zuerst abwählen.');
            }
        }
        foreach (Oeffnung::dienste() as $d) {
            if ($d['praxis'] === $id) throw new \InvalidArgumentException('„' . $p['name'] . '“ steht noch beim Bereitschaftsdienst (' . Oeffnung::wannText($d['wann'], $d['datum']) . '). Bitte dort zuerst ändern.');
        }
        $pdo = Speicher::pdo();
        $pdo->beginTransaction();
        foreach (Mitteilungen::alle() as $m) {
            if (in_array($id, $m['praxen'], true)) {
                $pdo->prepare('UPDATE mitteilungen SET praxen = ? WHERE id = ?')->execute([json_encode(array_values(array_diff($m['praxen'], [$id]))), $m['id']]);
            }
        }
        $pdo->prepare('DELETE FROM praxen WHERE id = ?')->execute([$id]);
        $pdo->commit();
    }

    /** Eine Zeile für die Homepage: „Name, Straße, Ort, Tel. …“ (+ Zusatz). */
    public static function zeile(array $p): string {
        $teile = array_filter([$p['name'], $p['strasse'], $p['ort'], $p['telefon'] !== '' ? 'Tel. ' . $p['telefon'] : '']);
        return implode(', ', $teile) . ($p['zusatz'] !== '' ? ' – ' . $p['zusatz'] : '');
    }

    /** Kurz für die Leiste: „Name (Tel. …)“. */
    public static function kurz(array $p): string {
        return $p['name'] . ($p['telefon'] !== '' ? ' (Tel. ' . $p['telefon'] . ')' : '');
    }
}
