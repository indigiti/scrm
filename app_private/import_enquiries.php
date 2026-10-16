<?php
declare(strict_types=1);
/**
 * Historical enquiry import. Preview is read-only except small TTL receipt;
 * confirmed import is a single school-scoped JSON transaction.
 * Uploaded spreadsheet bytes are never persisted in SchoolFlow storage.
 */
const SF_IMPORT_FIELDS=['timestamp','name','email','phone','city','state','grade','campus','remarks','guardian','academic_year'];
function sf_import_zip_entries(string $zip):array {
    $end=strrpos($zip,"PK\x05\x06");
    if($end===false || $end+22>strlen($zip) || strlen($zip)>2500000)sf_fail(422,'Invalid or oversized Excel workbook');
    $meta=unpack('vcount',substr($zip,$end+10,2));
    $n=$meta['count']??0;
    if($n<1||$n>120)sf_fail(422,'Excel workbook contains too many parts');
    $pointer=unpack('Voffset',substr($zip,$end+16,4))['offset'];
    $files=[];$total=0;
    for($i=0;$i<$n;$i++){
        if(substr($zip,$pointer,4)!=="PK\x01\x02")sf_fail(422,'Invalid Excel ZIP index');
        $method=unpack('v',substr($zip,$pointer+10,2))[1];
        $size=unpack('V',substr($zip,$pointer+20,4))[1];
        $plain=unpack('V',substr($zip,$pointer+24,4))[1];
        $namelen=unpack('v',substr($zip,$pointer+28,2))[1];
        $extra=unpack('v',substr($zip,$pointer+30,2))[1];
        $comment=unpack('v',substr($zip,$pointer+32,2))[1];
        $location=unpack('V',substr($zip,$pointer+42,4))[1];
        $name=substr($zip,$pointer+46,$namelen);
        $pointer+=46+$namelen+$extra+$comment;
        if(!in_array($name,['xl/sharedStrings.xml','xl/worksheets/sheet1.xml'],true))continue;
        if($plain>10000000 || ($total+=$plain)>15000000 || !in_array($method,[0,8],true))sf_fail(413,'Excel sheet too large');
        if(substr($zip,$location,4)!=="PK\x03\x04")sf_fail(422,'Excel content missing');
        $localName=unpack('v',substr($zip,$location+26,2))[1];
        $localExtra=unpack('v',substr($zip,$location+28,2))[1];
        $compressed=substr($zip,$location+30+$localName+$localExtra,$size);
        $body=$method===8?gzinflate($compressed):$compressed;
        if(!is_string($body)||strlen($body)!==$plain)sf_fail(422,'Corrupt Excel sheet');
        $files[$name]=$body;
    }
    return $files;
}
function sf_import_xml_text(string $input):string {
    $input=preg_replace('/<[^>]+>/','',$input)??'';
    return html_entity_decode($input,ENT_QUOTES|ENT_XML1|ENT_SUBSTITUTE,'UTF-8');
}
function sf_import_xlsx(string $binary):array{
    $files=sf_import_zip_entries($binary);
    $xml=$files['xl/worksheets/sheet1.xml']??'';
    if($xml==='')sf_fail(422,'Cannot find first Excel sheet');
    $strings=[];
    if(isset($files['xl/sharedStrings.xml'])){
        preg_match_all('~<si(?:\s[^>]*)?>(.*?)</si>~s',$files['xl/sharedStrings.xml'],$matches);
        foreach($matches[1] as $piece){
            preg_match_all('~<t(?:\s[^>]*)?>(.*?)</t>~s',$piece,$chunks);
            $strings[]=implode('',array_map('sf_import_xml_text',$chunks[1]));
        }
    }
    preg_match_all('~<row\b[^>]*>(.*?)</row>~s',$xml,$rows);
    if(count($rows[1])>501)sf_fail(413,'Import a maximum of 500 enquiries at once');
    $out=[];
    foreach($rows[1] as $fragment){
        $line=[];preg_match_all('~<c\b([^>]*)(?:>(.*?)</c>|/>)~s',$fragment,$cells,PREG_SET_ORDER);
        foreach($cells as $cell){
            if(!preg_match('/\br="([A-Z]+)[0-9]+"/',$cell[1],$address))continue;
            $column=0;
            foreach(str_split($address[1]) as $char)$column=$column*26+(ord($char)-64);
            $column--;
            if($column>=40)sf_fail(422,'Only the first 40 columns are supported');
            $type=preg_match('/\bt="([^"]+)"/',$cell[1],$tm)?$tm[1]:'';
            $inner=$cell[2]??'';
            if($type==='s'){
                preg_match('~<v>(\d+)</v>~',$inner,$val);
                $text=$strings[(int)($val[1]??-1)]??'';
            }elseif($type==='inlineStr'){
                preg_match_all('~<t(?:\s[^>]*)?>(.*?)</t>~s',$inner,$values);
                $text=implode('',array_map('sf_import_xml_text',$values[1]));
            }else{
                preg_match('~<v[^>]*>(.*?)</v>~s',$inner,$val);
                $text=sf_import_xml_text($val[1]??'');
            }
            $line[$column]=$text;
        }
        if($line){ksort($line);$out[]=array_replace(array_fill(0,min(40,max(array_keys($line))+1),''),$line);}
    }
    return $out;
}
function sf_import_csv(string $content):array{
    $content=preg_replace('/^\xEF\xBB\xBF/','',$content)??$content;
    if(!preg_match('//u',$content))sf_fail(422,'Please export your CSV as UTF-8');
    $sep=substr_count(strtok($content,"\n"),"\t")>substr_count(strtok($content,"\n"),",")?"\t":",";
    $h=fopen('php://temp','w+');if(!$h)sf_fail(503,'Cannot read spreadsheet');
    try{
        fwrite($h,$content);rewind($h);$rows=[];
        while(($row=fgetcsv($h,0,$sep,'"','\\'))!==false){
            if($row===[null])continue;
            $rows[]=$row;
            if(count($rows)>501)sf_fail(413,'Import a maximum of 500 enquiries at once');
            if(count($row)>40)sf_fail(422,'Only the first 40 columns are supported');
        }
        return $rows;
    }finally{fclose($h);}
}
function sf_import_read(array $payload):array{
    $file=sf_text($payload,'filename',180,5);
    $data=sf_text($payload,'base64',3600000,10);
    $body=base64_decode($data,true);
    if(!is_string($body)||strlen($body)>2500000)sf_fail(413,'Spreadsheet exceeds the 2.5 MB import limit');
    if(preg_match('/\.csv$/iD',$file))$rows=sf_import_csv($body);
    elseif(preg_match('/\.xlsx$/iD',$file))$rows=sf_import_xlsx($body);
    else sf_fail(422,'Choose a .csv or .xlsx spreadsheet');
    if(count($rows)<2)sf_fail(422,'No enquiry rows found');
    return [$rows,hash('sha256',$body)];
}
function sf_import_header_map(array $headers,mixed $provided):array{
    $synonyms=[
      'timestamp'=>['timestamp','date','enquiry date','created at'],
      'name'=>['name','student','student name','student_name'],
      'email'=>['email','email address'],
      'phone'=>['phone','mobile','mobile number','contact number'],
      'city'=>['city','district'],
      'state'=>['state'],
      'grade'=>['class','grade','applying for','grade applying for'],
      'campus'=>['campus','branch','school'],
      'remarks'=>['remarks','follow up','follow-up','notes','remarks / follow-up notes'],
      'guardian'=>['parent','parent name','guardian','parent / guardian'],
      'academic_year'=>['academic year','year']
    ];
    if($provided!==null&&(!is_array($provided)||count($provided)>count(SF_IMPORT_FIELDS)))
        sf_fail(422,'Invalid column mapping');
    $map=[];
    foreach(SF_IMPORT_FIELDS as $field){
        if(is_array($provided)&&array_key_exists($field,$provided)){
            $index=$provided[$field];
            if($index===null||$index==='')continue;
            if(!is_int($index)||$index<0||$index>=count($headers))sf_fail(422,'Invalid column index for '.$field);
            $map[$field]=$index;continue;
        }
        foreach($headers as $index=>$label){
            if(in_array(strtolower(trim((string)$label)),$synonyms[$field],true)){$map[$field]=$index;break;}
        }
    }
    foreach(['name','phone'] as $required)if(!isset($map[$required]))sf_fail(422,'Map required column: '.$required);
    if(!isset($map['remarks']) && count($headers)>=9 && trim((string)($headers[8]??''))==='')$map['remarks']=8;
    if(count(array_unique($map))!==count($map))sf_fail(422,'Each imported field requires its own spreadsheet column');
    return $map;
}
function sf_import_timestamp(string $text):?string{
    if($text==='')return null;
    $zone=new DateTimeZone('Asia/Kolkata');
    if(is_numeric($text)&&((float)$text)>20000 && ((float)$text)<100000){
        $seconds=(int)round((((float)$text)-25569)*86400);
        $instant=(new DateTimeImmutable('@'.$seconds));
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$instant->format('Y-m-d H:i:s'),$zone);
        return $date?$date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM):null;
    }
    foreach(['d/m/Y h:i:s A','d/m/Y h:i A','d/m/Y H:i:s','d/m/Y','Y-m-d H:i:s','Y-m-d\TH:i:sP','Y-m-d'] as $format){
        $date=DateTimeImmutable::createFromFormat('!'.$format,$text,$zone);
        if($date && $date->format($format)===$text)return $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }
    return null;
}
function sf_import_rows(array $table,array $map,string $schoolName):array{
    $rows=[];$valid=0;$invalid=0;
    foreach(array_slice($table,1) as $lineIndex=>$line){
        $r=[];foreach($map as $field=>$position)$r[$field]=trim((string)($line[$position]??''));
        if(implode('',array_values($r))==='')continue;
        $issues=[];
        if(strlen($r['name']??'')<2||strlen($r['name'])>140)$issues[]='Student name required';
        $phone=preg_replace('/\D/','',$r['phone']??'');
        if(strlen($phone)<10||strlen($phone)>15)$issues[]='Valid phone number required';
        if(!empty($r['email'])&&!filter_var($r['email'],FILTER_VALIDATE_EMAIL))$issues[]='Invalid email';
        if(strlen($r['remarks']??'')>2000)$issues[]='Remarks must be under 2,000 characters';
        $campus=$r['campus']??'';
        if($campus!==''&&!str_contains(strtolower($schoolName),strtolower($campus))&&!str_contains(strtolower($campus),strtolower($schoolName)))
            $issues[]='Campus does not match selected school';
        $year=$r['academic_year']??'';
        if($year!=='' && (!preg_match('/^20[2-9][0-9]-[0-9]{2}$/D',$year) ||
            (int)substr($year,5,2)!==(((int)substr($year,2,2)+1)%100)))
            $issues[]='Invalid academic year';
        $date=sf_import_timestamp($r['timestamp']??'');
        if(!empty($r['timestamp'])&&$date===null)$issues[]='Cannot interpret timestamp';
        $r['normalized_phone']=$phone;
        $r['created_at_import']=$date;
        $r['row_number']=$lineIndex+2;
        $r['issues']=$issues;
        $rows[]=$r;
        if($issues)$invalid++;else $valid++;
    }
    return [$rows,$valid,$invalid];
}
function sf_import_find_duplicate(array $s,int $schoolId,array $row):?array{
    foreach($s['leads'] as $lead){
        if(($lead['school_id']??null)!==$schoolId)continue;
        $num=preg_replace('/\D/','',(string)($lead['normalized_phone']??$lead['phone']??''));
        if($num!==$row['normalized_phone'])continue;
        if(strtolower(trim((string)$lead['name']))!==strtolower(trim($row['name'])))continue;
        $grade=$row['grade']??'';
        if($grade!==''&&strtolower(trim((string)($lead['grade']??'')))!==strtolower($grade))continue;
        return $lead;
    }
    return null;
}
function sf_import_process(SchoolFlowStore $store,string $auth,int|string $schoolRef,string $action,array $payload):array{
    [$sheet,$digest]=sf_import_read($payload);
    $headers=array_slice($sheet[0],0,40);
    $mapping=sf_import_header_map($headers,$payload['mapping']??null);
    $mapDigest=hash('sha256',json_encode($mapping));
    if($action==='preview'){
        return $store->transaction(static function(array &$s)use($auth,$schoolRef,$sheet,$headers,$mapping,$digest,$mapDigest):array{
            $user=sf_session($s,$auth);$sid=sf_record_id($s,'schools',$schoolRef);
            sf_membership($s,$sid,$user['id'],['Admin','Admissions']);
            $school=sf_find($s['schools'],$sid);
            [$rows,$valid,$invalid]=sf_import_rows($sheet,$mapping,$school['name']);
            $duplicates=0;$seen=[];
            foreach($rows as &$r)if(!$r['issues']){
                $key=strtolower($r['name']).'|'.$r['normalized_phone'].'|'.strtolower($r['grade']??'');
                $duplicate=isset($seen[$key])||sf_import_find_duplicate($s,$sid,$r);
                $r['existing']=$duplicate?true:false;if($duplicate)$duplicates++;
                $seen[$key]=true;
            }unset($r);
            $receipt=sf_uuid();
            $s['import_previews']=array_values(array_filter($s['import_previews']??[],
                fn($p)=>($p['expires_at']??0)>time()));
            $s['import_previews'][]=['guid'=>$receipt,'school_id'=>$sid,'user_id'=>$user['id'],
                'digest'=>$digest,'map_hash'=>$mapDigest,'expires_at'=>time()+1200];
            sf_limit($s,'import-preview:'.$sid.':'.$user['id'],12,3600);
            return [200,['preview_guid'=>$receipt,'headers'=>$headers,'mapping'=>$mapping,
                'total'=>count($rows),'valid'=>$valid,'invalid'=>$invalid,'possible_duplicates'=>$duplicates,
                'samples'=>array_map(fn($r)=>[
                    'row_number'=>$r['row_number'],'name'=>$r['name']??'','phone'=>$r['phone']??'',
                    'campus'=>$r['campus']??'','remarks'=>$r['remarks']??'','issues'=>$r['issues'],
                    'existing'=>!empty($r['existing'])
                ],array_slice($rows,0,25))]];
        },true);
    }
    if($action!=='commit')sf_fail(405,'Invalid import action');
    $receipt=sf_text($payload,'preview_guid',36,36);
    $confirm=$payload['confirm']??false;
    if($confirm!==true)sf_fail(422,'Review and explicitly confirm the import');
    return $store->transaction(static function(array &$s)use($auth,$schoolRef,$sheet,$mapping,$digest,$mapDigest,$receipt):array{
        $user=sf_session($s,$auth);$uid=$user['id'];
        $sid=sf_record_id($s,'schools',$schoolRef);sf_membership($s,$sid,$uid,['Admin','Admissions']);
        $preview=null;
        foreach($s['import_previews']??[] as $p)if($p['guid']===$receipt && $p['school_id']===$sid && $p['user_id']===$uid &&
            $p['digest']===$digest && $p['map_hash']===$mapDigest && $p['expires_at']>time()){$preview=$p;break;}
        if(!$preview)sf_fail(409,'Import preview expired or file/mapping changed; preview again');
        $school=sf_find($s['schools'],$sid);
        [$rows,$valid,$invalid]=sf_import_rows($sheet,$mapping,$school['name']);
        if($invalid>0)sf_fail(422,'Fix invalid rows before importing; no records changed');
        $s['import_previews']=array_values(array_filter($s['import_previews'],fn($p)=>$p['guid']!==$receipt));
        $batch=sf_uuid();$added=[];$skipped=0;$notes=0;
        foreach($rows as $r){
            if(sf_import_find_duplicate($s,$sid,$r)){$skipped++;continue;}
            $lead=sf_create_lead($s,$sid,[
                'student_name'=>$r['name'],'parent_name'=>!empty($r['guardian'])?$r['guardian']:'Parent / guardian',
                'phone'=>$r['phone'],'email'=>$r['email']??'','grade'=>$r['grade']??'',
                'city'=>$r['city']??'','state'=>$r['state']??'','academic_year'=>$r['academic_year']??'',
                'consent'=>false,'message'=>'Historical spreadsheet import'
            ],null,$uid,null,'historical_import');
            $leadId=$lead['id'];$added[]=$leadId;
            foreach($s['leads'] as &$row)if($row['id']===$leadId){
                $row['created_at']=$r['created_at_import']??sf_time();
                $row['updated_at']=$row['created_at'];
                $row['import_batch_guid']=$batch;
                $row['import_source']='Spreadsheet';
                break;
            }unset($row);
            // Historical imports cannot automatically schedule new parent contact.
            foreach($s['tasks'] as &$task)if($task['lead_id']===$leadId&&$task['school_id']===$sid&&$task['status']==='Open'){
                $task['status']='Cancelled';$task['updated_at']=sf_time();
            }unset($task);
            if(!empty($r['remarks'])){
                $s['activities'][]=['id'=>sf_id($s,'activities'),'guid'=>sf_uuid(),'school_id'=>$sid,'lead_id'=>$leadId,
                    'actor_id'=>$uid,'action'=>'note','details'=>$r['remarks'],
                    'created_at'=>$r['created_at_import']??sf_time(),'import_batch_guid'=>$batch];
                $notes++;
            }
        }
        $s['import_batches']=$s['import_batches']??[];
        $s['import_batches'][]=['guid'=>$batch,'school_id'=>$sid,'actor_id'=>$uid,
            'created_at'=>sf_time(),'lead_ids'=>$added,'imported'=>count($added),
            'skipped'=>$skipped,'notes'=>$notes,'status'=>'committed'];
        sf_event($s,$sid,null,$uid,'historical_import_committed',
            count($added).' enquiries imported; '.$skipped.' existing matches skipped');
        return [201,['batch_guid'=>$batch,'imported'=>count($added),'skipped'=>$skipped,'notes'=>$notes]];
    },true);
}
function sf_import_rollback(SchoolFlowStore $store,string $auth,int|string $schoolRef,string $batchGuid):array{
    return $store->transaction(static function(array &$s)use($auth,$schoolRef,$batchGuid):array{
        $user=sf_session($s,$auth);$uid=$user['id'];$sid=sf_record_id($s,'schools',$schoolRef);
        sf_membership($s,$sid,$uid,['Admin']);
        $idx=null;foreach($s['import_batches']??[] as $i=>$b)if($b['guid']===$batchGuid && $b['school_id']===$sid){$idx=$i;break;}
        if($idx===null)sf_fail(404,'Import batch not found');
        $batch=$s['import_batches'][$idx];
        if($batch['status']!=='committed')sf_fail(409,'Batch already rolled back');
        if(strtotime($batch['created_at'])<time()-30*86400)sf_fail(409,'Rollback window expired');
        $ids=array_fill_keys($batch['lead_ids'],true);
        foreach($s['calls']??[] as $c)if(isset($ids[$c['lead_id']]))sf_fail(409,'A counsellor has contacted an imported lead. Rollback blocked');
        foreach($s['visits']??[] as $v)if(isset($ids[$v['lead_id']]))sf_fail(409,'An imported lead has visits. Rollback blocked');
        foreach($s['activities'] as $activity)if(isset($ids[$activity['lead_id']??0]) &&
            ($activity['action']??'')==='note' && ($activity['import_batch_guid']??'')!==$batchGuid)
            sf_fail(409,'An imported lead has newer notes. Rollback blocked');
        foreach($s['leads'] as $lead)if(isset($ids[$lead['id']])&&(($lead['import_batch_guid']??'')!==$batchGuid || ($lead['updated_at']??'')!==($lead['created_at']??'')))
            sf_fail(409,'Import record changed. Rollback blocked');
        $families=[];
        foreach($s['leads'] as $lead)if(isset($ids[$lead['id']]))$families[$lead['family_guid']??'']=true;
        $s['leads']=array_values(array_filter($s['leads'],fn($l)=>!isset($ids[$l['id']])));
        foreach(['activities','tasks','applications','admission_documents','notification_queue','stage_history','message_attempts'] as $table)
            if(isset($s[$table]))$s[$table]=array_values(array_filter($s[$table],fn($r)=>!isset($ids[$r['lead_id']??0])));
        // A sibling created after the import may now reference the same family.
        // Never remove a parent profile while any surviving student uses it.
        foreach($s['leads'] as $survivor)unset($families[$survivor['family_guid']??'']);
        if(isset($s['parent_profiles']))$s['parent_profiles']=array_values(array_filter(
            $s['parent_profiles'],fn($p)=>!isset($families[$p['guid']??''])));
        $s['import_batches'][$idx]['status']='rolled_back';
        $s['import_batches'][$idx]['rolled_back_at']=sf_time();
        $s['import_batches'][$idx]['rolled_back_by']=$uid;
        sf_event($s,$sid,null,$uid,'historical_import_rolled_back',count($ids).' imported leads removed');
        return [200,['rolled_back'=>count($ids),'batch_guid'=>$batchGuid]];
    },true);
}
