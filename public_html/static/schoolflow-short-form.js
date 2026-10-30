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
 if(host.dataset.schoolflowMounted)return;host.dataset.schoolflowMounted='1';
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
 function error(s){status.textContent=s;status.classList.add('sf2-error');}
 function clear(){status.textContent='';status.classList.remove('sf2-error');}
 async function get(path){var r=await fetch(api+path,{credentials:'omit',cache:'no-store',headers:{Accept:'application/json'}});var d=await r.json().catch(function(){return {};});if(!r.ok)throw Error(d.detail||'SchoolFlow temporarily unavailable');return d;}
 async function campuses(){
  campus.disabled=true;campus.replaceChildren(new Option('Loading campuses…',''));
  try{var d=await get('/config');campus.replaceChildren(new Option('Select campus',''));
   (d.schools||[]).forEach(function(x){if(x&&x.code&&x.name)campus.add(new Option(x.name,x.code));});
   campus.disabled=campus.options.length<2;
   if(campus.disabled)error('No approved campuses available for this website.');
  }catch(e){campus.replaceChildren(new Option('Campuses unavailable',''));error('Unable to load campuses. Verify that this website domain is authorized in SchoolFlow.');}
 }
 async function challenge(){token='';verify.hidden=false;try{var d=await get('/challenge');token=d.token;outer.querySelector('.sf2-question').textContent=d.question;form.elements.captcha_answer.value='';}
 catch(e){error('Unable to load verification. Check SchoolFlow configuration.');}}
 function open(){outer.classList.add('sf2-open');opened=true;clear();campuses();form.elements.parent_name.focus();}
 if(mode==='popup'){
  var trigger=document.createElement('button');trigger.className='sf2-trigger';trigger.textContent=loader.dataset.schoolflowButton||'Enquire Now';
  document.body.appendChild(trigger);trigger.addEventListener('click',open);
  outer.querySelector('.sf2-close').addEventListener('click',function(){outer.classList.remove('sf2-open');opened=false;});
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
   school_code:campus.value,form_display:mode,consent:true,website:p.website.value,captcha_token:token,
   captcha_answer:p.captcha_answer.value.trim(),landing_page:location.href.slice(0,500),
   referrer:document.referrer.slice(0,500),utm_source:query.get('utm_source')||'',
   utm_medium:query.get('utm_medium')||'',utm_campaign:query.get('utm_campaign')||'',
   utm_term:query.get('utm_term')||'',utm_content:query.get('utm_content')||''};
  busy=true;submit.disabled=true;submit.textContent='Submitting…';
  try{var res=await fetch(api+'/short-enquiries',{method:'POST',credentials:'omit',cache:'no-store',headers:{'Content-Type':'application/json','Idempotency-Key':attemptId},body:JSON.stringify(data)});
   var body=await res.json().catch(function(){return {};});if(!res.ok)throw Error(body.detail||'Submission failed');
   status.textContent='Thank you. Your admission enquiry has been received.';form.reset();verify.hidden=true;token='';attemptId='';
  }catch(e){error(e.message);await challenge();}
  finally{busy=false;submit.disabled=false;submit.textContent='Request Admission Callback';}
 });
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();