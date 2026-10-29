<?php
declare(strict_types=1);
/** Read-only consistency report for SchoolFlow JSON. Does not print PII. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==realpath(__FILE__))return;
$options=getopt('',['data-dir:','strict']);
$dir=realpath((string)($options['data-dir']??''));
if($dir===false||!is_file($dir.'/store.json')){
    fwrite(STDERR,"Usage: php bin/verify-store.php --data-dir=/absolute/private_html/scrm/data [--strict]\n");exit(2);
}
try{
    $raw=file_get_contents($dir.'/store.json');
    if(!is_string($raw)||$raw==='')throw new RuntimeException('JSON store missing');
    $s=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($s)||($s['version']??null)!==1)throw new RuntimeException('Store schema invalid');
    $errors=[];$counts=[];$schoolIds=[];$userIds=[];$leadIds=[];
    foreach($s['schools']??[] as $x)$schoolIds[(string)($x['id']??'')]=true;
    foreach($s['users']??[] as $x)$userIds[(string)($x['id']??'')]=true;
    foreach($s['leads']??[] as $x)$leadIds[(string)($x['id']??'')]=(string)($x['school_id']??'');
    foreach($s as $key=>$v)if(is_array($v)&&$key!=='counters')$counts[$key]=count($v);
    foreach($s['memberships']??[] as $m){
        if(!isset($schoolIds[(string)($m['school_id']??'')]))$errors[]='member_school_missing';
        if(!isset($userIds[(string)($m['user_id']??'')]))$errors[]='member_user_missing';
    }
    foreach($s['leads']??[] as $lead)if(!isset($schoolIds[(string)($lead['school_id']??'')]))$errors[]='lead_school_missing';
    foreach($s['sites']??[] as $site)if(!isset($schoolIds[(string)($site['school_id']??'')]))$errors[]='source_school_missing';
    foreach(['tasks','calls','visits','applications','admission_documents','stage_history'] as $collection)
        foreach($s[$collection]??[] as $entry)if(isset($entry['lead_id']) &&
            ($leadIds[(string)$entry['lead_id']]??null)!==(string)($entry['school_id']??''))$errors[]='orphan_'.$collection;
    foreach(['users','schools','leads','sites'] as $collection){
        $seen=[];
        foreach($s[$collection]??[] as $item){
            $id=(string)($item['id']??'');
            if($id===''||isset($seen[$id]))$errors[]='duplicate_or_missing_'.$collection.'_id';
            $seen[$id]=true;
        }
    }
    $keys=[];
    foreach(['messaging.key','public-form.key'] as $name){
        $file=$dir.'/'.$name;$exists=is_file($file);
        $keys[$name]=['present'=>$exists,'valid'=>$exists?filesize($file)===32:null];
        if($exists&&filesize($file)!==32)$errors[]='invalid_'.$name;
    }
    $summary=array_count_values($errors);ksort($summary);ksort($counts);
    echo json_encode(['ok'=>!$errors,'sha256'=>hash('sha256',$raw),
        'counts'=>$counts,'keys'=>$keys,'findings'=>$summary],
        JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit($errors&&array_key_exists('strict',$options)?1:0);
}catch(Throwable $error){fwrite(STDERR,"Verification stopped: ".$error->getMessage()."\n");exit(2);}
