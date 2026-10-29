<?php
declare(strict_types=1);
/** v2.2: school-scoped, read-only admissions work queue. No provider sends. */
function sf_work_queue_build(array $s,int $sid,int $uid,string $role,int $limit=100,int $offset=0,?DateTimeImmutable $clock=null):array {
    $limit=min(250,max(1,$limit));$offset=min(10000,max(0,$offset));
    $clock=($clock??new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $today=$clock->format('Y-m-d');$now=$clock->getTimestamp();
    $sla=sf_sla_policy($s,$sid)['first_contact_minutes']*60;
    $visible=sf_visible_leads($s,$sid,$uid,$role);
    $byId=[];$items=[];$summary=['overdue_first_contact'=>0,'unassigned'=>0,'overdue_followup'=>0,'followup_today'=>0,'task_overdue'=>0,'visit_today'=>0,'needs_next_action'=>0];
    foreach($visible as $lead){
        if(!empty($lead['is_demo'])||in_array($lead['status']??'',['Lost','Enrolled','Not Eligible','Duplicate / Invalid'],true))continue;
        $lid=(int)$lead['id'];$byId[$lid]=$lead;
        $flags=[];
        $created=strtotime((string)($lead['created_at']??''))?:0;
        if(empty($lead['last_successful_contact_at'])&&empty($lead['import_batch_guid'])&&$created>0&&$now-$created>=$sla)$flags['overdue_first_contact']=100;
        if(empty($lead['assigned_to'])&&$role!=='Counsellor')$flags['unassigned']=95;
        $follow=(string)($lead['followup_date']??'');
        if($follow!==''&&$follow<$today)$flags['overdue_followup']=90;
        elseif($follow===$today)$flags['followup_today']=65;
        if(!$flags&&$follow===''&&$created>0&&$now-$created>=86400)$flags['needs_next_action']=30;
        if($flags)$items[$lid]=['lead_id'=>$lid,'lead_guid'=>$lead['guid']??null,'student_name'=>$lead['name']??'',
            'grade'=>$lead['grade']??'','stage'=>$lead['status']??'','owner_id'=>$lead['assigned_to']??null,
            'created_at'=>$lead['created_at']??'', 'due_date'=>$follow,'flags'=>$flags,'priority'=>max($flags)];
    }
    foreach($s['tasks']??[] as $task){
        if(($task['school_id']??null)!==$sid||($task['status']??'')!=='Open')continue;
        $lid=(int)($task['lead_id']??0);if(!isset($byId[$lid]))continue;
        $due=(string)($task['due_date']??'');
        $flag=$due!==''&&$due<$today?'task_overdue':($due===$today?'followup_today':null);
        if($flag===null)continue;
        if(!isset($items[$lid])){
            $l=$byId[$lid];$items[$lid]=['lead_id'=>$lid,'lead_guid'=>$l['guid']??null,
                'student_name'=>$l['name']??'','grade'=>$l['grade']??'','stage'=>$l['status']??'',
                'owner_id'=>$l['assigned_to']??null,'created_at'=>$l['created_at']??'',
                'due_date'=>$due,'flags'=>[],'priority'=>0];
        }
        $weight=$flag==='task_overdue'?85:65;
        $items[$lid]['flags'][$flag]=max($weight,$items[$lid]['flags'][$flag]??0);
        $items[$lid]['priority']=max($items[$lid]['priority'],$weight);
    }
    foreach($s['visits']??[] as $visit){
        if(($visit['school_id']??null)!==$sid||($visit['status']??'')!=='Scheduled')continue;
        $lid=(int)($visit['lead_id']??0);if(!isset($byId[$lid]))continue;
        $date=substr((string)($visit['date']??$visit['scheduled_at']??''),0,10);
        if($date!==$today)continue;
        if(!isset($items[$lid])){
            $l=$byId[$lid];$items[$lid]=['lead_id'=>$lid,'lead_guid'=>$l['guid']??null,
                'student_name'=>$l['name']??'','grade'=>$l['grade']??'','stage'=>$l['status']??'',
                'owner_id'=>$l['assigned_to']??null,'created_at'=>$l['created_at']??'',
                'due_date'=>$today,'flags'=>[],'priority'=>0];
        }
        $items[$lid]['flags']['visit_today']=75;
        $items[$lid]['priority']=max(75,$items[$lid]['priority']);
    }
    foreach($items as &$item){
        foreach(array_keys($item['flags']) as $flag)$summary[$flag]++;
        $item['reasons']=array_keys($item['flags']);
        unset($item['flags']);
    }unset($item);
    $rows=array_values($items);
    usort($rows,static fn($a,$b)=>($b['priority']<=>$a['priority'])?:strcmp($a['due_date']?:'9999',$b['due_date']?:'9999')?:($a['lead_id']<=>$b['lead_id']));
    return ['as_of'=>$clock->format(DATE_ATOM),'total'=>count($rows),'summary'=>$summary,'offset'=>$offset,
        'limit'=>$limit,'items'=>array_slice($rows,$offset,$limit)];
}

/** Response contains only leads visible to this authenticated school member. */
function sf_work_queue_endpoint(string $method,string $schoolRef,array $headers,string $uri,SchoolFlowStore $store):array {
    if($method!=='GET')sf_fail(405,'Work queue is read-only');
    $params=[];
    parse_str((string)(parse_url($uri,PHP_URL_QUERY)??''),$params);
    $limit=filter_var($params['limit']??100,FILTER_VALIDATE_INT);
    $offset=filter_var($params['offset']??0,FILTER_VALIDATE_INT);
    if($limit===false||$offset===false||$limit<1||$limit>250||$offset<0||$offset>10000)
        sf_fail(422,'Invalid queue pagination');
    return $store->transaction(static function(array $s)use($schoolRef,$headers,$limit,$offset):array{
        $user=sf_session($s,(string)($headers['authorization']??''));
        if(!empty($user['must_change_password']))sf_fail(403,'Change password first');
        $sid=sf_record_id($s,'schools',$schoolRef);
        $role=sf_membership($s,$sid,(int)$user['id']);
        return [200,sf_work_queue_build($s,$sid,(int)$user['id'],$role,$limit,$offset)];
    });
}
