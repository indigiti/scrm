<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function wh_assert(bool $value,string $message):void{if(!$value)throw new RuntimeException('Provider webhook: '.$message);}
$secret='whsec_'.base64_encode(random_bytes(32));
$raw=json_encode(['type'=>'email.delivered','data'=>['email_id'=>'mail-123']],JSON_THROW_ON_ERROR);
$id='msg_test_event_123';$stamp=(string)time();
$signature='v1,'.base64_encode(hash_hmac('sha256',$id.'.'.$stamp.'.'.$raw,base64_decode(substr($secret,6)),true));
$headers=['svix-id'=>$id,'svix-timestamp'=>$stamp,'svix-signature'=>$signature];
wh_assert(sf_webhook_svix_verified($raw,$headers,$secret),'valid Resend Svix signature');
wh_assert(!sf_webhook_svix_verified($raw.'x',$headers,$secret),'payload tamper rejected');
wh_assert(!sf_webhook_svix_verified($raw,$headers,$secret,time()+900),'expired timestamp rejected');
$metaSecret='test-meta-app-secret-long';
$metaSignature='sha256='.hash_hmac('sha256',$raw,$metaSecret);
wh_assert(sf_webhook_meta_verified($raw,$metaSignature,$metaSecret),'Meta signature valid');
wh_assert(!sf_webhook_meta_verified($raw.'x',$metaSignature,$metaSecret),'Meta tamper rejected');
$dir=sys_get_temp_dir().'/scrm-webhook-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);
try{
    $store=new SchoolFlowStore($dir);
    $store->transaction(static function(array &$s){
        $s['schools']=[['id'=>1,'name'=>'School One'],['id'=>2,'name'=>'School Two']];
        $s['leads']=[['id'=>11,'school_id'=>1],['id'=>12,'school_id'=>2]];
        $s['message_attempts']=[
            ['id'=>1,'school_id'=>1,'lead_id'=>11,'channel'=>'email','provider_id'=>'mail-123','status'=>'accepted'],
            ['id'=>2,'school_id'=>2,'lead_id'=>12,'channel'=>'email','provider_id'=>'mail-123','status'=>'accepted'],
            ['id'=>3,'school_id'=>1,'lead_id'=>11,'channel'=>'whatsapp','provider_id'=>'wa-456','status'=>'accepted']];
        $s['communication_settings']=['1'=>['phone_number_id'=>'123456789']];
    },true);
    putenv('SCHOOLFLOW_RESEND_WEBHOOK_SECRET_1='.$secret);
    [$status,$out]=sf_webhook_receive('POST','/api/webhooks/resend/1',$raw,$headers,$store);
    wh_assert($status===200&&$out['matched']===1&&$out['changed']===1,'Resend delivery reconciled');
    [$status,$again]=sf_webhook_receive('POST','/api/webhooks/resend/1',$raw,$headers,$store);
    wh_assert($again['changed']===0,'duplicate provider event is idempotent');
    $state=$store->transaction(static fn(array $s)=>$s);
    wh_assert($state['message_attempts'][0]['status']==='delivered','updated only matched school');
    wh_assert($state['message_attempts'][1]['status']==='accepted','second school protected');
    $wa=['entry'=>[['changes'=>[['value'=>['metadata'=>['phone_number_id'=>'123456789'],
        'statuses'=>[['id'=>'wa-456','status'=>'read']]]]]]]];
    $waRaw=json_encode($wa,JSON_THROW_ON_ERROR);
    putenv('SCHOOLFLOW_META_WEBHOOK_APP_SECRET_1='.$metaSecret);
    $waHdr=['x-hub-signature-256'=>'sha256='.hash_hmac('sha256',$waRaw,$metaSecret)];
    [$status,$waOut]=sf_webhook_receive('POST','/api/webhooks/meta/1',$waRaw,$waHdr,$store);
    wh_assert($status===200&&$waOut['changed']===1,'Meta read receipt processed');
    $state=$store->transaction(static fn(array $s)=>$s);
    wh_assert($state['message_attempts'][2]['status']==='read','WhatsApp state advanced');
    $badHeaders=$headers;$badHeaders['svix-signature']='v1,invalid';
    try{sf_webhook_receive('POST','/api/webhooks/resend/1',$raw,$badHeaders,$store);throw new RuntimeException('Unsigned webhook accepted');}
    catch(SFError $e){wh_assert($e->httpStatus===401,'bad signature blocked');}
    putenv('SCHOOLFLOW_RESEND_WEBHOOK_SECRET_1');putenv('SCHOOLFLOW_META_WEBHOOK_APP_SECRET_1');
}finally{
    foreach(glob($dir.'/*')?:[] as $path)@unlink($path);
    @rmdir($dir);
}
echo "PASS: v2.4 Resend/Meta HMAC verification, isolation, replay and delivery status\n";
