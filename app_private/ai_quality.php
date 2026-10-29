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
    if(preg_match('/\b(?:school\s+bus|bus\s+(?:route|stop|fee|pickup|come)|pickup\s+point|transport\s+(?:route|fee)|school\s+timings?|office\s+hours?|available\s+seats?|vacancies|admission\s+deadline)\b/iu',$q))return false;
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

/**
 * Aggregated school-specific question coverage. Current topic rows store the
 * most recent response source, not the source of every historical response.
 */
function sf_ai_quality_coverage(array $s,int $sid):array{
    $topics=[];$questions=0;$unverified=[];
    foreach($s['public_counsellor_topics']??[] as $entry){
        if((int)($entry['school_id']??0)!==$sid)continue;
        $count=max(0,(int)($entry['count']??0));$questions+=$count;
        $topic=(string)($entry['topic']??'other');$source=(string)($entry['last_source']??'unknown');
        $key=$topic.'|'.($entry['grade']??'').'|'.($entry['academic_year']??'');
        $row=['topic'=>$topic,'grade'=>substr((string)($entry['grade']??''),0,40),
            'academic_year'=>substr((string)($entry['academic_year']??''),0,12),
            'questions'=>$count,'latest_source'=>$source,
            'needs_review'=>in_array($source,['ai','general_guidance','unknown'],true)];
        $topics[$key]=$row;
        if($row['needs_review'])$unverified[]=$key;
    }
    $out=array_values($topics);
    usort($out,static fn($a,$b)=>($b['questions']<=>$a['questions'])?:strcmp($a['topic'],$b['topic']));
    return ['observed_questions'=>$questions,'topic_groups'=>count($out),
        'groups_needing_review'=>count($unverified),
        'data_limit'=>'Latest source per topic/grade/year only, not an audited per-answer accuracy score',
        'topics'=>array_slice($out,0,100)];
}
function sf_ai_quality_admin(string $method,string $ref,array $headers,SchoolFlowStore $store):array{
    if($method!=='GET')sf_fail(405,'Quality report is read-only');
    return $store->transaction(static function(array $s)use($ref,$headers):array{
        $u=sf_session($s,(string)($headers['authorization']??''));
        if(!empty($u['must_change_password']))sf_fail(403,'Change password first');
        $sid=sf_record_id($s,'schools',$ref);
        sf_membership($s,$sid,(int)$u['id'],['Admin']);
        return [200,sf_ai_quality_coverage($s,$sid)];
    });
}
