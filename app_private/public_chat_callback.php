<?php
declare(strict_types=1);
/** Public callback: limited parent contact, school route and validated consent. */
function sf_chat_callback(string $guid,array $p,array $headers,string $ip,SchoolFlowStore $store):array {
    $origin=(string)($headers['origin']??'');
    $id=(string)($headers['idempotency-key']??'');
    if(!preg_match('/^[A-Za-z0-9_-]{12,100}$/D',$id))sf_fail(422,'Submission ID required');
    if(($p['consent']??false)!==true)sf_fail(422,'Contact consent is required');
    if(trim((string)($p['website']??''))!=='')sf_fail(422,'Invalid submission');
    $code=sf_text($p,'school_code',50,3);
    $guardian=sf_text($p,'parent_name',140,2);
    $phone=sf_text($p,'phone',10,10);
    if(!preg_match('/^[6-9][0-9]{9}$/D',$phone))sf_fail(422,'Valid mobile number required');
    $student=sf_text($p,'student_name',140);
    $email=sf_text($p,'email',160);
    if($email!=='')sf_email($email);
    $grade=sf_text($p,'grade',80);
    $preferred=sf_text($p,'preferred_time',100);
    $proof=sf_public_verify_challenge($store,$guid,$origin,(string)($p['captcha_token']??''),$p['captcha_answer']??'');
    $ipHash=substr(hash('sha256',$ip),0,20);
    return $store->transaction(static function(array &$s)use($guid,$origin,$id,$code,$guardian,$phone,$student,$email,$grade,$preferred,$proof,$ipHash):array{
        $group=sf_public_group($s,$guid);
        if(!$group||!sf_public_origin_ok($group,$origin))sf_fail(403,'Website unauthorized');
        $route=null;
        foreach(sf_group_campuses($s,$group) as $candidate)if($candidate['code']===$code){
            if(sf_chat_settings($s,(int)$candidate['school_id'])['enabled'])$route=$candidate;
            break;
        }
        if(!$route)sf_fail(403,'Campus unavailable');
        foreach($s['receipts']??[] as $row)if(($row['chat_group_id']??null)===$group['id']&&($row['idempotency_key']??'')===$id){
            if($row['school_id']!==$route['school_id'])sf_fail(409,'Campus mismatch');
            return [200,['success'=>true,'duplicate_submission'=>true]];
        }
        sf_limit($s,'chat-callback-ip:'.$group['id'].':'.$ipHash,8,3600);
        sf_limit($s,'chat-callback-total:'.$group['id'],100,3600);
        foreach($s['receipts']??[] as $r)if(($r['public_challenge']??null)===$proof)sf_fail(409,'CAPTCHA already used');
        $details=['parent_name'=>$guardian,'student_name'=>$student!==''?$student:'Student details pending',
             'phone'=>'+91 '.$phone,'email'=>$email,'grade'=>$grade,'consent'=>true,
             'message'=>'Requested callback through admissions chatbot'.($preferred!==''?'; preferred time: '.$preferred:''),
             'utm_source'=>'public_admissions_chat'];
        $lead=sf_create_lead($s,(int)$route['school_id'],$details,(int)$route['site_id'],null,null,'public_chat_callback_requested');
        foreach($s['leads'] as &$saved)if($saved['id']===$lead['id']){
            $saved['contact_state']='Callback Requested';
            $saved['lead_channel']='chatbot';
            break;
        }
        unset($saved);
        $s['receipts'][]=['chat_group_id'=>$group['id'],'school_id'=>$route['school_id'],
             'idempotency_key'=>$id,'public_challenge'=>$proof,'lead_id'=>$lead['id'],'created_at'=>sf_time()];
        return [201,['success'=>true,'guid'=>$lead['guid'],'duplicate_submission'=>false]];
    },true);
}
