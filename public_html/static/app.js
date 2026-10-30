(()=>{
'use strict';
const root=document.getElementById('root');
const APP_BASE=(window.SCHOOLFLOW_BASE_PATH||'/scrm').replace(/\/$/,'');
const PAGES=['Dashboard','My Work','Enquiries','Chatbot Leads','Pipeline','Follow-ups','Tasks','Websites & Forms','Schools & Routing','School Visits','Families','Email Templates','Communications','Automations','Team','Reports','Import Enquiries','Seat Planning','Admissions AI'];
const ICONS={'Dashboard':'▦','My Work':'☑','Enquiries':'♧','Chatbot Leads':'☏','Pipeline':'▥','Follow-ups':'▣','Tasks':'☷','Websites & Forms':'◉','Schools & Routing':'▤','School Visits':'▣','Families':'♧','Email Templates':'✉','Communications':'☏','Automations':'⏰','Team':'♙','Reports':'◫','Import Enquiries':'⇧','Seat Planning':'▤','Admissions AI':'✦'};
const STAGES=['New','Contacted','Counselling','Visit Scheduled','Applied','Enrolled','Lost','New Enquiry','Contact Attempted','Contact Established','Visit Planned','Visit Completed','Application Started','Application Submitted','Documents Pending','Under Review','Offer Issued','Admission Confirmed','On Hold / Deferred','Waitlisted','Not Eligible','Duplicate / Invalid'];

const PHASES=['Enquiry','Engagement','Application','Admission','Exceptions'];
function admissionPhase(stage){
 if(['New','New Enquiry','Contact Attempted'].includes(stage))return 'Enquiry';
 if(['Contacted','Contact Established','Counselling','Visit Scheduled','Visit Planned','Visit Completed'].includes(stage))return 'Engagement';
 if(['Applied','Application Started','Application Submitted','Documents Pending','Under Review'].includes(stage))return 'Application';
 if(['Offer Issued','Admission Confirmed','Enrolled'].includes(stage))return 'Admission';
 return 'Exceptions';
}
const PHASE_TARGETS={'Enquiry':'New Enquiry','Engagement':'Counselling','Application':'Application Started','Admission':'Offer Issued'};
const CLOSED_STAGES=['Lost','Enrolled','Not Eligible','Duplicate / Invalid'];
const NAV_SECTIONS=[['WORKSPACE',['Dashboard','My Work']],['ADMISSIONS',['Enquiries','Chatbot Leads','Pipeline','Follow-ups','Tasks','School Visits','Families','Seat Planning']],['COMMUNICATIONS',['Communications','Email Templates','Automations']],['INSIGHTS',['Reports','Admissions AI']],['MANAGEMENT',['Websites & Forms','Schools & Routing','Team','Import Enquiries']]];

const TEMPERATURES=['Unrated','Hot','Warm','Cold'];
const CONTACT_STATES=['Pending Contact','Attempted','Connected','Awaiting Parent Response','Callback Requested','Unresponsive','No Action Required'];
const LOST_REASONS=['','Fees','Location','Transport','Curriculum','Admission elsewhere','No longer interested','No response','Other'];
const state={stagingResetPreview:null,token:sessionStorage.getItem('schoolflow_session')||'',setup:null,me:null,schoolId:null,page:'Dashboard',leads:[],sites:[],members:[],modal:'',detail:null,demo:null,audit:null,search:'',status:'All',siteFilter:'All',key:'',error:'',notice:'',loading:false};
const ext={messaging:{email:{connected:false,provider:'off',from:''},whatsapp:{connected:false,provider:'off',template:'',phone_number_id:'',language:'en_US'}},schoolsOverview:[],groupSites:[],groupKey:'',editGroupId:null,autoPolicy:{enabled:false,email:false,whatsapp:false,task_kinds:['Follow-up','Campus Visit'],max_daily:10},autoQueue:[],workQueue:{items:[],summary:{},total:0},followupPolicy:{retry_days:[1,2,4]},tasks:[],applications:[],visits:[],families:[],templates:[],analytics:{},assignment:{mode:'least_loaded'},assignedFilter:'All',dueFilter:'All',contactFilter:'All',temperatureFilter:'All',sourceTypeFilter:'All',formTypeFilter:'All',importUpload:null,importPreview:null,lastImport:null,seatPlans:[],responseSla:{first_contact_minutes:120},resetResult:null,editMemberId:null,editSiteId:null,editSchoolId:null,visitMonth:new Date().toISOString().slice(0,7),chatSettings:null,chatProvider:null,chatProviderTest:null,chatQueue:[],chatGroups:[]};
const esc=(v)=>String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const initials=(str)=>String(str||'?').split(/\s+/).slice(0,2).map(s=>s[0]).join('').toUpperCase();
const dateFmt=(s)=>s?new Date(s+'T12:00:00').toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'}):'Not scheduled';
const today=()=>new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Kolkata'}); // use client's local day below for scheduling
const day=()=>{let d=new Date();return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;};
const school=()=>state.me?.schools.find(s=>s.id===state.schoolId)||state.me?.schools[0];
const role=()=>school()?.role||'';
const isAdmin=()=>role()==='Admin';
const canImport=()=>['Admin','Admissions'].includes(role());
const canManageSchools=()=>!!state.me?.schools.some(s=>s.role==='Admin');
const userName=id=>state.members.find(m=>m.id===id)?.name||'Unassigned';
const siteName=id=>state.sites.find(s=>s.id===id)?.name||'Manual entry';
const short=(s,n=42)=>String(s||'').length>n?String(s).slice(0,n-1)+'…':String(s||'');
const stat=(name,val,info,ico,cls='')=>`<div class="stat"><div class="stat-top"><span>${esc(name)}</span><div class="stat-ico ${cls}">${ico}</div></div><b>${esc(val)}</b><small>${esc(info)}</small></div>`;
const stageBadge=s=>`<span class="stage-tag stage-${esc(String(s).replaceAll(' ','-'))}">${esc(s)}</span>`;
const btn=(text,action,cls='',extra='')=>`<button class="btn ${cls}" data-action="${action}" ${extra}>${text}</button>`;
const option=(value,text,selected)=>`<option value="${esc(value)}" ${String(value)===String(selected)?'selected':''}>${esc(text)}</option>`;
const memberOptions=selected=>`<option value="">Unassigned / Auto-assign</option>`+state.members.filter(m=>m.enabled!==false).map(m=>option(m.id,m.name+' · '+m.role,selected)).join('');
const siteOptions=selected=>`<option value="">Manual entry</option>`+state.sites.map(s=>option(s.id,s.name,selected)).join('');
const input=(label,name,type='text',value='',required=false)=>`<div class="form-field"><label>${esc(label)}<input name="${name}" type="${type}" value="${esc(value)}" ${required?'required':''}></label></div>`;
const select=(label,name,options)=>`<div class="form-field"><label>${esc(label)}<select name="${name}">${options}</select></label></div>`;
const toast=(msg,error=false)=>{state.notice=msg;state.error=error?msg:'';let old=document.getElementById('sfToast');if(old)old.remove();let el=document.createElement('div');el.id='sfToast';el.className='toast';el.setAttribute('role',error?'alert':'status');el.setAttribute('aria-live',error?'assertive':'polite');el.textContent=msg;el.style.background=error?'#be425a':'#2b3450';document.body.append(el);setTimeout(()=>el.remove(),3500);};
async function api(path,method='GET',body=null){
 const headers={};if(state.token)headers.Authorization='Bearer '+state.token;
 if(body!==null)headers['Content-Type']='application/json';
 let resp;
 try{resp=await fetch(APP_BASE+path,{method,headers,body:body===null?undefined:JSON.stringify(body),cache:'no-store'});}catch(e){throw new Error('Cannot reach the CRM server. Is it running?');}
 let data=await resp.json().catch(()=>({}));
 if(resp.status===401 && path!='/api/auth/login' && path!='/api/setup/admin'){
   state.token='';state.me=null;sessionStorage.removeItem('schoolflow_session');render();throw new Error('Your session expired. Please sign in again.');
 }
 if(!resp.ok){let detail=data.detail;if(Array.isArray(detail))detail=detail.map(d=>d.msg).join('; ');throw new Error(typeof detail==='string'?detail:`Request failed (${resp.status})`);}
 return data;
}
async function initialize(){
 try {
   state.setup=await api('/api/setup/status');
   if(state.setup.needs_setup){state.token='';state.me=null;sessionStorage.removeItem('schoolflow_session');render();return;}
 }catch(e){state.setup={needs_setup:false};state.error='Unable to check application setup: '+e.message;render();return;}
 if(!state.token){render();return;}
 try{state.me=await api('/api/me');state.schoolId=Number(sessionStorage.getItem('schoolflow_school'))||state.me.schools[0]?.id;
 if(!state.me.schools.find(s=>s.id===state.schoolId))state.schoolId=state.me.schools[0]?.id;
 if(state.me.user.must_change_password){render();return;}
 await reload();}catch(e){state.token='';sessionStorage.removeItem('schoolflow_session');state.error=e.message;render();}
}
async function reload(){
 if(!state.schoolId){render();return;}
 const path=`/api/schools/${state.schoolId}`;
 [state.leads,state.sites,state.members,state.demo,state.audit]=await Promise.all([
   api(path+'/leads'),api(path+'/sites'),api(path+'/members'),
   isAdmin()?api(path+'/demo-data'):Promise.resolve(null),
   isAdmin() && state.page==='Reports'?api(path+'/audit'):Promise.resolve(null)
 ]);
 [ext.visits,ext.families,ext.templates,ext.analytics,ext.assignment,ext.messaging,ext.tasks,ext.applications,ext.workQueue]=await Promise.all([
   api(path+'/visits'),api(path+'/families'),api(path+'/templates'),api(path+'/analytics'),api(path+'/assignment'),api(path+'/communications'),api(path+'/tasks'),api(path+'/applications'),api(path+'/work-queue?limit=100')]);
 if(isAdmin()){
  [ext.autoPolicy,ext.autoQueue,ext.followupPolicy,ext.seatPlans,ext.responseSla]=await Promise.all([api(path+'/automation'),api(path+'/automation/queue'),api(path+'/followup-policy'),api(path+'/seat-plans'),api(path+'/response-sla')]);
 }else{ext.autoPolicy={enabled:false};ext.autoQueue=[];}
 render();
}
const auditStamp=(stamp)=>{const d=new Date(stamp);return Number.isNaN(d.getTime())?String(stamp||''):
 d.toLocaleString('en-IN',{timeZone:'Asia/Kolkata',day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});};
function auditEntries(events){return (events||[]).map(a=>`<div class="audit-entry"><div class="audit-entry-title">${esc(a.action.replaceAll('_',' '))}</div>${a.details?`<div class="audit-entry-desc">${esc(a.details)}</div>`:''}<div class="audit-entry-meta">${esc(a.actor_name||'Website / system')} · ${esc(auditStamp(a.created_at))}</div>${a.lead_id?`<div class="audit-entry-link">Enquiry #${Number(a.lead_id)}</div>`:''}</div>`).join('')||'<p class="muted">No recorded actions yet.</p>';}
function loginPage(){const isSetup=state.setup?.needs_setup;
 const form=isSetup?`<h2>Create first administrator</h2><p>Set up your school and administrator account. This option is available only before the first user is created.</p>${state.error?`<div class="error" role="alert">${esc(state.error)}</div>`:''}
 <form id="setupForm" autocomplete="on">${input('School name','school_name','text','',true)}${input('Your full name','name','text','',true)}${input('Admin work email','email','email','',true)}${input('Password (12+ characters)','password','password','',true)}${input('Confirm password','confirm_password','password','',true)}<div class="form-field"><label>GitHub fine-grained token (github_pat_...)</label><input name="github_token" type="password" required autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="255"></div><button type="submit" class="btn primary full-btn">Create administrator →</button></form>
 <div class="footer-tip" style="margin-top:24px"><b>Verify GitHub repository ownership (no SSH)</b><div style="margin-top:6px">Sign in to GitHub as <strong>indigiti</strong>. <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">Create a fine-grained token</a> with no additional repository permissions, paste it above, and revoke it on GitHub after setup. It is used once to verify your GitHub identity and is never saved by SchoolFlow.</div></div>`:
 `<h2>Welcome back</h2><p>Sign in to your school admissions workspace.</p>${state.error?`<div class="error" role="alert">${esc(state.error)}</div>`:''}<form id="loginForm">${input('Work email','email','email','',true)}${input('Password','password','password','',true)}<button type="submit" class="btn primary full-btn">Sign in →</button></form>`;
 return `<div class="login-wrap"><div class="login-brand"><div class="logo" style="padding:0"><div class="logomark">S</div><div><strong>SchoolFlow</strong><small>Admissions CRM</small></div></div><div class="eyebrow" style="margin-top:48px">MULTI-SCHOOL ADMISSIONS PLATFORM</div><h1>Every school enquiry. One clear journey.</h1><p>Capture enquiries from WordPress and other websites, assign counsellors, schedule follow-ups and convert more families into admissions.</p><div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:23px"><span class="pill">Multiple schools</span><span class="pill">Private website APIs</span><span class="pill">Staff roles</span><span class="pill">Live shared records</span></div></div><div class="login-panel"><div class="hero-icon" style="height:55px;width:55px;margin-bottom:22px;color:#fff;background:linear-gradient(135deg,#787ae9,#5db2f5)">✦</div>${form}</div></div>`;
}
const NAV_SVG={"Dashboard":"<rect x=\"3\" y=\"3\" width=\"7\" height=\"9\" rx=\"1\"/><rect x=\"14\" y=\"3\" width=\"7\" height=\"5\" rx=\"1\"/><rect x=\"14\" y=\"12\" width=\"7\" height=\"9\" rx=\"1\"/><rect x=\"3\" y=\"16\" width=\"7\" height=\"5\" rx=\"1\"/>","My Work":"<rect x=\"4\" y=\"3\" width=\"16\" height=\"18\" rx=\"2\"/><path d=\"M8 8h8M8 12h4m-4 4 2 2 4-4\"/>","Enquiries":"<path d=\"M5 21h14M7 17v-3a5 5 0 0 1 10 0v3M9 7a3 3 0 0 1 6 0\"/>","Chatbot Leads":"<rect x=\"3\" y=\"7\" width=\"18\" height=\"13\" rx=\"3\"/><path d=\"M12 3v4M8 13h.01M16 13h.01M8 17h8\"/>","Pipeline":"<rect x=\"2\" y=\"4\" width=\"5\" height=\"16\" rx=\"1\"/><rect x=\"9.5\" y=\"4\" width=\"5\" height=\"16\" rx=\"1\"/><rect x=\"17\" y=\"4\" width=\"5\" height=\"16\" rx=\"1\"/>","Follow-ups":"<path d=\"M21 12a9 9 0 1 1-3-6.7M21 4v6h-6M12 7v5l3 2\"/>","Tasks":"<rect x=\"4\" y=\"3\" width=\"16\" height=\"18\" rx=\"2\"/><path d=\"m8 9 1.5 1.5L12 8M14 9h3m-9 7 1.5 1.5L12 15m2 1h3\"/>","Websites & Forms":"<circle cx=\"12\" cy=\"12\" r=\"9\"/><path d=\"M3 12h18M12 3c4 4 4 14 0 18M12 3c-4 4-4 14 0 18\"/>","Schools & Routing":"<path d=\"m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8\"/>","School Visits":"<rect x=\"3\" y=\"5\" width=\"18\" height=\"16\" rx=\"2\"/><path d=\"M7 3v4M17 3v4M3 10h18M8 15h3m-3 3h3\"/>","Families":"<path d=\"M16 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M9.5 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6M21 20v-2a4 4 0 0 0-3-3.9M16 4.2a3 3 0 0 1 0 5.6\"/>","Email Templates":"<rect x=\"3\" y=\"5\" width=\"18\" height=\"14\" rx=\"2\"/><path d=\"m3 7 9 7 9-7\"/>","Communications":"<path d=\"M21 11a8 8 0 0 1-8 8H8l-5 3v-6a8 8 0 1 1 18-5z\"/>","Automations":"<path d=\"m4 4 4 0 0 4M20 20h-4v-4M7 17a8 8 0 0 0 13-4M17 7A8 8 0 0 0 4 11M12 8v4l3 2\"/>","Team":"<circle cx=\"9\" cy=\"8\" r=\"3\"/><path d=\"M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6M20 20v-2a5 5 0 0 0-3-4.6\"/>","Reports":"<path d=\"M4 20V10M10 20V4M16 20v-8M22 20v-5M2 20h21\"/>","Import Enquiries":"<path d=\"M12 3v13m-5-5 5 5 5-5M4 17v4h16v-4\"/>","Seat Planning":"<path d=\"M4 21v-7m16 7v-7M4 18h16M6 14V7a3 3 0 0 1 6 0v7M12 14V7a3 3 0 0 1 6 0v7\"/>","Admissions AI":"<path d=\"m12 2 2.4 7.6L22 12l-7.6 2.4L12 22l-2.4-7.6L2 12l7.6-2.4z\"/>"};
function navIcon(page){return `<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${NAV_SVG[page]||NAV_SVG.Dashboard}</svg>`;}
function nav(){
 const due=state.leads.filter(l=>l.followup_date&&l.followup_date<=day()&&!CLOSED_STAGES.includes(l.status)&&l.status!=='Admission Confirmed').length;
 const chatbot=state.leads.filter(l=>!!l.chatbot_lead||['admissions_guided_chat','public_admissions_chat'].includes(l.utm_source)).length;
 const groups=NAV_SECTIONS.map(([title,items])=>{
  const permitted=items.filter(p=>(p!=='Schools & Routing'||canManageSchools())&&(p!=='Automations'||isAdmin())&&(p!=='Import Enquiries'||canImport())&&(p!=='Seat Planning'||isAdmin())&&(p!=='Admissions AI'||isAdmin()));
  if(!permitted.length)return '';
  return `<div class="sf-nav-group"><div class="nav-section">${esc(title)}</div>${permitted.map(p=>{
   const count=p==='Enquiries'?state.leads.length:p==='Chatbot Leads'?chatbot:p==='Follow-ups'?due:0;
   return `<button type="button" class="${state.page===p?'active':''}" data-action="nav" data-page="${esc(p)}" ${state.page===p?'aria-current="page"':''}><span class="nav-emoji">${navIcon(p)}</span><span class="sf-nav-text">${esc(p)}</span>${count?`<em>${count}</em>`:''}</button>`;
  }).join('')}</div>`;
 }).join('');
 return `<aside class="sidebar" id="sidebar"><div class="logo"><div class="logomark">S</div><div><strong>SchoolFlow</strong><small>ADMISSIONS WORKSPACE</small></div><button class="sf-close-nav" type="button" data-action="close-menu" aria-label="Close menu">×</button></div><div class="school-select"><label for="schoolSelect">CURRENT CAMPUS</label><select id="schoolSelect" aria-label="Choose school">${state.me.schools.map(s=>option(s.id,s.name,state.schoolId)).join('')}</select></div><nav class="nav" aria-label="SchoolFlow main navigation">${groups}</nav><div class="sidebar-bottom"><div class="sf-workspace-health"><span class="sf-dot"></span><div><strong>Workspace ready</strong><small>${state.sites.filter(s=>s.enabled).length} connected sources</small></div></div><div class="profile"><div class="avatar">${esc(initials(state.me.user.name))}</div><div class="sf-profile-details"><strong>${esc(state.me.user.name)}</strong><span>${esc(role())}</span></div><button type="button" class="icon-btn" title="Sign out" aria-label="Sign out" data-action="logout">${navIcon('Import Enquiries')}</button></div></div></aside>`;
}
function topbar(){
 const date=new Date().toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'});
 const due=state.leads.filter(l=>l.followup_date&&l.followup_date<=day()&&!CLOSED_STAGES.includes(l.status)&&l.status!=='Admission Confirmed').length;
 return `<header class="topbar"><div class="sf-top-left"><button type="button" class="icon-btn mobile-menu" data-action="menu" title="Open navigation" aria-label="Open navigation" aria-controls="sidebar">☰</button><div class="sf-top-location"><span>SchoolFlow / ${esc(school()?.name||'Campus')}</span><strong>${esc(state.page)}</strong></div></div><div class="top-actions"><span class="sf-top-date">${esc(date)}</span><button type="button" class="sf-top-icon" data-action="open-search" title="Search enquiries" aria-label="Search enquiries">⌕</button><button type="button" class="sf-top-reminders" data-action="nav" data-page="Follow-ups" title="Open due follow-ups">Follow-ups ${due?`<span>${due}</span>`:''}</button>${canManageSchools()&&state.page!=='Schools & Routing'?'<button type="button" class="sf-top-icon sf-add-school" data-action="new-school" title="Add school" aria-label="Add school">＋</button>':''}<button type="button" class="btn primary sf-new-action" data-action="new-lead">＋ New enquiry</button></div></header>`;
}
function pageHead(title,description,button=''){return `<div class="view-title"><div><div class="eyebrow">${esc(school()?.name||'Workspace')}</div><h1>${esc(title)}</h1><p>${esc(description)}</p></div><div class="new-controls">${button}</div></div>`;}
function tableRows(rows){return rows.map(l=>`<tr data-action="view-lead" data-id="${l.id}"><td><div class="name-cell"><div class="small-avatar">${initials(l.name)}</div><div><b>${esc(l.name)}</b><small>${esc(l.guid?'#'+l.guid.slice(0,8):'#'+l.id)} · ${esc(l.grade||'Class not set')} · ${esc(l.temperature||'Unrated')}${l.duplicate_of?' · Possible duplicate':''}${l.is_demo?' · DEMO':''}${l.last_call_outcome?' · '+esc(l.last_call_outcome):''}</small></div></div></td><td><div class="strong">${esc(l.guardian)}</div><small class="muted">${esc(l.phone)}</small></td><td><strong>${esc(l.source_name||'Manual entry')}</strong><small class="muted">${esc((l.source_type||'other').replaceAll('_',' '))} · ${esc(l.form_type||'unknown')}${l.landing_page_version?' · '+esc(l.landing_page_version):''}</small></td><td>${stageBadge(l.status)}</td><td>${esc(l.assigned_name||'Unassigned')}</td><td>${esc(dateFmt(l.followup_date))}</td><td><button class="btn small" data-action="view-lead" data-id="${l.id}">View →</button></td></tr>`).join('')||`<tr><td colspan="7"><div class="empty-state"><b>No enquiries found</b><p>Connect a website or add an enquiry to get started.</p></div></td></tr>`;}
function leadTable(rows){return `<div class="panel"><div class="table-scroll"><table class="lead-table"><thead><tr><th>Student</th><th>Parent / phone</th><th>Source</th><th>Stage</th><th>Assigned</th><th>Follow-up</th><th>Action</th></tr></thead><tbody>${tableRows(rows)}</tbody></table></div></div>`;}
const canWebStageReset=()=>window.location.hostname==='stage.digiti.in'&&isAdmin()&&
  String(state.me?.user?.email||'').toLowerCase().split('@')[0]==='in.stg.adm';
function stagingResetPanel(){
 if(!canWebStageReset())return '';
 const p=state.stagingResetPreview;
 const row=p?Object.entries(p.counts||{}).filter(([k,v])=>Number(v)>0).map(([k,v])=>
   '<span style="display:inline-block;margin:3px 12px 3px 0">'+esc(k.replaceAll('_',' '))+': <b>'+Number(v)+'</b></span>').join(''):'';
 return '<section class="page-card" style="border:1px solid #d9a8a8;margin-top:14px">'+
   '<h3 style="margin:0 0 8px">Full staging reset — administrator only</h3>'+
   '<p>This deletes all existing schools, staff, enquiries, connected websites and settings. It preserves your admin account, creates one empty school, and signs out everyone. A verified private server backup is created before deletion.</p>'+
   '<p class="muted-note">This is different from Clear demo data. WordPress and external provider records are not deleted.</p>'+
   (p?'<div role="status"><strong>Preview for: '+esc(p.keep_email||'')+'</strong><p>'+row+'</p></div>'+
     '<form id="webStageResetForm">'+
       '<label class="form-field">Current admin password<input type="password" name="password" autocomplete="current-password" required></label>'+
       '<label class="form-field">Type RESET STAGING KEEP IN.STG.ADM<input name="confirmation" type="text" autocomplete="off" required></label>'+
       '<p class="muted-note">No undo in the browser. A server-side backup is kept privately for recovery.</p>'+
       '<button type="submit" class="btn primary">Delete staging data and preserve admin</button>'+
     '</form>':'<button type="button" class="btn small" data-action="preview-web-stage-reset">Preview full staging reset</button>')+
   '</section>';
}
function demoControls(){
 if(!isAdmin())return '';
 const d=state.demo||{leads:0,sites:0,has_demo_data:false};
 return `<div class="page-card" style="margin:0 0 18px;padding:17px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;border:1px solid #dcdffb;background:#f8f9ff"><div><strong style="font-size:15px;color:#27305b">Demo data controls <span style="font-size:11px;color:#6c57e8">ADMIN ONLY</span></strong><div class="muted-note" style="margin-top:5px">${d.has_demo_data?`${d.leads} demo enquiries · ${d.sites} demo sources. These are synthetic examples.`:'Preview the CRM with 18 fictional enquiries across admissions stages and 3 example websites.'}</div><div class="muted-note">Clearing demo records does not delete real leads or staff.</div></div><div class="button-row">${btn('＋ Add demo data','add-demo','primary small',d.has_demo_data?'disabled title="Clear demo data first"':'')}${btn('Clear demo data','clear-demo','small',d.has_demo_data?'':'disabled title="No demo records"')}</div></div>`;
}
function dashboard(){
 const leads=state.leads,total=leads.length,now=day();
 const active=leads.filter(l=>!CLOSED_STAGES.includes(l.status)&&l.status!=='Admission Confirmed');
 const recent=leads.filter(l=>['New','New Enquiry'].includes(l.status)).length;
 const dueItems=active.filter(l=>l.followup_date&&l.followup_date<=now).sort((a,b)=>String(a.followup_date).localeCompare(String(b.followup_date)));
 const unassigned=active.filter(l=>!l.assigned_to).length;
 const admitted=leads.filter(l=>['Enrolled','Admission Confirmed'].includes(l.status)).length;
 const visits=(ext.visits||[]).filter(v=>v.status==='Scheduled'&&v.starts_at&&v.starts_at.slice(0,10)>=now).length;
 const firstContact=ext.analytics.first_contact_pending??active.filter(l=>!l.last_successful_contact_at).length;
 const breach=ext.analytics.sla_breached||0;
 const conversion=total?Math.round(admitted/total*100):0;
 const sources=state.sites.map(s=>({...s,count:leads.filter(l=>String(l.site_id)===String(s.id)).length})).sort((a,b)=>b.count-a.count);
 const phases=['Enquiry','Engagement','Application','Admission','Exceptions'].map(name=>({name,count:leads.filter(l=>admissionPhase(l.status)===name).length}));
 const latest=[...leads].sort((a,b)=>String(b.created_at||'').localeCompare(String(a.created_at||''))).slice(0,6);
 const kpi=(title,value,detail,icon,target,kind='')=>`<button type="button" class="sf-kpi ${kind}" data-action="nav" data-page="${esc(target)}"><span class="sf-kpi-title">${esc(title)}<span class="sf-kpi-symbol">${navIcon(icon)}</span></span><strong>${esc(value)}</strong><small>${esc(detail)}</small><span class="sf-kpi-link">Explore <span aria-hidden="true">↗</span></span></button>`;
 const taskItem=(label,count,page,description,kind='')=>`<button type="button" class="sf-priority-item ${kind}" data-action="nav" data-page="${esc(page)}"><span class="sf-priority-count">${esc(count)}</span><span class="sf-priority-label"><strong>${esc(label)}</strong><small>${esc(description)}</small></span><span aria-hidden="true">→</span></button>`;
 return `<div class="sf-dashboard">
 <section class="sf-welcome"><div><span class="sf-overline"><span class="sf-dot"></span> ADMISSIONS COMMAND CENTRE</span><h1>Good ${new Date().getHours()<12?'morning':new Date().getHours()<17?'afternoon':'evening'}, ${esc(state.me.user.name.split(/\s+/)[0]||'there')}.</h1><p>Here’s your admissions overview for <strong>${esc(school()?.name||'your school')}</strong>. Review what needs attention and move families forward.</p></div><div class="sf-welcome-aside"><span>Today’s priorities</span><strong>${dueItems.length+unassigned+Number(breach||0)}</strong><small>Follow-ups, unassigned and SLA flags</small><button type="button" class="btn primary" data-action="nav" data-page="My Work">Open My Work →</button></div></section>
 <div class="sf-dashboard-section-title"><div><h2>Admissions overview</h2><p>Live data from your selected campus</p></div><button type="button" class="sf-text-link" data-action="nav" data-page="Reports">Detailed reports ↗</button></div>
 <div class="sf-kpi-grid">
 ${kpi('Total enquiries',total,'All captured admissions enquiries','Enquiries','Enquiries')}
 ${kpi('New enquiries',recent,'Awaiting admissions outreach','Chatbot Leads','Enquiries','tone-blue')}
 ${kpi('Follow-ups due',dueItems.length,'Today and overdue','Follow-ups','Follow-ups','tone-amber')}
 ${kpi('Admissions confirmed',admitted,`${conversion}% of recorded enquiries`,'Reports','Pipeline','tone-green')}
 </div>
 <section class="sf-quick-launch" aria-label="Quick actions"><div class="sf-quick-label">QUICK ACCESS</div>${[['My Work','My Work'],['Enquiries','Enquiries'],['Chatbot Leads','Chatbot Leads'],['School Visits','School Visits'],['Families','Families']].map(([name,target])=>`<button type="button" data-action="nav" data-page="${esc(target)}">${navIcon(name)}<span>${esc(name)}</span><span aria-hidden="true">↗</span></button>`).join('')}</section>
 <div class="sf-dashboard-body">
 <div class="sf-dashboard-primary">
 <section class="sf-surface"><div class="sf-section-header"><div><h2>Needs your attention</h2><p>Actions that help prevent missed enquiries</p></div><button type="button" class="sf-text-link" data-action="nav" data-page="My Work">View work queue →</button></div>
 <div class="sf-priority-grid">${taskItem('Follow-ups due',dueItems.length,'Follow-ups','Scheduled for today or earlier','urgent')}${taskItem('First contact pending',firstContact,'My Work','Parents waiting for the first response')}${taskItem('Unassigned enquiries',unassigned,'Enquiries','Assign a team member')}${taskItem('SLA breaches',breach,'My Work','First-response target exceeded','urgent')}</div>
 ${dueItems.length?`<div class="sf-due-heading">Next follow-ups</div><div class="sf-due-list">${dueItems.slice(0,3).map(l=>`<button type="button" class="sf-due-row" data-action="view-lead" data-id="${Number(l.id)}"><span class="small-avatar">${esc(initials(l.name))}</span><span class="sf-due-name"><strong>${esc(l.name)}</strong><small>${esc(l.guardian||'Parent contact')} · ${esc(dateFmt(l.followup_date))}</small></span>${stageBadge(l.status)}<span aria-hidden="true">↗</span></button>`).join('')}</div>`:'<p class="sf-all-clear">No overdue or due-today follow-ups.</p>'}
 </section>
 <section class="sf-surface"><div class="sf-section-header"><div><h2>Recent enquiries</h2><p>New activity in your shared inbox</p></div><button type="button" class="sf-text-link" data-action="nav" data-page="Enquiries">All enquiries →</button></div>
 ${latest.length?`<div class="sf-recent-list">${latest.map(l=>`<button type="button" class="sf-recent-row" data-action="view-lead" data-id="${Number(l.id)}"><span class="small-avatar">${esc(initials(l.name))}</span><span class="sf-recent-details"><strong>${esc(l.name)}</strong><small>${esc(l.grade||'Class not set')} · ${esc(l.source_name||'Manual entry')}</small></span>${stageBadge(l.status)}<span class="sf-row-arrow" aria-hidden="true">↗</span></button>`).join('')}</div>`:'<div class="sf-dashboard-empty"><strong>No enquiries yet</strong><p>New website and manual enquiries will appear here.</p><button type="button" class="btn primary" data-action="new-lead">＋ Add first enquiry</button></div>'}
 </section></div>
 <div class="sf-dashboard-aside">
 <section class="sf-surface"><div class="sf-section-header"><div><h2>Admissions funnel</h2><p>Enquiries by journey phase</p></div><button type="button" class="sf-text-link" data-action="nav" data-page="Pipeline">Pipeline ↗</button></div>
 <div class="sf-funnel-list">${phases.map(p=>`<div class="sf-funnel-row"><div><span>${esc(p.name)}</span><strong>${p.count}</strong></div><div class="sf-funnel-track"><span style="width:${total?Math.max(0,Math.min(100,p.count/total*100)):0}%"></span></div></div>`).join('')}</div><div class="sf-funnel-foot"><span>Admission conversion</span><strong>${conversion}%</strong></div></section>
 <section class="sf-surface"><div class="sf-section-header"><div><h2>Connected sources</h2><p>Where enquiries originate</p></div>${isAdmin()?'<button type="button" class="sf-text-link" data-action="nav" data-page="Websites & Forms">Manage ↗</button>':''}</div>
 ${sources.length?`<div class="sf-source-list">${sources.slice(0,5).map(s=>`<div class="sf-source-row"><span class="site-icon">${s.platform==='WordPress'?'W':'◎'}</span><span><strong>${esc(short(s.name,27))}</strong><small>${esc(short(s.domain,35))}</small></span><b>${s.count}</b></div>`).join('')}</div>`:'<p class="sf-empty-note">No connected website sources. Add a website to start collecting enquiries automatically.</p>'}
 </section>
 <section class="sf-mini-operations"><div><span>${navIcon('School Visits')}</span><strong>${visits}</strong><small>Upcoming school visits</small></div><div><span>${navIcon('Families')}</span><strong>${ext.families.length}</strong><small>Families in CRM</small></div></section>
 </div></div>
 ${isAdmin()?`<details class="sf-demo-options"><summary>Demo data and testing tools</summary>${demoControls()}${stagingResetPanel()}</details>`:''}
 </div>`;
}
function enquiries(){let leads=state.leads.filter(l=>(state.status==='All'||l.status===state.status)&&(state.siteFilter==='All'||String(l.site_id)===state.siteFilter)&&(ext.sourceTypeFilter==='All'||(l.source_type||'other')===ext.sourceTypeFilter)&&(ext.formTypeFilter==='All'||(l.form_type||'unknown')===ext.formTypeFilter)&&(ext.temperatureFilter==='All'||(l.temperature||'Unrated')===ext.temperatureFilter)&&(!state.search||[l.name,l.guardian,l.phone,l.grade,l.email].join(' ').toLowerCase().includes(state.search.toLowerCase()))&&(ext.assignedFilter==='All'||(ext.assignedFilter==='Unassigned'?!l.assigned_to:String(l.assigned_to)===ext.assignedFilter))&&(ext.contactFilter==='All'||(ext.contactFilter==='Pending'?!l.last_successful_contact_at:!!l.last_successful_contact_at))&&(ext.dueFilter==='All'||(ext.dueFilter==='Overdue'?!!l.followup_date&&l.followup_date<day():ext.dueFilter==='Today'?l.followup_date===day():ext.dueFilter==='Upcoming'?l.followup_date>day():!l.followup_date)));
 return `${pageHead('Enquiries', 'Search, assign and track school admissions enquiries.',btn('＋ Add enquiry','new-lead','primary')+btn('⇩ Export Excel','export-xlsx','primary')+btn('CSV','export-csv','small'))}<div class="filters button-row"><input id="q" type="search" placeholder="Search name, guardian, phone or class" value="${esc(state.search)}"><select id="statusFilter">${['All',...STAGES].map(s=>option(s,s==='All'?'All stages':s,state.status)).join('')}</select><select id="siteFilter">${option('All','All sources',state.siteFilter)}${state.sites.map(s=>option(s.id,s.name,state.siteFilter)).join('')}</select><select id="sourceTypeFilter">${option('All','All origin types',ext.sourceTypeFilter)}${[['group_website','Group website'],['individual_website','Individual website'],['chatbot','AI chatbot'],['manual','Manual'],['import','Imported'],['other','Other']].map(([v,t])=>option(v,t,ext.sourceTypeFilter)).join('')}</select><select id="formTypeFilter">${option('All','All form surfaces',ext.formTypeFilter)}${[['inline','Inline'],['popup','Popup'],['chat','Chat'],['api','Website API'],['manual','Manual'],['unknown','Unknown / legacy']].map(([v,t])=>option(v,t,ext.formTypeFilter)).join('')}</select><select id="assignedFilter">${option('All','All counsellors',ext.assignedFilter)}${option('Unassigned','Unassigned',ext.assignedFilter)}${state.members.map(m=>option(m.id,m.name,ext.assignedFilter)).join('')}</select><select id="dueFilter">${['All','Overdue','Today','Upcoming','Unscheduled'].map(x=>option(x,x==='All'?'All follow-ups':x,ext.dueFilter)).join('')}</select><select id="temperatureFilter">${['All',...TEMPERATURES].map(t=>option(t,t==='All'?'All temperatures':t,ext.temperatureFilter)).join('')}</select><select id="contactFilter">${option('All','All contact status',ext.contactFilter)}${option('Pending','Never contacted',ext.contactFilter)}${option('Contacted','Previously contacted',ext.contactFilter)}</select><span class="muted-note">${leads.length} result(s)</span></div>${leadTable(leads)}`;
}
function chatbotLeads(){
 const all=state.leads.filter(l=>!!l.chatbot_lead ||
   ['admissions_guided_chat','public_admissions_chat'].includes(l.utm_source));
 const rows=all.filter(l=>(state.status==='All'||l.status===state.status)&&
   (!state.search||[l.name,l.guardian,l.phone,l.grade,l.email,l.source_name].join(' ').toLowerCase().includes(state.search.toLowerCase())));
 const callbackCount=all.filter(l=>l.contact_state==='Callback Requested').length;
 const newCount=all.filter(l=>['New','New Enquiry'].includes(l.status)).length;
 return pageHead('Chatbot Leads','Admissions enquiries captured through guided chatbot sessions. Leads remain in the normal pipeline for follow-up.')+
  '<div class="panel" style="padding:17px;margin-bottom:16px;display:flex;gap:24px;flex-wrap:wrap">'+
  '<div><b>'+all.length+'</b><div class="muted-note">Total chatbot leads</div></div>'+
  '<div><b>'+newCount+'</b><div class="muted-note">New enquiries</div></div>'+
  '<div><b>'+callbackCount+'</b><div class="muted-note">Callback requested</div></div></div>'+
  '<div class="filters button-row"><input id="q" type="search" placeholder="Search chatbot leads" value="'+esc(state.search)+'">'+
  '<select id="statusFilter">'+['All',...STAGES].map(x=>option(x,x==='All'?'All stages':x,state.status)).join('')+'</select>'+
  '<span class="muted-note">'+rows.length+' result(s)</span></div>'+
  leadTable(rows);
}
function pipeline(){
 const columns=PHASES.map(phase=>{
   const rows=state.leads.filter(l=>admissionPhase(l.status)===phase);
   const drop=phase==='Exceptions'?'':` data-phase="${esc(phase)}"`;
   return `<section class="pipeline-column phase-column"${drop} aria-label="${esc(phase)} phase">
     <div class="button-row phase-header"><b>${esc(phase)}</b><span class="phase-count">${rows.length}</span></div>
     ${rows.length?rows.map(l=>`<div class="pipeline-item phase-card" draggable="true" data-lead="${l.id}" data-action="view-lead" data-id="${l.id}" role="button" tabindex="0" aria-label="Open ${esc(l.name)} enquiry">
       <b>${esc(l.name)}</b><small>${esc(l.grade||'—')} · ${esc(l.guardian)}</small>
       <small>${esc(l.status)} · ${esc(l.temperature||'Unrated')}</small>
       <small>${esc(l.assigned_name||'Unassigned')}${l.followup_date?' · ◷ '+esc(dateFmt(l.followup_date)):''}</small>
     </div>`).join(''):'<p class="muted-note">No enquiries in this phase.</p>'}
   </section>`;
 }).join('');
 return `${pageHead('Admissions pipeline','Four admission phases and a separate exception lane. Open a lead to change detailed stages.',btn('＋ New enquiry','new-lead','primary'))}
  <div class="phase-legend">Move cards among the first four phases. Exceptions, loss reasons and enrollment require the detailed enquiry workflow.</div>
  <div class="pipeline-scroll"><div class="pipeline-columns phase-pipeline">${columns}</div></div>
  <p class="muted-note">Keyboard users can open a card with Enter or Space and change its detailed stage.</p>`;
}
function smartQueuePanel(queue){
 const items=queue.items||[];
 const rows=items.map(item=>'<tr><td><b>'+Number(item.priority)+'</b></td>'+
 '<td>'+esc(item.student_name||'Student')+' <small class="muted">'+esc(item.grade||'')+'</small></td>'+
 '<td>'+((item.reasons||[]).map(x=>esc(String(x).replaceAll('_',' '))).join(', '))+'</td>'+
 '<td>'+esc(item.due_date||'Not scheduled')+'</td><td>'+esc(item.stage||'')+'</td>'+
 '<td><button class="btn small" data-action="view-lead" data-id="'+Number(item.lead_id)+'">Open enquiry</button></td></tr>').join('')||
 '<tr><td colspan="6">No actionable enquiries need attention.</td></tr>';
 return '<div class="panel"><div class="worklist-head"><h2>Smart priority queue</h2>'+
 '<span class="muted-note">'+Number(queue.total||0)+' enquiries needing action</span></div>'+
 '<div class="table-scroll"><table class="lead-table"><thead><tr><th>Priority</th><th>Student</th><th>Reason</th><th>Due</th><th>Stage</th><th>Action</th></tr></thead>'+
 '<tbody>'+rows+'</tbody></table></div></div>';
}
function myWork(){
 const open=(ext.tasks||[]).filter(t=>t.status==='Open');
 const due=open.filter(t=>['Overdue','Due Today'].includes(t.due_status));
 const items=[...due,...open.filter(t=>!due.includes(t))].sort((a,b)=>(a.due_date||'').localeCompare(b.due_date||''));
 const pending=state.leads.filter(l=>!l.last_successful_contact_at&&!CLOSED_STAGES.includes(l.status));
 return `${pageHead('My Work','Your admissions actions, callbacks and parent enquiries in one place.',btn('＋ New enquiry','new-lead','primary'))}
 <div class="stats">
  ${stat('Due now',due.length,'Overdue and today','◷','orange')}
  ${stat('Open actions',open.length,'All visible pending tasks','☑','blue')}
  ${stat('First contact',pending.length,'Parents not yet reached','☎')}${stat("SLA breached",ext.analytics.sla_breached||0,"Requires priority outreach","!","orange")}
  ${stat('Scheduled visits',(ext.visits||[]).filter(v=>v.status==='Scheduled').length,'Upcoming campus visits','▣')}
 </div>
 ${smartQueuePanel(ext.workQueue||{})}
 <div class="panel"><div class="worklist-head"><h2>Priority actions</h2><span class="muted-note">School time · ${esc(day())}</span></div>
 <div class="table-scroll"><table class="lead-table"><thead><tr><th>Due</th><th>Action</th><th>Student</th><th>Assigned</th><th>Status</th><th>Next step</th></tr></thead>
 <tbody>${items.map(t=>`<tr>
 <td>${esc(t.due_date||'Not scheduled')}</td><td>${esc(t.title||t.kind)}</td>
 <td>${esc(t.student_name||'Student')}</td><td>${esc(t.assigned_name||'Unassigned')}</td>
 <td>${esc(t.due_status||'Upcoming')}</td>
 <td><button class="btn small" data-action="open-task-lead" data-id="${Number(t.lead_id)}">Open lead</button>
 ${btn('✓ Done','task-complete','small',`data-id="${Number(t.id)}"`)}</td></tr>`).join('')||'<tr><td colspan="6">No open tasks. Your queue is clear.</td></tr>'}</tbody></table></div></div>
 <div class="page-card"><h3>Pending first conversations</h3>
 ${pending.slice(0,15).map(l=>`<button class="work-lead-button" data-action="view-lead" data-id="${Number(l.id)}">${esc(l.name)} · ${esc(l.grade||'Class not selected')} · ${esc(l.phone)}</button>`).join('')||'<p class="muted-note">All visible enquiries have a recorded first conversation or are closed.</p>'}</div>`;
}
function tasksPage(){const rows=ext.tasks||[];return `<div class="page-card"><h2>Admissions action queue</h2><p>Tasks are school-scoped and remain visible until completed or cancelled.</p><div class="stats">${stat('Open tasks',rows.filter(t=>t.status==='Open').length,'Counselling and admission work','☷')}${stat('Overdue',rows.filter(t=>t.due_status==='Overdue').length,'Needs attention','◷','orange')}</div><div class="panel"><div class="table-scroll"><table class="lead-table"><thead><tr><th>Due</th><th>Task</th><th>Student</th><th>Assigned</th><th>Status</th><th>Action</th></tr></thead><tbody>${rows.map(t=>`<tr><td>${esc(t.due_date)} · ${esc(t.due_status)}</td><td>${esc(t.title)}</td><td>${esc(t.student_name)}</td><td>${esc(t.assigned_name)}</td><td>${esc(t.status)}</td><td>${t.status==='Open'?btn('✓ Complete','task-complete','small',`data-id="${t.id}"`):''}</td></tr>`).join('')}</tbody></table></div></div></div>`;} 
function followups(){let rows=state.leads.filter(l=>l.followup_date&&!['Lost','Enrolled'].includes(l.status)).sort((a,b)=>a.followup_date.localeCompare(b.followup_date));
 return `${pageHead('Follow-ups','Prioritise calls, school visits and counselling sessions.',btn('＋ New enquiry','new-lead','primary'))}<div class="stats" style="margin:0 0 22px">${stat('Due or overdue',rows.filter(l=>l.followup_date<=day()).length,'Priority contact list','◷','orange')}${stat('Scheduled',rows.filter(l=>l.followup_date>day()).length,'Future follow-ups','▣','blue')}${stat('Active enquiries',state.leads.filter(l=>!['Lost','Enrolled'].includes(l.status)).length,'Still in admissions journey','♧')}${stat('Counsellors',state.members.filter(m=>m.role==='Counsellor').length,'Available staff','♙','green')}</div>${rows.length?`<div class="panel"><div class="table-scroll"><table class="lead-table"><thead><tr><th>Due date</th><th>Student</th><th>Contact</th><th>Stage</th><th>Assigned to</th><th>Actions</th></tr></thead><tbody>${rows.map(l=>`<tr><td><b style="color:${l.followup_date<=day()?'#dc8855':'#56607a'}">${esc(dateFmt(l.followup_date))}</b></td><td><b>${esc(l.name)}</b><small class="muted" style="display:block">${esc(l.grade)}</small></td><td>${esc(l.phone)}</td><td>${stageBadge(l.status)}</td><td>${esc(l.assigned_name||'Unassigned')}</td><td><div class="button-row">${btn('Open','view-lead','small',`data-id="${l.id}"`)}${l.consent&&l.email?btn('✉ Reminder draft','email-draft','small',`data-id="${l.id}"`):''}${btn('✓ Done','done-followup','small',`data-id="${l.id}"`)}</div></td></tr>`).join('')}</tbody></table></div></div>`:'<div class="empty-state"><b>No scheduled follow-ups</b><p>Add a follow-up date to an enquiry to see it here.</p></div>'}`;
}
function sitesPage(){return `${pageHead('Websites & forms','Connect separate WordPress websites, forms and landing pages.',isAdmin()?btn('＋ Connect website','new-site','primary'):'')}<div class="app-alert">Each website gets a different secret API key. New enquiries enter the correct school automatically. Keys are displayed once when created or rotated.</div>${state.key?`<div class="key-panel"><strong>New website API key — copy it now</strong><p class="muted-note">This secret cannot be retrieved later. Keep it on your WordPress server, never in a public form or browser code.</p><div class="key-box">${esc(state.key)}</div><div class="button-row" style="margin-top:12px">${btn('Copy key','copy-key','primary small')}${btn('I saved the key','dismiss-key','small')}</div></div>`:''}<div class="website-grid">${state.sites.map(s=>`<div class="web-tile"><div class="button-row" style="justify-content:space-between"><div class="site-icon">${s.platform==='WordPress'?'W':'⌁'}</div><span class="badge" style="background:${s.enabled?'#e6f8ef':'#ffe8ec'};color:${s.enabled?'#178c60':'#b5425d'}">${s.enabled?'● Active':'○ Disabled'}</span></div><h3>${esc(s.name)}</h3><p>${esc(s.domain)} · ${esc(s.platform)}</p><div class="button-row" style="justify-content:space-between;margin-bottom:14px"><span class="muted-note">Captured enquiries</span><b style="font-size:22px">${s.lead_count}</b></div>${isAdmin()&&!s.is_group_route?`<div class="button-row">${btn('Edit','edit-site','small',`data-id="${s.id}"`)}${btn('Rotate key','rotate-key','small',`data-id="${s.id}"`)}${btn(s.enabled?'Disable':'Enable','toggle-site','small',`data-id="${s.id}" data-enabled="${s.enabled?'0':'1'}"`)}</div>`:''}</div>`).join('')||'<div class="empty-state"><b>No websites added yet</b><p>Connect your first WordPress enquiry form.</p></div>'}</div><div class="page-card" style="margin-top:20px"><h3 style="margin-top:0">Integration instructions</h3><p class="muted">Use a server-side HTTPS request from your WordPress form. A ready-to-configure Contact Form 7 plugin is included in the project.</p><div class="snippet">POST https://YOUR-CRM-DOMAIN${APP_BASE}/api/v1/inbound-leads<br>Authorization: Bearer YOUR_SITE_API_KEY<br>Idempotency-Key: UNIQUE_FORM_SUBMISSION_ID<br>Content-Type: application/json<br><br>{ "student_name": "Student", "parent_name": "Guardian",<br>  "phone": "+91 9876543210", "class": "Grade 4", "consent": true }</div></div>`;}
function groupSchoolChecks(chosen=[]){
 const admins=state.me?.schools.filter(x=>x.role==='Admin')||[];
 return `<fieldset class="group-school-choice"><legend>Schools served by the group website</legend>${admins.map(sc=>`<label><input type="checkbox" name="school_ids" value="${Number(sc.id)}" ${chosen.includes(sc.id)?'checked':''}> ${esc(sc.name)}</label>`).join('')}</fieldset>`;
}
function sfGroupEmbed(guid){
 return '<script defer src="'+window.location.origin+'/scrm/embed/'+encodeURIComponent(guid)+'"></script>';
}
function sfGroupShortEmbed(guid,mode='inline'){
 const src=window.location.origin+'/scrm/embed/'+encodeURIComponent(guid);
 const tag='<script defer src="'+src+'" data-schoolflow-form="short" data-schoolflow-mode="'+mode+'"'+(mode==='inline'?' data-schoolflow-target="#schoolflow-admissions"':'')+'></script>';
 return mode==='inline'?'<div id="schoolflow-admissions"></div>\n'+tag:tag;
}
function routingPage(){
 if(!canManageSchools())return '<div class="empty-state">School management requires administrator access.</div>';
 const rows=ext.schoolsOverview||[],groups=ext.groupSites||[];
 return `${pageHead('Schools & lead routing','Connect one or many campus websites to the right school, with admin-controlled routing.',btn('＋ Add school','new-school','primary'))}
 <div class="app-alert">For a school group website, connect the permitted campuses below. Copy the public one-tag script into the website head; no Google Script, WordPress plugin or HFCM required. The older Contact Form 7 connector remains optional. A parent's choice can route only to an approved campus.</div>
 <section class="page-card" style="margin-bottom:20px"><div class="section-head" style="margin-top:0"><div><h2>Group websites</h2><p>One form, one private group key, multiple school destinations</p></div>${btn('＋ Connect group website','new-group','primary small')}</div>
 ${ext.groupKey?`<div class="key-panel"><strong>New group website key — copy and save now</strong><p class="muted-note">This secret is shown once. Set SCHOOLFLOW_CRM_GROUP_SITE_KEY on the WordPress server, never in HTML.</p><div class="key-box">${esc(ext.groupKey)}</div><div class="button-row" style="margin-top:10px">${btn('Copy key','copy-group-key','primary small')}${btn('I saved the key','dismiss-group-key','small')}</div></div>`:''}
 <div class="route-school-grid">${groups.map(g=>`<div class="route-school-card"><div class="route-school-head"><div><h3>${esc(g.name)}</h3><p class="muted-note">${esc(g.domain)} · Group #${Number(g.id)}</p></div><span class="badge ${g.enabled?'':'route-inactive'}">${g.enabled?'Active':'Disabled'}</span></div><div class="muted-note" style="margin:8px 0 4px"><b>GUID form embed</b> — script assets refresh automatically. This GUID is safe to publish. Never use the private sfgroup key in HTML.</div><code style="display:block;overflow-wrap:anywhere;white-space:normal;font-size:11px;margin:8px 0">${esc(sfGroupEmbed(g.guid||'')) }</code><div class="button-row" style="margin-bottom:10px">${btn("Copy head script","copy-group-embed","small",`data-id="${Number(g.id)}"`)}</div>
 <div class="muted-note"><b>Compact inline admission form</b> — paste the div and script where the form should appear.</div>
 <code style="display:block;overflow-wrap:anywhere;white-space:pre-wrap;font-size:11px;margin:8px 0">${esc(sfGroupShortEmbed(g.guid||'','inline'))}</code>
 <div class="button-row" style="margin-bottom:10px">${btn("Copy inline form","copy-group-inline","small",`data-id="${Number(g.id)}"`)}</div>
 <div class="muted-note"><b>Compact popup</b> — shorter alternative to the existing detailed popup.</div>
 <code style="display:block;overflow-wrap:anywhere;white-space:normal;font-size:11px;margin:8px 0">${esc(sfGroupShortEmbed(g.guid||'','popup'))}</code>
 <div class="button-row" style="margin-bottom:10px">${btn("Copy compact popup","copy-group-short-popup","small",`data-id="${Number(g.id)}"`)}</div>
 <div class="route-sources">${g.schools.map(c=>`<div class="route-source"><span><b>${esc(c.name)}</b><small>Choice code: ${esc(c.code)}</small></span><span class="role-label">School #${Number(c.school_id)}</span></div>`).join('')}</div><div class="button-row">${btn('Edit schools','edit-group','small',`data-id="${Number(g.id)}"`)}${btn('Refresh Form ID','refresh-group-form-id','small',`data-id="${Number(g.id)}"`)}${btn('Rotate key','rotate-group-key','small',`data-id="${Number(g.id)}"`)}${btn(g.enabled?'Disable':'Enable','toggle-group','small',`data-id="${Number(g.id)}"`)}</div></div>`).join('')||'<p class="muted-note">No group websites configured. Add one to route parents from a single school selector.</p>'}</div>
 <p class="muted-note" style="margin-top:12px">WordPress: update the SchoolFlow Contact Form 7 connector to v1.2. Add <code>[select* school_code]</code> to the chosen form, and put the group key and form ID in <code>wp-config.php</code>. Campus options are fetched privately from SchoolFlow and may take up to 60 seconds to refresh.</p></section>
 <div class="section-head"><h2>Individual school websites</h2></div>
 <div class="route-school-grid">${rows.map(sc=>`<section class="route-school-card"><div class="route-school-head"><div><div class="eyebrow">SCHOOL #${Number(sc.id)}</div><h3>${esc(sc.name)}</h3></div><span class="role-label">${sc.sites.filter(s=>s.enabled).length} active source(s)</span></div>
 <div class="route-stats"><span><strong>${Number(sc.leads)}</strong>Enquiries</span><span><strong>${Number(sc.new_leads)}</strong>New</span><span><strong>${Number(sc.unassigned)}</strong>Unassigned</span></div>
 <p class="muted-note">Counsellor allocation: <strong>${esc(sc.assignment_mode.replaceAll('_',' '))}</strong></p>
 <div class="route-sources">${sc.sites.map(site=>`<div class="route-source"><span><b>${esc(site.name)}</b><small>${esc(site.domain)}</small></span><span class="badge ${site.enabled?'':'route-inactive'}">${site.enabled?'Active':'Disabled'}</span></div>`).join('')||'<p class="muted-note">No website connected.</p>'}</div>
 <div class="button-row"><button class="btn small" data-action="edit-school" data-school-id="${Number(sc.id)}">Edit school</button><button class="btn small" data-action="route-open" data-school-id="${Number(sc.id)}">Open school</button><button class="btn primary small" data-action="route-connect" data-school-id="${Number(sc.id)}">＋ Connect website</button></div></section>`).join('')}</div>`;
}

function teamPage(){
 const can=isAdmin();
 return `${pageHead('Team & permissions','Edit, suspend and restore school-scoped staff access.',can?btn('＋ Add staff member','new-member','primary'):'')}
 ${ext.resetResult?`<div class="page-card" role="status"><h3>One-time temporary password</h3>
 <p>For ${esc(ext.resetResult.name)}. Share it through a secure channel. Staff must change it at next login.</p>
 <div class="staff-temp-secret">${esc(ext.resetResult.password)}</div>
 <button class="btn small" data-action="copy-staff-password">Copy password</button>
 <button class="btn small" data-action="dismiss-staff-password">Dismiss password</button></div>`:''}
 <div class="panel"><div class="table-scroll"><table class="lead-table">
 <thead><tr><th>Team member</th><th>Work email</th><th>Role</th><th>Access</th><th>Active leads</th>${can?'<th>Actions</th>':''}</tr></thead><tbody>
 ${state.members.map(m=>`<tr><td><div class="name-cell"><div class="small-avatar">${initials(m.name)}</div><b>${esc(m.name)}</b></div></td>
 <td>${esc(m.email)}</td><td><span class="role-label">${esc(m.role)}</span></td>
 <td><span class="badge ${m.enabled===false?'status-inactive':'status-active'}">${m.enabled===false?'Disabled':'Active'}</span>${m.shared_account?'<small class="muted-note"> · Multi-school</small>':''}</td>
 <td>${state.leads.filter(l=>l.assigned_to===m.id&&!['Enrolled','Lost'].includes(l.status)).length}</td>
 ${can?`<td><div class="staff-actions">
 ${btn('Edit','edit-staff','small',`data-id="${Number(m.id)}"`)}
 ${btn('Reset password','reset-staff','small',`data-id="${Number(m.id)}" ${m.shared_account||m.id===state.me.user.id?'disabled title="Use a central administrator or your own password settings"':''}`)}
 ${m.id===state.me.user.id?'':btn(m.enabled===false?'Reactivate':'Disable',m.enabled===false?'enable-staff':'disable-staff','small',`data-id="${Number(m.id)}"`)}
 </div></td>`:''}</tr>`).join('')||'<tr><td colspan="6">No staff members</td></tr>'}
 </tbody></table></div></div>
 <section class="page-card" style="margin-top:18px"><h3>My account</h3>
 <p class="muted-note">Change your own password securely. All sessions will be signed out after the change.</p>
 ${btn('Change my password','change-my-password','small')}
 </section>
 ${can?`<section class="page-card" style="margin-top:18px"><h3>Automatic enquiry assignment</h3>
 <p class="muted-note">Disabled counsellors are excluded and their open tasks become unassigned for reassignment.</p>
 <form id="assignmentForm"><div class="button-row">${select('Assignment policy','mode',[['least_loaded','Least-loaded counsellor'],['round_robin','Round-robin counsellors'],['manual','Manual / leave unassigned']].map(([k,v])=>option(k,v,ext.assignment.mode)).join(''))}
 <button class="btn primary" type="submit">Save policy</button></div></form></section>`:''}
 <div class="footer-tip" style="margin-top:17px">Admin: school-wide access and staff administration. Admissions: all school leads. Counsellor: assigned leads only. Multi-school account credentials cannot be reset or edited by an individual campus administrator.</div>`;
}
function admissionInsights(){const a=ext.analytics||{},temps=a.temperature_counts||{},phases=a.by_phase||{},reasons=a.lost_reasons||{};return `<div class="stats" style="margin:0 0 18px">${stat("Hot Leads",temps.Hot||0,"Qualified high-priority enquiries","♨","orange")}${stat("Applications Submitted",a.applications_submitted||0,"Verified submissions","▣","blue")}${stat("Visits Scheduled",a.visits_scheduled||0,"Planned campus visits","▤")}${stat("Overdue Tasks",a.overdue_tasks||0,"Needs counsellor action","◷","orange")}</div><div class="panel" style="padding:16px;margin-bottom:18px"><h3>Lead temperature distribution</h3>${Object.entries(temps).map(([k,v])=>`<span class="badge" style="margin:4px">${esc(k)} · ${Number(v)}</span>`).join("")}<h3>Admissions phases</h3>${Object.entries(phases).map(([k,v])=>`<div class="muted-note">${esc(k)} — ${Number(v)}</div>`).join("")}<h3>Lost reasons</h3>${Object.entries(reasons).map(([k,v])=>`<div class="muted-note">${esc(k)} — ${Number(v)}</div>`).join("")||"<div class=muted-note>No recorded lost reasons</div>"}</div>`;} 

const IMPORT_FIELDS=[
 ['timestamp','Timestamp'],['name','Student name'],['email','Email'],['phone','Phone'],
 ['city','City'],['state','State'],['grade','Class'],['campus','Campus'],
 ['remarks','Remarks / Follow-up Notes'],['guardian','Parent / Guardian'],['academic_year','Academic year']
];

function seatPlanning(){
 if(!isAdmin())return '<p>Seat planning requires a school administrator.</p>';
 const rows=ext.seatPlans||[],sla=ext.responseSla||{first_contact_minutes:120};
 return '<div class="page-card"><h2>Grade and academic-year seats</h2><p>Seat limits are school-specific. Enrollment is blocked when a configured grade and academic year is full.</p>'+
 '<form id="seatPlanForm"><div class="two-cols">'+
 input('Academic year (YYYY-YY)','academic_year','text','2027-28',true)+
 input('Class / Grade','grade','text','I',true)+
 input('Total seats','capacity','number','40',true)+'</div><button type="submit" class="btn primary">Save seat capacity</button></form>'+
 '<div class="table-scroll"><table class="lead-table"><thead><tr><th>Year</th><th>Grade</th><th>Seats</th><th>Enrolled</th><th>Remaining</th></tr></thead><tbody>'+
 rows.map(x=>'<tr><td>'+esc(x.academic_year)+'</td><td>'+esc(x.grade)+'</td><td>'+Number(x.capacity)+'</td><td>'+Number(x.enrolled)+'</td><td>'+Number(x.remaining)+'</td></tr>').join('')+
 '</tbody></table></div></div><div class="page-card"><h2>First-contact response SLA</h2><p>Track enquiries not contacted within the school target. Spreadsheet imports and demo enquiries are excluded.</p>'+
 '<form id="responseSlaForm">'+input('Minutes to first successful parent contact','first_contact_minutes','number',String(sla.first_contact_minutes),true)+
 '<button class="btn primary" type="submit">Save school SLA</button></form></div>';
}

function importPage(){
 if(!canImport())return '<p>This role cannot import admission records.</p>';
 const p=ext.importPreview, upload=ext.importUpload, result=ext.lastImport;
 const mapping=p?`<div class="page-card"><h3>Column mapping</h3><p>Check the detected columns before confirming. Re-run Preview after changing a mapping.</p>
 <div class="field-grid">${IMPORT_FIELDS.map(([key,label])=>`<div class="form-field"><label>${esc(label)}
 <select data-import-map="${key}"><option value="">Ignore column</option>
 ${p.headers.map((title,index)=>`<option value="${index}" ${p.mapping[key]===index?'selected':''}>${esc(title||'Column '+(index+1))}</option>`).join('')}</select></label></div>`).join('')}</div>
 <button class="btn small" data-action="import-preview">Recheck mapping</button></div>`:'';
 const sample=p?`<div class="page-card"><h3>Import preview</h3>
 <div class="stats">${stat('Total rows',p.total,'Rows with data','▤')}${stat('Valid',p.valid,'Ready for import','✓','green')}
 ${stat('Invalid',p.invalid,'Must be fixed before import','!','orange')}${stat('Existing matches',p.possible_duplicates,'Will be skipped','♧')}</div>
 <div class="table-scroll"><table class="lead-table"><thead><tr><th>Row</th><th>Student</th><th>Phone</th><th>Campus</th><th>Remark</th><th>Validation</th></tr></thead><tbody>
 ${p.samples.map(r=>`<tr><td>${Number(r.row_number)}</td><td>${esc(r.name)}</td><td>${esc(r.phone)}</td><td>${esc(r.campus)}</td><td>${esc(r.remarks)}</td><td>${esc(r.issues?.join('; ')|| (r.existing?'Existing student · skip':'Ready'))}</td></tr>`).join('')}</tbody></table></div>
 <p class="muted-note">Preview shows up to 25 rows. Confirmation checks every row again and makes one atomic school-scoped change. Imported records will not be opted in to messaging.</p>
 ${p.invalid===0?`<label class="import-confirm"><input id="sfImportConfirm" type="checkbox"> I checked the destination school, detected duplicates and historical remarks.</label>
 <button class="btn primary" data-action="import-commit">Confirm and import ${p.valid} valid enquiries</button>`:'<p>Correct the spreadsheet and preview again before importing.</p>'}
 </div>`:'';
 return `${pageHead('Import Enquiries','Import historical Excel or Google Sheet exports without modifying existing leads.',btn('⇩ Download Excel format','export-xlsx','small'))}
 <div class="page-card">
 <h2>Spreadsheet import</h2><p>Select the correct school workspace above. Upload a UTF-8 CSV or XLSX file (maximum 2.5 MB, 500 enquiry rows). Sheet column names such as Timestamp, Name, Phone, Class, Campus and Remarks are mapped automatically.</p>
 <div class="form-field"><label>Spreadsheet file (.csv or .xlsx)<input id="sfImportFile" type="file" accept=".csv,.xlsx" aria-label="Choose historical admissions spreadsheet"></label></div>
 ${upload?`<p class="muted-note">Selected: ${esc(upload.filename)}. Preview uses the selected file until you choose another.</p>`:''}
 <button class="btn primary" data-action="import-preview">Preview import</button>
 </div>${mapping}${sample}
 ${result?`<div class="page-card"><h3>Last confirmed batch</h3><p>${Number(result.imported)} enquiries created; ${Number(result.skipped)} existing matches skipped; ${Number(result.notes)} historical notes.</p>
 <p class="muted-note">Batch reference: ${esc(result.batch_guid)}. An administrator may roll back unchanged imported records within 30 days.</p>
 ${isAdmin()?`<button class="btn small" data-action="import-rollback" data-guid="${esc(result.batch_guid)}">Roll back this batch</button>`:''}</div>`:''}`;
}

function attributionReport(){
 const names={group_website:'Group website',individual_website:'Individual school website',chatbot:'AI chatbot',manual:'Manual enquiry',import:'Imported',other:'Other'};
 const bySource=new Map(),bySurface=new Map(),byPage=new Map(),byCampaign=new Map();
 const countInto=(map,key,lead)=>{const old=map.get(key)||{name:key,total:0,admitted:0,applied:0};old.total++;
  if(lead.status==='Enrolled')old.admitted++;
  if(['Applied','Application Started','Application Submitted','Documents Pending','Under Review','Offer Issued','Admission Confirmed','Enrolled'].includes(lead.status))old.applied++;
  map.set(key,old);};
 state.leads.forEach(l=>{countInto(bySource,names[l.source_type]||'Other',l);
  countInto(bySurface,l.form_type||'unknown',l);
  countInto(byPage,l.landing_page_version||'Unknown / other',l);
  countInto(byCampaign,(l.utm_source||'Unattributed')+' · '+(l.utm_campaign||'No campaign'),l);});
 const section=(title,map)=>'<div class="panel" style="padding:20px"><h3>'+esc(title)+'</h3>'+
  '<div class="table-scroll"><table class="lead-table"><thead><tr><th>Channel</th><th>Leads</th><th>Applied*</th><th>Enrolled</th><th>Enrollment rate</th></tr></thead><tbody>'+
  [...map.values()].sort((a,b)=>b.total-a.total).map(row=>'<tr><td>'+esc(row.name)+'</td><td>'+row.total+'</td><td>'+row.applied+'</td><td>'+row.admitted+'</td><td>'+Math.round(100*row.admitted/Math.max(1,row.total))+'%</td></tr>').join('')+
  '</tbody></table></div></div>';
 return '<section style="margin:20px 0"><h2>Lead attribution & conversion</h2><p class="muted-note">Counts are enquiries, not unique families. Applied* means currently at an application-or-later stage, not verified application submissions. Group routes and manually submitted API leads are identified from CRM source records; unknown historical form surfaces remain unknown.</p>'+
  '<div class="two-cols">'+section('By origin',bySource)+section('By form surface',bySurface)+'</div><div style="margin-top:16px">'+section('By landing page version',byPage)+'</div><div style="margin-top:16px">'+section('By campaign',byCampaign)+'</div></section>';
}
function reportsPage(){const total=state.leads.length, enrolled=state.leads.filter(l=>l.status==='Enrolled').length, byStage=STAGES.map(s=>({name:s,n:state.leads.filter(l=>l.status===s).length})), max=Math.max(1,...byStage.map(x=>x.n));
 return `${pageHead('Admissions reports','Source attribution and live funnel performance.',btn('⇩ Export Excel','export-xlsx','primary')+btn('CSV','export-csv','small'))}${admissionInsights()}${attributionReport()}<div class="stats" style="margin:0 0 20px">${stat('Total enquiries',total,'All recorded enquiries','♧')}${stat('Enrolled',enrolled,'Successful admissions','✓','green')}${stat('Conversion rate',total?Math.round(enrolled/total*100)+'%':'0%','Enrolled / total enquiries','↗','blue')}${stat('Active sources',state.sites.filter(s=>s.enabled).length,'Connected intake channels','◉','orange')}</div><div class="two-cols"><div class="panel" style="padding:22px"><h3>Admissions funnel</h3><p class="muted-note">Enquiries currently in each stage</p>${byStage.map(x=>`<div class="bar-row"><span>${esc(x.name)}</span><div class="bar-track"><i style="width:${x.n/max*100}%"></i></div><b>${x.n}</b></div>`).join('')}</div><div class="panel" style="padding:22px"><h3>Lead sources</h3><p class="muted-note">Captured by connected website</p>${state.sites.map(s=>`<div class="site-list-card"><div class="site-icon">${s.platform==='WordPress'?'W':'⌁'}</div><div style="flex:1"><b>${esc(s.name)}</b><div class="muted-note">${esc(s.domain)}</div></div><b>${s.lead_count}</b></div>`).join('')||'<p class="muted">No website data yet.</p>'}</div></div><section class="panel" style="padding:19px;margin-top:18px"><h3>Performance by counsellor</h3><div class="report-staff-grid">${Object.entries(ext.analytics.by_staff||{}).map(([id,n])=>`<div class="v2-metric"><b>${n}</b><span>${esc(id==='unassigned'?'Unassigned':userName(Number(id)))} enquiries</span></div>`).join('')||'<p class="muted">No assignments yet</p>'}</div><p class="muted-note">First contact pending: ${ext.analytics.first_contact_pending||0} · Follow-ups due: ${ext.analytics.followups_due||0}</p></section>${isAdmin()?`<section class="audit-school aria-label="School activity log"><div class="section-head"><div><h2>School activity log</h2><p>Admin-only history of important account, website and enquiry actions.</p></div></div><div class="panel" style="padding:18px 20px">${state.audit?auditEntries(state.audit.events):'<p class="muted">Loading audit history…</p>'}${state.audit?.has_more?'<div class="audit-more"><button class="btn small" data-action="more-school-audit">Load older activity</button></div>':''}</div></section>`:''}`;}

function schoolVisits(){
 const v=ext.visits.filter(x=>!ext.visitMonth||x.starts_at.startsWith(ext.visitMonth));
 const days=Array.from({length: new Date(Number(ext.visitMonth.slice(0,4)),Number(ext.visitMonth.slice(5)),0).getDate()},(_,i)=>`${ext.visitMonth}-${String(i+1).padStart(2,'0')}`);
 return `${pageHead('School visit calendar','Schedule and track admission visits and parent counselling meetings.',btn('＋ Book visit','book-visit','primary'))}<div class="v2-toolbar"><label>Month <input id="visitMonth" type="month" value="${esc(ext.visitMonth)}"></label><span>${v.length} visit(s)</span></div><div class="visit-calendar">${days.map(d=>`<div class="visit-day"><strong>${esc(d.slice(8))}</strong>${v.filter(x=>x.starts_at.slice(0,10)===d).map(x=>`<button class="visit-chip" data-action="view-lead" data-id="${x.lead_id}" title="Open enquiry">${esc(x.starts_at.slice(11))} ${esc(short(x.student_name,17))}</button>`).join('')}</div>`).join('')}</div><h3 class="section-title">Visits this month</h3><div class="panel table-scroll"><table class="lead-table"><thead><tr><th>Visit</th><th>Student</th><th>Purpose</th><th>Counsellor</th><th>Status</th><th>Feedback</th><th>Action</th></tr></thead><tbody>${v.map(x=>`<tr><td>${esc(x.starts_at.replace('T',' '))}</td><td>${esc(x.student_name)}</td><td>${esc(x.purpose)}</td><td>${esc(x.staff_name)}</td><td>${esc(x.status)}</td><td>${esc(x.feedback||'—')}</td><td>${x.status==='Scheduled'?`<button class="btn small" data-action="visit-status" data-id="${x.id}" data-status="Completed">Mark completed</button> <button class="btn small" data-action="visit-status" data-id="${x.id}" data-status="No Show">No show</button>`:''}</td></tr>`).join('')||'<tr><td colspan="6">No visits for this month.</td></tr>'}</tbody></table></div><p class="muted-note">Times shown in school-local format. No automated external messages are sent.</p>`;
}
function familyPage(){return `${pageHead('Parent & sibling profiles','Families are grouped by a shared normalized phone number.')}<div class="family-grid">${ext.families.map(f=>`<article class="panel family-card"><h3>${esc(f.guardian)}</h3><p>${esc(f.phone)} · ${esc(f.email||'No email')}</p><small class="muted-note">${esc(f.link_status||"Unverified phone grouping")}</small><b>${f.count} student(s)</b>${f.children.map(c=>`<button data-action="view-lead" data-id="${c.id}" class="family-child">${esc(c.name)} · ${esc(c.grade||'—')} <span>${esc(c.status)} →</span></button>`).join('')}</article>`).join('')||'<div class="empty-state">No parent records yet.</div>'}</div><p class="muted-note">Family grouping uses the telephone number already collected; it does not merge or delete enquiries.</p>`;}
function automationsPage(){const policy=ext.autoPolicy||{},queue=ext.autoQueue||[];return `<div class="page-card"><h2>Admission reminders</h2><p>Notifications are disabled until a school administrator opts in. Each parent must also have channel-specific consent; provider acceptance is not delivery.</p><form id="autoSettingsForm"><label><input type="checkbox" name="enabled" ${policy.enabled?"checked":""}> Enable admission reminders for this school</label><div class="two-cols"><label><input type="checkbox" name="email" ${policy.email?"checked":""}> Resend email</label><label><input type="checkbox" name="whatsapp" ${policy.whatsapp?"checked":""}> Meta WhatsApp approved template</label></div><div class="form-field"><label>Reminder task types</label>${["First Contact","Follow-up","Campus Visit"].map(v=>`<label><input type="checkbox" name="task_kinds" value="${esc(v)}" ${(policy.task_kinds||[]).includes(v)?"checked":""}> ${esc(v)}</label>`).join("")}</div><div class="two-cols">${input("Allowed sending from (IST)","send_start","time",policy.send_start||"09:00")}${input("Allowed sending until (IST)","send_end","time",policy.send_end||"18:00")}</div>${input("Maximum automated messages per day","max_daily","number",String(policy.max_daily||10))}<button class="btn primary" type="submit">Save policy</button></form><h3>Unanswered-call retry schedule</h3><form id="retryPolicyForm"><p>After the first, second and third unsuccessful calls, schedule the next attempt after these numbers of days.</p><div class="two-cols">${[0,1,2].map(i=>input("Attempt "+(i+1)+" retry days","retry"+(i+1),"number",String((ext.followupPolicy?.retry_days||[1,2,4])[i]))).join("")}</div><button class="btn small" type="submit">Save retry schedule</button></form><h3>Notification queue</h3><div class="table-scroll"><table class="lead-table"><thead><tr><th>Time</th><th>Type</th><th>Channel</th><th>Status</th><th>Provider ID</th></tr></thead><tbody>${queue.map(q=>`<tr><td>${esc(auditStamp(q.created_at))}</td><td>${esc(q.kind)}</td><td>${esc(q.channel)}</td><td>${esc(q.status)}</td><td>${esc(q.provider_id||"—")}</td></tr>`).join("")}</tbody></table></div><h3>Provider evidence reconciliation</h3><form id="autoReconcileForm">${input("Queue record ID","queue_id","number","",true)}${select("Provider-confirmed status","status",["delivered","read","failed"].map(v=>option(v,v,"")).join(""))}${input("Provider evidence reference","provider_evidence_reference","text","",true)}<button class="btn small" type="submit">Record provider evidence</button></form><p class="muted-note">A Cloudways cron job must run the SchoolFlow PHP automation worker; enabling this screen alone never schedules a server job.</p></div>`;} 
function communicationsPage(){
 const m=ext.messaging||{}, email=m.email||{},wa=m.whatsapp||{};
 return `${pageHead('Communication connections','Connect official email and WhatsApp services for your selected school.')}
 <div class="v2-metrics"><div class="v2-metric"><b>${email.connected?'Configured':'Not configured'}</b><span>Resend Email API</span></div><div class="v2-metric"><b>${wa.connected?'Configured':'Not configured'}</b><span>Meta WhatsApp Cloud API</span></div></div>
 <div class="app-alert">Manual WhatsApp and email drafts remain available even without API integration. Real API sends require contact consent, explicit confirmation and approved provider settings. Provider acceptance is not delivery confirmation.</div>
 ${isAdmin()?`<form id="communicationsForm" class="panel" style="padding:21px;max-width:830px">
 <h3>Official Email API — Resend</h3>
 ${select('Email provider','email_provider',[['off','Disabled'],['resend','Resend API']].map(([k,v])=>option(k,v,email.provider)).join(''))}
 ${input('Verified sender email','email_from','email',email.from||'')}
 <div class="form-field"><label>Resend API key (leave blank to keep current)</label><input name="email_api_key" type="password" autocomplete="new-password" placeholder="re_..." maxlength="1024"></div>
 <h3>WhatsApp Business Platform — Meta Cloud API</h3>
 ${select('WhatsApp provider','wa_provider',[['off','Disabled'],['meta','Meta Cloud API']].map(([k,v])=>option(k,v,wa.provider)).join(''))}
 ${input('Meta phone number ID','phone_number_id','text',wa.phone_number_id||'')}
 ${input('Approved WhatsApp template (no variables)','wa_template','text',wa.template||'')}
 ${input('Template language','wa_language','text',wa.language||'en_US')}
 <div class="form-field"><label>Meta permanent system-user access token (leave blank to keep current)</label><input name="wa_api_key" type="password" autocomplete="new-password" placeholder="EAA..." maxlength="1024"></div>
 <button class="btn primary" type="submit">Save secure connections</button><p class="muted-note">Credentials are encrypted under the private application directory and are never returned to the browser. Keep private_html/scrm/data/messaging.key in protected backups.</p>
 </form>`:''}
 <div class="page-card" style="margin-top:16px"><h3>Official setup and custom API</h3><p>Create a verified sender domain and sending API key in Resend. For WhatsApp, create a Meta Business account, register a phone number, generate a long-lived system-user token and get an approved no-variable template. Use the corresponding provider settings above. Custom webhook APIs can be integrated later using a reviewed server-only adapter; arbitrary URLs are intentionally not accepted here.</p></div>`;
}
function templatesPage(){return `${pageHead('Email templates','Build personalized drafts; optionally send directly from a configured provider in the enquiry sidebar.',isAdmin()?btn('＋ New template','new-template','primary'):'')}<div class="family-grid">${ext.templates.map(t=>`<article class="panel family-card"><h3>${esc(t.title)}</h3><p><strong>Subject:</strong> ${esc(t.subject)}</p><div class="template-preview">${esc(t.body)}</div>${isAdmin()&&t.id?btn('Edit template','edit-template','small',`data-id="${t.id}"`):''}</article>`).join('')}</div><div class="footer-tip">Personalized variables: {parent}, {student}, {school}, {grade}. Only mailto links are opened after you choose a parent with a valid email and contact consent.</div>`;}
function contactActions(l){
 return `<section class="contact-actions"><h3 class="section-title">Parent communication</h3><div class="button-row">${btn('☎ Call','call-parent','small',`data-id="${l.id}"`)}${btn('WhatsApp ↗','whatsapp','small',`data-id="${l.id}" ${l.consent?'':'disabled title="Guardian consent required"'}`)}${btn('✉ Email draft','email-draft','small',`data-id="${l.id}" ${l.consent&&l.email?'':'disabled title="Email and contact consent required"'}`)}${ext.messaging?.email?.connected?btn('Send email via API','api-send-email','primary small',`data-id="${l.id}" ${l.consent&&!l.is_demo&&l.email?'':'disabled'}`):''}${ext.messaging?.whatsapp?.connected?btn('Send WhatsApp template','api-send-wa','primary small',`data-id="${l.id}" ${l.consent&&!l.is_demo?'':'disabled'}`):''}</div>${l.consent&&l.email?`<div class="form-field" style="margin-top:12px"><label>Email template</label><select id="emailTemplateChoice">${ext.templates.map(t=>option(t.id,t.title,ext.templates[0]?.id)).join('')}</select></div>`:''}<p class="muted-note">Draft buttons open apps only. API send buttons submit to your configured provider after confirmation. Consent required. Demo contacts cannot receive API messages.</p></section>`;
}
function familyDetails(d){const f=d.family||{children:[]};const l=d.lead;
 return `<section class="family-details"><h3 class="section-title">Parent & sibling profile</h3><p class="muted-note">${esc(f.guardian||l.guardian)} · ${esc(f.phone||l.phone)} · ${f.count||1} connected enquiry(ies)</p>${(f.children||[]).map(c=>`<div class="button-row family-child" style="justify-content:space-between"><span>${esc(c.name)} · ${esc(c.grade||'—')} (${esc(c.status)})</span>${c.id!==l.id?btn('View','view-lead','small',`data-id="${c.id}"`):'<span class="muted-note">Current</span>'}</div>`).join('')}<form id="siblingForm" class="two-cols" style="margin-top:12px">${input('Sibling name','student_name','text','',true)}${input('Grade','grade','text','',true)}<button class="btn small" type="submit">＋ Add sibling enquiry</button></form></section>`;
}
function callVisitDetails(d){const l=d.lead;
 return `<section class="call-management"><h3 class="section-title">Record a call</h3><form id="callForm">${select('Call outcome','outcome',['Answered','No Answer','Busy','Interested','Not Interested','Callback Requested'].map(x=>option(x,x,'Answered')).join(''))}${input('Next follow-up date (optional)','followup_date','date','')}<div class="form-field"><label>Call summary (optional)</label><textarea name="note" maxlength="1000" placeholder="Optional internal call notes"></textarea></div><button class="btn primary" type="submit">Save call outcome</button></form><div class="call-history">${(d.calls||[]).slice(0,6).map(c=>`<div class="audit-entry"><strong>${esc(c.outcome)}</strong><div class="muted-note">${esc(auditStamp(c.created_at))}${c.followup_date?' · Next '+esc(c.followup_date):''}</div>${c.note?`<p>${esc(c.note)}</p>`:''}</div>`).join('')||'<p class="muted-note">No calls recorded.</p>'}</div><h3 class="section-title">Campus visits</h3><div class="button-row">${btn('＋ Book visit','book-visit','small',`data-id="${l.id}"`)}</div>${(d.visits||[]).map(v=>`<div class="audit-entry">${esc(v.starts_at.replace('T',' '))} · ${esc(v.purpose)} · ${esc(v.status)}</div>`).join('')||'<p class="muted-note">No campus visits booked.</p>'}</section>`;
}

function modalFrame(title,subtitle,inner){return `<div class="modal-wrap" data-action="close-modal" id="modalWrap"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="sf-modal-title"><div class="modalhead"><div><h2 id="sf-modal-title">${esc(title)}</h2><p>${esc(subtitle)}</p></div><button class="icon-btn" aria-label="Close dialog" data-action="close-modal">✕</button></div><div style="padding:21px 24px">${inner}</div></div></div>`;}
function modal(){if(!state.modal)return '';
 if(state.modal==='new-lead')return modalFrame('New enquiry','Add a phone call, walk-in or manually received lead.',`<form id="leadForm"><div class="two-cols">${input('Student name','student_name','text','',true)}${input('Parent / guardian name','parent_name','text','',true)}${input('Contact number','phone','tel','',true)}${input('Interested class','grade','text','',false)}${input('Email (optional)','email','email')}${input('Follow-up date','followup_date','date')}${select('Website source','site_id',siteOptions())}${role()==='Counsellor'?'':select('Assign to','assigned_to',memberOptions())}</div><div class="form-field"><label>Additional message</label><textarea name="message" placeholder="Reason for enquiry, preferred campus, admission details..."></textarea></div><label style="font-size:12px;color:#657087"><input type="checkbox" name="consent" value="true"> Parent has consented to be contacted</label><div class="button-row" style="margin-top:20px;justify-content:flex-end">${btn('Cancel','close-modal') }<button class="btn primary" type="submit">Save enquiry</button></div></form>`);
 if(state.modal==='new-site')return modalFrame('Connect website','Create a separate secret integration for each site.',`<form id="siteForm">${input('Website / form name','name','text','',true)}${input('Website domain','domain','text','',true)}${select('Platform','platform',['WordPress','Custom website','Landing page','Other'].map(s=>option(s,s,'WordPress')).join(''))}<div class="button-row" style="justify-content:flex-end">${btn('Cancel','close-modal')}<button class="btn primary" type="submit">Create API key</button></div></form>`);
 if(state.modal==='edit-staff'){
   const staff=state.members.find(m=>m.id===ext.editMemberId);
   if(!staff)return '';
   return modalFrame('Edit staff account','Update school role and account details.',`<form id="editStaffForm">
   ${input('Full name','name','text',staff.name,true)}
   ${input('Work email','email','email',staff.email,true)}
   ${select('School role','role',['Counsellor','Admissions','Admin'].map(r=>option(r,r,staff.role)).join(''))}
   <p class="muted-note">Multi-school identities must be edited centrally. Role changes revoke existing sessions.</p>
   <div class="button-row">${btn('Cancel','close-modal')}<button type="submit" class="btn primary">Save staff changes</button></div></form>`);
 }
 if(state.modal==='change-my-password')return modalFrame('Change my password','All existing sessions will be revoked after saving.',`<form id="ownPasswordForm">
   ${input('Current password','current_password','password','',true)}
   ${input('New password (12+ characters)','new_password','password','',true)}
   ${input('Confirm new password','confirm_password','password','',true)}
   <div class="button-row">${btn('Cancel','close-modal')}<button class="btn primary" type="submit">Change password</button></div></form>`);
 if(state.modal==='new-member')return modalFrame('Add staff account','Create access for a counsellor, admissions member or admin.',`<form id="memberForm">${input('Staff full name','name','text','',true)}${input('Email for login','email','email','',true)}${input('Initial password (12+ characters for new account)','password','password','',true)}${select('School role','role',['Counsellor','Admissions','Admin'].map(s=>option(s,s,'Counsellor')).join(''))}<div class="footer-tip" style="margin-bottom:13px">If the email already has a SchoolFlow account, it is linked to this school without changing that user's password.</div><div class="button-row" style="justify-content:flex-end">${btn('Cancel','close-modal')}<button class="btn primary" type="submit">Add member</button></div></form>`);
 if(state.modal==='book-visit')return modalFrame('Book school visit','Schedule a parent campus tour or admissions consultation.',`<form id="visitForm">${select('Student enquiry','lead_id',state.leads.map(l=>option(l.id,l.name+' · '+l.guardian,state.visitLeadId||state.detail?.lead?.id)).join(''))}${input('Visit date and time','starts_at','datetime-local','',true)}${input('Purpose','purpose','text','Campus tour',true)}${role()==='Counsellor'?'':select('Assigned staff','staff_id',memberOptions(state.detail?.lead?.assigned_to))}<button class="btn primary" type="submit">Book visit</button></form>`);
 if(state.modal==='new-template'||state.modal==='edit-template'){
  const t=ext.templates.find(x=>x.id===state.editTemplateId)||{title:'',subject:'',body:''};
  return modalFrame(state.modal==='edit-template'?'Edit email template':'New email template','For parent-approved communications only.',`<form id="templateForm">${input('Template title','title','text',t.title,true)}${input('Email subject','subject','text',t.subject,true)}<div class="form-field"><label>Message</label><textarea name="body" required maxlength="4000" rows="7">${esc(t.body)}</textarea></div><button class="btn primary" type="submit">Save template</button></form>`);
 }
 if(state.modal==='new-group'||state.modal==='edit-group'){
   const g=state.modal==='edit-group'?ext.groupSites.find(x=>x.id===ext.editGroupId):null;
   return modalFrame(g?'Edit group website':'Connect group website','Choose the schools available in the parent enquiry dropdown.',`<form id="groupSiteForm">${input('Group website name','name','text',g?.name||'',true)}${input('Website domain','domain','text',g?.domain||'',true)}${groupSchoolChecks(g?.schools.map(x=>x.school_id)||[])}<div class="button-row" style="justify-content:flex-end">${btn('Cancel','close-modal')}<button type="submit" class="btn primary">${g?'Save school routes':'Create group key'}</button></div></form>`);
 }
 if(state.modal==='edit-site'){
  const site=state.sites.find(x=>x.id===ext.editSiteId);if(!site)return '';
  return modalFrame('Edit website','Update website details without changing its API key.',`<form id="editSiteForm">
  ${input('Website name','name','text',site.name,true)}
  ${input('Domain','domain','text',site.domain,true)}
  ${select('Platform','platform',['WordPress','Custom website','Landing page','Other'].map(v=>option(v,v,site.platform)).join(''))}
  <div class="button-row">${btn('Cancel','close-modal')}<button class="btn primary" type="submit">Save website</button></div></form>`);
 }
 if(state.modal==='edit-school'){
  const sc=(ext.schoolsOverview||[]).find(x=>x.id===ext.editSchoolId);if(!sc)return '';
  return modalFrame('Edit school','Rename the school without changing its records, routes or GUID.',`<form id="editSchoolForm">
  ${input('School name','name','text',sc.name,true)}
  <div class="button-row">${btn('Cancel','close-modal')}<button class="btn primary" type="submit">Save school</button></div></form>`);
 }
 if(state.modal==='new-school')return modalFrame('Add school workspace','Create another separate school with the current admin as its administrator.',`<form id="schoolForm">${input('New school name','name','text','',true)}<div class="button-row" style="justify-content:flex-end">${btn('Cancel','close-modal')}<button class="btn primary" type="submit">Create school</button></div></form>`);
 return '';
}
function detailDrawer(){const d=state.detail;if(!d)return '';const l=d.lead;
 return `<div class="drawer-overlay" id="drawerOverlay" data-action="close-detail"><div class="drawer" role="dialog" aria-modal="true" aria-labelledby="sf-drawer-title" style="overflow:auto;max-height:100vh"><div class="drawer-head"><div><div class="eyebrow">ENQUIRY ${esc(l.guid?'#'+l.guid.slice(0,8):'#'+l.id)}</div><h2 id="sf-drawer-title" style="margin:4px 0">${esc(l.name)}</h2><p>${esc(l.grade||'Class not specified')} · ${esc(l.source_name||'Manual entry')}</p></div><button class="icon-btn" aria-label="Close enquiry details" data-action="close-detail">✕</button></div><div class="detail-body"><div class="next-action-strip"><strong>Next action</strong><span>${esc(l.followup_status||"Pending Contact")}${l.followup_date?" · "+esc(dateFmt(l.followup_date)):" · Schedule the next contact or close the enquiry"}</span></div><nav class="detail-jump" aria-label="Enquiry sections"><a href="#sf-contact">Contact</a><a href="#sf-admission">Admission</a><a href="#sf-history">History</a></nav><div class="detail-meta" id="sf-contact"><div><small>Guardian</small><strong>${esc(l.guardian)}</strong></div><div><small>Contact</small><strong>${esc(l.phone)}</strong></div><div><small>Email</small><strong>${esc(l.email||'Not provided')}</strong></div><div><small>Received</small><strong>${esc(l.created_at.slice(0,16).replace('T',' '))} UTC</strong></div>${l.duplicate_of?`<div style="grid-column:1/-1"><small>Potential duplicate</small><strong>Same contact as enquiry #${l.duplicate_of}</strong></div>`:''}${l.message?`<div style="grid-column:1/-1"><small>Message</small><strong>${esc(l.message)}</strong></div>`:''}</div><section class="panel" style="padding:16px;margin:14px 0">
 <h3 style="margin:0 0 10px">Lead attribution</h3>
 <div class="detail-meta"><div><small>Origin</small><strong>${esc((l.source_type||'other').replaceAll('_',' '))}</strong></div>
 <div><small>Form surface</small><strong>${esc(l.form_type||'unknown')} ${esc(l.form_variant||'')}</strong></div>
 <div><small>Source website</small><strong>${esc(l.source_name||'Manual entry')}</strong></div>
 <div><small>Landing version</small><strong>${esc(l.landing_page_version||'Not identified')}</strong></div>
 <div style="grid-column:1/-1"><small>Landing URL</small><strong style="overflow-wrap:anywhere">${esc(l.landing_page||'Not recorded')}</strong></div>
 <div><small>Campaign source</small><strong>${esc(l.utm_source||'Not recorded')}</strong></div>
 <div><small>Campaign name</small><strong>${esc(l.utm_campaign||'Not recorded')}</strong></div>
 <div style="grid-column:1/-1"><small>Referrer</small><strong style="overflow-wrap:anywhere">${esc(l.referrer||'Not recorded')}</strong></div>
 <div><small>First touch</small><strong>${esc(l.first_touch?.source_type||'Not recorded')} ${esc(l.first_touch?.captured_at||'')}</strong></div>
 <div><small>Latest touch</small><strong>${esc(l.latest_touch?.source_type||'Not recorded')} ${esc(l.latest_touch?.captured_at||'')}</strong></div></div>
 <p class="muted-note">Historic source categories are inferred from saved routing; unavailable first-touch evidence is not reconstructed. Page and form labels can be client-reported and are not verified ad-platform conversions.</p>
 </section>${contactActions(l)}${(d.messages||[]).length?`<div class="page-card"><b>Message delivery attempts</b>${d.messages.map(x=>`<div class="muted-note">${esc(x.channel)} — ${esc(x.status)} · ${esc(auditStamp(x.created_at))} (provider receipt not guaranteed)</div>`).join('')}</div>`:''}${familyDetails(d)}${callVisitDetails(d)}<h3 class="section-title" id="sf-admission">Application progress</h3><div class="page-card">${(()=>{const app=(ext.applications||[]).find(a=>a.lead_id===l.id);return app?`<div><b>Application:</b> ${esc(app.status)}${app.fee_recorded?' · Fee recorded':''}</div><form id="applicationForm"><input type="hidden" name="application_id" value="${app.id}">${select('Application milestone','status',['Started','Submitted','Documents Pending','Under Review','Offer Issued','Admission Confirmed','Enrolled','Rejected'].map(v=>option(v,v,app.status)).join(''))}${role()==='Admin'||role()==='Admissions'?'<label><input type="checkbox" name="fee_recorded" value="true"> Fee verified and recorded</label>':''}<button class="btn small" type="submit">Save milestone</button></form>`:`<button class="btn small" data-action="create-application" data-id="${l.id}">Start application</button>`;})()}</div><h3 class="section-title">Admission document checklist</h3>
<div class="page-card"><p class="muted-note">Record documents received or verified. No student document files are uploaded to this CRM.</p>
${(d.documents||[]).map(doc=>`<div class="doc-status-row"><strong>${esc(doc.type)}</strong><span>${esc(doc.status)}</span>
${doc.status==='Verified'&&role()==='Counsellor'?'':`<form class="documentStatusForm">
<input type="hidden" name="type" value="${esc(doc.type)}">
${select('Status','status',['Pending','Received','Verified','Rejected'].filter(v=>v!=='Verified'||role()!=='Counsellor').map(v=>option(v,v,doc.status)).join(''))}
${input('Internal checklist note','note','text',doc.note||'')}
<button class="btn small" type="submit">Update</button></form>`}</div>`).join('')}</div><h3 class="section-title">Parent messaging consent</h3><form id="channelConsentForm"><label><input type="checkbox" name="consent_email" ${l.consent_email?"checked":""}> Parent explicitly opted in to email reminders</label><label><input type="checkbox" name="consent_whatsapp" ${l.consent_whatsapp?"checked":""}> Parent explicitly opted in to WhatsApp reminders</label>${input("Consent evidence/source","consent_source","text","",true)}<p class="muted-note">Only mark consent when you have valid parent authorization. The change is recorded in the audit log.</p><button class="btn small" type="submit">Record consent</button></form><h3 class="section-title">Admissions & assignment</h3><form id="editLeadForm">${select('Current stage','status',STAGES.map(s=>option(s,s,l.status)).join(''))}${select('Lead temperature','temperature',TEMPERATURES.map(t=>option(t,t,l.temperature||'Unrated')).join(''))}${input('Temperature override reason (required if changed)','temperature_reason','text','')}${select('Contact state','contact_state',CONTACT_STATES.map(t=>option(t,t,l.contact_state||'Pending Contact')).join(''))}${select('Lost reason','lost_reason',LOST_REASONS.map(t=>option(t,t||'Select if lost',l.lost_reason||'')).join(''))}${input('Academic year (YYYY-YY)','academic_year','text',l.academic_year||'')}${input('Deferred review date','deferred_until','date',l.deferred_until||'')}${input('Current school','current_school','text',l.current_school||'')}${input('Date of birth','date_of_birth','date',l.date_of_birth||'')}<div class="muted-note">Follow-up: ${esc(l.followup_status||'Pending Contact')} · Recommended: ${esc(l.temperature_suggestion||'Unrated')} · Calls: ${Number(l.contact_attempts||0)}</div><div class="muted-note">Recommendation evidence: ${esc(l.temperature_suggestion_reason||"Not assessed")}</div>${role()==='Counsellor'?'':select('Assigned counsellor / team','assigned_to',memberOptions(l.assigned_to))}${input('Next follow-up date','followup_date','date',l.followup_date)}<button class="btn primary full-btn" type="submit">Save changes</button></form><h3 class="section-title">Stage history</h3>${(d.stage_history||[]).map(h=>`<p class="muted-note">${esc(h.from_stage)} → ${esc(h.to_stage)} · ${esc(auditStamp(h.occurred_at))}</p>`).join("")||"<p class=muted-note>No recorded transitions yet</p>"}<h3 class="section-title">Conversation notes</h3><form id="noteForm"><div class="form-field"><textarea name="text" required placeholder="e.g. Parent requested school fees and campus visit..."></textarea></div><button class="btn small" type="submit">＋ Add note</button></form><h3 class="section-title" id="sf-history">Conversation history</h3>${d.activities.filter(a=>a.action==='note').map(a=>`<div class="timeline-item"><strong>Note added</strong>${a.details?`<div class="tiny" style="margin:5px 0;white-space:pre-wrap;overflow-wrap:anywhere">${esc(a.details)}</div>`:''}<small>${esc(a.actor_name||'Website / system')} · ${esc(auditStamp(a.created_at))}</small></div>`).join('')||'<p class="muted">No notes yet.</p>'}<section class="drawer-audit" aria-label="Enquiry audit log"><h3 class="section-title">Audit log</h3><p class="muted-note">Who changed or viewed this enquiry, with timestamps. Older history is retained.</p>${auditEntries(d.audit?.events||[])}${d.audit?.has_more?'<div class="audit-more"><button class="btn small" data-action="more-enquiry-audit">Load older activity</button></div>':''}</section></div></div></div>`;
}

async function loadPublicChat(){
 if(!isAdmin())return;
 const base='/api/schools/'+state.schoolId+'/public-chat';
 const [settings,provider,review,groups,report,sources,files,quality]=await Promise.all([
   api(base),api(base+'/provider'),api(base+'/review'),api('/api/group-sites'),
   api(base+'/counsellor-report'),api(base+'/sources'),api(base+'/knowledge-files'),api(base+'/quality')
 ]);
 ext.chatSettings=settings;ext.chatProvider=provider;ext.chatProviderTest=null;
 ext.chatQueue=review.items||[];ext.chatGroups=Array.isArray(groups)?groups:[];
 ext.counsellorReport=report;ext.chatSources=sources;ext.chatFiles=files;ext.chatQuality=quality;
 render();
}

const sfStarterQuestions=[
 ['How can I apply for admission?','Please contact the campus admissions team for the current application steps and required forms.'],
 ['Which classes accept admissions?','Available classes and eligibility vary by campus and academic year. The admissions team can confirm.'],
 ['What documents are required?','Please request the current official document checklist from the school admissions team.'],
 ['What is the admission fee structure?','Fees depend on the class and campus. Request the approved fee schedule from admissions.'],
 ['Can I schedule a campus visit?','Yes. Select Request admissions callback in this chat to arrange a visit with the school team.'],
 ['Are admissions currently open?','Please ask the admissions team to confirm current application dates and availability.'],
 ['Is school transport available?','Transport routes and availability must be confirmed by the school admissions team.'],
 ['How can I request a callback?','After registering your enquiry, select Request admissions callback in this chat.']
];

const schoolInfoCategories=[
 ['fees','Fees & charges'],['documents','Admission documents'],
 ['age_eligibility','Age eligibility'],['facilities','Facilities'],
 ['transport','Bus & transport'],['curriculum','Curriculum & board'],
 ['admissions','Admission process'],['timings','School timings'],
 ['school_features','Special features'],['contact','Contact details'],['other','Other approved information']
];
function schoolFactEditor(fact={}){
 const category=fact.category||'fees';
 return '<div class="sf-school-fact panel" style="padding:12px;margin-bottom:10px">'+
  (fact.needs_recheck?'<p role="alert" class="app-alert">The source page changed. This fact is excluded from chatbot answers until you explicitly verify it below.</p>':'')+
  '<input type="hidden" class="sf-fact-source" value="'+esc(fact.source_id||'')+'">'+
  (fact.needs_recheck?'<label><input type="checkbox" class="sf-fact-confirm"> I reviewed this fact against the updated website and approve its publication</label>':'')+
  '<div class="form-field"><label>Information category</label><select class="sf-fact-category">'+
  schoolInfoCategories.map(([code,label])=>option(code,label,category)).join('')+'</select></div>'+
  '<div class="form-field"><label>Campus / class / topic label</label><input class="sf-fact-label" maxlength="120" value="'+esc(fact.label||'')+'" placeholder="e.g. Nursery annual tuition 2026–27"></div>'+
  '<div class="form-field"><label>Verified school information</label><textarea class="sf-fact-details" maxlength="900" rows="3" placeholder="Enter verified amount, age rule, document list, bus coverage, facilities or other approved details">'+esc(fact.details||'')+'</textarea></div>'+
  '<button type="button" class="btn small" data-action="chat-remove-fact">Remove information entry</button></div>';
}

function publicChatPage(){
 if(!isAdmin())return '<div class="empty-state">School administrator access required.</div>';
 const settings=ext.chatSettings;
 if(!settings)return pageHead('AI Admission Counsellor','Loading the knowledge desk…');
 const tab=ext.chatTab||'Overview';
 const navTabs=[['Overview','Overview'],['School Knowledge','Knowledge Library'],['Questions & Answers','Questions & Answers'],['Leads & Conversations','Leads'],['Settings','Settings']];
 const files=ext.chatFiles?.files||[],sources=ext.chatSources?.sources||[],
  candidates=ext.chatSources?.candidates||[],faqs=settings.faqs||[],review=ext.chatQueue||[],
  topics=ext.counsellorReport?.topics||[];
 const groups=(ext.chatGroups||[]).filter(g=>(g.schools||[]).some(x=>Number(x.school_id)===Number(state.schoolId)));
 const leads=state.leads.filter(x=>!!x.chatbot_lead);
 const activeFiles=files.filter(x=>x.status==='published'),draftFiles=files.filter(x=>x.status==='pending');
 const guide='<div class="ai-guidance"><strong>School-safe publishing:</strong> Uploads and scanned facts remain private until an administrator reviews and publishes them. Year-specific fees, age rules and policies must match the selected campus and academic session.</div>';
 const metrics='<div class="ai-kpi-grid">'+[
  ['Knowledge files',activeFiles.length,files.length-activeFiles.length+' awaiting publication'],
  ['Approved answers',faqs.length,'Reusable without AI generation'],
  ['Questions to review',review.length+candidates.length,'Unanswered or unverified facts'],
  ['Chatbot leads',leads.length,'Converted parent enquiries'],
  ['AI topics to review',Number(ext.chatQuality?.groups_needing_review||0),'Review latest AI or general-guidance answers']
 ].map(x=>'<div class="ai-kpi"><span>'+esc(x[0])+'</span><strong>'+Number(x[1])+'</strong><small>'+esc(x[2])+'</small></div>').join('')+'</div>';
 const fileList=files.length?'<div class="ai-file-list">'+files.map(file=>
  '<article class="ai-file-card">'+
   '<div class="ai-file-icon" aria-hidden="true">MD</div>'+
   '<div class="ai-file-main"><div class="ai-file-head"><strong>'+esc(file.filename)+'</strong>'+
   '<span class="ai-status '+(file.status==='published'?'published':'pending')+'">'+(file.status==='published'?'Published':'Needs review')+'</span></div>'+
   '<div class="ai-item-meta">'+Math.round(Number(file.bytes||0)/1024)+' KB · Uploaded '+esc(file.created_at||'—')+
   (file.published_at?' · Published '+esc(file.published_at):'')+'</div>'+
   '<details class="ai-file-preview"><summary>Preview and section outline</summary>'+
    '<div class="ai-collection">'+(file.sections||[]).slice(0,14).map(x=>'<span>'+esc(x)+'</span>').join('')+'</div>'+
    '<pre>'+esc(file.preview||'')+'</pre></details>'+
   '<div class="ai-item-actions">'+
    (file.status==='published'
     ?'<button type="button" class="btn small" data-action="chat-file-unpublish" data-id="'+esc(file.id)+'">Unpublish</button>'
     :'<button type="button" class="btn small primary" data-action="chat-file-publish" data-id="'+esc(file.id)+'">Review & publish</button>')+
    '<button type="button" class="btn small ai-danger" data-action="chat-file-delete" data-id="'+esc(file.id)+'">Delete file</button>'+
   '</div></div></article>').join('')+'</div>':
   '<div class="ai-empty"><div aria-hidden="true" class="ai-empty-mark">▤</div><strong>No knowledge files yet</strong>'+
   '<p>Upload school-approved Markdown or text. You can inspect it before publishing to parents.</p></div>';
 const knowledgeReadiness=activeFiles.length
  ?'<div class="ai-guidance" role="status"><b>Knowledge ready:</b> '+activeFiles.length+' published document(s) for this campus. Only published files are used in parent answers.</div>'
  :'<div class="ai-guidance" role="alert"><b>No published knowledge files for this campus.</b> '+(draftFiles.length
    ?draftFiles.length+' file(s) uploaded but awaiting Review &amp; publish. These documents are not used in chat yet.'
    :'Upload and publish a school knowledge document to enable verified file-based answers.')+'</div>';
 const knowledgeTest='<form id="chatKnowledgeTest" class="ai-add-faq">'+
  '<h4>Test a published knowledge answer</h4>'+
  '<p class="ai-muted">Checks the selected campus’s live published documents using the same factual lookup as the parent chatbot. No AI call or parent details are involved.</p>'+
  '<label>Sample parent question<input type="text" name="question" maxlength="200" required value="Class II annual fees 2026–27"></label>'+
  '<button type="submit" class="btn small primary">Test knowledge</button>'+
  (ext.chatKnowledgeTest?'<div class="ai-guidance" role="status"><strong>'+esc(ext.chatKnowledgeTest.status||'')+
    ' · '+Number(ext.chatKnowledgeTest.published_files||0)+' published file(s)</strong>'+
    '<p>'+esc(ext.chatKnowledgeTest.answer||'')+'</p></div>':'')+'</form>';
 const upload='<section class="ai-surface"><div class="ai-section-head"><div><span class="ai-eyebrow">01 · PRIMARY KNOWLEDGE</span>'+
  '<h3>School knowledge files</h3><p>Give your counsellor the facts it needs—fee tables, age criteria, documents, transport and school features.</p></div>'+
  '<span class="ai-status neutral">'+activeFiles.length+' live</span></div>'+
  '<form id="chatKnowledgeUpload" class="ai-upload"><div class="ai-upload-icon" aria-hidden="true">↑</div>'+
  '<div><strong>Choose a knowledge file to upload</strong><p>Markdown (.md) or plain text (.txt), maximum 120 KB. Up to 8 files per school.</p>'+
  '<input type="file" name="knowledge_file" accept=".md,.txt,text/markdown,text/plain" required aria-label="Choose school knowledge file"></div>'+
  '<button type="submit" class="btn primary">Upload for review</button></form>'+knowledgeReadiness+fileList+knowledgeTest+'</section>';
 const sourceList=sources.length?sources.map(src=>
  '<article class="ai-record"><div class="ai-record-body"><strong>'+esc(src.url)+'</strong>'+
  '<small>'+esc(src.status||'not_scanned')+' · Last checked '+esc(src.last_checked_at||'Never')+'</small></div>'+
  '<div class="ai-item-actions"><button type="button" class="btn small" data-action="chat-source-scan" data-id="'+esc(src.id)+'">Scan again</button>'+
  '<button type="button" class="btn small ai-danger" data-action="chat-source-remove" data-id="'+esc(src.id)+'">Remove</button></div></article>').join(''):
  '<p class="ai-muted">No website pages registered. A knowledge file is enough to get started.</p>';
 const reviewSources=candidates.length?candidates.map(item=>
  '<form class="sfSourceReview ai-record ai-review" data-id="'+esc(item.id)+'">'+
  '<div class="ai-review-top"><span class="ai-status pending">Not published</span><small>'+esc(item.category)+'</small></div>'+
  '<label>Information label<input name="label" maxlength="120" required value="'+esc(item.label||'')+'"></label>'+
  '<label>School-verified details<textarea name="details" rows="3" maxlength="900" required>'+esc(item.details||'')+'</textarea></label>'+
  '<p class="ai-muted">Source excerpt: '+esc(item.evidence||'')+'</p>'+
  '<div class="ai-item-actions"><button type="submit" class="btn small primary">Approve fact</button>'+
  '<button type="button" class="btn small ai-danger" data-action="chat-source-reject" data-id="'+esc(item.id)+'">Reject</button></div></form>').join(''):
  '<p class="ai-muted">No extracted website facts are awaiting review.</p>';
 const website='<details class="ai-surface ai-advanced"><summary>Website URL sources and scanned facts <span class="ai-status neutral">'+sources.length+' URLs</span></summary>'+
  '<div class="ai-inside"><p class="ai-muted">Scan public HTTPS pages belonging to this school. Scans create proposals—not published facts.</p>'+
  '<form id="chatSourceAddForm" class="ai-inline-form"><input type="url" name="url" required maxlength="600" placeholder="https://school.example/admissions">'+
  '<button type="submit" class="btn small primary">Add website URL</button></form>'+sourceList+
  '<h4>Awaiting review</h4>'+reviewSources+'</div></details>';
 const manualFacts='<details class="ai-surface ai-advanced"><summary>Manage structured facts manually <span class="ai-status neutral">'+(settings.school_facts||[]).length+' entries</span></summary>'+
  '<div class="ai-inside"><p class="ai-muted">Use for grade-specific facts that need precise wording. Uploaded files offer an easier way to maintain long fee schedules.</p>'+
  '<form id="chatFactsForm"><div id="sfSchoolFacts">'+(settings.school_facts||[]).map(schoolFactEditor).join('')+'</div>'+
  '<div class="ai-item-actions"><button type="button" class="btn small" data-action="chat-add-fact">Add fact</button>'+
  '<button type="submit" class="btn small primary">Save structured facts</button></div></form></div></details>';
 const faqList=faqs.length?faqs.map((faq,i)=>
  '<details class="ai-qa-row" '+(i===0?'open':'')+'><summary><span class="ai-qa-index">'+String(i+1).padStart(2,'0')+'</span>'+
  '<span><strong>'+esc(faq.question)+'</strong><small>Approved · Reusable answer</small></span><span class="ai-chevron">⌄</span></summary>'+
  '<form class="sfFaqEditor ai-qa-editor" data-original-question="'+esc(faq.question)+'">'+
  '<label>Parent question<input name="question" maxlength="180" required value="'+esc(faq.question)+'"></label>'+
  '<label>Verified school answer<textarea name="answer" maxlength="800" rows="4" required>'+esc(faq.answer)+'</textarea></label>'+
  '<div class="ai-item-actions"><button type="submit" class="btn small primary">Save changes</button>'+
  '<button type="button" class="btn small ai-danger" data-action="chat-delete-faq" data-question="'+esc(faq.question)+'">Delete answer</button></div></form></details>').join(''):
  '<div class="ai-empty"><strong>No approved Q&As yet</strong><p>Publish an answer after verifying it against school information.</p></div>';
 const faqPanel='<section class="ai-surface"><div class="ai-section-head"><div><span class="ai-eyebrow">ANSWER LIBRARY</span><h3>Approved questions & answers</h3>'+
  '<p>Edit an answer or delete it directly. Changes are saved immediately.</p></div>'+
  '<span class="ai-status published">'+faqs.length+' approved</span></div>'+
  '<form id="chatAddFaqForm" class="ai-add-faq"><h4>Add a verified answer</h4>'+
  '<label>Parent question<input name="question" required maxlength="180" placeholder="e.g. What is the admission process for Nursery?"></label>'+
  '<label>School-approved answer<textarea name="answer" rows="3" required maxlength="800" placeholder="Enter an answer verified for this campus, grade and academic year."></textarea></label>'+
  '<button type="submit" class="btn primary">Publish answer</button></form>'+
  '<div class="ai-qa-list">'+faqList+'</div></section>';
 const mostAsked=topics.length?topics.map((item,i)=>
  '<article class="ai-topic"><div class="ai-topic-summary"><span class="ai-topic-count">'+Number(item.count||0)+'<small>asked</small></span>'+
   '<div class="ai-record-body"><strong>'+esc(item.topic.replaceAll('_',' '))+
   (item.grade?' · Class '+esc(item.grade):'')+(item.academic_year?' · '+esc(item.academic_year):'')+'</strong>'+
   '<small>Answered using '+esc(item.last_source||'general guidance')+'</small></div></div>'+
   '<details><summary>See last answer shown to parents</summary><div class="ai-answer-quote">'+esc(item.last_answer||'No answer recorded')+'</div></details>'+
   '<div class="ai-item-actions"><button type="button" class="btn small" data-action="chat-topic-draft" data-index="'+i+'">Use as FAQ draft</button>'+
   '<button type="button" class="btn small ai-danger" data-action="chat-topic-delete" data-index="'+i+'">Delete topic</button></div></article>').join(''):
  '<div class="ai-empty"><strong>No question trends yet</strong><p>Questions will be grouped here as parents engage with the counsellor.</p></div>';
 const unanswered=review.length?review.map(item=>
  '<form class="sfChatReview ai-review-card" data-id="'+esc(item.id)+'"><div class="ai-review-top">'+
  '<span class="ai-status pending">Needs answer</span><small>'+Number(item.count||1)+' enquiries</small></div>'+
  '<strong>'+esc(item.question)+'</strong>'+
  '<label>Verified response<textarea name="answer" maxlength="800" required rows="3" placeholder="Write an accurate school answer before publishing"></textarea></label>'+
  '<div class="ai-item-actions"><button type="submit" class="btn small primary">Approve & publish</button>'+
  '<button type="button" class="btn small" data-action="chat-reject-topic" data-id="'+esc(item.id)+'">Dismiss</button>'+
  '<button type="button" class="btn small ai-danger" data-action="chat-delete-unanswered" data-id="'+esc(item.id)+'">Delete</button></div></form>').join(''):
  '<div class="ai-empty"><strong>Nothing awaiting a response</strong><p>Unanswered questions from parents will appear here for review.</p></div>';
 const qa='<div class="ai-grid-two"><div class="ai-col">'+faqPanel+'</div><div class="ai-col">'+
  '<section class="ai-surface"><div class="ai-section-head"><div><span class="ai-eyebrow">PARENT INSIGHTS</span><h3>Most asked topics</h3>'+
  '<p>Grouped by topic, grade and academic year.</p></div><span class="ai-status neutral">'+topics.length+' topics</span></div>'+mostAsked+'</section>'+
  '<section class="ai-surface"><div class="ai-section-head"><div><span class="ai-eyebrow">EDITORIAL QUEUE</span><h3>Unanswered questions</h3>'+
  '<p>Approve, dismiss or permanently delete each question.</p></div><span class="ai-status pending">'+review.length+' pending</span></div>'+
  unanswered+'</section></div></div>';
 const provider='<form id="chatProviderForm" class="ai-surface ai-settings-form">'+
  '<div class="ai-section-head"><div><span class="ai-eyebrow">AI PROVIDER</span><h3>OpenRouter connection</h3>'+
  '<p>AI expands on approved school knowledge. A saved key alone does not mean the model works.</p></div>'+
  '<span class="ai-status '+(ext.chatProvider?.connected?'published':'pending')+'">'+(ext.chatProvider?.connected?'Key stored':'Not connected')+'</span></div>'+
  '<div class="ai-form-grid"><label>Model identifier<input name="model" maxlength="100" required value="'+esc(ext.chatProvider?.model||'openai/gpt-4o-mini')+'"></label>'+
  '<label>API key (blank retains existing)<input name="api_key" type="password" maxlength="1024" autocomplete="new-password" placeholder="sk-or-…"></label></div>'+
  '<label class="ai-checkbox"><input type="checkbox" name="remove_key"> Disconnect and remove saved OpenRouter key</label>'+
  (ext.chatProviderTest?'<p class="ai-guidance" role="status">'+esc(ext.chatProviderTest.message||'')+'</p>':'')+
  '<div class="ai-item-actions"><button type="submit" class="btn primary">Save provider</button>'+
  '<button type="button" class="btn small" data-action="chat-test-provider">Test connection</button></div></form>';
 const settingsForm='<form id="chatSettingsForm" class="ai-surface ai-settings-form">'+
  '<div class="ai-section-head"><div><span class="ai-eyebrow">ASSISTANT SETTINGS</span><h3>Behaviour & publishing</h3>'+
  '<p>Configure this campus only. Other school settings remain independent.</p></div></div>'+
  '<label class="ai-toggle"><span><strong>Enable the admission counsellor</strong><small>Make the assistant available on this campus website</small></span>'+
  '<input type="checkbox" name="enabled" '+(settings.enabled?'checked':'')+'></label>'+
  '<label class="ai-toggle"><span><strong>Allow external AI replies</strong><small>Use approved school information and general education knowledge</small></span>'+
  '<input type="checkbox" name="ai_enabled" '+(settings.ai_enabled?'checked':'')+'></label>'+
  '<div class="ai-form-grid"><label>Visitor remembrance period<select name="remember_days">'+
    option(15,'15 days',String(settings.remember_days||30))+option(30,'30 days',String(settings.remember_days||30))+'</select></label></div>'+
  '<label>Verified campus overview<textarea name="overview" maxlength="1500" rows="4">'+esc(settings.overview||'')+'</textarea></label>'+
  '<button type="submit" class="btn primary">Save school settings</button></form>';
 const websiteEmbed='<section class="ai-surface"><div class="ai-section-head"><div><span class="ai-eyebrow">WEBSITE INTEGRATION</span>'+
  '<h3>GUID-based embed</h3><p>One script loads the admission counsellor and existing enquiry form. There is no need to rotate the form ID.</p></div></div>'+
  (groups.length?groups.map(g=>{
    const snippet=sfGroupEmbed(g.guid);
    return '<div class="ai-embed"><strong>'+esc(g.name)+'</strong><small>'+esc(g.domain)+'</small>'+
      '<code>'+esc(snippet)+'</code><button type="button" class="btn small" data-action="chat-copy-embed" data-id="'+Number(g.id)+'">Copy code</button></div>';
  }).join(''):'<p class="ai-muted">Connect a group website under Schools & Routing to get a script.</p>')+'</section>';
 const leadPanel='<section class="ai-surface"><div class="ai-section-head"><div><span class="ai-eyebrow">ADMISSION PIPELINE</span>'+
  '<h3>Chatbot leads & follow-ups</h3><p>Anonymous visitors can ask questions freely. A CRM lead is created when the parent consents to follow-up.</p></div></div>'+
  '<div class="ai-kpi-grid">'+[['Converted leads',leads.length],['Anonymous conversations',Number(ext.counsellorReport?.anonymous_conversations||0)],
   ['Callbacks requested',leads.filter(l=>l.contact_state==='Callback Requested').length]].map(x=>
    '<div class="ai-kpi"><span>'+esc(x[0])+'</span><strong>'+Number(x[1])+'</strong></div>').join('')+'</div>'+
   '<div class="ai-item-actions"><button type="button" class="btn primary" data-action="chat-goto-leads">Open Chatbot Leads</button></div></section>';
 const quality=ext.chatQuality||{topics:[],data_limit:'Latest source only'};
 const reviewTopics=(quality.topics||[]).filter(x=>x.needs_review).slice(0,8);
 const qualityPanel='<section class="ai-surface"><div class="ai-section-head"><div><span class="ai-eyebrow">ANSWER QUALITY</span><h3>Topics needing school verification</h3>'+
  '<p>Check where the most recent response relied on AI or general guidance. This is not an accuracy score.</p></div><span class="ai-status pending">'+Number(quality.groups_needing_review||0)+' groups</span></div>'+
  (reviewTopics.length?'<div class="ai-qa-list">'+reviewTopics.map(x=>'<div class="ai-record"><div class="ai-record-body"><strong>'+
  esc(String(x.topic||'').replaceAll('_',' '))+'</strong><small>'+esc(x.grade||'Class not specified')+
  ' · '+esc(x.academic_year||'Year not specified')+' · '+Number(x.questions||0)+' questions · Last source: '+esc(x.latest_source||'unknown')+
  '</small></div></div>').join('')+'</div>':'<p class="ai-muted">No latest-response groups have been flagged for review.</p>')+'</section>';
 const overview='<div class="ai-overview">'+metrics+qualityPanel+
  '<div class="ai-grid-two"><section class="ai-surface ai-feature"><span class="ai-eyebrow">RECOMMENDED WORKFLOW</span>'+
  '<h3>Teach the counsellor once. Answer consistently.</h3>'+
  '<div class="ai-steps"><div><b>1</b><span><strong>Upload</strong><small>Add the school’s approved knowledge file</small></span></div>'+
  '<div><b>2</b><span><strong>Review</strong><small>Check the campus, academic year, fees and exceptions</small></span></div>'+
  '<div><b>3</b><span><strong>Publish</strong><small>Make verified material available to parents</small></span></div>'+
  '<div><b>4</b><span><strong>Improve</strong><small>Resolve unanswered questions and reuse approved answers</small></span></div></div>'+
  '<button type="button" class="btn primary" data-action="chat-open-knowledge">Manage school knowledge</button></section>'+
  '<section class="ai-surface"><span class="ai-eyebrow">ASSISTANT READINESS</span><h3>Current setup</h3>'+
  '<div class="ai-readiness"><span>Campus chatbot</span><b>'+ (settings.enabled?'Enabled':'Disabled')+'</b></div>'+
  '<div class="ai-readiness"><span>External AI</span><b>'+(settings.ai_enabled?'Enabled':'Disabled')+'</b></div>'+
  '<div class="ai-readiness"><span>OpenRouter key</span><b>'+(ext.chatProvider?.connected?'Saved':'Not configured')+'</b></div>'+
  '<div class="ai-readiness"><span>Published knowledge files</span><b>'+activeFiles.length+'</b></div>'+
  '<p class="ai-muted">Source uploads and AI-generated replies never become verified knowledge until an administrator publishes them.</p>'+
  '<button type="button" class="btn small" data-action="chat-open-settings">Review settings</button></section></div></div>';
 const content=tab==='Overview'?overview:
  tab==='School Knowledge'?guide+upload+website+manualFacts:
  tab==='Questions & Answers'?qa:
  tab==='Leads & Conversations'?leadPanel:settingsForm+provider+websiteEmbed;
 return '<div class="ai-workspace">'+
  '<header class="ai-hero"><div class="ai-hero-copy"><span class="ai-eyebrow">SCHOOLFLOW · ADMISSIONS INTELLIGENCE</span>'+
  '<h2>AI Admission Counsellor</h2><p>Verified school knowledge, natural conversations and qualified parent enquiries—all in one clear workspace.</p></div>'+
  '<div class="ai-hero-live"><span class="ai-led"></span>'+(settings.enabled?'Assistant published':'Assistant not published')+'</div></header>'+
  '<nav class="ai-nav" aria-label="Admissions AI sections">'+navTabs.map(([key,label])=>
    '<button type="button" class="ai-nav-item '+(tab===key?'active':'')+'" data-action="chat-select-tab" data-tab="'+esc(key)+'"'+
    (tab===key?' aria-current="page"':'')+'>'+esc(label)+'</button>').join('')+'</nav>'+
  '<div class="ai-workspace-body">'+content+'</div></div>';
}

function render(){if(state.setup===null){root.innerHTML='<div class="login-wrap"><div class="login-panel"><h2>Connecting to SchoolFlow…</h2><p>Checking your application setup.</p></div></div>';return;}if(state.setup.needs_setup||!state.token||!state.me){root.innerHTML=loginPage();return;}
 if(state.me.user.must_change_password){
  root.innerHTML='<div class="login-wrap"><div class="login-panel"><h2>Change temporary password</h2><p>Your administrator reset your password. You must choose a new password before accessing school records.</p>'+
  '<form id="mandatoryPasswordForm">'+input('Temporary password','current_password','password','',true)+
  input('New password (12+ characters)','new_password','password','',true)+
  input('Confirm new password','confirm_password','password','',true)+
  '<button class="btn primary full-btn" type="submit">Save new password</button></form><button class="btn small" data-action="logout">Sign out</button></div></div>';return;
 }
 const pages={'Dashboard':dashboard,'My Work':myWork,'Enquiries':enquiries,'Chatbot Leads':chatbotLeads,'Pipeline':pipeline,'Follow-ups':followups,'Tasks':tasksPage,'Websites & Forms':sitesPage,'Schools & Routing':routingPage,'School Visits':schoolVisits,'Families':familyPage,'Email Templates':templatesPage,'Communications':communicationsPage,'Automations':automationsPage,'Team':teamPage,'Reports':reportsPage,'Import Enquiries':importPage,'Seat Planning':seatPlanning,'Admissions AI':publicChatPage};
 root.innerHTML=`<div class="app">${nav()}<button type="button" class="nav-scrim" data-action="close-menu" aria-label="Close navigation"></button><div class="main">${topbar()}<main class="content">${(pages[state.page]||dashboard)()}</main></div></div>${modal()}${detailDrawer()}`;
}
async function loadRouting(){[ext.schoolsOverview,ext.groupSites]=await Promise.all([api('/api/schools/overview'),api('/api/group-sites')]);render();}
async function perform(task){try{await task();}catch(e){toast(e.message,true);}}
function closeDialog(){state.modal='';state.detail=null;render();}
async function xlsxExport(){
 const filtering=state.page==='Enquiries';
 const filters=filtering?{
   status:state.status,temperature:ext.temperatureFilter,site:state.siteFilter,
    source_type:ext.sourceTypeFilter,form_type:ext.formTypeFilter,
   assigned:ext.assignedFilter,contact:ext.contactFilter,due:ext.dueFilter,search:state.search
 }:{};
 const path='/api/schools/'+state.schoolId+'/exports/admissions.xlsx';
 let response;
 try{
   response=await fetch(APP_BASE+path,{
     method:'POST',
     headers:{Authorization:'Bearer '+state.token,'Content-Type':'application/json'},
     body:JSON.stringify(filters),cache:'no-store',credentials:'same-origin'
   });
 }catch(e){throw new Error('Could not connect to SchoolFlow for Excel export');}
 if(response.status===401){
   state.token='';state.me=null;sessionStorage.removeItem('schoolflow_session');
   render();throw new Error('Session expired. Sign in again to export.');
 }
 if(!response.ok){
   const data=await response.json().catch(()=>({}));
   throw new Error(data.detail||'Excel export failed ('+response.status+')');
 }
 const workbook=await response.blob();
 if(workbook.size<200)throw new Error('The Excel workbook was empty');
 const objectUrl=URL.createObjectURL(workbook),link=document.createElement('a');
 link.href=objectUrl;
 link.download='SchoolFlow-Admissions-'+state.schoolId+'-'+day()+'.xlsx';
 document.body.appendChild(link);
 link.click();
 link.remove();
 setTimeout(()=>URL.revokeObjectURL(objectUrl),1000);
 toast('Excel report downloaded: enquiries and follow-up history');
}
async function csvExport(){await api(`/api/schools/${state.schoolId}/audit`,'POST',{event:'export_enquiries'});const lines=[['ID','Student','Guardian','Phone','Email','Class','Source','Status','Assigned','Follow-up','Created','Potential Duplicate','Origin Type','Form Surface','Form Variant','Landing Version','Landing URL','Campaign Source','Campaign Name','First Touch Source','Latest Touch Source'],...state.leads.map(l=>[l.id,l.name,l.guardian,l.phone,l.email,l.grade,l.source_name||'Manual',l.status,l.assigned_name||'',l.followup_date,l.created_at,l.duplicate_of||'',l.source_type||'',l.form_type||'',l.form_variant||'',l.landing_page_version||'',l.landing_page||'',l.utm_source||'',l.utm_campaign||'',l.first_touch?.utm_source||'',l.latest_touch?.utm_source||''])];
 const csv='\ufeff'+lines.map(r=>r.map(v=>'"'+( /^[\s]*[=+\-@]/.test(String(v??'')) ? "\'"+String(v??'') : String(v??'') ).replaceAll('"','""')+'"').join(',')).join('\r\n');let url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));let a=document.createElement('a');a.href=url;a.download=`schoolflow-school-${state.schoolId}-enquiries.csv`;a.click();URL.revokeObjectURL(url);toast('CSV exported');}
root.addEventListener('click',e=>{let el=e.target.closest('[data-action]');if(!el)return;let action=el.dataset.action;

 if(action==='chat-add-fact'){
   const container=root.querySelector('#sfSchoolFacts');
   if(!container)return;
   if(container.querySelectorAll('.sf-school-fact').length>=36)return toast('Maximum 36 school information entries',true);
   container.insertAdjacentHTML('beforeend',schoolFactEditor());
   return;
 }
 if(action==='chat-remove-fact'){
   el.closest('.sf-school-fact')?.remove();return;
 }
 if(action==='chat-topic-draft'){
   const item=(ext.counsellorReport?.topics||[])[Number(el.dataset.index)];
   if(!item)return;
   ext.chatTab='Questions & Answers';render();
   const form=root.querySelector('#chatAddFaqForm');
   if(!form)return;
   const label=item.topic==='fees'?'What is the admission fee structure?':
     'What can you tell me about '+String(item.topic||'school admissions').replaceAll('_',' ')+'?';
   const qualifier=(item.grade?' Class '+item.grade+'.':'')+
     (item.academic_year?' Academic year '+item.academic_year+'.':'');
   form.elements.question.value=(label+qualifier).slice(0,180);
   form.elements.answer.value=String(item.last_answer||'').slice(0,800);
   form.scrollIntoView({block:'center',behavior:'smooth'});
   toast('Draft prepared. Verify school facts and academic year before publishing.');
   return;
 }
 if(action==='chat-open-knowledge'||action==='chat-open-settings'){
   ext.chatTab=action==='chat-open-knowledge'?'School Knowledge':'Settings';render();return;
 }
 if(action==='chat-file-publish'||action==='chat-file-unpublish'||action==='chat-file-delete'){
   if(!isAdmin())return;
   const id=el.dataset.id||'';
   const record=(ext.chatFiles?.files||[]).find(x=>x.id===id);
   if(!record)return toast('Knowledge file not found',true);
   const op=action==='chat-file-publish'?'publish':action==='chat-file-unpublish'?'unpublish':'delete';
   const text=op==='publish'
     ?'Confirm you have reviewed "'+record.filename+'" and it applies to the currently selected school. Publishing makes its answers available to parents.'
     :op==='delete'?'Permanently delete "'+record.filename+'"? This removes it from all counsellor answers.':
      'Unpublish "'+record.filename+'"? It will immediately stop appearing in AI answers.';
   if(!confirm(text))return;
   perform(async()=>{
     await api('/api/schools/'+state.schoolId+'/public-chat/knowledge-files','POST',
       {action:op,id,confirmed:op==='publish'});
     await loadPublicChat();
     toast(op==='publish'?'School knowledge published.':op==='delete'?'Knowledge file deleted.':'Knowledge file unpublished.');
   });return;
 }
 if(action==='chat-delete-faq'){
   if(!isAdmin())return;
   const question=el.dataset.question||'';
   if(!confirm('Delete the approved answer for "'+question+'"? This cannot be undone.'))return;
   perform(async()=>{
     await api('/api/schools/'+state.schoolId+'/public-chat/faqs','POST',
       {action:'delete',original_question:question});
     await loadPublicChat();toast('Approved answer deleted.');
   });return;
 }
 if(action==='chat-topic-delete'){
   if(!isAdmin())return;
   const item=(ext.counsellorReport?.topics||[])[Number(el.dataset.index)];
   if(!item||!confirm('Delete this aggregated question topic and its saved answer example?'))return;
   perform(async()=>{
     await api('/api/schools/'+state.schoolId+'/public-chat/counsellor-report','POST',
       {action:'delete_topic',topic:item.topic,grade:item.grade||'',academic_year:item.academic_year||''});
     await loadPublicChat();toast('Question topic deleted.');
   });return;
 }
 if(action==='chat-delete-unanswered'){
   if(!isAdmin()||!confirm('Permanently delete this unanswered parent question?'))return;
   perform(async()=>{
     await api('/api/schools/'+state.schoolId+'/public-chat/review','POST',
       {id:el.dataset.id,decision:'delete'});
     await loadPublicChat();toast('Unanswered question deleted.');
   });return;
 }
 if(action==='chat-select-tab'){
   ext.chatTab=el.dataset.tab||'Overview';render();return;
 }
 if(action==='chat-goto-leads'){
   state.page='Chatbot Leads';render();return;
 }
 if(action==='chat-source-scan'||action==='chat-source-remove'||action==='chat-source-reject'){
   if(!isAdmin())return;
   const id=el.dataset.id||'';
   if(action==='chat-source-remove'&&!confirm('Remove this source URL? Its previously imported facts will require re-verification.'))return;
   perform(async()=>{
     const actionName=action==='chat-source-scan'?'scan':action==='chat-source-remove'?'remove':'reject';
     const data=actionName==='reject'?{action:'reject',candidate_id:id}:{action:actionName,source_id:id};
     const result=await api('/api/schools/'+state.schoolId+'/public-chat/sources','POST',data);
     await loadPublicChat();
     toast(actionName==='scan'?'Source scanned: '+Number(result.candidates||0)+' fact candidates awaiting review.':
       actionName==='remove'?'Source removed; linked facts need re-verification.':'Extracted fact rejected');
   });return;
 }
 if(action==='chat-starter-faq'){
   const area=root.querySelector('#sfChatFaqs');if(!area)return;
   const template=area.querySelector('.sf-chat-faq');if(!template)return;
   const existing=new Set([...area.querySelectorAll('.sf-chat-q')].map(x=>x.value.trim().toLowerCase()));
   for(const [question,answer] of sfStarterQuestions){
     if(existing.has(question.toLowerCase())||area.querySelectorAll('.sf-chat-faq').length>=60)continue;
     const clone=template.cloneNode(true);
     clone.querySelector('.sf-chat-q').value=question;
     clone.querySelector('.sf-chat-a').value=answer;
     area.appendChild(clone);existing.add(question.toLowerCase());
   }
   toast('Starter questions added to the editor. Review and save to publish.');return;
 }
 if(action==='chat-test-provider'){
   if(!isAdmin())return;
   perform(async()=>{
     const result=await api('/api/schools/'+state.schoolId+'/public-chat/provider/test','POST',{});
     ext.chatProviderTest=result;
     render();
     toast(result.status==='connected'?'OpenRouter model responded successfully':result.message,true);
   });return;
 }
 if(action==='chat-add-faq'){const area=root.querySelector('#sfChatFaqs');if(!area)return;const current=area.querySelectorAll('.sf-chat-faq').length;if(current>=60)return toast('Maximum 60 published FAQs',true);const template=area.querySelector('.sf-chat-faq');if(!template)return;const clone=template.cloneNode(true);clone.querySelectorAll('input,textarea').forEach(x=>x.value='');area.appendChild(clone);return;}

 if(action==='chat-reject-topic'){if(!isAdmin())return;perform(async()=>{await api('/api/schools/'+state.schoolId+'/public-chat/review','POST',{id:el.dataset.id,decision:'reject'});await loadPublicChat();toast('Topic rejected');});return;}
 if(action==='chat-copy-embed'){
   const group=(ext.chatGroups||[]).find(g=>g.id===Number(el.dataset.id));
   if(!group?.guid)return;
   const snippet=sfGroupEmbed(group.guid);
   navigator.clipboard.writeText(snippet).then(()=>toast('Embed code copied')).catch(()=>toast('Clipboard unavailable; select the snippet above',true));return;
 }
 if(action==='close-modal'&&e.target.closest('.modal')&&e.target!==el&&el.id==='modalWrap')return;
 if(action==='close-detail'&&e.target.closest('.drawer')&&e.target!==el&&el.id==='drawerOverlay')return;
 if(action==='close-modal'||action==='close-detail'){closeDialog();return;}
 if(action==='import-preview'){
  perform(async()=>{
   const uploadEl=root.querySelector('#sfImportFile');
   const file=uploadEl?.files?.[0];
   if(file){
    if(file.size>2500000)throw new Error('Maximum file size is 2.5 MB');
    const bytes=new Uint8Array(await file.arrayBuffer());let binary='';
    for(let i=0;i<bytes.length;i+=12000)binary+=String.fromCharCode(...bytes.slice(i,i+12000));
    ext.importUpload={filename:file.name,base64:btoa(binary)};
   }
   if(!ext.importUpload)throw new Error('Choose a CSV or XLSX file');
   if(!/\.(csv|xlsx)$/i.test(ext.importUpload.filename))throw new Error('Use CSV or XLSX');
   const selects=[...(root.querySelectorAll?.('[data-import-map]')||[])];
   const mapping={};
   for(const select of selects)mapping[select.dataset.importMap]=select.value===''?null:Number(select.value);
   const payload={...ext.importUpload};
   if(selects.length)payload.mapping=mapping;
   ext.importPreview=await api('/api/schools/'+state.schoolId+'/imports/preview','POST',payload);
   render();
  });return;
 }
 if(action==='import-commit'){
  perform(async()=>{
   if(!ext.importUpload||!ext.importPreview)throw new Error('Preview the spreadsheet first');
   if(!root.querySelector('#sfImportConfirm')?.checked)throw new Error('Confirm the import review checkbox');
   const p={...ext.importUpload,mapping:ext.importPreview.mapping,preview_guid:ext.importPreview.preview_guid,confirm:true};
   const result=await api('/api/schools/'+state.schoolId+'/imports/commit','POST',p);
   ext.lastImport=result;ext.importUpload=null;ext.importPreview=null;await reload();
   toast(result.imported+' enquiries imported; '+result.skipped+' matched and skipped.');
  });return;
 }
 if(action==='import-rollback'){
  if(!confirm('Roll back only the unchanged enquiries created by this import? This cannot be undone.'))return;
  perform(async()=>{
   await api('/api/schools/'+state.schoolId+'/imports/'+el.dataset.guid+'/rollback','POST',{});
   ext.lastImport=null;await reload();toast('Imported batch rolled back');
  });return;
 }
 if(action==='edit-site'){
  if(!isAdmin())return;
  ext.editSiteId=Number(el.dataset.id);state.modal='edit-site';render();return;
 }
 if(action==='edit-school'){
  const sid=Number(el.dataset.schoolId);
  if(!state.me.schools.some(x=>x.id===sid&&x.role==='Admin'))return toast('Only that school administrator can edit it',true);
  ext.editSchoolId=sid;state.modal='edit-school';render();return;
 }
 if(action==='edit-staff'){
  if(!isAdmin())return;
  ext.editMemberId=Number(el.dataset.id);state.modal='edit-staff';render();return;
 }
 if(action==='disable-staff'||action==='enable-staff'){
  if(!isAdmin())return;
  const staff=state.members.find(m=>m.id===Number(el.dataset.id));if(!staff)return;
  const enabled=action==='enable-staff';
  if(!confirm((enabled?'Restore school access for ':'Disable school access for ')+staff.name+'?'+
     (enabled?'':' Their open enquiries and tasks will be unassigned. Their active sessions will be revoked.')))return;
  perform(async()=>{
   await api('/api/schools/'+state.schoolId+'/members/'+staff.id,'PATCH',{enabled});
   ext.resetResult=null;await reload();toast(staff.name+(enabled?' reactivated':' disabled for this school'));
  });return;
 }
 if(action==='reset-staff'){
  if(!isAdmin())return;
  const staff=state.members.find(m=>m.id===Number(el.dataset.id));if(!staff)return;
  if(!confirm('Reset password for '+staff.name+' and revoke all their sessions?'))return;
  perform(async()=>{
   const result=await api('/api/schools/'+state.schoolId+'/members/'+staff.id+'/reset-password','POST',{});
   ext.resetResult={password:result.temporary_password,name:staff.name};
   render();toast('Temporary password generated. Copy it now.');
  });return;
 }
 if(action==='dismiss-staff-password'){ext.resetResult=null;render();return;}
 if(action==='copy-staff-password'){
  if(ext.resetResult?.password)navigator.clipboard.writeText(ext.resetResult.password).then(()=>toast('Copied securely')).catch(()=>toast('Copy password manually',true));
  return;
 }
 if(action==='change-my-password'){state.modal='change-my-password';render();return;}
 if(action==='task-complete'){perform(async()=>{await api('/api/schools/'+state.schoolId+'/tasks/'+Number(el.dataset.id),'PATCH',{status:'Completed'});await reload();toast('Task completed');});return;}
 if(action==='create-application'){perform(async()=>{await api('/api/schools/'+state.schoolId+'/applications','POST',{lead_id:Number(el.dataset.id)});await reload();state.detail=await api('/api/schools/'+state.schoolId+'/leads/'+Number(el.dataset.id));render();toast('Application started');});return;}
 if(action==='menu'){root.querySelector('.sidebar')?.classList.toggle('open');return;}
 if(action==='close-menu'){root.querySelector('.sidebar')?.classList.remove('open');return;}
 if(action==='open-search'){state.page='Enquiries';state.status='All';render();root.querySelector('#q')?.focus();return;}
 if(action==='nav'||action==='go-enquiries'){if((el.dataset.page||'Enquiries')!=='Team')ext.resetResult=null;state.page=el.dataset.page||'Enquiries';state.search='';state.status='All';state.modal='';state.detail=null;
   if(state.page==='Reports' && isAdmin()){state.audit=null;perform(async()=>{state.audit=await api(`/api/schools/${state.schoolId}/audit`);render();});}
   if(state.page==='Schools & Routing' && canManageSchools()){perform(loadRouting);}
   if(state.page==='Admissions AI' && isAdmin()){ext.chatSettings=null;perform(loadPublicChat);}
   render();return;}
 if(action==='route-open'||action==='route-connect'){
   const sid=Number(el.dataset.schoolId);
   if(!canManageSchools() || !state.me.schools.some(s=>s.id===sid&&s.role==='Admin'))return;
   state.schoolId=sid;ext.resetResult=null;sessionStorage.setItem('schoolflow_school',String(sid));
   state.page=action==='route-connect'?'Websites & Forms':'Dashboard';
   state.key='';state.detail=null;state.modal=action==='route-connect'?'new-site':'';
   perform(reload);return;
 }
 if(action==='new-group'||action==='edit-group'){
   if(!canManageSchools())return;
   ext.editGroupId=action==='edit-group'?Number(el.dataset.id):null;
   state.modal=action;render();return;
 }
 if(action==='refresh-group-form-id'){
   if(!isAdmin())return;
   const id=Number(el.dataset.id),group=ext.groupSites.find(x=>x.id===id);
   if(!group)return;
   if(!confirm('Generate a new public Form ID for '+group.name+'? The previous embed and public form GUID will stop working immediately. You MUST copy the new script into the website after refreshing. Existing enquiries and internal CRM keys will be preserved.'))return;
   perform(async()=>{
     const updated=await api('/api/group-sites/'+id+'/refresh-form-id','POST',{});
     const index=ext.groupSites.findIndex(x=>x.id===id);
     if(index>=0)ext.groupSites[index]=updated;
     ext.chatGroups=ext.chatGroups.map(x=>x.id===id?updated:x);
     render();
     toast('New Form ID generated. Copy the new GUID embed into the website. Old code is invalid.');
   });
   return;
 }
 if(['copy-group-embed','copy-group-inline','copy-group-short-popup'].includes(action)){
   const group=ext.groupSites.find(x=>x.id===Number(el.dataset.id));if(!group?.guid){toast('Group GUID not available yet',true);return;}
   const code=action==='copy-group-inline'?sfGroupShortEmbed(group.guid,'inline'):
      action==='copy-group-short-popup'?sfGroupShortEmbed(group.guid,'popup'):sfGroupEmbed(group.guid);
   navigator.clipboard.writeText(code).then(()=>toast('SchoolFlow embed copied')).catch(()=>toast('Copy blocked by the browser; select the code on the card',true));return;
 }
 if(action==='copy-group-key'){navigator.clipboard.writeText(ext.groupKey).then(()=>toast('Group website key copied'));return;}
 if(action==='dismiss-group-key'){ext.groupKey='';render();return;}
 if(action==='rotate-group-key'){
   const id=Number(el.dataset.id);if(!confirm('Rotate the group website key? Your WordPress integration will stop until the new key is installed.'))return;
   perform(async()=>{const r=await api(`/api/group-sites/${id}/rotate-key`,'POST',{});ext.groupKey=r.api_key;render();toast('New group key generated. Update WordPress.');});return;
 }
 if(action==='toggle-group'){
   const id=Number(el.dataset.id),g=ext.groupSites.find(x=>x.id===id);if(!g)return;
   if(!confirm(`${g.enabled?'Disable':'Enable'} group website ${g.name}?`))return;
   perform(async()=>{await api(`/api/group-sites/${id}`,'PATCH',{name:g.name,domain:g.domain,school_ids:g.schools.map(x=>x.school_id),enabled:!g.enabled});await loadRouting();toast('Group website status updated');});return;
 }
 if(['new-lead','new-site','new-member','new-school'].includes(action)){state.modal=action;state.detail=null;render();return;}
 if(action==='open-task-lead'){perform(async()=>{const id=Number(el.dataset.id);state.detail=await api('/api/schools/'+state.schoolId+'/leads/'+id);render();});return;}
 if(action==='view-lead'){perform(async()=>{const url=`/api/schools/${state.schoolId}/leads/${Number(el.dataset.id)}`;
   await api(url+'/view','POST',{});state.detail=await api(url);render();});return;}
 if(action==='done-followup'){perform(async()=>{await api(`/api/schools/${state.schoolId}/leads/${Number(el.dataset.id)}`,'PATCH',{followup_date:''});await reload();toast('Follow-up marked complete');});return;}
 if(action==='preview-web-stage-reset'){
   if(!canWebStageReset())return;
   perform(async()=>{state.stagingResetPreview=await api('/api/admin/staging-reset');render();});
   return;
 }
 if(action==='add-demo'){
   if(!isAdmin() || state.demo?.has_demo_data)return;
   if(!confirm('Add 18 fictional enquiries and 3 disabled example websites to this school?'))return;
   perform(async()=>{const result=await api(`/api/schools/${state.schoolId}/demo-data/seed`,'POST',{});await reload();toast(`Added ${result.leads} demo enquiries and ${result.sites} sources`);});return;
 }
 if(action==='clear-demo'){
   if(!isAdmin() || !state.demo?.has_demo_data)return;
   const confirmText=prompt('Remove ONLY demo enquiries and demo website sources from this school. Real records and staff remain untouched.\n\nType CLEAR DEMO to confirm:');
   if(confirmText!=='CLEAR DEMO')return;
   perform(async()=>{const result=await api(`/api/schools/${state.schoolId}/demo-data/clear`,'POST',{confirm:'CLEAR DEMO'});await reload();toast(`Removed ${result.removed_leads} demo enquiries and ${result.removed_sites} demo websites`);});return;
 }
 if(action==='more-school-audit' && state.audit?.next_before){perform(async()=>{
   const next=await api(`/api/schools/${state.schoolId}/audit?before=${state.audit.next_before}`);
   state.audit={events:[...state.audit.events,...next.events],has_more:next.has_more,next_before:next.next_before};render();
 });return;}
 if(action==='more-enquiry-audit' && state.detail?.audit?.next_before){perform(async()=>{
   const leadId=state.detail.lead.id;
   const next=await api(`/api/schools/${state.schoolId}/leads/${leadId}/audit?before=${state.detail.audit.next_before}`);
   state.detail.audit={events:[...state.detail.audit.events,...next.events],has_more:next.has_more,next_before:next.next_before};render();
 });return;}
 if(action==='export-xlsx'){perform(xlsxExport);return;}
 if(action==='export-csv'){perform(csvExport);return;}
 if(action==='dismiss-key'){state.key='';render();return;}
 if(action==='copy-key'){navigator.clipboard?.writeText(state.key).then(()=>toast('Key copied')).catch(()=>toast('Select and copy the key manually',true));return;}
 if(action==='rotate-key'){if(!confirm('Rotate the website API key? Its current key will stop working immediately.'))return;perform(async()=>{let result=await api(`/api/schools/${state.schoolId}/sites/${Number(el.dataset.id)}/rotate-key`,'POST');state.key=result.api_key;render();toast('Website key rotated');});return;}
 if(action==='toggle-site'){perform(async()=>{await api(`/api/schools/${state.schoolId}/sites/${Number(el.dataset.id)}?enabled=${el.dataset.enabled==='1'}`,'PATCH');await reload();toast('Website status updated');});return;}

 if(action==='api-send-email'||action==='api-send-wa'){
   const l=state.detail?.lead;if(!l||!l.consent||l.is_demo)return;
   const channel=action==='api-send-email'?'email':'whatsapp';
   const provider=channel==='email'?'Resend':'Meta WhatsApp Cloud API';
   if(!confirm(`Send one ${channel} message to the guardian using ${provider}? Provider charges and template rules may apply.`))return;
   const payload={lead_id:l.guid||l.id,request_id:crypto.randomUUID()};
   if(channel==='email'){
     const value=root.querySelector('#emailTemplateChoice')?.value;
     if(value)payload.template_id=Number(value);
   }
   perform(async()=>{
     const response=await api(`/api/schools/${state.schoolId}/communications/${channel}/send`,'POST',payload);
     state.detail=await api(`/api/schools/${state.schoolId}/leads/${l.guid||l.id}`);
     render();toast(response.detail||'Provider accepted request');
   });return;
 }
 if(action==='call-parent'){
   const lead=state.leads.find(l=>l.id===Number(el.dataset.id));
   if(lead){api(`/api/schools/${state.schoolId}/leads/${lead.id}/contact-action`,'POST',{channel:'phone'}).catch(()=>{});window.location.href='tel:'+lead.phone.replace(/[^\d+]/g,'');}return;
 }
 if(action==='whatsapp'){
   const l=state.detail?.lead;if(!l||!l.consent)return;
   const number=l.phone.replace(/\D/g,'');
   if(number.length<10)return toast('Valid parent contact required',true);
   const message=`Hello ${l.guardian}, thank you for enquiring about ${l.name} at ${school()?.name||'our school'}. How can we help with admissions?`;
   api(`/api/schools/${state.schoolId}/leads/${l.id}/contact-action`,'POST',{channel:'whatsapp'}).catch(()=>{});
   window.open('https://wa.me/'+number+'?text='+encodeURIComponent(message),'_blank','noopener,noreferrer');return;
 }
 if(action==='email-draft'){
   const l=state.detail?.lead?.id===Number(el.dataset.id)?state.detail.lead:state.leads.find(x=>x.id===Number(el.dataset.id));if(!l||!l.consent||!l.email)return;
   const t=ext.templates.find(t=>String(t.id)===String(root.querySelector('#emailTemplateChoice')?.value))||ext.templates[0];let subject=t?.subject||'School admissions enquiry';let body=t?.body||'Hello {parent},\n\nThank you for your enquiry for {student} at {school}.\n\nAdmissions Team';
   const values={parent:l.guardian,student:l.name,grade:l.grade,school:school()?.name||'SchoolFlow'};
   const fill=v=>v.replace(/\{(parent|student|grade|school)\}/g,(_,key)=>values[key]||'');
   api(`/api/schools/${state.schoolId}/leads/${l.id}/contact-action`,'POST',{channel:'email'}).catch(()=>{});
   window.location.href='mailto:'+encodeURIComponent(l.email)+'?subject='+encodeURIComponent(fill(subject))+'&body='+encodeURIComponent(fill(body));return;
 }
 if(action==='book-visit'){state.visitLeadId=Number(el.dataset.id)||state.detail?.lead?.id||state.leads[0]?.id;state.modal='book-visit';render();return;}
 if(action==='visit-status'){
 const feedback=prompt('Record visit outcome or reason for no-show (cancel to keep unchanged):','');
 if(feedback===null)return;
 const next=prompt('Next follow-up date YYYY-MM-DD (optional):','');
 if(next===null)return;
 perform(async()=>{const data={status:el.dataset.status,feedback};if(next)data.next_followup_date=next;
 await api('/api/schools/'+state.schoolId+'/visits/'+Number(el.dataset.id),'PATCH',data);await reload();toast('Visit outcome saved');});return;
}
 if(action==='new-template'){state.modal='new-template';state.editTemplateId=null;render();return;}
 if(action==='edit-template'){state.modal='edit-template';state.editTemplateId=Number(el.dataset.id);render();return;}
 if(action==='logout'){perform(async()=>{try{await api('/api/auth/logout','POST');}catch{}ext.resetResult=null;state.token='';state.me=null;sessionStorage.removeItem('schoolflow_session');render();});return;}
});
root.addEventListener('change',e=>{
 if(e.target.matches?.('[data-import-map]')){const confirmButton=root.querySelector('[data-action="import-commit"]');if(confirmButton)confirmButton.disabled=true;}
 if(e.target.id==='schoolSelect'){ext.resetResult=null;ext.importUpload=null;ext.importPreview=null;ext.lastImport=null;state.schoolId=Number(e.target.value);sessionStorage.setItem('schoolflow_school',String(state.schoolId));state.key='';state.detail=null;state.page='Dashboard';perform(reload);}
 if(e.target.id==='visitMonth'){ext.visitMonth=e.target.value;render();}
 if(e.target.id==='assignedFilter'){ext.assignedFilter=e.target.value;render();}
 if(e.target.id==='dueFilter'){ext.dueFilter=e.target.value;render();}
 if(e.target.id==='contactFilter'){ext.contactFilter=e.target.value;render();}
 if(e.target.id==='temperatureFilter'){ext.temperatureFilter=e.target.value;render();}
 if(e.target.id==='statusFilter'){state.status=e.target.value;render();}
 if(e.target.id==='siteFilter'){state.siteFilter=e.target.value;render();}  if(e.target.id==='sourceTypeFilter'){ext.sourceTypeFilter=e.target.value;render();}  if(e.target.id==='formTypeFilter'){ext.formTypeFilter=e.target.value;render();}
});
root.addEventListener('input',e=>{
 if(e.target.id==='q'){const p=e.target.selectionStart;state.search=e.target.value;render();const q=root.querySelector('#q');q?.focus();q?.setSelectionRange(p,p);}
});
root.addEventListener('submit',e=>{e.preventDefault();const f=e.target;let fd=Object.fromEntries(new FormData(f));
 if(f.id==='webStageResetForm'){
   if(!canWebStageReset()||!state.stagingResetPreview)return;
   if(fd.confirmation!=='RESET STAGING KEEP IN.STG.ADM')return toast('Type the complete confirmation phrase',true);
   if(!confirm('FINAL CONFIRMATION: Delete ALL staging schools, staff, enquiries and integrations? Keep only your administrator account and one new empty workspace?'))return;
   perform(async()=>{
     const result=await api('/api/admin/staging-reset','POST',{
       fingerprint:state.stagingResetPreview.fingerprint,password:fd.password,confirmation:fd.confirmation
     });
     if(!result.reset_complete)throw new Error('Staging reset did not complete');
     state.stagingResetPreview=null;state.token='';state.me=null;
     sessionStorage.removeItem('schoolflow_session');sessionStorage.removeItem('schoolflow_school');
     render();toast('Staging reset completed. Sign in with your existing administrator password.');
   });
   return;
 }


 if(f.id==='chatKnowledgeTest'){
   if(!isAdmin())return;
   perform(async()=>{
     const question=String(fd.question||'').trim();
     ext.chatKnowledgeTest=await api('/api/schools/'+state.schoolId+'/public-chat/knowledge-files',
       'POST',{action:'test',question});
     render();
     toast(ext.chatKnowledgeTest.status==='matched'?'Published school knowledge matched the question.':'Knowledge check needs attention.',ext.chatKnowledgeTest.status!=='matched');
   });return;
 }
 if(f.id==='chatKnowledgeUpload'){
   if(!isAdmin())return;
   const file=f.elements.knowledge_file?.files?.[0];
   if(!file)return toast('Select a school knowledge file first.',true);
   if(!/\.(md|txt)$/i.test(file.name))return toast('Choose a .md or .txt knowledge file.',true);
   if(file.size<40||file.size>120000)return toast('File size must be between 40 bytes and 120 KB.',true);
   perform(async()=>{
     const content=await file.text();
     if(!content.trim())throw new Error('Knowledge file is empty');
     await api('/api/schools/'+state.schoolId+'/public-chat/knowledge-files','POST',
       {action:'upload',filename:file.name,content});
     await loadPublicChat();toast('File uploaded for review. Inspect the content and publish explicitly.');
   });return;
 }
 if(f.id==='chatAddFaqForm'||f.classList?.contains('sfFaqEditor')){
   if(!isAdmin())return;
   perform(async()=>{
     const payload={
       action:f.id==='chatAddFaqForm'?'add':'update',
       original_question:f.dataset.originalQuestion||'',
       question:String(fd.question||'').trim(),answer:String(fd.answer||'').trim()
     };
     await api('/api/schools/'+state.schoolId+'/public-chat/faqs','POST',payload);
     await loadPublicChat();toast(payload.action==='add'?'Verified FAQ published.':'FAQ saved.');
   });return;
 }
 if(f.id==='chatFactsForm'){
   if(!isAdmin())return;
   perform(async()=>{
     const school_facts=[...f.querySelectorAll('.sf-school-fact')].map(card=>({
       category:card.querySelector('.sf-fact-category')?.value||'other',
       label:card.querySelector('.sf-fact-label')?.value.trim()||'',
       details:card.querySelector('.sf-fact-details')?.value.trim()||'',
       source_id:card.querySelector('.sf-fact-source')?.value||'',
       confirm_recheck:!!card.querySelector('.sf-fact-confirm')?.checked
     })).filter(x=>x.label||x.details);
     if(school_facts.some(x=>!x.label||!x.details))throw new Error('Complete each fact label and detail');
     const prev=ext.chatSettings;
     await api('/api/schools/'+state.schoolId+'/public-chat','PATCH',{
       enabled:!!prev.enabled,ai_enabled:!!prev.ai_enabled,
       overview:prev.overview||'',faqs:prev.faqs||[],
       school_facts,remember_days:Number(prev.remember_days||30)
     });
     await loadPublicChat();toast('Campus facts saved and published.');
   });return;
 }
 if(f.id==='chatProviderForm'){
   if(!isAdmin())return;
   perform(async()=>{
     await api('/api/schools/'+state.schoolId+'/public-chat/provider','PATCH',{
       model:String(fd.model||'').trim(),api_key:String(fd.api_key||'').trim(),
       remove_key:!!f.elements.remove_key.checked
     });
     await loadPublicChat();toast('OpenRouter connection settings saved securely');
   });return;
 }
 if(f.id==='chatSourceAddForm'){
   if(!isAdmin())return;
   perform(async()=>{
     await api('/api/schools/'+state.schoolId+'/public-chat/sources','POST',
       {action:'add',url:String(fd.url||'').trim()});
     await loadPublicChat();toast('Source URL added. Select Scan / Rescan to stage facts for review.');
   });return;
 }
 if(f.classList?.contains('sfSourceReview')){
   if(!isAdmin())return;
   perform(async()=>{
     await api('/api/schools/'+state.schoolId+'/public-chat/sources','POST',{
       action:'approve',candidate_id:f.dataset.id,label:String(fd.label||'').trim(),
       details:String(fd.details||'').trim()
     });
     await loadPublicChat();toast('Verified source information approved for this campus.');
   });return;
 }
 if(f.id==='chatSettingsForm'){
   if(!isAdmin())return;
   perform(async()=>{
     const prev=ext.chatSettings;
     await api('/api/schools/'+state.schoolId+'/public-chat','PATCH',{
       enabled:!!f.elements.enabled.checked,
       ai_enabled:!!f.elements.ai_enabled.checked,
       overview:String(fd.overview||'').trim(),
       faqs:prev.faqs||[],
       school_facts:prev.school_facts||[],
       remember_days:Number(fd.remember_days||30)
     });
     await loadPublicChat();toast('School counsellor settings saved.');
   });return;
 }
 if(f.classList?.contains('sfChatReview')){
   if(!isAdmin())return;
   perform(async()=>{
     const answer=String(fd.answer||'').trim();
     if(answer.length<3)throw new Error('Enter a verified answer before approving');
     await api('/api/schools/'+state.schoolId+'/public-chat/review','POST',{
       id:f.dataset.id,decision:'approve',answer
     });
     await loadPublicChat();toast('Verified answer published');
   });return;
 }
 if(f.id==='setupForm'){perform(async()=>{
   if(fd.password!==fd.confirm_password){state.error='Passwords do not match';render();return;}
   if(String(fd.password||'').length<12){state.error='Use a password of at least 12 characters';render();return;}
   try{
     let result=await api('/api/setup/admin','POST',fd);
     state.token=result.token;sessionStorage.setItem('schoolflow_session',state.token);
     state.setup={needs_setup:false};state.error='';state.me=await api('/api/me');state.schoolId=state.me.schools[0]?.id;
     await reload();toast('Administrator created. Welcome to SchoolFlow.');
   }catch(e){state.error=e.message;render();}
 });return;}
 if(f.id==='loginForm'){perform(async()=>{state.error='';let result=await api('/api/auth/login','POST',fd);state.token=result.token;sessionStorage.setItem('schoolflow_session',state.token);state.me=await api('/api/me');state.schoolId=state.me.schools[0]?.id;if(state.me.user.must_change_password){render();return;}await reload();toast('Signed in successfully');});return;}

 if(f.id==='seatPlanForm'){perform(async()=>{await api('/api/schools/'+state.schoolId+'/seat-plans','PATCH',{academic_year:fd.academic_year,grade:fd.grade,capacity:Number(fd.capacity)});await reload();toast('Seat capacity saved');});return;}
 if(f.id==='responseSlaForm'){perform(async()=>{await api('/api/schools/'+state.schoolId+'/response-sla','PATCH',{first_contact_minutes:Number(fd.first_contact_minutes)});await reload();toast('First-contact SLA updated');});return;}
 if(f.classList?.contains('documentStatusForm')){perform(async()=>{
   const id=state.detail?.lead?.id;if(!id)throw new Error('Choose an enquiry first');
   await api('/api/schools/'+state.schoolId+'/leads/'+id+'/documents','PATCH',{type:fd.type,status:fd.status,note:fd.note});
   state.detail=await api('/api/schools/'+state.schoolId+'/leads/'+id);render();toast('Document checklist saved');
 });return;}
 if(f.id==='applicationForm'){perform(async()=>{const id=Number(fd.application_id);const p={status:fd.status};if(fd.fee_recorded==='true')p.fee_recorded=true;await api('/api/schools/'+state.schoolId+'/applications/'+id,'PATCH',p);await reload();state.detail=await api('/api/schools/'+state.schoolId+'/leads/'+state.detail.lead.id);render();toast('Application updated');});return;}
 if(f.id==='retryPolicyForm'){perform(async()=>{await api('/api/schools/'+state.schoolId+'/followup-policy','PATCH',{retry_days:[Number(fd.retry1),Number(fd.retry2),Number(fd.retry3)]});await reload();toast('Retry schedule saved');});return;}
 if(f.id==='autoSettingsForm'){perform(async()=>{const p={enabled:!!f.querySelector('input[name="enabled"]:checked'),email:!!f.querySelector('input[name="email"]:checked'),whatsapp:!!f.querySelector('input[name="whatsapp"]:checked'),task_kinds:Array.from(f.querySelectorAll('input[name="task_kinds"]:checked')).map(x=>x.value),max_daily:Number(fd.max_daily),send_start:fd.send_start,send_end:fd.send_end};await api('/api/schools/'+state.schoolId+'/automation','PATCH',p);await reload();toast('Automation policy saved');});return;}
 if(f.id==='autoReconcileForm'){perform(async()=>{await api('/api/schools/'+state.schoolId+'/automation/queue/'+Number(fd.queue_id)+'/reconcile','POST',{status:fd.status,provider_evidence_reference:fd.provider_evidence_reference});await reload();toast('Provider evidence recorded');});return;}
 if(f.id==='channelConsentForm'){perform(async()=>{const leadId=state.detail.lead.id;await api('/api/schools/'+state.schoolId+'/leads/'+leadId,'PATCH',{consent_email:!!f.querySelector('input[name="consent_email"]:checked'),consent_whatsapp:!!f.querySelector('input[name="consent_whatsapp"]:checked'),consent_source:fd.consent_source});await reload();state.detail=await api('/api/schools/'+state.schoolId+'/leads/'+leadId);render();toast('Channel-specific consent recorded');});return;}
 if(f.id==='assignmentForm'){perform(async()=>{ext.assignment=await api(`/api/schools/${state.schoolId}/assignment`,'PATCH',{mode:fd.mode});toast('Assignment policy saved');render();});return;}
 if(f.id==='callForm'){perform(async()=>{const lid=state.detail.lead.id;await api(`/api/schools/${state.schoolId}/leads/${lid}/calls`,'POST',fd);await reload();state.detail=await api(`/api/schools/${state.schoolId}/leads/${lid}`);render();toast('Call outcome recorded');});return;}
 if(f.id==='siblingForm'){perform(async()=>{const lid=state.detail.lead.id;const result=await api(`/api/schools/${state.schoolId}/families/${lid}/siblings`,'POST',fd);await reload();state.detail=await api(`/api/schools/${state.schoolId}/leads/${result.id}`);render();toast('Sibling enquiry added');});return;}
 if(f.id==='visitForm'){perform(async()=>{const body={lead_id:Number(fd.lead_id),starts_at:fd.starts_at,purpose:fd.purpose};if(fd.staff_id)body.staff_id=Number(fd.staff_id);await api(`/api/schools/${state.schoolId}/visits`,'POST',body);state.modal='';await reload();if(state.detail){state.detail=await api(`/api/schools/${state.schoolId}/leads/${state.detail.lead.id}`);render();}toast('School visit booked');});return;}
 if(f.id==='templateForm'){perform(async()=>{const edit=state.modal==='edit-template';await api(`/api/schools/${state.schoolId}/templates${edit?'/'+state.editTemplateId:''}`,edit?'PATCH':'POST',fd);state.modal='';await reload();toast('Email template saved');});return;}
 if(f.id==='leadForm'){perform(async()=>{let body={...fd,consent:fd.consent==='true',site_id:fd.site_id?Number(fd.site_id):null,assigned_to:fd.assigned_to?Number(fd.assigned_to):null};if(!body.site_id)delete body.site_id;if(!body.assigned_to)delete body.assigned_to;let newLead=await api(`/api/schools/${state.schoolId}/leads`,'POST',body);state.modal='';await reload();state.detail=await api(`/api/schools/${state.schoolId}/leads/${newLead.id}`);render();toast('Enquiry saved');});return;}
 if(f.id==='groupSiteForm'){
   const chosen=Array.from(f.querySelectorAll('input[name="school_ids"]:checked')).map(x=>Number(x.value));
   if(!chosen.length){toast('Select at least one school',true);return;}
   perform(async()=>{
     const g=ext.groupSites.find(x=>x.id===ext.editGroupId);
     const result=await api(g?`/api/group-sites/${g.id}`:'/api/group-sites',g?'PATCH':'POST',{
       name:fd.name,domain:fd.domain,school_ids:chosen,enabled:g?g.enabled:true});
     if(result.api_key)ext.groupKey=result.api_key;
     state.modal='';state.page='Schools & Routing';await loadRouting();toast(g?'School routing updated':'Group website created. Copy its private key.');
   });return;
 }
 if(f.id==='communicationsForm'){
   perform(async()=>{ext.messaging=await api(`/api/schools/${state.schoolId}/communications`,'PATCH',fd);
     render();toast('Provider settings saved. Use a consented real enquiry to send a test.');});return;
 }
 if(f.id==='siteForm'){perform(async()=>{let result=await api(`/api/schools/${state.schoolId}/sites`,'POST',fd);state.key=result.api_key;state.modal='';state.page='Websites & Forms';await reload();toast('Website connected. Save its API key.');});return;}
 if(f.id==='editSiteForm'){perform(async()=>{
   await api('/api/schools/'+state.schoolId+'/sites/'+ext.editSiteId,'PATCH',{name:fd.name,domain:fd.domain,platform:fd.platform});
   state.modal='';ext.editSiteId=null;await reload();toast('Website details updated');
  });return;}
 if(f.id==='editSchoolForm'){perform(async()=>{
   await api('/api/schools/'+ext.editSchoolId,'PATCH',{name:fd.name});
   state.me=await api('/api/me');await loadRouting();state.modal='';ext.editSchoolId=null;await reload();toast('School name updated');
  });return;}
 if(f.id==='editStaffForm'){
  perform(async()=>{
   const id=ext.editMemberId;if(!id)throw new Error('Choose a staff member');
   await api('/api/schools/'+state.schoolId+'/members/'+id,'PATCH',{name:fd.name,email:fd.email,role:fd.role});
   state.modal='';ext.editMemberId=null;ext.resetResult=null;await reload();toast('Staff account updated');
  });return;
 }
 if(f.id==='ownPasswordForm'||f.id==='mandatoryPasswordForm'){
  perform(async()=>{
   if(fd.new_password!==fd.confirm_password)throw new Error('Passwords do not match');
   if(fd.new_password.length<12)throw new Error('Use at least 12 characters');
   await api('/api/me/password','POST',{current_password:fd.current_password,new_password:fd.new_password});
   state.token='';state.me=null;state.modal='';sessionStorage.removeItem('schoolflow_session');
   render();toast('Password changed. Sign in again.');
  });return;
 }
 if(f.id==='memberForm'){perform(async()=>{await api(`/api/schools/${state.schoolId}/members`,'POST',fd);state.modal='';await reload();toast('Team member added');});return;}
 if(f.id==='schoolForm'){perform(async()=>{let result=await api('/api/schools','POST',fd);state.me=await api('/api/me');state.schoolId=result.id;sessionStorage.setItem('schoolflow_school',String(state.schoolId));state.modal='';state.page='Dashboard';await reload();toast('School workspace created');});return;}
 if(f.id==='editLeadForm'){perform(async()=>{let leadId=state.detail.lead.id;let payload={status:fd.status,followup_date:fd.followup_date,contact_state:fd.contact_state,academic_year:fd.academic_year,lost_reason:fd.lost_reason,deferred_until:fd.deferred_until,current_school:fd.current_school,date_of_birth:fd.date_of_birth};if(fd.temperature!==(state.detail.lead.temperature||'Unrated')){payload.temperature=fd.temperature;payload.temperature_reason=fd.temperature_reason;}if(role()!=='Counsellor')payload.assigned_to=fd.assigned_to?Number(fd.assigned_to):null;await api(`/api/schools/${state.schoolId}/leads/${leadId}`,'PATCH',payload);await reload();state.detail=await api(`/api/schools/${state.schoolId}/leads/${leadId}`);render();toast('Enquiry updated');});return;}
 if(f.id==='noteForm'){perform(async()=>{let leadId=state.detail.lead.id;await api(`/api/schools/${state.schoolId}/leads/${leadId}/notes`,'POST',{text:fd.text});state.detail=await api(`/api/schools/${state.schoolId}/leads/${leadId}`);render();toast('Note added');});}
});
let dragged=null;
root.addEventListener('dragstart',e=>{const el=e.target.closest('[data-lead]');if(!el)return;dragged=Number(el.dataset.lead);e.dataTransfer.setData('text/plain',String(dragged));e.dataTransfer.effectAllowed='move';});
root.addEventListener('dragover',e=>{const col=e.target.closest('[data-phase]');if(col){e.preventDefault();col.classList.add('drag-active');}});
root.addEventListener('dragleave',e=>{const col=e.target.closest('[data-phase]');if(col&&!col.contains(e.relatedTarget))col.classList.remove('drag-active');});
root.addEventListener('drop',e=>{const col=e.target.closest('[data-phase]');if(!col)return;e.preventDefault();let leadId=Number(e.dataTransfer.getData('text/plain')||dragged);col.classList.remove('drag-active');dragged=null;if(!leadId)return;const phase=col.dataset.phase;const lead=state.leads.find(l=>l.id===leadId);if(!lead||!PHASE_TARGETS[phase]||admissionPhase(lead.status)===phase)return;if(lead.status==='Enrolled'&&!confirm('Move this enrolled admission to an earlier phase?'))return;perform(async()=>{await api(`/api/schools/${state.schoolId}/leads/${leadId}`,'PATCH',{status:PHASE_TARGETS[phase]});await reload();toast('Moved to '+phase);});});
root.addEventListener('keydown',e=>{const el=e.target.closest?.('[role="button"][data-action="view-lead"]');if(el&&(e.key==='Enter'||e.key===' ')){e.preventDefault();el.click();}});
document.addEventListener('keydown',e=>{
 if(e.key==='Escape'&&(state.modal||state.detail)){closeDialog();return;}
 if(e.key!=='Tab'||(!state.modal&&!state.detail)||typeof document.querySelector!=='function')return;
 const dialog=document.querySelector('.modal[role="dialog"],.drawer[role="dialog"]');
 if(!dialog)return;
 const controls=Array.from(dialog.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]')).filter(el=>el.getClientRects().length);
 if(!controls.length)return;
 const first=controls[0],last=controls[controls.length-1];
 if(e.shiftKey && document.activeElement===first){e.preventDefault();last.focus();}
 else if(!e.shiftKey && document.activeElement===last){e.preventDefault();first.focus();}
});
initialize();
})();
