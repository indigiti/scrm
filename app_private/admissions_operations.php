<?php
declare(strict_types=1);
/** Metadata-only document checklist, academic-year capacity and first-response SLA. */
const SF_DOCUMENT_TYPES=['Birth Certificate','Address Proof','Previous Report Card','Transfer Certificate','Student Photograph'];
const SF_DOCUMENT_STATUSES=['Pending','Received','Verified','Rejected'];
function sf_doc_list(array $s,int $sid,int $leadId):array {
    $existing=[];foreach($s['admission_documents']??[] as $entry)
        if(($entry['school_id']??null)===$sid && ($entry['lead_id']??null)===$leadId)
            $existing[$entry['type']]=$entry;
    $result=[];foreach(SF_DOCUMENT_TYPES as $type)
        $result[]=$existing[$type]??['type'=>$type,'status'=>'Pending','lead_id'=>$leadId,'school_id'=>$sid];
    return $result;
}
function sf_doc_update(array &$s,int $sid,int $leadId,int $actor,string $role,array $payload):array {
    $type=sf_text($payload,'type',60,4);$status=sf_text($payload,'status',15,4);
    if(!in_array($type,SF_DOCUMENT_TYPES,true)||!in_array($status,SF_DOCUMENT_STATUSES,true))sf_fail(422,'Invalid document checklist value');
    if($status==='Verified' && !in_array($role,['Admin','Admissions'],true))sf_fail(403,'Only admissions management can verify documents');
    $note=sf_text($payload,'note',240);
    $s['admission_documents']=$s['admission_documents']??[];
    foreach($s['admission_documents'] as &$doc)if($doc['school_id']===$sid && $doc['lead_id']===$leadId && $doc['type']===$type){
        if(($doc['status']??'')==='Verified' && !in_array($role,['Admin','Admissions'],true))sf_fail(403,'Verified documents are restricted to admissions management');
        $doc['status']=$status;$doc['note']=$note;$doc['updated_by']=$actor;$doc['updated_at']=sf_time();
        $result=$doc;unset($doc);
        sf_event($s,$sid,$leadId,$actor,'admission_document_updated',$type.' → '.$status);
        return $result;
    }unset($doc);
    $record=['id'=>sf_id($s,'admission_documents'),'guid'=>sf_uuid(),'school_id'=>$sid,'lead_id'=>$leadId,
        'type'=>$type,'status'=>$status,'note'=>$note,'updated_by'=>$actor,'updated_at'=>sf_time()];
    $s['admission_documents'][]=$record;
    sf_event($s,$sid,$leadId,$actor,'admission_document_updated',$type.' → '.$status);
    return $record;
}
function sf_capacity_plan(array $s,int $sid):array {
    $results=[];
    foreach($s['seat_plans']??[] as $entry)if($entry['school_id']===$sid){
        $enrolled=0;
        foreach($s['leads'] as $lead)if($lead['school_id']===$sid && ($lead['status']??'')==='Enrolled' &&
            ($lead['academic_year']??'')===$entry['academic_year'] && ($lead['grade']??'')===$entry['grade'])$enrolled++;
        $entry['enrolled']=$enrolled;$entry['remaining']=max(0,$entry['capacity']-$enrolled);
        $results[]=$entry;
    }
    usort($results,static fn($a,$b)=>strcmp($a['academic_year'].$a['grade'],$b['academic_year'].$b['grade']));
    return $results;
}
function sf_capacity_upsert(array &$s,int $sid,int $actor,array $payload):array {
    $year=sf_text($payload,'academic_year',7,7);
    if(!preg_match('/^20[2-9][0-9]-[0-9]{2}$/D',$year))sf_fail(422,'Academic year must be YYYY-YY');
    if((int)substr($year,5,2)!==(((int)substr($year,2,2)+1)%100))sf_fail(422,'Academic year invalid');
    $grade=sf_text($payload,'grade',80,1);$cap=$payload['capacity']??null;
    if(!is_int($cap)||$cap<0||$cap>2000)sf_fail(422,'Capacity must be between 0 and 2,000');
    $plans=$s['seat_plans']??[];
    $enrolled=0;
    foreach($s['leads'] as $lead)if($lead['school_id']===$sid && ($lead['status']??'')==='Enrolled' &&
        ($lead['academic_year']??'')===$year && ($lead['grade']??'')===$grade)$enrolled++;
    if($cap<$enrolled)sf_fail(422,'Cannot reduce seat capacity below already enrolled students');
    $s['seat_plans']=$plans;
    foreach($s['seat_plans'] as &$entry)if($entry['school_id']===$sid&&$entry['grade']===$grade&&$entry['academic_year']===$year){
        $entry['capacity']=$cap;$entry['updated_at']=sf_time();
        $result=$entry;unset($entry);
        sf_event($s,$sid,null,$actor,'seat_capacity_updated',$grade.' '.$year.' capacity '.$cap);
        return $result;
    }unset($entry);
    $row=['id'=>sf_id($s,'seat_plans'),'guid'=>sf_uuid(),'school_id'=>$sid,'academic_year'=>$year,
        'grade'=>$grade,'capacity'=>$cap,'updated_at'=>sf_time()];
    $s['seat_plans'][]=$row;
    sf_event($s,$sid,null,$actor,'seat_capacity_updated',$grade.' '.$year.' capacity '.$cap);
    return $row;
}
function sf_capacity_check_enrollment(array $s,array $lead):void{
    $year=(string)($lead['academic_year']??'');
    if($year==='')foreach($s['seat_plans']??[] as $p)if($p['school_id']===$lead['school_id']&&$p['grade']===$lead['grade'])sf_fail(422,'Choose an academic year before enrolling against configured seat capacity');
    foreach($s['seat_plans']??[] as $entry)if($entry['school_id']===$lead['school_id'] &&
        ($lead['academic_year']??'')===$entry['academic_year'] && ($lead['grade']??'')===$entry['grade']){
        $n=0;foreach($s['leads'] as $other)if($other['school_id']===$lead['school_id'] &&
            $other['id']!==$lead['id'] && $other['status']==='Enrolled' &&
            ($other['academic_year']??'')===$entry['academic_year'] && ($other['grade']??'')===$entry['grade'])$n++;
        if($n>=$entry['capacity'])sf_fail(409,'No seats remain in this grade and academic year; review the waitlist');
    }
}
function sf_sla_policy(array $s,int $sid):array {
    $p=$s['response_sla_settings'][(string)$sid]??[];
    return ['first_contact_minutes'=>$p['first_contact_minutes']??120];
}
function sf_sla_set_policy(array &$s,int $sid,int $actor,array $payload):array {
    $limit=$payload['first_contact_minutes']??null;
    if(!is_int($limit)||$limit<15||$limit>2880)sf_fail(422,'First-contact SLA must be 15–2880 minutes');
    $s['response_sla_settings'][(string)$sid]=['first_contact_minutes'=>$limit];
    sf_event($s,$sid,null,$actor,'first_contact_sla_changed',$limit.' minutes');
    return sf_sla_policy($s,$sid);
}
function sf_sla_summary(array $s,int $sid,array $visible):array {
    $threshold=sf_sla_policy($s,$sid)['first_contact_minutes'];
    $overdue=0;$approaching=0;$uncontacted=0;
    foreach($visible as $lead){
        if(!empty($lead['is_demo'])||!empty($lead['import_batch_guid'])||
           in_array($lead['status']??'',['Lost','Enrolled','Not Eligible','Duplicate / Invalid'],true))continue;
        if(!empty($lead['last_successful_contact_at']))continue;
        $created=strtotime((string)($lead['created_at']??''));if(!$created)continue;
        $uncontacted++;$age=(time()-$created)/60;
        if($age>=$threshold)$overdue++;
        elseif($age>=$threshold*.75)$approaching++;
    }
    return ['sla_first_contact_minutes'=>$threshold,'sla_uncontacted'=>$uncontacted,
        'sla_breached'=>$overdue,'sla_approaching'=>$approaching];
}
