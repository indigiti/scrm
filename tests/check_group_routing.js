const fs=require('fs'),vm=require('vm'),assert=require('assert');
const js=fs.readFileSync(__dirname+'/../public_html/static/app.js','utf8');
const handlers={},copied=[];let html='';
const root={addEventListener:(k,fn)=>handlers[k]=fn,querySelector:()=>null};
Object.defineProperty(root,'innerHTML',{get:()=>html,set:v=>html=v});
const schools=[{id:1,name:'ABC Wakad',role:'Admin'},{id:2,name:'ABC Baner',role:'Admin'}];
const routes=[{id:1,name:'ABC Group Website',domain:'group.test',guid:'a8b5de5d-ac84-4169-9347-21144cedd39c',enabled:true,schools:[{code:'campus-1',name:'ABC Wakad',school_id:1},{code:'campus-2',name:'ABC Baner',school_id:2}]}];
const data={
 '/api/setup/status':{needs_setup:false},'/api/me':{user:{id:1,name:'Group admin'},schools},
 '/api/schools/1/leads':[{id:7,guid:'12345678-1234-4123-8123-123456789012',name:'',guardian:'Example Parent',phone:'9876543210',
  grade:'Nursery',status:'New',temperature:'Unrated',source_name:'ABC Group Website (Group website)',
  source_type:'group_website',form_type:'inline',form_variant:'short',landing_page_version:'v2',
  utm_source:'google',utm_campaign:'nursery-pune',site_id:1,created_at:'2026-10-30T10:00:00Z'}],'/api/schools/1/sites':[],'/api/schools/1/members':[],
 '/api/schools/1/demo-data':{leads:0,sites:0,has_demo_data:false},'/api/schools/1/visits':[],'/api/schools/1/tasks':[],'/api/schools/1/automation':{enabled:false,email:false,whatsapp:false,task_kinds:['Follow-up','Campus Visit'],max_daily:10},'/api/schools/1/automation/queue':[],'/api/schools/1/work-queue?limit=100':{items:[],summary:{},total:0},'/api/schools/1/public-chat/quality':{topics:[],groups_needing_review:0,observed_questions:0},'/api/schools/1/followup-policy':{retry_days:[1,2,4]},'/api/schools/1/seat-plans':[],'/api/schools/1/response-sla':{first_contact_minutes:120},'/api/schools/1/applications':[],
 '/api/schools/1/families':[],'/api/schools/1/templates':[],'/api/schools/1/analytics':{},'/api/schools/1/assignment':{mode:'least_loaded'},
 '/api/schools/1/enquiry-panels':{sections:{
 attribution:{visible:true,expanded:false},communication:{visible:true,expanded:true},
 family:{visible:true,expanded:false},application:{visible:true,expanded:false},
 documents:{visible:true,expanded:false},consent:{visible:true,expanded:false},
 audit:{visible:true,expanded:false}},audit_admin_only:true},
 '/api/schools/1/communications':{email:{provider:'off',connected:false},whatsapp:{provider:'off',connected:false}},
 '/api/schools/overview':schools.map(s=>({id:s.id,name:s.name,leads:0,new_leads:0,unassigned:0,sites:[],assignment_mode:'least_loaded'})),
 '/api/schools/1/members/link-candidates':[{id:12,name:'Admissions Colleague',email:'admissions@example.org',source_schools:['ABC Baner'],can_link_counsellor:false,can_link_admissions:true}],
 '/api/schools/1/campaign-report':{totals:{enquiries:1,applied_or_later:0,confirmed_or_enrolled:0,enrolled:0,potential_duplicates:0,hot_or_warm:0},
  as_of:'2026-11-03T07:00:00+00:00',by:{utm_campaign:[{name:'nursery-pune',enquiries:1,applied:0,confirmed:0,enrolled:0}],utm_source:[{name:'google',enquiries:1,applied:0,confirmed:0,enrolled:0}]},
  filters:{},school_id:1},
 '/api/group-sites?school_id=1':routes,
 '/api/group-sites?school_id=2':routes,
};
const ctx={document:{getElementById:id=>id==='root'?root:null,addEventListener:()=>{},body:{append:()=>{}},createElement:()=>({style:{},setAttribute:()=>{},remove:()=>{}})},
 sessionStorage:{getItem:k=>k==='schoolflow_session'?'test-session':null,setItem:()=>{},removeItem:()=>{}},
 fetch:async path=>{const route=path.replace('/scrm','');if(!(route in data))throw Error('Unexpected API '+route);return {status:200,ok:true,json:async()=>data[route]};},
 setTimeout,Date,Intl,console,URL,Blob,confirm:()=>true,prompt:()=>null,navigator:{clipboard:{writeText:async text=>{copied.push(text);}}},URLSearchParams,window:{location:{href:'',origin:'https://schoolflow.test'},open:()=>{}}};
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
 handlers.click({target:{closest:()=>({dataset:{action:'nav',page:'Enquiries'}})}});
 await sleep(120);
 assert.match(html,/sourceTypeFilter/);assert.match(html,/formTypeFilter/);
 assert.match(html,/group website/);assert.match(html,/inline/);assert.match(html,/v2/);
 handlers.click({target:{closest:()=>({dataset:{action:'nav',page:'Reports'}})}});
 await sleep(120);
 assert.match(html,/Campaign performance/);
 assert.match(html,/By source/);assert.match(html,/By UTM campaign/);
 assert.match(html,/nursery-pune/);

 handlers.click({target:{closest:()=>({dataset:{action:'toggle-nav-group',group:'LEAD CAPTURE'}})}});
 assert.match(html,/data-group="LEAD CAPTURE" aria-expanded="true"/,'lead capture submenu expanded');
 handlers.click({target:{closest:()=>({dataset:{action:'nav',page:'Landing Pages'}})}});
 await sleep(120);
 assert.match(html,/Approved additional URLs/);
 assert.match(html,/Manage approved URLs/);
 handlers.click({target:{closest:()=>({dataset:{action:'edit-landing-pages',id:'1'}})}});
 assert.match(html,/landingPagesForm/);
 assert.match(html,/Approved landing pages/);
 assert.match(html,/restrict_primary/);
 handlers.click({target:{closest:()=>({dataset:{action:'close-modal'}})}});
 handlers.click({target:{closest:()=>({dataset:{action:'edit-submission-settings',id:'1'}})}});
 assert.match(html,/Redirect short inline form after success/,'inline LP thank-you redirect configurable');
 assert.match(html,/Popup forms never redirect/,'popup submissions always confirm without navigation');
 assert.doesNotMatch(html,/name="popup_redirect"/,'no popup redirect switch can mislead administrators');
 assert.doesNotMatch(html,/name="detailed_redirect"/,'no legacy detailed redirect switch shown');
 handlers.click({target:{closest:()=>({dataset:{action:'close-modal'}})}});
 handlers.click({target:{closest:()=>({dataset:{action:'new-page-popup',id:'1'}})}});
 assert.match(html,/pagePopupForm/,'page popup editor opens for selected school group');
 assert.match(html,/Placement/,'popup editor supports whole-website and optional exact-page scope');
 assert.match(html,/Enable popup after installation/,'popup editor defaults disabled until installation');
 assert.match(html,/Entire registered school website/,'website scope does not require LP approval');
 assert.match(html,/School\(s\) receiving this popup/,'selected-school control is explicit');
 handlers.click({target:{closest:()=>({dataset:{action:'close-modal'}})}});
 handlers.click({target:{closest:()=>({dataset:{action:'nav',page:'Popup Campaigns'}})}});
 await sleep(120);
 assert.match(html,/Configure campaign/);
 assert.match(html,/School website popups/);
 handlers.click({target:{closest:()=>({dataset:{action:'new-website-popup'}})}});
 assert.match(html,/websitePopupSourceForm/,'website picker replaces landing-page approval');
 assert.match(html,/ABC Group Website/,'connected group site is offered as popup source');
 handlers.click({target:{closest:()=>({dataset:{action:'close-modal'}})}});
 handlers.click({target:{closest:()=>({dataset:{action:'edit-popup-settings',id:'1'}})}});
 assert.match(html,/Website embed codes/,'embed guide appears inside popup settings');
 assert.match(html,/Short popup/);
 assert.match(html,/Short inline form/);
 assert.match(html,/Detailed popup/);
 assert.match(html,/Thank-you conversion tracking/);
 assert.match(html,/data-schoolflow-mode=&quot;popup&quot;/,'popup has explicit popup mode');
 assert.match(html,/data-schoolflow-mode=&quot;inline&quot;/,'inline has explicit inline mode');
 assert.match(html,/data-schoolflow-target/,'inline includes target element');
 assert.match(html,/schoolflow-conversion\.js/,'thank-you conversion snippet included');
 assert.match(html,/a8b5de5d-ac84-4169-9347-21144cedd39c/,'copy code uses current group GUID');
 assert.match(html,/type="button" data-id="1" aria-label="Copy Short popup/,'copy buttons cannot submit settings form');
 const actions=[
  ['copy-group-short-popup','data-schoolflow-mode="popup"'],
  ['copy-group-inline','data-schoolflow-mode="inline"'],
  ['copy-group-embed','<script defer src="https://schoolflow.test/scrm/embed/'],
  ['copy-group-conversion','schoolflow-conversion.js']
 ];
 for(const [action,needle] of actions){
  handlers.click({target:{closest:()=>({dataset:{action,id:'1'}})}});
  assert.ok(copied.at(-1).includes(needle),'correct snippet copied for '+action);
 }
 assert.equal(copied.length,4,'four copy actions are independent');
 assert.ok(copied[1].startsWith('<div id="schoolflow-admissions"></div>'),'inline includes container and script');
 assert.ok(!copied[2].includes('data-schoolflow-form="short"'),'bare legacy script remains detailed popup');
 handlers.click({target:{closest:()=>({dataset:{action:'close-modal'}})}});
 handlers.click({target:{closest:()=>({dataset:{action:'nav',page:'Team'}})}});
 await sleep(120);
 assert.match(html,/Assign existing staff/,'team offers school-scoped staff assignment');
 assert.match(html,/assigned enquiries from their home school/,'counselor isolation visible in team screen');
 handlers.click({target:{closest:()=>({dataset:{action:'assign-existing-staff'}})}});
 await sleep(120);
 assert.match(html,/staffSchoolLinkForm/,'link existing staff modal opens');
 assert.match(html,/Admissions Colleague/,'candidate is listed without global user search');
 assert.match(html,/Counsellors may have only one active school assignment/);
  console.log('PASS: Group Websites management, legacy popup and additional short inline\/popup snippets');
})().catch(e=>{console.error(e);process.exitCode=1;});
