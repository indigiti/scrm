// Headless browser-model checks for the *actual* short popup embed script.
// This is intentionally dependency-free so it runs in the PHP/Node CI image.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../public_html/static/schoolflow-short-form.js','utf8');
const guid='a8b5de5d-ac84-4169-9347-21144cedd39c';
const defaults={
 enabled:true,trigger:'either',delay_seconds:8,scroll_percent:40,
 exit_intent:true,mobile_enabled:true,repeat_after:'7',position:'center',
 include_paths:[],exclude_paths:[],start_date:'',end_date:'',start_time:'',end_time:'',
 school_codes:['campus-1']
};
function fixture(options={}){
 let now=Date.parse('2026-11-02T10:00:00Z'),nextTimer=1;
 const timers=new Map(),windowHandlers={},documentHandlers={},storage=options.storage||new Map(),
       session=options.session||new Map(),nodes=[];
 const classes=()=>{const set=new Set();return {
  add:s=>set.add(s),remove:s=>set.delete(s),contains:s=>set.has(s),
  toggle:(s,b)=>b?set.add(s):set.delete(s)
 };};
 function node(tag='div'){const el={
  tagName:tag.toUpperCase(),dataset:{},classList:classes(),handlers:{},children:[],
  addEventListener(type,fn){this.handlers[type]=fn;},
  appendChild(child){this.children.push(child);return child;},
  setAttribute(key,value){this[key]=value;},
  focus(){this.focused=true;}
 };nodes.push(el);return el;}
 const campus=node('select');
 campus.options=[];campus.disabled=true;
 campus.replaceChildren=(...items)=>{campus.options=items;};
 campus.add=item=>campus.options.push(item);
 const form=node('form');form.elements={
  school_code:campus,parent_name:node('input'),phone:node('input'),
  captcha_answer:node('input'),otp_code:node('input'),grade:node('select'),consent:node('input'),website:node('input')
 };
 const status=node('p'),submit=node('button'),verify=node('div'),close=node('button'),question=node('label'),refresh=node('button');
 const otp=node('div'),otpPin=node('div'),otpSend=node('button'),otpCheck=node('button'),otpStatus=node('p');
 status.textContent='';verify.hidden=true;
 let outer=null,trigger=null;
 const head={appendChild(){}},body={appendChild(el){
  if(el.className==='sf2-trigger')trigger=el;
  return el;
 }};
 let countDiv=0;
 const document={
  currentScript:{src:'https://crm.example.test/scrm/static/schoolflow-short-form.js?v=xyz',dataset:{
   schoolflowGroup:guid,schoolflowMode:'popup',schoolflowShortCssVersion:'abc'
  }},
  head,body,readyState:'complete',visibilityState:'visible',documentElement:{scrollHeight:2000},
  referrer:'',activeElement:node('button'),
  querySelector(){return null;},
  addEventListener(type,fn){documentHandlers[type]=fn;},
  removeEventListener(type,fn){if(documentHandlers[type]===fn)delete documentHandlers[type];},
  createElement(tag){
   const el=node(tag);
   if(tag==='div'&&++countDiv===2){
    outer=el;
    el.querySelector=selector=>({
     form, '.sf2-verify':verify,'.sf2-status':status,'.sf2-submit':submit,
     '.sf2-close':close,'.sf2-question':question,'.sf2-refresh':refresh,
     '.sf2-otp':otp,'.sf2-otp-pin':otpPin,'.sf2-otp-send':otpSend,
     '.sf2-otp-check':otpCheck,'.sf2-otp-status':otpStatus
    })[selector]||null;
    el.querySelectorAll=()=>[close,form.elements.parent_name,submit];
   }
   return el;
  }
 };
 const sMap=m=>({getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,String(v))});
 const config={...defaults,...options.config};
 const fetch=async url=>({ok:true,json:async()=>url.endsWith('/config')?{
   schools:[{code:'campus-1',name:'Campus One'}],popup:config,landing_pages:options.landingPages
  }: {token:'test',question:'1 + 1'}});
 class Clock extends Date{constructor(...args){super(...(args.length?args:[now]));}static now(){return now;}}
 const context={
  URL,URLSearchParams,Option:function(text,value){this.text=text;this.value=value;},
  document,location:{pathname:options.path||'/admissions',search:'',href:'https://school.test'+(options.path||'/admissions')},
  localStorage:sMap(storage),sessionStorage:sMap(session),Date:Clock,
  fetch,console,crypto:{randomUUID:()=>guid},matchMedia:()=>({matches:!!options.mobile}),
  innerHeight:1000,scrollY:0,
  addEventListener(type,fn){windowHandlers[type]=fn;},
  removeEventListener(type,fn){if(windowHandlers[type]===fn)delete windowHandlers[type];},
  setTimeout(fn,ms){const id=nextTimer++;timers.set(id,{due:now+ms,fn});return id;},
  clearTimeout(id){timers.delete(id);}
 };
 vm.runInNewContext(source,context);
 const flush=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
 return {
  ready:flush,isOpen:()=>outer.classList.contains('sf2-open'),
  manual:()=>trigger.handlers.click(),close:()=>close.handlers.click(),
  advance:ms=>{now+=ms;for(const [id,t] of [...timers])if(t.due<=now){timers.delete(id);t.fn();}},
  scroll:percent=>{context.scrollY=percent*10;windowHandlers.scroll?.();},
  exit:()=>documentHandlers.mouseout?.({relatedTarget:null,clientY:0}),
  storage,session,getConfig:()=>config,document,outer:()=>outer
 };
}
(async function(){
 const defaultOff=fixture({config:{enabled:false}});await defaultOff.ready();
 defaultOff.advance(60000);defaultOff.scroll(90);defaultOff.exit();
 assert.equal(defaultOff.isOpen(),false,'disabled campaign never opens automatically');
 defaultOff.manual();assert.equal(defaultOff.isOpen(),true,'disabled auto still supports manual enquiry');

 const timed=fixture();await timed.ready();
 timed.advance(7999);assert.equal(timed.isOpen(),false,'delay does not open early');
 timed.advance(1);assert.equal(timed.isOpen(),true,'time delay opens popup');
 timed.close();assert.equal(timed.isOpen(),false,'close works');
 timed.manual();assert.equal(timed.isOpen(),true,'manual open works after dismissal');

 const scroll=fixture({config:{trigger:'scroll',exit_intent:false}});await scroll.ready();
 scroll.scroll(30);assert.equal(scroll.isOpen(),false,'scroll below threshold');
 scroll.scroll(50);assert.equal(scroll.isOpen(),true,'scroll at threshold opens popup');

 const exit=fixture({config:{trigger:'exit',exit_intent:true}});await exit.ready();
 exit.advance(7999);exit.exit();assert.equal(exit.isOpen(),false,'exit intent has grace period');
 exit.advance(2);exit.exit();assert.equal(exit.isOpen(),true,'desktop exit triggers once');

 const mobile=fixture({config:{trigger:'exit'},mobile:true});await mobile.ready();
 mobile.advance(30000);mobile.exit();assert.equal(mobile.isOpen(),false,'exit does not hijack coarse pointer');

 const blocked=fixture({path:'/about',config:{include_paths:['/admissions'],trigger:'delay'}});await blocked.ready();
 blocked.advance(60000);assert.equal(blocked.isOpen(),false,'URL include targeting');
 const excluded=fixture({path:'/thank-you',config:{exclude_paths:['/thank-you']}});await excluded.ready();
 excluded.advance(60000);assert.equal(excluded.isOpen(),false,'URL exclude targeting');

 const outsideHours=fixture({config:{trigger:'delay',start_time:'16:00',end_time:'17:00'}});
 await outsideHours.ready();outsideHours.advance(8000);
 assert.equal(outsideHours.isOpen(),false,'daily IST campaign window blocks outside hours');
 const insideHours=fixture({config:{trigger:'delay',start_time:'15:00',end_time:'16:00'}});
 await insideHours.ready();insideHours.advance(8000);
 assert.equal(insideHours.isOpen(),true,'daily IST campaign window permits inside hours');
 const approval={primary_host:'primary.test',approved_urls:['https://school.test/admissions'],restrict_primary:false};
 const unapprovedLanding=fixture({path:'/other',landingPages:approval});await unapprovedLanding.ready();
 unapprovedLanding.advance(40000);unapprovedLanding.manual();
 assert.equal(unapprovedLanding.isOpen(),false,'unapproved secondary landing path blocks both automatic and manual popup');
 const approvedLanding=fixture({path:'/admissions',landingPages:approval});await approvedLanding.ready();
 approvedLanding.advance(8000);
 assert.equal(approvedLanding.isOpen(),true,'explicitly approved secondary landing path allows popup');
 const storage=new Map(),session=new Map();
 const first=fixture({storage,session});await first.ready();first.advance(8000);
 assert.equal(first.isOpen(),true);
 const second=fixture({storage,session});await second.ready();second.advance(60000);
 assert.equal(second.isOpen(),false,'7 day frequency suppresses repeat across page loads');
 const none=fixture({config:{school_codes:[]}});await none.ready();none.advance(60000);
 assert.equal(none.isOpen(),false,'campaign with no approved schools remains automatic-off');
 console.log('PASS: short popup manual compatibility, timer, scroll, exit, mobile, URL and frequency targeting');
})().catch(e=>{console.error(e);process.exitCode=1;});
