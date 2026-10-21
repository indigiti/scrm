/* SchoolFlow public admissions assistant — load from an approved website only. */
(function(){
'use strict';
const loader=document.currentScript;
const guid=(loader?.dataset.schoolflowGroup||'').toLowerCase();
if(!/^[a-f0-9]{8}-[a-f0-9-]{27}$/.test(guid))return;
// Avoid duplicated chat panels if both old and new embed snippets are present.
if(window.__schoolflowAdmissionsChatMounted)return;
window.__schoolflowAdmissionsChatMounted=guid;
const base=new URL(loader.src).href.replace(/\/static\/admissions-chat\.js(?:\?.*)?$/,'');
const api=base+'/api/public/forms/'+encodeURIComponent(guid);
const css=document.createElement('link');css.rel='stylesheet';css.href=base+'/static/admissions-chat.css?v='+encodeURIComponent(loader.getAttribute('data-schoolflow-chat-css-version')||'1');document.head.append(css);
const root=document.createElement('section');root.className='sfac';root.innerHTML=
'<button type="button" class="sfac-launch" aria-expanded="false" aria-label="Open admissions assistant">✦ Ask Admissions</button>'+
'<div class="sfac-panel" hidden role="dialog" aria-label="Admissions assistant">'+
'<header><strong>Admissions Assistant</strong><button type="button" class="sfac-close" aria-label="Close assistant">×</button></header>'+
'<p class="sfac-hint">Ask about admissions or request a callback. Please do not enter personal details in chat.</p>'+
'<label>Choose campus<select class="sfac-campus"><option value="">Loading campuses…</option></select></label>'+
'<div class="sfac-history" role="log" aria-live="polite"></div>'+
'<form class="sfac-ask"><label class="sfac-sr" for="sfac-question">Your question</label><input id="sfac-question" maxlength="400" required placeholder="Ask about admissions…"><button type="submit">Send</button></form>'+
'<div class="sfac-actions"><button type="button" class="sfac-callback-toggle">Request callback</button><button type="button" class="sfac-enquiry">Admission enquiry</button></div>'+
'<form class="sfac-callback" hidden><strong>Request a callback</strong><input name="parent_name" required minlength="2" maxlength="140" placeholder="Parent / guardian name"><input name="phone" required pattern="[6-9][0-9]{9}" maxlength="10" inputmode="numeric" placeholder="Mobile number"><input name="student_name" maxlength="140" placeholder="Student name (optional)"><input name="grade" maxlength="80" placeholder="Class / grade (optional)"><input name="email" type="email" maxlength="160" placeholder="Email (optional)"><input name="preferred_time" maxlength="100" placeholder="Preferred callback time (optional)"><label class="sfac-check"><input name="consent" type="checkbox" required> I agree to be contacted by the selected school about my admission enquiry.</label><label class="sfac-captcha"></label><input class="sfac-answer" type="text" required inputmode="numeric" maxlength="3" placeholder="Answer"><button type="submit">Submit callback request</button></form>'+
'<form class="sfac-enquiry-form" hidden><strong>Admission enquiry</strong>'+
'<input name="student_name" required minlength="2" maxlength="140" placeholder="Student name">'+
'<input name="parent_name" required minlength="2" maxlength="140" placeholder="Parent / guardian name">'+
'<input name="phone" required pattern="[6-9][0-9]{9}" maxlength="10" inputmode="numeric" placeholder="Mobile number">'+
'<input name="email" required type="email" maxlength="160" placeholder="Email address">'+
'<input name="grade" required maxlength="80" placeholder="Class / grade">'+
'<input name="pincode" required pattern="[0-9]{6}" maxlength="6" inputmode="numeric" placeholder="PIN code">'+
'<input name="state" required maxlength="100" placeholder="State">'+
'<input name="city" required maxlength="100" placeholder="City">'+
'<label class="sfac-check"><input name="consent" type="checkbox" required> I agree to be contacted by the selected school about my admission enquiry.</label>'+
'<label class="sfac-enquiry-captcha"></label><input class="sfac-enquiry-answer" required maxlength="3" inputmode="numeric" placeholder="Verification answer">'+
'<button type="submit">Submit admission enquiry</button></form>'+
'<p class="sfac-status" role="status" aria-live="polite"></p></div>';
document.body.append(root);
const $=s=>root.querySelector(s),panel=$('.sfac-panel'),campus=$('.sfac-campus'),status=$('.sfac-status');
let captcha=null,busy=false;
function feedback(v){status.textContent=v||'';}
function message(who,text){const p=document.createElement('p');p.className=who;p.textContent=(who==='parent'?'You: ':'Assistant: ')+text;$('.sfac-history').append(p);p.scrollIntoView({block:'nearest'});}
async function get(route){const response=await fetch(api+route,{cache:'no-store',credentials:'omit'});const data=await response.json();if(!response.ok)throw Error(data.detail||'Service unavailable');return data;}
async function post(route,data,extra={}){const r=await fetch(api+route,{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json',...extra},body:JSON.stringify(data)});const body=await r.json();if(!r.ok)throw Error(body.detail||'Please try again');return body;}
async function setup(){try{const data=await get('/chat-config');campus.replaceChildren(new Option('Choose a campus',''));(data.schools||[]).forEach(x=>campus.add(new Option(x.name,x.code)));if(campus.options.length===2)campus.selectedIndex=1;if(campus.options.length<=1){feedback('This school has not activated the admissions assistant.');$('.sfac-ask button').disabled=true;}}catch(e){feedback('Cannot load campuses at present.');}}
async function loadCaptcha(){captcha=null;try{captcha=await get('/challenge');$('.sfac-captcha').textContent=captcha.question;$('.sfac-enquiry-captcha').textContent=captcha.question;}catch(e){$('.sfac-captcha').textContent='Verification unavailable';$('.sfac-enquiry-captcha').textContent='Verification unavailable';}}
$('.sfac-launch').addEventListener('click',()=>{panel.hidden=!panel.hidden;$('.sfac-launch').setAttribute('aria-expanded',String(!panel.hidden));if(!panel.hidden){setup();$('#sfac-question').focus();}});
$('.sfac-close').addEventListener('click',()=>{panel.hidden=true;$('.sfac-launch').setAttribute('aria-expanded','false');$('.sfac-launch').focus();});
$('.sfac-ask').addEventListener('submit',async e=>{e.preventDefault();if(busy)return;if(!campus.value)return feedback('Choose a campus first.');const input=$('#sfac-question');const question=input.value.trim();if(question.length<3)return;message('parent',question);input.value='';busy=true;$('.sfac-ask button').disabled=true;feedback('Finding an answer…');try{const r=await post('/chat',{school_code:campus.value,question});message('assistant',r.answer);}catch(e){message('assistant','I cannot answer right now. Please request a callback.');}finally{busy=false;$('.sfac-ask button').disabled=false;feedback('');}});
$('.sfac-callback-toggle').addEventListener('click',()=>{const form=$('.sfac-callback');form.hidden=!form.hidden;if(!form.hidden){$('.sfac-enquiry-form').hidden=true;loadCaptcha();}});
$('.sfac-enquiry').addEventListener('click',()=>{const fn=window.openLeadPopup;if(typeof fn==='function'){fn();return;}const form=$('.sfac-enquiry-form');form.hidden=!form.hidden;if(!form.hidden){$('.sfac-callback').hidden=true;loadCaptcha();}});
$('.sfac-callback').addEventListener('submit',async e=>{e.preventDefault();if(busy)return;if(!campus.value)return feedback('Choose a campus first.');if(!captcha)return feedback('Complete verification first.');const form=e.currentTarget;const fd=new FormData(form),value=k=>String(fd.get(k)||'').trim();const data={school_code:campus.value,parent_name:value('parent_name'),phone:value('phone'),student_name:value('student_name'),grade:value('grade'),email:value('email'),preferred_time:value('preferred_time'),consent:fd.has('consent'),captcha_token:captcha.token,captcha_answer:$('.sfac-answer').value.trim(),website:''};busy=true;feedback('Submitting…');try{const bytes=crypto.getRandomValues(new Uint8Array(16));const id=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');await post('/callback',data,{'Idempotency-Key':id});form.hidden=true;form.reset();feedback('Callback request submitted. The admissions team can contact you.');}catch(err){feedback(err.message);await loadCaptcha();}finally{busy=false;}});

$('.sfac-enquiry-form').addEventListener('submit',async e=>{
 e.preventDefault();if(busy)return;
 if(!campus.value)return feedback('Choose a campus first.');
 if(!captcha)return feedback('Verification is unavailable. Please try again.');
 const form=e.currentTarget,fd=new FormData(form),v=k=>String(fd.get(k)||'').trim();
 const search=new URLSearchParams(location.search);
 const payload={student_name:v('student_name'),parent_name:v('parent_name'),phone:v('phone'),
  email:v('email'),grade:v('grade'),pincode:v('pincode'),state:v('state'),city:v('city'),
  school_code:campus.value,consent:fd.has('consent'),website:'',
  captcha_token:captcha.token,captcha_answer:$('.sfac-enquiry-answer').value.trim(),
  landing_page:location.href.slice(0,500),referrer:document.referrer.slice(0,500),
  utm_source:search.get('utm_source')||'',utm_medium:search.get('utm_medium')||'',
  utm_campaign:search.get('utm_campaign')||'',utm_term:search.get('utm_term')||'',
  utm_content:search.get('utm_content')||''};
 busy=true;feedback('Submitting admission enquiry…');
 try{
   const bytes=crypto.getRandomValues(new Uint8Array(16));
   const id=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
   await post('/enquiries',payload,{'Idempotency-Key':id});
   form.hidden=true;form.reset();feedback('Your enquiry has been submitted to the selected school.');
 }catch(err){feedback(err.message);await loadCaptcha();}
 finally{busy=false;}
});

})();