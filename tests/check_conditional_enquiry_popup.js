const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../public_html/static/lexicon-form.js','utf8');
const guid='ab123456-78ab-4abc-8def-123456789abc';
function fixture(opts={}){
 let now=0,nextId=0,shown=0;
 const timers=new Map(),listeners={},byId={},session=opts.session||new Map();
 function el(tag='div'){
  const handlers={};
  const classes=new Set();
  const e={tagName:tag.toUpperCase(),handlers,children:[],options:[],disabled:false,value:'',textContent:'',style:{},
   classList:{add:k=>classes.add(k),remove:k=>classes.delete(k),contains:k=>classes.has(k),toggle:(k,on)=>on?classes.add(k):classes.delete(k)},
   appendChild(child){this.children.push(child);return child;},
   addEventListener(type,fn){handlers[type]=fn;},
   setAttribute(key,value){this[key]=value;},
   getAttribute(key){return this[key]||null;},
   querySelector(key){if(key==='.lf-logo')return logo;return null;},
   replaceChildren(...children){this.options=children;},
   add(item){this.options.push(item);},
   focus(){this.focused=true;},
  };
  Object.defineProperty(e,'innerHTML',{set(v){this._html=v;this.firstElementChild=popup;},get(){return this._html;}});
  return e;
 }
 const popup=el(),logo=el('img');
 for(const key of ['lf_form','lf_submit','lf_campus','lf_pincode','lf_state','lf_city','lf_phone','lf_captcha','lf_feedback','lf_captcha_label','lf_name','lf_close'])byId[key]=el();
 const triggerButtons=[];
 const body={classList:{add(){},remove(){}},appendChild(node){
  if(node===popup)byId.leadPopup=popup;
  if(node.tagName==='DIV')this.sidebar=node;
 }};
 const loader={src:'https://crm.example.test/scrm/static/lexicon-form.js?v=hash',
  dataset:{schoolflowGroup:guid},
  getAttribute(name){if(name==='data-schoolflow-group')return guid;
   if(name==='data-schoolflow-auto-enquiry')return opts.autoOff?'off':null;
   if(name==='data-schoolflow-exit-intent')return opts.exitOff?'off':null;
   if(name==='data-schoolflow-enquiry-delay')return opts.delay===undefined?null:String(opts.delay);
   return null;}};
 const document={currentScript:loader,head:{appendChild(){}},body,visibilityState:'visible',readyState:'complete',
  activeElement:{focus(){}},
  createElement(type){let node=el(type);if(type==='button')triggerButtons.push(node);return node;},
  getElementById(id){return byId[id]||null;},
  addEventListener(type,fn){(listeners[type]||=[]).push(fn);},
  querySelectorAll(){return [];},
  dispatchEvent(event){(listeners[event.type]||[]).forEach(fn=>fn(event));}
 };
 const context={
  document,window:{},URL,console,
  sessionStorage:{getItem:k=>session.get(k)||null,setItem:(k,v)=>session.set(k,String(v)),removeItem:k=>session.delete(k)},
  Option:function(label,value){return {label,value};},
  Date:class extends Date{static now(){return now;}},
  setTimeout(fn,ms){const id=++nextId;timers.set(id,{fn,at:now+ms});return id;},
  clearTimeout(id){timers.delete(id);},
  fetch:async path=>({ok:true,json:async()=>path.endsWith('/config')?{schools:[]}:
   path.endsWith('/chat-config')?{schools:[]}:{token:'challenge',question:'2 + 2'}})
 };
 vm.runInNewContext(source,context,{filename:'lexicon-form.js'});
 function advance(ms){
  const limit=now+ms;
  for(let pass=0;pass<30;pass++){
   const eligible=[...timers].filter(([,t])=>t.at<=limit).sort((a,b)=>a[1].at-b[1].at);
   if(!eligible.length)break;
   const [id,item]=eligible[0];timers.delete(id);now=item.at;item.fn();
  }
  now=limit;
 }
 function exit(y=0,relatedTarget=null){(listeners.mouseout||[]).forEach(fn=>fn({clientY:y,relatedTarget,toElement:relatedTarget}));}
 function chat(open){
  context.window.__schoolflowAdmissionsChatMounted=guid;
  context.window.__schoolflowAdmissionsChatState={guid,open};
  document.dispatchEvent({type:'schoolflow:admissions-chat-state',detail:{guid,open}});
 }
 return {advance,exit,chat,popup,context,session,triggerButtons,document,
  isOpen:()=>popup.classList.contains('lf-visible')};
}
{
 const t=fixture();
 t.advance(29999);assert.equal(t.isOpen(),false,'timer must not fire early');
 t.advance(1);assert.equal(t.isOpen(),true,'30s timer opens the fallback');
 assert.equal(t.session.get('schoolflow_lexicon_auto_shown_'+guid),'1');
 t.context.window.openLeadPopup();
 assert.equal(t.isOpen(),true,'explicit open works');
}
{
 const t=fixture();
 t.chat(true);t.advance(30000);
 assert.equal(t.isOpen(),false,'active chatbot suppresses timed enquiry');
 t.chat(false);t.advance(14999);
 assert.equal(t.isOpen(),false,'after closing chat, give visitor grace time');
 t.advance(1);
 assert.equal(t.isOpen(),true,'deferred fallback opens after chat closes');
}
{
 const t=fixture();
 t.advance(8000);t.exit(0,{});
 assert.equal(t.isOpen(),false,'ordinary mouseout does not signal exit');
 t.exit(45);assert.equal(t.isOpen(),false,'leaving away from top does not trigger');
 t.chat(true);t.exit(0);assert.equal(t.isOpen(),false,'exit intent cannot interrupt open chat');
 t.chat(false);t.exit(0);assert.equal(t.isOpen(),true,'exit intent opens form when chat is closed');
 t.popup.classList.remove('lf-visible');
 t.triggerButtons.find(b=>b.textContent==='Enquire Now').handlers.click();
 assert.equal(t.isOpen(),true,'manual Enquire Now stays functional');
}
{
 const session=new Map([['schoolflow_lexicon_closed_'+guid,'1']]);
 const t=fixture({session});
 t.advance(30000);t.exit(0);
 assert.equal(t.isOpen(),false,'previous dismissal suppresses all auto popups');
 t.context.window.openLeadPopup();assert.equal(t.isOpen(),true,'dismissal does not disable manual open');
}
{
 const t=fixture();
 t.context.window.__schoolflowAdmissionsChatMounted=guid;
 t.context.window.__schoolflowAdmissionsChatState={guid,open:false,leadCaptured:true};
 t.advance(30000);t.exit(0);
 assert.equal(t.isOpen(),false,'existing chatbot lead is never solicited again');
}
{
 const t=fixture({autoOff:true});
 t.advance(180000);t.exit(0);
 assert.equal(t.isOpen(),false,'site may disable automatic fallback');
 t.context.window.openLeadPopup();assert.equal(t.isOpen(),true,'opt-out preserves manual form');
}
console.log('PASS: delayed form, exit intent, chat suppression, after-chat grace, dismissal and opt-out');
