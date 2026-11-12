'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public_html/static/management.js','utf8');
const context={window:{},URLSearchParams,console};
vm.runInNewContext(source,context,{filename:'management.js'});
const app=context.window.SFManagementUI;
assert(app&&typeof app.view==='function'&&typeof app.load==='function');
const requests=[];
const fake={
 filters:{period:'month',from:'2026-11-01',to:'2026-11-13'},
 summary:{enquiries:2,confirmed:1,hot:1,warm:0,cold:1,unrated:0,disqualified:0,
 overdue:1,sla_breached:0,unassigned:0,calls:2,connected_calls:1,unique_called_leads:1},
 schools:[{id:1,name:'Campus <Test>'}],
 school_performance:[{id:1,name:'Campus <Test>',enquiries:2,confirmed:1,overdue:1,sla_breached:0}],
 trend:[{date:'2026-11-13',count:2}],
 call_outcomes:[{name:'No Answer',count:1},{name:'Answered',count:1}],
 sources:[{name:'manual',count:2}],forms:[],campaigns:[],landing_pages:[],stages:[],
 disqualification_reasons:[],staff:[],attention:[],
 recent_leads:[{id:1,student:'Child <script>alert(1)</script>',school:'Campus <Test>',source_name:'Website',status:'New'}]
};
const api=async path=>{
 requests.push(path);
 if(path.startsWith('/api/management/overview'))return fake;
 if(path.startsWith('/api/management/leads/'))return {...fake.recent_leads[0],history:[],phone_masked:'•••• 4321'};
 if(path.startsWith('/api/management/leads'))return {rows:fake.recent_leads,total:1,page:1,pages:1};
 throw Error('Unknown endpoint');
};
(async()=>{
 let renders=0;
 await app.load(api,()=>{renders++;});
 assert(renders===1,'dashboard rendered after loading');
 assert(requests[0].includes('period=month'),'default monthly period used');
 const initial=app.view();
 assert(initial.includes('Admissions Intelligence'));
 assert(initial.includes('Campus &lt;Test&gt;'),'school names escaped');
 assert(!initial.includes('<script>alert(1)</script>'),'student names escaped');
 await app.click('management-tab',{tab:'Leads'},api,()=>{renders++;});
 assert(app.view().includes('Read-only lead register'),'lead register loads');
 await app.click('management-lead',{id:'1'},api,()=>{renders++;});
 assert(app.view().includes('Read-only enquiry detail'),'lead detail loads');
 assert(!app.view().includes('name="status"'),'no editable status controls');
 await app.click('management-period',{period:'custom'},api,()=>{renders++;});
 assert(requests.some(q=>q.includes('period=custom')&&q.includes('from=')),'custom period has both dates');
 await app.submit({school_id:'1',period:'month'},api,()=>{renders++;});
 assert(requests.some(q=>q.includes('school_id=1')),'school filter is sent to server');
 console.log('PASS: Management UI navigation, escaping, period filters and read-only detail controls');
})().catch(err=>{console.error(err);process.exitCode=1;});
