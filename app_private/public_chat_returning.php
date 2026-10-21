<?php
declare(strict_types=1);
/**
 * Browser opt-in return token: 256 random bits, only SHA-256 stored server-side.
 * A matching school/group/origin remains required. Token never contains PII.
 */
function sf_chat_remember_issue(array &$s,int $groupId,int $schoolId,int $leadId,int $days=30):array {
    $days=in_array($days,[15,30],true)?$days:30;
    $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    $exp=time()+$days*86400;
    $s['public_chat_remember']=array_values(array_filter($s['public_chat_remember']??[],
        static fn($r)=>(int)($r['expires_at']??0)>time() &&
            !((int)($r['group_id']??0)===$groupId && (int)($r['school_id']??0)===$schoolId &&
              (int)($r['lead_id']??0)===$leadId)));
    $s['public_chat_remember'][]=['token_hash'=>hash('sha256',$token),
        'group_id'=>$groupId,'school_id'=>$schoolId,'lead_id'=>$leadId,
        'expires_at'=>$exp,'created_at'=>sf_time()];
    return ['remember_token'=>$token,'remember_expires_at'=>$exp];
}
function sf_chat_remember_response(string $guid,string $action,array $p,array $headers,SchoolFlowStore $store):array {
    $origin=(string)($headers['origin']??'');
    $token=sf_text($p,'remember_token',128);
    if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))sf_fail(401,'Returning session unavailable');
    return $store->transaction(static function(array &$s)use($guid,$action,$origin,$token,$store):array {
        $group=sf_public_group($s,$guid);
        if(!$group||!sf_public_origin_ok($group,$origin))sf_fail(403,'Website unauthorized');
        $hash=hash('sha256',$token);
        $idx=null;
        foreach($s['public_chat_remember']??[] as $i=>$item)
          if(hash_equals((string)($item['token_hash']??''),$hash) &&
             (int)($item['group_id']??0)===(int)$group['id']){$idx=$i;break;}
        if($idx===null)sf_fail(401,'Returning session unavailable');
        $item=$s['public_chat_remember'][$idx];
        if((int)$item['expires_at']<=time()) {
            unset($s['public_chat_remember'][$idx]);
            $s['public_chat_remember']=array_values($s['public_chat_remember']);
            sf_fail(401,'Returning session expired');
        }
        if($action==='chat-forget'){
            unset($s['public_chat_remember'][$idx]);
            $s['public_chat_remember']=array_values($s['public_chat_remember']);
            return [200,['ok'=>true]];
        }
        $route=null;
        foreach(sf_group_campuses($s,$group) as $campus)
          if((int)$campus['school_id']===(int)$item['school_id'] &&
             !empty(sf_chat_settings($s,(int)$campus['school_id'])['enabled'])){
            $route=$campus;break;
          }
        if(!$route)sf_fail(403,'Campus no longer available');
        $leadId=(int)$item['lead_id'];
        $lead=sf_find($s['leads']??[],$leadId);
        if(!$lead||(int)($lead['school_id']??0)!==(int)$route['school_id'] ||
           !in_array((string)($lead['utm_source']??''),['admissions_guided_chat','public_admissions_chat'],true) ||
           empty($lead['consent']))sf_fail(401,'Returning enquiry unavailable');
        // Single-use rotation prevents replay of an old remembered credential.
        unset($s['public_chat_remember'][$idx]);
        $s['public_chat_remember']=array_values($s['public_chat_remember']);
        $config=sf_chat_settings($s,(int)$route['school_id']);
        $remainingDays=(int)ceil(((int)$item['expires_at']-time())/86400);
        $fresh=sf_chat_remember_issue($s,(int)$group['id'],(int)$route['school_id'],
            $leadId,$remainingDays<=15?15:30);
        // Never extend beyond the original expiration on resume.
        foreach($s['public_chat_remember'] as &$rec)
            if(hash_equals($rec['token_hash'],hash('sha256',$fresh['remember_token']))) {
                $rec['expires_at']=(int)$item['expires_at'];break;
            }
        unset($rec);
        $fresh['remember_expires_at']=(int)$item['expires_at'];
        $expires=time()+3600;
        $s['receipts'][]=['chat_group_id'=>$group['id'],'school_id'=>$route['school_id'],
            'chat_session_lead_id'=>$leadId,'chat_expires_at'=>$expires,'created_at'=>sf_time()];
        // Keep resumption receipts bounded per group while preserving all submitted enquiry receipts.
        $resumeKeys=[];
        foreach($s['receipts'] as $i=>$rec)
          if(($rec['chat_session_lead_id']??null)===$leadId &&
             empty($rec['chat_start_idempotency']) && empty($rec['public_challenge']))
            $resumeKeys[]=$i;
        foreach(array_slice($resumeKeys,0,max(0,count($resumeKeys)-12)) as $idx)unset($s['receipts'][$idx]);
        $s['receipts']=array_values($s['receipts']);
        return [200,array_merge($fresh,[
            'success'=>true,'session'=>sf_chat_token($store,(int)$group['id'],(int)$route['school_id'],$leadId,$expires,$origin),
            'school_code'=>$route['code'],'campus_name'=>$route['name'],
            'quick_questions'=>array_slice(array_column($config['faqs']??[],'question'),0,8)
        ])];
    },true);
}
