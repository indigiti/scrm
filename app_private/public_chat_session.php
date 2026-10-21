<?php
declare(strict_types=1);
/**
 * Public admissions chat sessions.
 * A verified parent enquiry is created before chat access is granted.
 * Session is a short-lived signed bearer, bound to origin, group and school.
 * Contact details are never included in the session token or AI context.
 */
function sf_chat_token(SchoolFlowStore $store,int $groupId,int $schoolId,int $leadId,int $expires,string $origin):string {
    $part=implode(':',[$groupId,$schoolId,$leadId,$expires,hash('sha256',$origin)]);
    $encoded=rtrim(strtr(base64_encode($part),'+/','-_'),'=');
    return $encoded.'.'.hash_hmac('sha256',$encoded,$store->publicFormKey());
}
function sf_chat_session_lead(SchoolFlowStore $store,string $token,int $groupId,int $schoolId,string $origin,array $s):int {
    if(strlen($token)>500 || !preg_match('/^([A-Za-z0-9_-]{20,420})\.([a-f0-9]{64})$/D',$token,$match))
        sf_fail(401,'Start an admission enquiry before chatting');
    if(!hash_equals(hash_hmac('sha256',$match[1],$store->publicFormKey()),$match[2]))
        sf_fail(401,'Chat session invalid');
    $decoded=base64_decode(strtr($match[1],'-_','+/'),true);
    $parts=is_string($decoded)?explode(':',$decoded):[];
    if(count($parts)!==5 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) ||
       !ctype_digit($parts[2]) || !ctype_digit($parts[3]) ||
       !hash_equals(hash('sha256',$origin),$parts[4]) ||
       (int)$parts[0]!==$groupId || (int)$parts[1]!==$schoolId ||
       (int)$parts[3]<time() || (int)$parts[3]>time()+7200)
       sf_fail(401,'Chat session expired; start a new enquiry');
    $leadId=(int)$parts[2];
    foreach($s['receipts']??[] as $receipt)
      if(($receipt['chat_session_lead_id']??null)===$leadId &&
         ($receipt['chat_group_id']??null)===$groupId &&
         ($receipt['school_id']??null)===$schoolId &&
         ($receipt['chat_expires_at']??0)===(int)$parts[3])
        return $leadId;
    sf_fail(401,'Chat session not found');
}
function sf_chat_start(string $guid,array $p,array $headers,string $ip,SchoolFlowStore $store):array {
    $origin=(string)($headers['origin']??'');
    $id=(string)($headers['idempotency-key']??'');
    if(!preg_match('/^[A-Za-z0-9_-]{12,100}$/D',$id))sf_fail(422,'Submission ID required');
    if(($p['consent']??false)!==true)sf_fail(422,'Contact consent required');
    if(trim((string)($p['website']??''))!=='')sf_fail(422,'Invalid submission');
    $code=sf_text($p,'school_code',50,3);
    $guardian=sf_text($p,'parent_name',140,2);
    $phone=sf_text($p,'phone',10,10);
    if(!preg_match('/^[6-9][0-9]{9}$/D',$phone))sf_fail(422,'Valid mobile number required');
    $student=sf_text($p,'student_name',140,2);
    $grade=sf_text($p,'grade',80,1);
    $email=sf_text($p,'email',160);
    if($email!=='')sf_email($email);
    $city=sf_text($p,'city',100);
    $proof=sf_public_verify_challenge($store,$guid,$origin,(string)($p['captcha_token']??''),$p['captcha_answer']??'');
    $ipKey=substr(hash('sha256',$ip),0,20);
    return $store->transaction(static function(array &$s)use($store,$guid,$origin,$id,$code,$guardian,$phone,$student,$grade,$email,$city,$proof,$ipKey):array{
        $group=sf_public_group($s,$guid);
        if(!$group||!sf_public_origin_ok($group,$origin))sf_fail(403,'Website unauthorized');
        $route=null;
        foreach(sf_group_campuses($s,$group) as $campus)
            if($campus['code']===$code && sf_chat_settings($s,(int)$campus['school_id'])['enabled']){$route=$campus;break;}
        if($route===null)sf_fail(403,'Campus unavailable');
        foreach($s['receipts']??[] as $receipt)
          if(($receipt['chat_group_id']??null)===$group['id'] && ($receipt['chat_start_idempotency']??'')===$id){
            if(($receipt['school_id']??null)!==$route['school_id'])sf_fail(409,'Campus mismatch');
            $session=sf_chat_token($store,(int)$group['id'],(int)$route['school_id'],
                (int)$receipt['chat_session_lead_id'],(int)$receipt['chat_expires_at'],$origin);
            return [200,['success'=>true,'session'=>$session,'duplicate_submission'=>true,
                'quick_questions'=>array_slice(array_column(sf_chat_settings($s,(int)$route['school_id'])['faqs'],'question'),0,8)]];
          }
        sf_limit($s,'chat-start-ip:'.$group['id'].':'.$ipKey,8,3600);
        sf_limit($s,'chat-start-group:'.$group['id'],100,3600);
        foreach($s['receipts']??[] as $receipt)
            if(($receipt['public_challenge']??null)===$proof)sf_fail(409,'CAPTCHA already used');
        $lead=sf_create_lead($s,(int)$route['school_id'],[
            'parent_name'=>$guardian,'student_name'=>$student,
            'phone'=>'+91 '.$phone,'email'=>$email,'grade'=>$grade,'city'=>$city,
            'consent'=>true,'message'=>'Parent started a guided admission chat; follow up from CRM.',
            'utm_source'=>'admissions_guided_chat'
        ],(int)$route['site_id'],null,null,'public_chat_enquiry_created');
        $expires=time()+3600;
        $s['receipts'][]=['chat_group_id'=>$group['id'],'school_id'=>$route['school_id'],
            'chat_start_idempotency'=>$id,'public_challenge'=>$proof,
            'chat_session_lead_id'=>$lead['id'],'chat_expires_at'=>$expires,'created_at'=>sf_time()];
        return [201,['success'=>true,'session'=>sf_chat_token($store,(int)$group['id'],(int)$route['school_id'],
            (int)$lead['id'],$expires,$origin),'duplicate_submission'=>false,
            'quick_questions'=>array_slice(array_column(sf_chat_settings($s,(int)$route['school_id'])['faqs'],'question'),0,8)]];
    },true);
}
function sf_chat_followup(string $guid,string $code,string $token,string $origin,SchoolFlowStore $store):array {
    return $store->transaction(static function(array &$s)use($guid,$code,$token,$origin,$store):array{
        $group=sf_public_group($s,$guid);
        if(!$group||!sf_public_origin_ok($group,$origin))sf_fail(403,'Website unauthorized');
        foreach(sf_group_campuses($s,$group) as $route)if($route['code']===$code &&
          sf_chat_settings($s,(int)$route['school_id'])['enabled']){
            $leadId=sf_chat_session_lead($store,$token,(int)$group['id'],(int)$route['school_id'],$origin,$s);
            foreach($s['leads'] as &$lead)if($lead['id']===$leadId && $lead['school_id']===$route['school_id']){
                $lead['contact_state']='Callback Requested';
                $lead['updated_at']=sf_time();
                sf_event($s,(int)$route['school_id'],$leadId,null,'public_chat_callback_requested','Parent requested callback during guided chat');
                return [200,['ok'=>true]];
            }
            unset($lead);
        }
        sf_fail(404,'Chat lead not found');
    },true);
}
