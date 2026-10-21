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

## 6. Age eligibility for the 2026–27 session
| Class | Eligible date of birth | Approximate age |
|---|---|---|
| Pre-Nursery | **1 January 2024 – 31 December 2024** | 2 years and above, below 3 |
| Nursery | **1 January 2023 – 31 December 2023** | 3 years and above, below 4 |
| LKG | **1 January 2022 – 31 December 2022** | 4 years and above, below 5 |
| UKG | **1 January 2021 – 31 December 2021** | 5 years and above, below 6 |
| Class I | **1 January 2020 – 31 December 2020** | 6 years and above, below 7 |
| Classes II–IX | Must have passed the previous class at a recognized school | DOB cutoff not supplied |

## 7. Age eligibility for the 2027–28 session
| Class | Eligible date of birth | Approximate age |
|---|---|---|
| Pre-Nursery | **1 January 2025 – 31 December 2025** | 2 years and above, below 3 |
| Nursery | **1 January 2024 – 31 December 2024** | 3 years and above, below 4 |
| LKG | **1 January 2023 – 31 December 2023** | 4 years and above, below 5 |
| UKG | **1 January 2022 – 31 December 2022** | 5 years and above, below 6 |
| Class I | **1 January 2021 – 31 December 2021** | 6 years and above, below 7 |
| Classes II–IX | Must have passed the previous class at a recognized school | DOB cutoff not supplied |

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
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'What are the fees?',['grade'=>'2']),'Which academic year'),
    'fee answer requests missing academic year');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Grade 2 fees 2026-27',[]),'116,725'),
    'correct Grade 2 annual fee 2026-27');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Grade 2 fees 2027-28',[]),'105,927'),
    'different annual fee next year, not merged');
 kf_assert(sf_kfile_grade('fees for 5 th',['grade'=>'I'])==='Class V',
    'fifth grade shorthand overrides previously remembered Class I');
 kf_assert(sf_kfile_grade('5th standard fees',['grade'=>'I'])==='Class V',
    'ordinal grade recognised in casual parent question');
 kf_assert(sf_kfile_grade('Class V fees',['grade'=>'I'])==='Class V',
    'Roman class notation takes precedence');
 kf_assert(sf_kfile_grade('fees for class 5',['grade'=>'I'])==='Class V',
    'plain numeric class is recognised');
 $wrongGrade=sf_kfile_fee_answer($doc,'fees for 5 th 2027-28',['grade'=>'I']);
 kf_assert(str_contains((string)$wrongGrade,'Class V') &&
    !str_contains((string)$wrongGrade,'105,927'),
    'a different grade fee must NEVER be substituted from prior class context');
 $classFiveDocument=$doc."\n## 11. School fees — academic year 2028–29\n".
   "### Annual fee table\n| Class | Component | Tuition | Total |\n".
   "|---|---:|---:|---:|\n| Class V | 15,000 | 90,000 | **105,000** |\n";
 kf_assert(str_contains((string)sf_kfile_fee_answer($classFiveDocument,
    'fees for 5 th 2028-29',['grade'=>'I']),'Class V'),
    'explicit fifth grade retrieves only its own year/row');
 $boardDoc=['content'=>"# School information\n## 2. Board and curriculum\n".
    "The school is affiliated to CBSE.\n"];
 $featuresDoc=['content'=>"# School information\n## 4. Campus facilities\n".
    "- Library\n- Science laboratory\n- Outdoor sports area\n"];
 kf_assert(str_contains((string)sf_kfile_school_brief_answer([$boardDoc],'is cbse'),'CBSE-affiliated'),
    'short CBSE question answered directly from approved campus affiliation');
 kf_assert(sf_kfile_school_brief_answer(
    [['content'=>"# General advice\nCBSE, ICSE, IB are different educational boards."]],'is cbse')===null,
    'generic mentions of CBSE cannot be mistaken for campus affiliation');
 $features=sf_kfile_school_brief_answer([$featuresDoc],'school features');
 kf_assert($features!==null && str_contains($features,'• Library') &&
    str_contains($features,'• Science laboratory'),
    'school features use compact verified bullet list, not general AI essay');

 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'What is Grade 2 first-term fee 2026-27?',[]),'58,362.50'),
    'published Term I total must not be replaced with annual fee');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Class II one-time admission fee 2026-27',[]),'20,000'),
    'one-time admission fee separate from tuition');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Class XI Science fees 2027-28',[]),'121,893'),
    'Class XI Science selected from correct year and stream');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Class XI fees 2027-28',[]),'Which stream'),
    'stream required before choosing between Class XI fee rows');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Grade 2 Term I fee 2027-28',[]),'annual fee amounts'),
    'do not invent missing 2027-28 instalment fee');
 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'Nursery fee 2026-27',[]),'96,296'),
    'preserve school stated total with known component discrepancy');
 kf_assert(str_contains((string)sf_kfile_age_answer($doc,'Nursery age criteria 2026-27',[]),'1 January 2023'),
    'approved nursery DOB range is year specific');
 kf_assert(str_contains((string)sf_kfile_age_answer($doc,'Nursery age criteria 2027-28',[]),'1 January 2024'),
    'age table does not confuse two years');
 kf_assert(str_contains((string)sf_kfile_age_answer($doc,'Class II age eligibility 2026-27',[]),'passed the previous class'),
    'Classes II–IX eligibility uses recognised prior school, not invented DOB cutoff');
 kf_assert(str_contains((string)sf_kfile_age_answer($doc,'Nursery age limit',[]),'academic year'),
    'age requires academic year before any birth-date cutoff');
 kf_assert(str_contains((string)sf_kfile_age_answer($doc,'Age eligibility 2026-27',[]),'• Pre-Nursery'),
    'general age question lists all confirmed classes rather than demanding grade');
 kf_assert(str_contains((string)sf_kfile_age_answer($doc,'Age eligibility 2026-27',[]),'Class I'),
    'all class listing includes Grade 1');
 kf_assert(str_contains((string)sf_kfile_age_answer($doc,'Age eligibility 2026-27',[]),'Classes II–IX'),
    'higher grade requirement clearly states previous class qualification');
 kf_assert(str_contains((string)sf_kfile_age_answer($doc,'Pre-Nursery age limit 2027-28',[]),'1 January 2025'),
    'Pre-Nursery uses its own year-specific DOB');
 kf_assert(str_contains((string)sf_kfile_age_answer($doc,'LKG age 2027-28',[]),'1 January 2023'),
    'LKG date is not confused with Nursery');
 kf_assert(str_contains((string)sf_kfile_age_answer($doc,'Class I age eligibility 2027-28',[]),'1 January 2021'),
    'Class I age uses school supplied DOB');
 kf_assert(str_contains((string)sf_kfile_example_answer($known,'admission documents',[]),'• '),
    'verified brief school documents should render as bullet checklist');
 $docChecklist=$doc."\n## 15. Admission documents\n- Birth certificate\n- Previous report card where applicable\n- Recent passport photographs\n";
 kf_assert(str_contains((string)sf_kfile_documents_answer(
    [['content'=>$docChecklist]],'Which documents are required?',[]),'• Birth certificate'),
    'knowledge document heading yields a readable verified checklist');


 $fileContext=sf_kfile_context($known,'Grade 2 fees 2026-27',['grade'=>'2']);
 kf_assert(str_contains($fileContext,'2026'),'approved knowledge excerpt usable for AI context');

 kf_assert(str_contains((string)sf_kfile_fee_answer($doc,'What is Class XI one-time admission fee 2027-28?',[]),'two different'),
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
