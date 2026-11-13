const fs=require('fs'),vm=require('vm'),assert=require('assert');
const js=fs.readFileSync(__dirname+'/../public_html/static/app.js','utf8');
const handlers={};let html='';
const lead={id:1,school_id:1,site_id:null,name:'Test Student',guardian:'Test Parent',phone:'+919999999876',normalized_phone:'919999999876',grade:'Grade 3',status:'New',assigned_to:1,assigned_name:'Demo Admin',source_name:'Manual entry',followup_date:'',email:'parent@example.org',created_at:'2026-10-13T04:00:00+00:00',consent:1,last_contact_at:'',last_call_outcome:''};
const root={addEventListener:(kind,fn)=>handlers[kind]=fn,querySelector:()=>null};Object.defineProperty(root,'innerHTML',{get:()=>html,set:v=>html=v});
const demo={has_demo_data:false,leads:0,sites:0};
const data={
'/api/setup/status':{needs_setup:false},'/api/me':{user:{id:1,name:'Demo Admin',email:'a@example.org'},schools:[{id:1,name:'Test School',role:'Admin'}]},
'/api/schools/1/leads':[lead],'/api/schools/1/sites':[],'/api/schools/1/members':[{id:1,name:'Demo Admin',role:'Admin',email:'a@example.org',enabled:true},{id:2,name:'Test Counsellor',role:'Counsellor',email:'c@example.org',enabled:true}],
'/api/schools/1/demo-data':demo,'/api/schools/1/visits':[],'/api/schools/1/tasks':[],'/api/schools/1/automation':{enabled:false,email:false,whatsapp:false,task_kinds:['Follow-up','Campus Visit'],max_daily:10},'/api/schools/1/automation/queue':[],'/api/schools/1/work-queue?limit=100':{items:[],summary:{},total:0},'/api/schools/1/public-chat/quality':{topics:[],groups_needing_review:0,observed_questions:0},'/api/schools/1/followup-policy':{retry_days:[1,2,4]},'/api/schools/1/seat-plans':[],'/api/schools/1/response-sla':{first_contact_minutes:120},'/api/schools/1/applications':[],'/api/schools/1/families':[{guardian:lead.guardian,phone:lead.phone,email:lead.email,count:1,children:[{id:1,name:lead.name,grade:lead.grade,status:'New'}]}],
'/api/schools/1/templates':[{id:0,title:'Admissions follow-up',subject:'Hello {parent}',body:'Hi {parent}'}],
'/api/schools/1/analytics':{total:1,first_contact_pending:1,followups_due:0,conversion_rate:0,by_staff:{1:1}},
'/api/schools/1/assignment':{mode:'least_loaded'},
 '/api/schools/1/enquiry-panels':{sections:{
 attribution:{visible:true,expanded:false},communication:{visible:true,expanded:true},
 family:{visible:true,expanded:false},application:{visible:true,expanded:false},
 documents:{visible:true,expanded:false},consent:{visible:true,expanded:false},
 audit:{visible:true,expanded:false}},audit_admin_only:true},
'/api/schools/1/communications':{email:{provider:'off',connected:false},whatsapp:{provider:'off',connected:false}},
'/api/schools/1/leads/1':{lead,family:{guardian:lead.guardian,phone:lead.phone,email:lead.email,count:1,children:[{id:1,name:lead.name,grade:lead.grade,status:'New'}]},calls:[],visits:[],activities:[],audit:{events:[]}},
'/api/schools/1/admission-register?view=enquiries&page=1&per_page=50':{school_id:1,school_name:'Test School',total:1,page:1,per_page:50,pages:1,has_more:false,scope:'school',as_of:'2026-11-03T10:00:00Z',staff:[{id:1,name:'Demo Admin'}],summary:{enquiries:1,contacted:0,followups_due:0,unassigned:0},rows:[{id:1,student:'Test Student',guardian:'Test Parent',email:'parent@example.org',phone:'09999999876',city:'Pune',state:'Maharashtra',grade:'III',campus:'Test School',assigned_name:'Demo Admin',last_remark:'Called parent today',last_remark_at:'2026-11-03T10:00:00Z',last_call_outcome:'Answered',followup_date:'',status:'New',timestamp:'2026-11-03T08:00:00Z'}]},
'/api/schools/1/admission-register?view=history&page=1&per_page=50':{school_id:1,school_name:'Test School',total:1,page:1,per_page:50,pages:1,has_more:false,scope:'school',as_of:'2026-11-03T10:00:00Z',staff:[{id:1,name:'Demo Admin'}],summary:{enquiries:1,contacted:0,followups_due:0,unassigned:0},rows:[{lead_id:1,student:'Test Student',phone:'09999999876',grade:'III',type:'Call',outcome:'Answered',note:'Called parent today',actor_name:'Demo Admin',followup_date:'',at:'2026-11-03T10:00:00Z'}]},
'/api/schools/1/admission-register?view=performance&page=1&per_page=50':{school_id:1,school_name:'Test School',total:1,page:1,per_page:50,pages:1,has_more:false,scope:'school',as_of:'2026-11-03T10:00:00Z',staff:[{id:1,name:'Demo Admin'}],summary:{enquiries:1,contacted:0,followups_due:0,unassigned:0},rows:[{name:'Demo Admin',assigned:1,contacted:1,call_attempts:1,followups_due:0,enrolled:0}]},
'/api/schools/1/leads/1/view':{ok:true}
};
const ctx={document:{getElementById:(id)=>id==='root'?root:null,addEventListener:()=>{},createElement:()=>({style:{},setAttribute(){},remove(){}}),body:{append:()=>{}}},sessionStorage:{getItem:k=>k==='schoolflow_session'?'test-session':null,setItem:()=>{},removeItem:()=>{}},
fetch:async(path)=>({status:200,ok:true,json:async()=>{if(!(path.replace('/scrm','') in data))throw Error('unexpected path '+path);return data[path.replace('/scrm','')];}}),
setTimeout,Date,Intl,console,URL,URLSearchParams,Blob,confirm:()=>true,prompt:()=>null,navigator:{},window:{location:{href:''},open:()=>{}},};
vm.runInNewContext(js,ctx);
function sleep(ms){return new Promise(r=>setTimeout(r,ms));}
(async()=>{
 await sleep(150);assert.match(html,/First contact pending/);assert.match(html,/School Visits/);assert.match(html,/Families/);
 const click=(action,page='',id='')=>handlers.click({target:{closest:()=>({dataset:{action,page,id},getAttribute:()=>null})}});
 click('nav','My Work');assert.match(html,/Priority actions/);assert.match(html,/Pending first conversations/);
 click('nav','Pipeline');assert.strictEqual((html.match(/class="pipeline-column phase-column"/g)||[]).length,5);assert.match(html,/Exceptions/);
 assert.match(html,/aria-label="SchoolFlow main navigation"/);
 click('nav','Team');assert.match(html,/Reset password/);assert.match(html,/Disable/);assert.match(html,/Change my password/);
 click('nav','Seat Planning');assert.match(html,/Grade and academic-year seats/);assert.match(html,/First-contact response SLA/);
 click('nav','Import Enquiries');assert.match(html,/Spreadsheet import/);assert.match(html,/sfImportFile/);assert.match(html,/Preview import/);
 click('nav','School Visits');assert.match(html,/School visit calendar/);
 click('nav','Families');assert.match(html,/Parent &amp; sibling profiles/);
 click('nav','Email Templates');assert.match(html,/Email templates/);
 click('nav','Enquiries');assert.match(html,/assignedFilter/);assert.match(html,/contactFilter/);
 click('nav','Admission Register');await sleep(150);
 assert.match(html,/Admission Enquiry Register/);
 assert.match(html,/Latest Calling Remark/);
 assert.match(html,/Called parent today/);
 assert.match(html,/09999999876/);
 handlers.click({target:{closest:()=>({dataset:{action:'register-view',view:'history'}})}});
 await sleep(150);assert.match(html,/Calling &amp; Follow-up History|Calling & Follow-up History/);assert.match(html,/Recorded By/);
 handlers.click({target:{closest:()=>({dataset:{action:'register-view',view:'performance'}})}});
 await sleep(150);assert.match(html,/Counsellor Performance/);assert.match(html,/Calls recorded/);
 click('nav','Enquiries');await sleep(30);
 click('nav','Enquiry Interface');await sleep(30);
 assert.match(html,/Communication/);assert.match(html,/Drawer tabs/);assert.match(html,/sf-settings-panel-settings/);
 assert.match(html,/name="action_call"/);assert.match(html,/name="action_whatsapp"/);
 assert.match(html,/name="action_email"/);assert.match(html,/name="action_api_email"/);
 assert.match(html,/name="tab_followup"/);assert.match(html,/name="tab_more"/);
 assert.match(html,/name="admin_shortcut"/);
 assert.match(html,/name="followup_disposition"/);
 click('nav','Enquiries');await sleep(30);
 click('view-lead','',1);await sleep(150);
 assert.match(html,/sf-detail-tab-followup/);
 assert.match(html,/sf-detail-tab-admission/);
 assert.match(html,/sf-detail-tab-family/);
 assert.match(html,/sf-detail-tab-history/);
 assert.match(html,/sf-detail-tab-more/);
 assert.match(html,/Configure school enquiry interface/);
 assert.match(html,/Call outcome/);
 assert.doesNotMatch(html,/Lead temperature \/ disposition|id="sfDisqualDialog"/);
 assert.match(html,/Save follow-up/);
 // Simulate the next settings fetch after an Admin enables the feature.
 data['/api/schools/1/enquiry-panels'].preferences={followup_disposition:true};
 await vm.runInNewContext('reload()',ctx);
 assert.match(html,/Lead temperature \/ disposition/);
 assert.match(html,/id="sfDisqualDialog"/);
 assert.match(html,/Disqualification reason/);
 assert.doesNotMatch(html,/href="#sf-contact"/);
 assert.match(html,/Save follow-up/);assert.match(html,/Parent & sibling profile/);assert.match(html,/Audit log/);assert.match(html,/aria-modal="true"/);assert.match(html,/Next action/);assert.match(html,/emailTemplateChoice/);
 console.log('PASS: JavaScript renders School Visits, Families, Email Templates, filters, call manager, communications and audit drawer');
})().catch(e=>{console.error(e);process.exitCode=1;});
