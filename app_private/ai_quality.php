<?php
declare(strict_types=1);
/** v2.3: deterministic guardrails and offline acceptance for school-specific AI answers. */
/** External generative AI may explain concepts but must not invent campus facts. */
function sf_ai_quality_can_use_ai(array $topics,string $question):bool{
    $q=strtolower(trim($question));
    // Fees and admission ages must come from school-verified, year/grade-bound data.
    if(array_intersect(['fees','age_eligibility'],$topics))return false;
    // Board affiliations and school-specific facilities need explicitly approved facts.
    if(preg_match('/\b(cbse|icse|affiliat(?:ion|ed)?|board|facilities|amenities|school\s+features?)\b/iu',$q)
        && !preg_match('/^(?:what\s+(?:is|does)|explain|meaning\s+of|difference\s+between|compare)\s+(?:the\s+)?(?:cbse|icse|a\s+board|school\s+facilities)\b/iu',$q))return false;
    // Bus stops, prices, schedules and seat counts change and must be verified.
    if(preg_match('/\b(?:bus\s+(?:route|stop|fee)|pickup\s+point|transport\s+(?:route|fee)|school\s+timings?|office\s+hours?|available\s+seats?|vacancies|admission\s+deadline)\b/iu',$q))return false;
    return true;
}
/** Offline, explicit assertions only. Does not invent an LLM quality score. */
function sf_ai_quality_check_case(array $case,array $actual):array{
    $name=trim((string)($case['id']??''));if($name==='')throw new InvalidArgumentException('Case ID required');
    $reasons=[];
    if(isset($case['expected_source'])&&($actual['source']??'')!==$case['expected_source'])
        $reasons[]='unexpected_source';
    $answer=(string)($actual['answer']??'');
    if(trim($answer)==='')$reasons[]='empty_answer';
    foreach($case['required_phrases']??[] as $phrase)
        if(!str_contains(strtolower($answer),strtolower((string)$phrase)))
            $reasons[]='missing_required_phrase';
    foreach($case['forbidden_phrases']??[] as $phrase)
        if(str_contains(mb_strtolower($answer,'UTF-8'),mb_strtolower((string)$phrase,'UTF-8')))
            $reasons[]='forbidden_claim';
    if(isset($case['max_words'])&&str_word_count($answer)>(int)$case['max_words'])
        $reasons[]='too_verbose';
    return ['id'=>$name,'passed'=>!$reasons,'reasons'=>array_values(array_unique($reasons))];
}
function sf_ai_quality_report(array $cases,array $outputs):array {
    $results=[];$pass=0;
    foreach($cases as $case){
        $id=(string)($case['id']??'');
        $result=sf_ai_quality_check_case($case,$outputs[$id]??[]);
        $results[]=$result;if($result['passed'])$pass++;
    }
    return ['total'=>count($cases),'passed'=>$pass,'failed'=>count($cases)-$pass,
        'pass_rate'=>count($cases)?round(100*$pass/count($cases),1):0,'cases'=>$results];
}
