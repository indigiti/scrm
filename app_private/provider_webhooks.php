<?php
declare(strict_types=1);
/**
 * v2.4 provider delivery reconciliation. Authenticity is checked against
 * per-school secrets provisioned on the server, never user-supplied tokens.
 * Never persist provider payloads or parent contact fields.
 */
function sf_webhook_svix_verified(string $raw,array $headers,string $secret,?int $now=null):bool{
    $id=(string)($headers['svix-id']??'');
    $timestamp=(string)($headers['svix-timestamp']??'');
    $signature=(string)($headers['svix-signature']??'');
    if(!preg_match('/^[\w-]{8,150}$/D',$id)||!preg_match('/^[0-9]{10,12}$/D',$timestamp)||
       abs(($now??time())-(int)$timestamp)>300 || !str_starts_with($secret,'whsec_'))return false;
    $key=base64_decode(strtr(substr($secret,6),'-_','+/'),true);
    if($key===false||strlen($key)<16)return false;
    $expected=base64_encode(hash_hmac('sha256',$id.'.'.$timestamp.'.'.$raw,$key,true));
    foreach(preg_split('/\s+/',trim($signature))?:[] as $part)
        if(str_starts_with($part,'v1,')&&hash_equals($expected,substr($part,3)))return true;
    return false;
}
function sf_webhook_meta_verified(string $raw,string $signature,string $secret):bool{
    if(strlen($secret)<16||!preg_match('/^sha256=([0-9a-f]{64})$/D',$signature,$m))return false;
    return hash_equals(hash_hmac('sha256',$raw,$secret),$m[1]);
}
function sf_webhook_update_status(array &$s,int $sid,string $channel,string $providerId,string $status,string $eventKey):array{
    if(!in_array($channel,['email','whatsapp'],true)||!in_array($status,['accepted','delivered','read','failed'],true))
        throw new InvalidArgumentException('Invalid delivery state');
    $s['processed_provider_events']=$s['processed_provider_events']??[];
    $key=hash('sha256',$sid.'|'.$channel.'|'.$eventKey);
    if(isset($s['processed_provider_events'][$key]))return ['duplicate'=>true,'matched'=>true,'changed'=>false];
    $matched=false;$changed=false;$leadId=null;
    $rank=['pending'=>0,'accepted'=>1,'needs_review'=>1,'failed'=>2,'delivered'=>3,'read'=>4];
    foreach(['message_attempts','notification_queue'] as $collection){
        foreach($s[$collection]??[] as &$row){
            if(($row['school_id']??null)!==$sid||($row['channel']??'')!==$channel||
                (string)($row['provider_id']??'')!==$providerId||$providerId==='')continue;
            $matched=true;$leadId=(int)($row['lead_id']??0);
            $old=(string)($row['status']??'pending');
            // Never regress confirmed delivery/read, nor promote a terminal
            // failed message on an out-of-order provider retry.
            $accept=($status==='failed'&&in_array($old,['pending','accepted','needs_review'],true))||
                ($status!=='failed' && $old!=='failed' && ($rank[$status]??0)>($rank[$old]??0));
            if($accept){$row['status']=$status;$row['updated_at']=sf_time();$row['status_source']='signed_provider_webhook';$changed=true;}
        }unset($row);
    }
    if(!$matched)return ['duplicate'=>false,'matched'=>false,'changed'=>false];
    $s['processed_provider_events'][$key]=sf_time();
    // Old event dedupe keys may expire, but status remains monotonic.
    if(count($s['processed_provider_events'])>10000)$s['processed_provider_events']=array_slice($s['processed_provider_events'],-9000,null,true);
    if($changed)sf_event($s,$sid,$leadId,null,'provider_webhook_reconciled',$channel.' delivery status '.$status);
    return ['duplicate'=>false,'matched'=>true,'changed'=>$changed];
}
function sf_webhook_receive(string $method,string $path,string $raw,array $headers,SchoolFlowStore $store):array{
    if($method!=='POST')sf_fail(405,'Only POST webhooks are accepted');
    if(strlen($raw)>65536||$raw==='')sf_fail(413,'Invalid webhook size');
    if(!preg_match('~^/api/webhooks/(resend|meta)/([0-9]+|[a-f0-9-]{36})$~iD',$path,$m))sf_fail(404,'Webhook unavailable');
    $provider=strtolower($m[1]);$ref=$m[2];
    $sid=$store->transaction(static fn(array $s)=>sf_record_id($s,'schools',$ref));
    $setting=$provider==='resend'?'SCHOOLFLOW_RESEND_WEBHOOK_SECRET_':'SCHOOLFLOW_META_WEBHOOK_APP_SECRET_';
    $secret=getenv($setting.$sid);
    if(!is_string($secret)||strlen($secret)<16)sf_fail(503,'Webhook is not configured');
    $valid=$provider==='resend'
        ?sf_webhook_svix_verified($raw,$headers,$secret)
        :sf_webhook_meta_verified($raw,(string)($headers['x-hub-signature-256']??''),$secret);
    if(!$valid)sf_fail(401,'Webhook signature rejected');
    $event=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($event))sf_fail(422,'Invalid webhook event');
    $updates=[];
    if($provider==='resend'){
        $eventType=(string)($event['type']??'');
        $status=match($eventType){
            'email.delivered'=>'delivered',
            'email.bounced','email.failed','email.complained'=>'failed',
            'email.sent'=>'accepted',
            default=>null
        };
        $providerId=(string)($event['data']['email_id']??'');
        if($status!==null&&$providerId!==''&&strlen($providerId)<=150)
            $updates[]=['email',$providerId,$status,(string)($headers['svix-id']??'')];
    }else{
        $phoneId=$store->transaction(static fn(array $s)=>(string)($s['communication_settings'][(string)$sid]['phone_number_id']??''));
        if($phoneId==='')sf_fail(409,'Meta phone number is not configured');
        foreach($event['entry']??[] as $entry)foreach($entry['changes']??[] as $change){
            $value=$change['value']??[];
            if((string)($value['metadata']['phone_number_id']??'')!==$phoneId)continue;
            foreach($value['statuses']??[] as $item){
                $providerId=(string)($item['id']??'');$state=(string)($item['status']??'');
                $status=match($state){'sent'=>'accepted','delivered'=>'delivered','read'=>'read','failed'=>'failed',default=>null};
                if($status!==null&&$providerId!==''&&strlen($providerId)<=150)
                    $updates[]=['whatsapp',$providerId,$status,hash('sha256',$raw.'|'.$providerId.'|'.$state)];
            }
        }
    }
    if(count($updates)>50)sf_fail(422,'Too many webhook updates');
    if(!$updates)return [200,['ok'=>true,'processed'=>0]];
    return $store->transaction(static function(array &$s)use($updates,$sid):array{
        $changed=0;$matched=0;
        foreach($updates as [$channel,$providerId,$status,$eventKey]){
            $out=sf_webhook_update_status($s,$sid,$channel,$providerId,$status,$eventKey);
            if($out['matched'])$matched++;
            if($out['changed'])$changed++;
        }
        return [200,['ok'=>true,'processed'=>count($updates),'matched'=>$matched,'changed'=>$changed]];
    },true);
}
