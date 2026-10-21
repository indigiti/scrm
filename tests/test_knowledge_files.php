<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function kf_assert(bool $v,string $detail):void{
    if(!$v)throw new RuntimeException('Knowledge file regression: '.$detail);
}
$dir=sys_get_temp_dir().'/scrm-knowledge-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
$store=new SchoolFlowStore($dir);
function kf_req(string $method,string $route,array $payload=[],string $user='admin'):array{
    global $store;
    try{
      return sf_handle($method,$route,$payload,['authorization'=>'Bearer '.$user], '198.51.100.17',$store);
    }catch(SFError $err){
      return [$err->httpStatus,['detail'=>$err->getMessage()]];
    }
}
try {
 $store->transaction(static function(array &$s):void{
    $s['users'][]=['id'=>1,'name'=>'School Admin','active'=>true,'email'=>'admin@example.invalid'];
    $s['counters']['users']=1;
    foreach([1,2] as $id){
        $s['schools'][]=['id'=>$id,'guid'=>sf_uuid(),'name'=>'School '.$id];
        $s['counters']['schools']=$id;
        $s['memberships'][]=['school_id'=>$id,'user_id'=>1,'role'=>'Admin'];
    }
    $s['sessions'][]=['user_id'=>1,'token_hash'=>sf_hash('admin'),'expires_at'=>time()+1200];
 },true);
 $doc=<<<'MARKDOWN'
# The Lexicon International School, Kalyaninagar — Admissions Knowledge Base

## 1. Chatbot answering rules
Do not mix the two academic sessions. Grade and year must be known before quoting fees.

## 9. School fees — academic year 2026–27
### Annual fee table, 2026–27
| Class | Annual term-fee component (₹) | Annual tuition-fee component (₹) | Stated annual total (₹) |
|---|---:|---:|---:|
| Nursery | 13,757 | 82,540 | **96,296** |
| Class II | 16,675 | 100,050 | **116,725** |

### First-term fee table, 2026–27
| Class | Term fee | Tuition | First term |
|---|---:|---:|---:|
| Class II | 8,338 | 50,025 | **58,362.50** |

### One-time admission-fee notes
Class XI has conflicting amounts of ₹20,000 and ₹30,000.

## 10. School fees — academic year 2027–28
### Annual fee table, 2027–28
| Class | Term-fee | Tuition-fee | Stated annual total |
|---|---:|---:|---:|
| Nursery | 13,757 | 82,540 | **96,296** |
| Class II | 15,133 | 90,794 | **105,927** |
| Class XI — Commerce/Humanities | 14,934 | 91,060 | **105,994** |
| Class XI — Science | 17,174 | 104,719 | **121,893** |

## 12. Ready-to-use example chatbot responses
**Q: What are the admission documents?**
A: The required documents include a birth certificate and school leaving certificate.

**Q: Do you provide bus transport to my area?**
A: Transport is GPS-enabled but routes and charges need school confirmation.
MARKDOWN;
 $base='/api/schools/1/public-chat';
 [$uploadCode,$uploaded]=kf_req('POST',$base.'/knowledge-files',
    ['action'=>'upload','filename'=>'Lexicon_Kalyani_Admissions_KB.md','content'=>$doc]);
 kf_assert($uploadCode===201 && !empty($uploaded['id']),'upload is staged');
 [$getCode,$inventory]=kf_req('GET',$base.'/knowledge-files');
 kf_assert($getCode===200 && count($inventory['files'])===1,'file visible to school admin');
 kf_assert($inventory['files'][0]['status']==='pending','not auto published');
 $id=$uploaded['id'];
 $data=$store->transaction(static fn(array $s)=>$s);
 kf_assert(count(sf_kfile_published($data,1))===0,'unreviewed file excluded');
 kf_assert(count(sf_kfile_published($data,2))===0,'other campus has no access');
 kf_assert(kf_req('POST',$base.'/knowledge-files',
    ['action'=>'publish','id'=>$id])[0]===422,'publishing requires explicit confirmation');
 [$publishCode,$pub]=kf_req('POST',$base.'/knowledge-files',
    ['action'=>'publish','id'=>$id,'confirmed'=>true]);
 kf_assert($publishCode===200 && $pub['ok'],'admin publishes file');
 $data=$store->transaction(static fn(array $s)=>$s);
 kf_assert(count(sf_kfile_published($data,1))===1,'approved school source available');
 kf_assert(count(sf_kfile_published($data,2))===0,'campus files segregated');
 $known=sf_kfile_published($data,1);
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'What are the fees?',['grade'=>'2']),'academic years'),
    'fee answer requests missing academic year');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Grade 2 fees 2026-27',[]),'116,725'),
    'correct Grade 2 annual fee 2026-27');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Grade 2 fees 2027-28',[]),'105,927'),
    'different annual fee next year, not merged');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'What is Grade 2 first-term fee 2026-27?',[]),'58,362.50'),
    'published Term I total must not be replaced with annual fee');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Class II one-time admission fee 2026-27',[]),'20,000'),
    'one-time admission fee separate from tuition');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Class XI Science fees 2027-28',[]),'121,893'),
    'Class XI Science selected from correct year and stream');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Class XI fees 2027-28',[]),'Which stream'),
    'stream required before choosing between Class XI fee rows');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Grade 2 Term I fee 2027-28',[]),'annual totals only'),
    'do not invent missing 2027-28 instalment fee');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Nursery fee 2026-27',[]),'96,296'),
    'preserve school stated total with known component discrepancy');
 $fileContext=sf_kfile_context($known,'Grade 2 fees 2026-27',['grade'=>'2']);
 kf_assert(str_contains($fileContext,'2026'),'approved knowledge excerpt usable for AI context');

 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'What is Class XI one-time admission fee 2027-28?',[]),'conflict'),
    'contradictory one-time admission fee never guessed');
 kf_assert(str_contains((string)sf_kfile_example_answer($known,'admission documents',[]),'birth certificate'),
    'exact school-supplied admissions document answer');
 [$createFaq,$faq]=kf_req('POST',$base.'/faqs',['action'=>'add','question'=>'What is the school board?',
    'answer'=>'The school follows CBSE.']);
 kf_assert($createFaq===200,'add approved FAQ');
 [$editFaq,$updated]=kf_req('POST',$base.'/faqs',['action'=>'update',
    'original_question'=>'What is the school board?','question'=>'Which board is followed?',
    'answer'=>'This campus follows the CBSE board.']);
 kf_assert($editFaq===200,'edit approved FAQ');
 kf_assert(kf_req('POST',$base.'/faqs',['action'=>'delete',
    'original_question'=>'Which board is followed?'])[0]===200,'approved FAQ delete persists');
 [$current,$cfg]=kf_req('GET',$base);
 kf_assert($current===200&&count($cfg['faqs'])===0,'FAQ removed from persisted knowledge');
 $grouping=$store->transaction(static function(array &$s):void {
    $s['public_counsellor_topics'][]=['school_id'=>1,'topic'=>'fees',
      'grade'=>'2','academic_year'=>'2026–27','count'=>3,'last_answer'=>'Some fee amount'];
    $s['public_counsellor_topics'][]=['school_id'=>2,'topic'=>'fees',
      'grade'=>'2','academic_year'=>'2026–27','count'=>2,'last_answer'=>'Other school'];
    $s['public_chat_suggestions'][]=['id'=>sf_uuid(),'school_id'=>1,'status'=>'pending',
        'question'=>'Are your pickup locations available?','count'=>1];
 },true);
 kf_assert(kf_req('POST',$base.'/counsellor-report',[
   'action'=>'delete_topic','topic'=>'fees','grade'=>'2','academic_year'=>'2026–27'])[0]===200,
    'question-history topic deletion supported');
 $remaining=$store->transaction(static fn(array $s)=>$s);
 kf_assert(count($remaining['public_counsellor_topics'])===1&&
    $remaining['public_counsellor_topics'][0]['school_id']===2,
    'delete topic does not affect another school');
 $reviewId=$remaining['public_chat_suggestions'][0]['id'];
 kf_assert(kf_req('POST',$base.'/review',['id'=>$reviewId,'decision'=>'delete'])[0]===200,
   'unanswered question may be deleted');
 [$removed,$removedResult]=kf_req('POST',$base.'/knowledge-files',['action'=>'unpublish','id'=>$id]);
 kf_assert($removed===200,'unpublish file');
 $remaining=$store->transaction(static fn(array $s)=>$s);
 kf_assert(count(sf_kfile_published($remaining,1))===0,'unpublished file excluded');
 kf_assert(kf_req('POST',$base.'/knowledge-files',['action'=>'delete','id'=>$id])[0]===200,
   'knowledge file deletable by school admin');
 kf_assert(count(kf_req('GET',$base.'/knowledge-files')[1]['files'])===0,'deleted file absent');
 kf_assert(kf_req('POST','/api/schools/2/public-chat/knowledge-files',
      ['action'=>'delete','id'=>$id])[0]===404,'cross-campus knowledge delete denied');
 echo "PASS: reviewed school files, precise academic fees, Q&A saves/deletes, campus isolation and topic removals\n";
} finally{
 foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
 @rmdir($dir);
}
