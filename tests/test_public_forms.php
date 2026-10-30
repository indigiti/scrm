<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/sf-public-forms-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function pf(bool $ok,string $note): void {if(!$ok)throw new RuntimeException('Public form test: '.$note);}
function req(string $method,string $path,array $payload=[],string $origin='https://lexiconedu.in',string $idem='',string $auth=''):array {
    global $store;
    try {
        return sf_handle($method,$path,$payload,['origin'=>$origin,'idempotency-key'=>$idem,'authorization'=>$auth?'Bearer '.$auth:''],'198.51.100.3',$store);
    } catch(SFError $e) {return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try {
    $store->transaction(function(array &$s){
        foreach(['Wagholi','Hadapsar','Unrelated Campus'] as $name) {
            $s['schools'][]=['id'=>sf_id($s,'schools'),'name'=>$name];
        }
        $s['users'][]=['id'=>1,'name'=>'Admin','email'=>'admin@example.invalid','active'=>true];
        $s['counters']['users']=1;
        foreach([1,2] as $school)$s['memberships'][]=['user_id'=>1,'school_id'=>$school,'role'=>'Admin'];
        $s['sessions'][]=['user_id'=>1,'token_hash'=>sf_hash('admin'),'expires_at'=>time()+600];
    },true);
    [$code,$created]=req('POST','/api/group-sites',['name'=>'Lexicon Schools','domain'=>'lexiconedu.in','school_ids'=>[1,2]],'https://lexiconedu.in','','admin');
    pf($code===201,'create group via existing admin API');
    $guid=$created['guid'];$base='/api/public/forms/'.$guid;
    pf(preg_match('/^[0-9a-f-]{36}$/D',$guid)===1,'group exposes public GUID');
    pf(sf_public_allowed_origin_path($store,$base.'/config','https://lexiconedu.in'),'configured site allowed');
    pf(sf_public_allowed_origin_path($store,$base.'/config','https://www.lexiconedu.in'),'www variant allowed');
    pf(!sf_public_allowed_origin_path($store,$base.'/config','https://attacker.test'),'unrelated site blocked');
    pf(!sf_public_allowed_origin_path($store,$base.'/config','http://lexiconedu.in'),'insecure HTTP blocked');
    [$code,$config]=req('GET',$base.'/config');
    pf($code===200 && count($config['schools'])===2 && !str_contains(json_encode($config),'sfgroup_'),'keyless public campus list');
    pf(req('GET',$base.'/config',[],'https://attacker.test')[0]===403,'origin rejected by application too');
    [$code,$challenge]=req('GET',$base.'/challenge');
    pf($code===200 && str_starts_with($challenge['question'],'Solve: '),'challenge issued by CRM');
    preg_match('/Solve: (\d+) \+ (\d+)/',$challenge['question'],$parts);
    $correct=(string)((int)$parts[1]+(int)$parts[2]);
    $payload=['student_name'=>'Example Pupil','parent_name'=>'Example Guardian','phone'=>'9876543210',
        'email'=>'guardian@example.org','school_code'=>'campus-2','grade'=>'II','consent'=>true,
        'captcha_token'=>$challenge['token'],'captcha_answer'=>$correct,'pincode'=>'411014',
        'state'=>'Maharashtra','city'=>'Pune','landing_page'=>'https://lexiconedu.in/admissions',
        'utm_source'=>'search','utm_campaign'=>'admissions','school_id'=>1];
    pf(req('POST',$base.'/enquiries',$payload,'https://attacker.test','public-attempt-1')[0]===403,'cross-origin blocked');
    pf(req('POST',$base.'/enquiries',[...$payload,'captcha_answer'=>'0'],'https://lexiconedu.in','public-attempt-2')[0]===422,'wrong CAPTCHA blocked');
    pf(req('POST',$base.'/enquiries',[...$payload,'consent'=>false],'https://lexiconedu.in','public-attempt-3')[0]===422,'consent enforced');
    pf(req('POST',$base.'/enquiries',[...$payload,'school_code'=>'campus-3'],'https://lexiconedu.in','public-attempt-4')[0]===422,'unauthorized school rejected');
    pf(req('POST',$base.'/enquiries',[...$payload,'school_code'=>'Campus'],'https://lexiconedu.in','public-attempt-placeholder')[0]===422,'placeholder Campus is never a valid school choice');
    pf(req('POST',$base.'/enquiries',[...$payload,'school_code'=>''],'https://lexiconedu.in','public-attempt-empty-campus')[0]===422,'empty campus selection rejected');
    [$code,$ok]=req('POST',$base.'/enquiries',$payload,'https://lexiconedu.in','public-attempt-good');
    pf($code===201 && !empty($ok['guid']),'accepted new lead with GUID');
    pf(req('POST',$base.'/enquiries',$payload,'https://lexiconedu.in','public-attempt-good')[1]['duplicate_submission']===true,'idempotency response');
    pf(req('POST',$base.'/enquiries',$payload,'https://lexiconedu.in','public-attempt-reused')[0]===409,'challenge replay rejected');
    [$code,$another]=req('GET',$base.'/challenge');
    preg_match('/Solve: (\d+) \+ (\d+)/',$another['question'],$next);
    $anotherPayload=[...$payload,'captcha_token'=>$another['token'],'captcha_answer'=>(string)((int)$next[1]+(int)$next[2])];
    pf(req('POST',$base.'/enquiries',[...$anotherPayload,'school_code'=>'campus-1'],'https://lexiconedu.in','public-attempt-unique')[0]===201,'distinct campus accepted');
    $snapshot=$store->transaction(fn($s)=>$s);
    $first=array_values(array_filter($snapshot['leads'],fn($l)=>$l['school_id']===2));
    pf(count($first)===1 && $first[0]['state']==='Maharashtra' && $first[0]['city']==='Pune','geographic fields stored under intended campus');
    pf($first[0]['utm_source']==='search' && $first[0]['pincode']==='411014','marketing and PIN captured');
    pf(count(array_filter($snapshot['leads'],fn($l)=>$l['school_id']===3))===0,'unrelated campus unchanged');
    pf(is_file($dir.'/public-form.key') && strlen(file_get_contents($dir.'/public-form.key'))===32,'private CAPTCHA signing key');
    pf(sf_public_parse_pin('[{"Status":"Success","PostOffice":[{"State":"Maharashtra","District":"Pune"}]}]')===['state'=>'Maharashtra','city'=>'Pune'],'PIN parser');
    pf(sf_public_parse_pin('[{"Status":"Error","PostOffice":null}]')===null,'PIN not found');
    echo "PASS: public form origins, campus list, HMAC CAPTCHA, consent, routing, idempotency, metadata, PIN parse\n";
}finally{
    foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);
}
