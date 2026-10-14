<?php
declare(strict_types=1);
/** School-scoped provider configuration and explicit, consent-checked sends.
 * Only fixed official API endpoints are supported: no arbitrary outbound URL / SSRF.
 * Encrypted credentials never leave server-side storage or enter the audit log.
 */
function sf_provider_view(array $s,int $sid): array {
    $c=$s['communication_settings'][(string)$sid]??[];
    return [
        'email'=>['provider'=>$c['email_provider']??'off','connected'=>($c['email_provider']??'off')==='resend'&&!empty($c['email_key']),
            'from'=>$c['email_from']??''],
        'whatsapp'=>['provider'=>$c['wa_provider']??'off','connected'=>($c['wa_provider']??'off')==='meta'&&!empty($c['wa_key']),
            'phone_number_id'=>$c['phone_number_id']??'', 'template'=>$c['wa_template']??'', 'language'=>$c['wa_language']??'en_US']
    ];
}
function sf_provider_set(array &$s,SchoolFlowStore $store,int $sid,int $uid,array $p): array {
    $old=$s['communication_settings'][(string)$sid]??[];
    $emailProvider=sf_text($p,'email_provider',20,2);
    $waProvider=sf_text($p,'wa_provider',20,2);
    if(!in_array($emailProvider,['off','resend'],true)||!in_array($waProvider,['off','meta'],true))sf_fail(422,'Unsupported provider');
    $from=sf_text($p,'email_from',254);
    if($emailProvider==='resend')sf_email($from);
    $pid=sf_text($p,'phone_number_id',30);
    if($waProvider==='meta'&&!preg_match('/^[0-9]{8,25}$/D',$pid))sf_fail(422,'Valid Meta phone number ID required');
    $template=sf_text($p,'wa_template',100);
    $language=sf_text($p,'wa_language',15)?:'en_US';
    if($waProvider==='meta'&&(!preg_match('/^[a-z0-9_]{1,100}$/D',$template)||!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$language)))sf_fail(422,'Approved template name and language required');
    $emailKey=sf_text($p,'email_api_key',1024);
    $waKey=sf_text($p,'wa_api_key',1024);
    if($emailKey!=='')$old['email_key']=$store->seal($emailKey);
    if($waKey!=='')$old['wa_key']=$store->seal($waKey);
    if($emailProvider==='resend'&&empty($old['email_key']))sf_fail(422,'Resend API key required');
    if($waProvider==='meta'&&empty($old['wa_key']))sf_fail(422,'Meta Cloud API access token required');
    $old['email_provider']=$emailProvider;$old['wa_provider']=$waProvider;
    $old['email_from']=$from;$old['phone_number_id']=$pid;
    $old['wa_template']=$template;$old['wa_language']=$language;
    $s['communication_settings'][(string)$sid]=$old;
    sf_event($s,$sid,null,$uid,'communication_settings_changed','Provider settings updated (secrets redacted)');
    return sf_provider_view($s,$sid);
}
function sf_fill_email(string $text,array $lead,string $school):string {
    return strtr($text,['{parent}'=>$lead['guardian'],'{student}'=>$lead['name'],
        '{school}'=>$school,'{grade}'=>$lead['grade']??'']);
}
/** Only use server-controlled destinations and never log response bodies. */
function sf_provider_request(string $channel,array $c,string $key,array $body,string $nonce):array {
    if(!function_exists('curl_init'))sf_fail(503,'PHP cURL extension is required');
    if($channel==='email'){
        $url='https://api.resend.com/emails';
        $headers=['Authorization: Bearer '.$key,'Content-Type: application/json','Idempotency-Key: '.$nonce];
    }else{
        $url='https://graph.facebook.com/v24.0/'.$c['phone_number_id'].'/messages';
        $headers=['Authorization: Bearer '.$key,'Content-Type: application/json'];
    }
    $handle=curl_init($url);
    curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_MAXREDIRS=>0]);
    $response=curl_exec($handle);$http=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if($response===false||$http<200||$http>=300)return ['ok'=>false,'http'=>$http];
    $decoded=json_decode($response,true);
    $id=$channel==='email'?($decoded['id']??''):($decoded['messages'][0]['id']??'');
    return ['ok'=>true,'http'=>$http,'provider_id'=>is_string($id)?substr($id,0,150):''];
}
function sf_provider_send(string $uri,array $p,array $headers,SchoolFlowStore $store,?callable $transport=null):array {
    $path=parse_url($uri,PHP_URL_PATH)?:'';
    if(!preg_match('#^/api/schools/(\d+)/communications/(email|whatsapp)/send$#D',$path,$m))sf_fail(404,'Not found');
    $sid=(int)$m[1];$channel=$m[2];$auth=$headers['authorization']??'';
    $nonce=$p['request_id']??'';
    if(!is_string($nonce)||!preg_match('/^[a-f0-9-]{36}$/iD',$nonce))sf_fail(422,'Request UUID required');
    $leadRef=$p['lead_id']??null;
    if(!is_int($leadRef)&&!is_string($leadRef))sf_fail(422,'Enquiry identifier required');
    $reservation=$store->transaction(function(&$s)use($sid,$channel,$auth,$leadRef,$nonce,$p,$store){
        $user=sf_session($s,$auth);$role=sf_membership($s,$sid,$user['id']);
        $leadId=sf_record_id($s,'leads',$leadRef,$sid);
        $lead=sf_lead_allowed($s,$sid,$leadId,$user['id'],$role);
        foreach($s['message_attempts']??[] as $attempt)if($attempt['school_id']===$sid&&($attempt['request_id']??null)===$nonce)sf_fail(409,'This message request has already been processed');
        if(empty($lead['consent']))sf_fail(403,'Guardian messaging consent required');
        if(!empty($lead['is_demo']))sf_fail(403,'Demo contacts cannot receive real messages');
        $c=$s['communication_settings'][(string)$sid]??[];
        $mode=$channel==='email'?'email':'wa';
        if(($c[$mode.'_provider']??'off')!==($channel==='email'?'resend':'meta')||empty($c[$mode.'_key']))sf_fail(409,'Provider is not connected');
        $key=$store->unseal($c[$mode.'_key']);
        if($channel==='email'){
            $email=$lead['email']??'';
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||str_ends_with(strtolower($email),'@example.invalid'))sf_fail(422,'Real guardian email required');
            $subject='Your enquiry at '.(sf_find($s['schools'],$sid)['name']??'our school');
            $text="Hello {parent},\n\nThank you for your enquiry for {student} at {school}.\n\nAdmissions Team";
            $tid=$p['template_id']??null;
            if($tid!==null&&$tid!==''){
                $t=sf_find($s['email_templates']??[],(int)$tid);
                if(!$t||$t['school_id']!==$sid||!empty($t['is_demo']))sf_fail(422,'Choose a real email template for provider sending');
                $subject=$t['subject'];$text=$t['body'];
            }
            $school=sf_find($s['schools'],$sid)['name']??'SchoolFlow';
            $body=['from'=>$c['email_from'],'to'=>[$email],'subject'=>sf_fill_email($subject,$lead,$school),
                'text'=>sf_fill_email($text,$lead,$school)];
        }else{
            $digits=preg_replace('/\D/','',$lead['phone']??'');
            if(strlen($digits)<10||strlen($digits)>15||preg_match('/^0{5}/',$digits))sf_fail(422,'Valid international-format guardian phone required');
            $body=['messaging_product'=>'whatsapp','to'=>$digits,'type'=>'template',
                'template'=>['name'=>$c['wa_template'],'language'=>['code'=>$c['wa_language']]]];
        }
        $s['message_attempts']=$s['message_attempts']??[];
        $today=gmdate('Y-m-d');$daily=0;
        foreach($s['message_attempts'] as $a)if($a['school_id']===$sid&&$a['actor_id']===$user['id']&&str_starts_with($a['created_at'],$today))$daily++;
        if($daily>=50)sf_fail(429,'Daily send limit reached');
        $entry=['id'=>sf_id($s,'message_attempts'),'guid'=>sf_uuid(),'school_id'=>$sid,'lead_id'=>$leadId,
            'actor_id'=>$user['id'],'request_id'=>$nonce,'channel'=>$channel,'status'=>'pending',
            'created_at'=>sf_time(),'provider_id'=>''];
        $s['message_attempts'][]=$entry;
        sf_event($s,$sid,$leadId,$user['id'],'message_submitted',$channel.' send requested');
        return ['id'=>$entry['id'],'body'=>$body,'key'=>$key,'settings'=>$c];
    },true);
    try{$out=$transport?($transport($channel,$reservation['body'],$nonce)):
        sf_provider_request($channel,$reservation['settings'],$reservation['key'],$reservation['body'],$nonce);
        if(!is_array($out)||!array_key_exists('ok',$out))$out=['ok'=>false];
    }catch(Throwable){$out=['ok'=>false];}
    $status=!empty($out['ok'])?'accepted':'failed';
    $store->transaction(function(&$s)use($reservation,$status,$out,$sid,$channel){
        foreach($s['message_attempts'] as &$a)if($a['id']===$reservation['id']){
            $a['status']=$status;$a['provider_id']=substr((string)($out['provider_id']??''),0,150);
            $a['updated_at']=sf_time();
            sf_event($s,$sid,$a['lead_id'],$a['actor_id'],'message_'.$status,$channel.' provider '.$status);
            break;
        }unset($a);
    },true);
    if($status==='failed')sf_fail(502,'Provider did not accept message. Check connection, provider approval and logs');
    return [202,['status'=>'accepted','detail'=>'Provider accepted request; delivery not yet confirmed']];
}
