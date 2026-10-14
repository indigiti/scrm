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
