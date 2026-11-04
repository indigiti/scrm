<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-pagepop-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function pp(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('Page popup: '.$message);}
function pp_req(string $method,string $path,array $payload=[],string $user='owner',
    string $origin='https://alpha.test'):array {
    global $store;
    try{return sf_handle($method,$path,$payload,
        ['authorization'=>'Bearer '.$user,'origin'=>$origin],'198.51.100.51',$store);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
    $store->transaction(static function(array &$s):void {
        foreach(['Alpha','Beta','Unrelated'] as $name)
            $s['schools'][]=['id'=>sf_id($s,'schools'),'name'=>$name,'created_at'=>sf_time()];
        $s['users'][]=['id'=>1,'name'=>'Group owner','email'=>'owner@example.invalid','active'=>true];
        $s['users'][]=['id'=>2,'name'=>'Outside owner','email'=>'outside@example.invalid','active'=>true];
        $s['users'][]=['id'=>3,'name'=>'Counsellor','email'=>'staff@example.invalid','active'=>true];
        foreach([[1,1,'Admin'],[1,2,'Admin'],[2,3,'Admin'],[3,1,'Counsellor']] as [$uid,$sid,$role])
            $s['memberships'][]=['user_id'=>$uid,'school_id'=>$sid,'role'=>$role];
        foreach(['owner','outside','staff'] as $n=>$token)
            $s['sessions'][]=['user_id'=>$n+1,'token_hash'=>sf_hash($token),
                'expires_at'=>time()+3600];
        $s['counters']['users']=3;
    },true);
    [$a,$alpha]=pp_req('POST','/api/group-sites',
        ['name'=>'Alpha School LP','domain'=>'alpha.test','school_ids'=>[1]]);
    [$b,$beta]=pp_req('POST','/api/group-sites',
        ['name'=>'Beta School LP','domain'=>'beta.test','school_ids'=>[2]]);
    [$sh,$shared]=pp_req('POST','/api/group-sites',
        ['name'=>'Shared Group','domain'=>'shared.test','school_ids'=>[1,2]]);
    pp($a===201&&$b===201&&$sh===201,'groups created');
    [$status,$one]=pp_req('GET','/api/group-sites?school_id=1');
    pp($status===200&&count($one)===2&&
        count(array_filter($one,fn($g)=>$g['id']===$beta['id']))===0,
        'Alpha school only receives Alpha and Shared forms');
    [$status,$two]=pp_req('GET','/api/group-sites?school_id=2');
    pp($status===200&&count($two)===2&&
        count(array_filter($two,fn($g)=>$g['id']===$alpha['id']))===0,
        'Beta school only receives Beta and Shared forms');
    pp(pp_req('GET','/api/group-sites?school_id=3')[0]===403,
        'owner cannot enumerate unrelated school');
    pp(pp_req('GET','/api/group-sites?school_id=%2e%2e')[0]===422,
        'invalid school filter blocked');
    pp(pp_req('GET','/api/group-sites?school_id=1',[],'staff')[0]===403,
        'non-admin cannot list group forms');
    pp(pp_req('GET','/api/group-sites?school_id=3',[],'outside')[1]===[],
        'unrelated admin never sees owners forms');

    $id=$alpha['id'];$path='/api/group-sites/'.$id.'/page-popups';
    [$ap,$settings]=pp_req('PATCH','/api/group-sites/'.$id.'/landing-pages',[
        'approved_urls'=>['https://alpha.test/lp/','https://campaign.test/apply/']]);
    pp($ap===200,'landing URLs approved');
    pp(pp_req('POST',$path,['name'=>'Invalid offsite',
        'landing_page'=>'https://unrelated.test/lp/','settings'=>['enabled'=>true]])[0]===422,
        'cannot attach popup to unrelated website');
    pp(pp_req('POST',$path,['name'=>'Unapproved secondary',
        'landing_page'=>'https://campaign.test/other','settings'=>['enabled'=>true]])[0]===422,
        'secondary page must be individually approved');
    pp(pp_req('POST',$path,['name'=>'Invalid campus',
        'landing_page'=>'https://alpha.test/lp/','settings'=>['enabled'=>true,'school_ids'=>[2]]])[0]===422,
        'campaign cannot route to school outside group');
    [$created,$campaign]=pp_req('POST',$path,[
        'name'=>'Admission page popup','landing_page'=>'https://alpha.test/lp/',
        'settings'=>['enabled'=>true,'trigger'=>'manual','school_ids'=>[1]]]);
    pp($created===201&&preg_match('/^[a-f0-9-]{36}$/',$campaign['guid'])===1,
        'GUID campaign created with secure targeting');
    $guid=$campaign['guid'];$form='/api/public/forms/'.$alpha['guid'];
    [$pubStatus,$config]=pp_req('GET',$form.'/config');
    pp($pubStatus===200&&count($config['page_popup_campaigns'])===1,
        'public config exposes one enabled popup');
    $client=$config['page_popup_campaigns'][0];
    pp($client['guid']===$guid&&$client['landing_page']==='https://alpha.test/lp'&&
        $client['popup']['school_codes']===['campus-1'],
        'public popup bound to correct landing URL and school');
    pp(!isset($client['api_key'])&&!isset($client['settings']['api_key']),
        'no private API credential in public response');
    pp(pp_req('POST',$path,['name'=>'Duplicate URL',
        'landing_page'=>'https://alpha.test/lp','settings'=>['enabled'=>true]])[0]===409,
        'one configured popup per landing page');
    pp(pp_req('GET',$path,[],'outside')[0]===404,'non-owner cannot list');
    pp(pp_req('PATCH',$path.'/'.$guid,[
        'name'=>'Disabled popup','landing_page'=>'https://alpha.test/lp',
        'settings'=>['enabled'=>false]])[0]===200,'popup can be disabled');
    pp(pp_req('GET',$form.'/config')[1]['page_popup_campaigns']===[],
        'disabled popup not exposed to the live browser');
    pp(pp_req('PATCH',$path.'/'.$guid,[
        'name'=>'Enabled popup','landing_page'=>'https://alpha.test/lp',
        'settings'=>['enabled'=>true]])[0]===200,'popup reenabled');
    pp(count(pp_req('GET',$form.'/config')[1]['page_popup_campaigns'])===1,
        'reenabled popup public');
    [$another,$second]=pp_req('POST',$path,[
        'name'=>'Secondary campaign','landing_page'=>'https://campaign.test/apply',
        'settings'=>['enabled'=>true]]);
    pp($another===201,'approved secondary page can have its own popup');
    pp(count(pp_req('GET',$form.'/config')[1]['page_popup_campaigns'])===2,
        'each LP independent and advertised by distinct GUID');
    pp(pp_req('PATCH','/api/group-sites/'.$id.'/landing-pages',
        ['approved_urls'=>['https://alpha.test/lp']])[0]===200,
        'remove secondary approval');
    pp(count(pp_req('GET',$form.'/config')[1]['page_popup_campaigns'])===1,
        'revoked landing approval stops its popup automatically');
    pp(pp_req('DELETE',$path.'/'.$guid)[0]===200,'deletion works');
    pp(count(pp_req('GET',$path)[1])===1,'only selected campaign removed');
    pp(pp_req('PATCH',$path.'/'.$guid,
        ['name'=>'Deleted','landing_page'=>'https://alpha.test/lp',
        'settings'=>['enabled'=>true]])[0]===404,
        'deleted campaign GUID can never be edited');
    pp(pp_req('GET',$form.'/config')[1]['page_popup_campaigns']===[],
        'removed and revoked popup assets are not offered to the browser');
    echo "PASS: active-school group scope, page popup CRUD, GUID targeting, revocation and school access\n";
}finally{
    foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);
}
