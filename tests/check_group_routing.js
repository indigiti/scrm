const fs=require('fs'),vm=require('vm'),assert=require('assert');
const js=fs.readFileSync(__dirname+'/../public_html/static/app.js','utf8');
const handlers={};let html='';
const root={addEventListener:(k,fn)=>handlers[k]=fn,querySelector:()=>null};
Object.defineProperty(root,'innerHTML',{get:()=>html,set:v=>html=v});
const schools=[{id:1,name:'ABC Wakad',role:'Admin'},{id:2,name:'ABC Baner',role:'Admin'}];
const routes=[{id:1,name:'ABC Group Website',domain:'group.test',enabled:true,schools:[{code:'campus-1',name:'ABC Wakad',school_id:1},{code:'campus-2',name:'ABC Baner',school_id:2}]}];
const data={
 '/api/setup/status':{needs_setup:false},'/api/me':{user:{id:1,name:'Group admin'},schools},
 '/api/schools/1/leads':[],'/api/schools/1/sites':[],'/api/schools/1/members':[],
 '/api/schools/1/demo-data':{leads:0,sites:0,has_demo_data:false},'/api/schools/1/visits':[],'/api/schools/1/tasks':[],'/api/schools/1/automation':{enabled:false,email:false,whatsapp:false,task_kinds:['Follow-up','Campus Visit'],max_daily:10},'/api/schools/1/automation/queue':[],'/api/schools/1/work-queue?limit=100':{items:[],summary:{},total:0},'/api/schools/1/public-chat/quality':{topics:[],groups_needing_review:0,observed_questions:0},'/api/schools/1/followup-policy':{retry_days:[1,2,4]},'/api/schools/1/seat-plans':[],'/api/schools/1/response-sla':{first_contact_minutes:120},'/api/schools/1/applications':[],
 '/api/schools/1/families':[],'/api/schools/1/templates':[],'/api/schools/1/analytics':{},'/api/schools/1/assignment':{mode:'least_loaded'},
 '/api/schools/1/communications':{email:{provider:'off',connected:false},whatsapp:{provider:'off',connected:false}},
 '/api/schools/overview':schools.map(s=>({id:s.id,name:s.name,leads:0,new_leads:0,unassigned:0,sites:[],assignment_mode:'least_loaded'})),
 '/api/group-sites':routes,
};
const ctx={document:{getElementById:()=>root,addEventListener:()=>{},body:{append:()=>{}}},
 sessionStorage:{getItem:k=>k==='schoolflow_session'?'test-session':null,setItem:()=>{},removeItem:()=>{}},
 fetch:async path=>{const route=path.replace('/scrm','');if(!(route in data))throw Error('Unexpected API '+route);return {status:200,ok:true,json:async()=>data[route]};},
 setTimeout,Date,Intl,console,URL,Blob,confirm:()=>true,prompt:()=>null,navigator:{clipboard:{writeText:async()=>{}}},window:{location:{href:'',origin:'https://schoolflow.test'},open:()=>{}}};
vm.runInNewContext(js,ctx);
function sleep(ms){return new Promise(r=>setTimeout(r,ms));}
(async()=>{
 await sleep(120);
 handlers.click({target:{closest:()=>({dataset:{action:'nav',page:'Schools & Routing'}})}});
 await sleep(120);
 assert.match(html,/Group websites/);assert.match(html,/ABC Group Website/);assert.match(html,/ABC Wakad/);assert.match(html,/campus-2/);
 handlers.click({target:{closest:()=>({dataset:{action:'new-group'}})}});
 assert.match(html,/groupSiteForm/);assert.match(html,/Schools served by the group website/);
 assert.match(html,/ABC Baner/);
 assert.match(html,/Copy head script/);
 assert.match(html,/Copy inline form/);
 assert.match(html,/Copy compact popup/);
 assert.match(html,/data-schoolflow-form/);
 assert.match(html,/schoolflow-admissions/);
 console.log('PASS: Group Websites management, legacy popup and additional short inline\/popup snippets');
})().catch(e=>{console.error(e);process.exitCode=1;});
