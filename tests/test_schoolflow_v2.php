<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/scrm-v2-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function v2_check($v,string $msg): void {if(!$v)throw new RuntimeException('V2: '.$msg);}
function v2_req(string $method,string $path,array $payload=[],string $token='t1'): array {
    global $store;
    try{return sf_handle($method,$path,$payload,['authorization'=>'Bearer '.$token], '127.0.0.1',$store);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
$store->transaction(function(&$s){
    foreach([['Admin One','Admin',1],['Counsellor One','Counsellor',1],['Counsellor Two','Counsellor',1],['School Two Admin','Admin',2]] as $i=>$pair){
        [$name,$role,$sid]=$pair;
        $uid=sf_id($s,'users');$s['users'][]=['id'=>$uid,'name'=>$name,'email'=>"$uid@example.invalid",'password_hash'=>'x','active'=>true];
        $s['memberships'][]=['user_id'=>$uid,'school_id'=>$sid,'role'=>$role];
        $s['sessions'][]=['user_id'=>$uid,'token_hash'=>sf_hash('t'.$uid),'expires_at'=>time()+5000];
    }
    foreach(['School One','School Two'] as $n){$sid=sf_id($s,'schools');$s['schools'][]=['id'=>$sid,'name'=>$n];}
},true);
// Simulate upgrading the exact pre-2.0 JSON shape.
$store->transaction(function(&$s){foreach(['calls','visits','email_templates','assignment_modes','assignment_cursors'] as $key)unset($s[$key]);},true);
$base='/api/schools/1';
v2_check(v2_req('GET',$base.'/assignment')[1]['mode']==='least_loaded','legacy default assignment');
$v=v2_req('PATCH',$base.'/assignment',['mode'=>'round_robin']);v2_check($v[0]===200,'admin updates assignment');
v2_check(v2_req('PATCH',$base.'/assignment',['mode'=>'manual'],'t2')[0]===403,'counsellor cannot update assignment');
$leadBody=['student_name'=>'Pupil One','parent_name'=>'Parent One','phone'=>'+91 9999991000','grade'=>'Grade 2','consent'=>true,'email'=>'parent@example.invalid'];
$a=v2_req('POST',$base.'/leads',$leadBody);$lid=$a[1]['id'];v2_check($a[1]['assigned_to']===2,'round robin first');
$b=v2_req('POST',$base.'/leads',[...$leadBody,'student_name'=>'Pupil Two','phone'=>'+91 9999991001']);
v2_check($b[1]['assigned_to']===3,'round robin second');
$other=v2_req('POST','/api/schools/2/leads',[...$leadBody,'student_name'=>'Other School Pupil'],'t4');
v2_check($other[0]===201,'second school entry');
v2_check(v2_req('GET',$base.'/leads/'.$b[1]['id'],[],'t2')[0]===403,'counsellor cannot view others lead');
v2_check(v2_req('POST',$base.'/leads/'.$lid.'/contact-action',['channel'=>'whatsapp'],'t2')[0]===200,'consented WhatsApp launch audited');
v2_check(v2_req('POST',$base.'/leads/'.$lid.'/contact-action',['channel'=>'invalid'],'t2')[0]===422,'reject invalid communication channel');
$call=v2_req('POST',$base.'/leads/'.$lid.'/calls',['outcome'=>'Interested','followup_date'=>'2026-10-20','note'=>'Parent asked about timetable'],'t2');
v2_check($call[0]===201,'call logged');
$detail=v2_req('GET',$base.'/leads/'.$lid,[],'t2')[1];
v2_check($detail['lead']['last_call_outcome']==='Interested'&&$detail['lead']['followup_date']==='2026-10-20','call updates lead');
v2_check(count($detail['calls'])===1,'call history available');
v2_check(v2_req('POST',$base.'/leads/'.$lid.'/calls',['outcome'=>'Unknown'],'t2')[0]===422,'invalid outcome rejected');
$v=v2_req('POST',$base.'/visits',['lead_id'=>$lid,'starts_at'=>'2026-10-20T15:30','purpose'=>'Campus tour'],'t2');
v2_check($v[0]===201,'visit booked');
v2_check(v2_req('POST',$base.'/visits',['lead_id'=>$b[1]['id'],'starts_at'=>'2026-10-20T15:30','purpose'=>'Campus tour'],'t2')[0]===403,'cannot book another counsellor visit');
$visits=v2_req('GET',$base.'/visits',[],'t2')[1];v2_check(count($visits)===1,'staff sees own visit');
v2_check(v2_req('PATCH',$base.'/visits/'.$v[1]['id'],['status'=>'Completed'],'t2')[0]===200,'visit completed');
v2_check(v2_req('PATCH',$base.'/visits/'.$v[1]['id'],['status'=>'Maybe'],'t2')[0]===422,'invalid visit status blocked');
$family=v2_req('POST',$base.'/families/'.$lid.'/siblings',['student_name'=>'Sibling One','grade'=>'Grade 1'],'t2');
v2_check($family[0]===201&&$family[1]['assigned_to']===2,'sibling record inherits assignment');
$d=v2_req('GET',$base.'/leads/'.$lid,[],'t2')[1];v2_check($d['family']['count']===2,'family profile includes sibling');
v2_check(v2_req('GET',$base.'/families',[],'t2')[0]===200,'counsellor families permitted');
v2_check(v2_req('POST',$base.'/families/'.$b[1]['id'].'/siblings',['student_name'=>'Bad','grade'=>'Grade 1'],'t2')[0]===403,'sibling role isolation');
v2_check(v2_req('GET','/api/schools/2/families',[],'t2')[0]===403,'school isolation');
$template=v2_req('POST',$base.'/templates',['title'=>'Follow-up','subject'=>'Hello {parent}','body'=>'Dear {parent}, we welcome {student}']);
v2_check($template[0]===201,'template created');
v2_check(v2_req('POST',$base.'/templates',['title'=>'No','subject'=>'No','body'=>'No'],'t2')[0]===403,'counsellor cannot author templates');
v2_check(v2_req('PATCH',$base.'/templates/'.$template[1]['id'],['subject'=>'Updated parent message'])[0]===200,'template edit');
v2_check(count(v2_req('GET',$base.'/templates',[],'t2')[1])===1,'template visible to staff');
$analytics=v2_req('GET',$base.'/analytics',[],'t2')[1];
v2_check($analytics['total']===2,'counsellor analytics limited to own leads');
v2_check($analytics['first_contact_pending']===1,'first-contact metric');
v2_check(v2_req('GET',$base.'/analytics')[1]['total']===3,'admin analytics school level');
$log=v2_req('GET',$base.'/audit')[1];
$actions=array_column($log['events'],'action');v2_check(in_array('call_logged',$actions,true)&&in_array('visit_booked',$actions,true)&&in_array('email_template_created',$actions,true),'actions audited');
$blob=$store->transaction(fn($s)=>json_encode($s));
v2_check(strpos($blob,'Parent asked about timetable')!==false,'call note persisted');
echo "PASS: SchoolFlow V2 assignment, calls, visits, families, templates, analytics, audit and tenant permissions\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}