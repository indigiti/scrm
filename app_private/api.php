<?php
declare(strict_types=1);
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/messaging.php';
require_once __DIR__ . '/public_forms.php';
require_once __DIR__ . '/public_chat_knowledge.php';
require_once __DIR__ . '/public_chat_callback.php';
require_once __DIR__ . '/public_chat_session.php';
require_once __DIR__ . '/public_chat_returning.php';
require_once __DIR__ . '/public_chat_provider.php';
require_once __DIR__ . '/public_chat.php';
require_once __DIR__ . '/public_counsellor.php';
require_once __DIR__ . '/public_chat_sources.php';
require_once __DIR__ . '/public_chat_knowledge_files.php';
require_once __DIR__ . '/admission_workflow.php';
require_once __DIR__ . '/admissions_tasks.php';
require_once __DIR__ . '/admissions_analytics.php';
require_once __DIR__ . '/admissions_automation.php';
require_once __DIR__ . '/export_xlsx.php';
require_once __DIR__ . '/import_enquiries.php';
require_once __DIR__ . '/admissions_operations.php';
require_once __DIR__ . '/staff_management.php';
require_once __DIR__ . '/staging_web_reset.php';
require_once __DIR__ . '/work_queue.php';
require_once __DIR__ . '/ai_quality.php';
require_once __DIR__ . '/provider_webhooks.php';

const SF_STAGES = ['New','Contacted','Counselling','Visit Scheduled','Applied','Enrolled','Lost','New Enquiry','Contact Attempted','Contact Established','Visit Planned','Visit Completed','Application Started','Application Submitted','Documents Pending','Under Review','Offer Issued','Admission Confirmed','On Hold / Deferred','Waitlisted','Not Eligible','Duplicate / Invalid'];
const SF_ROLES = ['Admin','Admissions','Counsellor'];
const SF_TTL = 43200;

class SFError extends RuntimeException {
    public function __construct(public int $httpStatus, string $message) { parent::__construct($message); }
}
function sf_fail(int $status, string $message): never { throw new SFError($status, $message); }
function sf_time(): string { return gmdate('Y-m-d\TH:i:s+00:00'); }
function sf_hash(string $s): string { return hash('sha256', $s); }
function sf_uuid(): string { return SchoolFlowStore::guid(); }
/** GUID public reference with legacy numeric identifier support during migration. */
function sf_record_id(array $s,string $table,int|string $reference,?int $schoolId=null): int {
    if(is_int($reference)||(is_string($reference)&&ctype_digit($reference))){
        $id=(int)$reference;
    }else{
        if(!is_string($reference)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$reference))sf_fail(404,'Record not found');
        $id=0;
        foreach($s[$table]??[] as $row)if(($row['guid']??null)===$reference){$id=$row['id']??0;break;}
    }
    if(!$id)sf_fail(404,'Record not found');
    if($schoolId!==null){
        $row=sf_find($s[$table]??[],$id);
        if(!$row||($row['school_id']??null)!==$schoolId)sf_fail(404,'Record not found');
    }
    return $id;
}

function sf_id(array &$s, string $table): int {
    $s['counters'][$table] = ($s['counters'][$table] ?? 0) + 1;
    return $s['counters'][$table];
}
function sf_text(array $data, string $field, int $max, int $min = 0): string {
    $v = $data[$field] ?? '';
    if (!is_string($v) && !is_numeric($v)) sf_fail(422, "$field must be a string");
    $v = trim((string)$v);
    if (strlen($v) < $min || strlen($v) > $max) sf_fail(422, "$field must contain $min to $max bytes");
    return $v;
}
function sf_email(string $email): string {
    $email = strtolower(trim($email));
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) sf_fail(422, 'Valid email required');
    return $email;
}
function sf_date(mixed $d): string {
    if ($d === null || $d === '') return '';
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d)) sf_fail(422,'Date must be YYYY-MM-DD');
    [$y,$m,$day] = array_map('intval', explode('-', $d));
    if (!checkdate($m, $day, $y)) sf_fail(422,'Date must be valid');
    return $d;
}
function sf_phone(string $phone): string {
    if (strlen(preg_replace('/\D/', '', $phone)) < 7) sf_fail(422, 'Phone number is too short');
    return $phone;
}
function sf_find(array $rows, int $id): ?array {
    foreach ($rows as $r) if (($r['id'] ?? null) === $id) return $r;
    return null;
}
function sf_membership(array $s, int $schoolId, int $userId, array $roles = []): string {
    foreach ($s['memberships'] as $m) if ($m['school_id'] === $schoolId && $m['user_id'] === $userId) {
        if(!sf_staff_enabled($m) || !(sf_user($s,$userId)['active']??false))sf_fail(403,'This school account is disabled');
        if ($roles && !in_array($m['role'], $roles, true)) sf_fail(403, 'Your role does not permit this action');
        return $m['role'];
    }
    sf_fail(403, 'You are not a member of this school');
}
function sf_user(array $s, int $id): ?array { return sf_find($s['users'], $id); }
function sf_source(array $s, int $id): ?array { return sf_find($s['sites'], $id); }

/** A group website owns several server-approved campus routes; its key never enters the browser. */
function sf_group_site_by_key(array $s, string $authorization): ?array {
    if (!str_starts_with($authorization, 'Bearer sfgroup_')) return null;
    $hash=sf_hash(substr($authorization,7));
    foreach ($s['group_sites']??[] as $group) if (!empty($group['enabled']) && hash_equals($group['key_hash'],$hash)) return $group;
    return null;
}
function sf_group_campuses(array $s, array $group): array {
    $out=[];
    foreach ($group['routes'] as $route) {
        $school=sf_find($s['schools'], (int)$route['school_id']);
        $site=sf_source($s,(int)$route['site_id']);
        if (!$school || !$site || empty($site['enabled'])) continue;
        // An owner who has lost school-admin membership cannot keep routing data there.
        $authorized=false;
        foreach ($s['memberships'] as $m) if ($m['school_id']===$school['id'] && $m['user_id']===$group['owner_id'] && $m['role']==='Admin' && sf_staff_enabled($m) && (sf_user($s,$m['user_id'])['active']??false)) {$authorized=true;break;}
        if($authorized) $out[]=['code'=>$route['code'],'name'=>$school['name'],'school_id'=>$school['id'],'site_id'=>$site['id']];
    }
    return $out;
}
function sf_group_select_schools(array &$s, int $uid, int $groupId, string $name, string $domain, mixed $selected, array $previous=[]): array {
    if (!is_array($selected) || !$selected || !array_is_list($selected) || count($selected)>60) sf_fail(422,'Select between 1 and 60 school campuses');
    $ids=[];
    foreach($selected as $item) {
        if(!is_int($item) && (!is_string($item)||!ctype_digit($item))) sf_fail(422,'Invalid school selection');
        $sid=(int)$item;
        if($sid<1 || isset($ids[$sid])) sf_fail(422,'School selection contains invalid or duplicate IDs');
        sf_membership($s,$sid,$uid,['Admin']);$ids[$sid]=true;
    }
    $routes=[];
    foreach(array_keys($ids) as $sid) {
        $source=null;
        foreach($previous as $old) if((int)$old['school_id']===$sid) {$source=(int)$old['site_id'];break;}
        if($source===null){
            $source=sf_id($s,'sites');
            $s['sites'][]=['id'=>$source,'school_id'=>$sid,'name'=>$name.' (Group website)','domain'=>$domain,
                'platform'=>'Group website','key_hash'=>sf_hash(bin2hex(random_bytes(32))),
                'enabled'=>1,'group_site_id'=>$groupId,'is_group_route'=>true,'created_at'=>sf_time()];
        }
        $routes[]=['code'=>'campus-'.$sid,'school_id'=>$sid,'site_id'=>$source];
        foreach($s['sites'] as &$site) if($site['id']===$source){$site['enabled']=1;$site['name']=$name.' (Group website)';$site['domain']=$domain;}
        unset($site);
    }
    foreach($previous as $old) if(!isset($ids[(int)$old['school_id']])) {
        foreach($s['sites'] as &$site) if($site['id']===$old['site_id']) $site['enabled']=0;
        unset($site);
    }
    return $routes;
}
function sf_group_public_view(array $s, array $group): array {
    return ['id'=>$group['id'],'guid'=>$group['guid']??'', 'name'=>$group['name'],'domain'=>$group['domain'],'enabled'=>(bool)$group['enabled'],
        'schools'=>array_map(static fn($r)=>['code'=>$r['code'],'school_id'=>$r['school_id'],
            'name'=>(sf_find($s['schools'],(int)$r['school_id'])['name']??'Unknown school')], $group['routes'])];
}

function sf_session(array $s, string $authorization): array {
    if (!str_starts_with($authorization, 'Bearer ')) sf_fail(401, 'Sign in required');
    $hash = sf_hash(substr($authorization, 7));
    foreach ($s['sessions'] as $ses) if (hash_equals($ses['token_hash'], $hash) && $ses['expires_at'] > time()) {
        $user = sf_user($s, $ses['user_id']);
        if ($user && $user['active']) return $user;
    }
    sf_fail(401, 'Session expired; sign in again');
}
function sf_limit(array &$s, string $key, int $max, int $seconds): void {
    $now = time();
    $times = array_values(array_filter($s['rate_limits'][$key] ?? [], fn($t) => $t > $now - $seconds));
    if (count($times) >= $max) sf_fail(429, 'Too many requests. Try again later.');
    $times[] = $now;
    $s['rate_limits'][$key] = $times;
    if (count($s['rate_limits']) > 4000) $s['rate_limits'] = array_slice($s['rate_limits'], -1000, null, true);
}
/** Append-only business audit: every record is committed with the same locked JSON transaction.
 * Records contain minimal descriptions (never passwords, notes, bearer credentials, or private keys).
 * Older installations have 'activities' but not 'audit_logs': import those once on first audited write.
 */
function sf_event(array &$s, int $schoolId, ?int $leadId, ?int $actorId, string $action, string $details = ''): void {
    if (!isset($s['audit_logs'])) {
        $s['audit_logs'] = [];
        foreach ($s['activities'] ?? [] as $old) {
            $actor = isset($old['actor_id']) && $old['actor_id'] !== null ? sf_user($s, (int)$old['actor_id']) : null;
            $s['audit_logs'][] = [
                'id'=>sf_id($s,'audit_logs'), 'school_id'=>$old['school_id'],
                'lead_id'=>$old['lead_id']??null, 'actor_id'=>$old['actor_id']??null,
                'actor_name'=>$actor['name']??'Website / system', 'action'=>$old['action'],
                'details'=>$old['action']==='note'?'Conversation note added':substr((string)($old['details']??''),0,220),
                'created_at'=>$old['created_at'],
            ];
        }
    }
    $actor = $actorId !== null ? sf_user($s,$actorId) : null;
    $s['audit_logs'][] = [
        'id'=>sf_id($s,'audit_logs'), 'school_id'=>$schoolId,'lead_id'=>$leadId,
        'actor_id'=>$actorId, 'actor_name'=>$actor['name']??'Website / system',
        'action'=>$action, 'details'=>substr($details,0,220), 'created_at'=>sf_time(),
    ];
}
function sf_audit(array &$s, int $schoolId, ?int $leadId, ?int $actorId, string $action, string $details = ''): void {
    $s['activities'][] = ['id'=>sf_id($s,'activities'), 'school_id'=>$schoolId,'lead_id'=>$leadId,
        'actor_id'=>$actorId,'action'=>$action,'details'=>substr($details,0,2000),'created_at'=>sf_time()];
    sf_event($s,$schoolId,$leadId,$actorId,$action,$action==='note'?'Conversation note added':$details);
}
/** Newest-first cursor-based audit page. Limits protect the JSON-backed web app. */
function sf_audit_page(array $s, int $schoolId, ?int $leadId, ?int $before, int $limit=50): array {
    $limit=max(1,min(100,$limit));$rows=[];
    $logs=$s['audit_logs'] ?? null;
    if ($logs === null) {
        // Read-only view of history from installations that predate the audit-log feature.
        // The same IDs are persisted when the first audited write imports this history.
        $logs=[];$oldId=(int)($s['counters']['audit_logs']??0);
        foreach ($s['activities'] ?? [] as $activity) {
            $actor=isset($activity['actor_id']) && $activity['actor_id']!==null?sf_user($s,(int)$activity['actor_id']):null;
            $logs[]=['id'=>++$oldId,'school_id'=>$activity['school_id'],'lead_id'=>$activity['lead_id']??null,
                'actor_id'=>$activity['actor_id']??null,'actor_name'=>$actor['name']??'Website / system',
                'action'=>$activity['action'],'details'=>$activity['action']==='note'?'Conversation note added':substr((string)($activity['details']??''),0,220),
                'created_at'=>$activity['created_at']];
        }
    }
    foreach (array_reverse($logs) as $entry) {
        if ($entry['school_id'] !== $schoolId) continue;
        if ($leadId !== null && ($entry['lead_id']??null) !== $leadId) continue;
        if ($before !== null && $entry['id'] >= $before) continue;
        $rows[]=$entry;
        if (count($rows)>$limit) break;
    }
    $hasMore=count($rows)>$limit;
    if ($hasMore) array_pop($rows);
    return ['events'=>$rows,'has_more'=>$hasMore,'next_before'=>$hasMore ? end($rows)['id'] : null];
}
function sf_audit_cursor(string $uri): ?int {
    $query=(string)(parse_url($uri,PHP_URL_QUERY) ?: '');parse_str($query,$args);
    if (!isset($args['before'])) return null;
    if (!is_scalar($args['before']) || !ctype_digit((string)$args['before']) || (int)$args['before']<=0) sf_fail(422,'Invalid audit cursor');
    return (int)$args['before'];
}
function sf_lead_view(array $s, array $lead): array {
    $source = $lead['site_id'] ? sf_source($s, $lead['site_id']) : null;
    $owner = $lead['assigned_to'] ? sf_user($s, $lead['assigned_to']) : null;
    // Preserve original campus/site routing while identifying chatbot leads
    // separately, including enquiries created before the channel field existed.
    $lead['chatbot_lead'] = ($lead['lead_channel']??'')==='chatbot'
        || in_array((string)($lead['utm_source']??''),['admissions_guided_chat','public_admissions_chat'],true);
    $lead['source_name'] = $lead['chatbot_lead']
        ? 'Chatbot · '.($source['name']??'Admissions')
        : ($source['name']??null);
    $lead['source_domain'] = $source['domain'] ?? null;
    $lead['assigned_name'] = $owner['name'] ?? null;
    $lead['last_contact_at'] = $lead['last_contact_at'] ?? '';
    $lead['last_call_outcome'] = $lead['last_call_outcome'] ?? '';
    $lead=sf_admission_view($lead);
    $lead['admission_phase']=sf_admission_phase((string)($lead['status']??'New'));
    return $lead;
}
function sf_lead_allowed(array $s, int $schoolId, int $leadId, int $userId, string $role): array {
    $lead = sf_find($s['leads'], $leadId);
    if (!$lead || $lead['school_id'] !== $schoolId) sf_fail(404,'Enquiry not found');
    if ($role === 'Counsellor' && $lead['assigned_to'] !== $userId) sf_fail(403,'You can only access assigned enquiries');
    return $lead;
}
function sf_validate_assignment(array $s, int $schoolId, mixed $id): ?int {
    if ($id === null || $id === '') return null;
    if (filter_var($id, FILTER_VALIDATE_INT) === false) sf_fail(422,'Invalid staff ID');
    $id = (int)$id;
    sf_membership($s, $schoolId, $id);
    return $id;
}
function sf_auto_owner(array $s, int $schoolId): ?int {
    $loads = [];
    foreach ($s['memberships'] as $m) if ($m['school_id'] === $schoolId && $m['role'] === 'Counsellor' && sf_staff_enabled($m) && (sf_user($s,$m['user_id'])['active']??false)) $loads[$m['user_id']] = 0;
    foreach ($s['leads'] as $lead) if ($lead['school_id'] === $schoolId && isset($loads[$lead['assigned_to'] ?? -1]) && !in_array($lead['status'], ['Enrolled','Lost'], true)) $loads[$lead['assigned_to']]++;
    if (!$loads) return null;
    asort($loads, SORT_NUMERIC);
    return (int)array_key_first($loads);
}
/** Assignment can be configured per school; default remains the prior least-loaded policy. */
function sf_assignment_owner(array &$s, int $schoolId): ?int {
    $policy = $s['assignment_modes'][(string)$schoolId] ?? 'least_loaded';
    if ($policy === 'manual') return null;
    if ($policy !== 'round_robin') return sf_auto_owner($s,$schoolId);
    $people=[];
    foreach ($s['memberships'] as $m) if ($m['school_id']===$schoolId && $m['role']==='Counsellor' && sf_staff_enabled($m)) {
        $user=sf_user($s,$m['user_id']);if ($user && $user['active']) $people[]=$user['id'];
    }
    sort($people,SORT_NUMERIC);
    if (!$people) return null;
    $last=(int)($s['assignment_cursors'][(string)$schoolId]??0);
    $next=$people[0];
    foreach($people as $id) if($id>$last){$next=$id;break;}
    $s['assignment_cursors'][(string)$schoolId]=$next;
    return $next;
}
function sf_visible_leads(array $s,int $schoolId,int $uid,string $role): array {
    return array_values(array_filter($s['leads'],fn($l)=>$l['school_id']===$schoolId&&($role!=='Counsellor'||$l['assigned_to']===$uid)));
}
function sf_valid_datetime(string $v): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d$/D',$v)) sf_fail(422,'Use YYYY-MM-DDTHH:MM local time');
    sf_date(substr($v,0,10));return $v;
}
function sf_parent_key(array $l): string {return !empty($l['family_guid'])?'G:'.$l['family_guid']:'P:'.(string)($l['normalized_phone']??preg_replace('/\D/','',(string)($l['phone']??'')));}
function sf_family_view(array $s,int $schoolId,array $lead,int $uid,string $role): array {
    $key=sf_parent_key($lead);$children=[];
    foreach(sf_visible_leads($s,$schoolId,$uid,$role) as $l) if($key!=='' && sf_parent_key($l)===$key) {
        $children[]=['id'=>$l['id'],'name'=>$l['name'],'grade'=>$l['grade'],'status'=>$l['status']];
    }
    return ['guardian'=>$lead['guardian'],'phone'=>$lead['phone'],'email'=>$lead['email'],
        'family_guid'=>$lead['family_guid']??null,'link_status'=>!empty($lead['family_guid'])?'Explicitly linked':'Unverified phone grouping',
        'children'=>$children,'count'=>count($children)];
}
function sf_visit_view(array $v,array $s): array {
    $l=sf_find($s['leads'],$v['lead_id']);$v['student_name']=$l['name']??'Unknown';
    $staff=isset($v['staff_id'])&&$v['staff_id']!==null?sf_user($s,(int)$v['staff_id']):null;$v['staff_name']=$staff['name']??'Unassigned';return $v;
}
function sf_create_lead(array &$s, int $schoolId, array $p, ?int $siteId, ?int $actor, ?int $assigned, string $action): array {
    $name = sf_text($p,'student_name',140,2);$guardian = sf_text($p,'parent_name',140,2);
    $phone = sf_phone(sf_text($p,'phone',40,6));$norm = preg_replace('/\D/','',$phone);
    $duplicate = null;
    foreach ($s['leads'] as $previous) if ($previous['school_id'] === $schoolId &&
        $previous['normalized_phone'] === $norm && strtolower(trim($previous['name'])) === strtolower(trim($name)) &&
        strtolower(trim((string)($previous['grade']??''))) === strtolower(trim(sf_text($p,'grade',80)))) {
        $duplicate = $previous['id']; break;
    }
    if ($assigned === null) $assigned = sf_assignment_owner($s, $schoolId);
    $familyGuid=sf_family_create_profile($s,$schoolId,$guardian,$phone,sf_text($p,'email',160));
    $lead = ['id'=>sf_id($s,'leads'),'guid'=>sf_uuid(),'school_id'=>$schoolId,'site_id'=>$siteId,'family_guid'=>$familyGuid,'name'=>$name,'guardian'=>$guardian,
        'phone'=>$phone,'normalized_phone'=>$norm,'email'=>sf_text($p,'email',160),'grade'=>sf_text($p,'grade',80),
        'message'=>sf_text($p,'message',2000),'utm_source'=>sf_text($p,'utm_source',140),'utm_campaign'=>sf_text($p,'utm_campaign',140),
        'pincode'=>sf_text($p,'pincode',6),'state'=>sf_text($p,'state',100),'city'=>sf_text($p,'city',100),
        'utm_medium'=>sf_text($p,'utm_medium',140),'utm_term'=>sf_text($p,'utm_term',140),'utm_content'=>sf_text($p,'utm_content',140),
        'landing_page'=>sf_text($p,'landing_page',500),'referrer'=>sf_text($p,'referrer',500),
        'status'=>'New','temperature'=>'Unrated','temperature_suggestion'=>'Unrated',
        'contact_state'=>'Pending Contact','contact_attempts'=>0,'last_successful_contact_at'=>'',
        'consent_email'=>false,'consent_whatsapp'=>false,
        'academic_year'=>sf_text($p,'academic_year',7),'assigned_to'=>$assigned,'followup_date'=>sf_date($p['followup_date'] ?? null),
        'consent'=>!empty($p['consent']) ? 1:0,'duplicate_of'=>$duplicate,'created_at'=>sf_time(),'updated_at'=>sf_time()];
    $s['leads'][] = $lead;
    sf_audit($s,$schoolId,$lead['id'],$actor,$action,$duplicate ? 'Potential duplicate' : '');
    sf_task_schedule($s,$lead,'First Contact',sf_admission_today(),$actor);
    if(!empty($lead['followup_date']))sf_task_sync_followup($s,$lead,$actor);
    return sf_lead_view($s, $lead);
}
/** Demo records have an explicit flag and are always scoped to one school. */
function sf_demo_status(array $s, int $schoolId): array {
    $leadCount = 0; $siteCount = 0;
    foreach ($s['leads'] as $l) if ($l['school_id'] === $schoolId && !empty($l['is_demo'])) $leadCount++;
    foreach ($s['sites'] as $site) if ($site['school_id'] === $schoolId && !empty($site['is_demo'])) $siteCount++;
    return ['leads'=>$leadCount,'sites'=>$siteCount,'has_demo_data'=>($leadCount+$siteCount)>0];
}
function sf_demo_seed(array &$s, int $schoolId, int $adminId): array {
    $status = sf_demo_status($s,$schoolId);
    if ($status['has_demo_data']) sf_fail(409,'Demo records already exist for this school. Clear demo data before adding a new set.');
    // Domains and phone numbers are non-operational, deliberately synthetic examples.
    $examples = [
        ['Demo: School Website','demo-school.example.invalid','WordPress'],
        ['Demo: Admissions Landing Page','admissions-demo.example.invalid','Landing page'],
        ['Demo: Open Day Registration','open-day-demo.example.invalid','WordPress'],
    ];
    $siteIds = [];
    foreach ($examples as [$name,$domain,$platform]) {
        $siteId=sf_id($s,'sites'); $siteIds[]=$siteId;
        $s['sites'][]=['id'=>$siteId,'school_id'=>$schoolId,'name'=>$name,'domain'=>$domain,'platform'=>$platform,
            'key_hash'=>sf_hash(bin2hex(random_bytes(24))), 'enabled'=>0,'is_demo'=>true,'created_at'=>sf_time()];
    }
    // Real staff members may be used as assignment targets; no demo login accounts are created.
    $owners=[];
    foreach ($s['memberships'] as $m) if ($m['school_id']===$schoolId && sf_staff_enabled($m) && in_array($m['role'],['Counsellor','Admissions'],true)) $owners[]=$m['user_id'];
    if (!$owners) $owners=[$adminId];
    $rows = [
        ['Aarav Mehta','Rohan Mehta','Grade 1','New',-1,0],
        ['Diya Sharma','Nisha Sharma','Grade 3','Contacted',0,1],
        ['Kabir Patel','Meera Patel','Grade 5','Counselling',2,2],
        ['Ananya Singh','Rahul Singh','KG', 'Visit Scheduled',3,0],
        ['Reyansh Gupta','Pooja Gupta','Grade 2','Applied',5,1],
        ['Ishita Rao','Manoj Rao','Grade 4','Enrolled',0,2],
        ['Vivaan Nair','Priya Nair','Grade 7','New',-2,0],
        ['Sara Khan','Amina Khan','Grade 6','Contacted',1,1],
        ['Advik Jain','Ritu Jain','Grade 8','Counselling',-1,2],
        ['Myra Kapoor','Neha Kapoor','Grade 1','Visit Scheduled',4,0],
        ['Arjun Reddy','Kiran Reddy','Grade 9','Lost',0,1],
        ['Tara Iyer','Lakshmi Iyer','Grade 2','Applied',2,2],
        ['Ayaan Ali','Farah Ali','Grade 3','Enrolled',0,0],
        ['Kiara Shah','Anita Shah','Grade 4','New',1,1],
        ['Dev Malhotra','Vikas Malhotra','Grade 6','Contacted',-3,2],
        ['Riya Verma','Sanjay Verma','KG','Counselling',3,0],
        ['Neil Das','Maya Das','Grade 5','Visit Scheduled',6,1],
        ['Zoya Mirza','Saba Mirza','Grade 7','Applied',-1,2],
    ];
    $firstDemoContact = null;
    foreach ($rows as $i => [$student,$guardian,$grade,$stage,$followOffset,$siteIndex]) {
        // 00000-prefixed phone values and example.invalid emails are never contactable.
        $phone=sprintf('+91 00000 %05d',10000+$i);
        $p=['student_name'=>'Demo: '.$student,'parent_name'=>'Demo: '.$guardian,'phone'=>$phone,
            'email'=>'demo'.$i.'@example.invalid','grade'=>$grade,
            'message'=>'SAMPLE DATA — training only. Do not contact. Parent requested fees, transport, or campus information.',
            'utm_source'=>$siteIndex===1?'google-demo':'website-demo','utm_campaign'=>'demo-admissions',
            'consent'=>false,'followup_date'=>in_array($stage,['Enrolled','Lost'],true)?'':gmdate('Y-m-d',strtotime(($followOffset>=0?'+':'').$followOffset.' days'))];
        $assigned=$owners[$i % count($owners)];
        $lead=sf_create_lead($s,$schoolId,$p,$siteIds[$siteIndex],$adminId,$assigned,'demo_seed');
        $leadId=$lead['id'];
        $leadIndex=count($s['leads'])-1;
        $s['leads'][$leadIndex]['is_demo']=true;
        // Keep example siblings in one fictional family, never copy a real or cross-campus contact.
        if($i===0) $firstDemoContact=[
            'guardian'=>$s['leads'][$leadIndex]['guardian'],
            'phone'=>$s['leads'][$leadIndex]['phone'],
            'normalized_phone'=>$s['leads'][$leadIndex]['normalized_phone'],
        ];
        if(($i===1 || $i===12) && $firstDemoContact!==null){
            foreach($firstDemoContact as $field=>$value) $s['leads'][$leadIndex][$field]=$value;
        }
        if(in_array($stage,['Contacted','Counselling','Visit Scheduled','Applied','Enrolled'],true)){
            $s['calls']=$s['calls']??[];
            $s['calls'][]=['id'=>sf_id($s,'calls'),'guid'=>sf_uuid(),'school_id'=>$schoolId,'lead_id'=>$leadId,
                'actor_id'=>$assigned,'outcome'=>'Interested','note'=>'DEMO call: family requested admission details.',
                'created_at'=>sf_time(),'followup_date'=>$p['followup_date'],'is_demo'=>true];
            $s['leads'][$leadIndex]['last_call_outcome']='Interested';
            $s['leads'][$leadIndex]['last_contact_at']=sf_time();
        }
        if($stage==='Visit Scheduled'||$stage==='Counselling'){
            $s['visits']=$s['visits']??[];
            $s['visits'][]=['id'=>sf_id($s,'visits'),'guid'=>sf_uuid(),'school_id'=>$schoolId,'lead_id'=>$leadId,
                'staff_id'=>$assigned,'purpose'=>'DEMO campus tour','starts_at'=>gmdate('Y-m-d\TH:i',strtotime('+'.(2+$i%8).' days 10:00')),
                'status'=>'Scheduled','created_at'=>sf_time(),'is_demo'=>true];
        }
        $s['leads'][$leadIndex]['status']=$stage;
        $s['leads'][$leadIndex]['created_at']=gmdate('Y-m-d\TH:i:s+00:00',strtotime('-'.($i%12+1).' days'));
        sf_audit($s,$schoolId,$leadId,$adminId,'note','DEMO NOTE: Example counselling discussion; not an actual family or enquiry.');
    }
    // Demo message history is explicitly synthetic: no real provider request or delivery occurred.
    $s['message_attempts']=$s['message_attempts']??[];
    foreach(array_slice($s['leads'],-count($rows),5) as $sample){
        $s['message_attempts'][]=['id'=>sf_id($s,'message_attempts'),'guid'=>sf_uuid(),
            'school_id'=>$schoolId,'lead_id'=>$sample['id'],'actor_id'=>$adminId,
            'channel'=>count($s['message_attempts'])%2?'email':'whatsapp',
            'status'=>'demo_preview','provider_id'=>'','created_at'=>sf_time(),'is_demo'=>true];
    }
    $s['email_templates']=$s['email_templates']??[];
    foreach([
        ['DEMO: Admission enquiry acknowledgement','Thank you for enquiring at {school}',
         'Dear {parent},\n\nThank you for your interest in {school} for {student}. Our admissions team will contact you.'],
        ['DEMO: Campus visit reminder','Visit reminder — {school}',
         'Hello {parent},\n\nWe look forward to seeing {student} at {school}.']
    ] as [$title,$subject,$body]) {
        $s['email_templates'][]=['id'=>sf_id($s,'email_templates'),'guid'=>sf_uuid(),'school_id'=>$schoolId,
            'title'=>$title,'subject'=>$subject,'body'=>$body,'is_demo'=>true,'created_at'=>sf_time()];
    }
    return array_merge(sf_demo_status($s,$schoolId),['created'=>true]);
}
function sf_demo_clear(array &$s, int $schoolId): array {
    $removedLeadIds=[];$demoFamilyGuids=[];
    foreach ($s['leads'] as $l) if ($l['school_id']===$schoolId && !empty($l['is_demo'])) {
        $removedLeadIds[$l['id']]=true;
        if(!empty($l['family_guid']))$demoFamilyGuids[$l['family_guid']]=true;
    }
    $s['leads']=array_values(array_filter($s['leads'],fn($l)=>!isset($removedLeadIds[$l['id']])));
    $s['activities']=array_values(array_filter($s['activities'],fn($a)=>!isset($removedLeadIds[$a['lead_id'] ?? -1])));
    // Delete only demo-associated profiles with no surviving enquiry.
    $survivingFamilyGuids=[];
    foreach($s['leads'] as $lead)if(!empty($lead['family_guid']))$survivingFamilyGuids[$lead['family_guid']]=true;
    if(isset($s['parent_profiles']))$s['parent_profiles']=array_values(array_filter(
        $s['parent_profiles'],static fn($p)=>!((int)($p['school_id']??0)===$schoolId &&
            isset($demoFamilyGuids[$p['guid']??'']) &&
            !isset($survivingFamilyGuids[$p['guid']??'']))
    ));

    foreach(['calls','visits','tasks','applications','admission_documents','notification_queue','stage_history','message_attempts'] as $related)if(isset($s[$related]))
        $s[$related]=array_values(array_filter($s[$related],fn($x)=>!isset($removedLeadIds[$x['lead_id']??-1])));
    // Preserve real submissions even if they refer to a demo lead or a demo source.
    foreach ($s['leads'] as &$l) if ($l['school_id']===$schoolId && isset($removedLeadIds[$l['duplicate_of'] ?? -1])) $l['duplicate_of']=null;
    unset($l);
    $removedSiteIds=[];$keptSites=0;
    foreach ($s['sites'] as $site) if ($site['school_id']===$schoolId && !empty($site['is_demo'])) {
        $referenced=false;
        foreach ($s['leads'] as $l) if ($l['site_id']===$site['id']) {$referenced=true;break;}
        if ($referenced) $keptSites++; else $removedSiteIds[$site['id']]=true;
    }
    // A demo website retained for real enquiries must no longer be demo-tagged.
    foreach ($s['sites'] as &$site) if ($site['school_id']===$schoolId && !empty($site['is_demo']) && !isset($removedSiteIds[$site['id']])) { $site['is_demo']=false; $site['enabled']=0; }
    unset($site);
    $s['sites']=array_values(array_filter($s['sites'],fn($site)=>!isset($removedSiteIds[$site['id']])));
    $s['receipts']=array_values(array_filter($s['receipts'],static fn($r)=>
        !isset($removedLeadIds[$r['lead_id']??-1]) &&
        !isset($removedLeadIds[$r['chat_session_lead_id']??-1]) &&
        !isset($removedSiteIds[$r['site_id']??-1])));
    if(isset($s['email_templates']))$s['email_templates']=array_values(array_filter($s['email_templates'],
        fn($t)=>!($t['school_id']===$schoolId&&!empty($t['is_demo']))));
    return ['removed_leads'=>count($removedLeadIds),'removed_sites'=>count($removedSiteIds),'kept_demo_sites_with_real_leads'=>$keptSites];
}

function sf_json(mixed $result, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * One-time browser bootstrap: verify a minimal-permission GitHub token against the
 * immutable GitHub user ID of the owner of indigiti/scrm. Token is never persisted.
 */
function sf_github_owner_verified(string $token): bool {
    // Fine-grained GitHub PATs can call GET /user without repository permissions.
    if (!preg_match('/^github_pat_[A-Za-z0-9_]{30,240}$/D', $token)) {
        sf_fail(422, 'Use a fine-grained GitHub personal access token (github_pat_...).');
    }
    if (!function_exists('curl_init')) sf_fail(503, 'The server PHP cURL extension is required for GitHub verification.');
    $ch = curl_init('https://api.github.com/user');
    if ($ch === false) sf_fail(503, 'GitHub verification is not available.');
    try {
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'User-Agent: SchoolFlow-First-Admin',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    } finally {curl_close($ch);}
    if ($body === false) sf_fail(503, 'GitHub is temporarily unreachable. Please retry.');
    if ($status !== 200) sf_fail(403, 'GitHub could not verify this fine-grained token.');
    $profile = json_decode($body, true);
    // ID is more stable than a mutable username. indigiti GitHub owner id = 34906944.
    return is_array($profile) && ($profile['id'] ?? null) === 34906944;
}

/** Route return form: [HTTP status, data]. All mutations run inside exclusive file lock. */
function sf_handle(string $method, string $uri, array $payload, array $headers, string $ip, SchoolFlowStore $store, ?callable $ownerVerifier = null, ?callable $messageTransport = null): array {
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    if ($path === '/health/ready' && $method === 'GET')return $store->transaction(static function(array $s):array {
        $ready=false;
        foreach($s['memberships']??[] as $membership){
            if(($membership['role']??'')!=='Admin'||!sf_staff_enabled($membership))continue;
            $user=sf_user($s,(int)($membership['user_id']??0));
            $school=sf_find($s['schools']??[],(int)($membership['school_id']??0));
            if($school!==null&&!empty($user['active'])){$ready=true;break;}
        }
        return [$ready?200:503,['ok'=>$ready,'service'=>'schoolflow']];
    });
    if ($path === '/health' && $method === 'GET') return [200, ['ok'=>true]];
    if($method==='POST' && preg_match('#^/api/schools/(\d+|[0-9a-f-]{36})/exports/admissions\.xlsx$#iD',$path,$exportMatch))
        return [200,sf_export_admissions($store,(string)($headers['authorization']??''),$exportMatch[1],$payload)];
    if($method==='POST' && preg_match('#^/api/schools/(\d+|[a-f0-9-]{36})/imports/(preview|commit)$#iD',$path,$importMatch))
        return sf_import_process($store,(string)($headers['authorization']??''),$importMatch[1],$importMatch[2],$payload);
    if($method==='POST' && preg_match('#^/api/schools/(\d+|[a-f0-9-]{36})/imports/([a-f0-9-]{36})/rollback$#iD',$path,$importMatch))
        return sf_import_rollback($store,(string)($headers['authorization']??''),$importMatch[1],$importMatch[2]);
    if($method==='POST' && preg_match('~^/api/public/forms/([a-f0-9-]{36})/counsel$~iD',$path,$cm))
        return sf_counsellor_message(strtolower($cm[1]),$payload,$headers,$ip,$store);
    if($method==='POST' && preg_match('~^/api/public/forms/([a-f0-9-]{36})/counsel-lead$~iD',$path,$cl))
        return sf_counsellor_convert(strtolower($cl[1]),$payload,$headers,$ip,$store);
    if(preg_match('~^/api/schools/([a-f0-9-]{36}|[0-9]+)/public-chat/knowledge-files$~iD',$path,$kbFiles))
        return sf_kfile_admin($method,$kbFiles[1],$payload,$headers,$store);
    if(preg_match('~^/api/schools/([a-f0-9-]{36}|[0-9]+)/public-chat/faqs$~iD',$path,$kbFaqs))
        return sf_chat_faq_manage($method,$kbFaqs[1],$payload,$headers,$store);
    if(preg_match('~^/api/schools/([a-f0-9-]{36}|[0-9]+)/public-chat/sources$~iD',$path,$srcChat))
        return sf_counsellor_sources($method,$srcChat[1],$payload,$headers,$store);
    if(preg_match('~^/api/schools/([a-f0-9-]{36}|[0-9]+)/public-chat/counsellor-report$~iD',$path,$cr))
        return sf_counsellor_report($method,$cr[1],$payload,$headers,$store);
    if(preg_match('~^/api/public/forms/([a-f0-9-]{36})/(chat-config|chat|chat-start|chat-followup|chat-remember|chat-resume|chat-forget|callback)$~iD',$path,$chatMatch))
        return sf_chat_public($method,strtolower($chatMatch[1]),$chatMatch[2],$payload,$headers,$ip,$store);
    if($method==='POST' && preg_match('~^/api/schools/([a-f0-9-]{36}|[0-9]+)/public-chat/provider/test$~iD',$path,$providerTest))
        return sf_chat_provider_test($providerTest[1],$headers,$store);
    if(preg_match('~^/api/schools/([a-f0-9-]{36}|[0-9]+)/public-chat/provider$~iD',$path,$providerChat))
        return sf_chat_provider_admin($method,$providerChat[1],$payload,$headers,$store);
    if(preg_match('~^/api/schools/([a-f0-9-]{36}|[0-9]+)/public-chat$~iD',$path,$adminChat))
        return sf_chat_admin($method,$adminChat[1],$payload,$headers,$store);
    if(preg_match('~^/api/schools/([a-f0-9-]{36}|[0-9]+)/public-chat/quality$~iD',$path,$quality))
        return sf_ai_quality_admin($method,$quality[1],$headers,$store);
    if(preg_match('~^/api/schools/([a-f0-9-]{36}|[0-9]+)/public-chat/review$~iD',$path,$review))
        return sf_kb_review($method,$review[1],$payload,$headers,$store);
    if (str_starts_with($path,'/api/public/forms/'))
        return sf_public_handle($method,$path,$payload,$headers,$ip,$store);
    if($method==='POST' && preg_match('#^/api/schools/\d+/communications/(email|whatsapp)/send$#D',$path))
        return sf_provider_send($uri,$payload,$headers,$store,$messageTransport);
    if(preg_match('~^/api/schools/([0-9]+|[a-f0-9-]{36})/work-queue$~iD',$path,$wq))
        return sf_work_queue_endpoint($method,$wq[1],$headers,$uri,$store);
    // Public first-run discovery contains no secrets. Setup is available only for a truly empty store.
    if ($path === '/api/setup/status' && $method === 'GET') {
        return $store->transaction(static function (array $s): array {
            return [200, ['needs_setup' => count($s['users']) === 0,
                          'code_ready' => !empty($s['setup_code_hash']) && count($s['users']) === 0]];
        });
    }
    if ($path === '/api/setup/admin' && $method === 'POST') {
        // Count every attempt BEFORE external verification; even failed requests are rate limited.
        $store->transaction(static function (array &$s) use ($ip): void {
            if (!empty($s['users'])) sf_fail(409, 'Setup is already complete. Please sign in.');
            sf_limit($s, 'first-admin:' . hash('sha256', $ip), 6, 900);
        }, true);
        $github = isset($payload['github_token']) && $payload['github_token'] !== '';
        if ($github) {
            $token = sf_text($payload, 'github_token', 255, 40);
            // In production this always calls GitHub over HTTPS. Callback injection is
            // provided solely for isolated tests of the first-admin state machine.
            $verifier = $ownerVerifier ?? 'sf_github_owner_verified';
            if ($verifier($token) !== true) sf_fail(403, 'GitHub verification requires the indigiti repository owner.');
        }
        return $store->transaction(static function (array &$s) use ($payload, $github): array {
            // This second locked check prevents a concurrent request creating another admin.
            if (!empty($s['users'])) sf_fail(409, 'Setup is already complete. Please sign in.');
            if (!$github) {
                // Keep legacy SSH setup-code support for installations already using it.
                $code = sf_text($payload, 'setup_code', 128, 12);
                if (empty($s['setup_code_hash']) || !hash_equals($s['setup_code_hash'], sf_hash($code))) {
                    sf_fail(403, 'Incorrect setup code. Use GitHub verification or Cloudways SSH.');
                }
            }
            $name = sf_text($payload, 'name', 140, 2);
            $school = sf_text($payload, 'school_name', 140, 3);
            $email = sf_email(sf_text($payload, 'email', 254, 3));
            $password = sf_text($payload, 'password', 1024, 12);
            $confirm = sf_text($payload, 'confirm_password', 1024, 12);
            if (!hash_equals($password, $confirm)) sf_fail(422, 'Passwords do not match');
            $user = ['id'=>sf_id($s,'users'),'name'=>$name,'email'=>$email,
                'password_hash'=>password_hash($password, PASSWORD_DEFAULT),
                'active'=>true,'created_at'=>sf_time()];
            $schoolRow = ['id'=>sf_id($s,'schools'),'guid'=>sf_uuid(),'name'=>$school,'created_at'=>sf_time()];
            $s['users'][] = $user;
            $s['schools'][] = $schoolRow;
            $s['memberships'][] = ['school_id'=>$schoolRow['id'],'user_id'=>$user['id'],'role'=>'Admin'];
            sf_event($s,$schoolRow['id'],null,$user['id'],'admin_created','First administrator created');
            unset($s['setup_code_hash']);
            $sessionToken = bin2hex(random_bytes(40));
            $expires = time() + SF_TTL;
            $s['sessions'][] = ['token_hash'=>sf_hash($sessionToken),'user_id'=>$user['id'],'expires_at'=>$expires];
            return [201, ['token'=>$sessionToken,'expires_at'=>gmdate('Y-m-d\TH:i:s+00:00',$expires)]];
        }, true);
    }
    if ($path === '/api/auth/login' && $method === 'POST') {
        return $store->transaction(function (&$s) use ($payload,$ip) {
            sf_limit($s, 'login:' . hash('sha256',$ip), 12,900);
            $email=sf_email(sf_text($payload,'email',254,3));
            $pass=sf_text($payload,'password',1024,1);
            $user=null;
            foreach ($s['users'] as $u) if ($u['email']===$email && $u['active']) { $user=$u;break; }
            if($user && !array_filter($s['memberships'],fn($m)=>$m['user_id']===$user['id'] && sf_staff_enabled($m)))$user=null;
            if (!$user || !password_verify($pass,$user['password_hash'])) {
                if ($user) foreach ($s['memberships'] as $m) if ($m['user_id']===$user['id']) sf_event($s,$m['school_id'],null,$user['id'],'login_failed','Incorrect password');
                return [401, ['detail'=>'Incorrect email or password']];
            }
            $token = bin2hex(random_bytes(40));
            $expires=time()+SF_TTL;
            $s['sessions'] = array_values(array_filter($s['sessions'], fn($v) => $v['expires_at'] > time()));
            $s['sessions'][] = ['token_hash'=>sf_hash($token),'user_id'=>$user['id'],'expires_at'=>$expires];
            foreach ($s['memberships'] as $m) if ($m['user_id']===$user['id']) sf_event($s,$m['school_id'],null,$user['id'],'login','Signed in');
            return [200, ['token'=>$token,'expires_at'=>gmdate('Y-m-d\TH:i:s+00:00', $expires)]];
        },true);
    }
    if ($path === '/api/v1/group-schools' && $method === 'GET') {
        $auth=$headers['authorization']??'';
        return $store->transaction(static function (array $s) use($auth): array {
            $group=sf_group_site_by_key($s,$auth);
            if (!$group) sf_fail(401,'Group website key not recognized or inactive');
            $list=sf_group_campuses($s,$group);
            return [200,['schools'=>array_map(static fn($x)=>['code'=>$x['code'],'name'=>$x['name']],$list)]];
        });
    }
    if ($path === '/api/v1/inbound-leads' && $method === 'POST') {
        $auth=$headers['authorization']??'';
        if (!str_starts_with($auth,'Bearer sfsite_') && !str_starts_with($auth,'Bearer sfgroup_')) sf_fail(401, 'Website API key required');
        $idem=$headers['idempotency-key']??'';
        if (strlen($idem)<6 || strlen($idem)>160) sf_fail(422,'A unique Idempotency-Key of 6–160 characters is required');
        if (($payload['consent']??false) !== true) sf_fail(422,'Parent/guardian consent required');
        if (str_starts_with($auth,'Bearer sfgroup_')) {
            return $store->transaction(function (&$s) use ($auth,$idem,$payload) {
                $group=sf_group_site_by_key($s,$auth);
                if (!$group) sf_fail(401,'Group website key not recognized or inactive');
                $code=sf_text($payload,'school_code',50,3);
                $route=null;
                foreach(sf_group_campuses($s,$group) as $campus) if(hash_equals($campus['code'],$code)) {$route=$campus;break;}
                if(!$route) sf_fail(422,'Choose a valid school from this group website');
                sf_limit($s,'group:'.$group['id'],120,60);
                // Separate receipt namespace from legacy site IDs; replay stays tied to the selected campus.
                foreach($s['receipts'] as $receipt) if(($receipt['group_site_id']??null)===$group['id'] && $receipt['idempotency_key']===$idem) {
                    if(($receipt['school_id']??null)!==$route['school_id']) sf_fail(409,'This submission ID was already used for a different school');
                    return [200,['id'=>$receipt['lead_id'],'duplicate_submission'=>true]];
                }
                $p=$payload;$p['grade']=$payload['class']??($payload['grade']??'');
                $lead=sf_create_lead($s,$route['school_id'],$p,$route['site_id'],null,null,'group_website_received');
                $s['receipts'][]=['group_site_id'=>$group['id'],'school_id'=>$route['school_id'],
                    'idempotency_key'=>$idem,'lead_id'=>$lead['id'],'created_at'=>sf_time()];
                return [201,['id'=>$lead['id'],'duplicate_submission'=>false]];
            },true);
        }
        return $store->transaction(function (&$s) use ($auth,$idem,$payload) {
            $site=null;
            foreach ($s['sites'] as $entry) if ($entry['enabled'] && hash_equals($entry['key_hash'],sf_hash(substr($auth,7)))) {$site=$entry;break;}
            if (!$site) sf_fail(401,'Website key not recognized or inactive');
            sf_limit($s,'site:'.$site['id'],120,60);
            foreach ($s['receipts'] as $r) if ($r['site_id']===$site['id'] && $r['idempotency_key']===$idem) return [200,['id'=>$r['lead_id'],'duplicate_submission'=>true]];
            $p=$payload;
            $p['grade']=$payload['class']??($payload['grade']??'');
            $lead=sf_create_lead($s,$site['school_id'],$p,$site['id'],null,null,'website_received');
            $s['receipts'][]=['site_id'=>$site['id'],'idempotency_key'=>$idem,'lead_id'=>$lead['id'],'created_at'=>sf_time()];
            return [201,['id'=>$lead['id'],'duplicate_submission'=>false]];
        },true);
    }
    // All other API routes require staff authentication.
    if (!str_starts_with($path, '/api/')) sf_fail(404,'Not found');
    $auth=$headers['authorization']??'';
    $write=$method !== 'GET';
    return $store->transaction(function (&$s) use ($path,$method,$payload,$auth,$uri,$store) {
        $user=sf_session($s,$auth);$uid=$user['id'];
        if(!empty($user['must_change_password']) && !in_array($path,['/api/me','/api/me/password','/api/auth/logout'],true))
            sf_fail(403,'Change your temporary password before using the CRM');
        if($path==='/api/me/password' && $method==='POST')return [200,sf_staff_change_own_password($s,$uid,$payload)];
        if($path==='/api/admin/staging-reset')return sf_web_stage_handle($s,$user,$method,$payload,$store);
        // School URLs accept GUIDs without changing legacy numeric IDs or internal relations.
        if(preg_match('#^/api/schools/([0-9a-f-]{36})(/.*)?$#iD',$path,$sr)){
            $canonical=sf_record_id($s,'schools',$sr[1]);
            $path='/api/schools/'.$canonical.($sr[2]??'');
        }

        if(preg_match('#^/api/schools/(\d+)/members/(\d+)(?:/(reset-password))?$#D',$path,$staffMatch)){
            $sid=(int)$staffMatch[1];$target=(int)$staffMatch[2];
            if($method==='PATCH' && empty($staffMatch[3]))return [200,sf_staff_update($s,$sid,$uid,$target,$payload)];
            if($method==='POST' && ($staffMatch[3]??'')==='reset-password')
                return [200,sf_staff_reset_password($s,$sid,$uid,$target)];
            sf_fail(405,'Team member operation is not supported');
        }
        if(preg_match('#^/api/schools/(\d+)/leads/(\d+)/documents$#D',$path,$dm)){
            $sid=(int)$dm[1];$leadId=(int)$dm[2];
            $role=sf_membership($s,$sid,$uid);
            sf_lead_allowed($s,$sid,$leadId,$uid,$role);
            if($method==='GET')return [200,sf_doc_list($s,$sid,$leadId)];
            if($method==='PATCH')return [200,sf_doc_update($s,$sid,$leadId,$uid,$role,$payload)];
            sf_fail(405,'Document checklist method not allowed');
        }
        if(preg_match('#^/api/schools/(\d+)/(seat-plans|response-sla)$#D',$path,$ops)){
            $sid=(int)$ops[1];$action=$ops[2];
            sf_membership($s,$sid,$uid,['Admin']);
            if($action==='seat-plans'){
                if($method==='GET')return [200,sf_capacity_plan($s,$sid)];
                if($method==='PATCH')return [200,sf_capacity_upsert($s,$sid,$uid,$payload)];
            }
            if($action==='response-sla'){
                if($method==='GET')return [200,sf_sla_policy($s,$sid)];
                if($method==='PATCH')return [200,sf_sla_set_policy($s,$sid,$uid,$payload)];
            }
            sf_fail(405,'Operations method not allowed');
        }
        if(preg_match('#^/api/schools/(\d+)/followup-policy$#D',$path,$pm)){
            $sid=(int)$pm[1];sf_membership($s,$sid,$uid,['Admin']);
            if($method==='GET')return [200,sf_followup_policy($s,$sid)];
            if($method==='PATCH')return [200,sf_followup_set_policy($s,$sid,$uid,$payload)];
            sf_fail(405,'Follow-up policy method not allowed');
        }
        // School-scoped opt-in automation: policy, status and evidence-based reconciliation.
        if(preg_match('#^/api/schools/(\d+)/automation(?:/(queue))?(?:/(\d+)/(reconcile))?$#D',$path,$am)){
            $sid=(int)$am[1];sf_membership($s,$sid,$uid,['Admin']);
            $queue=($am[2]??'')==='queue';$entryId=isset($am[3])?(int)$am[3]:null;
            $s['notification_queue']=$s['notification_queue']??[];
            if($method==='GET'&&!$queue&&$entryId===null)return [200,sf_auto_policy($s,$sid)];
            if($method==='PATCH'&&!$queue&&$entryId===null)return [200,sf_auto_set_policy($s,$sid,$uid,$payload)];
            if($method==='GET'&&$queue&&$entryId===null){
                $out=array_values(array_filter($s['notification_queue'],fn($v)=>$v['school_id']===$sid));
                usort($out,fn($a,$b)=>$b['id']<=>$a['id']);
                return [200,array_map('sf_auto_queue_view',array_slice($out,0,500))];
            }
            if($method==='POST'&&$queue&&$entryId!==null&&($am[4]??'')==='reconcile')
                return [200,sf_auto_reconcile($s,$sid,$entryId,$uid,$payload)];
            sf_fail(405,'Automation method not allowed');
        }
        // Configuration is school-scoped; keys are encrypted and never returned by GET.
        if(preg_match('#^/api/schools/(\d+)/communications$#D',$path,$cm)){
            $sid=(int)$cm[1];sf_membership($s,$sid,$uid);
            if($method==='GET')return [200,sf_provider_view($s,$sid)];
            if($method==='PATCH'){
                sf_membership($s,$sid,$uid,['Admin']);
                return [200,sf_provider_set($s,$store,$sid,$uid,$payload)];
            }
            sf_fail(404,'Not found');
        }
        if ($path==='/api/auth/logout' && $method==='POST') {
            $hash=sf_hash(substr($auth,7));
            $s['sessions']=array_values(array_filter($s['sessions'],fn($ss)=>$ss['token_hash']!==$hash));
            foreach ($s['memberships'] as $m) if ($m['user_id']===$uid) sf_event($s,$m['school_id'],null,$uid,'logout','Signed out');
            return [200,['ok'=>true]];
        }
        if ($path==='/api/me' && $method==='GET') {
            $schools=[];
            foreach ($s['memberships'] as $m) if ($m['user_id']===$uid && sf_staff_enabled($m)) {
                $school=sf_find($s['schools'],$m['school_id']);
                if ($school) $schools[]=['id'=>$school['id'],'name'=>$school['name'],'role'=>$m['role']];
            }
            usort($schools,fn($a,$b)=>strcmp($a['name'],$b['name']));
            return [200,['user'=>['id'=>$uid,'name'=>$user['name'],'email'=>$user['email'],'must_change_password'=>!empty($user['must_change_password'])],'schools'=>$schools]];
        }
        // Group website administration: owner must be an administrator of each destination school.
        if (preg_match('#^/api/group-sites(?:/(\d+))?(?:/(rotate-key|refresh-form-id))?$#D',$path,$gm)) {
            $s['group_sites']=$s['group_sites']??[];
            $groupId=isset($gm[1]) && $gm[1]!==''?(int)$gm[1]:null;
            $operation=$gm[2]??'';
            $authorizedSchools=[];
            foreach($s['memberships'] as $member) if($member['user_id']===$uid && $member['role']==='Admin' && sf_staff_enabled($member)) $authorizedSchools[]=$member['school_id'];
            if(!$authorizedSchools) sf_fail(403,'School administrator access required');
            if($method==='GET' && $groupId===null) {
                $visible=[];
                foreach($s['group_sites'] as $group) if($group['owner_id']===$uid) $visible[]=sf_group_public_view($s,$group);
                return [200,$visible];
            }
            if($method==='POST' && $groupId===null) {
                $name=sf_text($payload,'name',140,3);$domain=sf_text($payload,'domain',180,3);
                $schoolIds=$payload['school_ids']??null;
                // All validation before allocating a persistent group source.
                if(!is_array($schoolIds) || !$schoolIds || !array_is_list($schoolIds)) sf_fail(422,'Choose school campuses');
                foreach($schoolIds as $sid) {
                    if((!is_int($sid) && (!is_string($sid) || !ctype_digit($sid))) || !in_array((int)$sid,$authorizedSchools,true)) sf_fail(403,'You may only connect schools you administer');
                }
                $id=sf_id($s,'group_sites');
                $routes=sf_group_select_schools($s,$uid,$id,$name,$domain,$schoolIds);
                $key='sfgroup_'.bin2hex(random_bytes(32));
                $group=['id'=>$id,'guid'=>sf_uuid(),'owner_id'=>$uid,'name'=>$name,'domain'=>$domain,'key_hash'=>sf_hash($key),
                    'enabled'=>true,'routes'=>$routes,'created_at'=>sf_time()];
                $s['group_sites'][]=$group;
                foreach($routes as $route)sf_event($s,$route['school_id'],null,$uid,'group_website_connected','Group source #'.$id.' connected');
                return [201,array_merge(sf_group_public_view($s,$group),['api_key'=>$key])];
            }
            $group=null;
            foreach($s['group_sites'] as $g) if($g['id']===$groupId && $g['owner_id']===$uid) {$group=$g;break;}
            if(!$group)sf_fail(404,'Group website not found');
            if($method==='POST' && $operation==='rotate-key') {
                $key='sfgroup_'.bin2hex(random_bytes(32));
                foreach($s['group_sites'] as &$row)if($row['id']===$groupId)$row['key_hash']=sf_hash($key);
                unset($row);
                foreach($group['routes'] as $route)sf_event($s,$route['school_id'],null,$uid,'group_key_rotated','Group source #'.$groupId);
                return [200,['api_key'=>$key]];
            }
            if($method==='POST' && $operation==='refresh-form-id'){
                // Rotating the public embed GUID invalidates previous embeds and
                // public form endpoints. School routes, captured leads and private
                // integration keys remain unchanged.
                $newGuid=sf_uuid();
                foreach($s['group_sites'] as &$row)if($row['id']===$groupId){
                    $row['guid']=$newGuid;
                    $row['form_id_refreshed_at']=sf_time();
                    $group=$row;
                    break;
                }
                unset($row);
                foreach($group['routes'] as $route)
                    sf_event($s,(int)$route['school_id'],null,$uid,'group_form_id_refreshed',
                        'Public embed identifier refreshed for group #'.$groupId);
                return [200,sf_group_public_view($s,$group)];
            }
            if($method==='PATCH' && $operation==='') {
                $name=sf_text($payload,'name',140,3);
                $domain=sf_text($payload,'domain',180,3);
                $enabled=$payload['enabled']??true;
                if(!is_bool($enabled))sf_fail(422,'enabled must be true or false');
                $routes=sf_group_select_schools($s,$uid,$groupId,$name,$domain,$payload['school_ids']??null,$group['routes']);
                foreach($s['group_sites'] as &$row) if($row['id']===$groupId){
                    $row['name']=$name;$row['domain']=$domain;$row['enabled']=$enabled;$row['routes']=$routes;
                    $group=$row;break;
                }unset($row);
                foreach($routes as $route) foreach($s['sites'] as &$site) if($site['id']===$route['site_id'])$site['enabled']=$enabled;
                unset($site);
                foreach($routes as $route)sf_event($s,$route['school_id'],null,$uid,'group_routing_changed','Group source #'.$groupId.' updated');
                return [200,sf_group_public_view($s,$group)];
            }
            sf_fail(404,'Unknown group website action');
        }
        // School-wide routing overview: only schools where this user is an Admin.
        // Never expose site bearer keys or leads from unrelated schools.
        if ($path==='/api/schools/overview' && $method==='GET') {
            $adminIds=[];
            foreach ($s['memberships'] as $m) if ($m['user_id']===$uid && $m['role']==='Admin' && sf_staff_enabled($m)) $adminIds[$m['school_id']]=true;
            if (!$adminIds) sf_fail(403,'Only school administrators can see routing');
            $rows=[];
            foreach ($s['schools'] as $school) {
                $sid=$school['id'];
                if (!isset($adminIds[$sid])) continue;
                $sites=[];$leads=0;$new=0;$unassigned=0;$enrolled=0;$hot=0;$breached=0;
                $slaMinutes=sf_sla_policy($s,$sid)['first_contact_minutes'];
                foreach ($s['sites'] as $site) if ($site['school_id']===$sid) {
                    $sites[]=['id'=>$site['id'],'name'=>$site['name'],'domain'=>$site['domain'],
                        'platform'=>$site['platform'],'enabled'=>(bool)$site['enabled']];
                }
                foreach ($s['leads'] as $lead) if ($lead['school_id']===$sid) {
                    $leads++;
                    if (in_array($lead['status'],['New','New Enquiry'],true)) $new++;
                    if (($lead['assigned_to']??null)===null) $unassigned++;
                    if ($lead['status']==='Enrolled')$enrolled++;
                    if (($lead['temperature']??'Unrated')==='Hot')$hot++;
                    if(empty($lead['last_successful_contact_at']) && empty($lead['is_demo']) && empty($lead['import_batch_guid']) &&
                       !in_array($lead['status'],['Lost','Enrolled','Not Eligible','Duplicate / Invalid'],true) &&
                       (time()-strtotime((string)($lead['created_at']??'now'))) >= $slaMinutes*60)$breached++;
                }
                usort($sites,fn($a,$b)=>strcmp($a['name'],$b['name']));
                $rows[]=['id'=>$sid,'name'=>$school['name'],'leads'=>$leads,'new_leads'=>$new,
                    'unassigned'=>$unassigned,'enrolled'=>$enrolled,'hot_leads'=>$hot,'sla_breached'=>$breached,'sites'=>$sites,
                    'assignment_mode'=>$s['assignment_modes'][(string)$sid]??'least_loaded'];
            }
            usort($rows,fn($a,$b)=>strcmp($a['name'],$b['name']));
            return [200,$rows];
        }
        if ($path==='/api/schools' && $method==='POST') {
            $allowed=false;
            foreach ($s['memberships'] as $m) if ($m['user_id']===$uid && $m['role']==='Admin' && sf_staff_enabled($m)) $allowed=true;
            if (!$allowed) sf_fail(403,'Only an existing school admin can create a school');
            $name=sf_text($payload,'name',140,3);
            $school=['id'=>sf_id($s,'schools'),'guid'=>sf_uuid(),'name'=>$name,'created_at'=>sf_time()];
            $s['schools'][]=$school;$s['memberships'][]=['user_id'=>$uid,'school_id'=>$school['id'],'role'=>'Admin'];
            sf_event($s,$school['id'],null,$uid,'school_created','New school workspace');
            return [201,['id'=>$school['id'],'name'=>$name,'role'=>'Admin']];
        }
        if(preg_match('#^/api/schools/(\d+)$#D',$path,$schoolMatch) && $method==='PATCH'){
            $sid=(int)$schoolMatch[1];sf_membership($s,$sid,$uid,['Admin']);
            $name=sf_text($payload,'name',140,3);
            foreach($s['schools'] as &$school)if($school['id']===$sid){
                $school['name']=$name;$school['updated_at']=sf_time();
                $saved=$school;break;
            }unset($school);
            if(!isset($saved))sf_fail(404,'School not found');
            sf_event($s,$sid,null,$uid,'school_updated','School name updated');
            return [200,['id'=>$sid,'name'=>$name]];
        }
        // CRM 2.0 school-scoped modules: backwards compatible with existing store.json.
        if (preg_match('#^/api/schools/(\d+)/(assignment|visits|families|templates|analytics|tasks|applications)(?:/(\d+))?(?:/(siblings))?$#D',$path,$ext)) {
            $sid=(int)$ext[1];$kind=$ext[2];$rid=isset($ext[3])?(int)$ext[3]:null;
            $role=sf_membership($s,$sid,$uid);
            $visible=sf_visible_leads($s,$sid,$uid,$role);
            if($kind==='assignment') {
                if($method==='GET')return [200,['mode'=>$s['assignment_modes'][(string)$sid]??'least_loaded','options'=>['least_loaded','round_robin','manual']]];
                sf_membership($s,$sid,$uid,['Admin']);
                if($method==='PATCH' && $rid===null) {
                    $mode=sf_text($payload,'mode',30,3);
                    if(!in_array($mode,['least_loaded','round_robin','manual'],true))sf_fail(422,'Invalid assignment mode');
                    $s['assignment_modes'][(string)$sid]=$mode;
                    sf_event($s,$sid,null,$uid,'assignment_policy_changed',$mode);
                    return [200,['mode'=>$mode]];
                }
            }
            if($kind==='tasks') {
                $s['tasks']=$s['tasks']??[];
                if($method==='GET' && $rid===null){
                    $visibleIds=array_column($visible,'id');$result=[];
                    foreach($s['tasks'] as $task)if($task['school_id']===$sid && in_array($task['lead_id'],$visibleIds,true))$result[]=sf_task_present($task,$s);
                    usort($result,static fn($a,$b)=>strcmp($a['due_date'],$b['due_date']));
                    return [200,array_slice($result,0,2000)];
                }
                if($method==='POST'&&$rid===null){
                    $lid=(int)($payload['lead_id']??0);$lead=sf_lead_allowed($s,$sid,$lid,$uid,$role);
                    $kindValue=sf_text($payload,'kind',40,3);
                    $date=sf_date($payload['due_date']??null);
                    return [201,sf_task_present(sf_task_schedule($s,$lead,$kindValue,$date,$uid),$s)];
                }
                if($method==='PATCH'&&$rid!==null) {
                    $status=sf_text($payload,'status',20,4);
                    if(!in_array($status,SF_TASK_STATUSES,true))sf_fail(422,'Invalid task status');
                    foreach($s['tasks'] as &$task)if($task['id']===$rid && $task['school_id']===$sid){
                        sf_lead_allowed($s,$sid,$task['lead_id'],$uid,$role);
                        if($role==='Counsellor'&&($task['assigned_to']??null)!==$uid)sf_fail(403,'Task is not assigned to you');
                        $task['status']=$status;$task['updated_at']=sf_time();
                        if($status==='Completed')$task['completed_at']=sf_time();
                        $saved=$task;unset($task);
                        sf_event($s,$sid,$saved['lead_id'],$uid,'task_status_changed',$status.' · '.$saved['kind']);
                        return [200,sf_task_present($saved,$s)];
                    }unset($task);
                    sf_fail(404,'Task not found');
                }
                sf_fail(405,'Task method not allowed');
            }
            if($kind==='applications') {
                $s['applications']=$s['applications']??[];
                if($method==='GET'&&$rid===null){
                    $ids=array_column($visible,'id');
                    return [200,array_values(array_filter($s['applications'],fn($a)=>$a['school_id']===$sid&&in_array($a['lead_id'],$ids,true)))];
                }
                if($method==='POST'&&$rid===null){
                    $lid=(int)($payload['lead_id']??0);$lead=sf_lead_allowed($s,$sid,$lid,$uid,$role);
                    if(sf_application_for_lead($s,$sid,$lid)!==null)sf_fail(409,'Application already exists');
                    $application=['id'=>sf_id($s,'applications'),'guid'=>sf_uuid(),'school_id'=>$sid,
                        'lead_id'=>$lid,'status'=>'Started','fee_recorded'=>false,'created_at'=>sf_time(),'updated_at'=>sf_time()];
                    $s['applications'][]=$application;
                    foreach($s['leads'] as &$row)if($row['id']===$lid){$row['status']='Application Started';$row['updated_at']=sf_time();break;}unset($row);
                    sf_event($s,$sid,$lid,$uid,'application_started','Application record created');
                    return [201,$application];
                }
                if($method==='PATCH'&&$rid!==null){
                    $status=sf_text($payload,'status',30,3);
                    if(!in_array($status,SF_APPLICATION_STATUSES,true))sf_fail(422,'Invalid application status');
                    foreach($s['applications'] as &$app)if($app['id']===$rid&&$app['school_id']===$sid){
                        sf_lead_allowed($s,$sid,$app['lead_id'],$uid,$role);
                        $fees=(bool)($app['fee_recorded']??false);
                        if(array_key_exists('fee_recorded',$payload)){
                            sf_membership($s,$sid,$uid,['Admin','Admissions']);
                            if(!is_bool($payload['fee_recorded']))sf_fail(422,'Fee confirmation must be boolean');
                            $fees=$payload['fee_recorded'];
                        }
                        if($status==='Enrolled' && (!$fees || !in_array($app['status'],['Admission Confirmed','Enrolled'],true)))
                            sf_fail(422,'Admission must be confirmed and fee recorded before enrollment');
                        if($status==='Enrolled' && $app['status']!=='Enrolled')sf_capacity_check_enrollment($s,sf_find($s['leads'],$app['lead_id']));
                        $app['status']=$status;$app['fee_recorded']=$fees;$app['updated_at']=sf_time();
                        $saved=$app;unset($app);
                        $stages=['Started'=>'Application Started','Submitted'=>'Application Submitted',
                            'Documents Pending'=>'Documents Pending','Under Review'=>'Under Review',
                            'Offer Issued'=>'Offer Issued','Admission Confirmed'=>'Admission Confirmed','Enrolled'=>'Enrolled'];
                        foreach($s['leads'] as &$row)if($row['id']===$saved['lead_id']){
                            $previousStage=$row['status'];if(isset($stages[$status]))$row['status']=$stages[$status];
                            if($previousStage!==$row['status'])sf_stage_history_add($s,$row,$previousStage,$row['status'],$uid);
                            if($status==='Submitted')sf_admission_recommend($row,'Hot','Application formally submitted','application');
                            $row['updated_at']=sf_time();break;
                        }unset($row);
                        sf_event($s,$sid,$saved['lead_id'],$uid,'application_status_changed',$status.($fees?' · fee verified':''));
                        if($status==='Submitted')sf_task_schedule($s,sf_find($s['leads'],$saved['lead_id']),'Application Review',sf_admission_today(),$uid);
                        if($status==='Enrolled')sf_task_cancel_for_closed_lead($s,$sid,$saved['lead_id'],$uid);
                        return [200,$saved];
                    }unset($app);
                    sf_fail(404,'Application not found');
                }
                sf_fail(405,'Application method not allowed');
            }
            if($kind==='families') {
                if($method==='GET' && $rid===null) {
                    $family=[];foreach($visible as $l){$key=sf_parent_key($l);if($key!==''&&!isset($family[$key]))$family[$key]=sf_family_view($s,$sid,$l,$uid,$role);}
                    return [200,array_values($family)];
                }
                if($method==='POST'&&$rid!==null&&($ext[4]??'')==='siblings') {
                    $parent=sf_lead_allowed($s,$sid,$rid,$uid,$role);
                    $p=['student_name'=>sf_text($payload,'student_name',140,2),
                        'parent_name'=>$parent['guardian'],'phone'=>$parent['phone'],
                        'email'=>$parent['email'],'grade'=>sf_text($payload,'grade',80),
                        'message'=>'Sibling enquiry','consent'=>$parent['consent']];
                    $created=sf_create_lead($s,$sid,$p,$parent['site_id'],$uid,$parent['assigned_to'],'sibling_enquiry_created');
                    sf_family_explicit_sibling($s,$parent,$created['id'],$uid);
                    $created=sf_lead_view($s,sf_find($s['leads'],$created['id']));
                    sf_event($s,$sid,$parent['id'],$uid,'sibling_linked','Sibling enquiry #'.$created['id']);
                    return [201,$created];
                }
            }
            if($kind==='visits') {
                $s['visits']=$s['visits']??[];
                if($method==='GET'&&$rid===null) {
                    $visibleIds=array_column($visible,'id');$rows=[];
                    foreach($s['visits'] as $v)if($v['school_id']===$sid&&in_array($v['lead_id'],$visibleIds,true))$rows[]=sf_visit_view($v,$s);
                    usort($rows,fn($a,$b)=>strcmp($a['starts_at'],$b['starts_at']));
                    return [200,array_slice($rows,0,2000)];
                }
                if($method==='POST'&&$rid===null){
                    $lid=(int)($payload['lead_id']??0);$l=sf_lead_allowed($s,$sid,$lid,$uid,$role);
                    $start=sf_valid_datetime(sf_text($payload,'starts_at',16,16));
                    $staff=sf_validate_assignment($s,$sid,$payload['staff_id']??($l['assigned_to']??null));
                    if($role==='Counsellor'&&$staff!==$uid)sf_fail(403,'Counsellors cannot book another staff member');
                    $visit=['id'=>sf_id($s,'visits'),'school_id'=>$sid,'lead_id'=>$lid,'staff_id'=>$staff,
                        'starts_at'=>$start,'purpose'=>sf_text($payload,'purpose',120,2),
                        'status'=>'Scheduled','created_at'=>sf_time()];
                    $s['visits'][]=$visit;sf_task_schedule($s,$l,'Campus Visit',substr($start,0,10),$uid);
                    foreach($s['leads'] as &$row)if($row['id']===$lid){sf_admission_recommend($row,'Warm','Campus visit booked; intent needs confirmation','visit');break;}unset($row);
                    sf_event($s,$sid,$lid,$uid,'visit_booked',$start.' · '.$visit['purpose']);
                    return [201,sf_visit_view($visit,$s)];
                }
                if($method==='PATCH'&&$rid!==null){
                    foreach($s['visits'] as &$v)if($v['id']===$rid&&$v['school_id']===$sid){
                        sf_lead_allowed($s,$sid,$v['lead_id'],$uid,$role);
                        $status=sf_text($payload,'status',30,3);
                        if(!in_array($status,['Scheduled','Completed','No Show','Cancelled'],true))sf_fail(422,'Invalid visit status');
                        $v['status']=$status;
                        $v['feedback']=sf_text($payload,'feedback',600);
                        $v['updated_by']=$uid;$v['updated_at']=sf_time();
                        $visitLeadId=(int)$v['lead_id'];
                        $savedVisit=$v;unset($v);
                        sf_event($s,$sid,$visitLeadId,$uid,'visit_status_changed',$status);
                        if(array_key_exists('next_followup_date',$payload) && $payload['next_followup_date']!==''){
                            $date=sf_date($payload['next_followup_date']);
                            foreach($s['leads'] as &$row)if($row['id']===$visitLeadId){
                                $row['followup_date']=$date;$row['updated_at']=sf_time();
                                $copy=$row;break;
                            }unset($row);
                            if(isset($copy))sf_task_sync_followup($s,$copy,$uid);
                        }
                        sf_task_resolve_visit($s,$sid,$visitLeadId,$status,$uid);
                        return [200,sf_visit_view($savedVisit,$s)];
                    }unset($v);sf_fail(404,'Visit not found');
                }
            }
            if($kind==='templates') {
                $s['email_templates']=$s['email_templates']??[];
                if($method==='GET'&&$rid===null){
                    $r=array_values(array_filter($s['email_templates'],fn($t)=>$t['school_id']===$sid));
                    if(!$r)$r=[['id'=>0,'school_id'=>$sid,'title'=>'Admissions follow-up','subject'=>'Following up on your school enquiry',
                        'body'=>'Hello {parent},\n\nThank you for your interest in {school} for {student}. Please reply if you would like to schedule a visit.\n\nAdmissions Team']];
                    return [200,$r];
                }
                sf_membership($s,$sid,$uid,['Admin']);
                if($method==='POST'&&$rid===null){
                    $t=['id'=>sf_id($s,'email_templates'),'school_id'=>$sid,'title'=>sf_text($payload,'title',80,2),
                        'subject'=>sf_text($payload,'subject',180,2),'body'=>sf_text($payload,'body',4000,2)];
                    $s['email_templates'][]=$t;sf_event($s,$sid,null,$uid,'email_template_created','Template #'.$t['id']);return [201,$t];
                }
                if($method==='PATCH'&&$rid!==null){
                    foreach($s['email_templates'] as &$t)if($t['school_id']===$sid&&$t['id']===$rid){
                        foreach(['title'=>80,'subject'=>180,'body'=>4000] as $f=>$max)if(array_key_exists($f,$payload))$t[$f]=sf_text($payload,$f,$max,2);
                        sf_event($s,$sid,null,$uid,'email_template_updated','Template #'.$rid);return [200,$t];
                    }unset($t);sf_fail(404,'Template not found');
                }
            }
            if($kind==='analytics'&&$method==='GET'){
                $total=count($visible);$enrolled=0;$pending=0;$due=0;$stage=[];$source=[];$byStaff=[];
                foreach($visible as $l){
                    $stage[$l['status']]=($stage[$l['status']]??0)+1;
                    $source[(string)($l['site_id']??'manual')]=($source[(string)($l['site_id']??'manual')]??0)+1;
                    $byStaff[(string)($l['assigned_to']??'unassigned')]=($byStaff[(string)($l['assigned_to']??'unassigned')]??0)+1;
                    if($l['status']==='Enrolled')$enrolled++;
                    if(in_array($l['status'],['New','New Enquiry'],true)&&empty($l['last_successful_contact_at']))$pending++;
                    if(!empty($l['followup_date'])&&$l['followup_date']<=sf_admission_today()&&!in_array($l['status'],['Lost','Enrolled'],true))$due++;
                }
                return [200,array_merge(['total'=>$total,'enrolled'=>$enrolled,'conversion_rate'=>$total?round($enrolled*100/$total,1):0,
                    'first_contact_pending'=>$pending,'followups_due'=>$due,'open_tasks'=>count(array_filter($s['tasks']??[],fn($t)=>$t['school_id']===$sid&&$t['status']==='Open'&&in_array($t['lead_id'],array_column($visible,'id'),true))), 'by_stage'=>$stage,'by_site'=>$source,'by_staff'=>$byStaff], sf_analytics_enrichment($s,$visible,$sid),sf_sla_summary($s,$sid,$visible))];
            }
            sf_fail(404,'Not found');
        }
        if (!preg_match('#^/api/schools/(\d+)/(members|sites|leads|demo-data|audit)(?:/(\d+|[0-9a-fA-F-]{36}|seed|clear))?(?:/(rotate-key|notes|audit|view|calls|contact-action))?$#D',$path,$m)) sf_fail(404,'Not found');
        $schoolId=(int)$m[1];$area=$m[2];
        $itemRef=$m[3]??'';
        $itemId=$itemRef!==''&&ctype_digit($itemRef)?(int)$itemRef:null;
        if($area==='leads'&&$itemRef!==''&&$itemId===null)
            $itemId=sf_record_id($s,'leads',$itemRef,$schoolId);
        $sub=$m[4]??'';
        $role=sf_membership($s,$schoolId,$uid);
        if ($area==='audit') {
            if ($itemId!==null) sf_fail(404,'Not found');
            if ($method==='POST' && ($payload['event']??'')==='export_enquiries') {
                sf_event($s,$schoolId,null,$uid,'enquiries_exported','Exported school enquiry CSV');
                return [200,['ok'=>true]];
            }
            sf_membership($s,$schoolId,$uid,['Admin']);
            if ($method!=='GET') sf_fail(404,'Not found');
            return [200,sf_audit_page($s,$schoolId,null,sf_audit_cursor($uri))];
        }
        if ($area==='demo-data') {
            sf_membership($s,$schoolId,$uid,['Admin']);
            $demoAction=$m[3]??'';
            if ($demoAction==='' && $method==='GET') return [200,sf_demo_status($s,$schoolId)];
            if ($demoAction==='seed' && $method==='POST') {
                $result=sf_demo_seed($s,$schoolId,$uid);
                sf_event($s,$schoolId,null,$uid,'demo_seeded','Added '.$result['leads'].' sample enquiries and '.$result['sites'].' sample sites');
                return [201,$result];
            }
            if ($demoAction==='clear' && $method==='POST') {
                if (($payload['confirm']??null)!=='CLEAR DEMO') sf_fail(422,'Type CLEAR DEMO to confirm');
                // Import legacy history before demo activities are removed.
                if (!isset($s['audit_logs'])) sf_event($s,$schoolId,null,$uid,'audit_upgraded','Audit history initialized');
                $result=sf_demo_clear($s,$schoolId);
                sf_event($s,$schoolId,null,$uid,'demo_cleared','Removed '.$result['removed_leads'].' sample enquiries and '.$result['removed_sites'].' sample sites');
                return [200,$result];
            }
            sf_fail(404,'Not found');
        }
        if ($area==='members' && $itemId===null) {
            if($method==='GET')return [200,sf_staff_view($s,$schoolId)];
            if($method==='POST'){
                sf_membership($s,$schoolId,$uid,['Admin']);
                $name=sf_text($payload,'name',140,2);$email=sf_email(sf_text($payload,'email',254,3));
                $newRole=sf_text($payload,'role',30,3);
                if(!in_array($newRole,SF_ROLES,true))sf_fail(422,'Invalid staff role');
                foreach($s['users'] as $existing)if(strcasecmp($existing['email'],$email)===0)
                    sf_fail(409,'Email already registered. Linking accounts across schools requires separate verification');
                $pass=sf_text($payload,'password',1024,12);
                $new=['id'=>sf_id($s,'users'),'guid'=>sf_uuid(),'name'=>$name,'email'=>$email,
                    'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'active'=>true,'created_at'=>sf_time()];
                $s['users'][]=$new;
                $s['memberships'][]=['guid'=>sf_uuid(),'school_id'=>$schoolId,'user_id'=>$new['id'],'role'=>$newRole,'enabled'=>true];
                sf_event($s,$schoolId,null,$uid,'member_added','Staff #'.$new['id'].' role '.$newRole);
                return [201,['id'=>$new['id'],'name'=>$name,'email'=>$email,'role'=>$newRole,'enabled'=>true]];
            }
        }
        if ($area==='sites') {
            if ($itemId===null && $method==='GET') {
                $rows=[];
                foreach ($s['sites'] as $site) if ($site['school_id']===$schoolId) {
                    $count=0;foreach ($s['leads'] as $lead) if ($lead['site_id']===$site['id'])$count++;
                    $view=$site;unset($view['key_hash']);$view['lead_count']=$count;$rows[]=$view;
                }
                usort($rows,fn($a,$b)=>$b['id']<=>$a['id']);return [200,$rows];
            }
            sf_membership($s,$schoolId,$uid,['Admin']);
            if ($itemId===null && $method==='POST') {
                $name=sf_text($payload,'name',140,2);$domain=sf_text($payload,'domain',180,3);$platform=sf_text($payload,'platform',60) ?: 'WordPress';
                $key='sfsite_'.bin2hex(random_bytes(32));
                $site=['id'=>sf_id($s,'sites'),'guid'=>sf_uuid(),'school_id'=>$schoolId,'name'=>$name,'domain'=>$domain,'platform'=>$platform,
                    'key_hash'=>sf_hash($key),'enabled'=>1,'created_at'=>sf_time()];
                $s['sites'][]=$site;
                sf_event($s,$schoolId,null,$uid,'website_connected','Source #'.$site['id'].' connected');
                return [201,['id'=>$site['id'],'name'=>$name,'api_key'=>$key,'warning'=>'Copy this key now. It cannot be shown again.']];
            }
            if ($itemId!==null) {
                $site=sf_source($s,$itemId);
                if (!$site || $site['school_id']!==$schoolId) sf_fail(404,'Website not found');
                if(!empty($site['is_group_route'])) sf_fail(403,'Manage group sources from Schools & Routing');
                foreach ($s['sites'] as &$v) if ($v['id']===$itemId) {
                    if ($sub==='rotate-key' && $method==='POST') {
                        $key='sfsite_'.bin2hex(random_bytes(32));$v['key_hash']=sf_hash($key);
                        sf_event($s,$schoolId,null,$uid,'website_key_rotated','Source #'.$itemId.' key rotated');
                        return [200,['api_key'=>$key,'warning'=>'Old key revoked. Copy the new key now.']];
                    }
                    if ($sub==='audit' && $method==='GET') return [200,sf_audit_page($s,$schoolId,$itemId,sf_audit_cursor($uri))];
                if ($sub==='view' && $method==='POST') {
                    sf_event($s,$schoolId,$itemId,$uid,'enquiry_viewed','Opened enquiry details');
                    return [200,['ok'=>true]];
                }
                if ($sub==='' && $method==='PATCH') {
                        $query=parse_url($uri,PHP_URL_QUERY);
                        parse_str(is_string($query)?$query:'',$siteQuery);
                        $enabled=$siteQuery['enabled']??null;
                        if(array_key_exists('enabled',$payload)){
                            if(!is_bool($payload['enabled']))sf_fail(422,'enabled must be boolean');
                            $enabled=$payload['enabled']?'true':'false';
                        }
                        if($enabled!==null){
                            if(!in_array($enabled,['true','false','1','0'],true))sf_fail(422,'Invalid enabled value');
                            $v['enabled']=in_array($enabled,['true','1'],true)?1:0;
                        }
                        $changed=false;
                        foreach(['name'=>140,'domain'=>180,'platform'=>60] as $field=>$max)if(array_key_exists($field,$payload)){
                            $value=sf_text($payload,$field,$max,$field==='platform'?1:2);
                            $v[$field]=$value;$changed=true;
                        }
                        if($enabled===null && !$changed)sf_fail(422,'Provide website details or enabled status');
                        $v['updated_at']=sf_time();
                        sf_event($s,$schoolId,null,$uid,$changed?'website_updated':'website_status_changed',
                            'Source #'.$itemId.' '.($v['enabled']?'active':'disabled'));
                        return [200,['ok'=>true,'id'=>$itemId,'enabled'=>(bool)$v['enabled'],
                            'name'=>$v['name'],'domain'=>$v['domain'],'platform'=>$v['platform']]];
                    }
                } unset($v);
            }
        }
        if ($area==='leads') {
            if ($itemId===null && $method==='GET') {
                $rows=[];
                foreach ($s['leads'] as $lead) if ($lead['school_id']===$schoolId && ($role!=='Counsellor'||$lead['assigned_to']===$uid)) {
                    $rows[]=sf_lead_view($s,$lead);
                }
                usort($rows,fn($a,$b)=>$b['id']<=>$a['id']);return [200,array_slice($rows,0,2000)];
            }
            if ($itemId===null && $method==='POST') {
                $siteId=$payload['site_id']??null;$siteId=$siteId===null?null:(int)$siteId;
                if ($siteId!==null) {
                    $site=sf_source($s,$siteId);
                    if (!$site || $site['school_id']!==$schoolId) sf_fail(422,'Website does not belong to this school');
                }
                $assigned=sf_validate_assignment($s,$schoolId,$payload['assigned_to']??null);
                if ($role==='Counsellor' && $assigned!==null && $assigned!==$uid) sf_fail(403,'Counsellors cannot assign to others');
                if ($role==='Counsellor') $assigned=$uid;
                return [201,sf_create_lead($s,$schoolId,$payload,$siteId,$uid,$assigned,'manual_create')];
            }
            if ($itemId!==null) {
                $lead=sf_lead_allowed($s,$schoolId,$itemId,$uid,$role);
                if ($sub==='' && $method==='GET') {
                    $activities=[];
                    foreach ($s['activities'] as $a) if ($a['lead_id']===$itemId && $a['school_id']===$schoolId) {
                        $actor=$a['actor_id']?sf_user($s,$a['actor_id']):null;
                        $a['actor_name']=$actor['name']??null;$activities[]=$a;
                    }
                    usort($activities,fn($a,$b)=>$b['id']<=>$a['id']);
                    $calls=array_values(array_filter($s['calls']??[],fn($v)=>$v['school_id']===$schoolId&&$v['lead_id']===$itemId));
                    $messages=array_values(array_filter($s['message_attempts']??[],fn($v)=>$v['school_id']===$schoolId&&$v['lead_id']===$itemId));
                    $messages=array_map(static function($x){unset($x['request_id']);return $x;},$messages);
                    $visits=array_values(array_filter($s['visits']??[],fn($v)=>$v['school_id']===$schoolId&&$v['lead_id']===$itemId));
                    usort($calls,fn($a,$b)=>$b['id']<=>$a['id']);
                    return [200,['lead'=>sf_lead_view($s,$lead),'family'=>sf_family_view($s,$schoolId,$lead,$uid,$role),
                        'calls'=>array_slice($calls,0,50),'messages'=>array_slice(array_reverse($messages),0,20),'visits'=>array_map(fn($v)=>sf_visit_view($v,$s),$visits),
                        'activities'=>array_slice($activities,0,100),
                        'stage_history'=>array_values(array_filter($s['stage_history']??[],fn($h)=>$h['school_id']===$schoolId&&$h['lead_id']===$itemId)),
                        'documents'=>sf_doc_list($s,$schoolId,$itemId),
                        'audit'=>sf_audit_page($s,$schoolId,$itemId,sf_audit_cursor($uri))]];
                }
                if ($sub==='audit' && $method==='GET') return [200,sf_audit_page($s,$schoolId,$itemId,sf_audit_cursor($uri))];
                if ($sub==='view' && $method==='POST') {
                    sf_event($s,$schoolId,$itemId,$uid,'enquiry_viewed','Opened enquiry details');
                    return [200,['ok'=>true]];
                }
                if ($sub==='' && $method==='PATCH') {
                    $changes=[];
                    foreach (['status','assigned_to','followup_date'] as $k) if (array_key_exists($k,$payload)) $changes[$k]=$payload[$k];
                    $changes=array_merge($changes,sf_admission_extra_changes($payload));
                    if(array_key_exists('deferred_until',$payload))$changes['deferred_until']=sf_date($payload['deferred_until']);
                    if(($changes['status']??'')==='On Hold / Deferred' && !empty($changes['deferred_until']))$changes['followup_date']=$changes['deferred_until'];
                    foreach(['consent_email','consent_whatsapp'] as $ch)if(array_key_exists($ch,$payload)){
                        if(!is_bool($payload[$ch]))sf_fail(422,'Channel consent must be boolean');
                        if($payload[$ch] && (empty($lead['consent'])||!empty($lead['is_demo'])))sf_fail(422,'Parent contact consent is required');
                        $reason=sf_text($payload,'consent_source',160,8);
                        $changes[$ch]=$payload[$ch];
                        $changes[$ch.'_recorded_at']=sf_time();
                        $changes[$ch.'_source']=$reason;
                    }

                    if (!$changes) sf_fail(422,'Nothing to update');
                    if (array_key_exists('status',$changes) && !in_array($changes['status'],SF_STAGES,true)) sf_fail(422,'Invalid stage');
                    if (($changes['status']??'')==='On Hold / Deferred' && empty($changes['deferred_until']) && empty($lead['deferred_until']))sf_fail(422,'Deferred review date required');
                    if(($lead['status']??'')==='Enrolled' && array_key_exists('academic_year',$changes) && $changes['academic_year']!==($lead['academic_year']??''))sf_fail(409,'Reopen admission before changing an enrolled academic year');
                    if (($changes['status']??'')==='Lost' && $lead['status']!=='Lost' && empty($changes['lost_reason']) && empty($lead['lost_reason']))sf_fail(422,'Lost reason required');
                    if (($changes['status']??'')==='Enrolled' && $lead['status']!=='Enrolled' && !sf_application_can_enrol($s,$schoolId,$itemId))sf_fail(422,'Confirmed application and recorded fee required before enrollment');
                    if (($changes['status']??'')==='Enrolled' && $lead['status']!=='Enrolled')sf_capacity_check_enrollment($s,$lead);
                    if (array_key_exists('followup_date',$changes)) $changes['followup_date']=sf_date($changes['followup_date']);
                    if (array_key_exists('assigned_to',$changes)) {
                        $changes['assigned_to']=sf_validate_assignment($s,$schoolId,$changes['assigned_to']);
                        if ($role==='Counsellor' && $changes['assigned_to']!==$uid) sf_fail(403,'Counsellors cannot reassign leads');
                    }
                    foreach ($s['leads'] as &$l) if ($l['id']===$itemId) {
                        $previousStage=$l['status'];
                        foreach ($changes as $k=>$v) if (($l[$k]??null)!==$v) {sf_audit($s,$schoolId,$itemId,$uid,'update_'.$k,($k==='temperature_reason'||str_ends_with($k,'_source'))?'Reason/source recorded':(($l[$k]??'(none)').' → '.($v??'(none)')));$l[$k]=$v;}
                        $l['updated_at']=sf_time();$updated=$l;
                        sf_stage_history_add($s,$l,$previousStage,$l['status'],$uid);break;
                    } unset($l);
                    if(array_key_exists('assigned_to',$changes) && ($lead['assigned_to']??null)!==($updated['assigned_to']??null))
                        sf_task_reassign_open($s,$schoolId,$itemId,$updated['assigned_to']??null,$uid);
                    if(array_key_exists('followup_date',$changes))sf_task_sync_followup($s,$updated,$uid);
                    if(($changes['status']??'')==='On Hold / Deferred' && !empty($updated['deferred_until']))sf_task_schedule($s,$updated,'Follow-up',$updated['deferred_until'],$uid);
                    if(in_array($updated['status'],['Lost','Enrolled','Not Eligible','Duplicate / Invalid'],true))sf_task_cancel_for_closed_lead($s,$schoolId,$itemId,$uid);
                    return [200,sf_lead_view($s,$updated)];
                }
                if ($sub==='contact-action' && $method==='POST') {
                    $channel=sf_text($payload,'channel',16,3);
                    if(!in_array($channel,['phone','whatsapp','email'],true))sf_fail(422,'Invalid communication channel');
                    if($channel!=='phone'&&!$lead['consent'])sf_fail(403,'Guardian contact consent required');
                    if($channel==='email'&&!filter_var($lead['email'],FILTER_VALIDATE_EMAIL))sf_fail(422,'Guardian email required');
                    sf_event($s,$schoolId,$itemId,$uid,'communication_link_opened',$channel.' action opened (delivery not confirmed)');
                    return [200,['ok'=>true]];
                }
                if($sub==='calls' && $method==='POST') {
                    $outcome=sf_text($payload,'outcome',30,2);
                    if(!in_array($outcome,['Answered','No Answer','Busy','Interested','Not Interested','Callback Requested'],true))sf_fail(422,'Invalid call outcome');
                    $next=sf_date($payload['followup_date']??null);
                    $note=sf_text($payload,'note',1000);
                    $s['calls']=$s['calls']??[];
                    $entry=['id'=>sf_id($s,'calls'),'school_id'=>$schoolId,'lead_id'=>$itemId,
                        'actor_id'=>$uid,'outcome'=>$outcome,'note'=>$note,'created_at'=>sf_time(), 'followup_date'=>$next];
                    $s['calls'][]=$entry;
                    foreach($s['leads'] as &$v)if($v['id']===$itemId){
                        $v['last_call_outcome']=$outcome;sf_admission_call_update($v,$outcome,sf_time());
                        if($outcome==='Interested')sf_admission_recommend($v,'Warm','Parent confirmed interest','call');
                        if($outcome==='Not Interested')sf_admission_recommend($v,'Cold','Parent explicitly declined interest','call');
                        if($outcome==='Callback Requested')sf_admission_recommend($v,'Warm','Parent requested a callback','call');
                        if($next!=='')$v['followup_date']=$next;
                        $v['updated_at']=sf_time();$updatedLead=$v;break;
                    }unset($v);
                    if(!empty($updatedLead['last_successful_contact_at']))sf_task_complete_kind($s,$schoolId,$itemId,'First Contact',$uid);
                    if($next!=='')sf_task_sync_followup($s,$updatedLead,$uid);
                    elseif(in_array($outcome,['No Answer','Busy'],true))sf_task_auto_retry($s,$schoolId,$itemId,$uid);
                    sf_event($s,$schoolId,$itemId,$uid,'call_logged',$outcome.($next?' · next '.$next:''));
                    return [201,$entry];
                }
                if ($sub==='notes' && $method==='POST') {
                    $note=sf_text($payload,'text',2000,1);
                    sf_audit($s,$schoolId,$itemId,$uid,'note',$note);
                    return [201,['ok'=>true]];
                }
            }
        }
        sf_fail(404,'Not found');
    },$write);
}
