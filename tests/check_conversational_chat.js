/* Node DOM-mock regression: parents can ask before giving any contact details. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const {webcrypto}=require('node:crypto');
const source=fs.readFileSync(__dirname+'/../public_html/static/admissions-chat.js','utf8');
const guid='3b8a718d-4521-4c31-8c48-5898524be892';
function element(){
 return {children:[],listeners:{},value:'',textContent:'',hidden:false,disabled:false,
   append(...children){this.children.push(...children);},
   replaceChildren(...children){this.children=[...children];},
   focus(){this.focused=true;},setAttribute(n,v){this[n]=v;},
   getAttribute(){return null;},
   addEventListener(type,fn){this.listeners[type]=fn;},
   click(){assert.ok(this.listeners.click,'control is active');return this.listeners.click({preventDefault(){}});}
 };
}
const cache={};
for(const s of ['.sfac-panel','.sfac-history','.sfac-quick','.sfac-entry','#sfac-reply',
'.sfac-send','.sfac-step','.sfac-status','.sfac-launch','.sfac-close'])cache[s]=element();
cache['.sfac-panel'].hidden=true;
const widget=element();widget.querySelector=k=>cache[k];
const host=element();
host.attachShadow=({mode})=>{assert.equal(mode,'open');return {append(item){if(item===widget)host.widget=widget;}}};
const loader={src:'https://crm.example.test/scrm/static/admissions-chat.js?v=hash',
 getAttribute:key=>({'data-schoolflow-group':guid,'data-schoolflow-chat-css-version':'123'}[key]||null)};
const doc={currentScript:loader,head:{append(){}},body:{append(e){assert.equal(e,host);}},
 createElement:type=>type==='section'?widget:type==='div'?host:element()};
const requests=[],store=new Map(),anon=new Map();
const localStorage={getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)};
const sessionStorage={getItem:k=>anon.get(k)||null,setItem:(k,v)=>anon.set(k,v),removeItem:k=>anon.delete(k)};
async function fetch(url,options){
 const route=url.replace('https://crm.example.test/scrm/api/public/forms/'+guid,'');
 const body=options.body?JSON.parse(options.body):null;
 requests.push({route,body,headers:options.headers,method:options.method});
 let result;
 switch(route){
 case '/chat-config':result={schools:[{code:'campus-1',name:'Campus 1'}]};break;
 case '/counsel':result={conversation_token:'a'.repeat(43),
  school_code:'campus-1',campus_name:'Campus 1',profile:{grade:'2',interests:['fees']},
  qualified:'Considering admission',suggest_contact:body.message.includes('visit'),
  reply:body.message.includes('visit')?'We can request a Saturday visit. Would you like help?':'**The approved fee**\n- **Class II:** ₹116,725\n- **Confirmation:** Ask admissions'};break;
 case '/challenge':result={question:'Solve: 4 + 7',token:'signed-challenge'};break;
 case '/counsel-lead':result={success:true,lead_created:true,session:'signed-chat-session',school_code:'campus-1'};break;
 case '/chat-remember':result={remember_token:'b'.repeat(43),remember_expires_at:Math.floor(Date.now()/1000)+2592000};break;
 default:throw Error('Unexpected route '+route);
 }
 return {ok:true,json:async()=>result};
}
const context={document:doc,window:{},URL,crypto:webcrypto,fetch,localStorage,sessionStorage,console};
vm.runInNewContext(source,context,{filename:'admissions-chat.js'});
const $=s=>cache[s],tick=()=>new Promise(r=>setImmediate(r));
async function submit(text){
 $('#sfac-reply').value=text;
 const fn=$('.sfac-entry').listeners.submit;
 assert.ok(fn,'message send registered');fn({preventDefault(){}});
 await tick();await tick();
}
function choose(label){
 const btn=$('.sfac-quick').children.find(b=>b.textContent.includes(label));
 assert.ok(btn,'missing choice '+label+': '+$('.sfac-quick').children.map(x=>x.textContent));
 return btn.click();
}
(async()=>{
 assert.ok(host.widget===widget,'Shadow DOM isolation');
 $('.sfac-launch').click();await tick();
 assert.ok(widget.innerHTML.includes('Admission Counsellor'));
 assert.equal(requests.filter(x=>x.route==='/counsel-lead').length,0,'opening never registers lead');
 assert.equal($('.sfac-entry').hidden,false,'parents can immediately ask a question');
 await submit('Grade 2 fees?');
 const bubbleTexts=$('.sfac-history').children.map(x=>x.textContent);
 assert.ok(bubbleTexts.some(x=>x.includes('• Class II: ₹116,725')),'list shown with readable bullets');
 assert.ok(!bubbleTexts.some(x=>x.includes('**')),'Markdown bold markers are removed from UI');
 assert.equal(requests.filter(x=>x.route==='/counsel').length,1,'first question answered before details');
 assert.equal(requests.filter(x=>x.route==='/counsel-lead').length,0,'no covert lead');
 // First useful answer introduces the option to share a name, but does not
 // prevent more questions or create a lead.
 choose('Tell you my name');await tick();
 assert.equal($('.sfac-quick').children.length,0,'no Skip name control appears when parent chooses to give a name');
 await submit('R');
 assert.ok($('.sfac-status').textContent.includes('Please enter your name'),'invalid name remains on the name step');
 await submit('Rahul');
 assert.equal(requests.filter(x=>x.route==='/counsel-lead').length,0,'sharing a name is not CRM contact consent');
 choose('Continue asking');await tick();
 await submit('Can we visit Saturday?');
 assert.equal(requests.filter(x=>x.route==='/counsel').length,2);
 choose('Request a visit');await tick();
 // The parent already volunteered a name, so never ask for it twice.
 await submit('9876543210');
 assert.equal(requests.filter(x=>x.route==='/counsel-lead').length,0,'no lead without consent');
 choose('Yes, I agree');await tick();await tick();
 await submit('11');
 const leads=requests.filter(x=>x.route==='/counsel-lead');
 assert.equal(leads.length,1);
 assert.equal(leads[0].body.phone,'9876543210');
 assert.equal(leads[0].body.parent_name,'Rahul');
 assert.equal(leads[0].body.consent,true);
 assert.equal(leads[0].body.captcha_answer,'11');
 assert.ok(leads[0].headers['Idempotency-Key'],'idempotency on conversion');
 assert.equal(requests.filter(x=>x.route==='/chat-remember').length,0,'remember disabled until opt-in');
 choose('Remember this browser');await tick();await tick();
 const recorded=[...store.values()].join('');
 assert.ok(recorded.includes('b'.repeat(43)),'explicitly remembered token');
 assert.ok(!recorded.includes('9876543210'),'phone never persisted to browser storage');
 assert.ok([...anon.values()].join('').includes('a'.repeat(43)),'anonymous session uses tab-scoped storage');
 console.log('PASS: answer-first conversation, optional visit lead, consent, math, no contact storage, explicit return');
})().catch(e=>{console.error(e);process.exitCode=1;});
