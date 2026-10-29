<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function clean_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('cleanup integrity: '.$message);}
$dir=sys_get_temp_dir().'/scrm-cleanup-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);
try{
    $store=new SchoolFlowStore($dir);
    $store->transaction(static function(array &$s){
        $s['schools']=[['id'=>1,'name'=>'School A'],['id'=>2,'name'=>'School B']];
        $s['parent_profiles']=[
            ['id'=>1,'school_id'=>1,'guid'=>'demo-alone'],
            ['id'=>2,'school_id'=>1,'guid'=>'shared'],
            ['id'=>3,'school_id'=>1,'guid'=>'orphan-real'],
            ['id'=>4,'school_id'=>2,'guid'=>'other-school-orphan']];
        $s['leads']=[
            ['id'=>1,'school_id'=>1,'is_demo'=>true,'family_guid'=>'demo-alone','site_id'=>10],
            ['id'=>2,'school_id'=>1,'is_demo'=>true,'family_guid'=>'shared','site_id'=>10],
            ['id'=>3,'school_id'=>1,'is_demo'=>false,'family_guid'=>'shared','site_id'=>11,'duplicate_of'=>1],
            ['id'=>4,'school_id'=>2,'is_demo'=>false,'family_guid'=>'other-school-orphan','site_id'=>12]];
        $s['sites']=[
            ['id'=>10,'school_id'=>1,'is_demo'=>true,'enabled'=>false],
            ['id'=>11,'school_id'=>1,'enabled'=>true],
            ['id'=>12,'school_id'=>2,'enabled'=>true]];
        $s['activities']=[['school_id'=>1,'lead_id'=>1],['school_id'=>2,'lead_id'=>4]];
        $s['receipts']=[
            ['school_id'=>1,'chat_session_lead_id'=>1],
            ['school_id'=>1,'lead_id'=>3],
            ['school_id'=>2,'lead_id'=>4],
            ['school_id'=>1,'site_id'=>10]];
        $s['calls']=[['school_id'=>1,'lead_id'=>1],['school_id'=>2,'lead_id'=>4]];
    },true);
    $out=$store->transaction(static fn(array &$s)=>sf_demo_clear($s,1),true);
    clean_assert($out['removed_leads']===2&&$out['removed_sites']===1,'demo only deleted');
    $s=$store->transaction(static fn(array $s)=>$s);
    $f=array_column($s['parent_profiles'],'guid');
    clean_assert(!in_array('demo-alone',$f,true),'orphan demo profile deleted');
    foreach(['shared','orphan-real','other-school-orphan'] as $guid)
        clean_assert(in_array($guid,$f,true),'legitimate family profile preserved '.$guid);
    clean_assert(count($s['leads'])===2,'keeps real and other school enquiries');
    clean_assert($s['leads'][0]['duplicate_of']===null,'clears dangling duplicate reference');
    clean_assert(count($s['receipts'])===2,'removes demo chatbot receipt and source receipt');
    clean_assert(count($s['calls'])===1&&$s['calls'][0]['school_id']===2,'preserves other-school call');
    echo "PASS: v2.1 school-scoped demo data integrity with shared families and receipts\n";
}finally{
    foreach(glob($dir.'/*')?:[] as $f)@unlink($f);
    @rmdir($dir);
}
