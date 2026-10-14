<?php
declare(strict_types=1);
/** End-to-end native XLSX binary response regression against packaged DigiOps gateway. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/app_private/api.php';
$root=dirname(__DIR__);
$gateway=$root.'/dist/digiops-release/index.php';
if(!is_file($gateway))throw new RuntimeException('Build DigiOps payload before XLSX HTTP test');
$tmp=sys_get_temp_dir().'/sf-http-xlsx-'.bin2hex(random_bytes(6));
mkdir($tmp.'/data',0700,true);$proc=null;$pipes=[];
function bx(bool $value,string $message):void{if(!$value)throw new RuntimeException('XLSX HTTP: '.$message);}
function bx_request(int $port,string $path,string $auth='http-xlsx-admin'):array {
    $context=stream_context_create(['http'=>[
        'method'=>'POST','header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".$auth."\r\n",
        'content'=>'{}','timeout'=>5,'ignore_errors'=>true
    ]]);
    $body=@file_get_contents('http://127.0.0.1:'.$port.$path,false,$context);
    $headers=$http_response_header??[];
    $code=preg_match('/^HTTP\/\d\.\d (\d{3})/',$headers[0]??'',$m)?(int)$m[1]:0;
    return [$code,$body,$headers];
}
try{
    $store=new SchoolFlowStore($tmp.'/data');
    $store->transaction(function(array &$s):void{
        $s['schools'][]=['id'=>1,'name'=>'Example Campus'];
        $s['schools'][]=['id'=>2,'name'=>'Other Campus'];
        $s['users'][]=['id'=>1,'name'=>'Export Administrator','email'=>'export@example.invalid','active'=>true];
        $s['memberships'][]=['school_id'=>1,'user_id'=>1,'role'=>'Admin'];
        $s['sessions'][]=['token_hash'=>sf_hash('http-xlsx-admin'),'user_id'=>1,'expires_at'=>time()+1000];
        $s['leads'][]=['id'=>1,'guid'=>sf_uuid(),'school_id'=>1,'name'=>'Test Child','guardian'=>'Test Parent',
            'email'=>'parent@example.invalid','phone'=>'09876543210','grade'=>'I','city'=>'Pune','state'=>'Maharashtra',
            'created_at'=>sf_time(),'status'=>'New','temperature'=>'Unrated','site_id'=>null,'assigned_to'=>null,'followup_date'=>''];
    },true);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$err);
    bx((bool)$socket,'allocate port');
    $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);
    fclose($socket);
    $router=$tmp.'/router.php';
    file_put_contents($router,"<?php\nrequire ".var_export($gateway,true).";\n");
    $proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname($gateway),$router],
        [0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],
        $pipes,$root,array_merge($_ENV,['SCHOOLFLOW_DATA_DIR'=>$tmp.'/data']));
    bx(is_resource($proc),'start packaged PHP gateway');
    if(isset($pipes[0])&&is_resource($pipes[0]))fclose($pipes[0]);
    $ready=false;
    for($i=0;$i<30;$i++){
        [$status,$body]=bx_request($port,'/scrm/api/schools/1/exports/admissions.xlsx');
        if($status===200){$ready=true;break;}
        usleep(100000);
    }
    bx($ready,'authenticated XLSX endpoint returned 200');
    bx(is_string($body) && str_starts_with($body,"PK\x03\x04"),'actual download contains ZIP workbook bytes');
    $heads=strtolower(implode("\n",$http_response_header??[])); // context header variables may differ, use returned headers below.
    [$ok,$data,$headers]=bx_request($port,'/scrm/api/schools/1/exports/admissions.xlsx');
    bx($ok===200 && str_starts_with((string)$data,"PK\x03\x04"),'binary download persists');
    $headersLower=strtolower(implode("\n",$headers));
    bx(str_contains($headersLower,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),'Excel Content-Type header');
    bx(str_contains($headersLower,'content-disposition: attachment; filename='),'download filename header');
    bx(str_contains($data,'PK'),'binary response is not JSON encoded');
    [$forbidden,$error]=bx_request($port,'/scrm/api/schools/2/exports/admissions.xlsx');
    bx($forbidden===403 && is_array(json_decode((string)$error,true)),'other school refused as JSON error');
    [$unauth,$error]=bx_request($port,'/scrm/api/schools/1/exports/admissions.xlsx','invalid-token');
    bx($unauth===401,'unauthenticated request rejected');
    echo "PASS: deployed PHP gateway serves XLSX binary with correct headers and enforces permissions\n";
}finally{
    if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}
    foreach(glob($tmp.'/data/*')?:[] as $f)@unlink($f);
    @rmdir($tmp.'/data');@unlink($tmp.'/router.php');@unlink($tmp.'/server.log');@rmdir($tmp);
}
