/* SchoolFlow Forms v2: native short inline/popup embed. No private keys in browser. */
(function(){
'use strict';
var loader=document.currentScript;if(!loader)return;
var guid=(loader.dataset.schoolflowGroup||'').trim().toLowerCase();
if(!/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(guid))return;
var mode=loader.dataset.schoolflowMode==='inline'?'inline':'popup';
var target=loader.dataset.schoolflowTarget||'#schoolflow-admissions';
var base=new URL(loader.src).href.replace(/\/static\/schoolflow-short-form\.js(?:\?.*)?$/,'');
var api=base+'/api/public/forms/'+encodeURIComponent(guid);
var css=document.createElement('link');css.rel='stylesheet';css.href=base+'/static/schoolflow-short-form.css?v='+encodeURIComponent(loader.dataset.schoolflowShortCssVersion||'1');document.head.appendChild(css);
function init(){
 var host=mode==='inline'?document.querySelector(target):document.createElement('div');
 if(!host){console.warn('SchoolFlow inline target not found:',target);return;}
 if(host.dataset.schoolflowMounted)return;
 if(mode==='popup'&&document.querySelector('[data-schoolflow-popup-guid="'+guid+'"]'))return;
 host.dataset.schoolflowMounted='1';
 if(mode==='popup')host.setAttribute('data-schoolflow-popup-guid',guid);
 if(mode==='popup')document.body.appendChild(host);
 var outer=document.createElement('div');outer.className='sf2 '+(mode==='popup'?'sf2-popup':'sf2-inline');
 outer.innerHTML='<div class="sf2-panel" role="group" aria-label="Admission enquiry">'+
  (mode==='popup'?'<button type="button" class="sf2-close" aria-label="Close">×</button>':'')+
  '<h2>Admission Enquiry</h2><p>Get a call back from our admissions team.</p>'+
  '<form novalidate><label>Parent / Guardian name<input name="parent_name" maxlength="140" autocomplete="name" required placeholder="Your full name"></label>'+
  '<label>Mobile number<input name="phone" inputmode="numeric" autocomplete="tel" maxlength="10" required placeholder="10-digit mobile"></label>'+
  '<div class="sf2-row"><label>Class<select name="grade" required><option value="">Select class</option>'+
  ['Nursery','LKG','UKG','I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'].map(function(x){return '<option>'+x+'</option>';}).join('')+
  '</select></label><label>Campus<select name="school_code" required disabled><option value="">Loading campuses…</option></select></label></div>'+
  '<div class="sf2-verify" hidden><label class="sf2-question">Security question</label><input name="captcha_answer" inputmode="numeric" maxlength="3" placeholder="Answer" required><button type="button" class="sf2-refresh">Refresh question</button></div>'+
  '<label class="sf2-consent"><input type="checkbox" name="consent" required> I agree to be contacted by the selected school about this admission enquiry.</label>'+
  '<input class="sf2-trap" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">'+
  '<p class="sf2-status" aria-live="polite"></p><button type="submit" class="sf2-submit">Request Admission Callback</button></form></div>';
 host.appendChild(outer);
 var form=outer.querySelector('form'),campus=form.elements.school_code,verify=outer.querySelector('.sf2-verify');
 var status=outer.querySelector('.sf2-status'),submit=outer.querySelector('.sf2-submit'),token='',attemptId='',busy=false,opened=mode!=='popup';
 var configLoaded=false,config=null,previousFocus=null,alreadyShown=false,completed=false,closedByUser=false;
 var openedAt=Date.now(),timer=null,scrollListener=null,exitListener=null,popupTrigger='manual';
 var campaignKey='sf2_popup_'+guid;
 function readStored(){try{return JSON.parse(localStorage.getItem(campaignKey)||'{}')||{};}catch(e){return {};}}
 function writeStored(x){try{localStorage.setItem(campaignKey,JSON.stringify(x));}catch(e){}}
 function remember(reason){var x=readStored();x[reason]=Date.now();writeStored(x);}
 function eligibleRepeat(){
  if(!config)return false;
  var x=readStored(),now=Date.now(),days={session:0,'1':1,'7':7,'30':30,never:36500};
  if(x.completed&&now-x.completed<30*86400000)return false;
  if(config.repeat_after==='session'){
   try{if(sessionStorage.getItem(campaignKey+'_shown'))return false;}catch(e){if(alreadyShown)return false;}
   return true;
  }
  var period=days[config.repeat_after]??7;
  return (!x.shown||now-x.shown>=period*86400000)&&(!x.dismissed||now-x.dismissed>=period*86400000);
 }
 function matchesPath(path,prefix){return prefix==='/'||path===prefix||path.startsWith(prefix+'/');}
 function autoAllowed(){
  if(!config||!config.enabled||completed||closedByUser||alreadyShown||opened||document.visibilityState==='hidden')return false;
  if(!config.mobile_enabled&&matchMedia('(max-width: 767px)').matches)return false;
  var path=location.pathname,includes=config.include_paths||[],excludes=config.exclude_paths||[];
  if(path==='/scrm'||path.startsWith('/scrm/'))return false;
  if(includes.length&&!includes.some(function(x){return matchesPath(path,x);}))return false;
  if(excludes.some(function(x){return matchesPath(path,x);}))return false;
  var today=new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Kolkata'});
  if(config.start_date&&today<config.start_date)return false;
  if(config.end_date&&today>config.end_date)return false;
  return eligibleRepeat();
 }
 function clearTriggers(){
  if(timer!==null){clearTimeout(timer);timer=null;}
  if(scrollListener){removeEventListener('scroll',scrollListener);scrollListener=null;}
  if(exitListener){document.removeEventListener('mouseout',exitListener);exitListener=null;}
 }
 function openAuto(reason){
  if(!autoAllowed())return;
  alreadyShown=true;popupTrigger=reason||'delay';remember('shown');
  try{sessionStorage.setItem(campaignKey+'_shown','1');}catch(e){}
  clearTriggers();open();
 }
 function setupTriggers(){
  clearTriggers();
  if(!config||!config.enabled||!autoAllowed())return;
  if(config.trigger==='manual')return;
  var t=config.trigger;
  if(t==='delay'||t==='either')timer=setTimeout(function(){openAuto('delay');},Math.max(0,config.delay_seconds)*1000);
  if(t==='scroll'||t==='either'){
   scrollListener=function(){var max=document.documentElement.scrollHeight-innerHeight;
    if(max>0&&scrollY/max*100>=config.scroll_percent)openAuto('scroll');};
   addEventListener('scroll',scrollListener,{passive:true});scrollListener();
  }
  if(config.exit_intent||t==='exit'){
   exitListener=function(e){
    if(e.relatedTarget||e.clientY>4||Date.now()-openedAt<8000||matchMedia('(pointer:coarse)').matches)return;
    openAuto('exit');
   };
   document.addEventListener('mouseout',exitListener);
  }
 }
 function error(s){status.textContent=s;status.classList.add('sf2-error');}
 function clear(){status.textContent='';status.classList.remove('sf2-error');}
 async function get(path){var r=await fetch(api+path,{credentials:'omit',cache:'no-store',headers:{Accept:'application/json'}});var d=await r.json().catch(function(){return {};});if(!r.ok)throw Error(d.detail||'SchoolFlow temporarily unavailable');return d;}
 async function campuses(){
  campus.disabled=true;campus.replaceChildren(new Option('Loading campuses…',''));
  try{var d=await get('/config');campus.replaceChildren(new Option('Select campus',''));
   var permitted=d.popup&&Array.isArray(d.popup.school_codes)?d.popup.school_codes:null;
   (d.schools||[]).forEach(function(x){if(x&&x.code&&x.name&&(!config||!config.enabled||!permitted||permitted.includes(x.code)))campus.add(new Option(x.name,x.code));});
   campus.disabled=campus.options.length<2;
   if(campus.disabled)error('No approved campuses available for this website.');
  }catch(e){campus.replaceChildren(new Option('Campuses unavailable',''));error('Unable to load campuses. Verify that this website domain is authorized in SchoolFlow.');}
 }
 async function challenge(){token='';verify.hidden=false;try{var d=await get('/challenge');token=d.token;outer.querySelector('.sf2-question').textContent=d.question;form.elements.captcha_answer.value='';}
 catch(e){error('Unable to load verification. Check SchoolFlow configuration.');}}
 function open(){
  if(opened)return;
  previousFocus=document.activeElement;outer.classList.add('sf2-open');opened=true;clear();campuses();
  if(mode==='popup'){outer.setAttribute('role','dialog');outer.setAttribute('aria-modal','true');}
  form.elements.parent_name.focus();
 }
 function close(){
  outer.classList.remove('sf2-open');opened=false;closedByUser=true;remember('dismissed');
  clearTriggers();
  if(previousFocus&&typeof previousFocus.focus==='function')previousFocus.focus();
 }
 if(mode==='popup'){
  var trigger=document.createElement('button');trigger.className='sf2-trigger';trigger.textContent=loader.dataset.schoolflowButton||'Enquire Now';
  document.body.appendChild(trigger);trigger.addEventListener('click',function(){popupTrigger='manual';open();});
  outer.querySelector('.sf2-close').addEventListener('click',close);
  outer.addEventListener('click',function(e){if(e.target===outer)close();});
  outer.addEventListener('keydown',function(e){
   if(e.key==='Escape'){e.preventDefault();close();return;}
   if(e.key==='Tab'){
    var focusables=Array.from(outer.querySelectorAll('button:not([disabled]),input:not([disabled]):not([tabindex="-1"]),select:not([disabled])')).filter(function(x){return x.offsetParent!==null;});
    if(!focusables.length)return;
    var first=focusables[0],last=focusables[focusables.length-1];
    if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
    else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
   }
  });
  get('/config').then(function(d){
   config=d.popup||null;configLoaded=true;
   if(config&&['right','bottom'].includes(config.position))outer.classList.add('sf2-position-'+config.position);
   if(config&&config.school_codes&&config.school_codes.length===0)return;
   setupTriggers();
  }).catch(function(){configLoaded=true;});
  addEventListener('pageshow',function(e){if(e.persisted)setupTriggers();});
  addEventListener('popstate',setupTriggers);
 }else campuses();
 outer.querySelector('.sf2-refresh').addEventListener('click',challenge);
 form.addEventListener('submit',async function(ev){
  ev.preventDefault();if(busy||!opened)return;clear();
  var p=form.elements;var phone=p.phone.value.trim();
  if(p.parent_name.value.trim().length<2)return error('Enter parent or guardian name.');
  if(!/^[6-9][0-9]{9}$/.test(phone))return error('Enter a valid 10-digit mobile number.');
  if(!p.grade.value||!campus.value||campus.disabled)return error('Select class and an available campus.');
  if(!p.consent.checked)return error('Consent is required.');
  if(!token){await challenge();if(token)error('Please answer the security question to complete your enquiry.');return;}
  if(!/^\d+$/.test(p.captcha_answer.value.trim()))return error('Enter the security answer.');
  if(!attemptId){if(!crypto||!crypto.randomUUID)return error('A secure browser is required.');attemptId=crypto.randomUUID();}
  var query=new URLSearchParams(location.search),data={
   student_name:'',parent_name:p.parent_name.value.trim(),phone:phone,grade:p.grade.value,
   school_code:campus.value,form_display:mode,popup_trigger:mode==='popup'?popupTrigger:'',consent:true,website:p.website.value,captcha_token:token,
   captcha_answer:p.captcha_answer.value.trim(),landing_page:location.href.slice(0,500),
   referrer:document.referrer.slice(0,500),utm_source:query.get('utm_source')||'',
   utm_medium:query.get('utm_medium')||'',utm_campaign:query.get('utm_campaign')||'',
   utm_term:query.get('utm_term')||'',utm_content:query.get('utm_content')||''};
  busy=true;submit.disabled=true;submit.textContent='Submitting…';
  try{var res=await fetch(api+'/short-enquiries',{method:'POST',credentials:'omit',cache:'no-store',headers:{'Content-Type':'application/json','Idempotency-Key':attemptId},body:JSON.stringify(data)});
   var body=await res.json().catch(function(){return {};});if(!res.ok)throw Error(body.detail||'Submission failed');
   status.textContent='Thank you. Your admission enquiry has been received.';form.reset();verify.hidden=true;token='';attemptId='';
   completed=true;remember('completed');clearTriggers();
  }catch(e){error(e.message);await challenge();}
  finally{busy=false;submit.disabled=false;submit.textContent='Request Admission Callback';}
 });
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();