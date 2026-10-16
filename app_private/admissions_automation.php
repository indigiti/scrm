<?php
declare(strict_types=1);
/**
 * Release D: opt-in, idempotent cron-safe notification dispatch.
 * The scheduler NEVER sends unless a school admin explicitly enables it,
 * provider credentials exist and the parent has channel-specific consent.
 */
const SF_AUTO_CHANNELS=['email','whatsapp'];
const SF_AUTO_TASK_KINDS=['First Contact','Follow-up','Campus Visit'];
function sf_auto_policy(array $s,int $sid):array{
    $p=$s['automation_settings'][(string)$sid]??[];
    return ['enabled'=>($p['enabled']??false)===true,
        'email'=>($p['email']??false)===true,'whatsapp'=>($p['whatsapp']??false)===true,
        'task_kinds'=>is_array($p['task_kinds']??null)?$p['task_kinds']:['Follow-up','Campus Visit'],
        'max_daily'=>min(50,max(1,(int)($p['max_daily']??10))),
        'send_start'=>$p['send_start']??'09:00','send_end'=>$p['send_end']??'18:00'];
}
function sf_auto_set_policy(array &$s,int $sid,int $uid,array $p):array {
    foreach(['enabled','email','whatsapp'] as $name)if(!is_bool($p[$name]??null))sf_fail(422,$name.' must be boolean');
    $kinds=$p['task_kinds']??[];
    if(!is_array($kinds)||!array_is_list($kinds)||count($kinds)>3)sf_fail(422,'Invalid automation kinds');
    foreach($kinds as $kind)if(!in_array($kind,SF_AUTO_TASK_KINDS,true))sf_fail(422,'Unsupported automated task type');
    $kinds=array_values(array_unique($kinds));
    $max=$p['max_daily']??10;
    if(!is_int($max)||$max<1||$max>50)sf_fail(422,'Max daily must be between 1 and 50');
    $previous=sf_auto_policy($s,$sid);
    $start=$p['send_start']??$previous['send_start'];
    $end=$p['send_end']??$previous['send_end'];
    if(!is_string($start)||!is_string($end)||
       !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$start)||
       !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$end)||$start>=$end)
        sf_fail(422,'Choose a same-day reminder window with start before end');
    $policy=['enabled'=>$p['enabled'],'email'=>$p['email'],'whatsapp'=>$p['whatsapp'],
        'task_kinds'=>$kinds,'max_daily'=>$max,'send_start'=>$start,'send_end'=>$end];
    if($policy['enabled'] && (!$kinds || (!$policy['email']&&!$policy['whatsapp'])))
        sf_fail(422,'Enable at least one channel and task type');
    $s['automation_settings'][(string)$sid]=$policy;
    sf_event($s,$sid,null,$uid,'automation_policy_changed',
        $policy['enabled']?'Automations enabled by administrator':'Automations disabled by administrator');
    return $policy;
}
function sf_auto_consent(array $lead,string $channel):bool {
    return !empty($lead['consent']) && !empty($lead['consent_'.$channel]) &&
        empty($lead['is_demo']) && !in_array($lead['status']??'',['Lost','Enrolled','Not Eligible','Duplicate / Invalid'],true);
}
function sf_auto_connected(array $s,int $sid,string $channel):bool {
    $c=$s['communication_settings'][(string)$sid]??[];
    $prefix=$channel==='email'?'email':'wa';
    return ($c[$prefix.'_provider']??'off')===($channel==='email'?'resend':'meta') && !empty($c[$prefix.'_key']);
}
function sf_auto_within_hours(array $policy,?DateTimeImmutable $now=null):bool {
    $now=($now??new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $current=$now->format('H:i');
    return $current>=($policy['send_start']??'09:00') && $current<($policy['send_end']??'18:00');
}
function sf_auto_local_day(string $timestamp):string{
    try{return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d');}
    catch(Throwable){return '';}
}
function sf_auto_queue_view(array $entry):array {
    // Safe staff response omits internal nonces and request metadata.
    unset($entry['request_id']);
    return $entry;
}
function sf_auto_enqueue_due(array &$s):int {
    $count=0;$s['notification_queue']=$s['notification_queue']??[];
    foreach($s['tasks']??[] as $task){
        if(($task['status']??'')!=='Open' || empty($task['due_date']) || ($task['due_date']??'')>sf_admission_today())continue;
        $sid=(int)$task['school_id'];$policy=sf_auto_policy($s,$sid);
        if(!$policy['enabled']||!in_array($task['kind'],$policy['task_kinds'],true))continue;
        $lead=sf_find($s['leads'],(int)$task['lead_id']);
        if(!$lead||$lead['school_id']!==$sid)continue;
        foreach(SF_AUTO_CHANNELS as $channel){
            if(!$policy[$channel] || !sf_auto_consent($lead,$channel) || !sf_auto_connected($s,$sid,$channel))continue;
            $unique='task:'.($task['guid']??(string)$task['id']).':'.($task['revision']??1).':'.$task['due_date'].':'.$channel;
            $exists=false;
            foreach($s['notification_queue'] as $existing)if(($existing['event_key']??'')===$unique){$exists=true;break;}
            if($exists)continue;
            $s['notification_queue'][]=['id'=>sf_id($s,'notification_queue'),'guid'=>sf_uuid(),
                'school_id'=>$sid,'lead_id'=>$lead['id'],'task_id'=>$task['id'],
                'channel'=>$channel,'kind'=>$task['kind'],'task_due_date'=>$task['due_date'],'task_revision'=>$task['revision']??1,'event_key'=>$unique,
                'status'=>'queued','attempts'=>0,'created_at'=>sf_time()];
            $count++;
        }
    }
    return $count;
}
function sf_auto_reserve(array &$s,SchoolFlowStore $store):?array {
    foreach($s['notification_queue'] as &$entry){
        if(($entry['status']??'')!=='queued')continue;
        $sid=$entry['school_id'];$policy=sf_auto_policy($s,$sid);
        $channel=$entry['channel'];
        $task=sf_find($s['tasks']??[],(int)$entry['task_id']);
        $lead=sf_find($s['leads'],(int)$entry['lead_id']);
        $reason='';
        if(!$policy['enabled']||!$policy[$channel])$reason='School automation policy disabled';
        elseif(!$lead||!$task||$lead['school_id']!==$sid||$task['school_id']!==$sid||$task['status']!=='Open')$reason='Related lead/task inactive';
        elseif(($task['due_date']??'')!==($entry['task_due_date']??'') || (int)($task['revision']??1)!==(int)($entry['task_revision']??1) || ($task['due_date']??'')>sf_admission_today())$reason='Task was rescheduled or is not yet due';
        elseif(!sf_auto_consent($lead,$channel))$reason='Consent withdrawn or enquiry closed';
        elseif(!sf_auto_connected($s,$sid,$channel))$reason='Messaging provider unconfigured';
        if($reason!==''){
            $entry['status']='skipped';$entry['updated_at']=sf_time();$entry['reason']=$reason;
            continue;
        }
        if(!sf_auto_within_hours($policy))continue; // keep queued until permitted school-local hours
        $sentToday=0;
        foreach($s['message_attempts']??[] as $attempt)if($attempt['school_id']===$sid &&
            !empty($attempt['automated']) && sf_auto_local_day((string)($attempt['created_at']??''))===sf_admission_today())$sentToday++;
        if($sentToday >= $policy['max_daily'])return null; // leave queued for next cron/day
        $c=$s['communication_settings'][(string)$sid];
        $key=$store->unseal($c[$channel==='email'?'email_key':'wa_key']);
        if($channel==='email'){
            if(!filter_var($lead['email'],FILTER_VALIDATE_EMAIL)||str_ends_with(strtolower($lead['email']),'@example.invalid')){
                $entry['status']='skipped';$entry['reason']='Guardian email missing/invalid';continue;
            }
            $school=sf_find($s['schools'],$sid)['name']??'School';
            $body=['from'=>$c['email_from'],'to'=>[$lead['email']],
                'subject'=>'Reminder from '.$school,
                'text'=>sf_fill_email('Hello {parent},'."\n\n".'This is a reminder from {school} regarding {student}. Please contact our admissions office if you need assistance.',$lead,$school)];
        }else{
            $digits=preg_replace('/\D/','',(string)($lead['phone']??''));
            if(strlen($digits)<10||strlen($digits)>15||preg_match('/^0{5}/',$digits)){
                $entry['status']='skipped';$entry['reason']='Invalid WhatsApp number';continue;
            }
            $body=['messaging_product'=>'whatsapp','to'=>$digits,'type'=>'template',
                'template'=>['name'=>$c['wa_template'],'language'=>['code'=>$c['wa_language']]]];
        }
        $nonce=sf_uuid();
        $attempt=['id'=>sf_id($s,'message_attempts'),'guid'=>sf_uuid(),'school_id'=>$sid,
            'lead_id'=>$lead['id'],'actor_id'=>null,'request_id'=>$nonce,
            'channel'=>$channel,'status'=>'pending','provider_id'=>'','automated'=>true,
            'created_at'=>sf_time()];
        $s['message_attempts'][]=$attempt;
        $entry['status']='sending';$entry['attempts']=(int)$entry['attempts']+1;
        $entry['message_attempt_id']=$attempt['id'];$entry['updated_at']=sf_time();
        sf_event($s,$sid,$lead['id'],null,'automation_send_reserved',
            $channel.' reminder reserved for '.$task['kind']);
        $reserved=['queue_id'=>$entry['id'],'message_id'=>$attempt['id'],
           'sid'=>$sid,'lead_id'=>$lead['id'],'channel'=>$channel,
           'settings'=>$c,'key'=>$key,'body'=>$body,'nonce'=>$nonce];
        unset($entry);
        return $reserved;
    }unset($entry);
    return null;
}
function sf_auto_run(SchoolFlowStore $store,?callable $transport=null,int $max=20):array{
    $max=min(20,max(1,$max));
    $enqueued=$store->transaction(static function(array &$s):int{
        return sf_auto_enqueue_due($s);
    },true);
    $accepted=0;$review=0;
    for($i=0;$i<$max;$i++){
        $send=$store->transaction(static function(array &$s)use($store):?array{
            return sf_auto_reserve($s,$store);
        },true);
        if($send===null)break;
        try{
            $out=$transport?$transport($send['channel'],$send['body'],$send['nonce']):
                sf_provider_request($send['channel'],$send['settings'],$send['key'],$send['body'],$send['nonce']);
            if(!is_array($out)||!array_key_exists('ok',$out))$out=['ok'=>false];
        }catch(Throwable){$out=['ok'=>false];}
        // Provider failure/timeout is ambiguous; NEVER automatically repeat with a new nonce.
        $status=!empty($out['ok'])?'accepted':'needs_review';
        $store->transaction(static function(array &$s)use($send,$status,$out){
            foreach($s['notification_queue'] as &$q)if($q['id']===$send['queue_id']){
                $q['status']=$status;$q['provider_id']=substr((string)($out['provider_id']??''),0,150);
                $q['updated_at']=sf_time();break;
            }unset($q);
            foreach($s['message_attempts'] as &$a)if($a['id']===$send['message_id']){
                $a['status']=$status;$a['provider_id']=substr((string)($out['provider_id']??''),0,150);
                $a['updated_at']=sf_time();break;
            }unset($a);
            sf_event($s,$send['sid'],$send['lead_id'],null,'automation_'.$status,
                $send['channel'].' provider '.$status.' (not proof of delivery)');
        },true);
        if($status==='accepted')$accepted++;else $review++;
    }
    return ['enqueued'=>$enqueued,'provider_accepted'=>$accepted,'needs_review'=>$review];
}
function sf_auto_reconcile(array &$s,int $sid,int $queueId,int $actor,array $payload):array {
    $status=sf_text($payload,'status',30,4);
    if(!in_array($status,['delivered','read','failed'],true))sf_fail(422,'Invalid reconciliation status');
    $reference=sf_text($payload,'provider_evidence_reference',180,8);
    foreach($s['notification_queue'] as &$entry)if($entry['id']===$queueId && $entry['school_id']===$sid){
        if(!in_array($entry['status'],['accepted','needs_review','delivered','read','failed'],true))sf_fail(409,'Only processed messages can be reconciled');
        $entry['status']=$status;$entry['evidence_reference']=$reference;$entry['reconciled_by']=$actor;
        $entry['reconciled_at']=sf_time();
        foreach($s['message_attempts'] as &$m)if($m['id']===($entry['message_attempt_id']??null)){$m['status']=$status;break;}unset($m);
        sf_event($s,$sid,$entry['lead_id'],$actor,'automation_delivery_reconciled',
            $entry['channel'].' marked '.$status.' with provider evidence recorded');
        $result=sf_auto_queue_view($entry);unset($entry);return $result;
    }unset($entry);
    sf_fail(404,'Notification not found');
}
