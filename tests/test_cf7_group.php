<?php
declare(strict_types=1);
function add_action($a,$b):void{}
function add_filter($a,$b,$p=10,$n=1):void{}
function sanitize_text_field($s):string{return trim((string)$s);}
function sanitize_key($s):string{return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$s));}
function untrailingslashit($s):string{return rtrim($s,'/');}
function wp_generate_uuid4():string{return 'uuid-test-abc';}
function wp_json_encode($s):string{return json_encode($s);}
$GLOBALS['requests']=[];
function wp_remote_get($url,$args):array{ $GLOBALS['requests'][]=['GET',$url,$args];return ['response'=>['code'=>200],'body'=>json_encode(['schools'=>[['code'=>'campus-1','name'=>'ABC Wakad'],['code'=>'campus-2','name'=>'ABC Baner']]])];}
function wp_remote_post($url,$args):array{$GLOBALS['requests'][]=['POST',$url,$args];return ['response'=>['code'=>201]];}
function wp_remote_retrieve_body($r):string{return $r['body'];}
function wp_remote_retrieve_response_code($r):int{return $r['response']['code'];}
function is_wp_error($r):bool{return false;}
class WPCF7_Submission{
 public static ?self $instance=null;
 public static function get_instance():?self{return self::$instance;}
 public array $data=[];
 public function get_posted_data():array{return $this->data;}
 public function get_meta($x):string{return 'serial123';}
}
class CF7Form {function __construct(public int $number){}function id():int{return $this->number;}}
define('ABSPATH','/wordpress');
define('SCHOOLFLOW_CRM_URL','https://crm.example.test/scrm');
define('SCHOOLFLOW_CRM_GROUP_SITE_KEY','sfgroup_TEST_KEY_PRIVATE');
define('SCHOOLFLOW_CRM_GROUP_FORM_IDS',[123]);
define('SCHOOLFLOW_CRM_SITE_KEY','sfsite_OLD_DEFAULT');
define('SCHOOLFLOW_CRM_FORM_KEYS',[456=>'sfsite_INDEPENDENT_FORM']);
require_once dirname(__DIR__).'/wordpress/schoolflow-contact-form-7.php';
$tag=(object)['name'=>'school_code','basetype'=>'select','raw_values'=>[],'values'=>[],'labels'=>[]];
$tag=schoolflow_group_school_options($tag);
if(count($tag->values)!==2 || $tag->values[1]!=='campus-2' || $tag->labels[0]!=='ABC Wakad')throw new RuntimeException('Dynamic CF7 campus choices failed');
if($GLOBALS['requests'][0][2]['headers']['Authorization']!=='Bearer sfgroup_TEST_KEY_PRIVATE')throw new RuntimeException('Private selector fetch lacked auth');
WPCF7_Submission::$instance=new WPCF7_Submission();
WPCF7_Submission::$instance->data=['student_name'=>'Student','parent_name'=>'Parent','phone'=>'9999911111','consent'=>['Yes'],'school_code'=>'campus-2'];
schoolflow_capture_cf7_submission(new CF7Form(123));
$sent=end($GLOBALS['requests']);
if($sent[0]!=='POST' || $sent[2]['headers']['Authorization']!=='Bearer sfgroup_TEST_KEY_PRIVATE')throw new RuntimeException('Wrong group token');
$body=json_decode($sent[2]['body'],true);
if($body['school_code']!=='campus-2')throw new RuntimeException('Selected school not posted');
class ValidationResult {public bool $invalid=false;function invalidate($tag,$message):void{$this->invalid=true;}}
$validResult=new ValidationResult();
if(schoolflow_validate_group_school($validResult,$tag)->invalid)throw new RuntimeException('Valid school rejected');
WPCF7_Submission::$instance->data['school_code']='campus-999';
$invalidResult=new ValidationResult();
if(!schoolflow_validate_group_school($invalidResult,$tag)->invalid)throw new RuntimeException('Forged campus was not rejected by CF7 validation');
WPCF7_Submission::$instance->data['school_code']='campus-2';
$before=count($GLOBALS['requests']);
WPCF7_Submission::$instance->data['school_code']='campus-999';
schoolflow_capture_cf7_submission(new CF7Form(123));
if(count($GLOBALS['requests'])!==$before+1)throw new RuntimeException('Invalid campus should be checked against server allowlist'); // GET may refresh
if(end($GLOBALS['requests'])[0]!=='GET')throw new RuntimeException('Invalid campus improperly sent to CRM');
$before=count($GLOBALS['requests']);
WPCF7_Submission::$instance->data['school_code']='campus-1';
schoolflow_capture_cf7_submission(new CF7Form(999));
if(count($GLOBALS['requests'])!==$before)throw new RuntimeException('Unmapped group form should not submit');
echo "PASS: CF7 dynamic server-side school options and group form submission\n";