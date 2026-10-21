<?php
declare(strict_types=1);
/**
 * Public admissions knowledge and review queue.
 * No automatic publishing of user-generated answers or raw chat transcripts.
 * Published entries are scoped to a single school.
 */
function sf_kb_words(string $text):array {
    $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
    $text=strtr($text,[
       'admissions'=>'admission','enrolment'=>'admission','enrollment'=>'admission',
       'documents'=>'document','required'=>'require','application'=>'apply',
       'admitted'=>'admission','fees'=>'fee','charges'=>'fee',
       'विद्यालय'=>'school','शाळा'=>'school','प्रवेश'=>'admission',
       'फीस'=>'fee','शुल्क'=>'fee','कागदपत्रे'=>'document','दस्तावेज'=>'document'
    ]);
    $stop=['what','when','where','which','could','would','about','please','your',
      'this','that','have','does','with','need','want','tell','know','some',
      'school','child','student','admission','hello','thanks',
      'क्या','कैसे','कब','कहाँ','मुझे','आहे','काय','मला','साठी'];
    $parts=preg_split('/[^\\p{L}\\p{N}]+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
    return array_values(array_unique(array_filter($parts,static fn($w)=>
      (strlen($w)>=3 || preg_match('/^[\\p{Devanagari}]+$/u',$w)) &&
      !in_array($w,$stop,true))));
}
/** Conservative answer reuse: never return a loosely related fee/policy answer. */
function sf_kb_match(string $question,array $config):?array {
    $query=sf_kb_words($question);
    if(!$query)return null;
    $best=null;$top=0.0;
    foreach($config['faqs']??[] as $entry) {
        $terms=sf_kb_words((string)($entry['question']??''));
        if(!$terms)continue;
        $shared=count(array_intersect($query,$terms));
        $precision=$shared/count($query);
        $recall=$shared/count($terms);
        $score=($precision+$recall)/2;
        // A 1-word question is only eligible for a 1-word exact match.
        if($shared<min(2,count($query),count($terms)))continue;
        if($precision<0.75 || $recall<0.75 || $score<0.78)continue;
        if($score>$top){$top=$score;$best=$entry;}
    }
    return $best;
}
/** A heuristic privacy filter. Ambiguous or personally identifying inputs are not persisted. */
function sf_kb_safe_topic(string $question):?string {
    $question=trim(preg_replace('/\\s+/u',' ',$question)??'');
    if(strlen($question)<12||strlen($question)>180)return null;
    if(preg_match('/[\\r\\n<>@]|\\b\\d{4,}\\b|[A-Z0-9._%+\\-]+@[A-Z0-9.\\-]+/iu',$question))return null;
    if(preg_match('/\\b(my name|my child|my daughter|my son|my phone|call me|contact me|address|password|otp|ignore previous|system prompt|api key)\\b/i',$question))return null;
    if(!sf_kb_words($question))return null;
    return $question;
}
/** Store de-identified, unanswered question candidates only; no model outputs. */
function sf_kb_suggest(SchoolFlowStore $store,int $schoolId,string $question):void {
    $topic=sf_kb_safe_topic($question);
    if($topic===null)return;
    $key=hash('sha256',implode('|',sf_kb_words($topic)));
    $store->transaction(static function(array &$s)use($schoolId,$topic,$key):void {
        $s['public_chat_suggestions']=$s['public_chat_suggestions']??[];
        $pending=0;
        foreach($s['public_chat_suggestions'] as &$item) {
            if(($item['school_id']??null)!==$schoolId)continue;
            if(($item['status']??'')==='pending')$pending++;
            if(($item['topic_hash']??'')===$key) {
                if(($item['status']??'')==='pending')$item['count']=min(100,(int)($item['count']??1)+1);
                return;
            }
        }
        unset($item);
        if($pending>=60)return;
        $s['public_chat_suggestions'][]=[
          'id'=>sf_uuid(),'school_id'=>$schoolId,'topic_hash'=>$key,
          'question'=>$topic,'status'=>'pending','count'=>1,'created_at'=>sf_time()];
        // Cap this school's historical queue at 150 entries, keeping latest.
        $schoolEntries=0;
        foreach(array_reverse($s['public_chat_suggestions'],true) as $idx=>$item)
          if(($item['school_id']??null)===$schoolId && ++$schoolEntries>150)
            unset($s['public_chat_suggestions'][$idx]);
        $s['public_chat_suggestions']=array_values($s['public_chat_suggestions']);
    },true);
}
function sf_kb_review(string $method,string $schoolRef,array $payload,array $headers,SchoolFlowStore $store):array {
    if(!in_array($method,['GET','POST'],true))sf_fail(405,'Method not allowed');
    return $store->transaction(static function(array &$s)use($method,$schoolRef,$payload,$headers):array {
        $user=sf_session($s,(string)($headers['authorization']??''));
        if(!empty($user['must_change_password']))sf_fail(403,'Change your temporary password first');
        $sid=sf_record_id($s,'schools',$schoolRef);
        sf_membership($s,$sid,(int)$user['id'],['Admin']);
        if($method==='GET') {
            $items=array_values(array_filter($s['public_chat_suggestions']??[],
              static fn($row)=>($row['school_id']??null)===$sid && ($row['status']??'')==='pending'));
            usort($items,static fn($a,$b)=>($b['count']??0)<=>($a['count']??0));
            return [200,['items'=>array_slice($items,0,60)]];
        }
        $id=sf_text($payload,'id',36,36);
        if(!preg_match('/^[0-9a-f-]{36}$/D',$id))sf_fail(422,'Invalid topic reference');
        $decision=sf_text($payload,'decision',12,6);
        if(!in_array($decision,['approve','reject'],true))sf_fail(422,'Invalid review action');
        $index=null;
        foreach($s['public_chat_suggestions']??[] as $i=>$entry)
            if(($entry['id']??'')===$id && ($entry['school_id']??null)===$sid && ($entry['status']??'')==='pending'){$index=$i;break;}
        if($index===null)sf_fail(404,'Pending topic not found');
        if($decision==='approve'){
            $answer=sf_text($payload,'answer',800,3);
            $config=sf_chat_settings($s,$sid);
            if(count($config['faqs'])>=60)sf_fail(409,'Knowledge base has reached 60 answers');
            $question=$s['public_chat_suggestions'][$index]['question'];
            $hash=hash('sha256',implode('|',sf_kb_words($question)));
            foreach($config['faqs'] as $faq)
                if(hash('sha256',implode('|',sf_kb_words($faq['question'])))===$hash)
                    sf_fail(409,'An answer for this topic already exists');
            $config['faqs'][]=['question'=>$question,'answer'=>$answer];
            $s['public_chat_settings'][(string)$sid]=$config;
        }
        $s['public_chat_suggestions'][$index]['status']=$decision==='approve'?'approved':'rejected';
        unset($s['public_chat_suggestions'][$index]['question']);
        sf_event($s,$sid,null,(int)$user['id'],'public_chat_topic_reviewed',$decision==='approve'?'FAQ approved':'Topic rejected');
        return [200,['ok'=>true]];
    },$method==='POST');
}
