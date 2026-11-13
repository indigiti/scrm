<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';

$tmp=sys_get_temp_dir().'/schoolflow-followup-v2-'.bin2hex(random_bytes(6));
mkdir($tmp,0700,true);
$store=new SchoolFlowStore($tmp);

function fuv2_check(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('Follow-up V2: '.$message);
}
function fuv2_request(string $method,string $path,array $payload=[],string $token=''):array{
    global $store;
    try{return sf_handle($method,$path,$payload,
        ['authorization'=>$token!==''?'Bearer '.$token:''],'127.0.0.1',$store);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
    $store->transaction(function(&$s){
        $uid=sf_id($s,'users');$sid=sf_id($s,'schools');
        $s['users'][]=['id'=>$uid,'name'=>'Test Admin','email'=>'v2-admin@example.test',
            'password_hash'=>password_hash('secure-password-12345',PASSWORD_DEFAULT),
            'active'=>true,'created_at'=>sf_time()];
        $s['schools'][]=['id'=>$sid,'name'=>'Test Campus','created_at'=>sf_time()];
        $s['memberships'][]=['school_id'=>$sid,'user_id'=>$uid,'role'=>'Admin'];
    },true);
    [$code,$login]=fuv2_request('POST','/api/auth/login',
        ['email'=>'v2-admin@example.test','password'=>'secure-password-12345']);
    fuv2_check($code===200,'admin login');
    $admin=$login['token'];
    [$code,$lead]=fuv2_request('POST','/api/schools/1/leads',
        ['student_name'=>'Test Pupil','parent_name'=>'Test Guardian',
            'phone'=>'+91 9999988877','grade'=>'Grade 4'],$admin);
    fuv2_check($code===201,'create test enquiry');
    $path='/api/schools/1/leads/'.$lead['id'];
    $settingsPath='/api/schools/1/enquiry-panels';
    [$code,$settings]=fuv2_request('GET',$settingsPath,[],$admin);
    fuv2_check($code===200&&$settings['preferences']['followup_disposition']===false,'disqualification disabled by default');
    $hidden=['disposition'=>'Warm','outcome'=>'Answered','note'=>'Parent confirmed receiving the prospectus.',
        'followup_date'=>'2026-11-20','submission_id'=>'followup-hidden-0001'];
    fuv2_check(fuv2_request('POST',$path.'/followup',array_replace($hidden,[
        'disposition'=>'Disqualified','disqualification_reason'=>'Fees'
    ]),$admin)[0]===403,'direct disqualification is blocked while disabled');
    [$code,$unchanged]=fuv2_request('POST',$path.'/followup',$hidden,$admin);
    fuv2_check($code===201&&$unchanged['lead']['temperature']==='Warm'&&
        $unchanged['lead']['followup_date']==='2026-11-20','temperature and follow-up save while disqualification is disabled');
    $prefs=$settings['preferences'];
    $prefs['followup_disposition']=true;
    fuv2_check(fuv2_request('PATCH',$settingsPath,['sections'=>$settings['sections'],'preferences'=>$prefs],$admin)[0]===200,
        'Admin can enable follow-up classification');
    $base=['outcome'=>'Interested','note'=>'Parent requested a campus visit this week.',
        'followup_date'=>'2026-11-21','disposition'=>'Hot',
        'submission_id'=>'followup-request-0001'];
    [$code,$invalid]=fuv2_request('POST',$path.'/followup',
        array_merge($base,['note'=>'short']),$admin);
    fuv2_check($code===422,'summary must be mandatory on follow-up endpoint');
    [$legacyCode,$legacyData]=fuv2_request('POST',$path.'/calls',
        ['outcome'=>'Answered','note'=>''],$admin);
    fuv2_check($legacyCode===422,'legacy calls must reject empty summary; got '.(string)$legacyCode.' '.json_encode($legacyData));
    [$code,$saved]=fuv2_request('POST',$path.'/followup',$base,$admin);
    fuv2_check($code===201 && ($saved['lead']['temperature']??'')==='Hot','save call and hot temperature');
    fuv2_check(($saved['lead']['followup_date']??'')==='2026-11-21','save next follow-up');
    [$code,$repeat]=fuv2_request('POST',$path.'/followup',$base,$admin);
    fuv2_check($code===200 && !empty($repeat['duplicate']),'retry should be idempotent');
    [$code,$detail]=fuv2_request('GET',$path,[],$admin);
    fuv2_check($code===200 && count($detail['calls'])===2,'retry should create only one call');
    fuv2_check(fuv2_request('PATCH',$path,['status'=>'Not Eligible'],$admin)[0]===422,
        'direct stage API must not bypass mandatory reason');
    $dq=array_merge($base,['outcome'=>'No Answer',
        'note'=>'Parent asked us to close this enquiry.',
        'followup_date'=>'','disposition'=>'Disqualified','submission_id'=>'followup-request-0002']);
    fuv2_check(fuv2_request('POST',$path.'/followup',$dq,$admin)[0]===422,
        'disqualification requires reason');
    fuv2_check(fuv2_request('POST',$path.'/followup',
        array_merge($dq,['disqualification_reason'=>'Other']),$admin)[0]===422,
        'Other requires explanation');
    [$code,$disqualified]=fuv2_request('POST',$path.'/followup',
        array_merge($dq,['disqualification_reason'=>'Fees',
            'disqualification_note'=>'Fees exceed the family budget.']),$admin);
    fuv2_check($code===201,'valid disqualification saved');
    fuv2_check(($disqualified['lead']['status']??'')==='Not Eligible',
        'disqualification maps to Not Eligible, not temperature');
    fuv2_check(($disqualified['lead']['temperature']??'')==='Hot',
        'temperature remains Hot after disqualification');
    fuv2_check(($disqualified['lead']['disqualification_reason']??'')==='Fees',
        'reason persisted');
    [$code,$detail]=fuv2_request('GET',$path,[],$admin);
    fuv2_check($code===200 && count($detail['calls'])===3,'all calls persisted');
    $prefs['followup_disposition']=false;
    fuv2_check(fuv2_request('PATCH',$settingsPath,['sections'=>$settings['sections'],'preferences'=>$prefs],$admin)[0]===200,
        'Admin can disable follow-up classification again');
    [$code,$stillClosed]=fuv2_request('POST',$path.'/followup',array_merge($hidden,[
        'submission_id'=>'followup-hidden-0002','disposition'=>'Cold','followup_date'=>''
    ]),$admin);
    fuv2_check($code===201&&$stillClosed['lead']['status']==='Not Eligible'&&$stillClosed['lead']['temperature']==='Cold'&&
        $stillClosed['lead']['disqualification_reason']==='Fees','temperature can change without reopening or clearing disqualification');
    $prefs['followup_disposition']=true;
    fuv2_check(fuv2_request('PATCH',$settingsPath,['sections'=>$settings['sections'],'preferences'=>$prefs],$admin)[0]===200,
        'classification can be restored');
    $reopen=array_merge($base,['disposition'=>'Warm','submission_id'=>'followup-request-0003']);
    [$code,$restored]=fuv2_request('POST',$path.'/followup',$reopen,$admin);
    fuv2_check($code===201 && ($restored['lead']['status']??'')==='Contact Attempted',
        'reopen disqualified lead for follow-up');
    fuv2_check(($restored['lead']['temperature']??'')==='Warm'
        && ($restored['lead']['disqualification_reason']??'')==='',
        'reopened lead classification and active reason');
    $audit=$store->transaction(function($s)use($lead){
        return array_values(array_filter($s['audit_logs']??[],
            fn($e)=>($e['lead_id']??null)===$lead['id']));
    });
    fuv2_check((bool)array_filter($audit,fn($e)=>($e['action']??'')==='lead_disqualified'),
        'disqualification audited');
    fuv2_check((bool)array_filter($audit,fn($e)=>($e['action']??'')==='lead_reopened'),
        'reopening audited');
    echo "PASS: Follow-up V2 validation, single-save, idempotency, disposition, audit, recovery\n";
}finally{
    foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);
    @rmdir($tmp);
}
