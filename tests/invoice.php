<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Config, Database, FhirClient, HttpClient, InvoiceReceipt, Problem, ReceiptPdf, ReceiptPrinter};
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message): void { try { $fn(); } catch (Problem) { return; } throw new RuntimeException($message); }
function visit(App $app, string $context): array {
    $launch=$app->launch(['context_id'=>$context,'oauth_token'=>'','fhir_base_url'=>'']);
    parse_str(parse_url($launch['url'],PHP_URL_FRAGMENT),$fragment);
    $exchange=$app->exchange($fragment['launch'],null); $browser=$app->browser($exchange['cookie']);
    return [$app->visit($browser,$exchange['visit_id']),$browser];
}
final class InvoiceHttp extends HttpClient {
    public array $calls=[], $body=[];
    public bool $missingId=false, $allowReceipt=false, $receiptUnavailable=false;
    public int $downloads=0;
    public string $original='<svg xmlns="http://www.w3.org/2000/svg" width="420" height="310"><rect width="420" height="310" fill="white"/><g font-family="sans-serif" fill="black"><text x="20" y="40" font-size="26">TEST: SumUp-Originalbeleg</text><text x="20" y="95" font-size="30">14,49 EUR</text><text x="20" y="140" font-size="22">Kartenzahlung erfolgreich</text><text x="20" y="190" font-size="22">TEST-Karte **** 1234</text><path d="M20 240 H400" stroke="black"/></g></svg>';
    public string $timestamp='2026-09-19T09:30:00Z';
    public function request(string $method,string $url,array $headers,?array $body=null,string $caFile='',bool $pinCertificate=false):array {
        $this->calls[]=[$method,$url,$body];
        if (str_contains($url,'/Patient?')) return ['status'=>200,'body'=>['resourceType'=>'Patient','id'=>'patient-1','name'=>[['given'=>['Erika'],'family'=>'Musterfrau']],
            'address'=>[['use'=>'old','line'=>['Alte Straße 1']],['use'=>'home','line'=>['Musterstraße 2'],'postalCode'=>'12345','city'=>'Musterstadt']]]];
        check(str_starts_with($url,'https://api.sumup.com/'),'Unerwarteter Netzwerkaufruf');
        if ($method==='POST' && str_ends_with($url,'/checkout')) {
            $this->body=$body;
            return ['status'=>201,'body'=>['data'=>['checkout_id'=>'checkout-1','client_transaction_id'=>'client-1']]];
        }
        if (str_contains($url,'/transactions?')) return ['status'=>200,'body'=>['id'=>$this->missingId ? '' : 'c31a51a8-02c8-42a9-a831-7df089a04b36','status'=>'SUCCESSFUL','client_transaction_id'=>'client-1',
            'merchant_code'=>'MTEST','currency'=>'EUR','amount'=>$this->body['total_amount']['value']/100,'timestamp'=>$this->timestamp,
            'links'=>[['rel'=>'receipt','href'=>'https://receipts.sumup.com/test-original']]]];
        throw new RuntimeException('Unerwarteter API-Pfad');
    }
    public function download(string $url):array {
        check($this->allowReceipt && $url==='https://receipts.sumup.com/test-original','Unerwarteter Originalbeleg-Abruf');
        $this->downloads++;
        return ['status'=>$this->receiptUnavailable ? 503 : 200,'body'=>$this->original];
    }
}
final class InvoicePrinter extends ReceiptPrinter {
    public int $sent=0;
    public function send(string $data):void {
        check(str_starts_with($data,"\x1b\x40\x1d\x28\x4c") && str_ends_with($data,"\x1d\x56\x42\x00"),'Lokaler Bon nicht als ESC/POS mit Schnitt');
        $this->sent++;
    }
}
$dir=sys_get_temp_dir().'/ks-invoice-'.bin2hex(random_bytes(6));
try {
    // Echte Migration von Version 1.3: bestehende Preise und historische Zahlungen erhalten.
    $db=new Database($dir); $db->pdo->exec(file_get_contents(__DIR__.'/fixtures/schema-v3.sql'));
    $db->pdo->exec('PRAGMA user_version=3');
    $oldId=App::id(); $db->query('INSERT INTO services(id,label,price_cents,updated_at) VALUES(?,?,?,?)',[$oldId,'Vorhandenes Attest',1000,time()]);
    $oldVisit=App::id(); $oldPayment=App::id();
    $db->query('INSERT INTO visits(id,context_id,patient_id,patient_name,oauth_cipher,created_at,expires_at) VALUES(?,?,?,?,?,?,?)',[$oldVisit,'legacy','legacy-patient','Altbestand','legacy-token',time(),time()+3600]);
    $db->query('INSERT INTO payments(id,visit_id,request_id,request_hash,reference,reader_id,amount_cents,services_json,payment_status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$oldPayment,$oldVisit,App::id(),'legacy-hash','POS-LEGACY','legacy-reader',1000,'[{"label":"Vorhandenes Attest","price_cents":1000}]','successful',time()]);
    $oldPaymentRow=$db->one('SELECT * FROM payments WHERE id=?',[$oldPayment]);
    $db->migrate(); $db->migrate();
    $old=$db->one('SELECT * FROM services WHERE id=?',[$oldId]);
    check($old['price_cents']===1000 && $old['goae_code']==='' && $old['factor']==='','Migration verändert Festpreis');
    check(in_array('invoice_json',array_column($db->query('PRAGMA table_info(payments)')->fetchAll(),'name'),true),'Belegspalte bei Upgrade fehlt');
    $migrated=$db->one('SELECT * FROM payments WHERE id=?',[$oldPayment]);
    foreach($oldPaymentRow as $key=>$value) check($migrated[$key]===$value,'Historische Zahlung bei Migration verändert');
    check($migrated['invoice_json']===null,'Nachträglich neue Rechnung für Altzahlung erzeugt');
    $values=Config::parse(file_get_contents(dirname(__DIR__).'/config/kienzle-sumup.example.toml'));
    $values['app']['state_dir']=$dir; $values['app']['launcher_key']=str_repeat('a',64); $values['app']['encryption_key']=str_repeat('b',64);
    $values['sumup']=['mode'=>'live','api_key'=>'test','affiliate_key'=>'test','app_id'=>'test.app','merchant_code'=>'MTEST','reader_id'=>'reader-test'];
    $values['practice']=['name'=>'Testpraxis Dr. Muster','street'=>'Praxisstraße 12','city'=>'12345 Musterstadt','contact'=>'Telefon 01234 56789'];
    $values['printing']=['enabled'=>true,'share'=>'//kienzlebox/TMm10','width_dots'=>420,'cut'=>true];
    $cfg=new Config($values); $http=new InvoiceHttp(); $printer=new InvoicePrinter($cfg); $app=new App($cfg,$http,null,$printer);
    [$v,$b]=visit($app,'invoice'); [$v2,$b2]=visit($app,'other');
    $serviceInputs=[
        ['label'=>'Blutentnahme','price'=>'4,19','goae_code'=>'250','factor'=>'1,8'],
        ['label'=>'Laborwert Eins','price'=>'3,50','goae_code'=>'3562','factor'=>'1,15','fee_type'=>'lab'],
        ['label'=>'Laborwert Zwei','price'=>'6,80','goae_code'=>'3563','factor'=>'1,15','fee_type'=>'lab'],
    ];
    $ids=[];
    foreach ($serviceInputs as $s) { $app->saveService($v,$s); $ids[]=$db->one('SELECT id FROM services WHERE label=?',[$s['label']])['id']; }
    $app->saveGroup($v,['label'=>'Laborprofil Eins','services'=>[$ids[0],$ids[1]]]);
    $app->saveGroup($v,['label'=>'Laborprofil Zwei','services'=>[$ids[0],$ids[2]]]);
    $groups=$db->query('SELECT id FROM service_groups ORDER BY label')->fetchAll(PDO::FETCH_COLUMN);
    rejects(fn()=>$app->saveGroup($v,['label'=>'Falsch','services'=>[$groups[0]]]),'Verschachtelte Kombination akzeptiert');
    rejects(fn()=>$app->deleteService($v,$ids[0]),'Enthaltene Leistung erzeugt kaputte Kombination');
    $input=['request_id'=>App::id(),'services'=>[$ids[0]],'groups'=>$groups,'reasons'=>[],'manual_amount'=>'','service_date'=>'2026-09-01'];
    $unconfigured=$values; $unconfigured['practice']=[];
    rejects(fn()=>(new App(new Config($unconfigured),$http))->start($v,$input),'GOÄ-Rechnung ohne Praxisdaten');
    $p=$app->start($v,$input+['amount_cents'=>1]);
    check($p['amount_cents']===1449 && $p['local_receipt'],'Kombinationen zählen Blutentnahme mehrfach oder verändern Festpreise');
    check($app->start($v,$input)['id']===$p['id'],'Doppelstart');
    $saved=$db->one('SELECT * FROM payments WHERE id=?',[$p['id']]);
    $invoice=json_decode($saved['invoice_json'],true,32,JSON_THROW_ON_ERROR);
    check(count($invoice['items'])===3 && count(array_filter($invoice['items'],fn($s)=>$s['id']===$ids[0]))===1,'Doppelte Rechnungsposition');
    check($invoice['service_date']==='2026-09-01' && $invoice['patient']['address']===['Musterstraße 1','12345 Musterstadt'],'Leistungsdatum oder Patientensnapshot fehlt');
    $body=$http->body;
    check(array_keys($body)===['total_amount','description','affiliate'] && $body['total_amount']['value']===1449,'Unerwartete SumUp-Nutzdaten');
    check($body['description']===$saved['reference'] && $body['affiliate']['foreign_transaction_id']===$saved['reference'],'Referenz nicht neutral');
    foreach (['Erika','Blut','Labor','GOÄ','3562','Musterstraße'] as $marker) check(!str_contains(json_encode($body,JSON_UNESCAPED_UNICODE),$marker),'Medizinische Angaben bei SumUp');
    rejects(fn()=>$app->receipt($v,$p['id']),'Bezahlter Bon vor Zahlungsbestätigung');
    $http->missingId=true;
    check($app->poll($v,$p['id'])['payment_status']==='pending','Erfolg ohne Rechnungsnummer angenommen');
    $http->missingId=false; $db->query('UPDATE payments SET checked_at=0 WHERE id=?',[$p['id']]);
    $p=$app->poll($v,$p['id']); check($p['invoice_number']==='c31a51a8-02c8-42a9-a831-7df089a04b36','Rechnungsnummer nicht SumUp-Transaktions-ID');
    check($p['paid_at']===strtotime($http->timestamp),'Zeitpunkt des Statusabrufs ersetzt SumUp-Zahlungszeit');
    $calls=count($http->calls); $pdf=$app->receipt($v,$p['id']);
    check(str_starts_with($pdf,'%PDF-') && count($http->calls)===$calls,'Lokaler PDF-Beleg benötigt SumUp oder ist kein PDF');
    rejects(fn()=>$app->receipt($v2,$p['id']),'Fremde Rechnung zugänglich');
    $print=['payment_id'=>$p['id'],'request_id'=>App::id()]; $app->receiptPrint($v,$print); $app->receiptPrint($v,$print);
    check($printer->sent===1 && count($http->calls)===$calls,'Druck doppelt oder medizinischer Bon extern angefordert');

    // Option gilt auch für bereits gespeicherte lokale Belege. Kein Teildruck bei Abruffehlern.
    $appendValues=$values; $appendValues['practice']['append_sumup_receipt']=true;
    $appendCfg=new Config($appendValues); $appendApp=new App($appendCfg,$http,null,$printer); $http->allowReceipt=true;
    check(str_starts_with($appendApp->receipt($v,$p['id']),'%PDF-') && $http->downloads===1,'Originalbeleg nicht in PDF übernommen');
    $appendPrint=['payment_id'=>$p['id'],'request_id'=>App::id()];
    $http->receiptUnavailable=true;
    rejects(fn()=>$appendApp->receiptPrint($v,$appendPrint),'Unvollständiger Bon gedruckt');
    check($printer->sent===1 && !$db->one('SELECT * FROM receipt_prints WHERE request_id=?',[$appendPrint['request_id']]),'Fehlgeschlagener Abruf übergibt einen Druckauftrag');
    $http->receiptUnavailable=false;
    $appendApp->receiptPrint($v,$appendPrint); $downloads=$http->downloads; $appendApp->receiptPrint($v,$appendPrint);
    check($printer->sent===2 && $http->downloads===$downloads,'Wiederholter Klick druckt erneut oder lädt Original erneut');
    check($db->one('SELECT invoice_json FROM payments WHERE id=?',[$p['id']])['invoice_json']===$saved['invoice_json'],'Belegoption verändert Rechnungsdaten');
    $badValues=$values; $badValues['practice']['append_sumup_receipt']='true';
    try { new Config($badValues); throw new LogicException('Belegoption akzeptiert Text statt Boolean'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(),'practice.append_sumup_receipt'),'Falscher Konfigurationsfehler'); }
    $app->saveService($v2,['id'=>$ids[0],'label'=>'Geänderter Text','price'=>'99,99','goae_code'=>'250','factor'=>'2,3']);
    $db->query('UPDATE visits SET patient_name=? WHERE id=?',['Geänderter Patient',$v['id']]);
    check($db->one('SELECT invoice_json FROM payments WHERE id=?',[$p['id']])['invoice_json']===$saved['invoice_json'],'Beleg nach Änderung nicht stabil');
    $app->document($v,$p['id']);
    $record=json_decode($db->one('SELECT resource_json FROM mock_records WHERE payment_id=?',[$p['id']])['resource_json'],true)['valueString'];
    foreach (['GOÄ 250, Faktor 1,8','Blutentnahme','Rechnungsnummer (SumUp): '.$p['invoice_number'],'Leistungsdatum: 2026-09-01'] as $text) check(str_contains($record,$text),'Rechnungsangabe fehlt im Akteneintrag');

    // Begründung gehört zum Patientenfall; Faktor ist reine Zusatzangabe zum Festpreis.
    $app->saveService($v2,['label'=>'Beratung','price'=>'12,34','goae_code'=>'1','factor'=>'3,0']);
    $high=$db->one('SELECT id FROM services WHERE label=?',['Beratung'])['id'];
    $next=['request_id'=>App::id(),'services'=>[$high],'manual_amount'=>''];
    rejects(fn()=>$app->start($v2,$next),'Schwellenüberschreitung ohne Begründung');
    rejects(fn()=>$app->saveService($v2,['label'=>'Ungültig','price'=>'2','goae_code'=>'3562','factor'=>'2,3','fee_type'=>'lab']),'Unzulässiger Laborfaktor');
    $next['reasons']=[$high=>'Erschwerte Beratung im konkreten Fall']; $p2=$app->start($v2,$next);
    check($p2['amount_cents']===1234,'Faktor multipliziert Festpreis');
    check(str_contains($db->one('SELECT invoice_json FROM payments WHERE id=?',[$p2['id']])['invoice_json'],'Erschwerte Beratung'),'Begründung fehlt im Snapshot');
    rejects(fn()=>$app->receipt($v2,$p2['id']),'Zweite unbezahlte Rechnung gedruckt');

    // Adresse aus FHIR: alte Anschrift ignorieren.
    $fhirValues=$values; $fhirValues['fhir']=['mode'=>'live','base_url'=>'https://t2med.test/r4','launch_urls'=>['https://t2med.test/r4'],'api_key'=>'test','entry_code'=>'ZAHLUNG'];
    $patient=(new FhirClient(new Config($fhirValues),$http))->patient('test','test');
    check($patient['address']===['Musterstraße 2','12345 Musterstadt'],'FHIR-Anschrift falsch');

    // Echte PDF-/Rasteraufbereitung mit langem UTF-8-Text und XML-Sonderzeichen.
    $invoice['number']=$p['invoice_number']; $invoice['issued_date']='2026-09-19';
    $payment=['status'=>'successful','paid_date'=>'19.09.2026 11:30','reference'=>$saved['reference'],'transaction_id'=>$p['invoice_number'],'mock'=>true];
    $svg=InvoiceReceipt::source($invoice,$payment);
    check(str_contains($svg,'ZAHLUNGSBESTÄTIGUNG'),'Bisherige Belegvariante fehlt');
    if ($output=getenv('KS_INVOICE_SAMPLE')) file_put_contents($output,ReceiptPdf::convert($svg));
    $combined=InvoiceReceipt::source($invoice,$payment,$http->original);
    check(!str_contains($combined,'ZAHLUNGSBESTÄTIGUNG') && !str_contains($combined,'Bezahlt per Karte') && substr_count($combined,'Vielen Dank.')===1,'Lokale Zahlungsbestätigung nicht ersetzt');
    $originalPng=ReceiptPdf::raster($http->original,420);
    check(str_contains($combined,base64_encode($originalPng)) && strpos($combined,'Vielen Dank.')<strpos($combined,'<image'),'Original fehlt oder steht vor Vielen Dank');
    check(str_contains(InvoiceReceipt::source($invoice,$payment,$originalPng),'<image'),'PNG-Originalbeleg nicht unterstützt');
    if ($output=getenv('KS_INVOICE_APPEND_SAMPLE')) file_put_contents($output,ReceiptPdf::convert($combined));
    // ESC/POS-Befehle durchlaufen, Rasterdaten nicht mit Steuerbefehlen verwechseln.
    $combinedPng=ReceiptPdf::raster($combined,420);
    foreach ([true,false] as $cut) {
        $raw=ReceiptPrinter::escpos($combinedPng,$cut); $offset=2;
        while (substr($raw,$offset,3)==="\x1d\x28\x4c") {
            $length=unpack('v',substr($raw,$offset+3,2))[1]; $offset+=5+$length;
            check(substr($raw,$offset,7)==="\x1d\x28\x4c\x02\x00\x30\x32",'Grafikdruck-Befehl fehlt'); $offset+=7;
        }
        check(substr($raw,$offset)===($cut ? "\x1d\x56\x42\x00" : "\x1b\x64\x03"),'Schnitt zwischen Rechnung und Originalbeleg oder falscher Abschluss');
    }
    $invoice['items'][0]['label']='Ärztliche Leistung mit sehrlangemzusammenhängendemWortäöüßundmehrZeichen <script>alert(1)</script> & Ende';
    $invoice['items'][0]['reason']=str_repeat('Ausführliche fallbezogene Begründung mit Umlauten. ',5);
    $svg=InvoiceReceipt::source($invoice,$payment);
    check(!str_contains($svg,'<script>') && str_contains($svg,'&lt;'),'SVG-Injection');
    $png=ReceiptPdf::raster($svg,420); $size=getimagesizefromstring($png);
    check($size[0]===420 && $size[1]>700 && $size[1]<12000,'Bon-Dimensionen falsch');
    check(str_starts_with(ReceiptPrinter::escpos($png,true),"\x1b\x40"),'Raster nicht druckbar');
    if ($output=getenv('KS_INVOICE_LONG_SAMPLE')) file_put_contents($output,ReceiptPdf::convert($svg));
    $payment['status']='pending'; rejects(fn()=>InvoiceReceipt::source($invoice,$payment),'Renderer druckt unbestätigte Zahlung als bezahlt');
    echo "OK: Migration, GOÄ-Festpreise, Kombinationen ohne Doppelposition, Begründung, neutrale SumUp-Daten, fester Beleg, Transaktions-ID als Rechnungsnummer, FHIR, PDF, optionaler Originalbeleg und Druck ohne Zwischenschnitt.\n";
} finally {
    if(is_dir($dir)) {
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($dir);
    }
}
