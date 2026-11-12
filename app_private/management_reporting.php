<?php
declare(strict_types=1);

/**
 * Management Intelligence V1. Explicitly assigned, read-only school oversight.
 * No provider configuration, source keys, parent contact details, or write actions.
 * All aggregates use the complete authorized store, never the browser inbox cache.
 */
function sf_management_schools(array $s, int $uid): array {
    $user=sf_user($s,$uid);
    if(!$user || empty($user['active'])) sf_fail(403,'Account unavailable');
    $allowed=[];
    foreach($s['memberships']??[] as $m) {
        if((int)($m['user_id']??0)!==$uid || ($m['role']??'')!=='Management' || !sf_staff_enabled($m))continue;
        $sid=(int)$m['school_id'];
        $school=sf_find($s['schools'],$sid);
        if($school)$allowed[$sid]=(string)$school['name'];
    }
    if(!$allowed)sf_fail(403,'Management membership required');
    asort($allowed,SORT_NATURAL|SORT_FLAG_CASE);
    return $allowed;
}

function sf_management_filters(string $uri,array $allowed): array {
    $query=parse_url($uri,PHP_URL_QUERY)?:'';
    if(strlen($query)>2500)sf_fail(422,'Too many report filters');
    parse_str($query,$raw);
    $keys=['period','school_id','from','to','source_type','form_type','campaign','grade','assigned_to','search','page','per_page','sort'];
    foreach($raw as $k=>$v)
        if(!in_array($k,$keys,true)||!is_string($v)||strlen($v)>180)sf_fail(422,'Unsupported report filter');
    $period=$raw['period']??'month';
    if(!in_array($period,['today','week','month','year','custom'],true))sf_fail(422,'Invalid period');
    $tz=new DateTimeZone('Asia/Kolkata');
    $now=new DateTimeImmutable('now',$tz);
    $today=$now->format('Y-m-d');
    $from=match($period){
        'today'=>$today,
        'week'=>$now->modify('monday this week')->format('Y-m-d'),
        'year'=>$now->format('Y-01-01'),
        default=>$now->format('Y-m-01')
    };
    $to=$today;
    if($period==='custom' && (!isset($raw['from'])||!isset($raw['to'])))sf_fail(422,'Custom period requires both dates');
    if(isset($raw['from'])||isset($raw['to'])){
        if(!isset($raw['from'],$raw['to']))sf_fail(422,'Both report dates required');
        $from=sf_date($raw['from']);$to=sf_date($raw['to']);
    }
    if($from>$to)sf_fail(422,'Start date must not be after end date');
    $span=(new DateTimeImmutable($from,$tz))->diff(new DateTimeImmutable($to,$tz))->days;
    if($span>3660)sf_fail(422,'Maximum report range is ten years');
    $school=$raw['school_id']??'all';
    if($school!=='all'&&(!ctype_digit($school)||!isset($allowed[(int)$school])))
        sf_fail(403,'School is outside your Management assignment');
    $assigned=$raw['assigned_to']??'all';
    if($assigned!=='all'&&$assigned!=='unassigned'&&(!ctype_digit($assigned)||(int)$assigned<1))
        sf_fail(422,'Invalid counsellor filter');
    $source=$raw['source_type']??'all';
    if(!in_array($source,['all','group_website','individual_website','chatbot','manual','import','other'],true))
        sf_fail(422,'Invalid source type');
    $form=$raw['form_type']??'all';
    if(!in_array($form,['all','inline','popup','api','chat','manual','import','unknown'],true))
        sf_fail(422,'Invalid form type');
    foreach(['page','per_page'] as $key)if(isset($raw[$key])&&!preg_match('/^[1-9]\d{0,5}$/D',$raw[$key]))
        sf_fail(422,'Invalid pagination');
    $page=(int)($raw['page']??1);$per=(int)($raw['per_page']??30);
    if($per>100||$page>100000)sf_fail(422,'Pagination exceeds limit');
    $sort=$raw['sort']??'newest';
    if(!in_array($sort,['newest','oldest','followup','name'],true))sf_fail(422,'Invalid sort');
    return ['period'=>$period,'from'=>$from,'to'=>$to,'today'=>$today,'school_id'=>$school,
        'source_type'=>$source,'form_type'=>$form,'campaign'=>trim($raw['campaign']??''),
        'grade'=>trim($raw['grade']??''),'assigned_to'=>$assigned,
        'search'=>trim($raw['search']??''),'page'=>$page,'per_page'=>$per,'sort'=>$sort,
        'month_buckets'=>$span>100];
}

/** All non-date filters. Calls are filtered by call date independently of lead creation date. */
function sf_management_context(array $lead,array $f,array $s,array $allowed):bool {
    $sid=(int)($lead['school_id']??0);
    if(!isset($allowed[$sid])||($f['school_id']!=='all'&&$sid!==(int)$f['school_id']))return false;
    if(!empty($lead['is_demo']))return false;
    if($f['source_type']!=='all' && sf_lead_origin($lead,
        !empty($lead['site_id'])?sf_source($s,(int)$lead['site_id']):null)!==$f['source_type'])return false;
    if($f['form_type']!=='all'&&($lead['form_type']??'unknown')!==$f['form_type'])return false;
    if($f['campaign']!==''&&strcasecmp((string)($lead['utm_campaign']??''),$f['campaign'])!==0)return false;
    if($f['grade']!==''&&strcasecmp((string)($lead['grade']??''),$f['grade'])!==0)return false;
    $owner=(int)($lead['assigned_to']??0);
    if($f['assigned_to']==='unassigned'&&$owner!==0)return false;
    if($f['assigned_to']!=='all'&&$f['assigned_to']!=='unassigned'&&$owner!==(int)$f['assigned_to'])return false;
    return true;
}
function sf_management_cohort_date(array $lead,array $f):bool {
    $date=sf_register_ist_date((string)($lead['created_at']??''));
    return $date!==''&&$date>=$f['from']&&$date<=$f['to'];
}
function sf_management_is_closed(array $lead):bool {
    return in_array((string)($lead['status']??''),['Enrolled','Admission Confirmed','Lost','Not Eligible','Duplicate / Invalid'],true);
}
function sf_management_is_confirmed(array $lead):bool {
    return in_array((string)($lead['status']??''),['Enrolled','Admission Confirmed'],true);
}
function sf_management_mask_phone(string $phone):string {
    $digits=preg_replace('/\D/','',$phone);
    return strlen($digits)>=4?'•••• '.substr($digits,-4):'Not available';
}
function sf_management_lead_row(array $s,array $l,array $allowed):array {
    $site=!empty($l['site_id'])?sf_source($s,(int)$l['site_id']):null;
    $staff=!empty($l['assigned_to'])?sf_user($s,(int)$l['assigned_to']):null;
    return ['id'=>(int)$l['id'],'guid'=>(string)($l['guid']??''),
        'school_id'=>(int)$l['school_id'],'school'=>$allowed[(int)$l['school_id']],
        'student'=>(string)($l['name']??''),'grade'=>(string)($l['grade']??''),
        'phone_masked'=>sf_management_mask_phone((string)($l['phone']??'')),
        'assigned'=>(string)($staff['name']??'Unassigned'),
        'status'=>(string)($l['status']??''),
        'temperature'=>(string)($l['temperature']??'Unrated'),
        'disqualified'=>(string)($l['status']??'')==='Not Eligible',
        'last_call_outcome'=>(string)($l['last_call_outcome']??'Pending'),
        'followup_date'=>(string)($l['followup_date']??''),
        'source_type'=>sf_lead_origin($l,$site),
        'source_name'=>(string)($site['name']??'Manual entry'),
        'form_type'=>(string)($l['form_type']??'unknown'),
        'utm_campaign'=>(string)($l['utm_campaign']??''),
        'landing_page'=>(string)($l['landing_page']??''),
        'created_at'=>(string)($l['created_at']??'')];
}
function sf_management_group_top(array $groups,int $limit=12):array {
    arsort($groups,SORT_NUMERIC);
    $rows=[];
    foreach(array_slice($groups,0,$limit,true) as $key=>$count)$rows[]=['name'=>(string)$key,'count'=>$count];
    return $rows;
}
function sf_management_summary(array $s,array $allowed,array $f):array {
    $tz=new DateTimeZone('Asia/Kolkata');
    $now=new DateTimeImmutable('now',$tz);
    $today=$f['today'];
    $schoolRows=[];
    foreach($allowed as $id=>$name)if($f['school_id']==='all'||$id===(int)$f['school_id'])
        $schoolRows[$id]=['id'=>$id,'name'=>$name,'enquiries'=>0,'confirmed'=>0,'enrolled'=>0,
            'hot'=>0,'contacted'=>0,'unassigned'=>0,'overdue'=>0,'sla_breached'=>0];
    $summary=['enquiries'=>0,'confirmed'=>0,'enrolled'=>0,'hot'=>0,'warm'=>0,'cold'=>0,
        'unrated'=>0,'disqualified'=>0,'contacted'=>0,'unassigned'=>0,'potential_duplicates'=>0,
        'overdue'=>0,'due_today'=>0,'due_next_7_days'=>0,'unscheduled'=>0,'sla_breached'=>0,
        'calls'=>0,'connected_calls'=>0,'unique_called_leads'=>0,'first_contact_within_sla'=>0];
    $sources=[];$forms=[];$campaigns=[];$pages=[];$stages=[];$reasons=[];
    $trend=[];$staff=[];$callOutcomes=array_fill_keys(['Answered','No Answer','Busy','Interested','Not Interested','Callback Requested'],0);
    $context=[];$cohort=[];$risk=[];
    foreach($s['leads']??[] as $lead) {
        if(!sf_management_context($lead,$f,$s,$allowed))continue;
        $id=(int)$lead['id'];$sid=(int)$lead['school_id'];$context[$id]=$lead;
        $closed=sf_management_is_closed($lead);
        $follow=(string)($lead['followup_date']??'');
        if(!$closed) {
            if($follow!==''&&$follow<$today){$summary['overdue']++;$schoolRows[$sid]['overdue']++;}
            if($follow===$today)$summary['due_today']++;
            if($follow>$today&&$follow<=$now->modify('+7 days')->format('Y-m-d'))$summary['due_next_7_days']++;
            if($follow==='')$summary['unscheduled']++;
            if(empty($lead['last_successful_contact_at'])&&!empty($lead['created_at'])&&empty($lead['import_batch_guid'])){
                $target=sf_sla_policy($s,$sid)['first_contact_minutes']??120;
                if(time()-strtotime((string)$lead['created_at'])>$target*60) {
                    $summary['sla_breached']++;$schoolRows[$sid]['sla_breached']++;
                }
            }
            if($follow<$today&&$follow!=='')$risk[]=['kind'=>'Overdue follow-up','lead_id'=>$id,'school_id'=>$sid,
                'student'=>(string)($lead['name']??''),'school'=>$allowed[$sid],'date'=>$follow,'priority'=>1];
            elseif(($lead['temperature']??'')==='Hot'&&$follow==='')$risk[]=['kind'=>'Hot lead · no next follow-up','lead_id'=>$id,'school_id'=>$sid,
                'student'=>(string)($lead['name']??''),'school'=>$allowed[$sid],'date'=>'','priority'=>2];
        }
        if(!sf_management_cohort_date($lead,$f))continue;
        $cohort[$id]=$lead;
        $summary['enquiries']++;$schoolRows[$sid]['enquiries']++;
        $confirmed=sf_management_is_confirmed($lead);
        if($confirmed){$summary['confirmed']++;$schoolRows[$sid]['confirmed']++;}
        if(($lead['status']??'')==='Enrolled'){$summary['enrolled']++;$schoolRows[$sid]['enrolled']++;}
        $temp=(string)($lead['temperature']??'Unrated');
        if(!in_array($temp,['Hot','Warm','Cold'],true))$temp='Unrated';
        $summary[strtolower($temp)]++;
        if($temp==='Hot')$schoolRows[$sid]['hot']++;
        if(($lead['status']??'')==='Not Eligible'){$summary['disqualified']++;
            $reason=(string)($lead['disqualification_reason']??'Unknown');
            $reasons[$reason]=($reasons[$reason]??0)+1;}
        $status=(string)($lead['status']??'Unknown');$stages[$status]=($stages[$status]??0)+1;
        if(!empty($lead['last_successful_contact_at'])) {
            $summary['contacted']++;$schoolRows[$sid]['contacted']++;
            $first=strtotime((string)$lead['last_successful_contact_at']);
            $start=strtotime((string)($lead['created_at']??''));
            $limit=sf_sla_policy($s,$sid)['first_contact_minutes']??120;
            if($first!==false&&$start!==false&&$first>=$start&&$first-$start<=$limit*60)$summary['first_contact_within_sla']++;
        }
        if(empty($lead['assigned_to'])){$summary['unassigned']++;$schoolRows[$sid]['unassigned']++;}
        if(!empty($lead['duplicate_of']))$summary['potential_duplicates']++;
        $site=!empty($lead['site_id'])?sf_source($s,(int)$lead['site_id']):null;
        $source=sf_lead_origin($lead,$site);
        $forms[(string)($lead['form_type']??'unknown')]=($forms[(string)($lead['form_type']??'unknown')]??0)+1;
        $sources[$source]=($sources[$source]??0)+1;
        $campaign=trim((string)($lead['utm_campaign']??''))?:'Unattributed';
        $campaigns[$campaign]=($campaigns[$campaign]??0)+1;
        $page=trim((string)($lead['landing_page']??''))?:'Unattributed';
        $pages[$page]=($pages[$page]??0)+1;
        $date=sf_register_ist_date((string)($lead['created_at']??''));
        $bucket=$f['month_buckets']?substr($date,0,7):$date;
        $trend[$bucket]=($trend[$bucket]??0)+1;
        $uid=(int)($lead['assigned_to']??0);
        if(!isset($staff[$uid]))$staff[$uid]=['name'=>$uid?(string)(sf_user($s,$uid)['name']??'Former staff'):'Unassigned',
            'assigned'=>0,'contacted'=>0,'confirmed'=>0,'calls_placed'=>0];
        $staff[$uid]['assigned']++;
        $staff[$uid]['contacted']+=(int)!empty($lead['last_successful_contact_at']);
        $staff[$uid]['confirmed']+=(int)$confirmed;
    }
    $calledIds=[];
    foreach($s['calls']??[] as $call){
        $id=(int)($call['lead_id']??0);
        if(!isset($context[$id]))continue;
        $date=sf_register_ist_date((string)($call['created_at']??''));
        if($date===''||$date<$f['from']||$date>$f['to'])continue;
        $summary['calls']++;$calledIds[$id]=true;
        $outcome=(string)($call['outcome']??'Other');
        $callOutcomes[$outcome]=($callOutcomes[$outcome]??0)+1;
        if(in_array($outcome,['Answered','Interested','Not Interested','Callback Requested'],true))
            $summary['connected_calls']++;
        $actor=(int)($call['actor_id']??0);
        if(!isset($staff[$actor]))$staff[$actor]=['name'=>$actor?(string)(sf_user($s,$actor)['name']??'Former staff'):'System',
            'assigned'=>0,'contacted'=>0,'confirmed'=>0,'calls_placed'=>0];
        $staff[$actor]['calls_placed']++;
    }
    $summary['unique_called_leads']=count($calledIds);
    ksort($trend);
    usort($risk,static fn($a,$b)=>($a['priority']<=>$b['priority'])?:strcmp($a['date'],$b['date']));
    $schools=array_values($schoolRows);
    usort($schools,static fn($a,$b)=>($b['enquiries']<=>$a['enquiries'])?:strcmp($a['name'],$b['name']));
    $staffRows=array_values($staff);
    usort($staffRows,static fn($a,$b)=>($b['assigned']<=>$a['assigned'])?:($b['calls_placed']<=>$a['calls_placed']));
    $recent=array_values($cohort);
    usort($recent,static fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
    $leads=array_map(static fn($l)=>sf_management_lead_row($s,$l,$allowed),array_slice($recent,0,8));
    return [
        'as_of'=>sf_time(),'filters'=>$f,'schools'=>array_map(static fn($id,$name)=>['id'=>$id,'name'=>$name],array_keys($allowed),array_values($allowed)),
        'summary'=>$summary,'school_performance'=>$schools,
        'trend'=>array_map(static fn($date,$count)=>['date'=>$date,'count'=>$count],array_keys($trend),array_values($trend)),
        'call_outcomes'=>sf_management_group_top($callOutcomes),
        'sources'=>sf_management_group_top($sources),'forms'=>sf_management_group_top($forms),
        'campaigns'=>sf_management_group_top($campaigns),'landing_pages'=>sf_management_group_top($pages),
        'stages'=>sf_management_group_top($stages,25),
        'disqualification_reasons'=>sf_management_group_top($reasons),
        'staff'=>array_slice($staffRows,0,30),'attention'=>array_slice($risk,0,12),'recent_leads'=>$leads,
        'definitions'=>[
            'cohort'=>'Enquiry totals are grouped by their creation date in Asia/Kolkata; demo leads excluded.',
            'calls'=>'Call outcomes count events performed during the period across all matching school enquiries.',
            'backlog'=>'Due and overdue follow-ups reflect the current open-lead backlog regardless of creation date.',
            'conversion'=>'Confirmed includes current Admission Confirmed and Enrolled stages; not historical point-in-time conversions.',
            'temperature'=>'Temperature is Hot/Warm/Cold/Unrated. Disqualified is a separate Not Eligible disposition.',
            'attribution'=>'Campaign and landing-page labels are saved submission metadata, not independently verified ad conversions.'
        ]
    ];
}
function sf_management_leads(array $s,array $allowed,array $f):array {
    $rows=[];
    foreach($s['leads']??[] as $lead){
        if(!sf_management_context($lead,$f,$s,$allowed)||!sf_management_cohort_date($lead,$f))continue;
        if($f['search']!==''){
            $haystack=implode(' ',[(string)($lead['name']??''),(string)($lead['grade']??''),
                (string)($lead['utm_campaign']??''),(string)($lead['status']??'')]);
            if(stripos($haystack,$f['search'])===false)continue;
        }
        $rows[]=$lead;
    }
    $sort=$f['sort'];
    $field=match($sort){'followup'=>'followup_date','name'=>'name',default=>'created_at'};
    usort($rows,static function($a,$b)use($field,$sort){
        $diff=strcmp((string)($a[$field]??''),(string)($b[$field]??''));
        if($diff===0)$diff=(int)$a['id']<=>(int)$b['id'];
        return $sort==='oldest'||$sort==='name'||$sort==='followup'?$diff:-$diff;
    });
    $count=count($rows);$offset=($f['page']-1)*$f['per_page'];
    return ['rows'=>array_map(static fn($l)=>sf_management_lead_row($s,$l,$allowed),array_slice($rows,$offset,$f['per_page'])),
        'total'=>$count,'page'=>$f['page'],'pages'=>max(1,(int)ceil($count/$f['per_page'])),'per_page'=>$f['per_page'],
        'filters'=>$f,'as_of'=>sf_time()];
}
function sf_management_lead_detail(array $s,array $allowed,int $uid,int $leadId):array {
    $lead=sf_find($s['leads'],$leadId);
    if(!$lead||!isset($allowed[(int)$lead['school_id']]))sf_fail(404,'Lead unavailable');
    $result=sf_management_lead_row($s,$lead,$allowed);
    $result['guardian']=(string)($lead['guardian']??'');
    $result['city']=(string)($lead['city']??'');
    $result['state']=(string)($lead['state']??'');
    $result['contact_state']=(string)($lead['contact_state']??'Pending Contact');
    $result['disqualification_reason']=(string)($lead['disqualification_reason']??'');
    $result['last_successful_contact_at']=(string)($lead['last_successful_contact_at']??'');
    $result['academic_year']=(string)($lead['academic_year']??'');
    $result['history']=[];
    foreach($s['calls']??[] as $call)
        if((int)($call['lead_id']??0)===$leadId&&(int)($call['school_id']??0)===(int)$lead['school_id']){
            $staff=sf_user($s,(int)($call['actor_id']??0));
            $result['history'][]=['at'=>(string)($call['created_at']??''),
                'outcome'=>(string)($call['outcome']??''),
                'note'=>(string)($call['note']??''),
                'by'=>(string)($staff['name']??'Former staff')];
        }
    usort($result['history'],static fn($a,$b)=>strcmp($b['at'],$a['at']));
    $result['history']=array_slice($result['history'],0,20);
    return $result;
}
function sf_management_handle(array $s,int $uid,string $path,string $uri,string $method):array {
    if($method!=='GET')sf_fail(405,'Management access is read-only');
    $allowed=sf_management_schools($s,$uid);
    if(preg_match('~^/api/management/leads/([1-9][0-9]*)$~D',$path,$m))
        return [200,sf_management_lead_detail($s,$allowed,$uid,(int)$m[1])];
    if(!in_array($path,['/api/management/overview','/api/management/leads'],true))
        sf_fail(404,'Unknown Management report');
    $filters=sf_management_filters($uri,$allowed);
    return [200,$path==='/api/management/leads'
        ?sf_management_leads($s,$allowed,$filters):sf_management_summary($s,$allowed,$filters)];
}
