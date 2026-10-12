<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/app_private/api.php';
if ($argc < 4) {
    fwrite(STDERR,"Usage: php bin/create-admin.php admin@example.com 'Admin Name' 'School Name'\n");
    fwrite(STDERR,"Set SCHOOLFLOW_ADMIN_PASSWORD in the environment (12+ characters).\n");
    exit(2);
}
$email=sf_email($argv[1]);$name=trim($argv[2]);$school=trim($argv[3]);
// Support 3 args after executable. No password on command line to avoid process list/history exposure.
$password=getenv('SCHOOLFLOW_ADMIN_PASSWORD');
if ($password===false || strlen($password)<12 || strlen($password)>1024) {fwrite(STDERR,"Set SCHOOLFLOW_ADMIN_PASSWORD to a password of 12+ characters.\n");exit(2);}
if (strlen($name)<2 || strlen($school)<3) {fwrite(STDERR,"Name or school name is too short.\n");exit(2);}
try {
    $s=new SchoolFlowStore();
    $result=$s->transaction(function (&$db) use ($email,$name,$school,$password) {
        if ($db['users']) throw new RuntimeException('Setup already complete; cannot create a second bootstrap administrator');
        $user=['id'=>sf_id($db,'users'),'name'=>$name,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'active'=>true,'created_at'=>sf_time()];
        $schoolRow=['id'=>sf_id($db,'schools'),'name'=>$school,'created_at'=>sf_time()];
        $db['users'][]=$user;$db['schools'][]=$schoolRow;
        $db['memberships'][]=['school_id'=>$schoolRow['id'],'user_id'=>$user['id'],'role'=>'Admin'];
        return true;
    },true);
    echo "SchoolFlow admin and first school created. Open your Cloudways app URL.\n";
} catch(Throwable $e) {fwrite(STDERR,"Setup failed: ".$e->getMessage()."\n");exit(1);}