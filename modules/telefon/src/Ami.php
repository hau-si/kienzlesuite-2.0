<?php
declare(strict_types=1);
namespace KienzleTelefon;

/**
 * Kleiner AMI-Client für die Weboberfläche (Seite „Anrufe“). Zugang `kienzle-web` aus <daten>/ami-web.json (von lib/anlage.py
 * erzeugt; nur lesen, wählen, keine Systembefehle), nur 127.0.0.1. Ereignisse sind abgeschaltet; es kommen nur Antworten auf
 * die eigenen Aktionen. Kurze Verbindung je Seitenabruf.
 */
final class Ami {
    /** @var resource */
    private $s;
    private int $nr = 0;

    private function __construct($s) { $this->s = $s; }

    public static function verbinden(float $zeit = 2.0): self {
        $z = json_decode((string)@file_get_contents(Anlage::$data . '/ami-web.json'), true);
        if (!is_array($z) || !is_string($z['user'] ?? null) || !is_string($z['secret'] ?? null)) throw new \RuntimeException('Die Telefonanlage ist noch nicht eingerichtet.');
        $s = @stream_socket_client('tcp://127.0.0.1:' . (int)($z['port'] ?? 5038), $errno, $errstr, $zeit);
        if (!is_resource($s)) throw new \RuntimeException('Die Telefonanlage antwortet nicht.');
        stream_set_timeout($s, (int)ceil($zeit));
        fgets($s, 200);                                  // Begrüßung „Asterisk Call Manager/…“
        $a = new self($s);
        $r = $a->aktion('Login', ['Username' => $z['user'], 'Secret' => $z['secret'], 'Events' => 'off']);
        if (strtolower($r[0]['response'] ?? '') !== 'success') throw new \RuntimeException('Anmeldung an der Telefonanlage abgelehnt.');
        return $a;
    }

    public function __destruct() {
        if (is_resource($this->s)) { @fwrite($this->s, "Action: Logoff\r\n\r\n"); @fclose($this->s); }
    }

    /** Aktion senden; liefert die Antwort und bei Listen alle Ereignisse bis „…Complete“ (Schlüssel klein geschrieben). */
    public function aktion(string $name, array $felder = []): array {
        $id = 'ks' . (++$this->nr);
        $text = "Action: $name\r\nActionID: $id\r\n";
        foreach ($felder as $k => $v) $text .= $k . ': ' . str_replace(["\r", "\n"], '', (string)$v) . "\r\n";
        fwrite($this->s, $text . "\r\n");
        $out = [];
        $liste = false;
        while (count($out) < 2000) {
            $m = $this->lesen();
            if ($m === null) throw new \RuntimeException('Die Telefonanlage hat nicht vollständig geantwortet.');
            if (($m['actionid'] ?? '') !== $id) continue;
            $out[] = $m;
            if (isset($m['response'])) {
                $liste = strtolower($m['eventlist'] ?? '') === 'start';
                if (!$liste) return $out;
            } elseif ($liste && str_ends_with(strtolower($m['event'] ?? ''), 'complete')) {
                return $out;
            }
        }
        return $out;
    }

    private function lesen(): ?array {
        $m = [];
        while (($z = fgets($this->s, 8192)) !== false) {
            $z = rtrim($z, "\r\n");
            if ($z === '') { if ($m) return $m; continue; }
            $p = strpos($z, ':');
            if ($p !== false) $m[strtolower(substr($z, 0, $p))] = ltrim(substr($z, $p + 1));
        }
        return null;
    }
}
