<?php
declare(strict_types=1);
namespace KienzleSumup;

class ReceiptPrinter {
    public function __construct(private Config $config) {}
    public function prepare(string $source): string {
        if (!extension_loaded('gd')) throw new Problem('Druckaufbereitung fehlt. Bitte den Server-Installer mit aktiviertem Direktdruck ausführen (php-gd).', 503);
        return self::escpos(ReceiptPdf::raster($source, $this->config->get('printing', 'width_dots', 420)), $this->config->get('printing', 'cut', true));
    }
    /** Epson GS ( L: Grafik in Streifen speichern (112) und drucken (50). */
    public static function escpos(string $png, bool $cut): string {
        $size = @getimagesizefromstring($png);
        if (!$size || $size[2] !== IMAGETYPE_PNG || $size[0] < 1 || $size[0] > 420 || $size[1] < 1 || $size[1] > 12000) {
            throw new Problem('Der Beleg ist für den TM-m10 zu groß oder unlesbar.', 502);
        }
        $image = @imagecreatefromstring($png);
        if (!$image) throw new Problem('Belegbild konnte nicht gelesen werden.', 502);
        try {
            if (!imageistruecolor($image)) imagepalettetotruecolor($image);
            $width = imagesx($image); $height = imagesy($image); $rowBytes = intdiv($width + 7, 8);
            $data = "\x1b\x40"; // Initialisieren; keine gespeicherten Geräteeinstellungen ändern.
            for ($start = 0; $start < $height; $start += 128) {
                $rows = min(128, $height - $start); $bits = '';
                for ($y = $start; $y < $start + $rows; $y++) {
                    for ($byte = 0; $byte < $rowBytes; $byte++) {
                        $value = 0;
                        for ($bit = 0; $bit < 8; $bit++) {
                            $x = $byte * 8 + $bit;
                            if ($x >= $width) continue;
                            $pixel = imagecolorat($image, $x, $y);
                            $gray = ((($pixel >> 16) & 255) * 299 + (($pixel >> 8) & 255) * 587 + ($pixel & 255) * 114) / 1000;
                            $alpha = ($pixel >> 24) & 127;
                            $gray = $gray + (255 - $gray) * $alpha / 127;
                            if ($gray < 180) $value |= 128 >> $bit;
                        }
                        $bits .= chr($value);
                    }
                }
                $payload = "\x30\x70\x30\x01\x01\x31" . pack('vv', $width, $rows) . $bits;
                $data .= "\x1d\x28\x4c" . pack('v', strlen($payload)) . $payload . "\x1d\x28\x4c\x02\x00\x30\x32";
            }
            // Genau ein Schnitt im Auftrag; RAW-Queue hängt selbst keinen Schnitt an.
            return $data . ($cut ? "\x1d\x56\x42\x00" : "\x1b\x64\x03");
        } finally { unset($image); }
    }
    public function send(string $data): void {
        if (!$this->config->get('printing', 'enabled', false)) throw new Problem('Direktdruck ist deaktiviert.', 409);
        if (!is_executable('/usr/bin/smbclient')) throw new Problem('Samba-Druckclient fehlt. Bitte den Server-Installer mit aktiviertem Direktdruck ausführen.', 503);
        try {
            $result = Command::run(['/usr/bin/smbclient', $this->config->get('printing', 'share'), '-N', '-U', 'guest',
                '--option=client min protocol=SMB2', '--timeout=15', '-c', 'print -'], $data, 20, 65536);
            if ($result['code'] !== 0) throw new Problem('Samba hat die Druckübergabe nicht bestätigt.', 502);
        } catch (Problem) {
            throw new Problem('Druckübergabe nicht bestätigt. Freigabe, Gastzugriff und Warteschlange auf dem Druckserver prüfen, bevor erneut gedruckt wird.', 502);
        }
    }
}
