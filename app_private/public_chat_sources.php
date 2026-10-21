<?php
declare(strict_types=1);
/** Reviewed school website source library; scanning never directly publishes. */
function sf_counsellor_source_host(array $s,int $schoolId,string $url):string {
    if(strlen($url)>600 || !filter_var($url,FILTER_VALIDATE_URL))sf_fail(422,'Enter a valid public HTTPS URL');
    $parts=parse_url($url);
    if(!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https' ||
       isset($parts['user']) || isset($parts['pass']) ||
       (isset($parts['port']) && (int)$parts['port']!==443) ||
       !empty($parts['fragment']))sf_fail(422,'Only clean public HTTPS school URLs are allowed');
    $host=strtolower((string)($parts['host']??''));
    if(!preg_match('/^[a-z0-9][a-z0-9.-]{2,250}[a-z0-9]$/D',$host) ||
       filter_var($host,FILTER_VALIDATE_IP))sf_fail(422,'Invalid school website host');
    $allowed=[];
    foreach($s['group_sites']??[] as $group)foreach($group['routes']??[] as $route)
        if((int)($route['school_id']??0)===$schoolId)
            $allowed[]=strtolower((string)($group['domain']??''));
    foreach($s['sites']??[] as $site)
        if((int)($site['school_id']??0)===$schoolId && !empty($site['enabled']))
            $allowed[]=strtolower((string)($site['domain']??''));
    foreach(array_unique($allowed) as $domain) {
        $domain=preg_replace('~^https?://~','',$domain);
        $domain=explode('/',$domain)[0];
        if($domain!=='' && ($host===$domain || $host==='www.'.$domain ||
          (strlen($domain)>5 && str_ends_with($host,'.'.$domain))))return $host;
    }
    sf_fail(422,'The URL must belong to a registered school website domain');
}
function sf_counsellor_public_ipv4(string $ip):bool {
    return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)!==false &&
        !preg_match('/^(?:0\.|127\.|169\.254\.|100\.(?:6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.)/',$ip);
}
/** Resolve once, pin connection to vetted public IP and disable redirects. */
function sf_counsellor_fetch_source(string $url,string $host):array {
    if(!function_exists('curl_init'))sf_fail(503,'Website scanner requires PHP cURL');
    $addresses=@gethostbynamel($host)?:[];
    $public=array_values(array_filter($addresses,'sf_counsellor_public_ipv4'));
    if(!$public)sf_fail(422,'School website could not be resolved to a safe public address');
    $buffer='';$limit=125000;
    $ch=curl_init($url);
    if($ch===false)sf_fail(503,'Could not initialize website scan');
    curl_setopt_array($ch,[
       CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,
       CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,
       CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
       CURLOPT_HTTPHEADER=>['Accept: text/html, text/plain;q=0.8','User-Agent: SchoolFlow-SchoolContentReview/1.0'],
       CURLOPT_RESOLVE=>[$host.':443:'.$public[0]],
       CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$buffer,$limit):int{
           if(strlen($buffer)+strlen($chunk)>$limit)return 0;
           $buffer.=$chunk;return strlen($chunk);
       }
    ]);
    $ok=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if($ok!==true||$status!==200)sf_fail(422,'The school URL could not be scanned (check availability and access)');
    if(!preg_match('~^(text/html|text/plain)(?:;|$)~i',$type))sf_fail(422,'Scanner currently supports HTML or plain-text pages only');
    if($buffer===''||strlen($buffer)>$limit)sf_fail(422,'School website has no readable text');
    $clean=preg_replace('~<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<noscript\b[^>]*>.*?</noscript>~is',' ',$buffer)??$buffer;
    $clean=preg_replace('~</(?:p|div|section|li|h[1-6]|tr)>~i',"\n",$clean)??$clean;
    $clean=html_entity_decode(strip_tags($clean),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $clean=preg_replace('/[ \t]+/',' ',$clean)??$clean;
    $clean=preg_replace('/\n\s*\n+/','\n',$clean)??$clean;
    $lines=array_values(array_filter(array_map('trim',explode("\n",$clean)),
        static fn($line)=>strlen($line)>=28 && strlen($line)<=1600));
    $categories=[
      'fees'=>'/fee|tuition|charge|annual payment|school cost|फीस|शुल्क/iu',
      'documents'=>'/document|certificate|birth.?proof|photograph|कागदपत्र/iu',
      'age_eligibility'=>'/eligib|age limit|minimum age|cut.?off/iu',
      'transport'=>'/bus|school transport|pickup|van service|route/iu',
      'facilities'=>'/library|laborator|playground|sports|facilit|canteen/iu',
      'curriculum'=>'/curriculum|cbse|icse|international baccalaureate|\bIB\b/iu',
      'admissions'=>'/admission|apply|registration|enrolment/iu',
      'timings'=>'/school hours|school timing|office hours/iu'
    ];
    $candidates=[];
    foreach($lines as $line){
        foreach($categories as $cat=>$regex)if(preg_match($regex,$line)){
            $snippet=substr($line,0,900);
            $key=$cat.'|'.hash('sha256',$snippet);
            $candidates[$key]=['category'=>$cat,'label'=>ucfirst(str_replace('_',' ',$cat)).
               ' — website source (verify grade/year)','details'=>$snippet,'evidence'=>$snippet];
            break;
        }
        if(count($candidates)>=12)break;
    }
    return ['fingerprint'=>hash('sha256',$buffer),
      'candidates'=>array_values($candidates),'page_excerpt'=>substr(trim($clean),0,120)];
}
function sf_counsellor_sources(string $method,string $ref,array $p,array $headers,SchoolFlowStore $store):array {
    if(!in_array($method,['GET','POST'],true))sf_fail(405,'Method not allowed');
    $identity=static function(array $s)use($ref,$headers):int {
        $user=sf_session($s,(string)($headers['authorization']??''));
        if(!empty($user['must_change_password']))sf_fail(403,'Change password first');
        $sid=sf_record_id($s,'schools',$ref);
        sf_membership($s,$sid,(int)$user['id'],['Admin']);
        return $sid;
    };
    if($method==='GET')return $store->transaction(static function(array $s)use($identity):array{
        $sid=$identity($s);
        return [200,['sources'=>array_values(array_filter($s['public_chat_sources']??[],
            static fn($row)=>(int)($row['school_id']??0)===$sid)),
          'candidates'=>array_values(array_filter($s['public_chat_source_candidates']??[],
            static fn($row)=>(int)($row['school_id']??0)===$sid&&($row['status']??'')==='pending'))]];
    });
    $action=sf_text($p,'action',24,3);
    if($action==='scan'){
        $id=sf_text($p,'source_id',36,36);
        $item=$store->transaction(static function(array $s)use($identity,$id):array {
            $sid=$identity($s);
            foreach($s['public_chat_sources']??[] as $row)
                if(($row['id']??'')===$id&&(int)($row['school_id']??0)===$sid){
                    $host=sf_counsellor_source_host($s,$sid,$row['url']);
                    return ['school_id'=>$sid,'source'=>$row,'host'=>$host];
                }
            sf_fail(404,'School source not found');
        });
        // Network access only outside a JSON transaction.
        $scan=sf_counsellor_fetch_source($item['source']['url'],$item['host']);
        return $store->transaction(static function(array &$s)use($identity,$id,$scan,$item):array{
            $sid=$identity($s);
            if($sid!==$item['school_id'])sf_fail(403,'School mismatch');
            foreach($s['public_chat_sources'] as &$row)
                if($row['id']===$id&&$row['school_id']===$sid){
                    $changed=!empty($row['fingerprint'])&&$row['fingerprint']!==$scan['fingerprint'];
                    $row['fingerprint']=$scan['fingerprint'];
                    $row['last_checked_at']=sf_time();
                    $row['status']=$changed?'content_changed':'pending_review';
                    $row['page_excerpt']=$scan['page_excerpt'];break;
                }
            unset($row);
            foreach($s['public_chat_source_candidates']??[] as &$candidate)
                if($candidate['source_id']===$id&&$candidate['status']==='pending')$candidate['status']='superseded';
            unset($candidate);
            foreach($scan['candidates'] as $candidate){
                $s['public_chat_source_candidates'][]=[
                   'id'=>sf_uuid(),'school_id'=>$sid,'source_id'=>$id,
                   'category'=>$candidate['category'],'label'=>$candidate['label'],
                   'details'=>$candidate['details'],'evidence'=>$candidate['evidence'],
                   'status'=>'pending','source_hash'=>$scan['fingerprint'],'created_at'=>sf_time()];
            }
            if($changed){
                $config=sf_chat_settings($s,$sid);
                foreach($config['school_facts']??[] as &$fact)
                    if(($fact['source_id']??'')===$id)$fact['needs_recheck']=true;
                unset($fact);
                $s['public_chat_settings'][(string)$sid]=$config;
            }
            return [200,['ok'=>true,'changed'=>$changed,'candidates'=>count($scan['candidates'])]];
        },true);
    }
    return $store->transaction(static function(array &$s)use($identity,$action,$p):array{
        $sid=$identity($s);
        $s['public_chat_sources']=$s['public_chat_sources']??[];
        $s['public_chat_source_candidates']=$s['public_chat_source_candidates']??[];
        if($action==='add'){
            $url=sf_text($p,'url',600,12);
            sf_counsellor_source_host($s,$sid,$url);
            $n=0;foreach($s['public_chat_sources'] as $v)
                if((int)$v['school_id']===$sid){
                    $n++;if($v['url']===$url)sf_fail(409,'This source is already added');
                }
            if($n>=20)sf_fail(409,'Maximum 20 website sources per school');
            $s['public_chat_sources'][]=[
              'id'=>sf_uuid(),'school_id'=>$sid,'url'=>$url,
              'status'=>'not_scanned','last_checked_at'=>null,'fingerprint'=>'',
              'created_at'=>sf_time()];
            return [201,['ok'=>true]];
        }
        if($action==='approve'||$action==='reject'){
            $id=sf_text($p,'candidate_id',36,36);
            foreach($s['public_chat_source_candidates'] as &$candidate)
                if($candidate['id']===$id&&(int)$candidate['school_id']===$sid &&
                   $candidate['status']==='pending'){
                    if($action==='approve'){
                        $facts=sf_chat_settings($s,$sid);
                        if(count($facts['school_facts']??[])>=36)sf_fail(409,'Verified school information is full');
                        $label=sf_text($p,'label',120,3);
                        $detail=sf_text($p,'details',900,3);
                        $facts['school_facts'][]=[
                         'category'=>$candidate['category'],'label'=>$label,'details'=>$detail,
                         'source_id'=>$candidate['source_id'],'source_hash'=>$candidate['source_hash'],
                         'needs_recheck'=>false];
                        $s['public_chat_settings'][(string)$sid]=$facts;
                    }
                    $candidate['status']=$action==='approve'?'approved':'rejected';
                    $candidate['reviewed_at']=sf_time();
                    return [200,['ok'=>true]];
                }
            sf_fail(404,'Pending source fact not found');
        }
        if($action==='remove'){
            $id=sf_text($p,'source_id',36,36);
            $found=false;
            foreach($s['public_chat_sources'] as $i=>$source)
                if($source['id']===$id&&(int)$source['school_id']===$sid){
                    unset($s['public_chat_sources'][$i]);$found=true;break;
                }
            if(!$found)sf_fail(404,'Source not found');
            $s['public_chat_sources']=array_values($s['public_chat_sources']);
            $conf=sf_chat_settings($s,$sid);
            foreach($conf['school_facts']??[] as &$fact)
                if(($fact['source_id']??'')===$id)$fact['needs_recheck']=true;
            unset($fact);
            $s['public_chat_settings'][(string)$sid]=$conf;
            return [200,['ok'=>true]];
        }
        sf_fail(422,'Unknown source action');
    },true);
}
