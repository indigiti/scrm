<?php
declare(strict_types=1);

/**
 * Short-form WhatsApp OTP adapter. All provider calls are SERVER-TO-SERVER.
 * Disabled by default. Inline and popup share one enforcement policy because
 * a public browser-supplied "form_display" cannot prove its own surface.
 *
 * A WaBridge HTTP 202 acknowledges queuing, not WhatsApp delivery. Only
 * possession of the one-time code proves access to the requested number.
 */
function sf_form_otp_defaults():array {
    return ['enabled'=>false,'short_forms'=>true,'session_id'=>'',
        'api_key_sealed'=>'','created_at'=>''];
}
function sf_form_otp_settings(array $group):array {
    return array_replace(sf_form_otp_defaults(),is_array($group['form_otp_settings']??null)
        ?$group['form_otp_settings']:[]);
}
function sf_form_otp_admin_view(array $group):array {
    $cfg=sf_form_otp_settings($group);
    return ['enabled'=>(bool)$cfg['enabled'],'short_forms'=>(bool)$cfg['short_forms'],
        'session_id'=>(string)$cfg['session_id'],'has_api_key'=>(bool)($cfg['api_key_sealed']||(getenv('SCHOOLFLOW_WABRIDGE_API_KEY')?:'')),
        'provider'=>'wabridge','ttl_seconds'=>300,'resend_after_seconds'=>60,
        'backend'=>'https://stage.digiti.in/wabridge',
        'delivery_verified'=>false];
}
function sf_form_otp_public_view(array $group):array {
    $cfg=sf_form_otp_settings($group);
    return ['required'=>(bool)$cfg['enabled']&&(bool)$cfg['short_forms'],
        'ttl_seconds'=>300,'resend_after_seconds'=>60,
        'provider'=>'whatsapp'];
}
function sf_form_otp_validate_settings(array $payload,array $group,SchoolFlowStore $store):array {
    foreach(array_keys($payload) as $key)if(!in_array($key,
        ['enabled','short_forms','session_id','api_key','clear_api_key'],true))
        sf_fail(422,'Unknown OTP setting');
    $old=sf_form_otp_settings($group);
    foreach(['enabled','short_forms'] as $key)if(isset($payload[$key])){
        if(!is_bool($payload[$key]))sf_fail(422,'OTP setting must be boolean');
        $old[$key]=$payload[$key];
    }
    if(array_key_exists('session_id',$payload)){
        if(!is_string($payload['session_id'])||!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$payload['session_id']))
            sf_fail(422,'Choose an authorized WaBridge session ID');
        $old['session_id']=$payload['session_id'];
    }
    if(isset($payload['clear_api_key'])&&!is_bool($payload['clear_api_key']))
        sf_fail(422,'Invalid credential operation');
    if(($payload['clear_api_key']??false)===true)$old['api_key_sealed']='';
    if(array_key_exists('api_key',$payload)){
        $key=$payload['api_key'];
        if(!is_string($key)||strlen($key)>256)sf_fail(422,'Invalid WaBridge credential');
        if($key!==''){
            if(!preg_match('/^[A-Za-z0-9._~-]{24,256}$/D',$key))
                sf_fail(422,'WaBridge API key must be a scoped bearer credential');
            $old['api_key_sealed']=$store->seal($key);
        }
    }
    if($old['enabled']&&(!$old['short_forms']||!$old['session_id']||
        (!$old['api_key_sealed']&&!(getenv('SCHOOLFLOW_WABRIDGE_API_KEY')?:''))))
        sf_fail(422,'Connect a WaBridge session and API credential before enabling short-form OTP');
    $old['created_at']=sf_time();
    return $old;
}
function sf_form_otp_phone_hash(string $phone,string $key):string {
    return hash_hmac('sha256','phone:91'.$phone,$key);
}
function sf_form_otp_code_hash(string $code,string $id,string $key):string {
    return hash_hmac('sha256','code:'.$id.':'.$code,$key);
}
function sf_form_otp_public_record(array $s,string $id):?array {
    foreach($s['form_otp_challenges']??[] as $entry)if(($entry['guid']??'')===$id)return $entry;
    return null;
}
function sf_form_otp_route(array $s,array $group,string $campus):array {
    foreach(sf_group_campuses($s,$group) as $choice)
        if(hash_equals((string)$choice['code'],$campus))return $choice;
    sf_fail(422,'Choose a valid school/campus');
}
function sf_form_otp_send_adapter(array $cfg,SchoolFlowStore $store,string $phone,
    string $code,string $id,?callable $transport=null):array {
    $payload=[
        'session_id'=>(string)$cfg['session_id'],'to'=>'91'.$phone,
        'text'=>'SchoolFlow admission enquiry verification code: '.$code.
            '. Valid for 5 minutes. Do not share this code.'];
    $idem='schoolflow-otp-'.$id;
    if($transport!==null)return $transport($payload,$idem);
    if(!function_exists('curl_init'))throw new RuntimeException('cURL unavailable');
    // Backend URL is server-owned, NOT an administrator-editable target.
    $base=(string)(getenv('SCHOOLFLOW_WABRIDGE_BASE_URL')?:'https://stage.digiti.in/wabridge');
    if(!preg_match('~^https://[a-z0-9.-]+(?::443)?/wabridge/?$~iD',$base))
        throw new RuntimeException('Unsafe WaBridge backend URL');
    $secret=$cfg['api_key_sealed']?$store->unseal((string)$cfg['api_key_sealed']):
        (string)(getenv('SCHOOLFLOW_WABRIDGE_API_KEY')?:'');
    if(!preg_match('/^[A-Za-z0-9._~-]{24,256}$/D',$secret))
        throw new RuntimeException('Missing scoped WaBridge API credential');
    $ch=curl_init(rtrim($base,'/').'/api/v1/messages.php');
    if($ch===false)throw new RuntimeException('WaBridge connection unavailable');
    try{
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret,'Idempotency-Key: '.$idem,
                'Content-Type: application/json','Accept: application/json'],
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>7,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        ]);
        $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    }finally{curl_close($ch);}
    if(!is_string($raw))throw new RuntimeException('WaBridge send outcome unknown');
    $decoded=json_decode($raw,true);
    return ['http'=>$http,'data'=>is_array($decoded)?$decoded:[]];
}
function sf_form_otp_request(string $guid,string $origin,array $p,string $ip,
    SchoolFlowStore $store,?callable $transport=null):array {
    if(($p['consent']??false)!==true)sf_fail(422,'Guardian contact consent required');
    $phone=sf_text($p,'phone',20,10);
    if(!preg_match('/^[6-9][0-9]{9}$/D',$phone))sf_fail(422,'Valid Indian mobile number required');
    $campus=sf_text($p,'school_code',50,3);
    $page=sf_text($p,'landing_page',500);
    $proof=sf_public_verify_challenge($store,$guid,$origin,
        (string)($p['captcha_token']??''),$p['captcha_answer']??'');
    $key=$store->publicFormKey();
    $hashed=sf_form_otp_phone_hash($phone,$key);
    $ipKey=substr(hash_hmac('sha256',$ip,$key),0,22);
    $id=sf_uuid();$code=(string)random_int(100000,999999);$expires=time()+300;
    $cfg=$store->transaction(static function(array &$s)use($guid,$origin,$campus,$page,$hashed,
        $ipKey,$id,$code,$expires,$key,$proof):array{
        $group=sf_public_group($s,$guid);
        if(!$group||!sf_public_origin_ok($group,$origin)||
            !sf_landing_page_approved($group,$origin,$page))
            sf_fail(403,'Form unavailable');
        $config=sf_form_otp_settings($group);
        if(!$config['enabled']||!$config['short_forms'])sf_fail(409,'WhatsApp verification not enabled');
        if(!$config['session_id']||(!$config['api_key_sealed']&&
            !(getenv('SCHOOLFLOW_WABRIDGE_API_KEY')?:'')))sf_fail(503,'WhatsApp verification unavailable');
        $route=sf_form_otp_route($s,$group,$campus);
        sf_limit($s,'public-otp-ip:'.$group['id'].':'.$ipKey,8,3600);
        sf_limit($s,'public-otp-phone:'.$group['id'].':'.$hashed,3,3600);
        sf_limit($s,'public-otp-group:'.$group['id'],120,3600);
        sf_limit($s,'public-otp-school:'.$route['school_id'],75,3600);
        $now=time();
        $s['form_otp_challenges']=array_values(array_filter($s['form_otp_challenges']??[],
            static fn($x)=>is_array($x)&&($x['expires_at']??0)>$now-3600));
        if(count($s['form_otp_challenges'])>=350)sf_fail(429,'Verification busy; try again later');
        foreach($s['form_otp_challenges'] as $previous)if(
            $previous['group_id']===$group['id']&&$previous['phone_hash']===$hashed&&
            ($previous['created_at']??0)>$now-60)
            sf_fail(429,'Please wait 60 seconds before requesting another code');
        $s['form_otp_challenges'][]=[
            'guid'=>$id,'group_id'=>$group['id'],'school_id'=>$route['school_id'],
            'origin'=>$origin,'phone_hash'=>$hashed,'campus_code'=>$campus,
            'code_hash'=>sf_form_otp_code_hash($code,$id,$key),
            'captcha_proof'=>$proof,'created_at'=>$now,'expires_at'=>$expires,
            'attempts'=>0,'verified_at'=>0,'proof_hash'=>'','used_at'=>0,
            'provider_job_id'=>'','state'=>'dispatching'
        ];
        return $config;
    },true);
    $state='needs_review';$job='';
    try{
        $out=sf_form_otp_send_adapter($cfg,$store,$phone,$code,$id,$transport);
        if(($out['http']??0)===202&&
            preg_match('/^[a-zA-Z0-9-]{24,64}$/D',(string)($out['data']['job_id']??''))){
            $state='queued';$job=(string)$out['data']['job_id'];
        }
    }catch(Throwable $e){error_log('SchoolFlow OTP transport '.get_class($e));}
    $store->transaction(static function(array &$s)use($id,$state,$job):void {
        foreach($s['form_otp_challenges'] as &$row)
            if(($row['guid']??'')===$id){$row['state']=$state;$row['provider_job_id']=$job;break;}
        unset($row);
    },true);
    if($state!=='queued')sf_fail(503,'WhatsApp verification request unavailable. No lead was submitted.');
    return [202,['otp_id'=>$id,'status'=>'queued','expires_in'=>300,
        'retry_after'=>60,'message'=>'Verification request queued. Enter the code when it arrives in WhatsApp.']];
}
function sf_form_otp_verify(string $guid,string $origin,array $p,
    SchoolFlowStore $store):array {
    $id=(string)($p['otp_id']??'');
    $phone=sf_text($p,'phone',20,10);
    $campus=sf_text($p,'school_code',50,3);
    $code=(string)($p['code']??'');
    if(!preg_match('/^[a-f0-9-]{36}$/iD',$id)||!preg_match('/^[6-9][0-9]{9}$/D',$phone)||
       !preg_match('/^[0-9]{6}$/D',$code))sf_fail(422,'Invalid verification details');
    $key=$store->publicFormKey();$hash=sf_form_otp_phone_hash($phone,$key);
    $answer=$store->transaction(static function(array &$s)use($guid,$origin,$id,$campus,$code,$hash,$key):array {
        $group=sf_public_group($s,$guid);
        if(!$group||!sf_public_origin_ok($group,$origin))sf_fail(403,'Form unavailable');
        $cfg=sf_form_otp_settings($group);
        if(!$cfg['enabled'])sf_fail(409,'OTP verification not enabled');
        foreach($s['form_otp_challenges']??[] as $index=>$current){
            if(($current['guid']??'')!==$id)continue;
            if($current['group_id']!==$group['id']||$current['origin']!==$origin||
                !hash_equals((string)$current['phone_hash'],$hash)||
                !hash_equals((string)$current['campus_code'],$campus))
                sf_fail(422,'Verification does not match this enquiry');
            if($current['state']!=='queued'||$current['expires_at']<time()||
                !empty($current['used_at'])||!empty($current['verified_at']))
                sf_fail(422,'Verification expired or already used');
            if(($current['attempts']??0)>=5)return ['error'=>'locked'];
            $s['form_otp_challenges'][$index]['attempts']++;
            if(!hash_equals((string)$current['code_hash'],sf_form_otp_code_hash($code,$id,$key)))
                // Return the error AFTER commit, so wrong guesses consume attempts.
                return ['error'=>'incorrect'];
            $secret=bin2hex(random_bytes(24));
            $s['form_otp_challenges'][$index]['verified_at']=time();
            $s['form_otp_challenges'][$index]['proof_hash']=hash('sha256',$secret);
            return ['verified'=>true,'otp_id'=>$id,'otp_proof'=>$secret,
                'expires_in'=>max(0,(int)$current['expires_at']-time())];
        }
        sf_fail(422,'Verification expired or unavailable');
    },true);
    if(($answer['error']??'')==='locked')sf_fail(429,'Too many incorrect codes');
    if(($answer['error']??'')==='incorrect')sf_fail(422,'Incorrect verification code');
    return [200,$answer];
}
function sf_form_otp_consume(array &$s,array $group,int $schoolId,string $campus,
    string $phone,string $origin,array $p,string $key):array {
    $cfg=sf_form_otp_settings($group);
    if(!$cfg['enabled']||!$cfg['short_forms'])return ['verified'=>false];
    $id=(string)($p['otp_id']??'');$proof=(string)($p['otp_proof']??'');
    if(!preg_match('/^[a-f0-9-]{36}$/iD',$id)||!preg_match('/^[a-f0-9]{48}$/D',$proof))
        sf_fail(422,'Verify your WhatsApp number before submitting');
    $hashed=sf_form_otp_phone_hash($phone,$key);
    foreach($s['form_otp_challenges']??[] as $index=>$row){
        if(($row['guid']??'')!==$id)continue;
        if((int)$row['group_id']!==(int)$group['id']||$row['school_id']!==$schoolId||
            !hash_equals((string)$row['phone_hash'],$hashed)||
            !hash_equals((string)$row['origin'],$origin)||
            !hash_equals((string)$row['campus_code'],$campus)||
            empty($row['verified_at'])||!empty($row['used_at'])||
            $row['expires_at']<time()||
            !hash_equals((string)$row['proof_hash'],hash('sha256',$proof)))
            sf_fail(422,'WhatsApp verification expired or does not match the form');
        $s['form_otp_challenges'][$index]['used_at']=time();
        return ['verified'=>true,'verified_at'=>gmdate('Y-m-d\TH:i:s+00:00',(int)$row['verified_at']),
            'provider'=>'wabridge','provider_job_id'=>(string)$row['provider_job_id'],
            'otp_id'=>$id];
    }
    unset($row);
    sf_fail(422,'WhatsApp verification unavailable');
}
