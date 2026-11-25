<?php
declare(strict_types=1);
namespace KienzleSignatur;

/**
 * Pad-Software von signotec (signoPAD-API Linux): Die Bibliotheken liegen nicht im Repository, sondern werden vom Admin
 * über die Oberfläche hochgeladen. Aus der ZIP-Datei von signotec werden nur zwei Bibliotheken und der Lizenztext
 * übernommen (feste Dateinamen, nichts wird entpackt oder ausgeführt); beides wird vorher geprüft.
 */
final class Pad {
    /** Größe der hochgeladenen ZIP-Datei: die PHP-Instanz des Moduls nimmt höchstens 8 MB entgegen. */
    public const MAX_ZIP = 8000000;
    public const MAX_BIB = 20000000;
    public const BIBLIOTHEKEN = ['libSTPadLib.so', 'libSTCPImageEngine.so'];
    private const LIZENZ = 'licenses/signotec endnutzer lizenzvertrag.txt';

    private static function ordner(): string { return Speicher::$data . '/signotec'; }

    /** Stand der Pad-Software: installiert, Version, Zeitpunkt, Benutzer. */
    public static function status(): array {
        $info = json_decode((string)@file_get_contents(self::ordner() . '/info.json'), true);
        $da = true;
        foreach (self::BIBLIOTHEKEN as $b) $da = $da && is_file(self::ordner() . '/' . $b);
        if (!$da || !is_array($info)) return ['installiert' => false, 'version' => '', 'zeit' => 0, 'von' => ''];
        return ['installiert' => true, 'version' => (string)($info['version'] ?? ''), 'zeit' => (int)($info['zeit'] ?? 0), 'von' => (string)($info['von'] ?? '')];
    }

    public static function ordnerPfad(): string { return self::ordner(); }

    /** Eine Bibliothek muss eine 64-Bit-x86-ELF-Datei sein (Linux, x86-64). */
    public static function istElfX64(string $bytes): bool {
        return strlen($bytes) > 64 && strncmp($bytes, "\x7fELF", 4) === 0 && $bytes[4] === "\x02" && $bytes[5] === "\x01" && substr($bytes, 18, 2) === "\x3e\x00";
    }

    /**
     * Prüft die ZIP-Datei von signotec und legt die Bibliotheken ab.
     * @return array Neuer Stand wie status()
     * @throws \InvalidArgumentException mit Text für den Admin
     */
    public static function installieren(string $zipBytes, string $benutzer, bool $lizenzAkzeptiert, ?int $jetzt = null): array {
        if (!$lizenzAkzeptiert) throw new \InvalidArgumentException('Bitte zuerst bestätigen, dass Sie den Lizenzvertrag von signotec gelesen haben und akzeptieren.');
        if ($zipBytes === '') throw new \InvalidArgumentException('Bitte zuerst die ZIP-Datei auswählen.');
        if (strlen($zipBytes) > self::MAX_ZIP) throw new \InvalidArgumentException('Die Datei ist zu groß (höchstens 8 MB). Bitte die ZIP-Datei „signoPAD-API Linux“ von signotec verwenden.');
        if (strncmp($zipBytes, "PK\x03\x04", 4) !== 0) throw new \InvalidArgumentException('Das ist keine ZIP-Datei. Bitte die Datei „signoPAD-API_Linux_….zip“ von signotec auswählen.');
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Die PHP-Erweiterung zip fehlt (Paket php-zip). Bitte das Modul unter Verwaltung → Module aktualisieren.');
        if (!is_dir(Speicher::$data) && !mkdir(Speicher::$data, 0700, true) && !is_dir(Speicher::$data)) throw new \RuntimeException('Datenordner nicht anlegbar.');
        $tmp = tempnam(Speicher::$data, 'zip');
        if ($tmp === false || file_put_contents($tmp, $zipBytes) === false) throw new \RuntimeException('Zwischendatei nicht schreibbar.');
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) throw new \InvalidArgumentException('Die ZIP-Datei lässt sich nicht öffnen. Bitte erneut von signotec herunterladen.');
            if ($zip->numFiles > 5000) throw new \InvalidArgumentException('Die ZIP-Datei hat zu viele Einträge.');
            $teile = [];
            foreach (self::BIBLIOTHEKEN as $b) {
                $bytes = null;
                foreach (['libSTPadLib.so' => ['libSTPadLib.so/x64/', 'Demo/x64/'], 'libSTCPImageEngine.so' => ['libSTCPImageEngine.so/x64/', 'Demo/x64/']][$b] as $pfad) {
                    $i = $zip->locateName($pfad . $b, \ZipArchive::FL_NOCASE);
                    if ($i === false) continue;
                    $st = $zip->statIndex($i);
                    if (!$st || $st['size'] > self::MAX_BIB || $st['size'] < 1000) throw new \InvalidArgumentException("$b hat eine ungewöhnliche Größe.");
                    $bytes = (string)$zip->getFromIndex($i);
                    break;
                }
                if ($bytes === null || $bytes === '') throw new \InvalidArgumentException("In der ZIP-Datei fehlt $b. Bitte das Paket „signoPAD-API Linux“ von signotec verwenden.");
                if (!self::istElfX64($bytes)) throw new \InvalidArgumentException("$b ist keine Linux-Bibliothek für x86-64.");
                $teile[$b] = $bytes;
            }
            $version = '';
            $readme = (string)$zip->getFromName('ReadMe.txt');
            if (preg_match('/signoPAD-API\s+Linux\s+Release\s+(\d+(?:\.\d+){1,3})/i', $readme, $m)) $version = $m[1];
            $lizenz = (string)$zip->getFromName(self::LIZENZ);
            $zip->close();
        } finally {
            @unlink($tmp);
        }
        $ziel = self::ordner();
        if (!is_dir($ziel) && !mkdir($ziel, 0700, true) && !is_dir($ziel)) throw new \RuntimeException('Ordner für die Pad-Software nicht anlegbar.');
        foreach ($teile as $name => $bytes) {
            $t = $ziel . '/.neu-' . $name;
            if (file_put_contents($t, $bytes) === false || !@chmod($t, 0640) || !rename($t, $ziel . '/' . $name)) throw new \RuntimeException("$name nicht schreibbar.");
        }
        if ($lizenz !== '') file_put_contents($ziel . '/Lizenzvertrag-signotec.txt', $lizenz);
        $info = ['version' => $version, 'zeit' => $jetzt ?? time(), 'von' => $benutzer, 'lizenz_akzeptiert' => true,
            'sha256' => array_map(fn($b) => hash('sha256', $b), $teile)];
        file_put_contents($ziel . '/info.json', json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::status();
    }

    public static function entfernen(): void {
        foreach (array_merge(self::BIBLIOTHEKEN, ['info.json', 'Lizenzvertrag-signotec.txt']) as $f) @unlink(self::ordner() . '/' . $f);
        @rmdir(self::ordner());
    }
}
