<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Config, HttpClient, Problem, TransportError};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (Problem) { return; }
    throw new RuntimeException($message);
}
function visit(App $app, string $context): array {
    $launch = $app->launch(['context_id'=>$context, 'oauth_token'=>'', 'fhir_base_url'=>'']);
    parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $fragment);
    $exchange = $app->exchange($fragment['launch'], null);
    $browser = $app->browser($exchange['cookie']);
    return [$app->visit($browser, $exchange['visit_id']), $browser];
}
function start(App $app, array $visit): array {
    return $app->start($visit, ['request_id'=>App::id(), 'services'=>[], 'manual_amount'=>'16']);
}
final class ReaderHttp extends HttpClient {
    public array $payments = [];
    public int $terminations = 0;
    public bool $statusError = false, $cancelLost = false, $startLost = false;
    public function request(string $method, string $url, array $headers, ?array $body=null, string $caFile='', bool $pinCertificate=false): array {
        check(str_starts_with($url, 'https://api.sumup.com/'), 'Unerwarteter FHIR-/Netzwerkaufruf');
        if ($method === 'POST' && str_ends_with($url, '/checkout')) {
            $id = 'client-' . (count($this->payments) + 1);
            $this->payments[$id] = ['status'=>'pending', 'reference'=>$body['affiliate']['foreign_transaction_id']];
            if ($this->startLost) throw new TransportError('Startantwort verloren.');
            return ['status'=>201, 'body'=>['data'=>['checkout_id'=>$id, 'client_transaction_id'=>$id]]];
        }
        if ($method === 'POST' && str_ends_with($url, '/terminate')) {
            $this->terminations++;
            if ($this->cancelLost) throw new TransportError('Abbruchantwort verloren.');
            return ['status'=>202, 'body'=>[]];
        }
        check($method === 'GET', 'Unerwartete Schreibanfrage');
        if ($this->statusError) return ['status'=>503, 'body'=>[]];
        if (str_contains($url, '/transactions?')) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $id = $query['client_transaction_id'] ?? '';
            $p = $this->payments[$id] ?? null;
            if (!$p || $p['status'] !== 'successful') return ['status'=>404, 'body'=>[]];
            return ['status'=>200, 'body'=>['id'=>'transaction-'.$id, 'client_transaction_id'=>$id,
                'merchant_code'=>'MTEST', 'currency'=>'EUR', 'amount'=>16, 'status'=>'SUCCESSFUL']];
        }
        $id = basename(parse_url($url, PHP_URL_PATH));
        check(isset($this->payments[$id]), 'Unbekannter Checkout');
        return ['status'=>200, 'body'=>['data'=>['checkout_id'=>$id, 'client_transaction_id'=>$id,
            'total_amount'=>['currency'=>'EUR', 'minor_unit'=>2, 'value'=>1600], 'status'=>$this->payments[$id]['status']]]];
    }
}
$dir = sys_get_temp_dir() . '/ks-reader-test-' . bin2hex(random_bytes(6));
try {
    $values = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $values['app']['state_dir']=$dir; $values['app']['launcher_key']=str_repeat('a',64); $values['app']['encryption_key']=str_repeat('b',64);
    $values['sumup']=['mode'=>'live','api_key'=>'test','affiliate_key'=>'test','app_id'=>'test.app','merchant_code'=>'MTEST','reader_id'=>'reader-test'];
    $http=new ReaderHttp(); $app=new App(new Config($values),$http); $app->db->migrate();
    [$v1,$b1]=visit($app,'first'); [$v2,$b2]=visit($app,'second'); [$v3,$b3]=visit($app,'third');
    $p1=start($app,$v1);
    $summary=$app->state($v2,$b2)['reader_payment'];
    check($summary['id']===$p1['id'] && array_keys($summary)===['id','amount_cents','reference','payment_status','error_message'], 'Falscher Vorgang oder Patientendaten in Terminalübersicht');
    check($app->state($v1,$b1)['reader_payment']===null, 'Eigene Zahlung als fremder Vorgang');
    rejects(fn()=>start($app,$v2),'Doppelbelegung möglich');
    rejects(fn()=>$app->poll($v2,$p1['id']),'Fremde vollständige Zahlung zugänglich');
    rejects(fn()=>$app->readerAction($v1,$p1['id'],true),'Eigene Zahlung über Terminalübersicht');

    $http->statusError=true;
    $result=$app->readerAction($v2,$p1['id'],true);
    check($http->terminations===0 && $result['reader_payment']!==null, 'Abbruch trotz fehlgeschlagener Statusprüfung');
    $http->statusError=false; $http->cancelLost=true;
    $result=$app->readerAction($v2,$p1['id'],true);
    check($result['reader_payment']['payment_status']==='pending' && str_contains($result['message'],'verloren'), 'Verlorene Antwort als bestätigter Abbruch');
    $http->cancelLost=false;
    $result=$app->readerAction($v2,$p1['id'],true);
    check($result['reader_payment']['payment_status']==='cancel_requested' && $http->terminations===2, 'Abbruch nicht wiederholbar');
    rejects(fn()=>start($app,$v2),'HTTP 202 gibt Terminal vorzeitig frei');
    $app->db->query('UPDATE payments SET checked_at=0 WHERE id=?',[$p1['id']]);
    check($app->readerAction($v2,$p1['id'])['reader_payment']['payment_status']==='cancel_requested','Polling verliert Abbruchauftrag');
    $http->payments['client-1']['status']='cancelled';
    $app->db->query('UPDATE payments SET checked_at=0 WHERE id=?',[$p1['id']]);
    check($app->readerAction($v2,$p1['id'])['reader_payment']===null, 'Bestätigter Abbruch gibt Terminal nicht frei');
    check(count($http->payments)===1, 'Abbruch startet automatisch nächste Zahlung');
    $p2=start($app,$v2);
    $stale=$app->readerAction($v3,$p1['id'],true);
    check($http->terminations===2 && $stale['reader_payment']['id']===$p2['id'], 'Veralteter Abbruch trifft neue Zahlung');

    // Erfolg kurz vor dem Abbruch: frisch lesen, erhalten, keine Stornierung/Erstattung.
    $http->payments['client-2']['status']='successful';
    $app->db->query('UPDATE payments SET checked_at=? WHERE id=?',[time(),$p2['id']]);
    $result=$app->readerAction($v3,$p2['id'],true);
    $saved=$app->db->one('SELECT * FROM payments WHERE id=?',[$p2['id']]);
    check($result['reader_payment']===null && str_contains($result['message'],'erfolgreich') && $http->terminations===2,'Erfolg vor Abbruch nicht frisch abgeglichen');
    check($saved['payment_status']==='successful' && $saved['paid_at']!==null && $saved['transaction_id']==='transaction-client-2' && $saved['doc_status']==='pending','Erfolgreiche Zahlung oder Dokumentation verändert');
    check((int)$app->db->query('SELECT COUNT(*) FROM mock_records')->fetchColumn()===0,'Abbruch schreibt in Akte');

    $http->startLost=true; $p3=start($app,$v3);
    $result=$app->readerAction($v1,$p3['id'],true);
    check($result['reader_payment']['payment_status']==='cancel_requested','Unbekannter Start ohne Checkout wird freigegeben');
    rejects(fn()=>start($app,$v1),'Ungeklärte Zahlung ohne Checkout gibt Terminal frei');
    $app->db->query('UPDATE payments SET reader_id=? WHERE id=?',['other-reader',$p3['id']]);
    rejects(fn()=>$app->readerAction($v1,$p3['id'],true),'Anderes Terminal abbrechbar');
    $app->db->query('UPDATE visits SET completed_at=? WHERE id=?',[time(),$v1['id']]);
    rejects(fn()=>$app->readerAction($v1,$p2['id'],true),'Abgeschlossene Sitzung darf Terminal bedienen');

    // Mock bestätigt sofort; derselbe vorhandene Abbruchknopf nutzt ebenfalls den Statusabgleich.
    $values['sumup']['mode']='mock'; $mock=new App(new Config($values));
    [$v4,$b4]=visit($mock,'mock-first'); [$v5,$b5]=visit($mock,'mock-second');
    $p4=start($mock,$v4);
    check($mock->readerAction($v5,$p4['id'],true)['reader_payment']===null,'Mock-Abbruch gibt Terminal nicht frei');
    check($mock->poll($v4,$p4['id'])['payment_status']==='cancelled','Alter Browser sieht Abbruch nicht');
    echo "OK: fremden Vorgang abbrechen, Statusabgleich, Wiederholung, verzögerte Bestätigung, veralteter Klick und Patiententrennung.\n";
} finally {
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
