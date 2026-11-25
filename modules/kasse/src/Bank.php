<?php
declare(strict_types=1);
namespace KienzleKasse;

/** Fest hinterlegte Kontodaten der Praxis für Überweisungen, samt EPC-QR-Code („GiroCode“) mit Betrag und Verwendungszweck. */
final class Bank {
    public function __construct(private readonly Config $config) {}

    public static function normalizeIban(string $iban): string { return strtoupper(preg_replace('/\s+/', '', $iban) ?? ''); }
    /** Prüft Aufbau und Prüfziffer (ISO 7064 Mod 97-10). */
    public static function validIban(string $iban): bool {
        $iban = self::normalizeIban($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $iban)) return false;
        $moved = substr($iban, 4) . substr($iban, 0, 4); $number = '';
        foreach (str_split($moved) as $char) $number .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
        $rest = 0;
        foreach (str_split($number, 7) as $part) $rest = (int)($rest . $part) % 97;
        return $rest === 1;
    }
    public function ready(): bool {
        return trim((string)$this->config->get('bank', 'holder', '')) !== '' && self::validIban((string)$this->config->get('bank', 'iban', ''));
    }
    /** Verwendungszweck: Vorlage mit {nummer} (Rechnungs- bzw. Vorgangsnummer) und {betrag}. */
    public function purpose(string $number, int $cents): string {
        $template = trim((string)$this->config->get('bank', 'purpose', '')) ?: 'Rechnung {nummer}';
        $text = strtr($template, ['{nummer}' => $number, '{betrag}' => Money::format($cents)]);
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text) ?? '', 0, 140);
    }
    /** EPC069-12-Nutzlast (Version 002, UTF-8, Euro). */
    public function epc(int $cents, string $purpose): string {
        $clean = static fn(string $v, int $max): string => mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $v) ?? ''), 0, $max);
        return implode("\n", ['BCD', '002', '1', 'SCT', strtoupper(preg_replace('/\s+/', '', (string)$this->config->get('bank', 'bic', '')) ?? ''),
            $clean((string)$this->config->get('bank', 'holder', ''), 70), self::normalizeIban((string)$this->config->get('bank', 'iban', '')),
            'EUR' . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT), '', '', $clean($purpose, 140)]);
    }
    /** Anzeigedaten samt QR-Code für die Oberfläche. */
    public function info(int $cents, string $number): array {
        $purpose = $this->purpose($number, $cents);
        $iban = self::normalizeIban((string)$this->config->get('bank', 'iban', ''));
        return ['holder' => (string)$this->config->get('bank', 'holder', ''), 'iban' => trim(chunk_split($iban, 4, ' ')),
            'bic' => (string)$this->config->get('bank', 'bic', ''), 'bank' => (string)$this->config->get('bank', 'bank_name', ''),
            'purpose' => $purpose, 'qr_svg' => Qr::svg($this->epc($cents, $purpose), 'class="transfer-qr" role="img" aria-label="QR-Code mit Betrag und Verwendungszweck"')];
    }
}
