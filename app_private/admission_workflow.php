<?php
declare(strict_types=1);
/** Admissions v3 classification is additive and safe for historical JSON records. */
const SF_TEMPERATURES = ['Unrated','Hot','Warm','Cold'];
const SF_CONTACT_STATES = ['Pending Contact','Attempted','Connected','Awaiting Parent Response','Callback Requested','Unresponsive','No Action Required'];
const SF_LOST_REASONS = ['Fees','Location','Transport','Curriculum','Admission elsewhere','No longer interested','No response','Other'];

function sf_admission_today(): string {
    return (new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
}
function sf_admission_followup(array $lead): string {
    if(in_array($lead['status']??'', ['Enrolled','Lost','Not Eligible','Duplicate / Invalid'],true))return 'No Action Required';
    $next=(string)($lead['followup_date']??'');
    if($next!=='' && $next<sf_admission_today())return 'Follow-up Overdue';
    if($next!=='' && $next===sf_admission_today())return 'Follow-up Due';
    $state=(string)($lead['contact_state']??'Pending Contact');
    if($state==='Callback Requested')return 'Callback Requested';
    if($state==='Unresponsive')return 'Unresponsive';
    if($state==='Awaiting Parent Response')return 'Awaiting Parent Response';
    if($state==='No Action Required')return 'No Action Required';
    if(empty($lead['last_successful_contact_at']))return 'Pending Contact';
    return $next!==''?'Follow-up Scheduled':'No Action Required';
}
function sf_admission_view(array $lead): array {
    $lead['temperature']=$lead['temperature']??'Unrated';
    $lead['temperature_suggestion']=$lead['temperature_suggestion']??'Unrated';
    $lead['contact_state']=$lead['contact_state']??(empty($lead['last_successful_contact_at'])?'Pending Contact':'Connected');
    $lead['followup_status']=sf_admission_followup($lead);
    $lead['lost_reason']=$lead['lost_reason']??'';
    $lead['academic_year']=$lead['academic_year']??'';
    $lead['contact_attempts']=$lead['contact_attempts']??0;
    $lead['last_successful_contact_at']=$lead['last_successful_contact_at']??'';
    return $lead;
}
function sf_admission_extra_changes(array $payload): array {
    $changes=[];
    if(array_key_exists('temperature',$payload)){
        $temp=sf_text($payload,'temperature',10,3);
        if(!in_array($temp,SF_TEMPERATURES,true))sf_fail(422,'Invalid lead temperature');
        $reason=sf_text($payload,'temperature_reason',240,8);
        $changes['temperature']=$temp;$changes['temperature_reason']=$reason;
    }
    if(array_key_exists('contact_state',$payload)){
        $state=sf_text($payload,'contact_state',40,3);
        if(!in_array($state,SF_CONTACT_STATES,true))sf_fail(422,'Invalid contact state');
        $changes['contact_state']=$state;
    }
    if(array_key_exists('lost_reason',$payload)){
        $reason=sf_text($payload,'lost_reason',50);
        if($reason!==''&&!in_array($reason,SF_LOST_REASONS,true))sf_fail(422,'Invalid lost reason');
        $changes['lost_reason']=$reason;
    }
    if(array_key_exists('academic_year',$payload)){
        $v=sf_text($payload,'academic_year',7);
        if($v!=='' && !preg_match('/^20[2-9][0-9]-[0-9]{2}$/D',$v))sf_fail(422,'Academic year must be YYYY-YY');
        if($v!=='' && ((int)substr($v,5,2))!==(((int)substr($v,2,2)+1)%100))sf_fail(422,'Academic year end must follow start');
        $changes['academic_year']=$v;
    }
    if(array_key_exists('current_school',$payload))$changes['current_school']=sf_text($payload,'current_school',140);
    if(array_key_exists('date_of_birth',$payload))$changes['date_of_birth']=sf_date($payload['date_of_birth']);
    return $changes;
}
function sf_admission_call_update(array &$lead,string $outcome,string $timestamp): void {
    $lead['contact_attempts']=(int)($lead['contact_attempts']??0)+1;
    $lead['last_attempt_at']=$timestamp;
    $connected=in_array($outcome,['Answered','Interested','Not Interested','Callback Requested'],true);
    if($connected){
        $lead['last_successful_contact_at']=$timestamp;
        $lead['last_contact_at']=$timestamp; // backward-compatible, but now means an actual conversation
        $lead['contact_attempts_without_answer']=0;
        $lead['contact_state']=$outcome==='Callback Requested'?'Callback Requested':'Connected';
    }else{
        $count=(int)($lead['contact_attempts_without_answer']??0)+1;
        $lead['contact_attempts_without_answer']=$count;
        if(empty($lead['last_successful_contact_at']))$lead['contact_state']=$count>=3?'Unresponsive':'Attempted';
    }
    if($outcome==='Interested')$lead['temperature_suggestion']='Warm';
    if($outcome==='Not Interested')$lead['temperature_suggestion']='Cold';
    if($outcome==='Callback Requested')$lead['temperature_suggestion']='Warm';
}
