<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-live-register-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function rg_ok(bool $yes,string $why):void {if(!$yes)throw new RuntimeException('Admission register: '.$why);}
function rg_req(string $method,string $url,array $body=[],string $who='admin'):array{
    global $store;
    try{return sf_handle($method,$url,$body,['authorization'=>'Bearer '.$who],'198.51.100.87',$store);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(static function(array &$s):void {
    $s['schools'][]=['id'=>1,'guid'=>sf_uuid(),'name'=>'Hadapsar'];
    $s['schools'][]=['id'=>2,'guid'=>sf_uuid(),'name'=>'Wagholi'];
    foreach([[1,'Principal','Admin',1,'admin'],[2,'Vaishali','Counsellor',1,'counsellor'],
        [3,'Admissions Office','Admissions',1,'admissions'],[4,'Other Campus','Admin',2,'outside']] as
        [$uid,$name,$role,$sid,$token]){
        $s['users'][]=['id'=>$uid,'name'=>$name,'email'=>'member'.$uid.'@example.invalid','active'=>true];
        $s['memberships'][]=['school_id'=>$sid,'user_id'=>$uid,'role'=>$role];
        $s['sessions'][]=['user_id'=>$uid,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
    }
 },true);
 $base='/api/schools/1';
 [$c,$a]=rg_req('POST',$base.'/leads',['student_name'=>'Fictional A','parent_name'=>'Guardian A',
    'phone'=>'09876543210','email'=>'a@example.invalid','city'=>'Pune','state'=>'Maharashtra',
    'grade'=>'VIII','utm_source'=>'google','consent'=>false,'assigned_to'=>2]);
 rg_ok($c===201,'school enquiry created');
 [$c,$b]=rg_req('POST',$base.'/leads',['student_name'=>'Fictional B','parent_name'=>'Guardian B',
    'phone'=>'9876501234','grade'=>'III','consent'=>false,'assigned_to'=>3]);
 rg_ok($c===201,'second school enquiry created');
 rg_req('POST',$base.'/leads/'.$a['id'].'/calls',
    ['outcome'=>'No Answer','note'=>'Second call no response']);
 rg_req('POST',$base.'/leads/'.$a['id'].'/notes',
    ['text'=>'Guardian requested a follow-up <next week>']);
 [$c,$other]=rg_req('POST','/api/schools/2/leads',
    ['student_name'=>'Other Campus Student','parent_name'=>'Other Parent','phone'=>'9777777777','grade'=>'I'],'outside');
 rg_ok($c===201,'different campus test lead created');
 [$c,$register]=rg_req('GET',$base.'/admission-register?per_page=1');
 rg_ok($c===200&&$register['total']===2&&count($register['rows'])===1&&
    $register['pages']===2&&$register['has_more'],'server-side 1-row pagination');
 [$c,$page2]=rg_req('GET',$base.'/admission-register?per_page=1&page=2');
 rg_ok($c===200&&$page2['total']===2&&count($page2['rows'])===1&&!$page2['has_more'],'second page');
 [$c,$filtered]=rg_req('GET',$base.'/admission-register?grade=VIII&outcome=No%20Answer');
 rg_ok($c===200&&$filtered['total']===1&&$filtered['rows'][0]['student']==='Fictional A','class and outcome filters');
 rg_ok($filtered['rows'][0]['phone']==='09876543210','leading zero preserved as text');
 rg_ok(str_contains($filtered['rows'][0]['last_remark'],'follow-up'),'latest call or note indexed');
 rg_ok($filtered['rows'][0]['assigned_name']==='Vaishali','school staff name in register');
 [$c,$byNote]=rg_req('GET',$base.'/admission-register?search=follow-up');
 rg_ok($c===200&&$byNote['total']===1,'search includes conversation remarks');
 [$c,$bySource]=rg_req('GET',$base.'/admission-register?source=google');
 rg_ok($c===200&&$bySource['total']===1,'source filter');
 [$c,$history]=rg_req('GET',$base.'/admission-register?view=history');
 rg_ok($c===200&&$history['total']===2,'notes and calls appear as two dated activities');
 rg_ok(count(array_filter($history['rows'],static fn(array $e):bool=>$e['type']==='Call'))===1,'call row present');
 rg_ok(!str_contains(json_encode($history),'Other Campus Student'),'no foreign data in history');
 [$c,$performance]=rg_req('GET',$base.'/admission-register?view=performance');
 rg_ok($c===200&&$performance['summary']['enquiries']===2,'performance summary');
 $staffRow=array_values(array_filter($performance['rows'],static fn(array $r):bool=>$r['user_id']===2));
 rg_ok(count($staffRow)===1&&$staffRow[0]['assigned']===1,'assigned staff metrics');
 [$c,$own]=rg_req('GET',$base.'/admission-register',[],'counsellor'); // method intentionally invalid payload shape; no write
 rg_ok($c===200&&$own['total']===1&&$own['rows'][0]['student']==='Fictional A','counsellor only assigned enquiry');
 [$c,$ownHistory]=rg_req('GET',$base.'/admission-register?view=history',[],'counsellor');
 rg_ok($c===200&&$ownHistory['total']===2,'counsellor only permitted conversation history');
 rg_ok(rg_req('GET','/api/schools/2/admission-register',[],'counsellor')[0]===403,'counsellor cannot access other campus');
 rg_ok(rg_req('GET',$base.'/admission-register',[],'outside')[0]===403,'other campus administrator blocked');
 rg_ok(rg_req('GET',$base.'/admission-register?view=unknown')[0]===422,'invalid report view rejected');
 rg_ok(rg_req('GET',$base.'/admission-register?per_page=500')[0]===422,'unbounded report page rejected');
 rg_ok(rg_req('GET',$base.'/admission-register?sort%5Bname%5D=asc')[0]===422,'nested query filter rejected');
 rg_ok(rg_req('GET',$base.'/admission-register?assigned=999')[1]['total']===0,'staff filter cannot widen scope');
 [$followStatus]=rg_req('PATCH',$base.'/leads/'.$a['id'],['followup_date'=>sf_admission_today()]);
 rg_ok($followStatus===200,'follow-up scheduled for today');
 [$dueStatus,$dueRows]=rg_req('GET',$base.'/admission-register?due=due');
 rg_ok($dueStatus===200&&$dueRows['total']===1&&$dueRows['rows'][0]['id']===$a['id'],
    'due filter includes an enquiry scheduled for today');

 [$c,$xlsx]=rg_req('POST',$base.'/exports/admissions.xlsx',
    ['grade'=>'VIII','call_outcome'=>'No Answer','search'=>'follow-up']);
 rg_ok($c===200&&str_starts_with($xlsx,'PK')&&strlen($xlsx)>300,'filtered XLSX created');
 rg_ok(rg_req('POST',$base.'/exports/admissions.xlsx',['call_outcome'=>'forged'])[0]===422,
    'invalid XLSX call filter blocked');
 [$c,$csvReport]=rg_req('POST',$base.'/exports/admissions-register.csv',
    ['grade'=>'VIII','call_outcome'=>'No Answer']);
 rg_ok($c===200&&str_starts_with($csvReport,"\xEF\xBB\xBF")&&
    str_contains($csvReport,'Assigned To')&&str_contains($csvReport,'Vaishali'),
    'CSV export preserves school staff and Google Sheet columns');
 rg_ok(str_contains($csvReport,"'09876543210"),'CSV preserves phone leading zero as text');
 rg_ok(!str_contains($csvReport,'Fictional B')&&!str_contains($csvReport,'Other Campus Student'),
    'CSV export obeys filters and campus isolation');
 rg_ok(rg_req('POST',$base.'/exports/admissions-register.csv',[],'outside')[0]===403,
    'CSV export rejects different campus user');
 // Import the user's spreadsheet-style Assigned To header via an existing,
 // school-scoped staff name and preserve the saved original remark.
 $csv="Timestamp,Name,Email,Phone,City,State,Class,Campus,Assigned To,Remarks\n".
      "02/09/2026 10:45:32 AM,Imported Example,import@example.invalid,09999999000,Pune,Maharashtra,IX,Hadapsar,Vaishali,Parent to call back\n";
 $payload=['filename'=>'roster.csv','base64'=>base64_encode($csv)];
 [$c,$preview]=rg_req('POST',$base.'/imports/preview',$payload);
 rg_ok($c===200&&$preview['valid']===1&&($preview['mapping']['assigned_to']??null)===8,'Assigned To column mapped and validated');
 [$c,$saved]=rg_req('POST',$base.'/imports/commit',$payload+['preview_guid'=>$preview['preview_guid'],'confirm'=>true]);
 rg_ok($c===201&&$saved['imported']===1,'assigned historical lead safely imported');
 $snapshot=$store->transaction(static fn(array $s)=>$s);
 $imported=array_values(array_filter($snapshot['leads'],static fn(array $v):bool=>($v['name']??'')==='Imported Example'))[0];
 rg_ok($imported['assigned_to']===2&&$imported['consent']===0,'import assigned by validated school staff without granting consent');
 $bad=str_replace('Vaishali','Unknown User',$csv);
 [$c,$rejected]=rg_req('POST',$base.'/imports/preview',['filename'=>'roster.csv','base64'=>base64_encode($bad)]);
 rg_ok($c===200&&$rejected['invalid']===1&&str_contains(implode(' ',$rejected['samples'][0]['issues']),'Assigned To'),
    'unknown staff flagged in preview rather than silently assigned');
 $store->transaction(static function(array &$s):void{
    $s['users'][]=['id'=>5,'name'=>'Vaishali','email'=>'duplicate@example.invalid','active'=>true];
    $s['memberships'][]=['school_id'=>1,'user_id'=>5,'role'=>'Admissions'];
 },true);
 [$c,$ambiguous]=rg_req('POST',$base.'/imports/preview',['filename'=>'roster.csv','base64'=>base64_encode($csv)]);
 rg_ok($c===200&&$ambiguous['invalid']===1,'duplicate active staff names require manual review');
 echo "PASS: paginated operational report, calling remarks, permission isolation, XLSX filtering and safe Assigned To imports\n";
}finally{
 foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
 @rmdir($dir);
}
