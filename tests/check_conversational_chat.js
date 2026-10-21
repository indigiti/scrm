/* Browser-free interactive regression of one-message-at-a-time admission intake. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const {webcrypto}=require('node:crypto');
const src=fs.readFileSync(__dirname+'/../public_html/static/admissions-chat.js','utf8');
assert.ok(!src.includes('sfac-register'),'bulk registration form must be absent');
assert.ok(src.includes('sfac-entry')&&src.includes('question('),'one active chat input');
const guid='3b8a718d-4521-4c31-8c48-5898524be892';
function el(){
 const e={
  children:[],listeners:{},textContent:'',value:'',hidden:false,disabled:false,
  append(...children){this.children.push(...children);},
  replaceChildren(...children){this.children=[...children];},
  focus(){this.focused=true;},
  setAttribute(name,v){this[name]=v;},
  addEventListener(type,handler){this.listeners[type]=handler;},
  click(){const fn=this.listeners.click;assert.ok(fn,'button click listener');return fn({target:this,preventDefault(){}});}
 };
 return e;
}
const cache={};
for(const selector of ['.sfac-panel','.sfac-history','.sfac-quick','.sfac-entry',
 '#sfac-reply','.sfac-send','.sfac-step','.sfac-status','.sfac-launch','.sfac-close'])cache[selector]=el();
// HTML hidden attributes are not parsed by the mock DOM.
cache['.sfac-panel'].hidden=true;
cache['.sfac-entry'].hidden=true;
const root=el();root.querySelector=s=>{if(!cache[s])throw Error('missing UI node '+s);return cache[s];};
const loader={src:'https://crm.example.test/scrm/static/admissions-chat.js?v=hash',
 getAttribute(key){return {'data-schoolflow-group':guid,'data-schoolflow-chat-css-version':'1234'}[key]||null;}};
const doc={
 currentScript:loader,
 body:{append(item){assert.equal(item,root);}},
 head:{append(){}},
 createElement(tag){return tag==='section'?root:el();}
};
const getUrl=url=>url.replace('https://crm.example.test/scrm/api/public/forms/'+guid,'');
const requests=[];
async function fetch(url,config){
 const route=getUrl(url);
 requests.push({route,method:config.method,body:config.body?JSON.parse(config.body):null,headers:config.headers});
 let result;
 if(route==='/chat-config')result={schools:[{code:'campus-1',name:'Campus Kalyani Nagar'}]};
 else if(route==='/challenge')result={question:'Solve: 4 + 7',token:'signed-math-token'};
 else if(route==='/chat-start'){
   assert.equal(config.method,'POST');
   assert.equal(config.body.includes('consent'),true);
   result={session:'signed-chat-session',quick_questions:['Which documents are required?']};
 }else if(route==='/chat')result={answer:'School will confirm the document checklist.'};
 else if(route==='/chat-followup')result={ok:true};
 else throw Error('Unexpected request '+route);
 return {ok:true,json:async()=>result};
}
const ctx={document:doc,window:{},URL,fetch,crypto:webcrypto,console};
vm.runInNewContext(src,ctx,{filename:'admissions-chat.js'});
const $=s=>cache[s],submit=v=>{
 if(v!==undefined)$('#sfac-reply').value=v;
 const handler=$('.sfac-entry').listeners.submit;
 assert.ok(handler,'chat send handler installed');
 return handler({preventDefault(){}});
};
const choice=(label)=>{
 const btn=$('.sfac-quick').children.find(x=>x.textContent.includes(label));
 assert.ok(btn,'missing choice '+label+' actual '+$('.sfac-quick').children.map(x=>x.textContent));
 return btn.click();
};
const tick=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 assert.ok(!root.innerHTML.includes('sfac-register'), 'no visible bulk lead form');
 assert.ok(root.innerHTML.includes('sfac-history'),'conversation is main interface');
 $('.sfac-launch').click();await tick();
 assert.equal(requests.filter(x=>x.route==='/chat').length,0,'AI must not start at open');
 choice('Kalyani Nagar');
 assert.equal($('.sfac-step').textContent,'Enquiry details · Step 2 of 9');
 submit('Demo Guardian');submit('9876543210');submit('Demo Student');
 submit('Nursery');
 choice('Skip this question');choice('Skip this question');
 assert.equal(requests.filter(x=>x.route==='/chat-start').length,0,'no lead before consent');
 choice('Not now');
 assert.equal(requests.filter(x=>x.route==='/chat-start').length,0,'declined consent blocks lead');
 choice('I agree');await tick();
 assert.ok(requests.some(x=>x.route==='/challenge'),'math verification fetched');
 submit('11');await tick();
 const lead=requests.filter(x=>x.route==='/chat-start');
 assert.equal(lead.length,1,'create exactly one lead');
 assert.equal(lead[0].body.parent_name,'Demo Guardian');
 assert.equal(lead[0].body.phone,'9876543210');
 assert.equal(lead[0].body.student_name,'Demo Student');
 assert.equal(lead[0].body.grade,'Nursery');
 assert.equal(lead[0].body.email,'');
 assert.equal(lead[0].body.city,'');
 assert.equal(lead[0].body.consent,true);
 assert.equal(lead[0].body.captcha_answer,'11');
 assert.ok(lead[0].headers['Idempotency-Key'],'idempotency key');
 assert.equal($('.sfac-step').textContent,'Enquiry registered · Ask anything');
 assert.equal(requests.filter(x=>x.route==='/chat').length,0,'Q&A has not started automatically');
 choice('Which documents');
 await tick();
 const questions=requests.filter(x=>x.route==='/chat');
 assert.equal(questions.length,1,'quick question submits once');
 assert.equal(questions[0].body.session,'signed-chat-session');
 assert.equal(questions[0].body.question,'Which documents are required?');
 assert.ok(!JSON.stringify(questions[0].body).includes('9876543210'),'phone excluded from AI payload');
 choice('Request admissions callback');await tick();
 assert.equal(requests.filter(x=>x.route==='/chat-followup').length,1);
 console.log('PASS: one-field-per-turn intake, decline/consent, secure lead, CAPTCHA, FAQ chips, callback');
})().catch(error=>{console.error(error);process.exitCode=1;});
