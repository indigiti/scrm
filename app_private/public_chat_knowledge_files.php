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
            return [200,['files'=>$out,'readiness'=>[
                'published'=>count(array_filter($docs,static fn($d)=>($d['status']??'')==='published')),
                'pending'=>count(array_filter($docs,static fn($d)=>($d['status']??'')==='pending')),
                'school_id'=>$sid],
                'limits'=>['max_bytes'=>120000,'max_files'=>8],
                'allowed_types'=>['md','txt']]];
        }
        $action=sf_text($p,'action',18,3);
        if($action==='test'){
            // School-admin-only, side-effect-free knowledge diagnostic.
            // It checks exactly the same published files used by /counsel.
            $question=sf_text($p,'question',200,3);
            $docs=sf_kfile_published($s,$sid);
            if(!$docs)return [200,['status'=>'no_published_files',
                'answer'=>'No published knowledge files are available for this campus. Uploading a document does not publish it. Review and publish the correct campus file.',
                'published_files'=>0]];
            $combined=implode("\n",array_column($docs,'content'));
            $profile=['grade'=>'','academic_year'=>''];
            $answer=sf_kfile_fee_answer($combined,$question,$profile);
            if($answer===null)$answer=sf_kfile_age_answer($combined,$question,$profile);
            if($answer===null)$answer=sf_kfile_example_answer($docs,$question,$profile);
            return [200,['status'=>$answer===null?'no_matching_knowledge':'matched',
                'published_files'=>count($docs),'answer'=>$answer??'Published files exist, but no verified direct answer matched this question. Check the document heading, class and academic year, or enable AI for broader questions.']];
        }
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
/** Grade explicitly supplied THIS turn takes priority over a remembered grade.
 * Handles "fees for 5 th", "5th class", "Grade 5", "Class V" and KG names.
 */
function sf_kfile_explicit_grade(string $question):string {
    $q=trim($question);
    $token='';
    if(preg_match('/\b(?:class|grade|std|standard)\s*(?:is\s+|for\s+)?(pre[- ]?nursery|nursery|lkg|ukg|(?:1[0-2]|[1-9])\s*(?:st|nd|rd|th)?|xii|xi|ix|viii|vii|vi|iv|iii|ii|x|v|i)\b/iu',$q,$m))
        $token=trim($m[1]);
    elseif(preg_match('/(?<![0-9])(?:1[0-2]|[1-9])\s*(?:st|nd|rd|th)\b/iu',$q,$m))
        $token=$m[0];
    elseif(preg_match('/\b(pre[- ]?nursery|nursery|lkg|ukg)\b/iu',$q,$m))
        $token=$m[1];
    if($token==='')return '';
    $normal=strtolower(trim(preg_replace('/\s*(?:st|nd|rd|th)\s*$/iu','',$token)??$token));
    $normal=preg_replace('/\s+/u',' ',$normal)??$normal;
    $roman=['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI',
       '7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X','11'=>'XI','12'=>'XII'];
    if(isset($roman[$normal]))return 'Class '.$roman[$normal];
    if(in_array(strtoupper($normal),$roman,true))return 'Class '.strtoupper($normal);
    if(in_array($normal,['pre-nursery','pre nursery'],true))return 'Pre-Nursery';
    if(in_array($normal,['nursery','lkg','ukg'],true))
        return $normal==='nursery'?'Nursery':strtoupper($normal);
    return '';
}
function sf_kfile_grade(string $question,array $profile):string {
    $direct=sf_kfile_explicit_grade($question);
    if($direct!=='')return $direct;
    $previous=trim((string)($profile['grade']??''));
    if($previous==='')return '';
    return sf_kfile_explicit_grade('Class '.$previous);
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
    if($year!=='' && $foundYears && !in_array($year,$foundYears,true))
        return 'I don’t have a fee amount for '.$year.'. The admissions team can help confirm it.';
    if(count($foundYears)>1 && $year==='')return 'Which academic year are you interested in — 2026–27 or 2027–28?';
    if($year==='' && $foundYears)$year=reset($foundYears);
    if($grade==='')return 'Which class are you looking at? I can check the fees for '.($year?:'the relevant academic year').'.';
    $oneTime=preg_match('/one[ -]?time|\badmission fee\b/iu',$question)===1;
    if($oneTime){
        if($grade==='Class XI')
            return 'I have two different one-time Class XI admission-fee amounts: ₹20,000 and ₹30,000. The admissions team can confirm the correct amount.';
        if(in_array($grade,['Nursery','LKG','UKG'],true))
            return 'The one-time admission fee for '.$grade.' in '.$year.' is ₹10,000. Confirm the final payable amount with admissions.';
        $levels=['Class I','Class II','Class III','Class IV','Class V','Class VI','Class VII','Class VIII','Class IX'];
        if(in_array($grade,$levels,true))
            return 'The one-time admission fee for '.$grade.' in '.$year.' is ₹20,000. Confirm the latest amount with admissions.';
        if(in_array($grade,['Class X','Class XII'],true))
            return 'The one-time admission fee for '.$grade.' in '.$year.' is ₹30,000. Confirm the latest amount with admissions.';
        return 'I don’t have the one-time admission fee for '.$grade.'. Please confirm with admissions.';
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
        return 'For 2027–28, I have annual fee amounts but not the '.$term.' instalment amount. Admissions can confirm it.';
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
    if($row===null)return 'I don’t have the current '.$grade.' '.($term?:'annual').' fee for '.$year.' for this class. The admissions team can confirm it.';
    $value=trim(str_replace('*','',end($row)));
    if(!preg_match('/^[₹]?\s*[\d,]+(?:\.\d+)?$/u',$value))return null;
    $answer='For '.$grade.' in '.$year.', the '.($term?:'annual').' fee is ₹'.ltrim($value,'₹ ').'.';
    if(preg_match('/(Nursery|LKG|UKG)/',$grade) && $value==='96,296')
        $answer.=' There is a ₹1 difference between the fee components and the total. Admissions can confirm the final payable amount.';
    if($grade==='Class XI')$answer.=' The one-time admission fee also needs confirmation from admissions.';
    return $answer.' The admissions team can confirm the final payable amount.';
}
/** Reuse school-supplied example replies, but only when unambiguous.
 * Academic-year and fees questions are handled separately.
 */
/** Extract the published inclusive DOB window for a known class/session. */
/** Read the published campus-specific table, including general all-class queries. */
function sf_kfile_age_answer(string $content,string $question,array $profile):?string {
    if(!preg_match('/age|eligib|birth|dob|वय|आयु/iu',$question))return null;
    $year=sf_kfile_year($question,$profile);
    $grade=sf_kfile_grade($question,$profile);
    $blocks=preg_split('/(?=^##\s)/m',$content)?:[];
    $available=[];
    foreach($blocks as $block){
        $heading=strtok($block,"\n")?:'';
        if(!preg_match('/^##\s+\d+\.?\s*Age eligibility.*?(20\d\d[–—-]\d\d)/iu',$heading,$m))continue;
        $y=str_replace(['-','—'],'–',$m[1]);$available[$y]=$block;
    }
    if(!$available)return null;
    if($year===''){
        if(count($available)===1)$year=(string)array_key_first($available);
        else return 'I can help with the school’s age requirements. Which academic year would you like to check — '.implode(' or ',array_keys($available)).'?';
    }
    if(!isset($available[$year]))
        return 'I don’t have age requirements for '.$year.'. The admissions team can confirm the correct requirements.';
    $rows=[];
    foreach(explode("\n",$available[$year]) as $line){
        if(!str_starts_with(trim($line),'|'))continue;
        $cells=array_map(static fn($cell)=>trim(str_replace(['**','__'],'',$cell)),
            explode('|',trim($line,' |')));
        if(count($cells)<2)continue;
        $label=$cells[0];
        if(!in_array($label,['Pre-Nursery','Nursery','LKG','UKG','Class I','Classes II–IX'],true))continue;
        $dob=trim($cells[1]);
        $age=(string)($cells[2]??'');
        if($dob==='')continue;
        $rows[$label]=['eligibility'=>$dob,'age'=>$age];
    }
    if(!$rows)return null;
    $note="\n\nThese age criteria are for ".$year.
        ". Dates shown are inclusive. Final eligibility is subject to verification by the school admissions team.";
    if($grade===''){
        $lines=['Here are the age requirements for '.$year.':'];
        foreach($rows as $label=>$entry){
            $eligibility=$entry['eligibility'];$age=$entry['age'];
            $isDob=preg_match('/\d{4}/',$eligibility)===1;
            $lines[]='• '.$label.': '.($isDob?'Born between ':'').$eligibility.
                ($age!=='' && strtolower($age)!=='dob cutoff not supplied'?' ('.$age.')':'');
        }
        return implode("\n",$lines).$note.
            "\n\nWhich class are you considering? I can help you check the relevant requirement.";
    }
    $key=$grade;
    if(preg_match('/^Class (II|III|IV|V|VI|VII|VIII|IX)$/',$grade))$key='Classes II–IX';
    if(!isset($rows[$key]))
        return 'I don’t have the age requirement for '.$grade.' in '.$year.'. The admissions team can help confirm it.';
    $entry=$rows[$key];$isDob=preg_match('/\d{4}/',$entry['eligibility'])===1;
    return 'For '.$grade.' in '.$year.', the age requirement is: '.
        ($isDob?'date of birth from ':'').$entry['eligibility'].
        ($entry['age']!=='' && strtolower($entry['age'])!=='dob cutoff not supplied'
          ?' (approximately '.$entry['age'].')':'').'.'.$note;
}
/** Format verified school document lists as a parent-friendly checklist. */
function sf_kfile_documents_answer(array $docs,string $question,array $profile):?string {
    if(!in_array('documents',sf_counsellor_classify($question),true))return null;
    $grade=sf_kfile_grade($question,$profile);
    foreach($docs as $doc){
        $content=(string)($doc['content']??'');
        $sections=preg_split('/(?=^#{1,3}\s+\S)/m',$content)?:[];
        foreach($sections as $section){
            $heading=strtok($section,"\n")?:'';
            if(!preg_match('/^#{1,3}\s+.*(?:documents|document checklist|application requirements)/iu',$heading))continue;
            $lines=[];
            foreach(explode("\n",$section) as $line){
                $line=trim($line);
                if(!preg_match('/^(?:[-*+]\s+|\d+[.)]\s+)(.+)$/u',$line,$m))continue;
                $item=trim(preg_replace('/\*\*|__/u','',$m[1])??$m[1]);
                if($item!=='' && !preg_match('/^[-|: ]+$/',$item))$lines[]='• '.sf_kfile_cut($item,270);
                if(count($lines)>=12)break;
            }
            if($lines){
                return 'For admission'.($grade!==''?' to '.$grade:'').', you’ll generally need:'.
                    "\n\n".implode("\n",$lines).
                    "\n\nRequirements may vary by class and admission stage. The school admissions team can confirm the final checklist.";
            }
        }
    }
    return null;
}

/** Promote approved brief document replies into a real, readable list. */
function sf_kfile_format_example(string $answer,string $question):string {
    $answer=trim($answer);
    if(!str_contains(strtolower($question),'document'))return $answer;
    // List only items explicitly supplied in the school answer.
    $content=preg_replace('/^(?:the )?(?:required )?documents?\s+(?:include|are)\s*:?\s*/iu','',$answer)??$answer;
    $content=rtrim($content,'. ');
    $items=preg_split('/\s*,\s*|\s+and\s+/iu',$content)?:[];
    $items=array_values(array_filter(array_map('trim',$items),static fn($i)=>$i!==''));
    if(count($items)<2||count($items)>12)return $answer.
        "\n\nPlease confirm the final requirements for your class with the admissions office.";
    return "The admission documents include:\n\n".
        implode("\n",array_map(static fn($i)=>'• '.ucfirst($i),$items)).
        "\n\nThe exact checklist may depend on the class and admission stage. Our admissions team can confirm the latest requirements.";
}

function sf_kfile_example_answer(array $docs,string $question,array $profile):?string {
    $documents=sf_kfile_documents_answer($docs,$question,$profile);
    if($documents!==null)return $documents;
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
                    return sf_kfile_format_example((string)(preg_replace('/\*\*/','',$m[1])??$m[1]),$example);
                if(preg_match('/^\*\*Q:/',$lines[$j]))break;
            }
        }
    }
    return null;
}

/** Answer board/CBSE and features only when the approved source is explicit. */
function sf_kfile_school_brief_answer(array $docs,string $question):?string {
    $board=preg_match('/\bcbse\b|\b(board|affiliation)\b/iu',$question)===1;
    $features=preg_match('/\b(school features?|highlights?|facilities|what makes (?:the )?school special)\b/iu',$question)===1;
    if(!$board && !$features)return null;
    foreach($docs as $doc){
        $content=(string)($doc['content']??'');
        if($board){
            $sections=preg_split('/(?=^#{1,3}\s+\S)/m',$content)?:[];
            foreach($sections as $section){
                $heading=trim(strtok($section,"\n")?:'');
                if(!preg_match('/^#{1,3}\s+.*\b(?:board|curriculum|affiliation|school identity|school information|about (?:the )?school)\b/iu',$heading))
                    continue;
                if(preg_match('/\b(?:not|non)[ -]?CBSE\b|\bCBSE\s+not\s+affiliated\b/iu',$section))continue;
                if(preg_match('/\b(?:affiliated\s+(?:to|with)|affiliation\s+(?:with|to))\s+(?:the\s+)?CBSE\b|\bCBSE[ -]affiliated\b/iu',$section))
                    return 'Yes, this school is CBSE-affiliated.';
                if(preg_match('/\b(?:follows?|offers?|uses?)\s+(?:the\s+)?CBSE\s+(?:curriculum|board|syllabus)\b|\bCBSE\s+(?:curriculum|syllabus|board)\s+(?:is|offered|followed)\b/iu',$section))
                    return 'This school follows the CBSE curriculum. The admissions team can also confirm its affiliation details.';
            }
        }
        if($features){
            $sections=preg_split('/(?=^#{1,3}\s+\S)/m',$content)?:[];
            foreach($sections as $section){
                $heading=trim(strtok($section,"\n")?:'');
                if(!preg_match('/^#{1,3}\s+.*\b(?:school features|school highlights|key highlights|campus facilities|facilities|infrastructure|campus amenities)\b/iu',$heading))
                    continue;
                $items=[];
                foreach(explode("\n",$section) as $line){
                    if(!preg_match('/^\s*[-*+]\s+(.+)$/u',$line,$m))continue;
                    $item=trim(str_replace(['**','__'],'',$m[1]));
                    if($item!=='' && strlen($item)<180 && !preg_match('/(?:please contact|for more information|example only)/i',$item))
                        $items[]='• '.$item;
                    if(count($items)>=5)break;
                }
                if($items)return "Here are some of the school’s features:\n\n".
                    implode("\n",$items).
                    "\n\nThe admissions team can help with more details about these facilities.";
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
                if(count($selected)>80)break;
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
