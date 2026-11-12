<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-management-'.bin2hex(random_bytes(7));
mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function management_test(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('Management intelligence: '.$message);
}
function management_request(string $method,string $path,string $token='manager',array $payload=[]):array{
    global $store;
    try{return sf_handle($method,$path,$payload,['authorization'=>'Bearer '.$token],'198.51.100.19',$store);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
    $store->transaction(static function(array &$s):void{
        foreach([1=>'School One',2=>'School Two',3=>'Outside School'] as $id=>$name)
            $s['schools'][]=['id'=>$id,'guid'=>sf_uuid(),'name'=>$name];
        foreach([
            [1,'Management Staff','manager',[[1,'Management'],[2,'Management']]],
            [2,'School Administrator','admin',[[1,'Admin'],[2,'Admin']]],
            [3,'Outside Manager','other',[[3,'Management']]],
            [4,'Admissions User','admissions',[[1,'Admissions']]]
        ] as [$id,$name,$token,$roles]){
            $s['users'][]=['id'=>$id,'name'=>$name,'email'=>'staff'.$id.'@example.invalid','active'=>true];
            $s['sessions'][]=['user_id'=>$id,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
            foreach($roles as [$sid,$role])$s['memberships'][]=['user_id'=>$id,'school_id'=>$sid,'role'=>$role,'enabled'=>true];
        }
        foreach([
            [1,1,'Alice','Hot','New Enquiry','2026-11-12T08:00:00+00:00','2026-11-12',1],
            [2,1,'Bob','Warm','Admission Confirmed','2026-11-12T09:00:00+00:00','',2],
            [3,2,'Claire','Cold','Not Eligible','2026-11-12T10:00:00+00:00','',2],
            [4,3,'Secret Student','Hot','Enrolled','2026-11-12T11:00:00+00:00','',3],
            [5,2,'Demo Student','Hot','New Enquiry','2026-11-12T12:00:00+00:00','',2]
        ] as [$id,$sid,$name,$temp,$status,$created,$follow,$actor]){
            $s['leads'][]=['id'=>$id,'guid'=>sf_uuid(),'school_id'=>$sid,'name'=>$name,'guardian'=>'Guardian',
                'phone'=>'9999997777','email'=>'secret@example.invalid','grade'=>'III','site_id'=>null,
                'created_at'=>$created,'status'=>$status,'temperature'=>$temp,
                'assigned_to'=>$actor,'followup_date'=>$follow,
                'form_type'=>'inline','source_type'=>'manual','utm_campaign'=>'Winter Campaign',
                'is_demo'=>$id===5,'disqualification_reason'=>$id===3?'Fees':''];
        }
        $s['calls'][]=['id'=>1,'school_id'=>1,'lead_id'=>1,'outcome'=>'No Answer',
            'created_at'=>'2026-11-12T12:00:00+00:00','actor_id'=>2,'note'=>'First attempt'];
        $s['calls'][]=['id'=>2,'school_id'=>1,'lead_id'=>1,'outcome'=>'Interested',
            'created_at'=>'2026-11-12T13:00:00+00:00','actor_id'=>2,'note'=>'Second attempt'];
        $s['calls'][]=['id'=>3,'school_id'=>3,'lead_id'=>4,'outcome'=>'Interested',
            'created_at'=>'2026-11-12T14:00:00+00:00','actor_id'=>3,'note'=>'Outside secret'];
    },true);
    [$code,$overview]=management_request('GET','/api/management/overview?period=custom&from=2026-11-12&to=2026-11-12');
    management_test($code===200,'management overview authorized');
    management_test($overview['summary']['enquiries']===3,'only assigned schools and non-demo leads included');
    management_test($overview['summary']['confirmed']===1,'current confirmed status');
    management_test($overview['summary']['disqualified']===1,'disqualification not a separate temperature');
    management_test($overview['summary']['calls']===2 && $overview['summary']['unique_called_leads']===1,'calls count events but distinct leads separately');
    management_test(count($overview['school_performance'])===2,'assigned school breakdown');
    management_test(count($overview['schools'])===2,'school selector restricted');
    [$code,$filtered]=management_request('GET','/api/management/overview?period=custom&from=2026-11-12&to=2026-11-12&school_id=2');
    management_test($code===200&&$filtered['summary']['enquiries']===1,'school filter restricts results');
    management_test(management_request('GET','/api/management/overview?school_id=3')[0]===403,'unrelated campus rejected');
    management_test(management_request('GET','/api/management/overview','admissions')[0]===403,'Admissions cannot impersonate Management');
    management_test(management_request('GET','/api/management/overview','other')[1]['summary']['enquiries']===1,'outside Management account sees only own campus');
    management_test(management_request('POST','/api/management/overview')[0]===405,'reports are GET-only');
    management_test(management_request('PATCH','/api/schools/1/leads/1','manager',['status'=>'Enrolled'])[0]===403,'Management cannot mutate school leads');
    management_test(management_request('GET','/api/schools/1/leads','manager')[0]===403,'Management cannot enter legacy lead API');
    management_test(management_request('GET','/api/schools/1/members','manager')[0]===403,'Management cannot see administrative member records');
    management_test(management_request('GET','/api/admin/master-leads','manager')[0]===403,'Management cannot bypass Admin master list');
    [$code,$leads]=management_request('GET','/api/management/leads?period=custom&from=2026-11-12&to=2026-11-12&per_page=2');
    management_test($code===200&&$leads['total']===3&&count($leads['rows'])===2,'bounded paginated report');
    $row=$leads['rows'][0];
    management_test(!isset($row['email'])&&!isset($row['phone'])&&!isset($row['guardian'])&&str_contains($row['phone_masked'],'7777'),'sensitive lead columns masked');
    [$code,$detail]=management_request('GET','/api/management/leads/1');
    management_test($code===200&&count($detail['history'])===2&&!isset($detail['email'])&&!isset($detail['phone']),'detail and call history redacted');
    management_test(management_request('GET','/api/management/leads/4')[0]===404,'direct outside lead ID denied');
    management_test(management_request('GET','/api/management/overview?period=custom')[0]===422,'incomplete period rejected');
    management_test(management_request('GET','/api/management/leads?per_page=999')[0]===422,'oversized pagination rejected');
    management_test(management_request('POST','/api/schools/1/members','manager',[])[0]===403,'staff creation forbidden');
    management_test(management_request('GET','/api/me')[0]===200,'Management still has account self-service');
    echo "PASS: Management Intelligence school isolation, calendar filters, cohort metrics, calls, masked details, read-only permissions\n";
}finally{
    foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);
}
