<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App,Config,HttpClient,Money,Problem,TransportError,ReceiptMailer};

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $fn, string $message): void { try { $fn(); } catch (Problem) { return; } throw new RuntimeException($message); }
function rejectedProblem(callable $fn, string $message): Problem { try { $fn(); } catch (Problem $e) { return $e; } throw new RuntimeException($message); }
function configuration(bool $live = false): Config {
    $v = Config::parse(file_get_contents(dirname(__DIR__).'/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = sys_get_temp_dir().'/kienzle-sumup-test-'.bin2hex(random_bytes(6));
    $v['app']['launcher_key'] = str_repeat('a',64); $v['app']['encryption_key'] = str_repeat('b',64);
    if ($live) {
        $v['sumup'] = ['mode'=>'live','api_key'=>'sumup-secret','affiliate_key'=>'affiliate-secret','app_id'=>'test.app','merchant_code'=>'MTEST','reader_id'=>'reader-test'];
        $v['fhir'] = ['mode'=>'live','base_url'=>'https://t2med.test/aps/fhir/api/r4','launch_urls'=>['https://t2med.test/aps/fhir/api/r4'],'api_key'=>'fhir-secret','entry_code'=>'ZAHLUNG','ca_file'=>''];
    }
    return new Config($v);
}
function visit(App $app, string $context='test'): array {
    $launch=$app->launch(['context_id'=>$context,'oauth_token'=>'secret-token','fhir_base_url'=>'https://t2med.test/aps/fhir/api/r4']);
    parse_str(parse_url($launch['url'],PHP_URL_FRAGMENT),$fragment);
    $exchange=$app->exchange($fragment['launch'],null);
    rejects(fn()=>$app->exchange($fragment['launch'],null),'Startticket wiederverwendbar');
    $browser=$app->browser($exchange['cookie']);
    return [$app->visit($browser,$exchange['visit_id']),$browser];
}
function prepare(App $app, array $v): array {
    $app->saveService($v,['label'=>'Attest','price'=>'10,00']);
    $app->saveService($v,['label'=>'Bescheinigung','price'=>'5.10']);
    $ids=$app->db->query('SELECT id FROM services')->fetchAll(PDO::FETCH_COLUMN);
    return ['services'=>$ids,'manual_amount'=>'0,90','request_id'=>App::id()];
}

final class FakeHttp extends HttpClient {
    public array $calls=[];
    public string $write='success';
    public bool $conditional=false;
    public bool $startLost=false;
    public bool $wrongAmount=false;
    public bool $paymentFound=true;
    public bool $existingRecord=false;
    public bool $patientUnavailable=false;
    public ?array $record=null;
    public string $reference='';
    public int $receiptStatus=200;
    public array $links=[];
    public function request(string $method,string $url,array $headers,?array $body=null,string $caFile='',bool $pinCertificate=false):array {
        $this->calls[]=[$method,$url,$headers,$body];
        if (str_contains($url,'api.sumup.com')) {
            if ($method==='POST' && str_ends_with($url,'/checkout')) {
                $this->reference=$body['affiliate']['foreign_transaction_id'];
                if ($this->startLost) throw new TransportError('Verbindung unterbrochen.',true);
                return ['status'=>201,'body'=>['data'=>['checkout_id'=>'checkout-1','client_transaction_id'=>'client-1']]];
            }
            if (!$this->paymentFound) return ['status'=>404,'body'=>[]];
            return ['status'=>200,'body'=>['id'=>'transaction-1','client_transaction_id'=>'client-1','foreign_transaction_id'=>$this->reference,'merchant_code'=>'MTEST','currency'=>'EUR','amount'=>$this->wrongAmount ? 1 : 16,'status'=>'SUCCESSFUL','links'=>$this->links]];
        }
        if (str_contains($url,'/Patient?')) {
            if ($this->patientUnavailable) throw new TransportError('Zertifikat-Testfehler (cURL 60)',false);
            return ['status'=>200,'body'=>['resourceType'=>'Patient','id'=>'patient-1','name'=>[['given'=>['Erika'],'family'=>'Musterfrau']],
                'telecom'=>[['system'=>'email','value'=>'old@example.invalid','use'=>'old','rank'=>1],['system'=>'email','value'=>'second@example.invalid','rank'=>2],['system'=>'email','value'=>'erika@example.invalid','rank'=>1],['system'=>'phone','value'=>'12345']]]];
        }
        if (str_ends_with($url,'/metadata')) return ['status'=>200,'body'=>['resourceType'=>'CapabilityStatement','rest'=>[['mode'=>'server','resource'=>[['type'=>'Observation','conditionalCreate'=>$this->conditional]]]]]];
        if (str_contains($url,'/Observation?')) return ['status'=>200,'body'=>['resourceType'=>'Bundle','entry'=>$this->existingRecord && $this->record ? [['resource'=>['id'=>'record-1']+$this->record]] : []]];
        if ($method==='POST') {
            $this->record=$body['entry'][0]['resource'];
            if ($this->write==='reject') return ['status'=>422,'body'=>['resourceType'=>'OperationOutcome']];
            if ($this->write==='lost') throw new TransportError('Antwort verloren.',true);
            return ['status'=>200,'body'=>['resourceType'=>'Bundle','type'=>'transaction-response','entry'=>[['response'=>['status'=>'201 Created','location'=>'Observation/record-1/_history/1']]]]];
        }
        throw new RuntimeException('Unerwarteter Testaufruf');
    }
    public function download(string $url):array {
        $this->calls[]=['GET',$url,[],null];
        return ['status'=>$this->receiptStatus,'body'=>'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="220"><rect width="100%" height="100%" fill="white"/><text x="20" y="45" font-family="sans-serif" font-size="22">SumUp-Testbeleg</text><text x="20" y="95" font-family="sans-serif" font-size="30">16,00 EUR</text><text x="20" y="145" font-size="16">VISA **** 1234</text></svg>'];
    }
    public function posts(string $host):int {return count(array_filter($this->calls,fn($c)=>$c[0]==='POST' && str_contains($c[1],$host)));}
}

final class FakeMailer extends ReceiptMailer {
    public int $sent=0;
    public bool $lost=false;
    public function send(string $email,string $pdf,string $reference):void {
        check(str_starts_with($pdf,'%PDF-'),'Mail-Anhang ist kein PDF');
        $this->sent++;
        if($this->lost) throw new Problem('Versandantwort verloren.',502);
    }
}
$dirs=[];
try {
    // Echter cURL-Fehlerpfad ohne Netz: HTTP wird durch HTTPS-only vor dem Verbinden abgelehnt.
    try {
        (new HttpClient())->request('GET','http://127.0.0.1/?token=transport-secret',[]);
        throw new RuntimeException('Unsicheres HTTP-Protokoll akzeptiert');
    } catch (TransportError $e) {
        check(!$e->ambiguous && str_contains($e->getMessage(),'cURL 1'),'cURL-Fehlerpfad beschädigt');
        check(!str_contains($e->getMessage(),'transport-secret'),'URL-Geheimnis in cURL-Fehler');
    }
    check(Money::cents('10,01',100000)===1001,'Cent-Berechnung');
    foreach (['1.001','1e3','-1','0','1001.00'] as $amount) rejects(fn()=>Money::cents($amount,100000),'Ungültiger Betrag akzeptiert');
    $cfg=configuration();$dirs[]=$cfg->get('app','state_dir');$app=new App($cfg);$app->db->migrate();
    [$v,$b]=visit($app);$input=prepare($app,$v);
    $p=$app->start($v,$input+['amount_cents'=>1]);check($p['amount_cents']===1600,'Browserbetrag vertraut');
    rejects(fn()=>$app->receipt($v,$p['id']),'Beleg vor erfolgreicher Zahlung');
    rejects(fn()=>$app->receiptShare($v,$p['id']),'Beleglink vor erfolgreicher Zahlung');
    rejects(fn()=>$app->receiptRecipient($v,$p['id']),'E-Mail-Vorschlag vor erfolgreicher Zahlung');
    check($app->start($v,$input)['id']===$p['id'],'Doppelstart');
    $changed=$input;$changed['manual_amount']='2';rejects(fn()=>$app->start($v,$changed),'Idempotenzschlüssel mit geändertem Inhalt akzeptiert');
    $app->deleteService($v,$input['services'][0]);
    $snapshot=$app->db->one('SELECT services_json FROM payments WHERE id=?',[$p['id']]);check(count(json_decode($snapshot['services_json'],true))===3,'Snapshot zerstört');
    [$v2,$b2]=visit($app,'other');rejects(fn()=>$app->visit($b2,$v['id']),'Fremder Browser erhält Patientenkontext');
    rejects(fn()=>$app->start($v2,['request_id'=>App::id(),'services'=>[],'manual_amount'=>'1']),'Terminal-Doppelbelegung');
    $app->mock($v,['payment_id'=>$p['id'],'status'=>'successful']);
    check(str_starts_with($app->receipt($v,$p['id']),'%PDF-'),'Testbeleg ist kein PDF');
    rejects(fn()=>$app->receipt($v2,$p['id']),'Beleg für fremden Vorgang zugänglich');
    rejects(fn()=>$app->receiptRecipient($v2,$p['id']),'E-Mail-Vorschlag für fremden Vorgang zugänglich');
    check($app->poll($v,$p['id'])['payment_status']==='successful','Mockzahlung nicht erfolgreich');
    check((int)$app->db->query('SELECT COUNT(*) FROM mock_records')->fetchColumn()===0,'Polling dokumentiert automatisch');
    $app->mock($v,['document_fail'=>true]);$v=$app->visit($b,$v['id']);
    $failed=$app->document($v,$p['id']);check($failed['doc_status']==='failed' && $failed['payment_status']==='successful','FHIR-Fehler verändert Zahlungsstatus');
    $app->mock($v,['document_fail'=>false]);$v=$app->visit($b,$v['id']);
    check($app->document($v,$p['id'])['doc_status']==='written','Dokumentation nicht wiederholbar');
    $app->document($v,$p['id']);check((int)$app->db->query('SELECT COUNT(*) FROM mock_records')->fetchColumn()===1,'Doppelte Dokumentation');
    check($app->db->one('SELECT oauth_cipher FROM visits WHERE id=?',[$v['id']])['oauth_cipher']==='','Token nach Abschluss behalten');
    echo "OK: Betrag, Leistungs-Snapshot, Startticket, Zugriff, Doppelstart, manuelle Dokumentation und Wiederholung.\n";

    $cfg=configuration(true);$dirs[]=$cfg->get('app','state_dir');$http=new FakeHttp();$app=new App($cfg,$http);$app->db->migrate();
    $received='http://localhost:16567/aps/fhir/api/r4';$oauthMarker='oauth-token-must-stay-secret';
    $problem=rejectedProblem(fn()=>$app->launch(['context_id'=>'rejected','oauth_token'=>$oauthMarker,'fhir_base_url'=>$received]),'Abweichende FHIR-Aufrufadresse akzeptiert');
    $message=$problem->getMessage();
    check(str_contains($message,"Von t2med (fhirBasisUrl): $received\n"),'Empfangene FHIR-Adresse fehlt in Diagnose');
    check(str_contains($message,'Im Server erlaubt (launch_urls): https://t2med.test/aps/fhir/api/r4'),'Erlaubte FHIR-Adresse oder launch_urls fehlt in Diagnose');
    check(!str_contains($message,$oauthMarker),'OAuth-Token in Diagnose offengelegt');
    $unsafeAddresses=[
        ['https://userinfo-secret@t2med.test/aps/fhir/api/r4','userinfo-secret'],
        ['https://t2med.test/aps/fhir/api/r4?oAuthToken=query-secret','query-secret'],
        ['https://t2med.test/aps/fhir/api/r4#fragment-secret','fragment-secret'],
    ];
    foreach ($unsafeAddresses as [$address,$marker]) {
        $problem=rejectedProblem(fn()=>$app->launch(['context_id'=>'rejected','oauth_token'=>$oauthMarker,'fhir_base_url'=>$address]),'Unsichere FHIR-Aufrufadresse akzeptiert');
        check(str_contains($problem->getMessage(),'[keine reine HTTP(S)-Basisadresse; Inhalt ausgeblendet]'),'Unsichere FHIR-Adresse nicht vollständig ausgeblendet');
        check(!str_contains($problem->getMessage(),$marker),'Geheimer URL-Marker in Diagnose offengelegt');
        check(!str_contains($problem->getMessage(),$oauthMarker),'OAuth-Token in Diagnose offengelegt');
    }
    check(count($http->calls)===0,'FHIR-Ablehnung löst HTTP-Aufruf aus');
    check((int)$app->db->query('SELECT COUNT(*) FROM visits')->fetchColumn()===0,'FHIR-Ablehnung erzeugt Besuch');
    $http->patientUnavailable=true;
    $problem=rejectedProblem(fn()=>visit($app),'FHIR-Verbindungsfehler nicht als Nutzermeldung behandelt');
    check($problem->http===502 && str_contains($problem->getMessage(),'cURL 60'),'FHIR-Verbindungsursache verdeckt');
    check(!str_contains($problem->getMessage(),'secret-token'),'FHIR-Token in Verbindungsfehler');
    check((int)$app->db->query('SELECT COUNT(*) FROM visits')->fetchColumn()===0,'Verbindungsfehler erzeugt Besuch');
    $http->patientUnavailable=false;
    [$v,$b]=visit($app);$input=prepare($app,$v);
    $http->startLost=true;$http->paymentFound=false;$p=$app->start($v,$input);check($p['payment_status']==='unknown','Verlorene Startantwort als Fehler interpretiert');
    $app->start($v,$input);check($http->posts('api.sumup.com')===1,'SumUp trotz gleicher ID erneut gestartet');
    $http->paymentFound=true;$http->wrongAmount=true;$app->poll($v,$p['id']);
    check($app->db->one('SELECT payment_status FROM payments WHERE id=?',[$p['id']])['payment_status']==='unknown','Falscher Betrag akzeptiert');
    $app->db->query('UPDATE payments SET checked_at=0 WHERE id=?',[$p['id']]);$http->wrongAmount=false;
    check($app->poll($v,$p['id'])['payment_status']==='successful','Wiederabgleich fehlgeschlagen');
    $paymentBefore=$app->db->one('SELECT * FROM payments WHERE id=?',[$p['id']]);
    $postsBefore=$http->posts('api.sumup.com');$fhirBefore=$http->posts('t2med.test');
    $sumupCallsBefore=count(array_filter($http->calls,fn($call)=>str_contains($call[1],'sumup.com')));
    check($app->receiptRecipient($v,$p['id'])['email']==='erika@example.invalid','Automatischer E-Mail-Vorschlag fehlt');
    check(count(array_filter($http->calls,fn($call)=>str_contains($call[1],'sumup.com')))===$sumupCallsBefore,'E-Mail-Vorschlag ruft SumUp auf');
    $url='https://receipts-ng.sumup.com/v0.1/receipts/transaction-1?mid=MTEST&format=svg';
    $http->links=[['rel'=>'receipt','href'=>$url]];
    $receipt=$app->receipt($v,$p['id']);$app->receipt($v,$p['id']);
    check(str_starts_with($receipt,'%PDF-'),'Zahlungsbeleg ist kein PDF');
    $http->wrongAmount=true;rejects(fn()=>$app->receipt($v,$p['id']),'Beleg trotz falscher Transaktionssumme');$http->wrongAmount=false;
    $http->receiptStatus=404;rejects(fn()=>$app->receipt($v,$p['id']),'Fehlender Originalbeleg akzeptiert');
    $http->receiptStatus=200;check(str_starts_with($app->receipt($v,$p['id']),'%PDF-'),'Belegabruf nicht wiederholbar');
    $share=$app->receiptShare($v,$p['id']);check($share['url']===$url && $share['email']==='erika@example.invalid','Beleglink oder bevorzugte t2med-E-Mail fehlt');
    foreach (['https://evil.example/receipt','https://receipts-ng.sumup.com.evil.example/x','javascript:alert(1)','https://secret@receipts-ng.sumup.com/x','https://api.sumup.com/v1.1/receipts/x'] as $bad) {
        $http->links=[['rel'=>'receipt','href'=>$bad]];check($app->receiptShare($v,$p['id'])['url']==='','Unzulässiger Beleglink');
    }
    $http->links=[];check($app->receiptShare($v,$p['id'])['url']==='','Beleglink erfunden');
    check($app->db->one('SELECT * FROM payments WHERE id=?',[$p['id']])===$paymentBefore,'Belegabruf verändert Zahlung');
    check($http->posts('api.sumup.com')===$postsBefore && $http->posts('t2med.test')===$fhirBefore,'Belegabruf erzeugt Zahlung oder Dokumentation');
    echo "OK: Belegzuordnung, Wiederholung, Beleglink und E-Mail-Vorschlag ohne Schreibvorgänge.\n";
    // Migration einer bestehenden Datenbank erhält Zahlung und ergänzt Versandstatus.
    $app->db->query('DROP TABLE receipt_emails');$app->db->query('PRAGMA user_version=1');$app->db->migrate();
    check($app->db->one('SELECT * FROM payments WHERE id=?',[$p['id']])===$paymentBefore,'Migration verändert Zahlung');
    $values=$cfg->values;$values['mail']=['enabled'=>true,'host'=>'smtp.example.invalid','port'=>587,'encryption'=>'starttls','username'=>'user','password'=>'secret','from_address'=>'praxis@example.invalid','from_name'=>'Musterpraxis'];
    $mailConfig=new Config($values);$mailer=new FakeMailer($mailConfig);$mailApp=new App($mailConfig,$http,$mailer);
    $http->links=[['rel'=>'receipt','href'=>$url]];
    $mailInput=['payment_id'=>$p['id'],'email'=>'erika@example.invalid','request_id'=>App::id()];
    check($mailApp->receiptEmail($v,$mailInput)['status']==='sent','Versand fehlgeschlagen');
    $mailApp->receiptEmail($v,$mailInput);check($mailer->sent===1,'Gleicher Versand doppelt gesendet');
    $changed=$mailInput;$changed['email']='other@example.invalid';rejects(fn()=>$mailApp->receiptEmail($v,$changed),'Versandschlüssel mit anderem Empfänger');
    $mailer->lost=true;$mailInput['request_id']=App::id();
    check($mailApp->receiptEmail($v,$mailInput)['status']==='unknown','Verlorene Versandantwort als sicherer Erfolg');
    $mailApp->receiptEmail($v,$mailInput);check($mailer->sent===2,'Unklarer Versand blind wiederholt');
    $mailer->lost=false;$mailInput['request_id']=App::id();check($mailApp->receiptEmail($v,$mailInput)['status']==='sent','Bewusster Neuversand nicht möglich');
    check($app->db->one('SELECT * FROM payments WHERE id=?',[$p['id']])===$paymentBefore,'Mailversand verändert Zahlung');
    check($http->posts('t2med.test')===$fhirBefore,'Mailversand schreibt Akteneintrag');
    echo "OK: PDF-Anhang, Migration, Versandwiederholung und verlorene SMTP-Antwort.\n";
    foreach ($http->calls as $call) if(str_contains($call[1],'api.sumup.com')) {
        $json=json_encode($call[3]);check(!str_contains($json,'Attest')&&!str_contains($json,'Erika')&&!str_contains($json,'patient-1'),'Patientendaten an SumUp');
    }
    $http->write='reject';check($app->document($v,$p['id'])['doc_status']==='failed','Klare FHIR-Ablehnung nicht wiederholbar');
    $http->write='lost';check($app->document($v,$p['id'])['doc_status']==='unknown','FHIR-Timeout fälschlich klarer Fehler');
    $before=$http->posts('t2med.test');$app->document($v,$p['id']);check($http->posts('t2med.test')===$before,'Unklarer FHIR-POST blind wiederholt');
    $http->existingRecord=true;check($app->document($v,$p['id'])['doc_status']==='written','Vorhandener FHIR-Eintrag nicht erkannt');
    check($http->posts('t2med.test')===$before,'Vorhandener FHIR-Eintrag doppelt geschrieben');
    echo "OK: Verlorene SumUp-Antwort, Betragsabgleich, neutrale Referenz, FHIR-Ablehnung/Timeout/Wiedererkennung.\n";
} finally {
    foreach ($dirs as $dir) {foreach (glob($dir.'/*') as $file) unlink($file); rmdir($dir);}
}
