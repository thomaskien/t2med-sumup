<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Config,ReceiptMailer,ReceiptPdf};

$values = Config::parse(file_get_contents(dirname(__DIR__).'/config/kienzle-sumup.example.toml'));
$values['app']['launcher_key'] = str_repeat('a',64); $values['app']['encryption_key'] = str_repeat('b',64);
$values['mail'] = ['enabled'=>true,'host'=>'smtp.example.invalid','port'=>587,'encryption'=>'starttls',
    'username'=>'smtp-test','password'=>'smtp-secret','from_address'=>'praxis@example.invalid','from_name'=>'Musterpraxis'];
$pdf = ReceiptPdf::convert('<svg xmlns="http://www.w3.org/2000/svg" width="300" height="100"><text x="20" y="40">TESTBELEG 16,00 EUR</text></svg>');
$message = (new ReceiptMailer(new Config($values)))->message('erika@example.invalid', $pdf, 'POS-TEST');
// Nur MIME erzeugen: keine SMTP-Verbindung und keine Nachricht versenden.
if (!$message->preSend()) throw new RuntimeException('MIME-Erzeugung fehlgeschlagen');
$mime = $message->getSentMIMEMessage();
foreach (['To: erika@example.invalid','Subject: Ihr Zahlungsbeleg','Content-Type: application/pdf','Zahlungsbeleg-POS-TEST.pdf'] as $part) {
    if (!str_contains($mime,$part)) throw new RuntimeException('Mailfeld fehlt: '.$part);
}
if (!str_contains(preg_replace('/\s/','',$mime),base64_encode($pdf))) throw new RuntimeException('PDF-Anhang beschädigt');
if (str_contains($mime,'smtp-secret') || str_contains($mime,'smtp-test')) throw new RuntimeException('SMTP-Zugangsdaten in E-Mail');
if ($message->SMTPSecure !== 'tls' || !$message->SMTPOptions['ssl']['verify_peer']) throw new RuntimeException('SMTP-TLS fehlt');
echo "OK: Mail-Empfänger, Betreff, unveränderter PDF-Anhang und SMTP-TLS; kein Versand.\n";
