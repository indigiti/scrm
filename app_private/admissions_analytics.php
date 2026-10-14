<?php
declare(strict_types=1);
/** Release C: explicit family identity and evidence-driven analytics. */
function sf_family_create_profile(array &$s,int $schoolId,string $guardian,string $phone,string $email): string {
    $s['parent_profiles']=$s['parent_profiles']??[];
    $id=sf_id($s,'parent_profiles');$guid=sf_uuid();
    $s['parent_profiles'][]=['id'=>$id,'guid'=>$guid,'school_id'=>$schoolId,'guardian'=>$guardian,
        'phone'=>$phone,'normalized_phone'=>preg_replace('/\D/','',$phone),'email'=>$email,
        'link_method'=>'new_enquiry','created_at'=>sf_time()];
    return $guid;
}
function sf_family_profile(array $s,int $schoolId,string $guid): ?array {
    foreach($s['parent_profiles']??[] as $row)if($row['school_id']===$schoolId&&$row['guid']===$guid)return $row;
    return null;
}
function sf_family_explicit_sibling(array &$s,array $parent,int $childId,int $userId):void {
    $guid=$parent['family_guid']??'';
    if(!$guid || !sf_family_profile($s,$parent['school_id'],$guid)){
        $guid=sf_family_create_profile($s,$parent['school_id'],$parent['guardian'],$parent['phone'],$parent['email']);
        foreach($s['leads'] as &$l)if($l['id']===$parent['id']){$l['family_guid']=$guid;break;}unset($l);
    }
    foreach($s['leads'] as &$row)if($row['id']===$childId && $row['school_id']===$parent['school_id']){
        $temporary=$row['family_guid']??null;
        $row['family_guid']=$guid;$row['updated_at']=sf_time();
        break;
    }unset($row);
    // Retain only parent profiles still referenced by a lead; explicit sibling does not leave orphan identities.
    if(!empty($temporary) && $temporary!==$guid){
        $stillUsed=false;
        foreach($s['leads'] as $row)if(($row['family_guid']??null)===$temporary){$stillUsed=true;break;}
        if(!$stillUsed)$s['parent_profiles']=array_values(array_filter($s['parent_profiles'],fn($p)=>$p['guid']!==$temporary));
    }
    sf_event($s,$parent['school_id'],$childId,$userId,'family_explicitly_linked','Parent profile linked to sibling');
}
function sf_admission_recommend(array &$lead,string $temperature,string $evidence,string $source):void {
    if(!in_array($temperature,SF_TEMPERATURES,true))return;
    $lead['temperature_suggestion']=$temperature;
    $lead['temperature_suggestion_reason']=$evidence;
    $lead['temperature_suggestion_source']=$source;
    $lead['temperature_suggestion_at']=sf_time();
    // Never overwrite counsellor-approved temperature or override justification.
}
function sf_stage_history_add(array &$s,array $lead,string $from,string $to,?int $actor):void {
    if($from===$to)return;
    $s['stage_history']=$s['stage_history']??[];
    $s['stage_history'][]=['id'=>sf_id($s,'stage_history'),'guid'=>sf_uuid(),
        'school_id'=>$lead['school_id'],'lead_id'=>$lead['id'],'from_stage'=>$from,
        'to_stage'=>$to,'actor_id'=>$actor,'occurred_at'=>sf_time()];
}
function sf_analytics_enrichment(array $s,array $visible,int $sid): array {
    $temperatures=array_fill_keys(SF_TEMPERATURES,0);$reasons=[];$years=[];$campaigns=[];
    $phases=[];$openApplications=0;$applicationsSubmitted=0;$visitScheduled=0;$overdueTasks=0;
    foreach($visible as $lead){
        $t=$lead['temperature']??'Unrated';if(isset($temperatures[$t]))$temperatures[$t]++;
        $phase=sf_admission_phase($lead['status']??'New');$phases[$phase]=($phases[$phase]??0)+1;
        $reason=(string)($lead['lost_reason']??'');if($reason!=='')$reasons[$reason]=($reasons[$reason]??0)+1;
        $year=(string)($lead['academic_year']??'');if($year!=='')$years[$year]=($years[$year]??0)+1;
        $campaign=(string)($lead['utm_campaign']??'');if($campaign!=='')$campaigns[$campaign]=($campaigns[$campaign]??0)+1;
    }
    $ids=array_column($visible,'id');
    foreach($s['applications']??[] as $app)if($app['school_id']===$sid&&in_array($app['lead_id'],$ids,true)){
        if(in_array($app['status'],['Submitted','Documents Pending','Under Review','Offer Issued','Admission Confirmed','Enrolled'],true))$applicationsSubmitted++;
        if(!in_array($app['status'],['Enrolled','Rejected'],true))$openApplications++;
    }
    foreach($s['visits']??[] as $v)if($v['school_id']===$sid && in_array($v['lead_id'],$ids,true) && ($v['status']??'')==='Scheduled')$visitScheduled++;
    foreach($s['tasks']??[] as $t)if($t['school_id']===$sid && in_array($t['lead_id'],$ids,true)&&sf_task_due($t)==='Overdue')$overdueTasks++;
    $total=count($visible);$enrolled=count(array_filter($visible,fn($v)=>($v['status']??'')==='Enrolled'));
    return ['temperature_counts'=>$temperatures,'by_phase'=>$phases,'lost_reasons'=>$reasons,
        'by_academic_year'=>$years,'by_campaign'=>$campaigns,'visits_scheduled'=>$visitScheduled,
        'applications_submitted'=>$applicationsSubmitted,'open_applications'=>$openApplications,
        'overdue_tasks'=>$overdueTasks,'enrolment_rate'=>$total?round($enrolled*100/$total,1):0,
        'application_conversion_rate'=>$total?round($applicationsSubmitted*100/$total,1):0];
}
