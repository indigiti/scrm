/* SchoolFlow guided admissions: consented CRM lead first, Q&A second. */
(function(){
'use strict';
const loader=document.currentScript;
const guid=(loader?.dataset.schoolflowGroup||'').trim().toLowerCase();
if(!/^[a-f0-9]{8}-[a-f0-9-]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(guid))return;
if(window.__schoolflowAdmissionsChatMounted)return;
window.__schoolflowAdmissionsChatMounted=guid;
const base=new URL(loader.src).href.replace(/\/static\/admissions-chat\.js(?:\?.*)?$/,'');
const api=base+'/api/public/forms/'+encodeURIComponent(guid);
const css=document.createElement('link');
css.rel='stylesheet';
css.href=base+'/static/admissions-chat.css?v='+encodeURIComponent(loader.getAttribute('data-schoolflow-chat-css-version')||'1');
document.head.append(css);
const root=document.createElement('section');
root.className='sfac';
root.innerHTML=
'<button type="button" class="sfac-launch" aria-expanded="false" aria-label="Open admissions assistant">✦ Ask Admissions</button>'+
'<div class="sfac-panel" hidden role="dialog" aria-label="Admissions assistant">'+
'<header><strong>Admissions Assistant</strong><button type="button" class="sfac-close" aria-label="Close assistant">×</button></header>'+
'<p class="sfac-hint">Register your enquiry, then ask questions about admissions. Your details go to the selected school, not to the AI answer service.</p>'+
'<label>School / campus<select class="sfac-campus"><option value="">Loading campuses…</option></select></label>'+
'<form class="sfac-register">'+
'<strong>Start your admission enquiry</strong>'+
'<input name="parent_name" required minlength="2" maxlength="140" autocomplete="name" placeholder="Parent / guardian name *">'+
'<input name="phone" required pattern="[6-9][0-9]{9}" maxlength="10" inputmode="numeric" autocomplete="tel" placeholder="10-digit mobile number *">'+
'<input name="student_name" required minlength="2" maxlength="140" placeholder="Student name *">'+
'<input name="grade" required maxlength="80" placeholder="Class / grade interested in *">'+
'<input name="email" type="email" maxlength="160" autocomplete="email" placeholder="Email address (optional)">'+
'<input name="city" maxlength="100" placeholder="City (optional)">'+
'<label class="sfac-check"><input name="consent" type="checkbox" required> I consent to the selected school storing these details and contacting me about my admission enquiry. I understand my questions may be processed by an AI service if the school has enabled it.</label>'+
'<label class="sfac-verify"></label><input class="sfac-answer" type="text" inputmode="numeric" pattern="[0-9]{1,3}" required maxlength="3" placeholder="Math verification answer *">'+
'<input class="sfac-trap" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">'+
'<button type="submit" class="sfac-primary">Submit enquiry & start chat</button></form>'+
'<div class="sfac-conversation" hidden>'+
'<p class="sfac-notice">Enquiry recorded. You can now ask questions about this campus. Avoid entering private contact details in chat.</p>'+
'<div class="sfac-quick" aria-label="Suggested admissions questions"></div>'+
'<div class="sfac-history" role="log" aria-live="polite"></div>'+
'<form class="sfac-ask"><label class="sfac-sr" for="sfac-question">Your question</label><input id="sfac-question" maxlength="400" required placeholder="Ask an admission question…"><button type="submit" class="sfac-primary">Send</button></form>'+
'<button type="button" class="sfac-followup">Request admissions callback</button></div>'+
'<p class="sfac-status" role="status" aria-live="polite"></p></div>';
document.body.append(root);
const $=sel=>root.querySelector(sel),panel=$('.sfac-panel'),campus=$('.sfac-campus');
let busy=false,session='',captcha=null,attemptId='';
function feedback(msg){$('.sfac-status').textContent=msg||'';}
async function read(route){
 const response=await fetch(api+route,{method:'GET',credentials:'omit',cache:'no-store',headers:{Accept:'application/json'}});
 const json=await response.json();if(!response.ok)throw Error(json.detail||'Service unavailable');return json;
}
async function send(route,data,headers={}){
 const response=await fetch(api+route,{method:'POST',credentials:'omit',cache:'no-store',
  headers:{'Content-Type':'application/json',...headers},body:JSON.stringify(data)});
 const json=await response.json();if(!response.ok)throw Error(json.detail||'Please try again');return json;
}
function createNonce(){const bytes=crypto.getRandomValues(new Uint8Array(16));return Array.from(bytes,x=>x.toString(16).padStart(2,'0')).join('');}
function say(role,text){
 const item=document.createElement('p');item.className=role;
 item.textContent=(role==='parent'?'You: ':'Assistant: ')+text;
 $('.sfac-history').append(item);item.scrollIntoView({block:'nearest'});
}
async function loadCampuses(){
 try{
  const data=await read('/chat-config');
  const choice=campus.value;
  campus.replaceChildren(new Option('Select your campus',''));
  (data.schools||[]).forEach(item=>campus.add(new Option(item.name,item.code)));
  if([...campus.options].some(x=>x.value===choice))campus.value=choice;
  else if(campus.options.length===2)campus.selectedIndex=1;
  if(campus.options.length<=1)feedback('Admissions chat is not available for this website yet.');
 }catch(e){feedback('Cannot load school campuses. Please try again.');}
}
async function loadChallenge(){
 captcha=null;$('.sfac-verify').textContent='Loading verification…';
 try{captcha=await read('/challenge');$('.sfac-verify').textContent=captcha.question;}
 catch(e){$('.sfac-verify').textContent='Verification unavailable. Reopen the assistant to retry.';}
}
function showQuick(questions){
 const wrap=$('.sfac-quick');wrap.replaceChildren();
 (questions||[]).slice(0,8).forEach(question=>{
  if(typeof question!=='string')return;
  const button=document.createElement('button');button.type='button';button.className='sfac-chip';
  button.textContent=question;button.addEventListener('click',()=>ask(question));wrap.append(button);
 });
}
async function ask(question){
 if(busy||!session)return;
 const value=String(question).trim();if(value.length<3||value.length>400)return;
 say('parent',value);$('.sfac-ask input').value='';
 busy=true;$('.sfac-ask button').disabled=true;feedback('Finding a verified answer…');
 try{const answer=await send('/chat',{school_code:campus.value,question:value,session});say('assistant',answer.answer);}
 catch(e){say('assistant','I cannot answer at present. Please request a callback from admissions.');}
 finally{busy=false;$('.sfac-ask button').disabled=false;feedback('');}
}
$('.sfac-launch').addEventListener('click',()=>{
 panel.hidden=!panel.hidden;$('.sfac-launch').setAttribute('aria-expanded',String(!panel.hidden));
 if(!panel.hidden){if(!session){loadCampuses();loadChallenge();}else $('.sfac-ask input').focus();}
});
$('.sfac-close').addEventListener('click',()=>{
 panel.hidden=true;$('.sfac-launch').setAttribute('aria-expanded','false');$('.sfac-launch').focus();
});
$('.sfac-register').addEventListener('submit',async e=>{
 e.preventDefault();if(busy||session)return;
 if(!campus.value)return feedback('Select a school campus before continuing.');
 if(!captcha)return feedback('Please complete verification.');
 const form=e.currentTarget,fd=new FormData(form),v=k=>String(fd.get(k)||'').trim();
 if(!fd.has('consent'))return feedback('Consent is required.');
 if(!attemptId)attemptId=createNonce();
 const data={
  school_code:campus.value,parent_name:v('parent_name'),phone:v('phone'),
  student_name:v('student_name'),grade:v('grade'),email:v('email'),city:v('city'),
  consent:true,captcha_token:captcha.token,captcha_answer:$('.sfac-answer').value.trim(),
  website:v('website')
 };
 busy=true;form.querySelector('button').disabled=true;campus.disabled=true;
 feedback('Saving your enquiry securely…');
 try{
  const created=await send('/chat-start',data,{'Idempotency-Key':attemptId});
  session=created.session;form.hidden=true;$('.sfac-conversation').hidden=false;
  showQuick(created.quick_questions);
  say('assistant','Thank you. Your enquiry has been registered with the selected campus. You may now ask about the admission process, fees, documents, visits or eligibility.');
  feedback('Enquiry created successfully.');$('.sfac-ask input').focus();
 }catch(err){feedback(err.message||'Could not start chat.');loadChallenge();}
 finally{busy=false;form.querySelector('button').disabled=false;if(!session)campus.disabled=false;}
});
$('.sfac-ask').addEventListener('submit',e=>{
 e.preventDefault();ask($('.sfac-ask input').value);
});
$('.sfac-followup').addEventListener('click',async e=>{
 if(!session||busy)return;busy=true;e.currentTarget.disabled=true;
 try{await send('/chat-followup',{school_code:campus.value,session});
  feedback('Callback requested. The school admissions team will follow up.');
  e.currentTarget.textContent='Callback requested';
 }catch(err){feedback(err.message);e.currentTarget.disabled=false;}
 finally{busy=false;}
});
})();