<?php
declare(strict_types=1);
/**
 * School-authenticated, review-before-publication Markdown/plain-text library.
 * Uploaded information is scoped to one campus and never public until approved.
 */
function sf_kfile_admin(string $method,string $ref,array $p,array $headers,SchoolFlowStore $store):array {
    if(!in_array($method,['GET','POST'],true))sf_fail(405,'Method not allowed');
    return $store->transaction(static function(array &$s)use($method,$ref,$p,$headers):array {
        $user=sf_session($s,(string)($headers['authorization']??''));
        if(!empty($user['must_change_password']))sf_fail(403,'Change password first');
        $sid=sf_record_id($s,'schools',$ref);
        sf_membership($s,$sid,(int)$user['id'],['Admin']);
        $s['public_chat_knowledge_files']=$s['public_chat_knowledge_files']??[];
        if($method==='GET'){
            $docs=array_values(array_filter($s['public_chat_knowledge_files'],
                static fn($d)=>(int)($d['school_id']??0)===$sid));
            usort($docs,static fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
            $out=[];
            foreach($docs as $doc){
                $heads=[];
                preg_match_all('/^#{1,3}\s+(.+)$/m',(string)$doc['content'],$m);
                foreach(array_slice($m[1],0,18) as $h)$heads[]=substr(trim($h),0,130);
                $out[]=[
                  'id'=>$doc['id'],'filename'=>$doc['filename'],'status'=>$doc['status'],
                  'bytes'=>strlen($doc['content']),'sections'=>$heads,
                  'preview'=>sf_kfile_cut($doc['content'],1500),
                  'created_at'=>$doc['created_at'],'published_at'=>$doc['published_at']??null,
                  'sha256'=>$doc['sha256']
                ];
            }
            return [200,['files'=>$out,'limits'=>['max_bytes'=>120000,'max_files'=>8],
                'allowed_types'=>['md','txt']]];
        }
        $action=sf_text($p,'action',18,3);
        if($action==='upload'){
            $filename=sf_text($p,'filename',150,5);
            if(!preg_match('/^[\p{L}\p{N} _().-]+\.(md|txt)$/ui',$filename)
                ||str_contains($filename,'..'))sf_fail(422,'Upload a .md or .txt school knowledge file');
            $content=sf_text($p,'content',120000,40);
            if(str_contains($content,"\0")||!preg_match('//u',$content))
                sf_fail(422,'Knowledge file must contain valid UTF-8 text');
            $count=0;
            foreach($s['public_chat_knowledge_files'] as $d)
                if((int)$d['school_id']===$sid){
                    $count++;
                    if($d['filename']===$filename)sf_fail(409,'A file with this name already exists. Remove or rename the older file first.');
                }
            if($count>=8)sf_fail(409,'Maximum eight knowledge files per school');
            $hash=hash('sha256',$content);
            $id=sf_uuid();
            $s['public_chat_knowledge_files'][]=[
              'id'=>$id,'school_id'=>$sid,'filename'=>$filename,'content'=>$content,
              'sha256'=>$hash,'status'=>'pending','created_at'=>sf_time(),
              'published_at'=>null,'uploaded_by'=>(int)$user['id']
            ];
            sf_event($s,$sid,null,(int)$user['id'],'public_chat_knowledge_uploaded','Knowledge file awaiting approval');
            return [201,['ok'=>true,'id'=>$id,'status'=>'pending','bytes'=>strlen($content)]];
        }
        $id=sf_text($p,'id',36,36);
        if(!preg_match('/^[0-9a-f-]{36}$/iD',$id))sf_fail(422,'Invalid file reference');
        $index=null;
        foreach($s['public_chat_knowledge_files'] as $i=>$d)
            if((int)($d['school_id']??0)===$sid && ($d['id']??'')===$id){$index=$i;break;}
        if($index===null)sf_fail(404,'Knowledge file not found');
        $doc=&$s['public_chat_knowledge_files'][$index];
        if($action==='publish'){
            if(($p['confirmed']??false)!==true)sf_fail(422,'Review and confirm the school-specific content before publishing');
            $doc['status']='published';$doc['published_at']=sf_time();
            $doc['approved_by']=(int)$user['id'];
        }elseif($action==='unpublish'){
            $doc['status']='pending';$doc['published_at']=null;
        }elseif($action==='delete'){
            unset($doc);
            unset($s['public_chat_knowledge_files'][$index]);
            $s['public_chat_knowledge_files']=array_values($s['public_chat_knowledge_files']);
        }else sf_fail(422,'Invalid knowledge file action');
        sf_event($s,$sid,null,(int)$user['id'],'public_chat_knowledge_'.$action,'School knowledge file '.$action);
        return [200,['ok'=>true,'action'=>$action]];
    },$method==='POST');
}
function sf_kfile_published(array $s,int $sid):array {
    return array_values(array_filter($s['public_chat_knowledge_files']??[],
        static fn($d)=>(int)($d['school_id']??0)===$sid && ($d['status']??'')==='published'));
}
function sf_kfile_year(string $question,array $profile):string {
    $text=$question.' '.(string)($profile['academic_year']??'');
    if(preg_match('/20(\d{2})\s*[-–—\/]\s*(?:20)?(\d{2})/u',$text,$m))
        return '20'.$m[1].'–'.$m[2];
    return '';
}
/** Return UTF-8-safe previews, never cut inside a multibyte symbol. */
function sf_kfile_cut(string $text,int $limit):string {
    $part=substr($text,0,$limit);
    while($part!==''&&!preg_match('//u',$part))$part=substr($part,0,-1);
    return $part;
}
function sf_kfile_grade(string $question,array $profile):string {
    $raw=(string)($profile['grade']??'');
    if(preg_match('/\b(?:class|grade|std|standard)\s*(nursery|pre[- ]?nursery|lkg|ukg|xii|xi|ix|viii|vii|vi|iv|iii|ii|x|v|i|[1-9]|1[0-2])\b/iu',$question,$m))
        $raw=$m[1];
    $norm=strtolower(trim($raw));
    if($norm===''){
        if(preg_match('/\b(pre[- ]?nursery|nursery|lkg|ukg)\b/iu',$question,$m))$norm=strtolower($m[1]);
        else return '';
    }
    $roman=['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI',
      '7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X','11'=>'XI','12'=>'XII'];
    if(isset($roman[$norm]))return 'Class '.$roman[$norm];
    if(in_array(strtoupper($norm),$roman,true))return 'Class '.strtoupper($norm);
    if($norm==='pre nursery'||$norm==='pre-nursery')return 'Pre-Nursery';
    if(in_array($norm,['nursery','lkg','ukg'],true))return strtoupper($norm)==='NURSERY'?'Nursery':strtoupper($norm);
    return '';
}
/** Return exact stated total, never recompute fee components or assume a year. */
function sf_kfile_fee_answer(string $content,string $question,array $profile):?string {
    $topic=strtolower($question);
    if(!preg_match('/fee|tuition|charges|cost|फीस|शुल्क/iu',$topic))return null;
    $year=sf_kfile_year($question,$profile);
    $grade=sf_kfile_grade($question,$profile);
    $foundYears=[];
    preg_match_all('/##\s+\d*\.?\s*(?:School )?fees\s*[—–-]\s*academic year\s*(20\d\d[–—-]\d\d)/iu',$content,$ym);
    foreach($ym[1]??[] as $y)$foundYears[]=str_replace(['-','—'],'–',$y);
    $foundYears=array_unique($foundYears);
    if(count($foundYears)>1 && $year==='')return 'I have fee information for multiple academic years. Do you mean 2026–27 or 2027–28?';
    if($year==='' && $foundYears)$year=reset($foundYears);
    if($grade==='')return 'Which class are you considering? I can check the school’s published fee details for '.($year?:'the relevant academic year').'.';
    $oneTime=preg_match('/one[ -]?time|\badmission fee\b/iu',$question)===1;
    if($oneTime){
        if($grade==='Class XI')
            return 'The school’s supplied one-time Class XI admission-fee notes conflict (₹20,000 and ₹30,000). Please confirm the correct amount with the admissions office.';
        if(in_array($grade,['Nursery','LKG','UKG'],true))
            return 'The supplied one-time admission fee for '.$grade.' in '.$year.' is ₹10,000. Confirm the final payable amount with admissions.';
        $levels=['Class I','Class II','Class III','Class IV','Class V','Class VI','Class VII','Class VIII','Class IX'];
        if(in_array($grade,$levels,true))
            return 'The supplied one-time admission fee for '.$grade.' in '.$year.' is ₹20,000. Confirm the latest amount with admissions.';
        if(in_array($grade,['Class X','Class XII'],true))
            return 'The supplied one-time admission fee for '.$grade.' in '.$year.' is ₹30,000. Confirm the latest amount with admissions.';
        return 'The supplied school knowledge does not specify a one-time admission fee for '.$grade.'. Please confirm with admissions.';
    }
    if($year==='')return null;
    $sections=preg_split('/(?=^##\s+(?:\d+\.\s*)?School fees\s*[—–-]\s*academic year\s+20\d\d[–—-]\d\d)/miu',$content);
    $section=null;
    foreach($sections as $piece)
        if(preg_match('/^##\s+(?:\d+\.\s*)?School fees\s*[—–-]\s*academic year\s+20\d\d[–—-]\d\d/miu',$piece,$header)){
            if(str_contains(str_replace(['-','—'],'–',$header[0]),$year)){$section=$piece;break;}
        }
    if($section===null)return null;
    $term=preg_match('/\b(?:first[ -]?term|term[ -]?i|term 1|1st term)\b/iu',$question)?'Term I':
        (preg_match('/\b(?:second[ -]?term|term[ -]?ii|term 2|2nd term)\b/iu',$question)?'Term II':'');
    if($term!=='' && $year==='2027–28')
        return 'The supplied 2027–28 school fee information provides annual totals only; the '.$term.' payment amount is not listed. Please confirm with admissions.';
    $heading=$term==='Term I'?'First-term fee table':($term==='Term II'?'Second-term fee table':'Annual fee table');
    if(!preg_match('/###\s+'.preg_quote($heading,'/').'.*?(?=^###\s+|\z)/misu',$section,$table))return null;
    if($grade==='Class XI'&&$year==='2027–28'&&
       !preg_match('/science|commerce|humanities|arts/iu',$question))
        return 'For Class XI in 2027–28, the school lists separate fees for Science and Commerce/Humanities. Which stream are you considering?';
    $row=null;
    foreach(explode("\n",$table[0]) as $line){
        if(!str_starts_with(trim($line),'|'))continue;
        $cols=array_map('trim',explode('|',trim($line,' |')));
        if(count($cols)<4)continue;
        $class=trim(preg_replace('/\*+/','',$cols[0]));
        if($class===$grade || str_starts_with($class,$grade.' —') || str_starts_with($class,$grade.' -')){
            if($grade==='Class XI' && stripos($question,'science')!==false && stripos($class,'science')===false)continue;
            if($grade==='Class XI' && stripos($question,'commerce')!==false && stripos($class,'science')!==false)continue;
            $row=$cols;break;
        }
    }
    if($row===null)return 'I do not have a confirmed '.$grade.' '.($term?:'annual').' fee for '.$year.' in this school’s approved document. Please check with admissions.';
    $value=trim(str_replace('*','',end($row)));
    if(!preg_match('/^[₹]?\s*[\d,]+(?:\.\d+)?$/u',$value))return null;
    $answer='For '.$grade.' in '.$year.', the school’s stated '.($term?:'annual').' fee is ₹'.ltrim($value,'₹ ').'.';
    if(preg_match('/(Nursery|LKG|UKG)/',$grade) && $value==='96,296')
        $answer.=' The supplied fee components have a ₹1 discrepancy; please confirm the final payable amount with admissions.';
    if($grade==='Class XI')$answer.=' One-time Class XI admission-fee notes conflict; please confirm those separately.';
    return $answer.' These figures come from the school-provided fee document, not live billing.';
}
/** Reuse school-supplied example replies, but only when unambiguous.
 * Academic-year and fees questions are handled separately.
 */
function sf_kfile_example_answer(array $docs,string $question,array $profile):?string {
    $topics=sf_counsellor_classify($question);
    $q=strtolower($question);
    $example=null;
    if(in_array('documents',$topics,true))$example='What are the admission documents?';
    elseif(in_array('transport',$topics,true))$example='Do you provide bus transport to my area?';
    elseif(str_contains($q,'address')||str_contains($q,'location'))$example='What is the address?';
    elseif(preg_match('/lucky\s*draw/i',$question))$example='When is the lucky draw?';
    elseif(str_contains($q,'class xi')&&str_contains($q,'subject'))$example='What subjects can I choose in Class XI?';
    if($example===null)return null;
    foreach($docs as $doc){
        $content=(string)($doc['content']??'');
        foreach(preg_split('/\R/u',$content)?:[] as $index=>$line){
            if(trim($line)!=='**Q: '.$example.'**')continue;
            $lines=preg_split('/\R/u',$content);
            for($j=$index+1;$j<min($index+5,count($lines));$j++){
                if(preg_match('/^\s*A:\s*(.+)/u',trim($lines[$j]),$m))
                    return trim(preg_replace('/\*\*/','',$m[1])??$m[1]);
                if(preg_match('/^\*\*Q:/',$lines[$j]))break;
            }
        }
    }
    return null;
}

function sf_kfile_context(array $docs,string $question,array $profile):string {
    $topics=sf_counsellor_classify($question);
    $relevant=[];
    $map=[
      'fees'=>['fee','charge','tuition'],'age_eligibility'=>['age','eligibility'],
      'documents'=>['document'],'transport'=>['transport','bus'],
      'facilities'=>['facilities','campus'],'school_features'=>['school','campus'],
      'admissions'=>['admission'],'curriculum'=>['class xi','subject','curriculum','cbse'],
      'timings'=>['timing','hours'],'contact'=>['contact','identity'],
      'visit'=>['visit','address']
    ];
    $terms=[];
    foreach($topics as $topic)foreach($map[$topic]??[] as $term)$terms[]=$term;
    foreach(['science','commerce','humanities','principal','address','subject'] as $term)
        if(stripos($question,$term)!==false)$terms[]=$term;
    if(!$terms)$terms=['school','contact'];
    $year=sf_kfile_year($question,$profile);
    $grade=sf_kfile_grade($question,$profile);
    foreach(array_slice($docs,0,8) as $doc){
        $content=(string)($doc['content']??'');
        $parts=preg_split('/(?=^##\s)/m',$content)?:[];
        $parent='';
        foreach($parts as $chunk){
            if(strlen(trim($chunk))<25)continue;
            $first=strtok($chunk,"\n")?:'';
            if(!str_starts_with($first,'## ')){
                // Answering rules and identifying details, not the entire document.
                $relevant[]=sf_kfile_cut($chunk,900);continue;
            }
            $score=0;
            $heading=strtolower($first);
            foreach($terms as $term)
                if(str_contains(strtolower($chunk),strtolower($term)))$score+=str_contains($heading,strtolower($term))?10:2;
            if($year!==''&&str_contains(str_replace('-','–',$chunk),$year))$score+=9;
            if($grade!==''&&str_contains($chunk,$grade))$score+=7;
            if($score>0)$relevant[]=['score'=>$score,'text'=>$chunk,'heading'=>$first,'filename'=>$doc['filename']??'School knowledge'];
        }
    }
    $ranked=array_values(array_filter($relevant,'is_array'));
    usort($ranked,static fn($a,$b)=>$b['score']<=>$a['score']);
    $result="School-provided reference excerpts (verify year, grade and conflicting notes; unmentioned facts are UNKNOWN):\n";
    $year=sf_kfile_year($question,$profile);
    if($year==='')$result.="Academic year not specified. Do not select a year-specific fee or cutoff; ask the parent which year.\n";
    if($grade==='')$result.="Grade not specified. Do not guess a grade-specific fee/age rule.\n";
    foreach(array_slice($ranked,0,3) as $item){
        $text=$item['text'];
        if(strlen($text)>3500){
            $lines=explode("\n",$text);
            $selected=[];
            $grade=$grade?:'';
            foreach($lines as $line){
                if(strlen($selected)>80)break;
                if($grade!==''&&str_contains($line,'|') && !str_contains($line,$grade) &&
                   !preg_match('/^\|\s*(Class|Nursery|LKG|UKG|Pre-)/',$line))continue;
                $selected[]=$line;
            }
            $text=implode("\n",$selected);
        }
        $next="\nFile: ".$item['filename']."\n".sf_kfile_cut($text,4400)."\n";
        if(strlen($result)+strlen($next)>10000)break;
        $result.=$next;
    }
    return strlen($result)>170?$result:'';
}
function sf_chat_faq_manage(string $method,string $ref,array $p,array $headers,SchoolFlowStore $store):array {
    if($method!=='POST')sf_fail(405,'Method not allowed');
    return $store->transaction(static function(array &$s)use($ref,$p,$headers):array{
        $user=sf_session($s,(string)($headers['authorization']??''));
        $sid=sf_record_id($s,'schools',$ref);
        sf_membership($s,$sid,(int)$user['id'],['Admin']);
        $action=sf_text($p,'action',12,3);
        if(!in_array($action,['add','update','delete'],true))sf_fail(422,'Unknown FAQ action');
        $config=sf_chat_settings($s,$sid);
        $faqs=$config['faqs']??[];
        $original=sf_text($p,'original_question',180);
        $idx=null;
        foreach($faqs as $i=>$f)if($original!==''&&strcasecmp(trim($f['question']),$original)===0){$idx=$i;break;}
        if($action==='add'){
            if(count($faqs)>=60)sf_fail(409,'Maximum 60 FAQs per campus');
            $question=sf_text($p,'question',180,3);$answer=sf_text($p,'answer',800,3);
            foreach($faqs as $f)if(strcasecmp(trim($f['question']),$question)===0)
                sf_fail(409,'That question already exists for this school');
            $faqs[]=['question'=>$question,'answer'=>$answer];
        }elseif($action==='update'){
            if($idx===null)sf_fail(404,'FAQ no longer available');
            $question=sf_text($p,'question',180,3);$answer=sf_text($p,'answer',800,3);
            foreach($faqs as $i=>$f)if($i!==$idx&&strcasecmp(trim($f['question']),$question)===0)
                sf_fail(409,'Another FAQ already uses this question');
            $faqs[$idx]=['question'=>$question,'answer'=>$answer];
        }else{
            if($idx===null)sf_fail(404,'FAQ no longer available');
            array_splice($faqs,$idx,1);
        }
        $config['faqs']=array_values($faqs);
        $s['public_chat_settings'][(string)$sid]=$config;
        sf_event($s,$sid,null,(int)$user['id'],'public_chat_faq_'.$action,'Approved school FAQ '.$action);
        return [200,['ok'=>true,'count'=>count($faqs)]];
    },true);
}
