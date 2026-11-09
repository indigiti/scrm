<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-master-leads-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function ml_assert(bool $condition,string $message):void{
 if(!$condition)throw new RuntimeException('Master leads: '.$message);
}
function ml_call(string $method,string $path,string $token='primary'):array{
 global $store;
 try{return sf_handle($method,$path,[],['authorization'=>'Bearer '.$token],'198.51.100.40',$store);}
 catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
 $store->transaction(static function(array &$s):void{
  foreach(['Campus A','Campus B','Unrelated Campus'] as $name)
   $s['schools'][]=['id'=>sf_id($s,'schools'),'guid'=>sf_uuid(),'name'=>$name];
  foreach([
   [1,'Company Admin','primary',[[1,'Admin'],[2,'Admin']]],
   [2,'Sales A','seller',[[1,'Counsellor']]],
   [3,'Campus B Admin','campus_b',[[2,'Admin']]],
   [4,'Unrelated Admin','outside',[[3,'Admin']]],
   [5,'Limited Admissions','admissions',[[1,'Admissions']]]
  ] as [$uid,$name,$token,$membership]){
   $s['users'][]=['id'=>$uid,'name'=>$name,'email'=>'person'.$uid.'@example.invalid','active'=>true];
   foreach($membership as [$sid,$role])
    $s['memberships'][]=['school_id'=>$sid,'user_id'=>$uid,'role'=>$role,'enabled'=>true];
   $s['sessions'][]=['user_id'=>$uid,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
  }
  foreach([
   [1,1,2,'New Enquiry','First Student','2026-11-01T10:00:00+00:00','2026-11-02'],
   [2,1,null,'Contacted','Second Student','2026-11-03T10:00:00+00:00','2026-12-02'],
   [3,2,3,'Enrolled','Third Student','2026-11-04T10:00:00+00:00',''],
   [4,3,4,'New Enquiry','Outside Student','2026-11-05T10:00:00+00:00','']
  ] as [$id,$sid,$owner,$status,$name,$created,$followup]){
   $s['leads'][]=[
    'id'=>$id,'guid'=>sf_uuid(),'school_id'=>$sid,'site_id'=>null,
    'assigned_to'=>$owner,'status'=>$status,'name'=>$name,'guardian'=>'Example Guardian',
    'phone'=>'0000000000','grade'=>'V','created_at'=>$created,
    'followup_date'=>$followup
   ];
  }
 },true);
 [$code,$data]=ml_call('GET','/api/admin/master-leads?per_page=2');
 ml_assert($code===200&&$data['total']===3&&count($data['rows'])===2&&$data['pages']===2,
  'pagination counts only authorized campuses');
 ml_assert($data['rows'][0]['id']===3&&$data['rows'][0]['school_id']===2,'descending sort');
 ml_assert($data['summary']['new']===1&&$data['summary']['unassigned']===1&&
  $data['summary']['converted']===1,'summary of filtered results');
 [$code,$page]=ml_call('GET','/api/admin/master-leads?page=2&per_page=2');
 ml_assert($code===200&&count($page['rows'])===1&&$page['rows'][0]['id']===1,'second page');
 [$code,$filter]=ml_call('GET','/api/admin/master-leads?school_id=1&assigned_to=unassigned');
 ml_assert($code===200&&$filter['total']===1&&$filter['rows'][0]['id']===2,'campus and owner filters');
 [$code,$filter]=ml_call('GET','/api/admin/master-leads?status=Enrolled');
 ml_assert($code===200&&$filter['total']===1&&$filter['rows'][0]['school_name']==='Campus B','stage filter');
 [$code,$filter]=ml_call('GET','/api/admin/master-leads?search=Third');
 ml_assert($code===200&&$filter['total']===1,'search across campuses');
 [$code,$filter]=ml_call('GET','/api/admin/master-leads?due=overdue');
 ml_assert($code===200&&$filter['total']===1&&$filter['rows'][0]['id']===1,'overdue filter excludes closed');
 ml_assert(ml_call('GET','/api/admin/master-leads?school_id=3')[0]===403,'unauthorized campus selection denied');
 ml_assert(ml_call('GET','/api/admin/master-leads','seller')[0]===403,'counsellor denied');
 ml_assert(ml_call('GET','/api/admin/master-leads','admissions')[0]===403,'admissions denied');
 ml_assert(ml_call('GET','/api/admin/master-leads','outside')[1]['total']===1,'unrelated admin sees own only');
 ml_assert(ml_call('GET','/api/admin/master-leads','campus_b')[1]['total']===1,'single campus admin sees own only');
 ml_assert(ml_call('GET','/api/admin/master-leads?per_page=101')[0]===422,'invalid page size denied');
 ml_assert(ml_call('GET','/api/admin/master-leads?status=MadeUp')[0]===422,'invalid status denied');
 ml_assert(ml_call('POST','/api/admin/master-leads')[0]===405,'read-only API');
 echo "PASS: admin-only master leads across authorized campuses, filtering, pagination, isolation and read-only access\n";
}finally{
 foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
 @rmdir($dir);
}
