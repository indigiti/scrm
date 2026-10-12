<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$tmp=sys_get_temp_dir().'/schoolflow-php-test-'.bin2hex(random_bytes(5));
mkdir($tmp,0700,true);
$store=new SchoolFlowStore($tmp);
function must(bool $ok, string $what): void { if (!$ok) throw new RuntimeException('FAIL: '.$what); }
function sendReq(string $method,string $path,array $body=[],string $token='',string $idem=''): array {
    global $store;
    $headers=['authorization'=>$token ? 'Bearer '.$token : '','idempotency-key'=>$idem];
    try {return sf_handle($method,$path,$body,$headers,'127.0.0.1',$store);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try {
    $store->transaction(function (&$s) {
        $u=['id'=>sf_id($s,'users'),'name'=>'Main Admin','email'=>'admin@example.test','password_hash'=>password_hash('admin-password-2026!', PASSWORD_DEFAULT),'active'=>true,'created_at'=>sf_time()];
        $sc=['id'=>sf_id($s,'schools'),'name'=>'School Alpha','created_at'=>sf_time()];
        $s['users'][]=$u;$s['schools'][]=$sc;
        $s['memberships'][]=['school_id'=>$sc['id'],'user_id'=>$u['id'],'role'=>'Admin'];
    },true);
    [$code,$res]=sendReq('POST','/api/auth/login',['email'=>'admin@example.test','password'=>'admin-password-2026!']);
    must($code===200,'login');$admin=$res['token'];
    [$code,$res]=sendReq('GET','/api/me',[], $admin);
    must($code===200 && $res['schools'][0]['name']==='School Alpha','school membership');
    [$code,$c]=sendReq('POST','/api/schools/1/members',['name'=>'Counsellor','email'=>'counsellor@example.test','password'=>'secure-counsellor-2026!','role'=>'Counsellor'],$admin);
    must($code===201,'create counsellor');
    [$code,$site]=sendReq('POST','/api/schools/1/sites',['name'=>'WordPress','domain'=>'forms.example.test','platform'=>'WordPress'],$admin);
    must($code===201,'connect source');
    $siteKey=$site['api_key'];
    $lead=['student_name'=>'Test Student','parent_name'=>'Test Parent','phone'=>'+919999888877','class'=>'Grade 5','consent'=>true];
    [$code,$l]=sendReq('POST','/api/v1/inbound-leads',$lead,$siteKey,'unique-submission-0001');
    must($code===201,'capture inbound lead');
    [$code,$again]=sendReq('POST','/api/v1/inbound-leads',$lead,$siteKey,'unique-submission-0001');
    must($code===200 && $again['duplicate_submission'] && $again['id']===$l['id'],'idempotency');
    [$code,$visible]=sendReq('GET','/api/schools/1/leads',[],$admin);
    must($code===200 && count($visible)===1 && $visible[0]['assigned_to']===$c['id'],'auto assign');
    [$code,$note]=sendReq('POST','/api/schools/1/leads/'.$l['id'].'/notes',['text'=>'Called parent'],$admin);
    must($code===201,'note');
    [$code,$changed]=sendReq('PATCH','/api/schools/1/leads/'.$l['id'],['status'=>'Applied'],$admin);
    must($code===200 && $changed['status']==='Applied','pipeline');
    [$code,$school]=sendReq('POST','/api/schools',['name'=>'School Beta'],$admin);
    must($code===201,'create separate school');
    [$code,$separate]=sendReq('GET','/api/schools/'.$school['id'].'/leads',[],$admin);
    must($code===200 && count($separate)===0,'school data separation');
    [$code,$clogin]=sendReq('POST','/api/auth/login',['email'=>'counsellor@example.test','password'=>'secure-counsellor-2026!']);
    must($code===200,'counsellor login');$counsellor=$clogin['token'];
    must(sendReq('GET','/api/schools/'.$school['id'].'/leads',[],$counsellor)[0]===403,'counsellor school isolation');
    must(sendReq('POST','/api/schools/1/sites',['name'=>'Forbidden','domain'=>'example.test'],$counsellor)[0]===403,'role controls');
    must(sendReq('POST','/api/v1/inbound-leads',array_merge($lead,['consent'=>false]),$siteKey,'unique-submission-0002')[0]===422,'consent check');
    must(sendReq('POST','/api/v1/inbound-leads',$lead,'sfsite_not_a_key','unique-submission-0003')[0]===401,'invalid website key');
    echo "PASS: 13 CRM PHP API checks including multi-school, staff access and WordPress intake\n";
} finally {
    foreach (glob($tmp.'/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
}