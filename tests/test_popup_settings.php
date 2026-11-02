<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app_private/api.php';
function popupCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Popup settings: '.$message);}
function popupReject(array $data,array $group):bool{
    try{sf_popup_validate($data,$group);return false;}
    catch(SFError $e){return $e->httpStatus===422;}
}
$group=['routes'=>[
    ['school_id'=>10,'site_id'=>100,'code'=>'campus-10'],
    ['school_id'=>20,'site_id'=>200,'code'=>'campus-20']
]];
$defaults=sf_popup_settings($group);
popupCheck($defaults['enabled']===false,'existing embeds remain manual-only');
popupCheck($defaults['trigger']==='either','trigger has safe documented default');
$p=sf_popup_validate([
    'enabled'=>true,'trigger'=>'either','delay_seconds'=>8,'scroll_percent'=>40,
    'include_paths'=>['/admissions','/apply/'],'exclude_paths'=>['/thank-you'],
    'start_date'=>'2026-11-01','end_date'=>'2027-03-31',
    'school_ids'=>[10],'repeat_after'=>'7','position'=>'right'
],$group);
popupCheck($p['include_paths']===['/admissions','/apply'],'normalizes URL prefixes');
popupCheck($p['school_ids']===[10]&&$p['enabled']===true,'valid authorized campus targeting');
popupCheck(popupReject(['school_ids'=>[30]],$group),'reject cross-group campus targeting');
popupCheck(popupReject(['school_ids'=>[10,10]],$group),'reject duplicate campus targets');
popupCheck(popupReject(['delay_seconds'=>-1],$group),'reject negative timing');
popupCheck(popupReject(['scroll_percent'=>99],$group),'reject scroll over 90');
popupCheck(popupReject(['start_date'=>'2027-12-01','end_date'=>'2027-01-01'],$group),'reject inverted campaign period');
popupCheck(popupReject(['include_paths'=>['https://example.com/admissions']],$group),'reject external URLs');
popupCheck(popupReject(['include_paths'=>['/a?next=1']],$group),'reject query strings');
popupCheck(popupReject(['enabled'=>'true'],$group),'reject string booleans');
popupCheck(popupReject(['secret_api_key'=>'example'],$group),'reject unsupported private configuration');
echo "PASS: popup defaults, validation, URL targeting, schedule and group campus isolation\n";
