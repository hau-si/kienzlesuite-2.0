<?php
declare(strict_types=1);
namespace KienzleTelefon;

/**
 * Seite „Anrufe“: Live-Übersicht (wer wartet, wer telefoniert, welche Telefone sind frei) und Wählen per Klick.
 * Daten kommen bei jedem Abruf frisch aus Asterisk (AMI: CoreShowChannels, DeviceStateList, QueueStatus); gespeichert wird
 * nichts außer der Zuordnung Benutzer → eigenes Telefon (<daten>/plaetze.json). Wählen: Asterisk ruft zuerst das eigene Telefon an
 * und wählt nach dem Abheben im Kontext der Telefone (kfx-phone-local) – es gelten dieselben Regeln wie beim Wählen am Telefon.
 */
final class Anrufe {
    /** @var callable|null Nur Tests: statt AMI; bekommt den Aktionsnamen und die Felder, liefert die Nachrichten. */
    public static $ami = null;

    private static function aktion(?Ami &$ami, string $name, array $felder = []): array {
        if (self::$ami) return (self::$ami)($name, $felder);
        $ami ??= Ami::verbinden();
        return $ami->aktion($name, $felder);
    }

    /** Aktueller Stand für die Seite. */
    public static function stand(array $cfg): array {
        $ami = null;
        try {
            $kanaele = self::aktion($ami, 'CoreShowChannels');
            $geraete = self::aktion($ami, 'DeviceStateList');
            $schlange = self::aktion($ami, 'QueueStatus');
        } catch (\RuntimeException $e) {
            return ['laeuft' => false, 'fehler' => $e->getMessage(), 'telefone' => [], 'warteschlange' => [], 'sprachmenue' => 0];
        }
        return ['laeuft' => true] + self::auswerten($cfg['nebenstellen'] ?? [], $kanaele, $geraete, $schlange);
    }

    /** Antworten von Asterisk zu Telefonen, Wartenden und Anrufern im Sprachmenü zusammenführen. */
    public static function auswerten(array $nebenstellen, array $kanaele, array $geraete, array $schlange): array {
        $zustand = [];
        foreach ($geraete as $g) {
            if (strtolower($g['event'] ?? '') === 'devicestatechange' && preg_match('#^PJSIP/(\d{2,8})$#D', (string)($g['device'] ?? ''), $m)) $zustand[$m[1]] = strtoupper((string)($g['state'] ?? ''));
        }
        $gespraech = [];
        $menue = 0;
        foreach ($kanaele as $c) {
            if (strtolower($c['event'] ?? '') !== 'coreshowchannel') continue;
            if (preg_match('#^PJSIP/(\d{2,8})-#', (string)($c['channel'] ?? ''), $m)) {
                $partner = (string)($c['connectedlinenum'] ?? '');
                if (in_array(strtolower($partner), ['', '<unknown>', 's'], true) || $partner === $m[1]) $partner = (string)($c['exten'] ?? '');
                $gespraech[$m[1]] = ['partner' => self::nummerZeigen($partner), 'dauer' => (string)($c['duration'] ?? ''), 'zustand' => (string)($c['channelstatedesc'] ?? '')];
            } elseif (str_starts_with((string)($c['context'] ?? ''), 'kienzlefon') && !str_starts_with((string)($c['channel'] ?? ''), 'Local/')) {
                $menue++;
            }
        }
        $telefone = [];
        foreach ($nebenstellen as $n) {
            $nr = (string)($n['nummer'] ?? '');
            $s = $zustand[$nr] ?? 'UNKNOWN';
            $g = $gespraech[$nr] ?? null;
            $art = match (true) {
                in_array($s, ['RINGING', 'RINGINUSE'], true) || ($g && in_array($g['zustand'], ['Ring', 'Ringing'], true)) => 'klingelt',
                in_array($s, ['INUSE', 'BUSY', 'ONHOLD'], true) || $g !== null => 'belegt',
                $s === 'NOT_INUSE' => 'frei',
                default => 'aus',
            };
            $telefone[] = ['nummer' => $nr, 'name' => (string)($n['name'] ?? ''), 'art' => $art, 'partner' => $g['partner'] ?? '', 'dauer' => $art === 'belegt' ? self::dauer($g['dauer'] ?? '') : ''];
        }
        $warten = [];
        foreach ($schlange as $q) {
            if (strtolower($q['event'] ?? '') !== 'queueentry') continue;
            $warten[] = ['schlange' => (string)($q['queue'] ?? ''), 'position' => (int)($q['position'] ?? 0), 'nummer' => self::nummerZeigen((string)($q['calleridnum'] ?? '')),
                'wahl' => self::nummerNormal((string)($q['calleridnum'] ?? '')) ?? '', 'warten' => self::dauer((int)($q['wait'] ?? 0))];
        }
        usort($warten, fn($a, $b) => [$a['schlange'] !== 'praxis', $a['position']] <=> [$b['schlange'] !== 'praxis', $b['position']]);
        return ['telefone' => $telefone, 'warteschlange' => $warten, 'sprachmenue' => $menue];
    }

    private static function dauer(string|int $wert): string {
        if (is_string($wert) && preg_match('/^(\d+):(\d\d):(\d\d)$/D', $wert, $m)) $wert = (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
        $s = max(0, (int)$wert);
        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    public static function nummerZeigen(string $nr): string {
        $nr = trim($nr);
        return in_array(strtolower($nr), ['', 'unknown', '<unknown>', 'anonymous', 'unbekannt'], true) ? 'unbekannt' : $nr;
    }

    /** Nummer zum Wählen: Ziffern; +49… → 0…, +… → 00…; sonst null. */
    public static function nummerNormal(string $nr): ?string {
        $nr = preg_replace('/[\s\/().-]/', '', $nr) ?? '';
        if (str_starts_with($nr, '+49')) $nr = '0' . substr($nr, 3);
        elseif (str_starts_with($nr, '+')) $nr = '00' . substr($nr, 1);
        return preg_match('/^[0-9]{3,20}$/D', $nr) ? $nr : null;
    }

    // ---- Eigenes Telefon je Benutzer ---------------------------------------------------------------------------------
    private static function plaetze(): array {
        $d = json_decode((string)@file_get_contents(Anlage::$data . '/plaetze.json'), true);
        return is_array($d) ? $d : [];
    }

    public static function meinTelefon(\KienzleModul $k, array $cfg): string {
        $nr = (string)(self::plaetze()[$k->benutzer()] ?? '');
        return in_array($nr, array_column($cfg['nebenstellen'] ?? [], 'nummer'), true) ? $nr : '';
    }

    public static function meinTelefonSetzen(\KienzleModul $k, array $cfg, string $nr): void {
        if ($nr !== '' && !in_array($nr, array_column($cfg['nebenstellen'] ?? [], 'nummer'), true)) throw new \InvalidArgumentException('Unbekanntes Telefon.');
        $p = self::plaetze();
        if ($nr === '') unset($p[$k->benutzer()]); else $p[$k->benutzer()] = $nr;
        $json = (string)json_encode($p);
        $tmp = Anlage::$data . '/.plaetze.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $json);
        chmod($tmp, 0600);
        rename($tmp, Anlage::$data . '/plaetze.json');
    }

    /** Wählen: erst klingelt das eigene Telefon, nach dem Abheben wählt die Anlage die Nummer. */
    public static function waehlen(\KienzleModul $k, array $cfg, string $nummer): string {
        $eigen = self::meinTelefon($k, $cfg);
        if ($eigen === '') throw new \InvalidArgumentException('Bitte zuerst oben „Mein Telefon“ wählen.');
        $nr = self::nummerNormal($nummer);
        if ($nr === null) throw new \InvalidArgumentException('Bitte eine Telefonnummer eingeben (nur Ziffern, + am Anfang erlaubt).');
        $ami = null;
        $r = self::aktion($ami, 'Originate', ['Channel' => 'PJSIP/' . $eigen, 'Context' => 'kfx-phone-local', 'Exten' => $nr, 'Priority' => 1,
            'CallerID' => 'Wählen <' . $nr . '>', 'Timeout' => 30000, 'Async' => 'true']);
        if (strtolower($r[0]['response'] ?? '') !== 'success') throw new \RuntimeException('Die Telefonanlage hat das Wählen abgelehnt.');
        return 'Ihr Telefon ' . $eigen . ' klingelt gleich. Nach dem Abheben wird ' . $nr . ' gewählt.';
    }
}
