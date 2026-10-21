<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function ef(bool $result,string $message):void {
    if(!$result)throw new RuntimeException('GUID embed test: '.$message);
}
$dir=sys_get_temp_dir().'/sf-embed-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function er(string $method,string $path,array $p=[],string $token='admin'):array {
    global $store;
    try {
        return sf_handle($method,$path,$p,[
            'authorization'=>'Bearer '.$token,'origin'=>'https://school.test'
        ],'198.51.100.88',$store);
    }catch(SFError $error){return [$error->httpStatus,['detail'=>$error->getMessage()]];}
}
try {
    $store->transaction(static function(array &$s):void {
        $s['schools'][]=['id'=>1,'guid'=>sf_uuid(),'name'=>'School'];
        $s['counters']['schools']=1;
        $s['users'][]=['id'=>1,'active'=>true,'name'=>'Owner','email'=>'owner@example.invalid'];
        $s['users'][]=['id'=>2,'active'=>true,'name'=>'Other','email'=>'other@example.invalid'];
        $s['counters']['users']=2;
        $s['memberships'][]=['school_id'=>1,'user_id'=>1,'role'=>'Admin'];
        $s['memberships'][]=['school_id'=>1,'user_id'=>2,'role'=>'Counsellor'];
        $s['sessions'][]=['user_id'=>1,'token_hash'=>sf_hash('admin'),'expires_at'=>time()+600];
        $s['sessions'][]=['user_id'=>2,'token_hash'=>sf_hash('other'),'expires_at'=>time()+600];
    },true);
    [$createdStatus,$group]=er('POST','/api/group-sites',[
       'name'=>'School Website','domain'=>'school.test','school_ids'=>[1]]);
    ef($createdStatus===201,'admin can create school group');
    $first=$group['guid'];$id=(int)$group['id'];
    ef(preg_match('/^[0-9a-f-]{36}$/D',$first)===1,'existing UUID public form ID');
    $root=dirname(__DIR__).'/public_html';
    // Keep old .js URLs functional, but use the extensionless URL for new
    // website installations to avoid a static-file CDN cache lifetime.
    $gateway=file_get_contents($root.'/index.php');
    $dashboard=file_get_contents($root.'/static/app.js');
    ef(is_string($gateway) && str_contains($gateway,'(?:\\.js)?'),
        'gateway handles both extensionless and historical .js embeds');
    ef(is_string($dashboard) && str_contains($dashboard,"encodeURIComponent(guid)+'"),
        'dashboard generates extensionless dynamic form script');
    $js=sf_public_embed_script($store,$first,$root);
    ef(str_contains($js,'static/lexicon-form.js?v='),'versioned original form script');
    ef(str_contains($js,'data-schoolflow-chat-version'),'chat JS fingerprint passed through');
    ef(str_contains($js,$first),'GUID route embedded');
    ef(str_contains($js,substr(hash_file('sha256',$root.'/static/lexicon-form.js'),0,16)),'JS fingerprint matches release asset');
    ef(str_contains($js,'data-thank-you'),'optional website customization preserved');
    ef(er('POST','/api/group-sites/'.$id.'/refresh-form-id',[],'other')[0]===403,'counsellor cannot refresh form');
    [$status,$updated]=er('POST','/api/group-sites/'.$id.'/refresh-form-id',[]);
    ef($status===200,'owner refreshes form ID from dashboard');
    $second=$updated['guid'];
    ef($first!==$second,'form ID rotated');
    ef($updated['schools']=== $group['schools'],'campus routes unchanged');
    ef(er('GET','/api/public/forms/'.$first.'/config')[0]===403,'old public API GUID invalid');
    try {
        sf_public_embed_script($store,$first,$root);
        ef(false,'old GUID must stop serving');
    } catch(SFError $error){
        ef($error->httpStatus===404,'old GUID embed rejected');
    }
    $nextScript=sf_public_embed_script($store,$second,$root);
    ef(str_contains($nextScript,$second),'new GUID embed works');
    $state=$store->transaction(static fn(array $s)=>$s);
    ef(count($state['group_sites'])===1,'refresh does not duplicate group');
    ef($state['group_sites'][0]['id']===$id,'internal ID unchanged');
    ef(!empty($state['group_sites'][0]['key_hash']),'private key remains configured');
    ef(count($state['leads'])===0,'no synthetic leads created during refresh');
    echo "PASS: GUID based bootstrap, asset hashes, form ID refresh and scoped authorization\n";
}finally{
    foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);
}
