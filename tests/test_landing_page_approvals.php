<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function la(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Landing approvals: '.$message);}
$dir=sys_get_temp_dir().'/sf-landings-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);$store=new SchoolFlowStore($dir);
function lr(string $method,string $path,array $payload=[],string $origin='https://school.test',string $token='owner',string $idem=''):array{
    global $store;
    try{return sf_handle($method,$path,$payload,
        ['authorization'=>'Bearer '.$token,'origin'=>$origin,'idempotency-key'=>$idem],
        '198.51.100.99',$store);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
    $store->transaction(static function(array &$s):void{
        $s['schools'][]=['id'=>1,'name'=>'First Campus'];
        $s['schools'][]=['id'=>2,'name'=>'Unrelated Campus'];
        $s['counters']['schools']=2;
        foreach([1,2,3] as $uid){
            $s['users'][]=['id'=>$uid,'name'=>'User '.$uid,'email'=>$uid.'@example.invalid','active'=>true];
            $s['sessions'][]=['user_id'=>$uid,'token_hash'=>sf_hash(['owner','counsellor','outsider'][$uid-1]),'expires_at'=>time()+3600];
        }
        $s['counters']['users']=3;
        $s['memberships'][]=['user_id'=>1,'school_id'=>1,'role'=>'Admin'];
        $s['memberships'][]=['user_id'=>2,'school_id'=>1,'role'=>'Counsellor'];
        $s['memberships'][]=['user_id'=>3,'school_id'=>2,'role'=>'Admin'];
    },true);
    [$created,$group]=lr('POST','/api/group-sites',['name'=>'School Group','domain'=>'school.test','school_ids'=>[1]]);
    la($created===201,'owner group creation');
    $id=$group['id'];$guid=$group['guid'];$base='/api/public/forms/'.$guid;
    la(sf_public_allowed_origin_path($store,$base.'/config','https://school.test'),'legacy primary domain allowed');
    la(sf_public_allowed_origin_path($store,$base.'/config','https://www.school.test'),'www primary variant allowed');
    la(!sf_public_allowed_origin_path($store,$base.'/config','https://campaign.test'),'campaign unapproved by default');
    la(lr('GET','/api/group-sites/'.$id.'/landing-pages',[],'https://school.test','counsellor')[0]===403,'counsellor blocked');
    la(lr('PATCH','/api/group-sites/'.$id.'/landing-pages',['approved_urls'=>['https://campaign.test/apply']], 'https://school.test','outsider')[0]===404,'foreign admin not owner');
    foreach(['http://campaign.test/apply','https://campaign.test/apply?utm_source=x',
        'https://campaign.test:8443/apply','https://campaign.test/a/../b','https://user@campaign.test/apply'] as $bad)
        la(lr('PATCH','/api/group-sites/'.$id.'/landing-pages',['approved_urls'=>[$bad]])[0]===422,'reject invalid landing URL '.$bad);
    la(lr('PATCH','/api/group-sites/'.$id.'/landing-pages',['approved_urls'=>['https://campaign.test/apply','https://campaign.test/apply/']])[0]===422,'duplicate normalized path rejected');
    la(lr('PATCH','/api/group-sites/'.$id.'/landing-pages',['approved_urls'=>[],'restrict_primary'=>true])[0]===422,'strict primary requires own approved URL');
    [$saved,$rules]=lr('PATCH','/api/group-sites/'.$id.'/landing-pages',[
        'approved_urls'=>['https://campaign.test/apply','https://school.test/admissions'],
        'restrict_primary'=>false
    ]);
    la($saved===200 && count($rules['approved_urls'])===2,'URLs saved and listed');
    la(sf_public_allowed_origin_path($store,$base.'/config','https://campaign.test'),'additional approved origin accepted');
    la(!sf_public_allowed_origin_path($store,$base.'/config','https://evil.campaign.test'),'sibling subdomain blocked');
    la(!sf_public_allowed_origin_path($store,$base.'/config','http://campaign.test'),'HTTP blocked');
    [$status,$config]=lr('GET',$base.'/config',[],'https://campaign.test');
    la($status===200 && count($config['schools'])===1,'public config works from new origin');
    la(($config['landing_pages']['approved_urls']??[])[0]==='https://campaign.test/apply','public config includes safe approved URLs');
    $rulesNow=$store->transaction(static function(array $s)use($guid){return sf_public_group($s,$guid);});
    la(sf_landing_page_approved($rulesNow,'https://campaign.test','https://campaign.test/apply?utm_source=google'),'UTM query not required to match');
    la(!sf_landing_page_approved($rulesNow,'https://campaign.test','https://campaign.test/other'),'unapproved path rejected');
    la(!sf_landing_page_approved($rulesNow,'https://campaign.test','https://school.test/admissions'),'origin and claimed page must agree');
    la(sf_landing_page_approved($rulesNow,'https://school.test','https://school.test/unlisted'),'primary legacy paths retained');

    // Live short lead capture from the approved secondary landing page.
    [$challengeStatus,$challenge]=lr('GET',$base.'/challenge',[],'https://campaign.test');
    la($challengeStatus===200,'secondary challenge allowed');
    preg_match('/Solve: (\d+) \+ (\d+)/',$challenge['question'],$matches);
    $lead=['parent_name'=>'Example Parent','phone'=>'9876543210','grade'=>'Nursery',
        'school_code'=>'campus-1','consent'=>true,'website'=>'','form_display'=>'popup',
        'landing_page'=>'https://campaign.test/apply?utm_source=ad','captcha_token'=>$challenge['token'],
        'captcha_answer'=>(string)((int)$matches[1]+(int)$matches[2])];
    la(lr('POST',$base.'/short-enquiries',[...$lead,'landing_page'=>'https://campaign.test/unapproved'], 'https://campaign.test','owner','short-not-approved-1')[0]===422,
        'unapproved page cannot submit a lead');
    la(lr('POST',$base.'/short-enquiries',$lead,'https://campaign.test','owner','short-approved-11')[0]===201,'approved secondary page creates enquiry');
    la(count($store->transaction(static fn(array $s):array=>$s['leads']))===1,'one saved lead');
    [$strictStatus,$strict]=lr('PATCH','/api/group-sites/'.$id.'/landing-pages',[
       'approved_urls'=>['https://campaign.test/apply','https://school.test/admissions'],
       'restrict_primary'=>true
    ]);
    la($strictStatus===200 && $strict['restrict_primary']===true,'strict primary enabled');
    $rulesNow=$store->transaction(static fn(array $s):array=>sf_public_group($s,$guid));
    la(!sf_landing_page_approved($rulesNow,'https://school.test','https://school.test/other'),'primary strict path enforced');
    la(sf_landing_page_approved($rulesNow,'https://www.school.test','https://www.school.test/admissions'),'primary www variant continues to work');
    [$revokeStatus]=lr('PATCH','/api/group-sites/'.$id.'/landing-pages',['approved_urls'=>['https://school.test/admissions'],'restrict_primary'=>true]);
    la($revokeStatus===200,'secondary approval removed');
    la(!sf_public_allowed_origin_path($store,$base.'/config','https://campaign.test'),'revocation blocks subsequent browser form requests');
    la(count($store->transaction(static fn(array $s):array=>$s['leads']))===1,'historic lead preserved after revoke');
    echo "PASS: landing page approvals, tenant roles, HTTPS enforcement, exact URLs, UTM query, submission routing and immediate revocation\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
