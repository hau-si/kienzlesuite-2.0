<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Fortlaufende Belegnummern für in der App erzeugte Rechnungen (IGeL/GOÄ) und Stornorechnungen.
 * Format mit Platzhaltern: {JJJJ} Jahr vierstellig, {JJ} zweistellig, {MM} Monat, {NNNN} laufende Nummer (Anzahl N = Mindestbreite).
 * Jede vergebene Nummer wird dauerhaft gespeichert und nie wieder vergeben (auch nicht nach einem Storno).
 */
final class DocNumbers {
    public const DEFAULT_IGEL = 'IGEL-{JJJJ}-{NNNN}';
    public const DEFAULT_STORNO = 'STORNO-{JJJJ}-{NNNN}';

    public static function validate(string $format, string $name): void {
        if (mb_strlen($format) > 40 || !preg_match('/^[A-Za-z0-9ÄÖÜäöüß._\/ {}-]+$/u', $format)) throw new \RuntimeException($name . ': höchstens 40 Zeichen (Buchstaben, Ziffern, Leerzeichen und . _ / -) und die Platzhalter {JJJJ}, {JJ}, {MM}, {NNNN}.');
        $rest = preg_replace('/\{(JJJJ|JJ|MM|N{1,9})\}/', '', $format);
        if (str_contains((string)$rest, '{') || str_contains((string)$rest, '}')) throw new \RuntimeException($name . ': unbekannter Platzhalter. Erlaubt sind {JJJJ}, {JJ}, {MM} und {NNNN}.');
        if (preg_match_all('/\{N{1,9}\}/', $format) !== 1) throw new \RuntimeException($name . ': genau ein Platzhalter für die laufende Nummer ist nötig, z. B. {NNNN}.');
    }
    /** Beispielnummer für die Einstellungsseite. */
    public static function example(string $format, \DateTimeImmutable $now): string { return self::render($format, $now, 1); }

    private static function render(string $format, \DateTimeImmutable $now, int $n): string {
        return preg_replace_callback('/\{(JJJJ|JJ|MM|N+)\}/', static fn($m) => match (true) {
            $m[1] === 'JJJJ' => $now->format('Y'), $m[1] === 'JJ' => $now->format('y'), $m[1] === 'MM' => $now->format('m'),
            default => str_pad((string)$n, strlen($m[1]), '0', STR_PAD_LEFT),
        }, $format);
    }
    /** Nächste freie Nummer vergeben und sofort dauerhaft festhalten. Der Aufrufer sichert die Reihenfolge durch eine Sperre. */
    public static function issue(Database $db, string $kind, string $format, \DateTimeImmutable $now, bool $test, ?string $paymentId): string {
        self::validate($format, 'Nummernformat');
        $parts = explode('{N}', preg_replace('/\{N+\}/', '{N}', preg_replace_callback('/\{(JJJJ|JJ|MM)\}/', static fn($m) => self::render($m[0], $now, 0), $format)), 2);
        [$before, $after] = [($test ? 'TEST-' : '') . $parts[0], $parts[1] ?? ''];
        $last = 0;
        foreach ($db->query('SELECT number FROM doc_numbers WHERE kind=?', [$kind])->fetchAll(\PDO::FETCH_COLUMN) as $number) {
            if (preg_match('/^' . preg_quote($before, '/') . '(\d+)' . preg_quote($after, '/') . '$/D', (string)$number, $m)) $last = max($last, (int)$m[1]);
        }
        $width = strlen(preg_match('/\{(N+)\}/', $format, $w) ? $w[1] : 'N');
        $number = $before . str_pad((string)($last + 1), $width, '0', STR_PAD_LEFT) . $after;
        $db->query('INSERT INTO doc_numbers(number,kind,payment_id,created_at) VALUES(?,?,?,?)', [$number, $kind, $paymentId, time()]);
        return $number;
    }
}
