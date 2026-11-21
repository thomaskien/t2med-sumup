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
        $mail->isSMTP(); $mail->Host = $this->config->get('mail', 'host'); $mail->Port = $this->config->get('mail', 'port');
        $mail->SMTPAuth = $this->config->get('mail', 'username', '') !== '';
        $mail->Username = $this->config->get('mail', 'username', ''); $mail->Password = $this->config->get('mail', 'password', '');
        $mail->SMTPSecure = $this->config->get('mail', 'encryption') === 'smtps' ? 'ssl' : 'tls';
        $mail->Timeout = 15; $mail->getSMTPInstance()->Timelimit = 20; $mail->SMTPDebug = 0;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->config->get('mail', 'from_address'), $this->config->get('mail', 'from_name', ''));
        $mail->addAddress($email);
        $mail->Subject = 'Ihr Zahlungsbeleg';
        $mail->Body = "Guten Tag,\r\n\r\nanbei erhalten Sie Ihren Zahlungsbeleg als PDF.\r\n\r\nMit freundlichen Grüßen\r\n" . $this->config->get('mail', 'from_name', '');
        $mail->addStringAttachment($pdf, 'Zahlungsbeleg-' . preg_replace('/[^A-Za-z0-9_-]/', '', $reference) . '.pdf', 'base64', 'application/pdf');
        return $mail;
    }
    public function send(string $email, string $pdf, string $reference): void {
        try { $this->message($email, $pdf, $reference)->send(); }
        catch (\PHPMailer\PHPMailer\Exception) {
            // SMTP-Rohfehler können Adressen oder Zugangsdaten enthalten.
            throw new Problem('Der Mailserver hat den Versand nicht bestätigt. SMTP-Einstellungen und Empfängerpostfach prüfen, bevor erneut gesendet wird.', 502);
        }
    }
}
