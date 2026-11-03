/* Install on the approved thank-you page to emit a single, non-PII conversion event. */
(function(){
 'use strict';
 var loader=document.currentScript;if(!loader)return;
 var guid=String(loader.dataset.schoolflowGroup||'').trim().toLowerCase();
 if(!/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(guid))return;
 // Install in the site-wide head: capture first-touch UTMs even on pages with no form.
 var firstKey='schoolflow_first_touch_'+guid;
 try{
  var old=JSON.parse(sessionStorage.getItem(firstKey)||'null');
  if(!old||!old.value||!Number.isFinite(old.time)||Date.now()-old.time>=86400000){
   var q=new URLSearchParams(location.search);
   var cleanPage=location.origin+location.pathname;
   var referrer='';
   try{var ref=new URL(document.referrer);referrer=(ref.origin+ref.pathname).slice(0,500);}catch(e){}
   sessionStorage.setItem(firstKey,JSON.stringify({time:Date.now(),value:{
    landing_page:cleanPage.slice(0,500),referrer:referrer,
    utm_source:(q.get('utm_source')||'').slice(0,140),
    utm_medium:(q.get('utm_medium')||'').slice(0,140),
    utm_campaign:(q.get('utm_campaign')||'').slice(0,140),
    utm_term:(q.get('utm_term')||'').slice(0,140),
    utm_content:(q.get('utm_content')||'').slice(0,140)
   }}));
  }
 }catch(e){}
 var key='schoolflow_conversion_'+guid,stored;
 try{stored=JSON.parse(sessionStorage.getItem(key)||'null');}catch(e){return;}
 if(!stored||typeof stored.event_id!=='string'||!/^[-a-zA-Z0-9]{12,100}$/.test(stored.event_id)||
    !Number.isFinite(stored.time)||Date.now()-stored.time>1800000||stored.time>Date.now()+60000){
  try{sessionStorage.removeItem(key);}catch(e){}return;
 }
 var base=new URL(loader.src).href.replace(/\/static\/schoolflow-conversion\.js(?:\?.*)?$/,'');
 var current=new URL(location.href);
 if(current.protocol!=='https:')return;
 fetch(base+'/api/public/forms/'+encodeURIComponent(guid)+'/config',{
  credentials:'omit',cache:'no-store',headers:{Accept:'application/json'}
 }).then(function(r){return r.ok?r.json():null;}).then(function(result){
  if(!result||!result.submission_settings)return;
  var expected=result.submission_settings.thank_you_url;
  if(!expected)return;
  var url=new URL(expected);
  if(url.origin!==current.origin||url.pathname.replace(/\/+$/,'')!==current.pathname.replace(/\/+$/,''))return;
  // Remove before notifying analytics consumers: reload and double embeds cannot duplicate an event.
  try{if(!sessionStorage.getItem(key))return;sessionStorage.removeItem(key);}catch(e){return;}
  var detail={event_id:stored.event_id,form_surface:stored.form_surface||'unknown',
    school_code:stored.school_code||'',group_guid:guid};
  window.dataLayer=window.dataLayer||[];
  window.dataLayer.push({
   event:'schoolflow_lead',schoolflow_event_id:detail.event_id,
   schoolflow_form_surface:detail.form_surface,schoolflow_school_code:detail.school_code,
   schoolflow_group_guid:guid
  });
  try{window.dispatchEvent(new CustomEvent('schoolflow:lead-success',{detail:detail}));}catch(e){}
 }).catch(function(){/* Fail closed; never fabricate a conversion. */});
})();