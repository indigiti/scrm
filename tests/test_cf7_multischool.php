<?php
declare(strict_types=1);
function add_action($h,$cb):void{}
function add_filter($h,$cb,$priority=10,$count=1):void{}
function sanitize_text_field($s):string{return trim((string)$s);}
function sanitize_key($s):string{return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}
function untrailingslashit($s):string{return rtrim($s,'/');}
function wp_generate_uuid4():string{return 'sampleuuid123';}
function wp_json_encode($x):string{return json_encode($x);}
$GLOBALS['calls']=[];
function wp_remote_post($url,$args):array{$GLOBALS['calls'][]=[$url,$args];return ['response'=>['code'=>201]];}
function wp_remote_retrieve_response_code($r):int{return $r['response']['code'];}
function is_wp_error($r):bool{return false;}
class WPCF7_Submission {
 public static ?self $sample=null;
 public static function get_instance():?self{return self::$sample;}
 public function get_posted_data():array{return ['student_name'=>'Student','parent_name'=>'Guardian','phone'=>'9999991234','grade'=>'Grade 1','consent'=>['yes']];}
 public function get_meta($k):string{return '101';}
}
class MockForm {public function __construct(private int $n){} public function id():int{return $this->n;}}
define('ABSPATH','/wp');
define('SCHOOLFLOW_CRM_URL','https://crm.example.test/scrm');
define('SCHOOLFLOW_CRM_SITE_KEY','sfsite_DEFAULT_MUST_NOT_BE_USED');
define('SCHOOLFLOW_CRM_FORM_KEYS',[101=>'sfsite_ALPHA',202=>'sfsite_BETA']);
require_once dirname(__DIR__).'/wordpress/schoolflow-contact-form-7.php';
WPCF7_Submission::$sample=new WPCF7_Submission();
foreach([101,202,303] as $formId)schoolflow_capture_cf7_submission(new MockForm($formId));
if(count($GLOBALS['calls'])!==2)throw new RuntimeException('Unmapped form must not be sent');
foreach(['sfsite_ALPHA','sfsite_BETA'] as $i=>$key){
 [$url,$data]=$GLOBALS['calls'][$i];
 if($url!=='https://crm.example.test/scrm/api/v1/inbound-leads')throw new RuntimeException('Unexpected URL');
 if($data['headers']['Authorization']!=='Bearer '.$key)throw new RuntimeException('Wrong school API key');
 if(json_decode($data['body'],true)['consent']!==true)throw new RuntimeException('Consent missing');
}
echo "PASS: Contact Form 7 school-key mapping and unmapped-form rejection\n";
