<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{ReceiptPdf,ReceiptPrinter,Problem};
function requirePrint(bool $ok,string $message):void {if(!$ok) throw new RuntimeException($message);}
// Raster über mehrere Streifen, Breite kein Vielfaches von 8; rechts bleiben vier Bits weiß.
$image=imagecreatetruecolor(420,260); imagefill($image,0,0,0xffffff);
imagesetpixel($image,0,0,0); imagesetpixel($image,419,259,0);
ob_start();imagepng($image);$png=ob_get_clean();unset($image);
$raw=ReceiptPrinter::escpos($png,true);$offset=2;$all='';$height=0;$blocks=0;
requirePrint(substr($raw,0,2)==="\x1b\x40",'Initialisierung fehlt');
while(substr($raw,$offset,3)==="\x1d\x28\x4c") {
    $size=unpack('v',substr($raw,$offset+3,2))[1];$payload=substr($raw,$offset+5,$size);
    requirePrint(substr($payload,0,6)==="\x30\x70\x30\x01\x01\x31",'Epson-Grafikparameter falsch');
    $dimensions=unpack('vwidth/vheight',substr($payload,6,4));
    requirePrint($dimensions['width']===420 && $dimensions['height']<=128,'Grafikabmessungen falsch');
    $bits=substr($payload,10);requirePrint(strlen($bits)===53*$dimensions['height'],'Rasterdatenlänge falsch');
    $all.=$bits;$height+=$dimensions['height'];$blocks++;$offset+=5+$size;
    requirePrint(substr($raw,$offset,7)==="\x1d\x28\x4c\x02\x00\x30\x32",'Grafikdruck-Befehl fehlt');$offset+=7;
}
requirePrint($height===260 && $blocks===3,'Grafik wurde abgeschnitten oder Streifen fehlen');
$expected=str_repeat("\0",53*260);$expected[0]="\x80";$expected[strlen($expected)-1]="\x10";
requirePrint($all===$expected,'Pixelreihenfolge, Farbbedeutung oder rechte Füllbits falsch');
requirePrint(substr($raw,$offset)==="\x1d\x56\x42\x00",'Genau ein abschließender Schnitt erwartet');
requirePrint(substr(ReceiptPrinter::escpos($png,false),$offset)==="\x1b\x64\x03",'Schnitt lässt sich nicht deaktivieren');
$scaled=ReceiptPdf::raster('<svg xmlns="http://www.w3.org/2000/svg" width="840" height="200"><rect width="840" height="200" fill="white"/><text x="20" y="80" font-size="48">TESTBELEG 16,00 EUR</text></svg>',420);
$d=getimagesizefromstring($scaled);requirePrint($d[0]===420 && $d[1]===100,'Beleg nicht proportional skaliert');
try {ReceiptPrinter::escpos('kein PNG',true);throw new RuntimeException('Ungültiges Bild akzeptiert');}catch(Problem){}
echo "OK: Proportionale Druckbreite, ESC/POS-Grafikstreifen, Pixel/Füllbits und optional genau ein Schnitt; kein Druckversand.\n";
