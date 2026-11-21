# PHPMailer 6.9.3 (mitgeliefert)

Quelle: https://github.com/PHPMailer/PHPMailer, Tag `v6.9.3`, unverändert.
Enthalten sind nur `Exception.php`, `PHPMailer.php` und `SMTP.php` (mehr braucht der SMTP-Versand nicht).
Lizenz: GNU LGPL 2.1 (siehe `LICENSE`).

Die Anwendung nimmt zuerst das Systempaket `libphp-phpmailer` (wird per `apt` aktuell gehalten);
diese Kopie wird nur verwendet, wenn das Systempaket fehlt (siehe `src/ReceiptMailer.php`).
Aktualisieren: die drei Dateien und `LICENSE` aus einem neuen PHPMailer-Release ersetzen, die Version hier eintragen.
