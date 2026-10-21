/* SchoolFlow Admissions Assistant: one-message-at-a-time enquiry, then Q&A. */
(function(){
'use strict';
const loader=document.currentScript;
const guid=(loader?.getAttribute('data-schoolflow-group')||'').trim().toLowerCase();
if(!/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(guid))return;
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
 '<button type="button" class="sfac-launch" aria-label="Open admissions assistant" aria-expanded="false">✦ Ask Admissions</button>'+
 '<div class="sfac-panel" hidden role="dialog" aria-label="Admissions assistant">'+
 '<header class="sfac-head"><span class="sfac-avatar" aria-hidden="true">✦</span><div class="sfac-headings"><strong>Admissions Assistant</strong><span class="sfac-step">Start an enquiry</span></div>'+
 '<button type="button" class="sfac-close" aria-label="Close admissions assistant">×</button></header>'+
 '<div class="sfac-history" role="log" aria-label="Admissions conversation" aria-live="polite" aria-relevant="additions text"></div>'+
 '<div class="sfac-quick" aria-label="Chat suggestions"></div>'+
 '<form class="sfac-entry" hidden><label class="sfac-sr" for="sfac-reply">Your reply</label>'+
 '<input id="sfac-reply" type="text" maxlength="400" autocomplete="off" placeholder="Type your answer…" required>'+
 '<button class="sfac-send" type="submit" aria-label="Send reply">➤</button></form>'+
 '<div class="sfac-footer"><span class="sfac-status" role="status" aria-live="polite"></span></div></div>';
document.body.append(root);

const $=selector=>root.querySelector(selector);
const panel=$('.sfac-panel'),history=$('.sfac-history'),choices=$('.sfac-quick');
const entry=$('.sfac-entry'),input=$('#sfac-reply'),sendButton=$('.sfac-send'),stepLabel=$('.sfac-step');
const steps=['campus','parent_name','phone','student_name','grade','email','city','consent','captcha'];
const prompts={
 parent_name:'May I have the parent or guardian’s full name?',
 phone:'What 10-digit mobile number should our admissions team use to contact you?',
 student_name:'What is the student’s name?',
 grade:'Which class or grade are you interested in?',
 email:'Would you like to share an email address? This is optional.',
 city:'Which city are you enquiring from? This is optional.',
 consent:'Before I register your enquiry: do you agree that the selected school may store these details and contact you about admissions? If the school enables an AI provider, general questions may be processed by it; your contact details are not sent to the AI.',
 captcha:'For verification, please solve the maths question below.'
};
let current='campus',started=false,busy=false,session='',captcha=null,attemptId='';
let campusCode='',schools=[],draft={},quickQuestions=[],callbackRequested=false;

function feedback(text){$('.sfac-status').textContent=text||'';}
function bubble(who,text){
 const p=document.createElement('p');p.className='sfac-bubble '+(who==='parent'?'parent':'assistant');
 p.textContent=String(text);
 history.append(p);
 history.scrollTop=history.scrollHeight;
 return p;
}
function buttons(options){
 choices.replaceChildren();
 for(const choice of options){
  const b=document.createElement('button');
  b.type='button';b.className='sfac-chip';b.textContent=choice.label;
  b.addEventListener('click',choice.action);choices.append(b);
 }
}
function setInput(visible,opts={}){
 entry.hidden=!visible;
 if(!visible){input.value='';return;}
 input.type=opts.type||'text';
 input.inputMode=opts.inputMode||'text';
 input.maxLength=opts.maxLength||400;
 input.placeholder=opts.placeholder||'Type your reply…';
 input.required=true;
 input.value='';
 input.autocomplete=opts.autocomplete||'off';
 sendButton.disabled=busy;
 if(!panel.hidden)input.focus();
}
function stage(name){
 current=name;
 stepLabel.textContent=name==='qa'?'Enquiry registered · Ask anything':
   name==='loading'?'Connecting to your campus':
   name==='registering'?'Registering your enquiry':
   'Enquiry details · Step '+(Math.max(steps.indexOf(name),0)+1)+' of '+steps.length;
}
async function get(route){
 const r=await fetch(api+route,{method:'GET',credentials:'omit',cache:'no-store',headers:{Accept:'application/json'}});
 const data=await r.json().catch(()=>({}));
 if(!r.ok)throw new Error(data.detail||'The admissions service is temporarily unavailable.');
 return data;
}
async function post(route,data,headers={}){
 const r=await fetch(api+route,{method:'POST',credentials:'omit',cache:'no-store',
  headers:{'Content-Type':'application/json',...headers},body:JSON.stringify(data)});
 const body=await r.json().catch(()=>({}));
 if(!r.ok)throw new Error(body.detail||'The admissions service is temporarily unavailable.');
 return body;
}
function nonce(){
 const values=crypto.getRandomValues(new Uint8Array(16));
 return Array.from(values,x=>x.toString(16).padStart(2,'0')).join('');
}
function question(name){
 stage(name);
 choices.replaceChildren();feedback('');
 if(name==='campus'){
  setInput(false);
  bubble('assistant','Hello! 👋 I can help with admissions. Which school campus are you interested in?');
  buttons(schools.map(s=>({label:s.name,action:()=>selectCampus(s)})));
  return;
 }
 bubble('assistant',prompts[name]);
 if(name==='consent'){
  setInput(false);
  buttons([
   {label:'I agree · Continue',action:()=>giveConsent(true)},
   {label:'Not now',action:()=>giveConsent(false)}
  ]);
  return;
 }
 if(name==='captcha'){
  setInput(false);
  loadChallenge();
  return;
 }
 const optional=name==='email'||name==='city';
 buttons(optional?[{label:'Skip this question',action:()=>accept('') }]:[]);
 if(name==='phone')setInput(true,{type:'tel',inputMode:'numeric',maxLength:10,placeholder:'10-digit mobile number',autocomplete:'tel'});
 else if(name==='email')setInput(true,{type:'email',maxLength:160,placeholder:'Email address (optional)',autocomplete:'email'});
 else setInput(true,{maxLength:name==='grade'?80:name==='city'?100:140,
  placeholder:({parent_name:'Parent / guardian name',student_name:'Student name',grade:'Class or grade',city:'City (optional)'})[name]||'Your answer',
  autocomplete:name==='parent_name'?'name':'off'});
}
function selectCampus(s){
 if(busy||current!=='campus')return;
 campusCode=s.code;
 bubble('parent',s.name);
 question('parent_name');
}
function validate(name,answer){
 if(name==='phone')return /^[6-9][0-9]{9}$/.test(answer)?'':'Please enter a valid 10-digit Indian mobile number.';
 if(name==='parent_name'||name==='student_name')return answer.length>=2&&answer.length<=140?'':'Please enter at least 2 characters.';
 if(name==='grade')return answer.length>=1&&answer.length<=80?'':'Please enter the intended class or grade.';
 if(name==='email')return !answer||(/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(answer)&&answer.length<=160)?'':'Please enter a valid email address, or select Skip.';
 if(name==='city')return answer.length<=100?'':'Please enter a city of at most 100 characters.';
 return '';
}
function accept(raw){
 if(busy||!steps.includes(current)||['campus','consent','captcha'].includes(current))return;
 const answer=String(raw).trim().replace(/\s+/g,' ');
 const error=validate(current,answer);
 if(error){feedback(error);input.focus();return;}
 // Do not display contact data in the visible transcript.
 bubble('parent',current==='phone'?'Mobile number provided ✓':
  current==='email'?(answer?'Email provided ✓':'Skipped'):
  answer||'Skipped');
 draft[current]=answer;
 const next=steps[steps.indexOf(current)+1];
 if(next)question(next);
}
function giveConsent(agreed){
 if(current!=='consent'||busy)return;
 if(!agreed){
  feedback('Your details have not been submitted.');
  bubble('assistant','That’s fine. I cannot register an enquiry or start admissions Q&A without consent. You can close this chat or agree when ready.');
  return;
 }
 draft.consent=true;bubble('parent','I agree to be contacted about admissions.');
 question('captcha');
}
async function loadChallenge(){
 captcha=null;feedback('Loading verification question…');
 try{
  const result=await get('/challenge');
  if(current!=='captcha')return;
  captcha=result;
  bubble('assistant',result.question);
  choices.replaceChildren();
  setInput(true,{type:'text',inputMode:'numeric',maxLength:3,placeholder:'Your maths answer'});
  feedback('');
 }catch(e){
  feedback('Could not load verification. Please try again.');
  buttons([{label:'Retry verification',action:loadChallenge}]);
 }
}
async function register(value){
 if(busy||current!=='captcha')return;
 if(!captcha)return feedback('Load the verification question first.');
 if(!/^[0-9]{1,3}$/.test(value))return feedback('Enter the numeric answer to the maths question.');
 const data={
  school_code:campusCode,parent_name:draft.parent_name,phone:draft.phone,
  student_name:draft.student_name,grade:draft.grade,
  email:draft.email||'',city:draft.city||'',
  consent:true,captcha_token:captcha.token,captcha_answer:value,website:''
 };
 if(!attemptId)attemptId=nonce();
 busy=true;stage('registering');setInput(false);choices.replaceChildren();
 bubble('parent','Verification completed');
 feedback('Creating your school enquiry…');
 try{
  const created=await post('/chat-start',data,{'Idempotency-Key':attemptId});
  if(!created.session)throw new Error('Could not start the admissions session.');
  session=created.session;quickQuestions=Array.isArray(created.quick_questions)?created.quick_questions:[];
  // Do not retain guardian or student contact details after successful submission.
  draft={};captcha=null;attemptId='';
  stage('qa');bubble('assistant','Thank you! Your enquiry is registered. You can now ask about the admission process, classes, fees, documents or campus visits. Please avoid sharing personal details in questions.');
  renderQuickQuestions();
  setInput(true,{maxLength:400,placeholder:'Ask about admissions…'});
  feedback('Your admission enquiry has been saved.');
 }catch(err){
  stage('captcha');bubble('assistant','I could not complete the enquiry. '+(err.message||'Please try again.')+' I’ll provide a new verification question.');
  feedback('Your enquiry is not confirmed.');await loadChallenge();
 }finally{busy=false;sendButton.disabled=false;}
}
function renderQuickQuestions(){
 const options=quickQuestions.slice(0,8).filter(s=>typeof s==='string'&&s.length<181)
   .map(q=>({label:q,action:()=>ask(q)}));
 if(!callbackRequested)options.push({label:'Request admissions callback',action:requestCallback});
 buttons(options);
}
async function ask(text){
 if(!session||current!=='qa'||busy)return;
 const questionText=String(text).trim();
 if(questionText.length<3||questionText.length>400)return feedback('Enter a question of 3–400 characters.');
 bubble('parent',questionText);input.value='';
 busy=true;sendButton.disabled=true;feedback('Finding an answer…');
 try{
  const result=await post('/chat',{school_code:campusCode,question:questionText,session});
  bubble('assistant',result.answer);
 }catch(e){
  bubble('assistant','I cannot answer that right now. You can request a callback from the admissions team.');
 }
 finally{busy=false;sendButton.disabled=false;feedback('');}
}
async function requestCallback(){
 if(!session||busy||callbackRequested)return;
 busy=true;feedback('Sending callback request…');
 try{
  await post('/chat-followup',{school_code:campusCode,session});
  callbackRequested=true;
  bubble('assistant','Your callback request is recorded. The admissions team will follow up using the contact details you provided.');
  renderQuickQuestions();
  feedback('');
 }catch(err){feedback(err.message||'Could not request a callback.');}
 finally{busy=false;}
}
async function start(){
 if(started)return;
 started=true;stage('loading');setInput(false);
 bubble('assistant','Welcome! I’ll take a few details through this conversation, one question at a time, before we begin admissions Q&A.');
 feedback('Loading available school campuses…');
 try{
  const data=await get('/chat-config');
  schools=(Array.isArray(data.schools)?data.schools:[]).filter(s=>typeof s.name==='string'&&typeof s.code==='string');
  if(!schools.length){
   bubble('assistant','Admissions chat is not enabled for this website yet.');
   feedback('No campuses available');return;
  }
  question('campus');
 }catch(e){
  bubble('assistant','I could not load the campus list. Please retry.');
  feedback('Campus list unavailable.');
  buttons([{label:'Retry',action:async()=>{started=false;history.replaceChildren();choices.replaceChildren();await start();}}]);
 }
}
$('.sfac-launch').addEventListener('click',()=>{
 panel.hidden=!panel.hidden;$('.sfac-launch').setAttribute('aria-expanded',String(!panel.hidden));
 if(!panel.hidden){start();if(current==='qa')input.focus();}
});
$('.sfac-close').addEventListener('click',()=>{
 panel.hidden=true;$('.sfac-launch').setAttribute('aria-expanded','false');$('.sfac-launch').focus();
});
entry.addEventListener('submit',e=>{
 e.preventDefault();
 if(busy)return;
 const value=input.value.trim();
 if(current==='captcha')register(value);
 else if(current==='qa')ask(value);
 else accept(value);
});
})();