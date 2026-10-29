<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function qa_assert(bool $value,string $message):void{if(!$value)throw new RuntimeException('AI QA: '.$message);}
qa_assert(!sf_ai_quality_can_use_ai(['fees'],'What is the fee for grade 1?'),'fees need approved source');
qa_assert(!sf_ai_quality_can_use_ai(['age_eligibility'],'What is class 1 cutoff?'),'age needs approved source');
qa_assert(!sf_ai_quality_can_use_ai(['curriculum'],'Is this school CBSE affiliated?'),'board needs approved source');
qa_assert(!sf_ai_quality_can_use_ai(['transport'],'Does the school bus come to my stop?'),'bus routes require verification');
qa_assert(sf_ai_quality_can_use_ai(['curriculum'],'What is CBSE?'),'general curriculum explanation allowed');
qa_assert(sf_ai_quality_can_use_ai(['admissions'],'How do admissions usually work?'),'general process allowed');
$cases=[
 ['id'=>'correct-fees','expected_source'=>'approved_knowledge_file','required_phrases'=>['2027–28'],'forbidden_phrases'=>['₹999']],
 ['id'=>'unverified-age','expected_source'=>'general_guidance','required_phrases'=>['admissions team']]
];
$output=[
 'correct-fees'=>['source'=>'approved_knowledge_file','answer'=>'The stated fee for 2027–28 is in the published school fee table.'],
 'unverified-age'=>['source'=>'general_guidance','answer'=>'The admissions team can confirm the cutoff.']
];
$report=sf_ai_quality_report($cases,$output);
qa_assert($report['passed']===2 && $report['failed']===0,'expected evaluation pass');
$output['correct-fees']['answer']='The fee is ₹999.';
$report=sf_ai_quality_report($cases,$output);
qa_assert($report['failed']===1 && in_array('forbidden_claim',$report['cases'][0]['reasons'],true),'catch invented claim');
echo "PASS: v2.3 QA checks sensitive campus prompts, graded answers and prohibited claims\n";
