(()=>{
'use strict';
const root=document.getElementById('root');
const APP_BASE=(window.SCHOOLFLOW_BASE_PATH||'/scrm').replace(/\/$/,'');
const PAGES=['Dashboard','My Work','Enquiries','Pipeline','Follow-ups','Tasks','Websites & Forms','Schools & Routing','School Visits','Families','Email Templates','Communications','Automations','Team','Reports','Import Enquiries','Seat Planning','Admissions AI'];
const ICONS={'Dashboard':'▦','My Work':'☑','Enquiries':'♧','Pipeline':'▥','Follow-ups':'▣','Tasks':'☷','Websites & Forms':'◉','Schools & Routing':'▤','School Visits':'▣','Families':'♧','Email Templates':'✉','Communications':'☏','Automations':'⏰','Team':'♙','Reports':'◫','Import Enquiries':'⇧','Seat Planning':'▤','Admissions AI':'✦'};
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
const NAV_SECTIONS=[
 ['OVERVIEW',['Dashboard','My Work']],
 ['ADMISSIONS',['Enquiries','Pipeline','Follow-ups','Tasks','School Visits','Families']],
 ['ENGAGEMENT',['Communications','Email Templates']],
 ['REPORTING',['Reports']],
 ['ADMINISTRATION',['Websites & Forms','Schools & Routing','Team','Import Enquiries','Seat Planning','Automations','Admissions AI']]
];
const TEMPERATURES=['Unrated','Hot','Warm','Cold'];
const CONTACT_STATES=['Pending Contact','Attempted','Connected','Awaiting Parent Response','Callback Requested','Unresponsive','No Action Required'];
const LOST_REASONS=['','Fees','Location','Transport','Curriculum','Admission elsewhere','No longer interested','No response','Other'];
const state={token:sessionStorage.getItem('schoolflow_session')||'',setup:null,me:null,schoolId:null,page:'Dashboard',leads:[],sites:[],members:[],modal:'',detail:null,demo:null,audit:null,search:'',status:'All',siteFilter:'All',key:'',error:'',notice:'',loading:false};
const ext={messaging:{email:{connected:false,provider:'off',from:''},whatsapp:{connected:false,provider:'off',template:'',phone_number_id:'',language:'en_US'}},schoolsOverview:[],groupSites:[],groupKey:'',editGroupId:null,autoPolicy:{enabled:false,email:false,whatsapp:false,task_kinds:['Follow-up','Campus Visit'],max_daily:10},autoQueue:[],followupPolicy:{retry_days:[1,2,4]},tasks:[],applications:[],visits:[],families:[],templates:[],analytics:{},assignment:{mode:'least_loaded'},assignedFilter:'All',dueFilter:'All',contactFilter:'All',temperatureFilter:'All',importUpload:null,importPreview:null,lastImport:null,seatPlans:[],responseSla:{first_contact_minutes:120},resetResult:null,editMemberId:null,editSiteId:null,editSchoolId:null,visitMonth:new Date().toISOString().slice(0,7),chatSettings:null,chatProvider:null,chatQueue:[],chatGroups:[]};
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
 [ext.visits,ext.families,ext.templates,ext.analytics,ext.assignment,ext.messaging,ext.tasks,ext.applications]=await Promise.all([
   api(path+'/visits'),api(path+'/families'),api(path+'/templates'),api(path+'/analytics'),api(path+'/assignment'),api(path+'/communications'),api(path+'/tasks'),api(path+'/applications')]);
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
function nav(){const n=state.leads.filter(l=>l.followup_date&&l.followup_date<=day()&&!['Enrolled','Lost'].includes(l.status)).length;
 return `<aside class="sidebar" id="sidebar"><div class="logo"><div class="logomark">S</div><div><strong>SchoolFlow</strong><small>School Enquiry CRM</small></div></div><div class="school-select"><label>SCHOOL WORKSPACE</label><select id="schoolSelect" aria-label="Choose school">${state.me.schools.map(s=>option(s.id,s.name,state.schoolId)).join('')}</select></div><div class="nav" aria-label="SchoolFlow main navigation">${NAV_SECTIONS.map(([label,items])=>`<div class="nav-section">${label}</div>${items.filter(p=>(p!=='Schools & Routing'||canManageSchools())&&(p!=='Automations'||isAdmin())&&(p!=='Import Enquiries'||canImport())&&(p!=='Seat Planning'||isAdmin())&&(p!=='Admissions AI'||isAdmin())).map(p=>`<button class="${state.page===p?'active':''}" data-action="nav" data-page="${esc(p)}" ${state.page===p?'aria-current="page"':''}><span class="nav-emoji" aria-hidden="true">${ICONS[p]}</span><span>${p}</span>${p==='Enquiries'?`<em>${state.leads.length}</em>`:p==='Follow-ups'&&n?`<em>${n}</em>`:''}</button>`).join('')}`).join('')}</div><div class="sidebar-bottom"><div class="sync-note"><b>● Shared workspace</b><br>${state.sites.filter(s=>s.enabled).length} connected sources<br>Secure server-side storage</div><div class="profile"><div class="avatar">${initials(state.me.user.name)}</div><div style="min-width:0"><strong>${esc(state.me.user.name)}</strong><span>${esc(role())}</span></div><button class="icon-btn" title="Sign out" data-action="logout" style="margin-left:auto">↪</button></div></div></aside>`;}
function topbar(){return `<header class="topbar"><div style="display:flex;align-items:center;gap:11px"><button class="icon-btn mobile-menu" data-action="menu" title="Menu">☰</button><div class="breadcrumbs">Schools &nbsp;/&nbsp; <b>${esc(state.page)}</b></div></div><div class="top-actions"><span class="demo-pill" style="background:#e5f8ef;color:#16835d">● Connected</span><span class="role-label">${esc(role())}</span><button class="icon-btn" data-action="nav" data-page="Follow-ups" title="Follow-ups">♧</button></div></header>`;}
function pageHead(title,description,button=''){return `<div class="view-title"><div><div class="eyebrow">${esc(school()?.name||'Workspace')}</div><h1>${esc(title)}</h1><p>${esc(description)}</p></div><div class="new-controls">${button}</div></div>`;}
function tableRows(rows){return rows.map(l=>`<tr data-action="view-lead" data-id="${l.id}"><td><div class="name-cell"><div class="small-avatar">${initials(l.name)}</div><div><b>${esc(l.name)}</b><small>${esc(l.guid?'#'+l.guid.slice(0,8):'#'+l.id)} · ${esc(l.grade||'Class not set')} · ${esc(l.temperature||'Unrated')}${l.duplicate_of?' · Possible duplicate':''}${l.is_demo?' · DEMO':''}${l.last_call_outcome?' · '+esc(l.last_call_outcome):''}</small></div></div></td><td><div class="strong">${esc(l.guardian)}</div><small class="muted">${esc(l.phone)}</small></td><td>${esc(l.source_name||'Manual entry')}</td><td>${stageBadge(l.status)}</td><td>${esc(l.assigned_name||'Unassigned')}</td><td>${esc(dateFmt(l.followup_date))}</td><td><button class="btn small" data-action="view-lead" data-id="${l.id}">View →</button></td></tr>`).join('')||`<tr><td colspan="7"><div class="empty-state"><b>No enquiries found</b><p>Connect a website or add an enquiry to get started.</p></div></td></tr>`;}
function leadTable(rows){return `<div class="panel"><div class="table-scroll"><table class="lead-table"><thead><tr><th>Student</th><th>Parent / phone</th><th>Source</th><th>Stage</th><th>Assigned</th><th>Follow-up</th><th>Action</th></tr></thead><tbody>${tableRows(rows)}</tbody></table></div></div>`;}
function demoControls(){
 if(!isAdmin())return '';
 const d=state.demo||{leads:0,sites:0,has_demo_data:false};
 return `<div class="page-card" style="margin:0 0 18px;padding:17px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;border:1px solid #dcdffb;background:#f8f9ff"><div><strong style="font-size:15px;color:#27305b">Demo data controls <span style="font-size:11px;color:#6c57e8">ADMIN ONLY</span></strong><div class="muted-note" style="margin-top:5px">${d.has_demo_data?`${d.leads} demo enquiries · ${d.sites} demo sources. These are synthetic examples.`:'Preview the CRM with 18 fictional enquiries across admissions stages and 3 example websites.'}</div><div class="muted-note">Clearing demo records does not delete real leads or staff.</div></div><div class="button-row">${btn('＋ Add demo data','add-demo','primary small',d.has_demo_data?'disabled title="Clear demo data first"':'')}${btn('Clear demo data','clear-demo','small',d.has_demo_data?'':'disabled title="No demo records"')}</div></div>`;
}
function dashboard(){const total=state.leads.length, fresh=state.leads.filter(l=>['New','New Enquiry'].includes(l.status)).length, enrolled=state.leads.filter(l=>l.status==='Enrolled').length, due=state.leads.filter(l=>l.followup_date&&l.followup_date<=day()&&!['Enrolled','Lost'].includes(l.status)).length;
 const bySite=state.sites.map(s=>({...s,num:state.leads.filter(l=>l.site_id===s.id).length})).sort((a,b)=>b.num-a.num);
 return `<section class="hero"><div class="hero-copy"><div class="hero-icon">✦</div><div><h1>Every enquiry. One clear journey.</h1><p>Manage the admissions journey at ${esc(school().name)}.</p></div></div><div class="hero-actions">${btn('＋ New enquiry','new-lead','ghost')}${isAdmin()?btn('◉ Connect website','new-site','primary'):''}</div></section>${demoControls()}<div class="stats">${stat('Total enquiries',total,'Across all authorised sources','♧')}${stat('New enquiries',fresh,'Waiting for first contact','✉','blue')}${stat('Follow-ups due',due,'Today or overdue','◷','orange')}${stat('Enrolled',enrolled,total?`${Math.round(enrolled/total*100)}% of leads`:'No conversions yet','✓','green')}</div><div class="v2-metrics"><div class="v2-metric"><b>${ext.analytics.first_contact_pending||0}</b><span>First contact pending</span></div><div class="v2-metric"><b>${ext.analytics.sla_breached||0}</b><span>First-contact SLA breaches</span></div><div class="v2-metric"><b>${ext.visits.filter(v=>v.status==='Scheduled'&&v.starts_at.slice(0,10)>=day()).length}</b><span>Upcoming campus visits</span></div><div class="v2-metric"><b>${ext.families.length}</b><span>Parent / family profiles</span></div><div class="v2-metric"><b>${ext.analytics.conversion_rate||0}%</b><span>Admission conversion</span></div></div><div class="dashboard-rows"><div><div class="section-head"><div><h2>Latest enquiries</h2><p>Shared admissions inbox</p></div>${btn('View all →','go-enquiries','small')}</div>${leadTable(state.leads.slice(0,8))}</div><div class="side-stack"><div class="panel"><div class="panel-head"><div><h3>Website sources</h3><p>Enquiries by origin</p></div></div><div style="padding:6px 17px 17px">${bySite.map(s=>`<div class="site-list-card"><div class="site-icon">${s.platform==='WordPress'?'W':'⌁'}</div><div style="flex:1;min-width:0"><b class="tiny">${esc(short(s.name,28))}</b><div class="muted-note">${esc(short(s.domain,30))}</div></div><b>${s.num}</b></div>`).join('')||'<p class="muted">No sites connected.</p>'}</div></div><div class="panel" style="padding:20px"><h3 style="margin:0">Team on admissions</h3><p class="muted-note" style="margin-top:3px">${state.members.length} authorised team members</p>${state.members.slice(0,5).map(u=>`<div class="site-list-card"><div class="small-avatar">${initials(u.name)}</div><div style="flex:1"><b class="tiny">${esc(u.name)}</b><div class="muted-note">${esc(u.role)}</div></div><b class="muted">${state.leads.filter(l=>l.assigned_to===u.id).length}</b></div>`).join('')}</div></div></div>`;
}
function enquiries(){let leads=state.leads.filter(l=>(state.status==='All'||l.status===state.status)&&(state.siteFilter==='All'||String(l.site_id)===state.siteFilter)&&(ext.temperatureFilter==='All'||(l.temperature||'Unrated')===ext.temperatureFilter)&&(!state.search||[l.name,l.guardian,l.phone,l.grade,l.email].join(' ').toLowerCase().includes(state.search.toLowerCase()))&&(ext.assignedFilter==='All'||(ext.assignedFilter==='Unassigned'?!l.assigned_to:String(l.assigned_to)===ext.assignedFilter))&&(ext.contactFilter==='All'||(ext.contactFilter==='Pending'?!l.last_successful_contact_at:!!l.last_successful_contact_at))&&(ext.dueFilter==='All'||(ext.dueFilter==='Overdue'?!!l.followup_date&&l.followup_date<day():ext.dueFilter==='Today'?l.followup_date===day():ext.dueFilter==='Upcoming'?l.followup_date>day():!l.followup_date)));
 return `${pageHead('Enquiries', 'Search, assign and track school admissions enquiries.',btn('＋ Add enquiry','new-lead','primary')+btn('⇩ Export Excel','export-xlsx','primary')+btn('CSV','export-csv','small'))}<div class="filters button-row"><input id="q" type="search" placeholder="Search name, guardian, phone or class" value="${esc(state.search)}"><select id="statusFilter">${['All',...STAGES].map(s=>option(s,s==='All'?'All stages':s,state.status)).join('')}</select><select id="siteFilter">${option('All','All sources',state.siteFilter)}${state.sites.map(s=>option(s.id,s.name,state.siteFilter)).join('')}</select><select id="assignedFilter">${option('All','All counsellors',ext.assignedFilter)}${option('Unassigned','Unassigned',ext.assignedFilter)}${state.members.map(m=>option(m.id,m.name,ext.assignedFilter)).join('')}</select><select id="dueFilter">${['All','Overdue','Today','Upcoming','Unscheduled'].map(x=>option(x,x==='All'?'All follow-ups':x,ext.dueFilter)).join('')}</select><select id="temperatureFilter">${['All',...TEMPERATURES].map(t=>option(t,t==='All'?'All temperatures':t,ext.temperatureFilter)).join('')}</select><select id="contactFilter">${option('All','All contact status',ext.contactFilter)}${option('Pending','Never contacted',ext.contactFilter)}${option('Contacted','Previously contacted',ext.contactFilter)}</select><span class="muted-note">${leads.length} result(s)</span></div>${leadTable(leads)}`;
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
function routingPage(){
 if(!canManageSchools())return '<div class="empty-state">School management requires administrator access.</div>';
 const rows=ext.schoolsOverview||[],groups=ext.groupSites||[];
 return `${pageHead('Schools & lead routing','Connect one or many campus websites to the right school, with admin-controlled routing.',btn('＋ Add school','new-school','primary'))}
 <div class="app-alert">For a school group website, connect the permitted campuses below. Copy the public one-tag script into the website head; no Google Script, WordPress plugin or HFCM required. The older Contact Form 7 connector remains optional. A parent's choice can route only to an approved campus.</div>
 <section class="page-card" style="margin-bottom:20px"><div class="section-head" style="margin-top:0"><div><h2>Group websites</h2><p>One form, one private group key, multiple school destinations</p></div>${btn('＋ Connect group website','new-group','primary small')}</div>
 ${ext.groupKey?`<div class="key-panel"><strong>New group website key — copy and save now</strong><p class="muted-note">This secret is shown once. Set SCHOOLFLOW_CRM_GROUP_SITE_KEY on the WordPress server, never in HTML.</p><div class="key-box">${esc(ext.groupKey)}</div><div class="button-row" style="margin-top:10px">${btn('Copy key','copy-group-key','primary small')}${btn('I saved the key','dismiss-group-key','small')}</div></div>`:''}
 <div class="route-school-grid">${groups.map(g=>`<div class="route-school-card"><div class="route-school-head"><div><h3>${esc(g.name)}</h3><p class="muted-note">${esc(g.domain)} · Group #${Number(g.id)}</p></div><span class="badge ${g.enabled?'':'route-inactive'}">${g.enabled?'Active':'Disabled'}</span></div><div class="muted-note" style="margin:8px 0 4px"><b>GUID form embed</b> — script assets refresh automatically. This GUID is safe to publish. Never use the private sfgroup key in HTML.</div><code style="display:block;overflow-wrap:anywhere;white-space:normal;font-size:11px;margin:8px 0">${esc(sfGroupEmbed(g.guid||'')) }</code><div class="button-row" style="margin-bottom:10px">${btn("Copy head script","copy-group-embed","small",`data-id="${Number(g.id)}"`)}</div><div class="route-sources">${g.schools.map(c=>`<div class="route-source"><span><b>${esc(c.name)}</b><small>Choice code: ${esc(c.code)}</small></span><span class="role-label">School #${Number(c.school_id)}</span></div>`).join('')}</div><div class="button-row">${btn('Edit schools','edit-group','small',`data-id="${Number(g.id)}"`)}${btn('Refresh Form ID','refresh-group-form-id','small',`data-id="${Number(g.id)}"`)}${btn('Rotate key','rotate-group-key','small',`data-id="${Number(g.id)}"`)}${btn(g.enabled?'Disable':'Enable','toggle-group','small',`data-id="${Number(g.id)}"`)}</div></div>`).join('')||'<p class="muted-note">No group websites configured. Add one to route parents from a single school selector.</p>'}</div>
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

function reportsPage(){const total=state.leads.length, enrolled=state.leads.filter(l=>l.status==='Enrolled').length, byStage=STAGES.map(s=>({name:s,n:state.leads.filter(l=>l.status===s).length})), max=Math.max(1,...byStage.map(x=>x.n));
 return `${pageHead('Admissions reports','Source attribution and live funnel performance.',btn('⇩ Export Excel','export-xlsx','primary')+btn('CSV','export-csv','small'))}${admissionInsights()}<div class="stats" style="margin:0 0 20px">${stat('Total enquiries',total,'All recorded enquiries','♧')}${stat('Enrolled',enrolled,'Successful admissions','✓','green')}${stat('Conversion rate',total?Math.round(enrolled/total*100)+'%':'0%','Enrolled / total enquiries','↗','blue')}${stat('Active sources',state.sites.filter(s=>s.enabled).length,'Connected intake channels','◉','orange')}</div><div class="two-cols"><div class="panel" style="padding:22px"><h3>Admissions funnel</h3><p class="muted-note">Enquiries currently in each stage</p>${byStage.map(x=>`<div class="bar-row"><span>${esc(x.name)}</span><div class="bar-track"><i style="width:${x.n/max*100}%"></i></div><b>${x.n}</b></div>`).join('')}</div><div class="panel" style="padding:22px"><h3>Lead sources</h3><p class="muted-note">Captured by connected website</p>${state.sites.map(s=>`<div class="site-list-card"><div class="site-icon">${s.platform==='WordPress'?'W':'⌁'}</div><div style="flex:1"><b>${esc(s.name)}</b><div class="muted-note">${esc(s.domain)}</div></div><b>${s.lead_count}</b></div>`).join('')||'<p class="muted">No website data yet.</p>'}</div></div><section class="panel" style="padding:19px;margin-top:18px"><h3>Performance by counsellor</h3><div class="report-staff-grid">${Object.entries(ext.analytics.by_staff||{}).map(([id,n])=>`<div class="v2-metric"><b>${n}</b><span>${esc(id==='unassigned'?'Unassigned':userName(Number(id)))} enquiries</span></div>`).join('')||'<p class="muted">No assignments yet</p>'}</div><p class="muted-note">First contact pending: ${ext.analytics.first_contact_pending||0} · Follow-ups due: ${ext.analytics.followups_due||0}</p></section>${isAdmin()?`<section class="audit-school aria-label="School activity log"><div class="section-head"><div><h2>School activity log</h2><p>Admin-only history of important account, website and enquiry actions.</p></div></div><div class="panel" style="padding:18px 20px">${state.audit?auditEntries(state.audit.events):'<p class="muted">Loading audit history…</p>'}${state.audit?.has_more?'<div class="audit-more"><button class="btn small" data-action="more-school-audit">Load older activity</button></div>':''}</div></section>`:''}`;}

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
 return `<div class="drawer-overlay" id="drawerOverlay" data-action="close-detail"><div class="drawer" role="dialog" aria-modal="true" aria-labelledby="sf-drawer-title" style="overflow:auto;max-height:100vh"><div class="drawer-head"><div><div class="eyebrow">ENQUIRY ${esc(l.guid?'#'+l.guid.slice(0,8):'#'+l.id)}</div><h2 id="sf-drawer-title" style="margin:4px 0">${esc(l.name)}</h2><p>${esc(l.grade||'Class not specified')} · ${esc(l.source_name||'Manual entry')}</p></div><button class="icon-btn" aria-label="Close enquiry details" data-action="close-detail">✕</button></div><div class="detail-body"><div class="next-action-strip"><strong>Next action</strong><span>${esc(l.followup_status||"Pending Contact")}${l.followup_date?" · "+esc(dateFmt(l.followup_date)):" · Schedule the next contact or close the enquiry"}</span></div><nav class="detail-jump" aria-label="Enquiry sections"><a href="#sf-contact">Contact</a><a href="#sf-admission">Admission</a><a href="#sf-history">History</a></nav><div class="detail-meta" id="sf-contact"><div><small>Guardian</small><strong>${esc(l.guardian)}</strong></div><div><small>Contact</small><strong>${esc(l.phone)}</strong></div><div><small>Email</small><strong>${esc(l.email||'Not provided')}</strong></div><div><small>Received</small><strong>${esc(l.created_at.slice(0,16).replace('T',' '))} UTC</strong></div>${l.duplicate_of?`<div style="grid-column:1/-1"><small>Potential duplicate</small><strong>Same contact as enquiry #${l.duplicate_of}</strong></div>`:''}${l.message?`<div style="grid-column:1/-1"><small>Message</small><strong>${esc(l.message)}</strong></div>`:''}</div>${contactActions(l)}${(d.messages||[]).length?`<div class="page-card"><b>Message delivery attempts</b>${d.messages.map(x=>`<div class="muted-note">${esc(x.channel)} — ${esc(x.status)} · ${esc(auditStamp(x.created_at))} (provider receipt not guaranteed)</div>`).join('')}</div>`:''}${familyDetails(d)}${callVisitDetails(d)}<h3 class="section-title" id="sf-admission">Application progress</h3><div class="page-card">${(()=>{const app=(ext.applications||[]).find(a=>a.lead_id===l.id);return app?`<div><b>Application:</b> ${esc(app.status)}${app.fee_recorded?' · Fee recorded':''}</div><form id="applicationForm"><input type="hidden" name="application_id" value="${app.id}">${select('Application milestone','status',['Started','Submitted','Documents Pending','Under Review','Offer Issued','Admission Confirmed','Enrolled','Rejected'].map(v=>option(v,v,app.status)).join(''))}${role()==='Admin'||role()==='Admissions'?'<label><input type="checkbox" name="fee_recorded" value="true"> Fee verified and recorded</label>':''}<button class="btn small" type="submit">Save milestone</button></form>`:`<button class="btn small" data-action="create-application" data-id="${l.id}">Start application</button>`;})()}</div><h3 class="section-title">Admission document checklist</h3>
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
 const [settings,provider,review,groups]=await Promise.all([
   api(base),api(base+'/provider'),api(base+'/review'),api('/api/group-sites')
 ]);
 ext.chatSettings=settings;ext.chatProvider=provider;ext.chatQueue=review.items||[];ext.chatGroups=Array.isArray(groups)?groups:[];
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
function publicChatPage(){
 if(!isAdmin())return '<div class="empty-state">School administrator access required.</div>';
 const settings=ext.chatSettings;
 if(!settings)return pageHead('Public Admissions AI','Loading school knowledge and unanswered topics…');
 const faqs=settings.faqs||[];
 const groups=(ext.chatGroups||[]).filter(g=>(g.schools||[]).some(s=>Number(s.school_id)===Number(state.schoolId)));
 const row=(q='',a='')=>'<div class="sf-chat-faq panel" style="padding:15px;margin-bottom:10px">'+
 '<div class="form-field"><label>Parent question</label><input class="sf-chat-q" maxlength="180" value="'+esc(q)+'" placeholder="e.g. Which documents are required?"></div>'+
 '<div class="form-field"><label>Approved answer</label><textarea class="sf-chat-a" maxlength="800" rows="2" placeholder="Only verified school information">'+esc(a)+'</textarea></div>'+
 '<button class="btn small" type="button" data-action="chat-remove-faq">Remove</button></div>';
 return pageHead('Public Admissions AI','School-specific chatbot settings and approved answers. No AI provider is required for verified FAQ matches.')+
 '<div class="app-alert">Answers published here are visible to parents. Check fees, documents, dates and policies before publishing. Past questions enter a private review queue and never publish automatically.</div>'+
 '<form id="chatSettingsForm" class="panel" style="padding:20px;margin-bottom:18px">'+
 '<h3>Assistant publication</h3><label><input type="checkbox" name="enabled" '+(settings.enabled?'checked':'')+'> Enable admissions chatbot for this campus</label>'+'<label><input type="checkbox" name="ai_enabled" '+(settings.ai_enabled?'checked':'')+'> Allow external AI for general, nonpersonal questions (optional; local FAQs work without it)</label>'+
 '<div class="form-field"><label>Verified school overview</label><textarea name="overview" maxlength="1500" rows="4">'+esc(settings.overview||'')+'</textarea></div>'+
 '<h3>Published questions and answers</h3><p class="muted-note">Starter Q&As are safe handoff templates. Review each answer for your campus before saving.</p><div id="sfChatFaqs">'+(faqs.length?faqs.map(f=>row(f.question,f.answer)).join(''):row())+'</div>'+
 '<div class="button-row"><button type="button" class="btn small" data-action="chat-add-faq">＋ Add FAQ</button><button type="button" class="btn small" data-action="chat-starter-faq">Add 8 starter Q&As</button>'+
 '<button type="submit" class="btn primary">Save chatbot settings</button></div></form>'+
 '<form id="chatProviderForm" class="panel" style="padding:20px;margin-bottom:18px">'+
 '<h3>OpenRouter AI provider (optional)</h3>'+
 '<p class="muted-note">OpenRouter answers only general admissions questions using published campus knowledge. Parent names, phone numbers and lead records are never included in requests. Verified local FAQ answers work without a provider.</p>'+
 '<p><b>Connection:</b> '+(ext.chatProvider?.connected?'Key saved securely':'Not connected')+'</p>'+
 '<div class="form-field"><label>OpenRouter model ID</label><input name="model" maxlength="100" required value="'+esc(ext.chatProvider?.model||'openai/gpt-4o-mini')+'" placeholder="openai/gpt-4o-mini"></div>'+
 '<div class="form-field"><label>API key (leave blank to keep existing key)</label><input name="api_key" type="password" maxlength="1024" autocomplete="new-password" placeholder="sk-or-…"></div>'+
 '<label><input type="checkbox" name="remove_key"> Disconnect and remove saved OpenRouter key</label>'+
 '<button type="submit" class="btn primary">Save AI connection</button></form>'+
'<section class="panel" style="padding:20px;margin-bottom:18px"><h3>Unanswered question review</h3>'+
 '<p class="muted-note">Topics are filtered for personal details; approve only after writing a verified answer. Reject unsuitable topics.</p>'+
 ((ext.chatQueue||[]).length?ext.chatQueue.map(item=>
  '<form class="sfChatReview" data-id="'+esc(item.id)+'" style="padding:12px 0;border-bottom:1px solid #e3e5ef">'+
  '<p><b>'+esc(item.question)+'</b> · '+Number(item.count||1)+' requests</p>'+
  '<textarea name="answer" maxlength="800" rows="2" placeholder="Verified school response for approval"></textarea>'+
  '<div class="button-row"><button type="submit" class="btn small primary">Approve & publish</button>'+
  '<button type="button" class="btn small" data-action="chat-reject-topic" data-id="'+esc(item.id)+'">Reject</button></div></form>').join(''):'<p class="muted-note">No unanswered topics waiting for approval.</p>')+
 '</section>'+
 '<section class="panel" style="padding:20px"><h3>One-script website integration</h3><p class="muted-note">The GUID embed serves both the enquiry form and the chatbot with automatically updated script versions. Older lexicon-form.js installations remain compatible. Only approved group websites can display it.</p>'+
 (groups.length?groups.map(g=>{const snippet=sfGroupEmbed(g.guid);
 return '<p><strong>'+esc(g.name)+'</strong> · '+esc(g.domain)+'</p><pre style="overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere">'+esc(snippet)+'</pre>'+
 '<button type="button" class="btn small" data-action="chat-copy-embed" data-id="'+Number(g.id)+'">Copy embed</button>'}).join(''):'<p class="muted-note">Connect a group website under Schools & Routing to obtain its embed snippet.</p>')+'</section>';
}

function render(){if(state.setup===null){root.innerHTML='<div class="login-wrap"><div class="login-panel"><h2>Connecting to SchoolFlow…</h2><p>Checking your application setup.</p></div></div>';return;}if(state.setup.needs_setup||!state.token||!state.me){root.innerHTML=loginPage();return;}
 if(state.me.user.must_change_password){
  root.innerHTML='<div class="login-wrap"><div class="login-panel"><h2>Change temporary password</h2><p>Your administrator reset your password. You must choose a new password before accessing school records.</p>'+
  '<form id="mandatoryPasswordForm">'+input('Temporary password','current_password','password','',true)+
  input('New password (12+ characters)','new_password','password','',true)+
  input('Confirm new password','confirm_password','password','',true)+
  '<button class="btn primary full-btn" type="submit">Save new password</button></form><button class="btn small" data-action="logout">Sign out</button></div></div>';return;
 }
 const pages={'Dashboard':dashboard,'My Work':myWork,'Enquiries':enquiries,'Pipeline':pipeline,'Follow-ups':followups,'Tasks':tasksPage,'Websites & Forms':sitesPage,'Schools & Routing':routingPage,'School Visits':schoolVisits,'Families':familyPage,'Email Templates':templatesPage,'Communications':communicationsPage,'Automations':automationsPage,'Team':teamPage,'Reports':reportsPage,'Import Enquiries':importPage,'Seat Planning':seatPlanning,'Admissions AI':publicChatPage};
 const addSchool=canManageSchools() && state.page!=='Schools & Routing'?`<button class="btn small" data-action="new-school" style="margin-left:8px">＋ Add school</button>`:'';
 root.innerHTML=`<div class="app">${nav()}<div class="main">${topbar()}<main class="content"><div style="text-align:right;margin-bottom:12px">${addSchool}</div>${(pages[state.page]||dashboard)()}</main></div></div>${modal()}${detailDrawer()}`;
}
async function loadRouting(){[ext.schoolsOverview,ext.groupSites]=await Promise.all([api('/api/schools/overview'),api('/api/group-sites')]);render();}
async function perform(task){try{await task();}catch(e){toast(e.message,true);}}
function closeDialog(){state.modal='';state.detail=null;render();}
async function xlsxExport(){
 const filtering=state.page==='Enquiries';
 const filters=filtering?{
   status:state.status,temperature:ext.temperatureFilter,site:state.siteFilter,
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
async function csvExport(){await api(`/api/schools/${state.schoolId}/audit`,'POST',{event:'export_enquiries'});const lines=[['ID','Student','Guardian','Phone','Email','Class','Source','Status','Assigned','Follow-up','Created','Potential Duplicate'],...state.leads.map(l=>[l.id,l.name,l.guardian,l.phone,l.email,l.grade,l.source_name||'Manual',l.status,l.assigned_name||'',l.followup_date,l.created_at,l.duplicate_of||''])];
 const csv='\ufeff'+lines.map(r=>r.map(v=>'"'+( /^[\s]*[=+\-@]/.test(String(v??'')) ? "\'"+String(v??'') : String(v??'') ).replaceAll('"','""')+'"').join(',')).join('\r\n');let url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));let a=document.createElement('a');a.href=url;a.download=`schoolflow-school-${state.schoolId}-enquiries.csv`;a.click();URL.revokeObjectURL(url);toast('CSV exported');}
root.addEventListener('click',e=>{let el=e.target.closest('[data-action]');if(!el)return;let action=el.dataset.action;

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
 if(action==='chat-add-faq'){const area=root.querySelector('#sfChatFaqs');if(!area)return;const current=area.querySelectorAll('.sf-chat-faq').length;if(current>=60)return toast('Maximum 60 published FAQs',true);const template=area.querySelector('.sf-chat-faq');if(!template)return;const clone=template.cloneNode(true);clone.querySelectorAll('input,textarea').forEach(x=>x.value='');area.appendChild(clone);return;}
 if(action==='chat-remove-faq'){el.closest('.sf-chat-faq')?.remove();return;}
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
 if(action==='copy-group-embed'){
   const group=ext.groupSites.find(x=>x.id===Number(el.dataset.id));if(!group?.guid){toast('Group GUID not available yet',true);return;}
   const code=sfGroupEmbed(group.guid);
   navigator.clipboard.writeText(code).then(()=>toast('Head script copied')).catch(()=>toast('Copy blocked by the browser; select the code on the card',true));return;
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
 if(e.target.id==='siteFilter'){state.siteFilter=e.target.value;render();}
});
root.addEventListener('input',e=>{
 if(e.target.id==='q'){const p=e.target.selectionStart;state.search=e.target.value;render();const q=root.querySelector('#q');q?.focus();q?.setSelectionRange(p,p);}
});
root.addEventListener('submit',e=>{e.preventDefault();const f=e.target;let fd=Object.fromEntries(new FormData(f));

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
 if(f.id==='chatSettingsForm'){
   if(!isAdmin())return;
   perform(async()=>{
     const faqs=[...f.querySelectorAll('.sf-chat-faq')].map(card=>({
       question:card.querySelector('.sf-chat-q')?.value.trim()||'',
       answer:card.querySelector('.sf-chat-a')?.value.trim()||''
     })).filter(x=>x.question||x.answer);
     if(faqs.some(x=>!x.question||!x.answer))throw new Error('Complete both the question and answer for each FAQ');
     await api('/api/schools/'+state.schoolId+'/public-chat','PATCH',{
       enabled:!!f.elements.enabled.checked,ai_enabled:!!f.elements.ai_enabled.checked,overview:fd.overview||'',faqs
     });
     await loadPublicChat();toast('Admissions chatbot settings saved');
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
