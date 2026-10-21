/**
 * Read-only public reachability probe for the Lexicon demo.
 * No credentials, no API mutations, and no school/guardian data printed.
 */
const target = 'https://lexiconedu.in/demo/';
const referenceBase = 'https://stage.digiti.in/scrm';
const timeout = 12000;
async function read(url,extra={}) {
  try {
    const r = await fetch(url,{
      method:'GET',redirect:'follow',signal:AbortSignal.timeout(timeout),
      headers:{'User-Agent':'Mozilla/5.0 SchoolFlow-Public-Widget-Diagnostic/1.0',...extra}
    });
    return {status:r.status,url:r.url,headers:r.headers,text:await r.text()};
  } catch(e) {
    return {status:'unreachable',error:e.message||String(e),text:'',url};
  }
}
const log=(name,value)=>console.log(name+': '+String(value));
const page=await read(target);
log('PAGE_HTTP',page.status);
if(page.error)log('PAGE_ERROR',page.error);
log('PAGE_LENGTH',page.text.length);
let scripts=[...page.text.matchAll(/<script\b[^>]*>/gi)].map(m=>m[0]);
let forms=scripts.filter(s=>/lexicon-form\.js/i.test(s));
log('LEXICON_FORM_SCRIPT_COUNT',forms.length);
log('OTHER_CHAT_SCRIPT_COUNT',scripts.filter(s=>/admissions-chat\.js/i.test(s)).length);
const getAttr=(html,name)=>{const rx=new RegExp('\\b'+name+'\\s*=\\s*(?:"([^"]*)"|\\x27([^\\x27]*)\\x27|([^\\s>]+))','i'); const x=html.match(rx);return x?(x[1]||x[2]||x[3]):''};
let siteOrigin = 'https://lexiconedu.in';
let scriptURL='';
let guid='';
for(const tag of forms) {
  const src=getAttr(tag,'src'), group=getAttr(tag,'data-schoolflow-group');
  log('INSTALLED_FORM_SRC',src?src.replace(/\?.*$/,''):'missing');
  log('GROUP_ID_PRESENT',Boolean(group));
  log('AUTOLOAD_OPT_OUT',getAttr(tag,'data-admissions-chat')==='off');
  if(!scriptURL && src)scriptURL=new URL(src,page.url).href;
  if(!guid && group)guid=group.trim();
}
if(!scriptURL){scriptURL=referenceBase+'/static/lexicon-form.js?v=1';log('USING_REFERENCE_CRM_ASSET',true);}
const formAsset=await read(scriptURL);
log('FORM_ASSET_HTTP',formAsset.status);
if(formAsset.error)log('FORM_ASSET_ERROR',formAsset.error);
log('FORM_ASSET_HAS_AUTOLOADER',formAsset.text.includes('loadAdmissionsChat()'));
log('FORM_ASSET_HAS_CHAT_CONFIG_CHECK',formAsset.text.includes("'/chat-config'"));
log('FORM_ASSET_SRC_PATH',new URL(scriptURL).pathname);
log('FORM_CACHE_CONTROL',formAsset.headers?.get('cache-control')||'none');
log('FORM_ETAG',formAsset.headers?.get('etag')||'none');
log('FORM_AGE',formAsset.headers?.get('age')||'none');
const fresh = await read(scriptURL.split('?')[0]+'?v=chat-probe-20261021');
log('FORM_FRESH_HTTP',fresh.status);
log('FORM_FRESH_AUTOLOADER',fresh.text.includes('loadAdmissionsChat()'));
log('FORM_FRESH_CACHE_CONTROL',fresh.headers?.get('cache-control')||'none');
log('FORM_FRESH_ETAG',fresh.headers?.get('etag')||'none');
log('FORM_BODY_MATCHES_BUSTED',formAsset.text===fresh.text);
const v2=await read(scriptURL.split('?')[0]+'?v=2');
log('FORM_VERSION_2_HTTP',v2.status);
log('FORM_VERSION_2_AUTOLOADER',v2.text.includes('loadAdmissionsChat()'));
log('FORM_VERSION_2_ETAG',v2.headers?.get('etag')||'none');
const base=scriptURL.replace(/\/static\/lexicon-form\.js(?:\?.*)?$/,'');
const chatAsset=await read(base+'/static/admissions-chat.js');
log('CHAT_ASSET_HTTP',chatAsset.status);
log('CHAT_ASSET_VALID',chatAsset.text.includes('sfac-launch'));
if(guid && /^[0-9a-f-]{36}$/i.test(guid)) {
  const legacyConfig=await read(base+'/api/public/forms/'+guid+'/config',{'Origin':siteOrigin,'Accept':'application/json'});
  log('LEGACY_FORM_CONFIG_HTTP',legacyConfig.status);
  log('LEGACY_FORM_CONFIG_CORS',legacyConfig.headers?.get('access-control-allow-origin')||'missing');
  const config=await read(base+'/api/public/forms/'+guid+'/chat-config',{'Origin':siteOrigin,'Accept':'application/json','Referer':target});
  log('PUBLIC_CONFIG_HTTP',config.status);
  log('PUBLIC_CONFIG_CONTENT_TYPE',config.headers?.get('content-type')||'none');
  log('PUBLIC_CONFIG_SERVER',config.headers?.get('server')||'none');
  log('PUBLIC_CONFIG_HTML_HEAD',config.text.slice(0,80).replace(/[\r\n]/g,' '));
  log('PUBLIC_CONFIG_CORS',config.headers?.get('access-control-allow-origin')||'missing');
  try {
    const data=JSON.parse(config.text);
    log('PUBLIC_ENABLED_CAMPUS_COUNT',Array.isArray(data.schools)?data.schools.length:'not_available');
    if(!Array.isArray(data.schools))log('PUBLIC_CONFIG_ERROR',(data.detail||'response_not_json').slice(0,130));
  }catch{log('PUBLIC_CONFIG_BODY','not_json');}
} else {
  log('PUBLIC_CONFIG_TEST','cannot_probe_without_embedded_guid');
}
log('PROBE_COMPLETE','read_only');
