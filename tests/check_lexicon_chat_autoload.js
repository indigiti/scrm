// One-tag Lexicon embed regression: chatbot autoload is optional and nonblocking.
const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../public_html/static/lexicon-form.js','utf8');
const guid='ab123456-78ab-4abc-8def-123456789abc';
async function runCase(config,options={}){
 const appended=[],requests=[],listeners=[];
 const loader={
  src:'https://crm.example.test/scrm/static/lexicon-form.js?v=1',
  dataset:{schoolflowGroup:guid},
  getAttribute(name){
   if(name==='data-schoolflow-group')return guid;
   if(name==='data-admissions-chat')return options.off?'off':null;
   return null;
  }
 };
 const head={appendChild(el){appended.push(el);return el;}};
 const document={
  currentScript:loader,head,readyState:'loading',
  createElement(tag){
   const attributes={};
   return {tagName:tag.toUpperCase(),dataset:{},setAttribute(name,value){
    attributes[name]=String(value);if(name==='data-schoolflow-group')this.dataset.schoolflowGroup=String(value);
   },getAttribute(name){return attributes[name]??null;}};
  },
  querySelectorAll(){
    if(!options.manual)return [];
    return [{src:'https://crm.example.test/scrm/static/admissions-chat.js',
      getAttribute(name){return name==='data-schoolflow-group'?guid:null;}}];
  },
  addEventListener(type,callback){listeners.push(type);}
 };
 const ctx={
  document,window:{__schoolflowAdmissionsChatMounted:!!options.mounted},
  URL,console,
  fetch:async (url,settings)=>{
   requests.push({url,settings});
   if(options.failure)throw new Error('CRM offline');
   return {ok:true,json:async()=>config};
  }
 };
 vm.runInNewContext(source,ctx,{filename:'lexicon-form.js'});
 await new Promise(r=>setImmediate(r));
 const scripts=appended.filter(e=>e.tagName==='SCRIPT');
 return {scripts,appended,requests,listeners,ctx};
}
(async()=>{
 const enabled={name:'School Group',schools:[{code:'campus-1',name:'Main Campus'}]};
 let r=await runCase(enabled);
 assert.equal(r.requests.length,1,'checks public enabled config only once');
 assert.equal(r.requests[0].url,'https://crm.example.test/scrm/api/public/forms/'+guid+'/chat-config');
 assert.equal(r.requests[0].settings.credentials,'omit');
 assert.equal(r.scripts.length,1,'enabled campus loads chatbot automatically');
 assert.match(r.scripts[0].src,/\/static\/admissions-chat\.js\?v=/);
 assert.equal(r.scripts[0].dataset.schoolflowGroup,guid,'existing group GUID forwarded');
 assert.equal(r.listeners.includes('DOMContentLoaded'),true,'original enquiry form still initializes');
 r=await runCase({schools:[]});
 assert.equal(r.scripts.length,0,'disabled campus never adds chatbot');
 r=await runCase(enabled,{failure:true});
 assert.equal(r.scripts.length,0,'API outage leaves original form unaffected');
 r=await runCase(enabled,{off:true});
 assert.equal(r.requests.length,0,'optional explicit opt-out skips chatbot');
 r=await runCase(enabled,{manual:true});
 assert.equal(r.scripts.length,0,'existing standalone chat embed is not duplicated');
 r=await runCase(enabled,{mounted:true});
 assert.equal(r.scripts.length,0,'mounted chat is not duplicated');
 console.log('PASS: previous Lexicon form code loads enabled chatbot, preserves original form, handles disabled/offline/duplicate cases');
})().catch(e=>{console.error(e);process.exitCode=1;});
