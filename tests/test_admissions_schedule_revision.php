<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-revision-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function sc(bool $v,string $msg):void{if(!$v)throw new RuntimeException('Schedule regression: '.$msg);}
try{
 $today=sf_admission_today();
 $tomorrow=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
 $store->transaction(function(&$s)use($store,$today){
  $s['schools'][]=['id'=>1,'name'=>'School One'];
  $s['leads'][]=['id'=>1,'guid'=>sf_uuid(),'school_id'=>1,'name'=>'Example Student','guardian'=>'Example Parent',
      'phone'=>'9876543210','normalized_phone'=>'9876543210','email'=>'parent@example.org',
      'consent'=>true,'consent_email'=>true,'status'=>'Counselling','assigned_to'=>null,'created_at'=>sf_time()];
  $s['counters']['leads']=1;
  $s['tasks'][]=['id'=>1,'guid'=>sf_uuid(),'school_id'=>1,'lead_id'=>1,'kind'=>'Follow-up',
      'title'=>'Follow up with parent','due_date'=>$today,'revision'=>1,'status'=>'Open','created_at'=>sf_time()];
  $s['counters']['tasks']=1;
  $s['automation_settings']['1']=['enabled'=>true,'email'=>true,'whatsapp'=>false,'task_kinds'=>['Follow-up'],'max_daily'=>5];
  $s['communication_settings']['1']=['email_provider'=>'resend','email_from'=>'admissions@example.org',
     'email_key'=>$store->seal('re_TEST_NOT_REAL'),'wa_provider'=>'off'];
 },true);
 $enqueued=$store->transaction(fn(&$s)=>sf_auto_enqueue_due($s),true);
 sc($enqueued===1,'queue due task');
 $store->transaction(function(&$s)use($tomorrow){
  sf_task_schedule($s,$s['leads'][0],'Follow-up',$tomorrow);
 },true);
 $count=0;
 $mock=static function($channel,$body,$nonce)use(&$count){$count++;return ['ok'=>true,'provider_id'=>'example-id'];};
 sf_auto_run($store,$mock);
 sc($count===0,'stale notification must not be sent after rescheduling');
 $s=$store->transaction(fn($s)=>$s);
 sc($s['notification_queue'][0]['status']==='skipped','stale queue marked skipped');
 sc($s['tasks'][0]['revision']===2,'rescheduling increments revision');
 $store->transaction(function(&$s)use($today){sf_task_schedule($s,$s['leads'][0],'Follow-up',$today);},true);
 sf_auto_run($store,$mock);
 sc($count===1,'new schedule revision can send exactly once');
 $s=$store->transaction(fn($s)=>$s);
 sc(count($s['notification_queue'])===2 && $s['tasks'][0]['revision']===3,'distinct schedules have distinct keys');
 $store->transaction(function(&$s)use($today){sf_task_schedule($s,$s['leads'][0],'Follow-up',$today);},true);
 sf_auto_run($store,$mock);
 sc($count===1,'repeated cron and unchanged due date never double-send');
 echo "PASS: rescheduling suppresses stale reminders; revision-keyed due tasks send once\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir);}
