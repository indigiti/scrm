<?php
declare(strict_types=1);
/**
 * Native, dependency-free XLSX export for SchoolFlow (PHP + JSON storage).
 * Rows come exclusively from the authorized school workspace; no browser-side PII export.
 */
function sf_xlsx_xml(string $value): string {
    $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',$value)??'';
    if(strlen($value)>30000)$value=substr($value,0,30000);
    return htmlspecialchars($value, ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
}
function sf_xlsx_col(int $zeroBased): string {
    $name='';
    for($n=$zeroBased+1;$n>0;$n=intdiv($n-1,26))$name=chr(65+(($n-1)%26)).$name;
    return $name;
}
function sf_xlsx_date(mixed $value): ?float {
    if(!is_string($value)||trim($value)==='')return null;
    try {
        $instant=new DateTimeImmutable($value,new DateTimeZone('UTC'));
        // Excel dates have no timezone: serialize the local India clock time.
        $local=$instant->setTimezone(new DateTimeZone('Asia/Kolkata'));
        $utcWall=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$local->format('Y-m-d H:i:s'),new DateTimeZone('UTC'));
        return $utcWall ? ((float)$utcWall->format('U'))/86400+25569 : null;
    } catch(Throwable) {return null;}
}
function sf_xlsx_sheet(array $headers,array $rows,array $widths,int $dateColumn=0,int $phoneColumn=3,int $notesColumn=8):string{
    $last=sf_xlsx_col(count($headers)-1);
    $size=count($rows)+1;
    $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml.='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml.='<dimension ref="A1:'.$last.$size.'"/>';
    $xml.='<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    $xml.='<sheetFormatPr defaultRowHeight="19"/><cols>';
    foreach($headers as $i=>$name)$xml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.($widths[$i]??20).'" customWidth="1"/>';
    $xml.='</cols><sheetData>';
    $all=array_merge([$headers],$rows);
    foreach($all as $index=>$row){
        $rowNum=$index+1;$header=$index===0;
        $rowHeight=19;
        if(!$header){
            $noteText=(string)($row[$notesColumn]??'');
            $lineCount=max(1,substr_count($noteText,"\n")+1,
                (int)ceil(strlen($noteText)/max(20,(int)(($widths[$notesColumn]??65)*0.85))));
            $rowHeight=min(105,max(19,$lineCount*18));
        }
        $xml.='<row r="'.$rowNum.'"'.($header?' ht="27" customHeight="1"':
            ($rowHeight>19?' ht="'.$rowHeight.'" customHeight="1"':'')).'>';
        foreach($headers as $col=>$ignored){
            $value=$row[$col]??'';
            $ref=sf_xlsx_col($col).$rowNum;
            if(!$header && $col===$dateColumn && is_string($value) && $value!==''){
                $serial=sf_xlsx_date($value);
                if($serial!==null){$xml.='<c r="'.$ref.'" s="2"><v>'.number_format($serial,10,'.','').'</v></c>';continue;}
            }
            $style=$header?1:($col===$notesColumn?3:($col===$phoneColumn?4:0));
            $xml.='<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.
                sf_xlsx_xml((string)$value).'</t></is></c>';
        }
        $xml.='</row>';
    }
    $xml.='</sheetData><autoFilter ref="A1:'.$last.$size.'"/>';
    $xml.='<pageMargins left="0.3" right="0.3" top="0.55" bottom="0.55" header="0.2" footer="0.2"/>';
    $xml.='</worksheet>';
    return $xml;
}
function sf_xlsx_zip(array $files):string{
    if(!function_exists('gzdeflate'))throw new RuntimeException('PHP zlib is required for Excel exports');
    $body='';$central='';$count=0;
    foreach($files as $name=>$data){
        $compressed=gzdeflate($data,6);
        if($compressed===false)throw new RuntimeException('Cannot compress Excel workbook');
        $crc=crc32($data);$nlen=strlen($name);$offset=strlen($body);
        $body.=pack('VvvvvvVVVvv',0x04034b50,20,0,8,0,0,$crc,strlen($compressed),strlen($data),$nlen,0)
            .$name.$compressed;
        $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,8,0,0,$crc,strlen($compressed),
            strlen($data),$nlen,0,0,0,0,0,$offset).$name;
        $count++;
    }
    return $body.$central.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($central),strlen($body),0);
}
function sf_xlsx_build(array $leads,array $history):string {
    $leadColumns=['Timestamp','Name','Email','Phone','City','State','Class','Campus',
        'Remarks / Follow-up Notes','Parent / Guardian','Lead Stage','Temperature','Follow-up Status',
        'Next Follow-up','Assigned Counsellor','Academic Year','Source','Pincode','UTM Campaign','Lead GUID'];
    $historyColumns=['Timestamp','Name','Phone','Campus','Activity','Outcome / Status','Notes','Recorded By','Lead GUID'];
    $xmlTypes='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
        '<Default Extension="xml" ContentType="application/xml"/>'.
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
        '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
        '</Types>';
    $rels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
        '</Relationships>';
    $workbook='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '.
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
        '<sheets><sheet name="Admission Enquiries" sheetId="1" r:id="rId1"/>'.
        '<sheet name="Follow-up History" sheetId="2" r:id="rId2"/></sheets></workbook>';
    $workbookRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'.
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'.
        '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'.
        '</Relationships>';
    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
        '<numFmts count="1"><numFmt numFmtId="165" formatCode="dd/mm/yyyy hh:mm:ss AM/PM"/></numFmts>'.
        '<fonts count="2"><font><sz val="10"/><name val="Calibri"/><color rgb="FF24334A"/></font>'.
        '<font><b/><sz val="10"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font></fonts>'.
        '<fills count="3"><fill><patternFill patternType="none"/></fill>'.
        '<fill><patternFill patternType="gray125"/></fill>'.
        '<fill><patternFill patternType="solid"><fgColor rgb="FF252D75"/><bgColor indexed="64"/></patternFill></fill></fills>'.
        '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'.
        '<border><left/><right/><top/><bottom style="thin"><color rgb="FFCFD8E4"/></bottom/><diagonal/></border></borders>'.
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'.
        '<cellXfs count="5">'.
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'.
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">'.
        '<alignment horizontal="center" vertical="center"/></xf>'.
        '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'.
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="top" wrapText="1"/></xf>'.
        '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'.
        '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    return sf_xlsx_zip([
        '[Content_Types].xml'=>$xmlTypes,
        '_rels/.rels'=>$rels,
        'xl/workbook.xml'=>$workbook,
        'xl/_rels/workbook.xml.rels'=>$workbookRels,
        'xl/styles.xml'=>$styles,
        'xl/worksheets/sheet1.xml'=>sf_xlsx_sheet($leadColumns,$leads,
            [23,25,34,20,22,22,15,26,74,26,24,18,23,20,26,17,26,14,27,39],0,3,8),
        'xl/worksheets/sheet2.xml'=>sf_xlsx_sheet($historyColumns,$history,
            [23,25,20,26,23,24,78,27,39],0,2,6),
    ]);
}
function sf_export_matches(array $lead,array $filters):bool{
    if(($filters['status']??'All')!=='All' && ($lead['status']??'')!==$filters['status'])return false;
    if(($filters['temperature']??'All')!=='All' && ($lead['temperature']??'Unrated')!==$filters['temperature'])return false;
    if(($filters['site']??'All')!=='All' && (string)($lead['site_id']??'')!==(string)$filters['site'])return false;
    if(($filters['assigned']??'All')!=='All'){
        $assigned=$filters['assigned'];
        if($assigned==='Unassigned' && !empty($lead['assigned_to']))return false;
        if($assigned!=='Unassigned' && (string)($lead['assigned_to']??'')!==(string)$assigned)return false;
    }
    $connected=!empty($lead['last_successful_contact_at']);
    if(($filters['contact']??'All')==='Pending' && $connected)return false;
    if(($filters['contact']??'All')==='Contacted' && !$connected)return false;
    $day=sf_admission_today();$due=$lead['followup_date']??'';
    switch($filters['due']??'All'){
        case 'Overdue': if(!$due||$due>=$day)return false;break;
        case 'Today': if($due!==$day)return false;break;
        case 'Upcoming': if(!$due||$due<=$day)return false;break;
        case 'Unscheduled': if($due!=='')return false;break;
    }
    $ts=(string)($lead['created_at']??'');
    if(($filters['from']??'')!==''||($filters['to']??'')!==''){
        try {$date=(new DateTimeImmutable($ts))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d');}
        catch(Throwable) {return false;}
        if(($filters['from']??'')!=='' && $date<$filters['from'])return false;
        if(($filters['to']??'')!=='' && $date>$filters['to'])return false;
    }
    $search=strtolower($filters['search']??'');
    if($search!==''){
        $haystack=strtolower(implode(' ',array_map('strval',[
            $lead['name']??'',$lead['guardian']??'',$lead['phone']??'',$lead['email']??'',$lead['grade']??''
        ])));
        if(!str_contains($haystack,$search))return false;
    }
    return true;
}
function sf_export_snapshot(SchoolFlowStore $store,string $auth,int|string $schoolRef,array $filters):array{
    foreach(['status','temperature','site','assigned','contact','due','search','from','to'] as $name){
        if(!is_string($filters[$name]??''))sf_fail(422,'Invalid report filter');
    }
    foreach(['from','to'] as $d)if(($filters[$d]??'')!=='')sf_date($filters[$d]);
    $limits=[
        'status'=>array_merge(['All'],SF_STAGES),
        'temperature'=>array_merge(['All'],SF_TEMPERATURES),
        'contact'=>['All','Pending','Contacted'],
        'due'=>['All','Overdue','Today','Upcoming','Unscheduled'],
    ];
    foreach($limits as $key=>$choices)if(isset($filters[$key]) && !in_array($filters[$key],$choices,true))
        sf_fail(422,'Invalid report filter');
    foreach(['site','assigned'] as $key)if(isset($filters[$key]) &&
        !preg_match('/^(All|Unassigned|[1-9][0-9]*)$/D',$filters[$key]))sf_fail(422,'Invalid report filter');

    if(($filters['from']??'')!==''&&($filters['to']??'')!==''&&$filters['from']>$filters['to'])
        sf_fail(422,'Report start date must precede end date');
    if(strlen((string)($filters['search']??''))>100)sf_fail(422,'Report search too long');
    return $store->transaction(static function(array &$s) use($auth,$schoolRef,$filters):array {
        $user=sf_session($s,$auth);$uid=$user['id'];
        $schoolId=sf_record_id($s,'schools',$schoolRef);$role=sf_membership($s,$schoolId,$uid);
        $school=sf_find($s['schools'],$schoolId);
        $selected=[];
        foreach($s['leads'] as $lead){
            if($lead['school_id']!==$schoolId || ($role==='Counsellor'&&$lead['assigned_to']!==$uid))continue;
            if(sf_export_matches($lead,$filters))$selected[]=$lead;
        }
        if(count($selected)>10000)sf_fail(413,'Please narrow your filters: Excel export is limited to 10,000 enquiries');
        usort($selected,static fn($a,$b)=>strcmp($b['created_at']??'',$a['created_at']??''));
        $ids=array_fill_keys(array_column($selected,'id'),true);
        $events=[];
        $add=static function(int $id,array $event)use(&$events):void{$events[$id][]=$event;};
        foreach($s['activities']??[] as $activity)if($activity['school_id']===$schoolId &&
            isset($ids[$activity['lead_id']??0])&&($activity['action']??'')==='note'){
            $actor=isset($activity['actor_id'])&&$activity['actor_id']!==null?sf_user($s,(int)$activity['actor_id']):null;
            $add((int)$activity['lead_id'],[$activity['created_at']??'','Conversation Note','',
                (string)($activity['details']??''),$actor['name']??'Admissions team']);
        }
        foreach($s['calls']??[] as $call)if($call['school_id']===$schoolId && isset($ids[$call['lead_id']??0])){
            $actor=isset($call['actor_id'])&&$call['actor_id']!==null?sf_user($s,(int)$call['actor_id']):null;
            $add((int)$call['lead_id'],[$call['created_at']??'','Call',(string)($call['outcome']??''),
                (string)($call['note']??''),$actor['name']??'Admissions team']);
        }
        foreach($s['visits']??[] as $visit)if($visit['school_id']===$schoolId && isset($ids[$visit['lead_id']??0])){
            $actor=isset($visit['staff_id'])&&$visit['staff_id']!==null?sf_user($s,(int)$visit['staff_id']):null;
            $add((int)$visit['lead_id'],[$visit['created_at']??$visit['starts_at']??'','Campus Visit',
                (string)($visit['status']??''),(string)($visit['purpose']??''),$actor['name']??'Admissions team']);
        }
        $leadRows=[];$historyRows=[];
        foreach($selected as $lead){
            $lid=$lead['id'];$items=$events[$lid]??[];
            if(($lead['message']??'')!=='')$items[]=[$lead['created_at']??'','Enquiry Message','',
                (string)$lead['message'],'Website / parent'];
            usort($items,static fn($a,$b)=>strcmp($b[0],$a[0]));
            $remarks=[];
            foreach($items as $e){
                $text=trim((string)$e[3]);
                if($text==='')$text=(string)$e[2];
                elseif($e[2]!=='')$text=$e[2].': '.$text;
                if($text!=='' && count($remarks)<3)$remarks[]=$text;
                if(count($historyRows)>=50000)sf_fail(413,'Too much history to export; narrow your filters');
                $historyRows[]=[(string)$e[0],(string)($lead['name']??''),(string)($lead['phone']??''),
                    (string)($school['name']??''),(string)$e[1],(string)$e[2],(string)$e[3],(string)$e[4],
                    (string)($lead['guid']??'')];
            }
            $owner=!empty($lead['assigned_to'])?sf_user($s,(int)$lead['assigned_to']):null;
            $leadRows[]=[
                (string)($lead['created_at']??''),(string)($lead['name']??''),(string)($lead['email']??''),
                (string)($lead['phone']??''),(string)($lead['city']??''),(string)($lead['state']??''),
                (string)($lead['grade']??''),(string)($school['name']??''),
                implode("\n",$remarks),(string)($lead['guardian']??''),(string)($lead['status']??'New'),
                (string)($lead['temperature']??'Unrated'),sf_admission_followup($lead),
                (string)($lead['followup_date']??''),(string)($owner['name']??''),
                (string)($lead['academic_year']??''),(string)(sf_source($s,(int)($lead['site_id']??0))['name']??'Manual'),
                (string)($lead['pincode']??''),(string)($lead['utm_campaign']??''),(string)($lead['guid']??'')];
        }
        usort($historyRows,static fn($a,$b)=>strcmp($b[0],$a[0]));
        sf_event($s,$schoolId,null,$uid,'admissions_xlsx_exported','Exported '.count($leadRows).' enquiries and '.count($historyRows).' activity rows');
        return [$leadRows,$historyRows];
    },true);
}
function sf_export_admissions(SchoolFlowStore $store,string $auth,int|string $schoolRef,array $filters):string{
    [$leads,$events]=sf_export_snapshot($store,$auth,$schoolRef,$filters);
    return sf_xlsx_build($leads,$events);
}
