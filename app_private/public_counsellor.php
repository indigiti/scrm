<?php
declare(strict_types=1);
/**
 * Public AI Admission Counsellor
 * Answer first, then optionally qualify a consented CRM lead.
 * Anonymous conversations persist only bounded, non-contact intent metadata.
 * No raw parent messages, student names, phone numbers or AI transcripts in
 * reusable school knowledge. No external network requests within DB locks.
 */
function sf_counsellor_routes(array $s,string $guid,string $origin):array {
    $group=sf_public_group($s,$guid);
    if(!$group||!sf_public_origin_ok($group,$origin))sf_fail(403,'Website unauthorized');
    $routes=[];
    foreach(sf_group_campuses($s,$group) as $r)
        if(!empty(sf_chat_settings($s,(int)$r['school_id'])['enabled']))$routes[$r['code']]=$r;
    if(!$routes)sf_fail(403,'Admission counsellor unavailable');
    return [$group,$routes];
}
function sf_counsellor_classify(string $question):array {
    $q=function_exists('mb_strtolower')?mb_strtolower($question,'UTF-8'):strtolower($question);
    $patterns=[
      'fees'=>'/fee|tuition|charge|payment|cost|फीस|शुल्क/u',
      'documents'=>'/document|certificate|paperwork|birth.?cert|कागदपत्र|दस्तावेज/u',
      'age_eligibility'=>'/\bage\b|eligib|cut.?off|आयु|वय/u',
      'transport'=>'/bus|transport|pick.?up|route|school van|बस/u',
      'facilities'=>'/facilit|lab|library|sports|playground|campus infrastructure|canteen/u',
      'curriculum'=>'/cbse|icse|\bib\b|board|curricul|syllabus/u',
      'admissions'=>'/admission|enrol|application|apply|प्रवेश/u',
      'timings'=>'/timi|hours|school time|schedule/u',
      'school_features'=>'/club|extra.?curricular|activity|activities|features/u',
      'visit'=>'/visit|tour|come to school|see campus|saturday|sunday|appointment/u'
    ];
    $topics=[];
    foreach($patterns as $type=>$rx)if(preg_match($rx,$q))$topics[]=$type;
    return $topics;
}
function sf_counsellor_extract(string $message,array $profile):array {
    $profile=array_merge(['grade'=>'','academic_year'=>'','residential_area'=>'',
        'interests'=>[],'visit_preference'=>'','lead_intent'=>'Browsing'], $profile);
    if(preg_match('/\b(?:grade|class|std|standard)\s*(?:is\s*)?([0-9]{1,2}|nursery|jr\.?\s*kg|sr\.?\s*kg|lkg|ukg|kindergarten)\b/i',$message,$m))
        $profile['grade']=substr(trim($m[1]),0,40);
    if(preg_match('/\b(20\d{2}\s*[-\/]\s*(?:20)?\d{2})\b/',$message,$m))
        $profile['academic_year']=preg_replace('/\s+/','',$m[1]);
    if(preg_match('/\b(?:we live in|i live in|reside in|from|we are in)\s+([\p{L} \-]{3,50})/ui',$message,$m)){
        $area=trim(preg_split('/[,.;!?]|\s+(?:and|near|but|so|what|how)\b/i',$m[1])[0]);
        if(strlen($area)>=3 && strlen($area)<=50)$profile['residential_area']=$area;
    }
    if(preg_match('/\b(saturday|sunday|monday|tuesday|wednesday|thursday|friday|weekend|tomorrow)\b/i',$message,$m)
      && in_array('visit',sf_counsellor_classify($message),true))
        $profile['visit_preference']=ucfirst(strtolower($m[1]));
    $profile['interests']=array_slice(array_values(array_unique(array_merge(
        $profile['interests'],sf_counsellor_classify($message)
    ))),0,12);
    $high=in_array('visit',$profile['interests'],true);
    $score=($high?55:0)+($profile['grade']!==''?15:0)
        +($profile['residential_area']!==''?10:0)
        +min(20,count($profile['interests'])*5);
    $profile['interest_score']=min(100,$score);
    $profile['lead_intent']=$high?'High interest':($score>=20?'Considering admission':'Browsing');
    $profile['recommended_action']=$high?'Offer a school visit request':
        ($profile['grade']===''?'Understand intended grade':'Answer questions; offer human follow-up if needed');
    return $profile;
}
function sf_counsellor_redact(string $text):string {
    $text=preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i','[email omitted]',$text)??'';
    $text=preg_replace('/(?<!\d)(?:\+?91[\s-]?)?[6-9]\d{9}(?!\d)/','[phone omitted]',$text)??'';
    return trim($text);
}
function sf_counsellor_fact_answer(string $question,array $profile,array $settings):?string {
    $topics=sf_counsellor_classify($question);
    $category=null;
    foreach($topics as $topic)if($topic!=='visit'){$category=$topic;break;}
    if($category===null)return null;
    $facts=array_values(array_filter($settings['school_facts']??[],
        static fn($f)=>($f['category']??'')===$category&&!empty($f['details'])&&empty($f['needs_recheck'])));
    if(!$facts)return null;
    $grade=strtolower(trim((string)($profile['grade']??'')));
    if($grade!=='' && in_array($category,['fees','age_eligibility'],true)){
        $matched=array_values(array_filter($facts,static function($f)use($grade):bool {
            $label=strtolower((string)($f['label']??''));
            return preg_match('/(?<![a-z0-9])'.preg_quote($grade,'/').'(?![a-z0-9])/i',$label)===1;
        }));
        if($matched)$facts=$matched;
        elseif(count($facts)>1)return 'I have verified information for other classes, but not yet for Grade '.$grade.'. The admissions team can confirm the applicable '.$category.' for your class.';
    }
    if($grade==='' && in_array($category,['fees','age_eligibility'],true)&&count($facts)>1)
        return 'The school has different '.($category==='fees'?'fee structures':'age requirements').' by class. Which grade are you considering? I can check the verified details.';
    $answers=[];
    foreach(array_slice($facts,0,5) as $f)
        $answers[]=(string)$f['label'].': '.(string)$f['details'];
    return implode("\n",$answers);
}
function sf_counsellor_next(string $question,array $profile,bool $campusSelected):string {
    $topics=sf_counsellor_classify($question);
    if(!$campusSelected)return 'Which campus are you considering? I can check the details for that branch.';
    if(in_array('visit',$topics,true))return 'Would you like our admissions team to help confirm a convenient visit time?';
    if($profile['grade']==='' && in_array('fees',$topics,true))
        return 'Which class or grade are you looking at?';
    if($profile['grade']!=='' && in_array('transport',$topics,true)&&$profile['residential_area']==='')
        return 'Which area would the bus need to cover? I can check the available school information.';
    // Answer first; do not tack on a scripted qualifying question to every turn.
    return '';
}
/**
 * Provider input is deliberately a topic synopsis, not verbatim parent text.
 * Heuristics cannot reliably detect every name or child's personal detail.
 */
function sf_counsellor_ai_topic(array $topics,array $profile):?string {
    $safeTopics=array_values(array_filter($topics,static fn($t)=>in_array($t,
       ['fees','documents','age_eligibility','transport','facilities',
        'curriculum','admissions','timings','school_features','visit'],true)));
    if(!$safeTopics)return null;
    $question='Explain general school admission information about '.implode(', ',$safeTopics);
    $grade=(string)($profile['grade']??'');
    if($grade!==''&&preg_match('/^(?:[0-9]{1,2}|nursery|lkg|ukg|kindergarten|jr\s*kg|sr\s*kg)$/i',$grade))
       $question.=' for class '.$grade;
    return $question.'. If specific campus facts are unavailable, state what needs confirmation.';
}

function sf_counsellor_anonymous_token():string {
    return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
}
/** No raw questions or contact fields are persisted before explicit consent. */
function sf_counsellor_message(string $guid,array $p,array $headers,string $ip,SchoolFlowStore $store):array {
    $origin=(string)($headers['origin']??'');
    $message=sf_text($p,'message',400,2);
    $given=sf_text($p,'conversation_token',128);
    if($given!==''&&!preg_match('/^[A-Za-z0-9_-]{43}$/D',$given))sf_fail(401,'Conversation expired');
    $selected=sf_text($p,'school_code',50);
    $safe=sf_counsellor_redact($message);
    $token=$given!==''?$given:sf_counsellor_anonymous_token();
    $stored=$store->transaction(static function(array &$s)use($guid,$origin,$ip,$selected,$message,$token,$given):array {
        [$group,$routes]=sf_counsellor_routes($s,$guid,$origin);
        $gid=(int)$group['id'];$now=time();
        sf_limit($s,'counsel-ip:'.$gid.':'.substr(hash('sha256',$ip),0,16),45,3600);
        sf_limit($s,'counsel-group:'.$gid,500,3600);
        $s['public_counsellor_sessions']=$s['public_counsellor_sessions']??[];
        $index=null;$fingerprint=hash('sha256',$token);
        if($given!=='')foreach($s['public_counsellor_sessions'] as $i=>$v)
          if(hash_equals((string)($v['token_hash']??''),$fingerprint)&&
             (int)($v['group_id']??0)===$gid&&
             (int)($v['expires_at']??0)>$now
             &&hash_equals((string)($v['origin_hash']??''),hash('sha256',$origin))){$index=$i;break;}
        if($given!==''&&$index===null)sf_fail(401,'Conversation expired');
        $rec=$index!==null?$s['public_counsellor_sessions'][$index]:
          ['token_hash'=>$fingerprint,'group_id'=>$gid,'school_id'=>0,
           'profile'=>[],'created_at'=>sf_time(),'expires_at'=>$now+86400,
           'turn_count'=>0,'lead_id'=>null];
        $school=null;
        if($selected!==''&&isset($routes[$selected]))$school=$routes[$selected];
        elseif(!empty($rec['school_id']))
            foreach($routes as $v)if((int)$v['school_id']===(int)$rec['school_id']){$school=$v;break;}
        elseif(count($routes)===1)$school=array_values($routes)[0];
        elseif($selected!=='')sf_fail(422,'Choose an available campus');
        // Determine branch from explicitly mentioned campus name only.
        if($school===null)foreach($routes as $v){
            $name=strtolower((string)$v['name']);
            if(strlen($name)>=4&&str_contains(strtolower($message),$name)){$school=$v;break;}
        }
        if($school!==null&&!empty($p['chat_session'])){
            $leadId=sf_chat_session_lead($store,(string)$p['chat_session'],
                $gid,(int)$school['school_id'],$origin,$s);
            $rec['lead_id']=$leadId;
            $existing=sf_find($s['leads'],$leadId);
            if($existing && empty($rec['profile']['grade']) && !empty($existing['grade']))
                $rec['profile']['grade']=(string)$existing['grade'];
        }
        $rec['origin_hash']=hash('sha256',$origin);
        $rec['school_id']=$school?(int)$school['school_id']:0;
        $rec['profile']=sf_counsellor_extract($message,$rec['profile']??[]);
        $rec['turn_count']=min(10000,(int)($rec['turn_count']??0)+1);
        $rec['last_at']=sf_time();
        if($index===null)$s['public_counsellor_sessions'][]=$rec;
        else $s['public_counsellor_sessions'][$index]=$rec;
        // Bounded private, anonymous conversations. Expired records removed.
        if(count($s['public_counsellor_sessions'])>1800)
            $s['public_counsellor_sessions']=array_slice(array_values(array_filter(
                $s['public_counsellor_sessions'],static fn($v)=>(int)($v['expires_at']??0)>$now)),-1200);
        return ['group_id'=>$gid,'school'=>$school,'routes'=>array_values($routes),
            'profile'=>$rec['profile'],'lead_id'=>$rec['lead_id']];
    },true);
    $route=$stored['school'];
    $config=$route? $store->transaction(static fn(array $s)=>
        sf_chat_settings($s,(int)$route['school_id'])) :null;
    $profile=$stored['profile'];
    $topics=sf_counsellor_classify($message);
    $reply=null;$source='general_guidance';
    if($route!==null){
        $q=sf_chat_expand_topic($safe);
        $match=sf_kb_match($q,$config);
        if($match!==null){$reply=$match['answer'];$source='approved_faq';}
        if($reply===null){$reply=sf_counsellor_fact_answer($q,$profile,$config);
            if($reply!==null)$source='approved_school_information';}
        if($reply===null){
            // The provider receives a de-identified topic, never contact data.
            // For personal questions build a topic-only synopsis.
            $aiTopic=sf_counsellor_ai_topic($topics,$profile);
            if($aiTopic!==null&&!empty($config['ai_enabled'])){
                $reply=!empty($config['openrouter_key'])
                    ? sf_chat_openrouter($aiTopic,$route['name'],$config,$store)
                    : sf_chat_ai($aiTopic,$route['name'],$config);
                if($reply!==null)$source='ai';
            }
        }
    }
    if($reply===null){
        if(in_array('visit',$topics,true))$reply='I can help you request a campus visit. The admissions team will confirm whether the requested day is available.';
        elseif(in_array('fees',$topics,true))$reply='Fees vary by class and campus. I can check published fees once we know the campus and class, or ask admissions to confirm.';
        elseif(in_array('documents',$topics,true))$reply='Schools commonly request a birth certificate, photographs and previous school records, depending on the class. I can check this campus’s official checklist if it is available.';
        elseif(in_array('age_eligibility',$topics,true))$reply='Admission age limits depend on the grade and the school’s cut-off date. The admissions team can confirm the rule for your child’s class.';
        elseif($route===null)$reply='I can help with school admissions, fee questions, documents, transport, facilities and campus visits.';
        else $reply='I can help explain general admission steps. For this campus’s specific policies, I can check published information or help you request confirmation.';
    }
    $next=sf_counsellor_next($message,$profile,$route!==null);
    if($next!=='')$reply.=" \n\n".$next;
    $topicsOut=array_values(array_unique($topics));
    // Save anonymised topic and EXACT parent-facing response for review and
    // coverage reporting. Never auto-approve model-generated information.
    $publicReply=sf_counsellor_redact($reply);
    $store->transaction(static function(array &$s)use($route,$topicsOut,$source,$publicReply,$stored):void{
        $sid=$route?(int)$route['school_id']:0;
        if(!$sid||!$topicsOut)return;
        $grade=substr((string)($stored['profile']['grade']??''),0,40);
        $year=substr((string)($stored['profile']['academic_year']??''),0,12);
        $s['public_counsellor_topics']=$s['public_counsellor_topics']??[];
        foreach($topicsOut as $topic){
            $found=false;
            foreach($s['public_counsellor_topics'] as &$row)
                if(($row['school_id']??0)===$sid&&($row['topic']??'')===$topic && ($row['grade']??'')===$grade && ($row['academic_year']??'')===$year) {
                    $row['count']=min(100000,(int)($row['count']??0)+1);
                    $row['last_answer']=substr($publicReply,0,900);
                    $row['last_source']=$source;
                    $row['last_seen_at']=sf_time();
                    $found=true;break;
                }
            unset($row);
            if(!$found)$s['public_counsellor_topics'][]=['school_id'=>$sid,
              'topic'=>$topic,'grade'=>$grade,'academic_year'=>$year,'count'=>1,'last_answer'=>substr($publicReply,0,900),
              'last_source'=>$source,'last_seen_at'=>sf_time()];
        }
        if(count($s['public_counsellor_topics'])>1500)
            $s['public_counsellor_topics']=array_slice($s['public_counsellor_topics'],-1200);
    },true);
    $campuses=array_map(static fn($v)=>['code'=>$v['code'],'name'=>$v['name']],$stored['routes']);
    return [200,['conversation_token'=>$token,'reply'=>$reply,'source'=>$source,
      'campus_name'=>$route['name']??null,'school_code'=>$route['code']??null,
      'profile'=>$profile,'qualified'=>$profile['lead_intent']??'Browsing',
      'suggest_contact'=>in_array('visit',$topics,true),'campuses'=>$campuses,
      'existing_lead'=>!empty($stored['lead_id'])]];
}
/** Deliberate contact permission converts one conversation to a single CRM lead. */
function sf_counsellor_convert(string $guid,array $p,array $headers,string $ip,SchoolFlowStore $store):array {
    $origin=(string)($headers['origin']??'');
    if(($p['consent']??false)!==true)sf_fail(422,'Permission to contact required');
    $token=sf_text($p,'conversation_token',128,43);
    if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))sf_fail(401,'Conversation expired');
    $phone=sf_text($p,'phone',10,10);
    if(!preg_match('/^[6-9][0-9]{9}$/D',$phone))sf_fail(422,'Valid mobile required');
    $name=sf_text($p,'parent_name',140);
    $id=sf_text($headers,'idempotency-key',100,12);
    if(!preg_match('/^[A-Za-z0-9_-]{12,100}$/D',$id))sf_fail(422,'Submission ID required');
    $proof=sf_public_verify_challenge($store,$guid,$origin,
        (string)($p['captcha_token']??''),$p['captcha_answer']??'');
    return $store->transaction(static function(array &$s)use($guid,$origin,$token,$phone,$name,$id,$proof,$ip,$store):array {
        [$group,$routes]=sf_counsellor_routes($s,$guid,$origin);
        $recId=null;$fingerprint=hash('sha256',$token);
        foreach($s['public_counsellor_sessions']??[] as $i=>$rec)
            if(hash_equals((string)($rec['token_hash']??''),$fingerprint)
               &&(int)($rec['group_id']??0)===(int)$group['id']
               &&(int)($rec['expires_at']??0)>time()){$recId=$i;break;}
        if($recId===null)sf_fail(401,'Conversation expired');
        $rec=&$s['public_counsellor_sessions'][$recId];
        foreach($routes as $route)if((int)$route['school_id']===(int)$rec['school_id'])break;
        if(!isset($route)||(int)$route['school_id']!==(int)$rec['school_id'])sf_fail(422,'Choose a campus first');
        $wasLinked=!empty($rec['lead_id']);
        if(!empty($rec['lead_id'])){
            $lead=sf_find($s['leads'],(int)$rec['lead_id']);
            if(!$lead)sf_fail(404,'Lead unavailable');
            if(substr(preg_replace('/\D/','',(string)($lead['phone']??'')),-10)!==$phone)
                sf_fail(409,'This enquiry is already linked to a different contact number');
            $existingId=(int)$rec['lead_id'];
        }else{
            sf_limit($s,'counsel-convert:'.substr(hash('sha256',$ip),0,16),8,3600);
            foreach($s['receipts']??[] as $receipt)
                if(($receipt['public_challenge']??'')===$proof)sf_fail(409,'Verification already used');
            $grade=(string)($rec['profile']['grade']??'');
            $existingId=null;$phoneMatches=[];
            foreach($s['leads']??[] as $candidate)
                if((int)($candidate['school_id']??0)===(int)$route['school_id']
                 && in_array((string)($candidate['utm_source']??''),['admissions_ai_counsellor','admissions_guided_chat'],true)
                 &&substr(preg_replace('/\D/','',(string)($candidate['phone']??'')),-10)===$phone
                 &&strtolower(trim((string)($candidate['grade']??'')))===strtolower(trim($grade))
                 &&strtotime((string)($candidate['created_at']??''))>time()-30*86400)
                    $phoneMatches[]=$candidate;
            // If multiple sibling candidates match, do not merge them blindly.
            if(count($phoneMatches)===1)$existingId=(int)$phoneMatches[0]['id'];
            if($existingId===null){
                $lead=sf_create_lead($s,(int)$route['school_id'],[
                   'parent_name'=>$name?:'Parent name not shared',
                   'student_name'=>'Student name not shared',
                   'phone'=>'+91 '.$phone,'grade'=>$grade,
                   'city'=>(string)($rec['profile']['residential_area']??''),
                   'consent'=>true,'utm_source'=>'admissions_ai_counsellor',
                   'message'=>'Parent requested contact through the AI Admissions Counsellor.'
                ],(int)$route['site_id'],null,null,'ai_counsellor_lead_created');
                $existingId=(int)$lead['id'];
            }
            foreach($s['leads'] as &$leadRow)
                if((int)$leadRow['id']===$existingId&&
                   (int)$leadRow['school_id']===(int)$route['school_id']){
                    $leadRow['lead_channel']='chatbot';
                    $leadRow['ai_intent']=$rec['profile']['lead_intent']??'Browsing';
                    $leadRow['ai_interests']=$rec['profile']['interests']??[];
                    $leadRow['ai_next_action']=$rec['profile']['recommended_action']??'Admissions follow-up';
                    break;
                }
            unset($leadRow);
            $rec['lead_id']=$existingId;
            $s['receipts'][]=['school_id'=>$route['school_id'],
              'chat_group_id'=>$group['id'],'public_challenge'=>$proof,
              'chat_counsellor_submission'=>$id,'chat_session_lead_id'=>$existingId,
              'chat_expires_at'=>time()+3600,'created_at'=>sf_time()];
        }
        $expires=time()+3600;
        $s['receipts'][]=['school_id'=>$route['school_id'],
           'chat_group_id'=>$group['id'],'chat_session_lead_id'=>$existingId,
           'chat_expires_at'=>$expires,'created_at'=>sf_time()];
        return [201,['success'=>true,'existing_lead'=>$wasLinked,
          'session'=>sf_chat_token($store,(int)$group['id'],(int)$route['school_id'],$existingId,$expires,$origin),
          'school_code'=>$route['code'],'lead_created'=>true]];
    },true);
}
function sf_counsellor_report(string $ref,array $headers,SchoolFlowStore $store):array {
    return $store->transaction(static function(array $s)use($ref,$headers):array{
        $user=sf_session($s,(string)($headers['authorization']??''));
        $schoolId=sf_record_id($s,'schools',$ref);
        sf_membership($s,$schoolId,(int)$user['id'],['Admin']);
        $topics=array_values(array_filter($s['public_counsellor_topics']??[],
            static fn($r)=>(int)($r['school_id']??0)===$schoolId));
        usort($topics,static fn($a,$b)=>($b['count']??0)<=>($a['count']??0));
        return [200,['topics'=>array_slice($topics,0,40),
           'anonymous_conversations'=>count(array_filter($s['public_counsellor_sessions']??[],
              static fn($r)=>(int)($r['school_id']??0)===$schoolId&&empty($r['lead_id']))) ]];
    });
}
