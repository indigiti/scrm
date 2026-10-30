<?php
declare(strict_types=1);
/**
 * Keyless public admission forms. A public form GUID identifies an admin-approved
 * group website; it is NOT an API key. Campus routing and validation stay server-side.
 * This file is bundled into the DigiOps PHP gateway (never exposed as a PHP source).
 */
function sf_public_group(array $s, string $guid): ?array {
    foreach ($s['group_sites'] ?? [] as $g) {
        if (!empty($g['enabled']) && hash_equals((string)($g['guid'] ?? ''), $guid)) return $g;
    }
    return null;
}
function sf_public_origin_ok(array $group, string $origin): bool {
    if (!preg_match('~^https://[a-z0-9.-]+(?::443)?$~iD', $origin)) return false;
    $configured = trim((string)($group['domain'] ?? ''));
    $website = parse_url(str_contains($configured, '://') ? $configured : 'https://'.$configured, PHP_URL_HOST);
    $request = parse_url($origin, PHP_URL_HOST);
    if (!is_string($website) || !is_string($request) || !$website || !$request) return false;
    $website = strtolower($website); $request = strtolower($request);
    // Permit apex/www variants of the same configured domain, not arbitrary subdomains.
    return preg_replace('/^www\./', '', $website) === preg_replace('/^www\./', '', $request);
}
function sf_public_allowed_origin_path(SchoolFlowStore $store, string $path, string $origin): bool {
    if (!preg_match('~^/api/public/forms/([a-f0-9-]{36})/(config|challenge|enquiries|short-enquiries|chat-config|chat|counsel|counsel-lead|chat-start|chat-followup|chat-remember|chat-resume|chat-forget|callback|pin/[0-9]{6})$~iD', $path, $m)) return false;
    return $store->transaction(static function (array $s) use ($m, $origin): bool {
        $group = sf_public_group($s, strtolower($m[1]));
        return $group !== null && sf_public_origin_ok($group, $origin) && count(sf_group_campuses($s, $group)) > 0;
    });
}
/**
 * Serve an uncached, GUID-addressed browser bootstrap. The GUID identifies the
 * public group form, never a private credential. The returned asset versions are
 * actual content hashes, not manually maintained ?v=1 query strings.
 */
function sf_public_embed_script(SchoolFlowStore $store,string $guid,string $publicRoot):string {
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$guid))
        sf_fail(404,'Public form not found');
    $enabled=$store->transaction(static function(array $s)use($guid):bool {
        $group=sf_public_group($s,strtolower($guid));
        return $group!==null && count(sf_group_campuses($s,$group))>0;
    });
    if(!$enabled)sf_fail(404,'Public form not found');
    $assets=['static/lexicon-form.js','static/lexicon-form.css',
        'static/admissions-chat.js','static/admissions-chat.css'];
    $versions=[];
    foreach($assets as $asset){
        $file=rtrim($publicRoot,'/').'/'.$asset;
        if(!is_file($file))throw new RuntimeException('Public form asset missing');
        $hash=hash_file('sha256',$file);
        if(!is_string($hash))throw new RuntimeException('Public form asset unreadable');
        $versions[]=substr($hash,0,16);
    }
    $data=json_encode(['guid'=>strtolower($guid),'versions'=>$versions],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    return '(function(){'."\n".
        'var current=document.currentScript;if(!current)return;'."\n".
        'var meta='.$data.';'."\n".
        'var base=new URL(current.src).origin+"/scrm";'."\n".
        'var script=document.createElement("script");script.async=true;'."\n".
        'script.src=base+"/static/lexicon-form.js?v="+meta.versions[0];'."\n".
        'script.setAttribute("data-schoolflow-group",meta.guid);'."\n".
        'script.setAttribute("data-schoolflow-form-css-version",meta.versions[1]);'."\n".
        'script.setAttribute("data-schoolflow-chat-version",meta.versions[2]);'."\n".
        'script.setAttribute("data-schoolflow-chat-css-version",meta.versions[3]);'."\n".
        '["data-thank-you","data-logo"].forEach(function(attr){var value=current.getAttribute(attr);if(value)script.setAttribute(attr,value);});'."\n".
        'document.head.appendChild(script);'."\n".
        '})();'."\n";
}

function sf_public_challenge(SchoolFlowStore $store, string $guid, string $origin): array {
    $a=random_int(1,20); $b=random_int(1,20); $issued=time();
    $raw=implode(':', [$guid,$a,$b,$issued,bin2hex(random_bytes(12))]);
    $payload=rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $mac=hash_hmac('sha256', $payload.'|'.$origin, $store->publicFormKey());
    return ['question'=>"Solve: $a + $b",'token'=>$payload.'.'.$mac];
}
function sf_public_verify_challenge(SchoolFlowStore $store, string $guid, string $origin, string $token, mixed $answer): string {
    if (strlen($token) > 300 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $m)) sf_fail(422,'Refresh the math CAPTCHA');
    $expected=hash_hmac('sha256', $m[1].'|'.$origin, $store->publicFormKey());
    if (!hash_equals($expected, $m[2])) sf_fail(422,'Invalid math CAPTCHA');
    $raw=base64_decode(strtr($m[1], '-_', '+/'), true);
    if (!is_string($raw)) sf_fail(422,'Invalid math CAPTCHA');
    $parts=explode(':',$raw);
    if(count($parts)!==5 || $parts[0]!==$guid ||
        !ctype_digit($parts[1]) || !ctype_digit($parts[2]) || !ctype_digit($parts[3]) ||
        !preg_match('/^[a-f0-9]{24}$/D',$parts[4])) sf_fail(422,'Invalid math CAPTCHA');
    $issued=(int)$parts[3];
    if ($issued < time()-600 || $issued > time()+15) sf_fail(422,'Math CAPTCHA expired; refresh it');
    if (!is_scalar($answer) || !ctype_digit((string)$answer) ||
        (int)$answer !== (int)$parts[1]+(int)$parts[2]) sf_fail(422,'Incorrect math CAPTCHA');
    return hash('sha256',$token);
}
/** Pure parse function for a fixed, India Post public PIN directory response. */
function sf_public_parse_pin(string $body): ?array {
    try {$json=json_decode($body,true,16,JSON_THROW_ON_ERROR);} catch (Throwable) {return null;}
    $first=$json[0]??null;
    if(!is_array($first) || ($first['Status']??'')!=='Success' || empty($first['PostOffice'][0])) return null;
    $post=$first['PostOffice'][0];
    $state=trim((string)($post['State']??''));
    $city=trim((string)($post['District']??''));
    if($state===''||$city===''||strlen($state)>100||strlen($city)>100) return null;
    return ['state'=>$state,'city'=>$city];
}
function sf_public_fetch_pin(string $pin): ?array {
    if(!function_exists('curl_init')) return null;
    // Fixed allowlisted HTTPS URL; caller controls only exactly six digits.
    $ch=curl_init('https://api.postalpincode.in/pincode/'.$pin);
    if($ch===false)return null;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,
        CURLOPT_MAXREDIRS=>0,CURLOPT_USERAGENT=>'SchoolFlow CRM PIN Lookup/1.0',
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if(!is_string($body)||$status!==200||strlen($body)>100000) return null;
    return sf_public_parse_pin($body);
}
function sf_public_handle(string $method, string $path, array $p, array $headers, string $ip, SchoolFlowStore $store): array {
    if(!preg_match('~^/api/public/forms/([a-f0-9-]{36})/(config|challenge|enquiries|short-enquiries|chat-config|chat|chat-start|chat-followup|chat-remember|chat-resume|chat-forget|callback|pin/[0-9]{6})$~iD',$path,$m))
        sf_fail(404,'Public form not found');
    $guid=strtolower($m[1]);$action=$m[2];$origin=(string)($headers['origin']??'');
    $group=$store->transaction(static function (array $s) use ($guid,$origin) {
        $g=sf_public_group($s,$guid);
        if(!$g || !sf_public_origin_ok($g,$origin) || !sf_group_campuses($s,$g)) sf_fail(403,'Form unavailable on this website');
        return $g;
    });
    if($action==='config' && $method==='GET') {
        return $store->transaction(static function (array $s) use ($guid): array {
            $g=sf_public_group($s,$guid);
            if(!$g)sf_fail(404,'Form unavailable');
            return [200,['name'=>$g['name'],'schools'=>array_map(static fn($v)=>['code'=>$v['code'],'name'=>$v['name']],sf_group_campuses($s,$g))]];
        });
    }
    if($action==='challenge' && $method==='GET') {
        $ipKey=substr(hash('sha256',$ip),0,20);
        $store->transaction(static function (array &$s) use($ipKey,$guid): void {
            sf_limit($s,'public-captcha:'.$guid.':'.$ipKey,60,3600);
        },true);
        return [200,sf_public_challenge($store,$guid,$origin)];
    }
    if(str_starts_with($action,'pin/') && $method==='GET') {
        $pin=substr($action,4);
        $ipKey=substr(hash('sha256',$ip),0,20);
        $cached=$store->transaction(static function (array &$s) use($pin,$ipKey): ?array {
            sf_limit($s,'public-pin:'.$ipKey,30,600);
            $entry=$s['pin_cache'][$pin]??null;
            return is_array($entry) && ($entry['expires_at']??0)>time() ? $entry['result'] : null;
        },true);
        if($cached!==null)return [200,$cached];
        $found=sf_public_fetch_pin($pin);
        if($found===null)sf_fail(404,'PIN not found; please enter State and City');
        $store->transaction(static function (array &$s) use ($pin,$found): void {
            $s['pin_cache']=$s['pin_cache']??[];
            if(count($s['pin_cache'])>450)$s['pin_cache']=array_filter($s['pin_cache'],static fn($v)=>($v['expires_at']??0)>time());
            if(count($s['pin_cache'])>500)$s['pin_cache']=array_slice($s['pin_cache'],-250,null,true);
            $s['pin_cache'][$pin]=['result'=>$found,'expires_at'=>time()+2592000];
        },true);
        return [200,$found];
    }
    if($action==='short-enquiries' && $method==='POST') {
        $idem=(string)($headers['idempotency-key']??'');
        if(!preg_match('/^[a-zA-Z0-9_-]{12,100}$/D',$idem))sf_fail(422,'Submission ID missing');
        if(trim((string)($p['website']??''))!=='')sf_fail(422,'Invalid submission');
        if(($p['consent']??null)!==true)sf_fail(422,'Parent/guardian consent required');
        $guardian=sf_text($p,'parent_name',140,2);
        $phone=sf_text($p,'phone',20,10);
        if(!preg_match('/^[6-9][0-9]{9}$/D',$phone))sf_fail(422,'Valid 10-digit mobile number required');
        $code=sf_text($p,'school_code',50,3);
        $grade=sf_text($p,'grade',80,1);
        foreach(['utm_source','utm_medium','utm_campaign','utm_term','utm_content'] as $u)sf_text($p,$u,140);
        $page=sf_text($p,'landing_page',500);
        $referrer=sf_text($p,'referrer',500);
        if($page!=='' && (parse_url($page,PHP_URL_HOST)===null ||
            !sf_public_origin_ok($group,parse_url($page,PHP_URL_SCHEME).'://'.parse_url($page,PHP_URL_HOST))))
            sf_fail(422,'Invalid enquiry page');
        $proof=sf_public_verify_challenge($store,$guid,$origin,(string)($p['captcha_token']??''),$p['captcha_answer']??'');
        $ipKey=substr(hash('sha256',$ip),0,20);
        return $store->transaction(static function(array &$s)use($guid,$origin,$idem,$proof,$ipKey,$p,$code,$grade,$phone,$guardian,$page,$referrer):array{
            $g=sf_public_group($s,$guid);
            if(!$g || !sf_public_origin_ok($g,$origin))sf_fail(403,'Form unavailable');
            $route=null;
            foreach(sf_group_campuses($s,$g) as $choice)if(hash_equals($choice['code'],$code)){$route=$choice;break;}
            if(!$route)sf_fail(422,'Choose a valid school/campus');
            foreach($s['receipts'] as $receipt)
                if(($receipt['public_group_id']??null)===$g['id'] && ($receipt['idempotency_key']??'')===$idem){
                    if(($receipt['school_id']??null)!==$route['school_id'])sf_fail(409,'Submission belongs to another school');
                    return [200,['success'=>true,'duplicate_submission'=>true]];
                }
            sf_limit($s,'public-short-ip:'.$g['id'].':'.$ipKey,10,3600);
            sf_limit($s,'public-short-group:'.$g['id'],150,3600);
            foreach($s['receipts'] as $receipt)if(($receipt['public_challenge']??'')===$proof)sf_fail(409,'Math CAPTCHA already used');
            $details=$p;
            $details['student_name']='';
            $details['parent_name']=$guardian;
            $details['phone']='+91 '.$phone;
            $details['grade']=$grade;
            $details['email']='';
            $details['pincode']='';$details['state']='';$details['city']='';
            $details['message']='Short admission enquiry — student details pending';
            $lead=sf_create_lead($s,$route['school_id'],$details,$route['site_id'],null,null,'public_group_short_received',true);
            $s['receipts'][]=['public_group_id'=>$g['id'],'school_id'=>$route['school_id'],
                'idempotency_key'=>$idem,'public_challenge'=>$proof,'lead_id'=>$lead['id'],'created_at'=>sf_time()];
            return [201,['success'=>true,'guid'=>$lead['guid'],'duplicate_submission'=>false]];
        },true);
    }
    if($action==='enquiries' && $method==='POST') {
        $ipAttempt=substr(hash('sha256',$ip),0,20);
        $store->transaction(static function (array &$s) use ($group,$ipAttempt): void {
            sf_limit($s,'public-attempt:'.$group['id'].':'.$ipAttempt,30,3600);
        },true);
        $idem=(string)($headers['idempotency-key']??'');
        if(!preg_match('/^[a-zA-Z0-9_-]{12,100}$/D',$idem))sf_fail(422,'Submission ID missing');
        // Honeypot: must remain blank. No private API key can be supplied by this caller.
        if(trim((string)($p['website']??''))!=='')sf_fail(422,'Invalid submission');
        $proof=sf_public_verify_challenge($store,$guid,$origin,(string)($p['captcha_token']??''),$p['captcha_answer']??'');
        if(($p['consent']??null)!==true)sf_fail(422,'Parent/guardian consent required');
        $phone=sf_text($p,'phone',20,10);
        if(!preg_match('/^[6-9][0-9]{9}$/D',$phone))sf_fail(422,'Valid 10-digit mobile number required');
        $pin=sf_text($p,'pincode',6,6);
        if(!preg_match('/^[0-9]{6}$/D',$pin))sf_fail(422,'Valid 6-digit PIN required');
        sf_email(sf_text($p,'email',160,5));
        $student=sf_text($p,'student_name',140,2);$guardian=sf_text($p,'parent_name',140,2);
        $code=sf_text($p,'school_code',50,3);$grade=sf_text($p,'grade',80,1);
        $state=sf_text($p,'state',100);$city=sf_text($p,'city',100);
        foreach(['utm_source','utm_medium','utm_campaign','utm_term','utm_content'] as $u)sf_text($p,$u,140);
        $page=sf_text($p,'landing_page',500);$referrer=sf_text($p,'referrer',500);
        if($page!=='' && (parse_url($page,PHP_URL_HOST)===null ||
            !sf_public_origin_ok($group,parse_url($page,PHP_URL_SCHEME).'://'.parse_url($page,PHP_URL_HOST))))sf_fail(422,'Invalid enquiry page');
        $ipKey=substr(hash('sha256',$ip),0,20);
        return $store->transaction(static function (array &$s) use($guid,$origin,$idem,$proof,$ipKey,$p,$code,$phone,$student,$guardian,$pin,$grade,$state,$city,$page,$referrer): array {
            $g=sf_public_group($s,$guid);
            if(!$g || !sf_public_origin_ok($g,$origin))sf_fail(403,'Form unavailable');
            $route=null;
            foreach(sf_group_campuses($s,$g) as $choice)if(hash_equals($choice['code'],$code)){$route=$choice;break;}
            if(!$route)sf_fail(422,'Choose a valid school/campus');
            foreach($s['receipts'] as $receipt)if(($receipt['public_group_id']??null)===$g['id'] && ($receipt['idempotency_key']??'')===$idem){
                if(($receipt['school_id']??null)!==$route['school_id'])sf_fail(409,'Submission belongs to another school');
                return [200,['success'=>true,'duplicate_submission'=>true]];
            }
            sf_limit($s,'public-form-ip:'.$g['id'].':'.$ipKey,10,3600);
            sf_limit($s,'public-form-group:'.$g['id'],150,3600);
            foreach($s['receipts'] as $receipt)if(($receipt['public_challenge']??'')===$proof)sf_fail(409,'This math CAPTCHA was already used');
            $details=$p;
            $details['phone']='+91 '.$phone;
            $details['student_name']=$student;$details['parent_name']=$guardian;$details['grade']=$grade;
            $details['pincode']=$pin;$details['state']=$state;$details['city']=$city;
            $details['landing_page']=$page;$details['referrer']=$referrer;
            $lead=sf_create_lead($s,$route['school_id'],$details,$route['site_id'],null,null,'public_group_website_received');
            $s['receipts'][]=['public_group_id'=>$g['id'],'school_id'=>$route['school_id'],
                'idempotency_key'=>$idem,'public_challenge'=>$proof,'lead_id'=>$lead['id'],'created_at'=>sf_time()];
            return [201,['success'=>true,'guid'=>$lead['guid'],'duplicate_submission'=>false]];
        },true);
    }
    sf_fail(405,'Method not allowed');
}
