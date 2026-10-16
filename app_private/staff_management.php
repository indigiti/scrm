<?php
declare(strict_types=1);
/** School-scoped staff lifecycle; legacy memberships without enabled remain enabled. */
function sf_staff_enabled(array $member):bool { return ($member['enabled']??true)!==false; }
function sf_staff_admin_count(array $s,int $sid):int{
    $n=0;foreach($s['memberships'] as $m)if($m['school_id']===$sid&&$m['role']==='Admin'&&sf_staff_enabled($m)&&
        (sf_user($s,$m['user_id'])['active']??false))$n++;
    return $n;
}
function sf_staff_view(array $s,int $sid):array{
    $out=[];
    foreach($s['memberships'] as $m)if($m['school_id']===$sid){
        $person=sf_user($s,$m['user_id']);if(!$person)continue;
        $out[]=['id'=>$person['id'],'guid'=>$person['guid']??null,'name'=>$person['name'],'email'=>$person['email'],
            'role'=>$m['role'],'enabled'=>sf_staff_enabled($m)&&($person['active']??false),
            'shared_account'=>count(array_filter($s['memberships'],fn($v)=>$v['user_id']===$person['id']))>1];
    }
    usort($out,fn($a,$b)=>strcmp($a['name'],$b['name']));
    return $out;
}
function sf_staff_revoke_sessions(array &$s,int $target):void{
    $s['sessions']=array_values(array_filter($s['sessions'],fn($v)=>(int)$v['user_id']!==$target));
}
function sf_staff_update(array &$s,int $sid,int $actor,int $target,array $p):array{
    sf_membership($s,$sid,$actor,['Admin']);
    $membership=null;
    foreach($s['memberships'] as $m)if($m['school_id']===$sid&&$m['user_id']===$target){$membership=$m;break;}
    if(!$membership)sf_fail(404,'Team member not found in this school');
    $account=sf_user($s,$target);
    if(!$account)sf_fail(404,'Team member not found');
    $enabled=array_key_exists('enabled',$p)?$p['enabled']:sf_staff_enabled($membership);
    if(!is_bool($enabled))sf_fail(422,'enabled must be a boolean');
    $role=array_key_exists('role',$p)?sf_text($p,'role',30,3):$membership['role'];
    if(!in_array($role,SF_ROLES,true))sf_fail(422,'Invalid staff role');
    if($actor===$target && (!$enabled||$role!=='Admin'))sf_fail(409,'Cannot remove your own administrator access');
    if($membership['role']==='Admin' && sf_staff_enabled($membership) && (!$enabled||$role!=='Admin') &&
        sf_staff_admin_count($s,$sid)<=1)sf_fail(409,'Appoint another active administrator first');
    $name=array_key_exists('name',$p)?sf_text($p,'name',140,2):$account['name'];
    $email=array_key_exists('email',$p)?sf_email(sf_text($p,'email',254,3)):$account['email'];
    $globalChange=($name!==$account['name']||$email!==$account['email']);
    $shared=count(array_filter($s['memberships'],fn($m)=>$m['user_id']===$target))>1;
    if($globalChange && $shared)sf_fail(409,'Shared-account identity must be updated by an authorized central account administrator');
    if($email!==$account['email'])foreach($s['users'] as $u)if($u['id']!==$target && strcasecmp($u['email'],$email)===0)
        sf_fail(409,'Email address is already registered');
    $statusChanged=$enabled!==sf_staff_enabled($membership);
    $roleChanged=$role!==$membership['role'];
    foreach($s['users'] as &$u)if($u['id']===$target){$u['name']=$name;$u['email']=$email;$u['updated_at']=sf_time();break;}unset($u);
    foreach($s['memberships'] as &$m)if($m['school_id']===$sid&&$m['user_id']===$target){
        $m['role']=$role;$m['enabled']=$enabled;$m['updated_at']=sf_time();break;
    }unset($m);
    // Leaving an inactive counsellor's leads/tasks assigned is misleading and blocks coverage.
    if(!$enabled || ($roleChanged && $membership['role']==='Counsellor')){
        $moved=0;
        foreach($s['leads'] as &$lead)if($lead['school_id']===$sid && ($lead['assigned_to']??null)===$target &&
            !in_array($lead['status'],['Enrolled','Lost','Not Eligible','Duplicate / Invalid'],true)){
            $lead['assigned_to']=null;$lead['updated_at']=sf_time();$moved++;
        }unset($lead);
        foreach($s['tasks']??[] as &$task)if($task['school_id']===$sid&&($task['assigned_to']??null)===$target &&
            ($task['status']??'')==='Open'){$task['assigned_to']=null;$task['updated_at']=sf_time();}unset($task);
        if($moved)sf_event($s,$sid,null,$actor,'staff_leads_unassigned',$moved.' open enquiries available for reassignment');
    }
    if($statusChanged||$roleChanged||$email!==$account['email'])sf_staff_revoke_sessions($s,$target);
    sf_event($s,$sid,null,$actor,'member_updated','Staff #'.$target.' role '.$role.', access '.($enabled?'enabled':'disabled'));
    foreach(sf_staff_view($s,$sid) as $row)if($row['id']===$target)return $row;
    sf_fail(500,'Unable to read updated team member');
}
function sf_staff_reset_password(array &$s,int $sid,int $actor,int $target):array {
    sf_membership($s,$sid,$actor,['Admin']);
    if($target===$actor)sf_fail(409,'Use Change My Password for your own account');
    $membership=false;
    foreach($s['memberships'] as $m)if($m['school_id']===$sid&&$m['user_id']===$target){$membership=true;break;}
    if(!$membership)sf_fail(404,'Member not found in this school');
    if(count(array_filter($s['memberships'],fn($v)=>$v['user_id']===$target))!==1)
        sf_fail(409,'Shared-account passwords cannot be reset by one school');
    $temporary=bin2hex(random_bytes(12)).'!aA1';
    foreach($s['users'] as &$user)if($user['id']===$target){
        $user['password_hash']=password_hash($temporary,PASSWORD_DEFAULT);
        $user['must_change_password']=true;$user['updated_at']=sf_time();break;
    }unset($user);
    sf_staff_revoke_sessions($s,$target);
    sf_event($s,$sid,null,$actor,'member_password_reset','Temporary password issued for staff #'.$target.'; sessions revoked');
    return ['temporary_password'=>$temporary,'must_change_password'=>true,'warning'=>'Copy once and give it to the staff member securely. Change required at first login.'];
}
function sf_staff_change_own_password(array &$s,int $uid,array $payload):array {
    $current=sf_text($payload,'current_password',1024,1);
    $replacement=sf_text($payload,'new_password',1024,12);
    $user=sf_user($s,$uid);
    if(!$user||!password_verify($current,$user['password_hash']))sf_fail(403,'Current password is incorrect');
    if(password_verify($replacement,$user['password_hash']))sf_fail(422,'Choose a different password');
    foreach($s['users'] as &$row)if($row['id']===$uid){
        $row['password_hash']=password_hash($replacement,PASSWORD_DEFAULT);
        $row['must_change_password']=false;$row['updated_at']=sf_time();break;
    }unset($row);
    sf_staff_revoke_sessions($s,$uid);
    foreach($s['memberships'] as $m)if($m['user_id']===$uid)
        sf_event($s,$m['school_id'],null,$uid,'own_password_changed','Password changed; all sessions revoked');
    return ['ok'=>true,'sessions_revoked'=>true];
}
