<?php
declare(strict_types=1);
namespace KienzleSumup;

class ReceiptMailer {
    public function __construct(private Config $config) {}
    /** Systempaket (per apt aktuell gehalten) zuerst, sonst die mitgelieferte Kopie in src/vendor/phpmailer. */
    public static function loadLibrary(): void {
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false)) return;
        foreach (['/usr/share/php/libphp-phpmailer/autoload.php', __DIR__ . '/vendor/phpmailer/autoload.php'] as $autoload) {
            if (!is_file($autoload)) continue;
            require_once $autoload;
            if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) return;
        }
        throw new Problem('Mailbibliothek fehlt (src/vendor/phpmailer). Bitte die Anwendung neu installieren oder aktualisieren.', 503);
    }
    public function message(string $email, string $pdf, string $reference): \PHPMailer\PHPMailer\PHPMailer {
        if (!$this->config->get('mail', 'enabled', false)) throw new Problem('E-Mail-Versand ist noch nicht eingerichtet (Einstellungen → E-Mail-Versand).', 409);
        self::loadLibrary();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) throw new Problem('Bitte eine gültige E-Mail-Adresse eingeben.');
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        if ($this->config->get('mail', 'art', 'smtp') !== 'gmail') {
            $mail->isSMTP(); $mail->Host = $this->config->get('mail', 'host'); $mail->Port = $this->config->get('mail', 'port');
            $mail->SMTPAuth = $this->config->get('mail', 'username', '') !== '';
            $mail->Username = $this->config->get('mail', 'username', ''); $mail->Password = $this->config->get('mail', 'password', '');
            $mail->SMTPSecure = $this->config->get('mail', 'encryption') === 'smtps' ? 'ssl' : 'tls';
            $mail->Timeout = 15; $mail->getSMTPInstance()->Timelimit = 20; $mail->SMTPDebug = 0;
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
        }
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->config->get('mail', 'from_address'), $this->config->get('mail', 'from_name', ''));
        $mail->addAddress($email);
        $mail->Subject = 'Ihr Zahlungsbeleg';
        $mail->Body = "Guten Tag,\r\n\r\nanbei erhalten Sie Ihren Zahlungsbeleg als PDF.\r\n\r\nMit freundlichen Grüßen\r\n" . $this->config->get('mail', 'from_name', '');
        $mail->addStringAttachment($pdf, 'Zahlungsbeleg-' . preg_replace('/[^A-Za-z0-9_-]/', '', $reference) . '.pdf', 'base64', 'application/pdf');
        return $mail;
    }
    /** Gmail: Nachricht mit PHPMailer bauen (ohne SMTP) und über die Gmail-Schnittstelle senden. Fehlertexte ohne Schlüssel. */
    private function sendGmail(\PHPMailer\PHPMailer\PHPMailer $mail): void {
        $mail->preSend();
        $raw = rtrim(strtr(base64_encode($mail->getSentMIMEMessage()), '+/', '-_'), '=');
        $token = $this->googlePost('https://oauth2.googleapis.com/token', http_build_query(['client_id' => $this->config->get('mail', 'client_id'), 'client_secret' => $this->config->get('mail', 'client_secret'),
            'refresh_token' => $this->config->get('mail', 'refresh_token'), 'grant_type' => 'refresh_token']), ['Content-Type: application/x-www-form-urlencoded']);
        if (!is_string($token['access_token'] ?? null) || $token['access_token'] === '') throw new Problem('Google hat die Anmeldung der Gmail-Verbindung abgelehnt. Bitte unter Verwaltung → Verbindungen neu anmelden.', 502);
        $this->googlePost('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', json_encode(['raw' => $raw], JSON_THROW_ON_ERROR), ['Authorization: Bearer ' . $token['access_token'], 'Content-Type: application/json']);
    }
    protected function googlePost(string $url, string $body, array $headers): array {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $out = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($out === false || $status < 200 || $status >= 300) throw new Problem('Gmail hat den Versand nicht bestätigt (HTTP ' . $status . '). Verbindung unter Verwaltung → Verbindungen prüfen, bevor erneut gesendet wird.', 502);
        $json = json_decode((string)$out, true);
        return is_array($json) ? $json : [];
    }
    public function send(string $email, string $pdf, string $reference): void {
        try {
            $mail = $this->message($email, $pdf, $reference);
            if ($this->config->get('mail', 'art', 'smtp') === 'gmail') $this->sendGmail($mail); else $mail->send();
        }
        catch (\PHPMailer\PHPMailer\Exception) {
            // SMTP-Rohfehler können Adressen oder Zugangsdaten enthalten.
            throw new Problem('Der Mailserver hat den Versand nicht bestätigt. SMTP-Einstellungen und Empfängerpostfach prüfen, bevor erneut gesendet wird.', 502);
        }
    }
}
