<?php
declare(strict_types=1);
/** Release B: school-scoped tasks and student application records. */
const SF_TASK_KINDS=['First Contact','Follow-up','Campus Visit','Document Review','Application Review','Other'];
const SF_TASK_STATUSES=['Open','Completed','Cancelled'];
const SF_APPLICATION_STATUSES=['Started','Submitted','Documents Pending','Under Review','Offer Issued','Admission Confirmed','Enrolled','Rejected'];
function sf_task_due(array $t): string {
    if(($t['status']??'Open')!=='Open')return (string)($t['status']??'Completed');
    $date=(string)($t['due_date']??'');
    if($date==='')return 'Unscheduled';
    if($date<sf_admission_today())return 'Overdue';
    if($date===sf_admission_today())return 'Due Today';
    return 'Upcoming';
}
function sf_task_present(array $t,array $s): array {
    $t['due_status']=sf_task_due($t);
    $lead=sf_find($s['leads'],(int)$t['lead_id']);
    $t['student_name']=$lead['name']??'Unknown';
    $actor=isset($t['assigned_to'])&&$t['assigned_to']!==null?sf_user($s,(int)$t['assigned_to']):null;
    $t['assigned_name']=$actor['name']??'Unassigned';
    return $t;
}
/** Reconcile one open task per lead and task kind; no duplicate reminders on retries. */
function sf_task_schedule(array &$s,array $lead,string $kind,string $date,?int $actor=null): array {
    if(!in_array($kind,SF_TASK_KINDS,true))sf_fail(422,'Invalid task kind');
    $date=sf_date($date);
    if($date==='')sf_fail(422,'Task due date required');
    $s['tasks']=$s['tasks']??[];
    foreach($s['tasks'] as &$task)if($task['school_id']===$lead['school_id'] &&
        $task['lead_id']===$lead['id'] && $task['kind']===$kind && $task['status']==='Open'){
        $task['due_date']=$date;
        $task['assigned_to']=$lead['assigned_to']??null;
        $task['updated_at']=sf_time();
        $result=$task;unset($task);return $result;
    }unset($task);
    $task=['id'=>sf_id($s,'tasks'),'guid'=>sf_uuid(),'school_id'=>$lead['school_id'],
      'lead_id'=>$lead['id'],'assigned_to'=>$lead['assigned_to']??null,
      'kind'=>$kind,'title'=>$kind==='First Contact'?'Contact parent about new enquiry':($kind==='Follow-up'?'Follow up with parent':$kind),
      'due_date'=>$date,'status'=>'Open','created_at'=>sf_time(),'updated_at'=>sf_time()];
    $s['tasks'][]=$task;
    sf_event($s,$lead['school_id'],$lead['id'],$actor,'task_created',$kind.' due '.$date);
    return $task;
}
function sf_task_complete_kind(array &$s,int $sid,int $lid,string $kind,?int $actor=null):void {
    foreach($s['tasks']??[] as &$t)if($t['school_id']===$sid&&$t['lead_id']===$lid&&$t['kind']===$kind&&$t['status']==='Open'){
       $t['status']='Completed';$t['completed_at']=sf_time();$t['updated_at']=sf_time();
       sf_event($s,$sid,$lid,$actor,'task_completed',$kind);
    }unset($t);
}
function sf_task_sync_followup(array &$s,array $lead,?int $actor=null):void {
    $date=(string)($lead['followup_date']??'');
    if($date!=='')sf_task_schedule($s,$lead,'Follow-up',$date,$actor);
    else{
        foreach($s['tasks']??[] as &$task)if($task['school_id']===$lead['school_id'] &&
           $task['lead_id']===$lead['id'] && $task['kind']==='Follow-up' && $task['status']==='Open'){
            $task['status']='Cancelled';$task['updated_at']=sf_time();
        }unset($task);
    }
}
function sf_admission_phase(string $status): string {
    return match($status){
        'New','New Enquiry','Contact Attempted'=>'Enquiry',
        'Contacted','Contact Established','Counselling','Visit Scheduled','Visit Planned','Visit Completed'=>'Engagement',
        'Applied','Application Started','Application Submitted','Documents Pending','Under Review'=>'Application',
        'Offer Issued','Admission Confirmed','Enrolled'=>'Admission',
        'On Hold / Deferred','Waitlisted','Lost','Not Eligible','Duplicate / Invalid'=>'Exception',
        default=>'Unknown'
    };
}
function sf_application_for_lead(array $s,int $sid,int $leadId): ?array {
    foreach($s['applications']??[] as $a)if($a['school_id']===$sid&&$a['lead_id']===$leadId)return $a;
    return null;
}
function sf_application_can_enrol(array $s,int $sid,int $leadId): bool {
    $application=sf_application_for_lead($s,$sid,$leadId);
    return $application!==null && !empty($application['fee_recorded']) &&
        in_array(($application['status']??''),['Admission Confirmed','Enrolled'],true);
}

/** Configurable retry cadence, applied only after actual unsuccessful call attempts. */
function sf_followup_policy(array $s,int $sid):array {
    $p=$s['followup_policies'][(string)$sid]??[];
    return ['retry_days'=>$p['retry_days']??[1,2,4]];
}
function sf_followup_set_policy(array &$s,int $sid,int $actor,array $payload):array {
    $days=$payload['retry_days']??null;
    if(!is_array($days)||!array_is_list($days)||count($days)!==3)sf_fail(422,'Configure three retry intervals');
    foreach($days as $interval)if(!is_int($interval)||$interval<1||$interval>30)sf_fail(422,'Retry intervals must be 1–30 days');
    $s['followup_policies'][(string)$sid]=['retry_days'=>$days];
    sf_event($s,$sid,null,$actor,'followup_policy_changed','School contact retry cadence updated');
    return sf_followup_policy($s,$sid);
}
function sf_task_auto_retry(array &$s,int $sid,int $lid,int $actor):void {
    $policy=sf_followup_policy($s,$sid);
    foreach($s['leads'] as &$l)if($l['id']===$lid && $l['school_id']===$sid){
        if(in_array($l['status'],['Lost','Enrolled','Not Eligible','Duplicate / Invalid'],true))break;
        $today=sf_admission_today();
        if(!empty($l['followup_date']) && $l['followup_date']>$today)break; // preserve an already planned callback
        $attempt=max(1,(int)($l['contact_attempts_without_answer']??1));
        $offset=$policy['retry_days'][min(2,$attempt-1)];
        $date=(new DateTimeImmutable($today,new DateTimeZone('Asia/Kolkata')))->modify('+'.$offset.' days')->format('Y-m-d');
        $l['followup_date']=$date;$l['updated_at']=sf_time();$copy=$l;
        sf_event($s,$sid,$lid,$actor,'followup_retry_scheduled','Unanswered call retry due '.$date);
        break;
    }unset($l);
    if(isset($copy))sf_task_sync_followup($s,$copy,$actor);
}
