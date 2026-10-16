<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-auto-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function dc(bool $v,string $why):void{if(!$v)throw new RuntimeException('Release D: '.$why);}
function dr(string $method,string $path,array $p=[],string $auth='admin'):array {
 global $store;
 try{return sf_handle($method,$path,$p,['authorization'=>'Bearer '.$auth],'198.51.100.3',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(function(&$s){
   foreach([1,2] as $id)$s['schools'][]=['id'=>$id,'name'=>'School '.$id];
   foreach([1,2] as $id){
       $s['users'][]=['id'=>$id,'name'=>'Admin '.$id,'email'=>'admin'.$id.'@example.invalid','active'=>true];
       $s['memberships'][]=['user_id'=>$id,'school_id'=>$id,'role'=>'Admin'];
   }
   foreach(['admin'=>1,'outsider'=>2] as $token=>$id)$s['sessions'][]=['user_id'=>$id,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
 },true);
 $sid=1;$prefix='/api/schools/1';
 [$ok,$lead]=dr('POST',$prefix.'/leads',['student_name'=>'Example Pupil','parent_name'=>'Example Guardian',
     'phone'=>'9876543210','email'=>'guardian@example.org','consent'=>true]);
 dc($ok===201,'lead created');
 $provider=['email_provider'=>'resend','wa_provider'=>'off','email_from'=>'admissions@example.org',
   'email_api_key'=>'re_example_TEST_ONLY','wa_api_key'=>'','phone_number_id'=>'','wa_template'=>'','wa_language'=>'en_US'];
 dc(dr('PATCH',$prefix.'/communications',$provider)[0]===200,'provider settings stored encrypted');
 $count=0;
 $mock=static function($channel,$body,$nonce)use(&$count){$count++;return ['ok'=>true,'provider_id'=>'provider-test-'.$count];};
 $result=sf_auto_run($store,$mock);
 dc($count===0 && $result['enqueued']===0,'disabled by default means zero sends');
 $policy=['enabled'=>true,'email'=>true,'whatsapp'=>false,'task_kinds'=>['First Contact'],'max_daily'=>5,'send_start'=>'00:00','send_end'=>'23:59'];
 dc(dr('PATCH',$prefix.'/automation',$policy)[0]===200,'administrator enabled narrowly scoped policy');
 sf_auto_run($store,$mock);
 dc($count===0,'generic contact consent is not sufficient for automated email');
 dc(dr('PATCH',$prefix.'/leads/'.$lead['id'],['consent_email'=>true,'consent_source'=>'Parent explicitly requested email follow-ups'])[0]===200,'channel consent saved with evidence');
 [$ok,$consented]=dr('GET',$prefix.'/leads/'.$lead['id']);
 dc($ok===200 && $consented['lead']['consent_email']===true,'consent visible to authorized school');
 $result=sf_auto_run($store,$mock);
 dc($count===1 && $result['provider_accepted']===1,'worker sends once after explicit opt-in');
 sf_auto_run($store,$mock);
 dc($count===1,'repeated cron does not resend same reminder');
 $queue=dr('GET',$prefix.'/automation/queue')[1];
 dc(count($queue)===1 && $queue[0]['status']==='accepted','provider acceptance is not delivery');
 dc(dr('GET',$prefix.'/automation',[],'outsider')[0]===403,'other campus blocked from automation settings');
 dc(dr('POST',$prefix.'/automation/queue/'.$queue[0]['id'].'/reconcile',
   ['status'=>'delivered','provider_evidence_reference'=>'provider-dashboard-20261014'])[0]===200,'evidence-based reconciliation');
 dc(dr('GET',$prefix.'/automation/queue')[1][0]['status']==='delivered','delivery changes only after reconciliation');
 [$ok,$next]=dr('POST',$prefix.'/leads',['student_name'=>'Second Example','parent_name'=>'Second Guardian',
     'phone'=>'9876543211','email'=>'second@example.org','consent'=>true]);
 dc($ok===201,'second lead created');
 dr('PATCH',$prefix.'/leads/'.$next['id'],['consent_email'=>true,'consent_source'=>'Parent gave specific email authorization']);
 $failCount=0;
 $fail=static function($channel,$body,$nonce)use(&$failCount){$failCount++;return ['ok'=>false,'http'=>504];};
 sf_auto_run($store,$fail);
 dc($failCount===1,'failed response handled');
 sf_auto_run($store,$fail);
 dc($failCount===1,'no unsafe automatic retries for ambiguous failed send');
 $queue=dr('GET',$prefix.'/automation/queue')[1];
 dc(count(array_filter($queue,fn($q)=>$q['status']==='needs_review'))===1,'failure held for manual review');
 dr('PATCH',$prefix.'/automation',['enabled'=>false,'email'=>false,'whatsapp'=>false,'task_kinds'=>[],'max_daily'=>5]);
 dc(!sf_auto_policy($store->transaction(fn($s)=>$s),1)['enabled'],'operator can disable immediately');
 $snapshot=$store->transaction(fn($s)=>$s);
 dc(!str_contains(json_encode($snapshot),'re_example_TEST_ONLY'),'encrypted provider secret never stored as plaintext');
 dc(count($snapshot['audit_logs'])>=4,'audit trail includes automated sending and settings');
 echo "PASS: Release D disabled defaults, consent, encrypted provider key, idempotency, tenant isolation, reconciliation and no blind retries\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
