/* Lexicon SchoolFlow native embed: one script tag in the website HEAD. */
(function(){
'use strict';
var loader=document.currentScript;
if(!loader || loader.dataset.schoolflowGroup===undefined || window.__schoolflowLexiconFormLoaded)return;
var guid=(loader.getAttribute('data-schoolflow-group')||'').trim().toLowerCase();
if(!/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(guid)){
  console.error('SchoolFlow: data-schoolflow-group must be the public group GUID.');return;
}
window.__schoolflowLexiconFormLoaded=true;
var url=new URL(loader.src),base=url.href.replace(/\/static\/lexicon-form\.js(?:\?.*)?$/,'');
var api=base+'/api/public/forms/'+encodeURIComponent(guid);
var thankYou=loader.dataset.thankYou||'https://lexiconedu.in/thank-you/';
var logo=loader.dataset.logo||'https://lexiconedu.in/wp-content/uploads/2025/10/The-Lexicon-Schools-Logo.png';
var css=document.createElement('link');css.rel='stylesheet';css.href=base+'/static/lexicon-form.css?v='+encodeURIComponent(loader.getAttribute('data-schoolflow-form-css-version')||'1');document.head.appendChild(css);

/**
 * Reuse the existing one-tag installation: load Admissions AI automatically
 * only if a campus in this approved group has enabled the public assistant.
 * A chatbot/API failure never prevents the original enquiry form from working.
 */
function loadAdmissionsChat(){
 if(loader.getAttribute('data-admissions-chat')==='off' || typeof fetch!=='function')return;
 fetch(api+'/chat-config',{method:'GET',cache:'no-store',credentials:'omit',headers:{Accept:'application/json'}})
 .then(function(response){return response.ok?response.json():null;})
 .then(function(config){
  if(!config || !Array.isArray(config.schools) || config.schools.length===0)return;
  // Also support legacy websites which explicitly embed admissions-chat.js.
  if(window.__schoolflowAdmissionsChatMounted)return;
  var scripts=document.querySelectorAll('script[data-schoolflow-group]');
  for(var i=0;i<scripts.length;i++){
   var existing=scripts[i];
   if(existing!==loader && existing.getAttribute('data-schoolflow-group')===guid &&
      /\/static\/admissions-chat\.js(?:\?|$)/.test(existing.src||''))return;
  }
  var script=document.createElement('script');
  script.async=true;
  script.src=base+'/static/admissions-chat.js?v='+encodeURIComponent(loader.getAttribute('data-schoolflow-chat-version')||'single-loader-1');
  script.setAttribute('data-schoolflow-chat-css-version',loader.getAttribute('data-schoolflow-chat-css-version')||'1');
  script.setAttribute('data-schoolflow-group',guid);
  script.setAttribute('data-schoolflow-chat-autoload','');
  document.head.appendChild(script);
 })
 .catch(function(){/* Degrade gracefully: the existing admission form still works. */});
}
loadAdmissionsChat();

function init(){
 if(document.getElementById('leadPopup'))return;
 var tabs=document.createElement('div');tabs.className='side-tabs';
 var trigger=document.createElement('button');trigger.type='button';trigger.className='side-tab';trigger.textContent='Enquire Now';
 trigger.setAttribute('aria-haspopup','dialog');tabs.appendChild(trigger);document.body.appendChild(tabs);
 var box=document.createElement('div');
 box.innerHTML=[
 '<div id="leadPopup" role="dialog" aria-modal="true" aria-labelledby="lf_title" aria-hidden="true">',
 '<div class="lf-popup-box" tabindex="-1">',
 '<button type="button" id="lf_close" aria-label="Close enquiry form">&times;</button>',
 '<img class="lf-logo" alt="The Lexicon Schools" loading="eager">',
 '<h3 class="lf-title" id="lf_title">Admission Enquiry</h3>',
 '<form id="lf_form" novalidate>',
 '<input id="lf_name" name="student_name" class="lf-input" placeholder="Student Name" autocomplete="off" maxlength="140" required>',
 '<input id="lf_parent" name="parent_name" class="lf-input" placeholder="Parent / Guardian Name" autocomplete="name" maxlength="140" required>',
 '<input id="lf_email" name="email" class="lf-input" type="email" placeholder="Email" autocomplete="email" maxlength="160" required>',
 '<input id="lf_phone" name="phone" class="lf-input" type="tel" placeholder="Phone (10 digits)" autocomplete="tel" inputmode="numeric" maxlength="10" required>',
 '<input id="lf_pincode" name="pincode" class="lf-input" placeholder="Pincode" autocomplete="postal-code" inputmode="numeric" maxlength="6" required>',
 '<div class="lf-row">',
 '<input id="lf_state" name="state" class="lf-input lf-readonly" placeholder="State" aria-label="State" readonly required>',
 '<input id="lf_city" name="city" class="lf-input lf-readonly" placeholder="City" aria-label="City" readonly required>',
 '</div><div class="lf-row">',
 '<select id="lf_class" name="grade" class="lf-select" required>',
 '<option value="" disabled selected hidden>Select Class</option>',
 '<option>Nursery</option><option>LKG</option><option>UKG</option>',
 '<option>I</option><option>II</option><option>III</option><option>IV</option><option>V</option><option>VI</option>',
 '<option>VII</option><option>VIII</option><option>IX</option><option>X</option><option>XI</option><option>XII</option>',
 '</select><select id="lf_campus" name="school_code" class="lf-select" required disabled>',
 '<option value="">Loading campuses...</option></select></div>',
 '<div class="lf-captcha"><span id="lf_captcha_label">Loading math CAPTCHA...</span>',
 '<input id="lf_captcha" class="lf-input" type="text" placeholder="Answer" autocomplete="off" inputmode="numeric" maxlength="3" required></div>',
 '<label class="lf-consent"><input id="lf_consent" type="checkbox" required>',
 '<span>I agree to be contacted by the selected school regarding this admission enquiry.</span></label>',
 '<label class="lf-honeypot" aria-hidden="true">Leave blank<input type="text" id="lf_website" tabindex="-1" autocomplete="off"></label>',
 '<p class="lf-feedback" id="lf_feedback" role="alert" aria-live="polite"></p>',
 '<button id="lf_submit" type="submit">SUBMIT</button></form></div></div>'
 ].join('');
 var popup=box.firstElementChild;popup.querySelector('.lf-logo').src=logo;document.body.appendChild(popup);
 var $=function(id){return document.getElementById(id);};
 var form=$('lf_form'),submit=$('lf_submit'),campus=$('lf_campus'),pin=$('lf_pincode');
 var state=$('lf_state'),city=$('lf_city'),phone=$('lf_phone'),answer=$('lf_captcha');
 var token='',latestLookup=0,lastFocus=null,loadedAt=0,attemptId='',blocked=false;
 // The enquiry form is a gentle fallback when the visitor is not using chat.
 // These optional website attributes allow site owners to opt out or tune timing.
 var autoEnabled=loader.getAttribute('data-schoolflow-auto-enquiry')!=='off';
 var exitEnabled=loader.getAttribute('data-schoolflow-exit-intent')!=='off';
 var configuredDelay=Number(loader.getAttribute('data-schoolflow-enquiry-delay'));
 var autoDelay=Number.isFinite(configuredDelay)&&configuredDelay>=8000&&configuredDelay<=180000?configuredDelay:30000;
 var pageOpenedAt=Date.now(),autoShownHere=false,deferredForChat=false,closeGraceTimer=null;
 var autoShownKey='schoolflow_lexicon_auto_shown_'+guid;
 async function decode(response){
  var data=await response.json().catch(function(){return {};});
  if(!response.ok)throw new Error(data.detail||('SchoolFlow unavailable ('+response.status+')'));
  return data;
 }
 var get=function(route){return fetch(api+route,{method:'GET',cache:'no-store',credentials:'omit',headers:{Accept:'application/json'}}).then(decode);};
 function feedback(message,success){$('lf_feedback').textContent=message||'';$('lf_feedback').classList.toggle('lf-success',!!success);}
 function closed(){try{return sessionStorage.getItem('schoolflow_lexicon_closed_'+guid)==='1';}catch(e){return false;}}
 function remember(){try{sessionStorage.setItem('schoolflow_lexicon_closed_'+guid,'1');}catch(e){}}
 function previouslyPrompted(){try{return sessionStorage.getItem(autoShownKey)==='1';}catch(e){return autoShownHere;}}
 function rememberPrompt(){autoShownHere=true;try{sessionStorage.setItem(autoShownKey,'1');}catch(e){}}
 function chatbotOpen(){
  var chat=window.__schoolflowAdmissionsChatState;
  // Conservative fallback for a legacy chatbot build with no visibility state.
  if(window.__schoolflowAdmissionsChatMounted===guid && (!chat||chat.guid!==guid))return true;
  return !!(chat&&chat.guid===guid&&chat.open);
 }
 function promptIfEligible(reason){
  if(!autoEnabled||closed()||autoShownHere||previouslyPrompted()||popup.classList.contains('lf-visible')||document.visibilityState==='hidden')return;
  var activeChat=window.__schoolflowAdmissionsChatState;
  if(activeChat&&activeChat.guid===guid&&activeChat.leadCaptured)return;
  if(chatbotOpen()){
   if(reason==='timer')deferredForChat=true;
   return;
  }
  if(reason==='timer' && Date.now()-pageOpenedAt<autoDelay)return;
  if(reason==='exit' && Date.now()-pageOpenedAt<8000)return;
  open();
  rememberPrompt();
 }
 function scheduleAfterChat(){
  if(closeGraceTimer!==null)clearTimeout(closeGraceTimer);
  closeGraceTimer=setTimeout(function(){closeGraceTimer=null;promptIfEligible('timer');},15000);
 }
 if(autoEnabled){
  setTimeout(function(){promptIfEligible('timer');},autoDelay);
  if(exitEnabled)document.addEventListener('mouseout',function(event){
   // Desktop exit intent only: leave through the top of the viewport.
   if(event.relatedTarget||event.toElement||event.clientY>10)return;
   promptIfEligible('exit');
  });
  document.addEventListener('schoolflow:admissions-chat-state',function(event){
   if(!event.detail||event.detail.guid!==guid)return;
   if(event.detail.open){
    if(closeGraceTimer!==null){clearTimeout(closeGraceTimer);closeGraceTimer=null;}
   }else if(deferredForChat){
    deferredForChat=false;
    scheduleAfterChat();
   }
  });
  // Background tabs do not launch a hidden modal.
  document.addEventListener('visibilitychange',function(){
   if(document.visibilityState==='visible'&&deferredForChat&&!chatbotOpen()){
    deferredForChat=false;scheduleAfterChat();
   }
  });
 }
 function resetCaptcha(){
  token='';$('lf_captcha_label').textContent='Loading math CAPTCHA...';answer.value='';
  get('/challenge').then(function(data){token=data.token;$('lf_captcha_label').textContent=data.question;})
  .catch(function(){$('lf_captcha_label').textContent='CAPTCHA unavailable';feedback('Cannot load the math question. Please reopen the form.');});
 }
 // The empty option is an instruction, not a school. It must never be
 // selectable or sent as a campus code.
 function campusPlaceholder(label){
  var option=new Option(label,'',true,true);
  option.disabled=true;option.hidden=true;
  return option;
 }
 function loadSchools(force){
  if(!force && Date.now()-loadedAt<60000 && !campus.disabled)return Promise.resolve();
  var selected=campus.value;campus.disabled=true;
  campus.replaceChildren(campusPlaceholder('Loading campuses...'));
  return get('/config').then(function(data){
   campus.replaceChildren(campusPlaceholder('Select Campus'));
   var seen=new Set();
   (Array.isArray(data.schools)?data.schools:[]).forEach(function(item){
    if(!item||typeof item.code!=='string'||typeof item.name!=='string')return;
    var code=item.code.trim(),name=item.name.trim();
    if(!code||!name||seen.has(code))return;
    seen.add(code);campus.add(new Option(name,code));
   });
   campus.disabled=seen.size===0;
   // Preserve a choice only while it is still present in the approved group.
   if(!campus.disabled && selected && seen.has(selected))campus.value=selected;
   else campus.value='';
   if(campus.disabled)feedback('No campus is available for this website.');
   loadedAt=Date.now();
  }).catch(function(){
   campus.replaceChildren(campusPlaceholder('Campuses unavailable'));
   campus.disabled=true;feedback('Cannot load campuses. Please try again.');
  });
 }
 function open(){
  lastFocus=document.activeElement;
  popup.classList.add('lf-visible');popup.setAttribute('aria-hidden','false');document.body.classList.add('lf-popup-open');
  feedback('');loadSchools(true);resetCaptcha();$('lf_name').focus();
 }
 function close(){
  popup.classList.remove('lf-visible');popup.setAttribute('aria-hidden','true');document.body.classList.remove('lf-popup-open');
  remember();if(lastFocus && typeof lastFocus.focus==='function')lastFocus.focus();
 }
 window.openLeadPopup=open;
 trigger.addEventListener('click',open);$('lf_close').addEventListener('click',close);
 popup.addEventListener('click',function(event){if(event.target===popup)close();});
 document.addEventListener('keydown',function(event){
  if(!popup.classList.contains('lf-visible'))return;
  if(event.key==='Escape'){event.preventDefault();close();}
  if(event.key==='Tab'){
   var elements=Array.from(popup.querySelectorAll('button:not([disabled]),input:not([disabled]):not([tabindex="-1"]),select:not([disabled])')).filter(function(el){return el.offsetParent!==null;});
   if(!elements.length)return;
   var first=elements[0],last=elements[elements.length-1];
   if(event.shiftKey && document.activeElement===first){event.preventDefault();last.focus();}
   if(!event.shiftKey && document.activeElement===last){event.preventDefault();first.focus();}
  }
 });
 document.addEventListener('click',function(event){var el=event.target.closest('[data-lexicon-form]');if(!el)return;event.preventDefault();open();});
 // Auto-prompt is conditional, session-limited and suppressed while chat is open.
 phone.addEventListener('input',function(){phone.value=phone.value.replace(/\D/g,'').slice(0,10);});
 function manualPin(){state.readOnly=false;city.readOnly=false;state.classList.remove('lf-readonly');city.classList.remove('lf-readonly');}
 function readonlyPin(){state.readOnly=true;city.readOnly=true;state.classList.add('lf-readonly');city.classList.add('lf-readonly');}
 pin.addEventListener('input',function(){
  pin.value=pin.value.replace(/\D/g,'').slice(0,6);
  state.value='';city.value='';var current=++latestLookup;
  if(pin.value.length!==6){readonlyPin();state.placeholder='State';city.placeholder='City';return;}
  readonlyPin();state.placeholder='Looking up...';city.placeholder='Looking up...';
  get('/pin/'+encodeURIComponent(pin.value)).then(function(data){
   if(current!==latestLookup)return;
   state.value=data.state||'';city.value=data.city||'';state.placeholder='State';city.placeholder='City';
   if(!state.value||!city.value)manualPin();
  }).catch(function(){
   if(current!==latestLookup)return;
   manualPin();state.placeholder='State (enter manually)';city.placeholder='City (enter manually)';
  });
 });
 function value(id){return ($(id).value||'').trim();}
 function makeId(){
  if(window.crypto && typeof crypto.randomUUID==='function')return crypto.randomUUID();
  if(!window.crypto || typeof crypto.getRandomValues!=='function')throw new Error('Secure browser required');
  var bytes=new Uint8Array(16);crypto.getRandomValues(bytes);
  return Array.from(bytes).map(function(n){return n.toString(16).padStart(2,'0');}).join('');
 }
 form.addEventListener('submit',async function(event){
  event.preventDefault();if(blocked)return;
  var tel=value('lf_phone');
  if(!value('lf_name') || !value('lf_parent'))return feedback('Enter Student and Parent / Guardian names.');
  if(!$('lf_email').validity.valid || !value('lf_email'))return feedback('Enter a valid email.');
  if(!/^[6-9]\d{9}$/.test(tel))return feedback('Enter a valid 10-digit mobile number.');
  if(!/^\d{6}$/.test(value('lf_pincode')))return feedback('Enter a valid 6-digit Pincode.');
  if(!value('lf_state') || !value('lf_city'))return feedback('State and City are required. Enter manually if lookup failed.');
  if(campus.disabled || !campus.value || !Array.from(campus.options).some(function(option){
   return !option.disabled && option.value===campus.value;
  }))return feedback('Select an available campus.');
  if(!value('lf_class'))return feedback('Select Class.');
  if(!token || !/^\d+$/.test(value('lf_captcha')))return feedback('Please answer the math CAPTCHA.');
  if(!$('lf_consent').checked)return feedback('Please agree to be contacted.');
  if(!attemptId)attemptId=makeId();
  var params=new URLSearchParams(location.search);
  var data={
   student_name:value('lf_name'),parent_name:value('lf_parent'),email:value('lf_email'),phone:tel,
   pincode:value('lf_pincode'),state:value('lf_state'),city:value('lf_city'),grade:value('lf_class'),
   school_code:campus.value,consent:true,captcha_token:token,captcha_answer:value('lf_captcha'),
   website:value('lf_website'),landing_page:location.href.slice(0,500),referrer:document.referrer.slice(0,500),
   utm_source:params.get('utm_source')||'',utm_medium:params.get('utm_medium')||'',
   utm_campaign:params.get('utm_campaign')||'',utm_term:params.get('utm_term')||'',
   utm_content:params.get('utm_content')||''
  };
  blocked=true;submit.disabled=true;submit.textContent='Submitting...';feedback('');
  try{
   await fetch(api+'/enquiries',{method:'POST',credentials:'omit',cache:'no-store',
    headers:{'Content-Type':'application/json','Idempotency-Key':attemptId},body:JSON.stringify(data)}).then(decode);
   remember();attemptId='';feedback('Enquiry submitted successfully.',true);window.location.assign(thankYou);
  }catch(error){feedback(error.message||'Submission failed. Please try again.');resetCaptcha();}
  finally{blocked=false;submit.disabled=false;submit.textContent='SUBMIT';}
 });
 loadSchools(false);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();