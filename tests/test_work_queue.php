<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function wq_assert(bool $value,string $message):void {if(!$value)throw new RuntimeException('work queue: '.$message);}
$clock=new DateTimeImmutable('2026-10-30T12:00:00+05:30');
$state=SchoolFlowStore::blank();
$state['schools']=[['id'=>1,'name'=>'One'],['id'=>2,'name'=>'Two']];
$state['users']=[['id'=>1,'email'=>'adm@example.test','active'=>true],['id'=>2,'email'=>'counsellor@example.test','active'=>true]];
$state['memberships']=[['school_id'=>1,'user_id'=>1,'role'=>'Admin'],['school_id'=>1,'user_id'=>2,'role'=>'Counsellor']];
$lead=static fn(int $id,int $sid,?int $owner,string $created,string $status='New',string $follow=''):array =>
 ['id'=>$id,'school_id'=>$sid,'assigned_to'=>$owner,'name'=>'Pupil '.$id,'grade'=>'1',
  'status'=>$status,'followup_date'=>$follow,'created_at'=>$created,
  'last_successful_contact_at'=>'','guid'=>'lead'.$id];
$state['leads']=[
 $lead(1,1,null,'2026-10-29T09:00:00+05:30','New','2026-10-28'),
 $lead(2,1,2,'2026-10-30T11:45:00+05:30'),
 $lead(3,1,2,'2026-10-29T09:00:00+05:30','Enrolled'),
 $lead(4,2,null,'2026-10-29T09:00:00+05:30'),
 $lead(5,1,2,'2026-10-29T09:00:00+05:30')
];
$state['leads'][4]['is_demo']=true;
$state['tasks']=[
 ['id'=>1,'school_id'=>1,'lead_id'=>1,'due_date'=>'2026-10-28','status'=>'Open'],
 ['id'=>2,'school_id'=>2,'lead_id'=>4,'due_date'=>'2026-10-28','status'=>'Open']
];
$state['response_sla_settings']=['1'=>['first_contact_minutes'=>120]];
$queue=sf_work_queue_build($state,1,1,'Admin',100,0,$clock);
wq_assert($queue['total']===1,'one actionable real school-one lead');
wq_assert($queue['items'][0]['lead_id']===1,'priority lead');
wq_assert(count($queue['items'][0]['reasons'])>=3,'dedupe with multiple priority reasons');
wq_assert($queue['summary']['unassigned']===1,'unassigned counted');
wq_assert($queue['summary']['overdue_first_contact']===1,'first contact SLA');
wq_assert($queue['summary']['task_overdue']===1,'overdue task');
wq_assert(sf_work_queue_build($state,1,2,'Counsellor',100,0,$clock)['total']===0,'counsellor cannot view unassigned or other school leads');
wq_assert(sf_work_queue_build($state,1,1,'Admin',1,1,$clock)['items']===[],'pagination');
$dir=sys_get_temp_dir().'/scrm-wq-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
try{
 $store=new SchoolFlowStore($dir);
 $store->transaction(static function(array &$s)use($state){
     $s=$state;
     $s['sessions']=[['token_hash'=>sf_hash('testadmin'),'user_id'=>1,'expires_at'=>time()+3600]];
 },true);
 $ok=sf_handle('GET','/api/schools/1/work-queue?limit=10',[],['authorization'=>'Bearer testadmin'],'127.0.0.1',$store);
 wq_assert($ok[0]===200 && isset($ok[1]['items']),'authenticated route');
 try{sf_handle('GET','/api/schools/2/work-queue',[],['authorization'=>'Bearer testadmin'],'127.0.0.1',$store);
     throw new RuntimeException('failed to block cross-school');}
 catch(SFError $e){wq_assert($e->httpStatus===403,'cross school forbidden');}
} finally {
 foreach(glob($dir.'/*')?:[] as $f)@unlink($f);
 @rmdir($dir);
}
echo "PASS: v2.2 work queue role isolation, priority, deduplication, pagination and API\n";
