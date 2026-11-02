<?php
declare(strict_types=1);

/**
 * Public form website allowlisting. Additional approvals authorize an exact
 * HTTPS origin + path, ignoring only analytics query parameters on the page.
 * A group's original website domain remains backwards-compatible by default.
 */
function sf_landing_primary_host(array $group): string {
    $configured=trim((string)($group['domain']??''));
    $host=parse_url(str_contains($configured,'://')?$configured:'https://'.$configured,PHP_URL_HOST);
    return is_string($host)?strtolower($host):'';
}
function sf_landing_primary_origin(array $group,string $origin): bool {
    $host=parse_url($origin,PHP_URL_HOST);
    if(!is_string($host)||!preg_match('~^https://[a-z0-9.-]+(?::443)?$~iD',$origin))return false;
    $primary=sf_landing_primary_host($group);
    return $primary!==''&&preg_replace('/^www\./','',$primary)===preg_replace('/^www\./','',strtolower($host));
}
function sf_landing_origin(string $url): string {
    $parts=parse_url($url);
    if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'
       ||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])
       ||(isset($parts['port'])&&(int)$parts['port']!==443))return '';
    $host=strtolower((string)$parts['host']);
    if(!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host)||str_contains($host,'..'))return '';
    return 'https://'.$host;
}
function sf_landing_normalize_url(string $url): string {
    if($url!==trim($url)||strlen($url)>300||$url==='')sf_fail(422,'Enter a complete HTTPS landing page URL');
    $parts=parse_url($url);
    if(!is_array($parts)||isset($parts['query'])||isset($parts['fragment'])
       ||sf_landing_origin($url)==='')sf_fail(422,'Landing page URLs must use HTTPS without query strings or fragments');
    $path=(string)($parts['path']??'/');
    if($path==='')$path='/';
    if(strlen($path)>180||!preg_match('#^/[a-zA-Z0-9._~/-]*$#D',$path)
       ||str_contains($path,'//')||preg_match('#(?:^|/)\.{1,2}(?:/|$)#',$path))
        sf_fail(422,'Landing page path must be a simple, absolute URL path');
    return sf_landing_origin($url).(rtrim($path,'/')?:'/');
}
function sf_landing_settings(array $group): array {
    return ['approved_urls'=>array_values($group['approved_landing_urls']??[]),
        'restrict_primary'=>(bool)($group['restrict_primary_landing_pages']??false)];
}
function sf_landing_validate(array $input,array $group): array {
    foreach(array_keys($input) as $key)if(!in_array($key,['approved_urls','restrict_primary'],true))
        sf_fail(422,'Unknown landing approval setting');
    $urls=$input['approved_urls']??[];
    $restrict=$input['restrict_primary']??false;
    if(!is_bool($restrict)||!is_array($urls)||!array_is_list($urls)||count($urls)>25)
        sf_fail(422,'Choose up to 25 approved HTTPS landing page URLs');
    $clean=[];
    foreach($urls as $url){
        if(!is_string($url))sf_fail(422,'Landing page URLs must be text');
        $value=sf_landing_normalize_url($url);
        if(in_array($value,$clean,true))sf_fail(422,'Duplicate landing page URL');
        $clean[]=$value;
    }
    if($restrict&&!array_filter($clean,static fn(string $u):bool=>
        sf_landing_primary_origin($group,sf_landing_origin($u))))
        sf_fail(422,'Approve at least one primary-domain landing page before restricting it');
    return ['approved_urls'=>$clean,'restrict_primary'=>$restrict];
}
/** Strict secondary-domain origin approval; do not allow sibling subdomains. */
function sf_landing_origin_approved(array $group,string $origin): bool {
    if(sf_landing_origin($origin)===''||sf_landing_origin($origin)!==$origin)return false;
    if(sf_landing_primary_origin($group,$origin))return true;
    foreach($group['approved_landing_urls']??[] as $url)
        if(sf_landing_origin((string)$url)===$origin)return true;
    return false;
}
function sf_landing_page_approved(array $group,string $origin,string $page): bool {
    $primary=sf_landing_primary_origin($group,$origin);
    $strict=(bool)($group['restrict_primary_landing_pages']??false);
    if($page==='')return $primary&&!$strict; // legacy forms on the original domain
    if(strlen($page)>500||sf_landing_origin($page)!==$origin)return false;
    $parts=parse_url($page);
    if(!is_array($parts))return false;
    $path=(string)($parts['path']??'/');
    if($path==='')$path='/';
    if(strlen($path)>180||!preg_match('#^/[a-zA-Z0-9._~%/-]*$#D',$path)
       ||str_contains($path,'//')||preg_match('#(?:^|/)(?:\.|%2e){1,2}(?:/|$)#i',$path))
        return false;
    if($primary&&!$strict)return true;
    $key=$origin.(rtrim($path,'/')?:'/');
    foreach($group['approved_landing_urls']??[] as $approved){
        if(sf_landing_primary_origin($group,$origin) && sf_landing_primary_origin($group,sf_landing_origin((string)$approved))){
            $approvedParts=parse_url((string)$approved);
            if((rtrim((string)($approvedParts['path']??'/'),'/')?:'/')===(rtrim($path,'/')?:'/'))return true;
        }elseif($approved===$key)return true;
    }
    return false;
}
