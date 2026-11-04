<?php
declare(strict_types=1);

/**
 * Short-form popup campaign settings belong to a group website, not to its
 * selected destination school. Old embeds remain manual-only until an admin
 * explicitly enables automatic triggering.
 */
function sf_popup_defaults(): array {
    return [
        'enabled'=>false,
        'trigger'=>'either',
        'delay_seconds'=>8,
        'scroll_percent'=>40,
        'exit_intent'=>true,
        'mobile_enabled'=>true,
        'repeat_after'=>'7',
        'position'=>'center',
        'include_paths'=>[],
        'exclude_paths'=>[],
        'start_date'=>'',
        'end_date'=>'',
        'start_time'=>'',
        'end_time'=>'',
        'school_ids'=>[],
    ];
}

function sf_popup_settings(array $group): array {
    $saved=$group['popup_settings']??[];
    if(!is_array($saved))$saved=[];
    return array_replace(sf_popup_defaults(),array_intersect_key($saved,sf_popup_defaults()));
}

/** Reject unknown keys and invalid/ambiguous targeting rules before storage. */
function sf_popup_validate(array $input,array $group): array {
    $defaults=sf_popup_defaults();
    foreach(array_keys($input) as $key)if(!array_key_exists($key,$defaults))sf_fail(422,'Unknown popup setting: '.$key);
    $p=array_replace($defaults,$input);
    foreach(['enabled','exit_intent','mobile_enabled'] as $key)
        if(!is_bool($p[$key]))sf_fail(422,$key.' must be true or false');
    if(!in_array($p['trigger'],['delay','scroll','either','exit','manual'],true))
        sf_fail(422,'Invalid popup trigger');
    if(!is_int($p['delay_seconds'])||$p['delay_seconds']<0||$p['delay_seconds']>60)
        sf_fail(422,'Popup delay must be between 0 and 60 seconds');
    if(!is_int($p['scroll_percent'])||$p['scroll_percent']<10||$p['scroll_percent']>90)
        sf_fail(422,'Scroll depth must be between 10 and 90 percent');
    if(!in_array($p['repeat_after'],['session','1','7','30','never'],true))
        sf_fail(422,'Invalid popup repeat setting');
    if(!in_array($p['position'],['center','right','bottom'],true))
        sf_fail(422,'Invalid popup position');
    foreach(['include_paths','exclude_paths'] as $key){
        $items=$p[$key];
        if(!is_array($items)||!array_is_list($items)||count($items)>20)
            sf_fail(422,$key.' must have at most 20 path prefixes');
        $clean=[];
        foreach($items as $item){
            if(!is_string($item)||strlen($item)>120||!preg_match('#^/(?:[a-zA-Z0-9._~%/-]*)$#D',$item)||
                str_contains($item,'//')||str_contains($item,'/../')||str_contains($item,'/./'))
                sf_fail(422,'Use website path prefixes beginning with / (no query strings)');
            $item=rtrim($item,'/')?:'/';
            if(!in_array($item,$clean,true))$clean[]=$item;
        }
        $p[$key]=$clean;
    }
    $p['start_date']=sf_date($p['start_date']);
    $p['end_date']=sf_date($p['end_date']);
    foreach(['start_time','end_time'] as $timeKey){
        if(!is_string($p[$timeKey])||($p[$timeKey]!==''&&
            !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$p[$timeKey])))
            sf_fail(422,'Campaign time must use 24-hour HH:MM format');
    }
    if(($p['start_time']==='')!==($p['end_time']===''))
        sf_fail(422,'Campaign start and end times must both be set or both blank');
    if($p['start_date']!==''&&$p['end_date']!==''&&$p['start_date']>$p['end_date'])
        sf_fail(422,'Popup end date cannot precede its start date');
    $allowed=array_map(static fn(array $route):int=>(int)$route['school_id'],$group['routes']??[]);
    if(!is_array($p['school_ids'])||!array_is_list($p['school_ids'])||count($p['school_ids'])>60)
        sf_fail(422,'Invalid popup school targeting');
    $ids=[];
    foreach($p['school_ids'] as $id){
        if(!is_int($id)||!in_array($id,$allowed,true)||in_array($id,$ids,true))
            sf_fail(422,'Popup may target only schools connected to this group');
        $ids[]=$id;
    }
    $p['school_ids']=$ids;
    return $p;
}

/** Public config contains only a safe routing-code subset; no private keys. */
function sf_popup_public(array $s,array $group): array {
    $settings=sf_popup_settings($group);
    $chosen=$settings['school_ids'];
    $settings['school_codes']=array_values(array_map(
        static fn(array $r):string=>(string)$r['code'],
        array_filter(sf_group_campuses($s,$group),
            static fn(array $r):bool=>$chosen===[]||in_array((int)$r['school_id'],$chosen,true))
    ));
    unset($settings['school_ids']);
    return $settings;
}


/**
 * Optional page-scoped short-popup campaigns. A group may serve several school
 * landing pages; do not make one global popup control all of them.
 * Campaigns reference stable public GUIDs and never contain private credentials.
 */
function sf_page_popup_campaigns(array $group):array {
    $campaigns=$group['page_popup_campaigns']??[];
    return is_array($campaigns)?array_values(array_filter($campaigns,'is_array')):[];
}
/**
 * A school website popup is bound to the registered group primary domain.
 * It is not an inline/LP approval. Optional path prefixes target pages on
 * that authorized website, without introducing a second domain-approval step.
 * Legacy "page" campaigns remain readable and editable.
 */
function sf_page_popup_validate(array $payload,array $group):array {
    foreach(array_keys($payload) as $key)
        if(!in_array($key,['name','scope','landing_page','settings'],true))
            sf_fail(422,'Unknown website popup field');
    $name=sf_text($payload,'name',90,3);
    $scope=$payload['scope']??'page';
    if(!in_array($scope,['website','page'],true))sf_fail(422,'Choose website or exact-page targeting');
    if(!isset($payload['settings'])||!is_array($payload['settings']))
        sf_fail(422,'Popup settings are required');
    $settings=sf_popup_validate($payload['settings'],$group);
    if($scope==='website'){
        $host=sf_landing_primary_host($group);
        $origin=sf_landing_origin('https://'.$host);
        if($origin===''||$origin!=='https://'.$host)sf_fail(422,'Connect a valid HTTPS school website before creating a popup');
        $landing=$origin.'/';
        // A website-scoped campaign belongs to its registered domain,
        // regardless of inline LP strict-primary path preferences.
    }else{
        $landing=sf_landing_normalize_url(sf_text($payload,'landing_page',300,12));
        $origin=sf_landing_origin($landing);
        if(!sf_landing_primary_origin($group,$origin)&&
           (!sf_landing_origin_approved($group,$origin)||
            !sf_landing_page_approved($group,$origin,$landing)))
            sf_fail(422,'Authorize this external URL or use the school website popup scope');
        $settings['include_paths']=[];
        $settings['exclude_paths']=[];
    }
    foreach(sf_submission_settings($group)['thank_you_urls'] as $thankYou)
        if($scope==='page'&&rtrim($thankYou,'/')===rtrim($landing,'/'))
            sf_fail(422,'Do not show a popup on a thank-you page');
    return ['name'=>$name,'scope'=>$scope,'landing_page'=>$landing,'settings'=>$settings];
}
function sf_popup_campaign_match(array $group,string $campaignGuid,string $origin,string $page,
    ?string $campusCode=null):bool {
    if(!preg_match('/^[a-f0-9-]{36}$/iD',$campaignGuid)||$page===''||
        sf_landing_origin($page)!==$origin)return false;
    $parts=parse_url($page);
    if(!is_array($parts)||isset($parts['user'])||isset($parts['pass']))return false;
    $path=(string)($parts['path']??'/');
    if($path==='')$path='/';
    if(strlen($path)>180||!preg_match('#^/[a-zA-Z0-9._~%/-]*$#D',$path)||
       str_contains($path,'//')||preg_match('#(?:^|/)(?:\.|%2e){1,2}(?:/|$)#i',$path))return false;
    $normalized=$origin.(rtrim($path,'/')?:'/');
    foreach(sf_page_popup_campaigns($group) as $campaign){
        if(!hash_equals((string)($campaign['guid']??''),$campaignGuid))continue;
        $settings=$campaign['settings']??[];
        if(!is_array($settings)||empty($settings['enabled']))return false;
        $scope=(string)($campaign['scope']??'page');
        $destination=(string)($campaign['landing_page']??'');
        if($scope==='website'){
            if(!sf_landing_primary_origin($group,$origin)||
                sf_landing_origin($destination)!==$origin)return false;
            $matches=static fn(string $prefix):bool=>$prefix==='/'||
                $path===$prefix||str_starts_with($path,rtrim($prefix,'/').'/');
            $allowed=$settings['include_paths']??[];
            $excluded=$settings['exclude_paths']??[];
            if($allowed&&!array_filter($allowed,$matches))return false;
            if(array_filter($excluded,$matches))return false;
        }elseif($scope==='page'){
            if(rtrim($destination,'/')!==rtrim($normalized,'/')||
                (!sf_landing_primary_origin($group,$origin)&&
                 !sf_landing_page_approved($group,$origin,$page)))return false;
        }else return false;
        foreach(sf_submission_settings($group)['thank_you_urls'] as $thanks)
            if(rtrim($thanks,'/')===rtrim($normalized,'/'))return false;
        if($campusCode!==null){
            $allowedSchools=$settings['school_ids']??[];
            if($allowedSchools){
                $schoolAllowed=false;
                foreach($group['routes']??[] as $route)
                    if((string)$route['code']===$campusCode&&
                        in_array((int)$route['school_id'],$allowedSchools,true))$schoolAllowed=true;
                if(!$schoolAllowed)return false;
            }
        }
        return true;
    }
    return false;
}
function sf_page_popup_public(array $s,array $group):array {
    $out=[];
    foreach(sf_page_popup_campaigns($group) as $campaign){
        $settings=$campaign['settings']??[];
        if(!is_array($settings)||empty($settings['enabled']))continue;
        $landing=(string)($campaign['landing_page']??'');
        $scope=(string)($campaign['scope']??'page');
        if($scope==='website'){
            if(!sf_landing_primary_origin($group,sf_landing_origin($landing)))continue;
        }elseif(!sf_landing_primary_origin($group,sf_landing_origin($landing))&&
                  !sf_landing_page_approved($group,sf_landing_origin($landing),$landing))continue;
        $popup=sf_popup_public($s,array_replace($group,['popup_settings'=>$settings]));
        $out[]=['guid'=>(string)$campaign['guid'],'scope'=>$scope,
            'landing_page'=>$landing,'popup'=>$popup];
    }
    return $out;
}


/**
 * Reversible per-surface publication switches for one group form.
 * These are not campaign scheduling flags: an OFF surface must not render
 * even its manual enquiry button on a website carrying an old embed tag.
 * Existing installations remain enabled until an Admin explicitly switches off.
 */
function sf_form_surface_settings(array $group):array {
    $defaults=['inline_enabled'=>true,'group_popup_enabled'=>true,'detailed_popup_enabled'=>true];
    $saved=$group['form_surface_settings']??[];
    if(!is_array($saved))$saved=[];
    return array_replace($defaults,array_intersect_key($saved,$defaults));
}
function sf_form_surface_validate(array $input,array $group):array {
    $current=sf_form_surface_settings($group);
    if(!$input)sf_fail(422,'Select a form surface to update');
    foreach($input as $key=>$value){
        if(!array_key_exists($key,$current)||!is_bool($value))
            sf_fail(422,'Unsupported form surface setting');
        $current[$key]=$value;
    }
    return $current;
}
