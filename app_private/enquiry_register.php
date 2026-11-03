<?php
declare(strict_types=1);

/**
 * SchoolFlow's operational register is read-only. It uses existing leads,
 * school-scoped calls and conversation notes; nothing is copied into a second
 * reporting datastore. All filtering and pagination happen after role checks.
 */
function sf_register_filters(string $uri):array {
    $query=parse_url($uri,PHP_URL_QUERY)?:'';
    if(strlen($query)>2500)sf_fail(422,'Too many report filters');
    parse_str($query,$raw);
    $allowed=['view','page','per_page','from','to','search','grade','assigned','status',
        'outcome','due','sort','direction','source'];
    foreach($raw as $key=>$value){
        if(!in_array($key,$allowed,true)||!is_string($value)||strlen($value)>130)
            sf_fail(422,'Invalid register filter');
    }
    $v=$raw['view']??'enquiries';
    if(!in_array($v,['enquiries','history','performance'],true))sf_fail(422,'Invalid report view');
    foreach(['page','per_page'] as $field){
        if(isset($raw[$field])&&!preg_match('/^[1-9][0-9]{0,5}$/D',$raw[$field]))
            sf_fail(422,'Invalid report pagination');
    }
    $page=(int)($raw['page']??1);$size=(int)($raw['per_page']??50);
    if($size>100||$page>100000)sf_fail(422,'Report page size must be 1–100');
    $from=$raw['from']??'';$to=$raw['to']??'';
    foreach([$from,$to] as $date)if($date!=='')sf_date($date);
    if($from!==''&&$to!==''&&$from>$to)sf_fail(422,'Invalid report date range');
    $status=$raw['status']??'';
    if($status!==''&&!in_array($status,SF_STAGES,true))sf_fail(422,'Invalid enquiry stage');
    $outcome=$raw['outcome']??'';
    if($outcome!==''&&!in_array($outcome,['Answered','No Answer','Busy','Interested','Not Interested','Callback Requested','Pending'],true))
        sf_fail(422,'Invalid call outcome');
    $assigned=$raw['assigned']??'';
    if($assigned!==''&&$assigned!=='unassigned'&&!preg_match('/^[1-9][0-9]*$/D',$assigned))
        sf_fail(422,'Invalid staff filter');
    $due=$raw['due']??'';
    if(!in_array($due,['','due','today','overdue','upcoming','unscheduled'],true))sf_fail(422,'Invalid follow-up filter');
    $sort=$raw['sort']??'created';
    if(!in_array($sort,['created','name','assigned','latest','followup'],true))sf_fail(422,'Invalid report sort');
    $direction=$raw['direction']??'desc';
    if(!in_array($direction,['asc','desc'],true))sf_fail(422,'Invalid report direction');
    return [
        'view'=>$v,'page'=>$page,'per_page'=>$size,'from'=>$from,'to'=>$to,
        'search'=>trim($raw['search']??''),'grade'=>trim($raw['grade']??''),
        'assigned'=>$assigned,'status'=>$status,'outcome'=>$outcome,'due'=>$due,
        'sort'=>$sort,'direction'=>$direction,'source'=>trim($raw['source']??'')
    ];
}
function sf_register_ist_date(string $timestamp):string {
    if($timestamp==='')return '';
    try{return (new DateTimeImmutable($timestamp))
        ->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d');}
    catch(Throwable){return '';}
}
function sf_register_report(array $s,int $schoolId,int $uid,string $role,string $uri):array {
    $f=sf_register_filters($uri);
    $school=sf_find($s['schools'],$schoolId);
    if(!$school)sf_fail(404,'School not found');
    $today=sf_admission_today();
    $visible=[];
    foreach($s['leads'] as $lead){
        if((int)$lead['school_id']!==$schoolId)continue;
        if($role==='Counsellor' && (int)($lead['assigned_to']??0)!==$uid)continue;
        $visible[(int)$lead['id']]=$lead;
    }
    // Build latest call/remark indexes before applying the call-outcome filter.
    // These indexes never include another school's events or inaccessible leads.
    $lastCall=[];$lastRemark=[];$callCount=[];$callsByActor=[];$timeline=[];
    foreach($s['calls']??[] as $call){
        $lid=(int)($call['lead_id']??0);
        if((int)($call['school_id']??0)!==$schoolId||!isset($visible[$lid]))continue;
        $at=(string)($call['created_at']??'');
        if(!isset($lastCall[$lid])||$at>=$lastCall[$lid]['at'])
            $lastCall[$lid]=['at'=>$at,'outcome'=>(string)($call['outcome']??'')];
        $callCount[$lid]=($callCount[$lid]??0)+1;
        $actor=(int)($call['actor_id']??0);$callsByActor[$actor]=($callsByActor[$actor]??0)+1;
        $note=trim((string)($call['note']??''));
        $summary=$note!==''?$note:(string)($call['outcome']??'Call recorded');
        if(!isset($lastRemark[$lid])||$at>=$lastRemark[$lid]['at'])
            $lastRemark[$lid]=['at'=>$at,'text'=>$summary,'kind'=>'Call'];
        $timeline[]= ['lead_id'=>$lid,'at'=>$at,'type'=>'Call',
            'outcome'=>(string)($call['outcome']??''),'note'=>$note,
            'actor_id'=>$actor,'followup_date'=>(string)($call['followup_date']??'')];
    }
    foreach($s['activities']??[] as $activity){
        $lid=(int)($activity['lead_id']??0);
        if((int)($activity['school_id']??0)!==$schoolId||!isset($visible[$lid])||
           ($activity['action']??'')!=='note')continue;
        $at=(string)($activity['created_at']??'');
        $note=trim((string)($activity['details']??''));
        if($note==='')continue;
        if(!isset($lastRemark[$lid])||$at>=$lastRemark[$lid]['at'])
            $lastRemark[$lid]=['at'=>$at,'text'=>$note,'kind'=>'Conversation note'];
        $timeline[]=['lead_id'=>$lid,'at'=>$at,'type'=>'Conversation note',
            'outcome'=>'','note'=>$note,'actor_id'=>(int)($activity['actor_id']??0),'followup_date'=>''];
    }
    $staffNames=[];$staffOptions=[];
    foreach($s['memberships'] as $membership){
        if((int)$membership['school_id']!==$schoolId||!sf_staff_enabled($membership))continue;
        $actor=(int)$membership['user_id'];$user=sf_user($s,$actor);
        if(!$user||empty($user['active']))continue;
        $staffNames[$actor]=(string)$user['name'];
        if($role!=='Counsellor'||$actor===$uid)
            $staffOptions[]=['id'=>$actor,'name'=>(string)$user['name'],'role'=>(string)$membership['role']];
    }
    $selected=[];
    foreach($visible as $id=>$lead){
        $created=sf_register_ist_date((string)($lead['created_at']??''));
        if($f['from']!==''&&$created<$f['from'])continue;
        if($f['to']!==''&&$created>$f['to'])continue;
        if($f['grade']!==''&&strcasecmp((string)($lead['grade']??''),$f['grade'])!==0)continue;
        if($f['status']!==''&&($lead['status']??'')!==$f['status'])continue;
        $owner=(int)($lead['assigned_to']??0);
        if($f['assigned']==='unassigned'&&$owner!==0)continue;
        if($f['assigned']!==''&&$f['assigned']!=='unassigned'&&$owner!==(int)$f['assigned'])continue;
        $outcome=$lastCall[$id]['outcome']??'Pending';
        if($f['outcome']!==''&&$f['outcome']!==$outcome)continue;
        $due=(string)($lead['followup_date']??'');
        if($f['due']==='due'&&($due===''||$due>$today))continue;
        if($f['due']==='today'&&$due!==$today)continue;
        if($f['due']==='overdue'&&($due===''||$due>=$today))continue;
        if($f['due']==='upcoming'&&($due===''||$due<=$today))continue;
        if($f['due']==='unscheduled'&&$due!=='')continue;
        if($f['source']!==''&&stripos((string)($lead['utm_source']??''),$f['source'])===false&&
            stripos((string)($lead['source_type']??''),$f['source'])===false)continue;
        if($f['search']!==''){
            $haystack=implode(' ',[(string)($lead['name']??''),(string)($lead['guardian']??''),
                (string)($lead['phone']??''),(string)($lead['email']??''),
                (string)($lead['city']??''),(string)($lead['grade']??''),
                (string)($lastRemark[$id]['text']??'')]);
            if(stripos($haystack,$f['search'])===false)continue;
        }
        $selected[$id]=$lead;
    }
    $summary=['enquiries'=>count($selected),'contacted'=>0,'unassigned'=>0,
        'followups_due'=>0,'calls'=>0,'enrolled'=>0];
    $performance=[];
    foreach($selected as $lid=>$lead){
        $owner=(int)($lead['assigned_to']??0);
        $key=(string)$owner;
        if(!isset($performance[$key]))$performance[$key]=[
            'user_id'=>$owner,'name'=>$owner?($staffNames[$owner]??'Former staff'):'Unassigned',
            'assigned'=>0,'contacted'=>0,'enrolled'=>0,'followups_due'=>0,'call_attempts'=>0
        ];
        $item=&$performance[$key];
        $item['assigned']++;
        $connected=!empty($lead['last_successful_contact_at']);
        if($connected){$item['contacted']++;$summary['contacted']++;}
        if($owner===0)$summary['unassigned']++;
        if(($lead['status']??'')==='Enrolled'){$item['enrolled']++;$summary['enrolled']++;}
        if(($lead['followup_date']??'')!==''&&$lead['followup_date']<=$today&&
            !in_array((string)($lead['status']??''),['Enrolled','Lost','Not Eligible','Duplicate / Invalid'],true)){
            $item['followups_due']++;$summary['followups_due']++;
        }
        $summary['calls']+=($callCount[$lid]??0);
        unset($item);
    }
    // Call activity is by the employee who actually placed the calls, not the
    // current owner of the enquiry. Make that distinction explicit in the UI.
    foreach($timeline as $event){
        if(!isset($selected[$event['lead_id']])||$event['type']!=='Call')continue;
        $actorKey=(string)$event['actor_id'];
        if(isset($performance[$actorKey]))$performance[$actorKey]['call_attempts']++;
    }
    $rows=[];
    if($f['view']==='performance'){
        $rows=array_values($performance);
        usort($rows,static fn($a,$b)=>$b['assigned']<=>$a['assigned'] ?: strcasecmp($a['name'],$b['name']));
    }elseif($f['view']==='history'){
        foreach($timeline as $event)if(isset($selected[$event['lead_id']])){
            $lead=$selected[$event['lead_id']];
            $event['student']=(string)($lead['name']??'');
            $event['phone']=(string)($lead['phone']??'');
            $event['grade']=(string)($lead['grade']??'');
            $event['assigned_name']=($lead['assigned_to']??null)?($staffNames[(int)$lead['assigned_to']]??'Former staff'):'Unassigned';
            $event['actor_name']=$staffNames[$event['actor_id']]??'Website / former staff';
            unset($event['actor_id']);
            $rows[]=$event;
        }
        usort($rows,static fn($a,$b)=>strcmp($b['at'],$a['at']) ?: ($b['lead_id']<=>$a['lead_id']));
    }else{
        foreach($selected as $lid=>$lead)$rows[]=[
            'id'=>$lid,'guid'=>(string)($lead['guid']??''),
            'timestamp'=>(string)($lead['created_at']??''),
            'student'=>(string)($lead['name']??''),
            'guardian'=>(string)($lead['guardian']??''),
            'email'=>(string)($lead['email']??''),
            'phone'=>(string)($lead['phone']??''),
            'city'=>(string)($lead['city']??''),
            'state'=>(string)($lead['state']??''),
            'grade'=>(string)($lead['grade']??''),
            'campus'=>(string)$school['name'],
            'assigned_to'=>(int)($lead['assigned_to']??0),
            'assigned_name'=>($lead['assigned_to']??null)?($staffNames[(int)$lead['assigned_to']]??'Former staff'):'Unassigned',
            'last_remark'=>(string)($lastRemark[$lid]['text']??''),
            'last_remark_at'=>(string)($lastRemark[$lid]['at']??''),
            'last_remark_type'=>(string)($lastRemark[$lid]['kind']??''),
            'last_call_outcome'=>(string)($lastCall[$lid]['outcome']??'Pending'),
            'last_call_at'=>(string)($lastCall[$lid]['at']??''),
            'call_attempts'=>$callCount[$lid]??0,
            'followup_date'=>(string)($lead['followup_date']??''),
            'status'=>(string)($lead['status']??''),
            'source'=>(string)($lead['utm_source']??$lead['source_type']??'')
        ];
        $sortKeys=['created'=>'timestamp','name'=>'student','assigned'=>'assigned_name',
            'latest'=>'last_remark_at','followup'=>'followup_date'];
        $key=$sortKeys[$f['sort']];
        $factor=$f['direction']==='asc'?1:-1;
        usort($rows,static function($a,$b)use($key,$factor):int{
            $v=strcmp((string)$a[$key],(string)$b[$key]);
            return $factor*($v?:($a['id']<=>$b['id']));
        });
    }
    $total=count($rows);
    $offset=($f['page']-1)*$f['per_page'];
    $pageRows=array_slice($rows,$offset,$f['per_page']);
    usort($staffOptions,static fn($a,$b)=>strcasecmp($a['name'],$b['name']));
    return [
        'view'=>$f['view'],'school_id'=>$schoolId,'school_name'=>(string)$school['name'],
        'rows'=>$pageRows,'total'=>$total,'page'=>$f['page'],'per_page'=>$f['per_page'],
        'pages'=>max(1,(int)ceil($total/$f['per_page'])),'has_more'=>$offset+$f['per_page']<$total,
        'summary'=>$summary,'staff'=>$staffOptions,'filters'=>$f,'as_of'=>sf_time(),
        'scope'=>$role==='Counsellor'?'assigned_only':'school'
    ];
}
