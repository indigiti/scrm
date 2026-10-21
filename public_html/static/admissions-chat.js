/* SchoolFlow AI Admission Counsellor — answer first, contact by permission. */
(function(){
'use strict';
const loader=document.currentScript;
const guid=(loader?.getAttribute('data-schoolflow-group')||'').trim().toLowerCase();
if(!/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(guid))return;
if(window.__schoolflowAdmissionsChatMounted)return;
window.__schoolflowAdmissionsChatMounted=guid;
window.__schoolflowAdmissionsChatState={guid:guid,open:false};
const base=new URL(loader.src).href.replace(/\/static\/admissions-chat\.js(?:\?.*)?$/,'');
const api=base+'/api/public/forms/'+encodeURIComponent(guid);
const host=document.createElement('div');host.className='schoolflow-admissions-host';
const mount=typeof host.attachShadow==='function'?host.attachShadow({mode:'open'}):host;
const css=document.createElement('link');css.rel='stylesheet';
css.href=base+'/static/admissions-chat.css?v='+encodeURIComponent(loader.getAttribute('data-schoolflow-chat-css-version')||'1');
mount.append(css);
const root=document.createElement('section');root.className='sfac';
root.innerHTML=
 '<button type="button" class="sfac-launch" aria-label="Open admission counsellor" aria-expanded="false">✦ Ask Admissions</button>'+
 '<div class="sfac-panel" hidden role="dialog" aria-label="AI Admission Counsellor">'+
 '<header class="sfac-head"><span class="sfac-avatar" aria-hidden="true">✦</span><div class="sfac-headings"><strong>Admission Counsellor</strong><span class="sfac-step">Here to help with your questions</span></div>'+
 '<button type="button" class="sfac-close" aria-label="Close admission counsellor">×</button></header>'+
 '<div class="sfac-history" role="log" aria-label="Conversation" aria-live="polite" aria-relevant="additions"></div>'+
 '<div class="sfac-quick" aria-label="Suggested questions and choices"></div>'+
 '<form class="sfac-entry"><label class="sfac-sr" for="sfac-reply">Your question or reply</label>'+
 '<input id="sfac-reply" type="text" maxlength="400" autocomplete="off" placeholder="Ask about fees, admissions, transport…" required>'+
 '<button class="sfac-send" type="submit" aria-label="Send message">➤</button></form>'+
 '<div class="sfac-footer"><span class="sfac-status" role="status" aria-live="polite"></span></div></div>';
mount.append(root);document.body.append(host);
const $=selector=>root.querySelector(selector);
const panel=$('.sfac-panel'),history=$('.sfac-history'),chips=$('.sfac-quick'),
entry=$('.sfac-entry'),input=$('#sfac-reply'),sendButton=$('.sfac-send');
const storageKey='schoolflow:admissions:return:'+guid,anonKey='schoolflow:counsellor:anonymous:'+guid;
let started=false,busy=false,schoolCode='',schools=[],conversationToken='',crmSession='';
let profile={},contactState='',contactDraft={},suggestedContact=false,callbackRequested=false,needDetail='',pendingTopic='';
let introductionOffered=false,answeredCount=0;
let verification=null,attemptId='',pendingCampusContact=false;
function status(s){$('.sfac-status').textContent=s||'';}
/** The assistant returns Markdown occasionally. Show readable plain text,
 * preserving bullet lines, without interpreting untrusted HTML/JS.
 */
function displayText(value){
 return String(value||'').replace(/\r\n?/g,'\n')
  .replace(/^\s{0,3}#{1,5}\s+/gm,'')
  .replace(/\*\*([^*\n]+)\*\*/g,'$1')
  .replace(/^\s*[-*]\s+/gm,'• ')
  .replace(/\[([^\]\n]+)\]\(https?:\/\/[^)\s]+\)/g,'$1')
  .replace(/\n{3,}/g,'\n\n')
  .trim();
}
function bubble(who,s){
 const element=document.createElement('p');element.className='sfac-bubble '+(who==='parent'?'parent':'assistant');
 element.textContent=who==='assistant'?displayText(s):String(s);
 history.append(element);history.scrollTop=history.scrollHeight;
}
function choices(items){
 chips.replaceChildren();
 for(const item of items){
  const b=document.createElement('button');b.type='button';b.className='sfac-chip';
  b.textContent=item.label;b.addEventListener('click',item.onClick);chips.append(b);
 }
}
function field(visible,options={}){
 entry.hidden=!visible;input.value='';
 input.type=options.type||'text';input.inputMode=options.inputMode||'text';
 input.maxLength=options.maxLength||400;input.placeholder=options.placeholder||'Ask me about admissions…';
 if(visible&&!panel.hidden)input.focus();
}
function headline(s){$('.sfac-step').textContent=s;}
function nonce(){return Array.from(crypto.getRandomValues(new Uint8Array(16)),
 b=>b.toString(16).padStart(2,'0')).join('');}
async function request(path,body=null,headers={}){
 const response=await fetch(api+path,{method:body?'POST':'GET',cache:'no-store',credentials:'omit',
  headers:{Accept:'application/json',...(body?{'Content-Type':'application/json'}:{}),...headers},
  ...(body?{body:JSON.stringify(body)}:{})});
 const result=await response.json().catch(()=>({}));
 if(!response.ok)throw new Error(result.detail||'The school service is temporarily unavailable.');
 return result;
}
function saveAnonymous(){
 try{
  if(conversationToken)sessionStorage.setItem(anonKey,conversationToken);
  else sessionStorage.removeItem(anonKey);
 }catch(e){}
}
function loadAnonymous(){
 try{const t=sessionStorage.getItem(anonKey)||'';return /^[A-Za-z0-9_-]{43}$/.test(t)?t:'';}
 catch(e){return '';}
}
function savedReturn(){
 try{const v=JSON.parse(localStorage.getItem(storageKey)||'null');
  return v&&/^[A-Za-z0-9_-]{43}$/.test(v.token||'')&&Number(v.expires)>Date.now()/1000?v:null;
 }catch(e){return null;}
}
function saveReturn(v){
 try{if(v.remember_token&&v.remember_expires_at)
   localStorage.setItem(storageKey,JSON.stringify({token:v.remember_token,expires:v.remember_expires_at}));}
 catch(e){status('Your browser cannot save this setting. Your conversation can continue.');}
}
function forgetLocal(){
 try{localStorage.removeItem(storageKey);}catch(e){}
}
function suggested(){
 const options=[];
 // Respond to the active admission question instead of offering only a
 // callback after the parent has selected a campus.
 if(schoolCode && ['fees','age_eligibility'].includes(pendingTopic)){
  if(needDetail==='academic_year'){
   for(const year of ['2026–27','2027–28'])
     options.push({label:year,onClick:()=>chat(year)});
  }else if(needDetail==='grade'){
   for(const grade of ['Nursery','Class I','Class II','Class XI'])
     options.push({label:grade,onClick:()=>chat(grade)});
  }
 }
 if(!schoolCode && schools.length>1)
   for(const s of schools.slice(0,8))
     options.push({label:s.name,onClick:()=>pickCampus(s)});
 if(!schoolCode && schools.length===1)
   options.push({label:schools[0].name,onClick:()=>pickCampus(schools[0])});
 if(suggestedContact)
   options.push({label:'Request a visit / callback',onClick:beginContact});
 if(!contactState&&crmSession)
   options.push({label:'Request admissions callback',onClick:requestCallback});
 if(!contactState&& !crmSession && schoolCode)
   options.push({label:'Ask admissions to contact me',onClick:beginContact});
 choices(options);
}
async function pickCampus(s){
 schoolCode=s.code;bubble('parent',s.name);
 // Always leave the question box available; campus choice isn't a form.
 if(pendingCampusContact){pendingCampusContact=false;beginContact();return;}
 const text='I am interested in the '+s.name+' campus.';
 await chat(text,false);
}
function updateProfile(result){
 if(result.school_code)schoolCode=result.school_code;
 if(result.conversation_token){conversationToken=result.conversation_token;saveAnonymous();}
 profile=result.profile||profile;
 pendingTopic=result.pending_topic||pendingTopic;
 needDetail=result.needs_detail||'';
 suggestedContact=!!result.suggest_contact;
 headline(result.campus_name?result.campus_name+' · Here to help':'Here to help with admissions');
}
function introduce(){
 if(contactState||busy)return;
 contactState='intro-name';
 bubble('assistant','What name should I use?');
 field(true,{maxLength:100,placeholder:'Enter your name'});
 choices([]);
}
function finishIntroduction(name){
 const clean=textSafe(name).replace(/[<>]/g,'').trim();
 if(clean.length<2 || clean.length>100 || /[?!]/.test(clean)){
  status('Please enter your name to continue.');
  return;
 }
 contactDraft.parent_name=clean;
 bubble('parent',clean);
 bubble('assistant','Nice to meet you, '+clean+'. What else would you like to know about admissions?');
 status('');
 contactState='';
 field(true,{placeholder:'Ask another admissions question…'});
 choices([{label:'Request a callback',onClick:beginContact},
          {label:'Continue asking',onClick:suggested}]);
}

function textSafe(raw){
 return String(raw||'').trim().slice(0,400);
}
async function chat(message,echo=true){
 if(busy||contactState)return;
 let cleaned=textSafe(message);
 if(cleaned.length<2)return status('Please ask a question to continue.');
 // If the parent volunteers a mobile number, do not put it in the AI prompt.
 const phone=(cleaned.match(/(?:^|[^\d])([6-9]\d{9})(?!\d)/)||[])[1];
 if(phone && !crmSession){
  const name=cleaned.replace(phone,'').replace(/^[,\s]+|[,\s]+$/g,'').replace(/[.,;:]/g,'').trim();
  if(name.length>=2&&name.length<=100)contactDraft.parent_name=name;
  contactDraft.phone=phone;
  bubble('parent','Contact details provided');
  bubble('assistant','Thank you. May the admissions team use this number to follow up about your enquiry?');
  contactState='consent';field(false);choices([
   {label:'Yes, I agree',onClick:()=>consent(true)},
   {label:'No, thanks',onClick:()=>consent(false)}]);
  return;
 }
 if(echo)bubble('parent',cleaned);
 busy=true;sendButton.disabled=true;status('One moment, I’ll look into that for you…');
 try{
  const result=await request('/counsel',{
   message:cleaned,school_code:schoolCode,
   conversation_token:conversationToken, ...(crmSession?{chat_session:crmSession}:{})
  });
  updateProfile(result);bubble('assistant',result.reply);
  answeredCount++;
  if(!introductionOffered && answeredCount===1 && !crmSession){
   introductionOffered=true;
   bubble('assistant','By the way, what should I call you?');
   choices([{label:'Tell you my name',onClick:introduce},
      {label:'Ask for a callback',onClick:beginContact}]);
  }else suggested();
 }catch(e){
  if(/expired/i.test(e.message)){conversationToken='';saveAnonymous();}
  bubble('assistant','I could not load that answer right now. Please try again or ask our admissions team.');
  status(e.message);suggested();
 }finally{busy=false;sendButton.disabled=false;if(!$('.sfac-status').textContent.includes('expired'))status('');}
}
function beginContact(){
 if(busy)return;
 if(!schoolCode){
  pendingCampusContact=true;bubble('assistant','Which campus should follow up with you?');
  choices(schools.map(s=>({label:s.name,onClick:()=>pickCampus(s)})));
  return;
 }
 if(crmSession){requestCallback();return;}
 const knownName=contactDraft.parent_name||'';
 if(knownName){
  contactState='phone';
  bubble('assistant','Certainly. What 10-digit mobile number can the school admissions team use to contact you? I’ll ask for your permission before sharing the request.');
  field(true,{type:'tel',inputMode:'numeric',maxLength:10,placeholder:'Mobile number for optional follow-up'});
  choices([]);
  return;
 }
 contactState='name';contactDraft={};bubble('assistant',
  'I can ask the school admissions team to contact you. May I have your name?');
 headline('Admissions follow-up');field(true,{maxLength:100,placeholder:'Enter your name'});
 choices([]);
}
function captureContact(raw){
 const value=textSafe(raw);
 if(contactState==='name'){
  if(value.length<2 || value.length>100 || /[?!]/.test(value))return status('Please enter your name to continue.');
  contactDraft.parent_name=value;
  bubble('parent',value);
  status('');
  contactState='phone';
  bubble('assistant','Which 10-digit mobile number can admissions use to reach you?');
  field(true,{type:'tel',inputMode:'numeric',maxLength:10,placeholder:'Mobile number'});
  choices([]);
 }else if(contactState==='phone'){
  if(!/^[6-9][0-9]{9}$/.test(value))return status('Please provide a valid 10-digit mobile number.');
  contactDraft.phone=value;bubble('parent','Mobile number provided');
  contactState='consent';field(false);
  bubble('assistant','Do you agree that the selected school can store this number and contact you about the enquiry or visit?');
  choices([{label:'Yes, I agree',onClick:()=>consent(true)},
   {label:'No, thanks',onClick:()=>consent(false)}]);
 }
}
function consent(ok){
 if(contactState!=='consent')return;
 if(!ok){
  contactDraft={};contactState='';bubble('assistant','No problem. We can keep discussing the school without storing your contact details.');
  headline('Here to answer your questions');field(true);suggested();return;
 }
 contactState='verification';bubble('parent','I agree to admission follow-up.');
 choices([]);field(false);loadCaptcha();
}
async function loadCaptcha(){
 if(contactState!=='verification')return;
 status('Preparing verification…');
 try{
  verification=await request('/challenge');
  bubble('assistant','One quick check before I send your request: '+verification.question);
  field(true,{maxLength:3,inputMode:'numeric',placeholder:'Maths verification answer'});
  choices([]);
  status('');
 }catch(e){
  status('Verification unavailable.');
  choices([{label:'Retry verification',onClick:loadCaptcha}]);
 }
}
async function convert(answer){
 if(contactState!=='verification'||busy)return;
 if(!verification)return status('Please load verification first.');
 if(!/^\d{1,3}$/.test(answer))return status('Enter the maths answer as digits.');
 if(!attemptId)attemptId=nonce();
 busy=true;sendButton.disabled=true;status('Saving your request with the school…');
 try{
  const result=await request('/counsel-lead',{
   conversation_token:conversationToken,school_code:schoolCode,
   parent_name:contactDraft.parent_name||'',phone:contactDraft.phone,
   consent:true,captcha_token:verification.token,captcha_answer:answer
  },{'Idempotency-Key':attemptId});
  crmSession=result.session;
  contactDraft={};verification=null;contactState='';attemptId='';
  bubble('assistant','Thank you! Your contact request is recorded with the selected school. The admissions team can confirm visit times and other campus-specific details.');
  headline('Enquiry registered · Ask anything');
  field(true);suggested();
  // Do not persist a returning credential without a separate opt-in.
  choices([{label:'Remember this browser (up to 30 days)',onClick:remember},
    {label:'Not now',onClick:suggested},
    {label:'Request callback',onClick:requestCallback}]);
 }catch(e){
  status(e.message);
  bubble('assistant','Your request has not been confirmed. I can offer a fresh verification question.');
  await loadCaptcha();
 }finally{busy=false;sendButton.disabled=false;}
}
async function remember(){
 if(!crmSession)return;
 try{
  const v=await request('/chat-remember',{school_code:schoolCode,session:crmSession});
  saveReturn(v);bubble('assistant','This browser can now continue the same enquiry next time. On a shared device, others using this browser could also resume it.');
 }catch(e){status('Could not save return access. You can still chat.');}
 suggested();
}
async function requestCallback(){
 if(!crmSession||!schoolCode||busy||callbackRequested)return;
 busy=true;
 try{await request('/chat-followup',{school_code:schoolCode,session:crmSession});
  callbackRequested=true;bubble('assistant','The admissions team has your callback request. They will contact you using the details you consented to share.');
 }catch(e){status('Could not request a callback. Please retry.');}
 finally{busy=false;suggested();}
}
async function restore(){
 const v=savedReturn();if(!v)return;
 busy=true;status('Restoring your admission enquiry…');
 try{
  const r=await request('/chat-resume',{remember_token:v.token});
  crmSession=r.session;schoolCode=r.school_code;conversationToken='';
  saveAnonymous();saveReturn(r);profile={};headline(r.campus_name+' · Enquiry resumed');
  bubble('assistant','Welcome back! Your previous school enquiry is still available. I can continue helping without asking for your contact details again. Previous chat messages are not stored.');
  suggested();field(true);
 }catch(e){
  forgetLocal();bubble('assistant','The previous enquiry can no longer be resumed. You can start a new conversation with no registration required.');
  choices([]);field(true);
 }finally{busy=false;status('');}
}
async function begin(){
 if(started)return;started=true;
 bubble('assistant','Hello, welcome! 👋 I’m your school admissions guide. You can ask me about fees, age eligibility, required documents, facilities, transport or choosing the right class. What would you like to know?');
 field(true);
 try{
  const data=await request('/chat-config');
  schools=Array.isArray(data.schools)?data.schools.filter(s=>typeof s.name==='string'&&typeof s.code==='string'):[];
  if(schools.length===1)schoolCode=schools[0].code;
  conversationToken=loadAnonymous();
  if(savedReturn()){
   bubble('assistant','Welcome back! Would you like to continue your previous enquiry or start a new conversation?');
   choices([{label:'Continue previous enquiry',onClick:restore},
     {label:'Start new chat',onClick:async()=>{const v=savedReturn();forgetLocal();
       if(v)request('/chat-forget',{remember_token:v.token}).catch(()=>{});
       crmSession='';conversationToken='';saveAnonymous();schoolCode=schools.length===1?schools[0].code:'';
       pendingTopic='';needDetail='';profile={};introductionOffered=false;answeredCount=0;
       history.replaceChildren();bubble('assistant','Of course. What would you like to know about admissions?');suggested();}}]);
  }else {
   choices([{label:'Fees',onClick:()=>chat('What are the school fees?')},
    {label:'Admission documents',onClick:()=>chat('What documents are required for admission?')},
    {label:'Age eligibility',onClick:()=>chat('What is the admission age requirement?')},
    {label:'Bus transport',onClick:()=>chat('Is bus transport available?')},
    ...((schools.length>1)?schools.slice(0,4).map(s=>({label:s.name,onClick:()=>pickCampus(s)})):[])]);
  }
 }catch(e){
  bubble('assistant','I can still chat when the school service is available again. Please retry.');
  status(e.message);started=false;
 }
}
function announceChatState(){
 window.__schoolflowAdmissionsChatState={guid:guid,open:!panel.hidden};
 document.dispatchEvent(new CustomEvent('schoolflow:admissions-chat-state',{
  detail:{guid:guid,open:!panel.hidden}
 }));
}
$('.sfac-launch').addEventListener('click',()=>{
 panel.hidden=!panel.hidden;$('.sfac-launch').setAttribute('aria-expanded',String(!panel.hidden));
 announceChatState();
 if(!panel.hidden){begin();input.focus();}
});
$('.sfac-close').addEventListener('click',()=>{
 panel.hidden=true;$('.sfac-launch').setAttribute('aria-expanded','false');
 announceChatState();
 $('.sfac-launch').focus();
});
entry.addEventListener('submit',e=>{
 e.preventDefault();if(busy)return;
 const value=input.value.trim();if(!value)return;
 input.value='';
 if(contactState==='intro-name')finishIntroduction(value);
 else if(contactState==='name'||contactState==='phone')captureContact(value);
 else if(contactState==='verification')convert(value);
 else chat(value);
});
})();