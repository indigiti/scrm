<?php
declare(strict_types=1);
require_once __DIR__ . '/store.php';

const SF_STAGES = ['New','Contacted','Counselling','Visit Scheduled','Applied','Enrolled','Lost'];
const SF_ROLES = ['Admin','Admissions','Counsellor'];
const SF_TTL = 43200;

class SFError extends RuntimeException {
    public function __construct(public int $httpStatus, string $message) { parent::__construct($message); }
}
function sf_fail(int $status, string $message): never { throw new SFError($status, $message); }
function sf_time(): string { return gmdate('Y-m-d\TH:i:s+00:00'); }
function sf_hash(string $s): string { return hash('sha256', $s); }
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
        if ($roles && !in_array($m['role'], $roles, true)) sf_fail(403, 'Your role does not permit this action');
        return $m['role'];
    }
    sf_fail(403, 'You are not a member of this school');
}
function sf_user(array $s, int $id): ?array { return sf_find($s['users'], $id); }
function sf_source(array $s, int $id): ?array { return sf_find($s['sites'], $id); }
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
function sf_audit(array &$s, int $schoolId, ?int $leadId, ?int $actorId, string $action, string $details = ''): void {
    $s['activities'][] = ['id'=>sf_id($s,'activities'), 'school_id'=>$schoolId,'lead_id'=>$leadId,
        'actor_id'=>$actorId,'action'=>$action,'details'=>substr($details,0,2000),'created_at'=>sf_time()];
}
function sf_lead_view(array $s, array $lead): array {
    $source = $lead['site_id'] ? sf_source($s, $lead['site_id']) : null;
    $owner = $lead['assigned_to'] ? sf_user($s, $lead['assigned_to']) : null;
    $lead['source_name'] = $source['name'] ?? null;
    $lead['source_domain'] = $source['domain'] ?? null;
    $lead['assigned_name'] = $owner['name'] ?? null;
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
    foreach ($s['memberships'] as $m) if ($m['school_id'] === $schoolId && $m['role'] === 'Counsellor') $loads[$m['user_id']] = 0;
    foreach ($s['leads'] as $lead) if ($lead['school_id'] === $schoolId && isset($loads[$lead['assigned_to'] ?? -1]) && !in_array($lead['status'], ['Enrolled','Lost'], true)) $loads[$lead['assigned_to']]++;
    if (!$loads) return null;
    asort($loads, SORT_NUMERIC);
    return (int)array_key_first($loads);
}
function sf_create_lead(array &$s, int $schoolId, array $p, ?int $siteId, ?int $actor, ?int $assigned, string $action): array {
    $name = sf_text($p,'student_name',140,2);$guardian = sf_text($p,'parent_name',140,2);
    $phone = sf_phone(sf_text($p,'phone',40,6));$norm = preg_replace('/\D/','',$phone);
    $duplicate = null;
    foreach ($s['leads'] as $lead) if ($lead['school_id'] === $schoolId && $lead['normalized_phone'] === $norm) { $duplicate = $lead['id']; break; }
    if ($assigned === null) $assigned = sf_auto_owner($s, $schoolId);
    $lead = ['id'=>sf_id($s,'leads'),'school_id'=>$schoolId,'site_id'=>$siteId,'name'=>$name,'guardian'=>$guardian,
        'phone'=>$phone,'normalized_phone'=>$norm,'email'=>sf_text($p,'email',160),'grade'=>sf_text($p,'grade',80),
        'message'=>sf_text($p,'message',2000),'utm_source'=>sf_text($p,'utm_source',140),'utm_campaign'=>sf_text($p,'utm_campaign',140),
        'status'=>'New','assigned_to'=>$assigned,'followup_date'=>sf_date($p['followup_date'] ?? null),
        'consent'=>!empty($p['consent']) ? 1:0,'duplicate_of'=>$duplicate,'created_at'=>sf_time(),'updated_at'=>sf_time()];
    $s['leads'][] = $lead;
    sf_audit($s,$schoolId,$lead['id'],$actor,$action,$duplicate ? 'Potential duplicate' : '');
    return sf_lead_view($s, $lead);
}
function sf_json(mixed $result, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Route return form: [HTTP status, data]. All mutations run inside exclusive file lock. */
function sf_handle(string $method, string $uri, array $payload, array $headers, string $ip, SchoolFlowStore $store): array {
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    if ($path === '/health' && $method === 'GET') return [200, ['ok'=>true]];
    // Public first-run discovery contains no secrets. Setup is available only for a truly empty store.
    if ($path === '/api/setup/status' && $method === 'GET') {
        return $store->transaction(static function (array $s): array {
            return [200, ['needs_setup' => count($s['users']) === 0,
                          'code_ready' => !empty($s['setup_code_hash']) && count($s['users']) === 0]];
        });
    }
    if ($path === '/api/setup/admin' && $method === 'POST') {
        return $store->transaction(static function (array &$s) use ($payload): array {
            // The exclusive store lock enforces single-use even for concurrent submissions.
            if (!empty($s['users'])) sf_fail(409, 'Setup is already complete. Please sign in.');
            $code = sf_text($payload, 'setup_code', 128, 12);
            if (empty($s['setup_code_hash']) || !hash_equals($s['setup_code_hash'], sf_hash($code))) {
                sf_fail(403, 'Incorrect setup code. Generate it using Cloudways SSH.');
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
            $schoolRow = ['id'=>sf_id($s,'schools'),'name'=>$school,'created_at'=>sf_time()];
            $s['users'][] = $user;
            $s['schools'][] = $schoolRow;
            $s['memberships'][] = ['school_id'=>$schoolRow['id'],'user_id'=>$user['id'],'role'=>'Admin'];
            unset($s['setup_code_hash']);
            $token = bin2hex(random_bytes(40));
            $expires = time() + SF_TTL;
            $s['sessions'][] = ['token_hash'=>sf_hash($token),'user_id'=>$user['id'],'expires_at'=>$expires];
            return [201, ['token'=>$token,'expires_at'=>gmdate('Y-m-d\TH:i:s+00:00',$expires)]];
        }, true);
    }
    if ($path === '/api/auth/login' && $method === 'POST') {
        return $store->transaction(function (&$s) use ($payload,$ip) {
            sf_limit($s, 'login:' . hash('sha256',$ip), 12,900);
            $email=sf_email(sf_text($payload,'email',254,3));
            $pass=sf_text($payload,'password',1024,1);
            $user=null;
            foreach ($s['users'] as $u) if ($u['email']===$email && $u['active']) { $user=$u;break; }
            if (!$user || !password_verify($pass,$user['password_hash'])) return [401, ['detail'=>'Incorrect email or password']];
            $token = bin2hex(random_bytes(40));
            $expires=time()+SF_TTL;
            $s['sessions'] = array_values(array_filter($s['sessions'], fn($v) => $v['expires_at'] > time()));
            $s['sessions'][] = ['token_hash'=>sf_hash($token),'user_id'=>$user['id'],'expires_at'=>$expires];
            return [200, ['token'=>$token,'expires_at'=>gmdate('Y-m-d\TH:i:s+00:00', $expires)]];
        },true);
    }
    if ($path === '/api/v1/inbound-leads' && $method === 'POST') {
        $auth=$headers['authorization']??'';
        if (!str_starts_with($auth,'Bearer sfsite_')) sf_fail(401, 'Website API key required');
        $idem=$headers['idempotency-key']??'';
        if (strlen($idem)<6 || strlen($idem)>160) sf_fail(422,'A unique Idempotency-Key of 6–160 characters is required');
        if (($payload['consent']??false) !== true) sf_fail(422,'Parent/guardian consent required');
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
    return $store->transaction(function (&$s) use ($path,$method,$payload,$auth) {
        $user=sf_session($s,$auth);$uid=$user['id'];
        if ($path==='/api/auth/logout' && $method==='POST') {
            $hash=sf_hash(substr($auth,7));
            $s['sessions']=array_values(array_filter($s['sessions'],fn($ss)=>$ss['token_hash']!==$hash));
            return [200,['ok'=>true]];
        }
        if ($path==='/api/me' && $method==='GET') {
            $schools=[];
            foreach ($s['memberships'] as $m) if ($m['user_id']===$uid) {
                $school=sf_find($s['schools'],$m['school_id']);
                if ($school) $schools[]=['id'=>$school['id'],'name'=>$school['name'],'role'=>$m['role']];
            }
            usort($schools,fn($a,$b)=>strcmp($a['name'],$b['name']));
            return [200,['user'=>['id'=>$uid,'name'=>$user['name'],'email'=>$user['email']],'schools'=>$schools]];
        }
        if ($path==='/api/schools' && $method==='POST') {
            $allowed=false;
            foreach ($s['memberships'] as $m) if ($m['user_id']===$uid && $m['role']==='Admin') $allowed=true;
            if (!$allowed) sf_fail(403,'Only an existing school admin can create a school');
            $name=sf_text($payload,'name',140,3);
            $school=['id'=>sf_id($s,'schools'),'name'=>$name,'created_at'=>sf_time()];
            $s['schools'][]=$school;$s['memberships'][]=['user_id'=>$uid,'school_id'=>$school['id'],'role'=>'Admin'];
            return [201,['id'=>$school['id'],'name'=>$name,'role'=>'Admin']];
        }
        if (!preg_match('#^/api/schools/(\d+)/(members|sites|leads)(?:/(\d+))?(?:/(rotate-key|notes))?$#D',$path,$m)) sf_fail(404,'Not found');
        $schoolId=(int)$m[1];$area=$m[2];$itemId=isset($m[3]) && $m[3]!==''?(int)$m[3]:null;
        $sub=$m[4]??'';
        $role=sf_membership($s,$schoolId,$uid);
        if ($area==='members' && $itemId===null) {
            if ($method==='GET') {
                $rows=[];
                foreach ($s['memberships'] as $member) if ($member['school_id']===$schoolId) {
                    $p=sf_user($s,$member['user_id']);
                    if($p)$rows[]=['id'=>$p['id'],'name'=>$p['name'],'email'=>$p['email'],'role'=>$member['role']];
                }
                usort($rows,fn($a,$b)=>strcmp($a['name'],$b['name']));return [200,$rows];
            }
            if ($method==='POST') {
                sf_membership($s,$schoolId,$uid,['Admin']);
                $name=sf_text($payload,'name',140,2);$email=sf_email(sf_text($payload,'email',254,3));
                $newRole=sf_text($payload,'role',30,3);
                if (!in_array($newRole,SF_ROLES,true)) sf_fail(422,'Invalid staff role');
                foreach ($s['users'] as $p) if ($p['email']===$email) sf_fail(409,'Email already registered; verified multi-school invitations are not yet supported');
                $pass=sf_text($payload,'password',1024,12);
                $new=['id'=>sf_id($s,'users'),'name'=>$name,'email'=>$email,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'active'=>true,'created_at'=>sf_time()];
                $s['users'][]=$new;$s['memberships'][]=['school_id'=>$schoolId,'user_id'=>$new['id'],'role'=>$newRole];
                sf_audit($s,$schoolId,null,$uid,'member_added','user:'.$new['id']);
                return [201,['id'=>$new['id'],'name'=>$name,'email'=>$email,'role'=>$newRole,'existing_account'=>false]];
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
                $site=['id'=>sf_id($s,'sites'),'school_id'=>$schoolId,'name'=>$name,'domain'=>$domain,'platform'=>$platform,
                    'key_hash'=>sf_hash($key),'enabled'=>1,'created_at'=>sf_time()];
                $s['sites'][]=$site;
                return [201,['id'=>$site['id'],'name'=>$name,'api_key'=>$key,'warning'=>'Copy this key now. It cannot be shown again.']];
            }
            if ($itemId!==null) {
                $site=sf_source($s,$itemId);
                if (!$site || $site['school_id']!==$schoolId) sf_fail(404,'Website not found');
                foreach ($s['sites'] as &$v) if ($v['id']===$itemId) {
                    if ($sub==='rotate-key' && $method==='POST') {
                        $key='sfsite_'.bin2hex(random_bytes(32));$v['key_hash']=sf_hash($key);
                        return [200,['api_key'=>$key,'warning'=>'Old key revoked. Copy the new key now.']];
                    }
                    if ($sub==='' && $method==='PATCH') {
                        $enabled=$_GET['enabled']??null;
                        if (!in_array($enabled,['true','false','1','0'],true)) sf_fail(422,'enabled query parameter required');
                        $v['enabled']=in_array($enabled,['true','1'],true)?1:0;
                        return [200,['ok'=>true,'enabled'=>(bool)$v['enabled']]];
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
                    return [200,['lead'=>sf_lead_view($s,$lead),'activities'=>array_slice($activities,0,100)]];
                }
                if ($sub==='' && $method==='PATCH') {
                    $changes=[];
                    foreach (['status','assigned_to','followup_date'] as $k) if (array_key_exists($k,$payload)) $changes[$k]=$payload[$k];
                    if (!$changes) sf_fail(422,'Nothing to update');
                    if (array_key_exists('status',$changes) && !in_array($changes['status'],SF_STAGES,true)) sf_fail(422,'Invalid stage');
                    if (array_key_exists('followup_date',$changes)) $changes['followup_date']=sf_date($changes['followup_date']);
                    if (array_key_exists('assigned_to',$changes)) {
                        $changes['assigned_to']=sf_validate_assignment($s,$schoolId,$changes['assigned_to']);
                        if ($role==='Counsellor' && $changes['assigned_to']!==$uid) sf_fail(403,'Counsellors cannot reassign leads');
                    }
                    foreach ($s['leads'] as &$l) if ($l['id']===$itemId) {
                        foreach ($changes as $k=>$v) if ($l[$k]!==$v) {sf_audit($s,$schoolId,$itemId,$uid,'update_'.$k,($l[$k]??'(none)').' → '.($v??'(none)'));$l[$k]=$v;}
                        $l['updated_at']=sf_time();$updated=$l;break;
                    } unset($l);
                    return [200,sf_lead_view($s,$updated)];
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