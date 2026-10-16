<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-historical-import-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function imp_assert(bool $yes,string $why):void{if(!$yes)throw new RuntimeException('Import: '.$why);}
function imp_req(string $method,string $uri,array $payload=[],string $token='admin'):array{
 global $store;
 try{return sf_handle($method,$uri,$payload,['authorization'=>'Bearer '.$token],'198.51.100.4',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(static function(array &$s){
   foreach([1,2] as $sid)$s['schools'][]=['id'=>$sid,'guid'=>sf_uuid(),'name'=>$sid===1?'Kalyani Nagar':'Wagholi'];
   foreach([1,2,3] as $uid){
      $s['users'][]=['id'=>$uid,'name'=>'Admin '.$uid,'email'=>'a'.$uid.'@example.invalid','active'=>true];
      $s['memberships'][]=['school_id'=>$uid===3?2:1,'user_id'=>$uid,'role'=>$uid===2?'Counsellor':'Admin'];
   }
   foreach(['admin'=>1,'counsellor'=>2,'other'=>3] as $token=>$uid)
      $s['sessions'][]=['user_id'=>$uid,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
 },true);
 $csv="Timestamp,Name,Email,Phone,City,State,Class,Campus,,Academic year\n".
      "03/09/2026 10:45:32 AM,Aarav Example,parent@example.org,09876543210,Pune,Maharashtra,I,Kalyani Nagar,\"Parent will come for a visit next week\",2027-28\n".
      "04/09/2026 11:00:00 AM,Diya Example,second@example.org,9999999011,Pune,Maharashtra,LKG,Kalyani Nagar,\"Looking for 2027-28\",2027-28\n";
 $payload=['filename'=>'historical.csv','base64'=>base64_encode($csv)];
 $base='/api/schools/1/imports';
 imp_assert(imp_req('POST',$base.'/preview',$payload,'counsellor')[0]===403,'counsellor cannot preview');
 imp_assert(imp_req('POST','/api/schools/2/imports/preview',$payload,'other')[1]['invalid']===2,'other campus rows rejected');
 [$ok,$preview]=imp_req('POST',$base.'/preview',$payload);
 imp_assert($ok===200&&$preview['valid']===2&&$preview['invalid']===0,'preview reads original headers and two rows');
 imp_assert(($preview['mapping']['remarks']??-1)===8,'unlabeled ninth-column remarks mapped');
 imp_assert($preview['possible_duplicates']===0,'no matching records');
 imp_assert(imp_req('POST',$base.'/commit',$payload)[0]===422,'requires explicit consent to commit');
 [$ok,$result]=imp_req('POST',$base.'/commit',$payload+['preview_guid'=>$preview['preview_guid'],'confirm'=>true]);
 imp_assert($ok===201 && $result['imported']===2&&$result['notes']===2,'two safe historical leads and notes imported');
 $snap=$store->transaction(fn($s)=>$s);
 $lead=array_values(array_filter($snap['leads'],fn($r)=>$r['school_id']===1&&$r['name']==='Aarav Example'))[0];
 imp_assert(str_starts_with($lead['created_at'],'2026-09-03T05:15:32'),'India time preserved as UTC instant');
 imp_assert($lead['consent']===0&&$lead['consent_email']===false,'historical upload never grants messaging permission');
 imp_assert($lead['academic_year']==='2027-28','mapped academic year preserved with the enquiry');
 imp_assert(count(array_filter($snap['tasks'],fn($t)=>$t['lead_id']===$lead['id']&&$t['status']==='Open'))===0,'no overdue parent-contact tasks created from import');
 imp_assert(count(array_filter($snap['activities'],fn($a)=>$a['lead_id']===$lead['id']&&$a['action']==='note'))===1,'remarks imported as timestamped history');
 imp_assert(imp_req('POST',$base.'/commit',$payload+['preview_guid'=>$preview['preview_guid'],'confirm'=>true])[0]===409,'preview receipt is single-use');
 [$ok,$again]=imp_req('POST',$base.'/preview',$payload);
 imp_assert($ok===200&&$again['possible_duplicates']===2,'repeat preview shows existing student matches');
 [$ok,$againDone]=imp_req('POST',$base.'/commit',$payload+['preview_guid'=>$again['preview_guid'],'confirm'=>true]);
 imp_assert($ok===201 && $againDone['skipped']===2 && $againDone['imported']===0,'reimport is non-destructive');
 $batch=$result['batch_guid'];
 imp_assert(imp_req('POST',"/api/schools/1/imports/$batch/rollback",[],'other')[0]===403,'cross-school rollback denied');
 [$ok,$rollback]=imp_req('POST',"/api/schools/1/imports/$batch/rollback");
 imp_assert($ok===200&&$rollback['rolled_back']===2,'admin may roll back unchanged import batch');
 $snap=$store->transaction(fn($s)=>$s);
 imp_assert(count($snap['leads'])===0&&count($snap['parent_profiles'])===0,'rollback leaves no orphaned imported records');
 // Native XLSX from the existing download engine is supported without requiring a new PHP extension.
 $xlsx=sf_xlsx_build([['2026-09-03T05:15:32+00:00','Rohan Example','test@example.org','09876543210','Pune',
      'Maharashtra','I','Kalyani Nagar','Spreadsheet remark','Parent']],[]);
 [$ok,$excel]=imp_req('POST',$base.'/preview',['filename'=>'old-report.xlsx','base64'=>base64_encode($xlsx)]);
 imp_assert($ok===200&&$excel['valid']===1,'native XLSX import accepts Excel workbook with inline strings and dates');
 echo "PASS: CSV/XLSX historical preview, unlabeled remarks, time preservation, no consent, isolation, idempotent import and rollback\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir);}
