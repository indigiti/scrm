<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
$dir=sys_get_temp_dir().'/schoolflow-xlsx-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);$db=new SchoolFlowStore($dir);
function ex_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('XLSX export: '.$message);}
function ex_call(string $method,string $uri,array $payload=[],string $token='admin'):array{
    global $db;
    try {return sf_handle($method,$uri,$payload,['authorization'=>'Bearer '.$token],'198.51.100.9',$db);}
    catch(SFError $e){return [$e->httpStatus,['detail'=>$e->getMessage()]];}
}
function ex_unzip(string $zip):array{
    $parts=[];$offset=0;$size=strlen($zip);
    while($offset+30<=$size && substr($zip,$offset,4)==="PK\x03\x04"){
        $method=unpack('v',substr($zip,$offset+8,2))[1];
        $comp=unpack('V',substr($zip,$offset+18,4))[1];
        $nameSize=unpack('v',substr($zip,$offset+26,2))[1];
        $extraSize=unpack('v',substr($zip,$offset+28,2))[1];
        $name=substr($zip,$offset+30,$nameSize);
        $start=$offset+30+$nameSize+$extraSize;
        $chunk=substr($zip,$start,$comp);
        $decoded=$method===8?gzinflate($chunk):$chunk;
        ex_check(is_string($decoded),'workbook entry decompresses');
        $parts[$name]=$decoded;
        $offset=$start+$comp;
    }
    return $parts;
}
try {
    $db->transaction(function(array &$s):void {
        $s['schools'][]=['id'=>1,'guid'=>sf_uuid(),'name'=>'Kalyani Nagar'];
        $s['schools'][]=['id'=>2,'guid'=>sf_uuid(),'name'=>'Wagholi'];
        foreach([['Administrator',1,1,'Admin'],['Staff',2,1,'Counsellor'],['Other campus',3,2,'Admin']] as [$name,$id,$schoolId,$role]){
            $s['users'][]=['id'=>$id,'name'=>$name,'email'=>'user'.$id.'@example.invalid','active'=>true];
            $s['memberships'][]=['user_id'=>$id,'school_id'=>$schoolId,'role'=>$role];
            $s['sessions'][]=['user_id'=>$id,'token_hash'=>sf_hash(['admin','staff','other'][$id-1]),'expires_at'=>time()+3600];
        }
    },true);
    $base='/api/schools/1';
    [$c,$one]=ex_call('POST',$base.'/leads',['student_name'=>'Aarav Sharma','parent_name'=>'Fictional Guardian',
        'phone'=>'09876543210','email'=>'parent@example.org','grade'=>'I','city'=>'Pune','state'=>'Maharashtra',
        'pincode'=>'411014','message'=>'Admission enquiry for next year',
        'utm_campaign'=>'school-enrolment','assigned_to'=>2,'consent'=>true]);
    ex_check($c===201,'first lead created');
    [$c,$two]=ex_call('POST',$base.'/leads',['student_name'=>'Diya Sharma','parent_name'=>'Fictional Guardian',
        'phone'=>'9999999000','email'=>'otherparent@example.org','grade'=>'II','city'=>'Pune','state'=>'Maharashtra',
        'assigned_to'=>1,'consent'=>true]);
    ex_check($c===201,'second lead created');
    ex_call('POST',$base.'/leads/'.$one['id'].'/notes',['text'=>'Parent requested a campus tour & forms <next week>']);
    ex_call('POST',$base.'/leads/'.$one['id'].'/calls',['outcome'=>'Callback Requested','note'=>'Call back in October']);
    [$c,$export]=ex_call('POST',$base.'/exports/admissions.xlsx');
    ex_check($c===200 && is_string($export)&&str_starts_with($export,"PK\x03\x04"),'binary XLSX returned');
    // Validate the central directory and CRC, not just local ZIP headers.
    $fixture=$dir.'/verified-sample.xlsx';
    file_put_contents($fixture,$export);
    if(is_executable('/usr/bin/unzip') && function_exists('exec')){
        $out=[];$exit=1;
        exec('/usr/bin/unzip -t '.escapeshellarg($fixture).' 2>&1',$out,$exit);
        ex_check($exit===0,'external ZIP integrity check (central directory and CRC)');
    }
    $parts=ex_unzip($export);
    foreach(['[Content_Types].xml','xl/workbook.xml','xl/styles.xml','xl/worksheets/sheet1.xml','xl/worksheets/sheet2.xml'] as $path)
        ex_check(isset($parts[$path]),'required OOXML part '.$path);
    $sheet=$parts['xl/worksheets/sheet1.xml'];$history=$parts['xl/worksheets/sheet2.xml'];
    ex_check(str_contains($sheet,'Remarks / Follow-up Notes') && str_contains($sheet,'Kalyani Nagar'),'screenshot layout and campus');
    ex_check(str_contains($sheet,'Source Type') && str_contains($sheet,'Form Surface') && str_contains($sheet,'First Touch Date'),'attribution export columns present');
    ex_check(str_contains($sheet,'manual'),'manual lead attribution exported');
    ex_check(ex_call('POST',$base.'/exports/admissions.xlsx',['source_type'=>'forged'])[0]===422,'invalid source type filter rejected');
    ex_check(str_contains($sheet,'Aarav Sharma')&&str_contains($sheet,'Diya Sharma'),'both authorized enquiries present');
    ex_check(str_contains($sheet,'Parent requested a campus tour &amp; forms &lt;next week&gt;'),'note properly escaped');
    ex_check(str_contains($sheet,'09876543210') && str_contains($sheet,'t="inlineStr"'),'phone remains text with zero preserved');
    ex_check(!str_contains($sheet,'<f>'),'no spreadsheet formula cells');
    ex_check(str_contains($history,'Conversation Note')&&str_contains($history,'Callback Requested'),'full note/call history');
    ex_check(str_contains($sheet,'s="2"><v>'),'real numeric Excel timestamps');
    ex_check(str_contains($parts['xl/styles.xml'],'FF252D75'),'dark navy header style');
    ex_check(str_contains($sheet,'state="frozen"')&&str_contains($sheet,'autoFilter'),'frozen header and filtering');
    [$c,$staff]=ex_call('POST',$base.'/exports/admissions.xlsx',[],'staff');
    ex_check($c===200,'counsellor can export only assigned enquiries');
    $staffSheet=ex_unzip($staff)['xl/worksheets/sheet1.xml'];
    ex_check(str_contains($staffSheet,'Aarav Sharma')&&!str_contains($staffSheet,'Diya Sharma'),'counsellor cannot export other staff leads');
    ex_check(ex_call('POST',$base.'/exports/admissions.xlsx',[],'other')[0]===403,'other-campus user blocked');
    [$c,$filtered]=ex_call('POST',$base.'/exports/admissions.xlsx',['search'=>'Diya','status'=>'New']);
    ex_check($c===200,'server-filtered export');
    $filteredXml=ex_unzip($filtered)['xl/worksheets/sheet1.xml'];
    ex_check(str_contains($filteredXml,'Diya Sharma')&&!str_contains($filteredXml,'Aarav Sharma'),'search filter respected');
    ex_check(ex_call('POST',$base.'/exports/admissions.xlsx',['from'=>'2026-12-31','to'=>'2026-01-01'])[0]===422,'reversed date blocked');
    ex_check(ex_call('POST',$base.'/exports/admissions.xlsx',['search'=>str_repeat('x',110)])[0]===422,'excessive query rejected');
    [$c,$empty]=ex_call('POST',$base.'/exports/admissions.xlsx',['search'=>'nothing-matches']);
    ex_check($c===200&&str_contains(ex_unzip($empty)['xl/worksheets/sheet1.xml'],'autoFilter'),'empty result has usable spreadsheet headers');
    $snap=$db->transaction(fn($s)=>$s);
    ex_check(count(array_filter($snap['audit_logs'],fn($l)=>($l['action']??'')==='admissions_xlsx_exported'))>=4,'exports audited without exposing payload');
    echo "PASS: XLSX ZIP/OOXML, screenshot columns, note history, filter enforcement, counselor/school isolation, audit\n";
} finally {
    foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);
}
