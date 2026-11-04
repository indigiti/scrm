<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$directory=sys_get_temp_dir().'/schoolflow-website-popup-'.bin2hex(random_bytes(5));
mkdir($directory,0700,true);
$store=new SchoolFlowStore($directory);
function wp_assert(bool $pass,string $message):void{
    if(!$pass)throw new RuntimeException('Website popup regression: '.$message);
}
function wp_api(string $method,string $path,array $payload=[],string $token='admin',string $origin='https://school.test',string $idem=''):array{
    global $store;
    try{return sf_handle($method,$path,$payload,
        ['authorization'=>'Bearer '.$token,'origin'=>$origin,'idempotency-key'=>$idem],
        '198.51.100.18',$store);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
try{
    $store->transaction(static function(array &$s):void{
        foreach(['First School','Second School'] as $name)
            $s['schools'][]=['id'=>sf_id($s,'schools'),'name'=>$name,'created_at'=>sf_time()];
        $s['users'][]=['id'=>1,'name'=>'Admin','email'=>'admin@example.invalid','active'=>true];
        $s['users'][]=['id'=>2,'name'=>'Other','email'=>'other@example.invalid','active'=>true];
        $s['memberships'][]=['user_id'=>1,'school_id'=>1,'role'=>'Admin','enabled'=>true];
        $s['memberships'][]=['user_id'=>2,'school_id'=>2,'role'=>'Admin','enabled'=>true];
        foreach(['admin','other'] as $i=>$token)
            $s['sessions'][]=['user_id'=>$i+1,'token_hash'=>sf_hash($token),'expires_at'=>time()+3600];
        $s['counters']['users']=2;
    },true);
    [$siteCode,$site]=wp_api('POST','/api/schools/1/sites',[
        'name'=>'Connected School Website','domain'=>'school.test','platform'=>'WordPress']);
    wp_assert($siteCode===201,'connected website created');
    $siteId=$site['id'];
    [$created,$linked]=wp_api('POST','/api/schools/1/sites/'.$siteId.'/public-form');
    wp_assert($created===201&&$linked['created']===true,'website form provisioned on demand');
    $g=$linked['group'];$groupId=$g['id'];$groupGuid=$g['guid'];
    wp_assert($g['domain']==='school.test'&&$g['linked_school_site_id']===$siteId,
        'domain and source linked to authorized school site');
    wp_assert(count($g['schools'])===1&&$g['schools'][0]['school_id']===1,
        'school-specific popup does not list other campuses');
    wp_assert(!$g['form_surface_settings']['inline_enabled']&&
        !$g['form_surface_settings']['group_popup_enabled']&&
        !$g['form_surface_settings']['detailed_popup_enabled'],
        'new form exposes only campaign GUID, not group-wide or inline surfaces');
    [$idempotent,$same]=wp_api('POST','/api/schools/1/sites/'.$siteId.'/public-form');
    wp_assert($idempotent===200&&$same['group']['guid']===$groupGuid,'no duplicate public forms');
    wp_assert(wp_api('POST','/api/schools/1/sites/'.$siteId.'/public-form',[],'other')[0]===403,
        'other school admin cannot provision form');
    wp_assert(wp_api('DELETE','/api/schools/1/sites/'.$siteId,
        ['confirm_guid'=>$store->transaction(static fn(array $s)=>sf_source($s,$siteId)['guid'])])[0]===409,
        'linked website cannot be deleted while form exists');
    wp_assert(wp_api('PATCH','/api/schools/1/sites/'.$siteId,['domain'=>'another.test'])[0]===409,
        'linked domain cannot silently invalidate popup installs');
    [$status,$popup]=wp_api('POST','/api/group-sites/'.$groupId.'/page-popups',[
        'name'=>'Website Admissions Popup','scope'=>'website',
        'settings'=>['enabled'=>true,'trigger'=>'manual','school_ids'=>[1],
            'include_paths'=>['/admissions'],'exclude_paths'=>['/admissions/thank-you']]]);
    wp_assert($status===201&&$popup['scope']==='website'&&
        $popup['landing_page']==='https://school.test/',
        'school website popup requires no additional LP approval');
    $campaign=$popup['guid'];
    $updatedGroup=$store->transaction(static function(array $s)use($groupId){
        foreach($s['group_sites'] as $item)if($item['id']===$groupId)return $item;
        throw new RuntimeException('group unavailable');
    });
    wp_assert(sf_popup_campaign_match($updatedGroup,$campaign,'https://school.test',
        'https://school.test/admissions/', 'campus-1'),
        'registered website path permitted');
    wp_assert(!sf_popup_campaign_match($updatedGroup,$campaign,'https://school.test',
        'https://school.test/fees/', 'campus-1'),
        'campaign include paths enforced server-side');
    wp_assert(!sf_popup_campaign_match($updatedGroup,$campaign,'https://school.test',
        'https://school.test/admissions/thank-you/', 'campus-1'),
        'excluded path disallowed');
    wp_assert(!sf_popup_campaign_match($updatedGroup,$campaign,'https://other.test',
        'https://other.test/admissions/', 'campus-1'),
        'cross-site campaign blocked');
    wp_assert(!sf_popup_campaign_match($updatedGroup,$campaign,'https://school.test',
        'https://school.test/admissions/', 'campus-2'),
        'other campus cannot submit through campaign');
    $popupPayload=['form_display'=>'popup','popup_campaign_guid'=>$campaign];
    wp_assert(sf_short_surface_allowed($updatedGroup,$popupPayload,
        'https://school.test','https://school.test/admissions/','campus-1'),
        'campaign-bound short form allowed');
    wp_assert(!sf_short_surface_allowed($updatedGroup,['form_display'=>'inline'],
        'https://school.test','https://school.test/admissions/','campus-1'),
        'disabled inline embed rejected by server');
    wp_assert(!sf_short_surface_allowed($updatedGroup,['form_display'=>'popup'],
        'https://school.test','https://school.test/admissions/','campus-1'),
        'disabled group popup cannot bypass campaign GUID requirement');
    wp_assert(!sf_short_surface_allowed($updatedGroup,$popupPayload,
        'https://school.test','https://school.test/other/','campus-1'),
        'forged submit on different path rejected');
    [$cfgStatus,$config]=wp_api('GET','/api/public/forms/'.$groupGuid.'/config');
    wp_assert($cfgStatus===200&&count($config['page_popup_campaigns'])===1&&
        $config['page_popup_campaigns'][0]['scope']==='website',
        'public config advertises only authorized website popup');
    wp_assert(wp_api('POST','/api/group-sites/'.$groupId.'/page-popups',[
        'name'=>'Unapproved offsite','scope'=>'page','landing_page'=>'https://foreign.test/apply/',
        'settings'=>['enabled'=>true,'school_ids'=>[1]]])[0]===422,
        'external campaign domain still requires authorization');
    wp_assert(wp_api('POST','/api/group-sites/'.$groupId.'/page-popups',[
        'name'=>'Another website-wide popup','scope'=>'website',
        'settings'=>['enabled'=>true,'school_ids'=>[1]]])[0]===409,
        'duplicate website popup prevented');
    wp_assert(wp_api('PATCH','/api/group-sites/'.$groupId.'/landing-pages',[
        'approved_urls'=>['https://school.test/apply'],'restrict_primary'=>true])[0]===200,
        'inline LP strict settings can coexist with site popup');
    wp_assert(sf_popup_campaign_match($updatedGroup,$campaign,'https://school.test',
        'https://school.test/admissions','campus-1'),
        'registered school popup ignores unrelated inline LP approval setting');
    wp_assert(wp_api('POST','/api/group-sites/'.$groupId.'/page-popups',[
        'name'=>'School exact page popup','scope'=>'page',
        'landing_page'=>'https://school.test/contact/',
        'settings'=>['enabled'=>true,'school_ids'=>[1]]])[0]===201,
        'optional page targeting works on connected school website without LP approval');
    // Direct connector uses its own original key and receipts, even after
    // deleting the public popup form.
    $leadBody=['student_name'=>'Demo Child','parent_name'=>'Guardian',
        'phone'=>'9876543210','grade'=>'I','consent'=>true];
    [$sent,$lead]=wp_api('POST','/api/v1/inbound-leads',$leadBody,$site['api_key'],
        'school-connector-demo-0001');
    wp_assert($sent===201,'private connected website receives admission lead; HTTP '.$sent.' '.json_encode($lead));
    [$removed,$removal]=wp_api('DELETE','/api/group-sites/'.$groupId,['confirm_guid'=>$groupGuid]);
    wp_assert($removed===200&&$removal['removed'],'form removed with consent');
    $state=$store->transaction(static fn(array $s):array=>$s);
    wp_assert((bool)sf_source($state,$siteId)['enabled']===true&&
        empty(sf_source($state,$siteId)['removed_at']),
        'public-form removal preserves connected school website');
    wp_assert(count($state['leads'])===1,'historic admissions lead retained');
    $after=wp_api('POST','/api/v1/inbound-leads',$leadBody,$site['api_key'],
        'school-connector-demo-0001');
    wp_assert($after[0]===200&&$after[1]['duplicate_submission']===true,
        'website idempotency receipts retained after popup form removal');
    wp_assert(wp_api('GET','/api/public/forms/'.$groupGuid.'/config')[0]===403,
        'removed campaign form no longer serves public configuration');
    [$siteDeleted]=wp_api('DELETE','/api/schools/1/sites/'.$siteId,
        ['confirm_guid'=>sf_source($state,$siteId)['guid']]);
    wp_assert($siteDeleted===200,'website may be removed after popup form removal');
    echo "PASS: authorized school popup, independent LP rules, server-side targeting, one-school route, linked-site lifecycle\n";
}finally{
    foreach(glob($directory.'/*')?:[] as $file)@unlink($file);
    @rmdir($directory);
}
