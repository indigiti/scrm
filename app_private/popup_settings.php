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
