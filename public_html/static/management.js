/* SchoolFlow Management Intelligence — no external dependencies, server-authorized data. */
(()=>{
'use strict';
const S={report:null,leads:null,detail:null,tab:'Overview',filters:{period:'month',school_id:'all'},request:0};
const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
const num=v=>Number(v||0).toLocaleString('en-IN');
const pct=(v,n)=>n?(100*v/n).toFixed(1)+'%':'—';
const field=(label,value)=>'<label>'+escape(label)+value+'</label>';
const sel=(name,title,entries)=>field(title,'<select name="'+name+'">'+entries.map(([v,t])=>
 '<option value="'+escape(v)+'" '+(String(S.filters[name]??'all')===String(v)?'selected':'')+'>'+escape(t)+'</option>').join('')+'</select>');
const table=(labels,rows)=>'<div class="mg-table-wrap"><table class="mg-table"><thead><tr>'+
 labels.map(t=>'<th>'+escape(t)+'</th>').join('')+'</tr></thead><tbody>'+
 (rows.length?rows.join(''):'<tr><td colspan="'+labels.length+'"><div class="mg-empty">No records for these filters</div></td></tr>')+
 '</tbody></table></div>';
const card=(title,content,caption='')=>'<section class="mg-card"><div class="mg-head"><h2>'+escape(title)+'</h2><small>'+escape(caption)+'</small></div>'+content+'</section>';
const pairs=(data)=>table(['Category','Enquiries'],(data||[]).map(x=>'<tr><td>'+escape(x.name)+'</td><td>'+num(x.count)+'</td></tr>'));
const kpi=(title,value,info)=>'<div class="mg-card"><small>'+escape(title)+'</small><strong>'+num(value)+'</strong><span>'+escape(info)+'</span></div>';
function query(extra={}){
 const params=new URLSearchParams();
 for(const [k,v] of Object.entries({...S.filters,...extra}))if(v!==''&&v!=null)params.set(k,String(v));
 return '?'+params.toString();
}
async function load(api,repaint){
 const id=++S.request;
 const r=await api('/api/management/overview'+query());
 if(id!==S.request)return;
 S.report=r;
 if(S.tab==='Leads')await loadLeads(api,repaint);
 else repaint();
}
async function loadLeads(api,repaint){
 const id=++S.request;
 const r=await api('/api/management/leads'+query());
 if(id!==S.request)return;
 S.leads=r;repaint();
}
async function click(action,dataset,api,repaint){
 if(action==='management-tab'){S.tab=dataset.tab;S.detail=null;if(S.tab==='Leads')await loadLeads(api,repaint);else repaint();return true;}
 if(action==='management-period'){S.filters.period=dataset.period;if(dataset.period==='custom'){S.filters.from=S.report?.filters?.from||'';S.filters.to=S.report?.filters?.to||'';}else{delete S.filters.from;delete S.filters.to;}S.filters.page=1;S.leads=null;await load(api,repaint);return true;}
 if(action==='management-refresh'){await load(api,repaint);return true;}
 if(action==='management-prev'||action==='management-next'){
  if(!S.leads)return true;
  const newPage=S.leads.page+(action==='management-next'?1:-1);
  if(newPage<1||newPage>S.leads.pages)return true;
  S.filters.page=newPage;await loadLeads(api,repaint);return true;
 }
 if(action==='management-lead'){S.detail=await api('/api/management/leads/'+Number(dataset.id));repaint();return true;}
 if(action==='management-close-detail'){S.detail=null;repaint();return true;}
 return false;
}
async function submit(fd,api,repaint){
 S.filters={...S.filters,...fd,page:1};
 if(S.filters.period!=='custom'){delete S.filters.from;delete S.filters.to;}
 S.detail=null;S.leads=null;await load(api,repaint);
}
function detail(){
 const l=S.detail;if(!l)return '';
 const keys=[['Student',l.student],['School',l.school],['Grade',l.grade],['Guardian',l.guardian],
 ['Contact',l.phone_masked],['Counsellor',l.assigned],['Status',l.status],['Temperature',l.temperature],
 ['Last call outcome',l.last_call_outcome],['Follow-up date',l.followup_date],['Campaign',l.utm_campaign],
 ['Landing page',l.landing_page],['Reason',l.disqualification_reason]];
 return card('Read-only enquiry detail','<button class="btn small" data-action="management-close-detail">← Back to reports</button>'+
 '<div class="mg-detail"><div class="mg-data">'+keys.map(([k,v])=>'<div><small>'+escape(k)+'</small>'+escape(v||'—')+'</div>').join('')+'</div>'+
 '<h3>Calling history</h3>'+table(['When','Outcome','Counsellor','Call summary'],(l.history||[]).map(e=>
 '<tr><td>'+escape(e.at)+'</td><td>'+escape(e.outcome)+'</td><td>'+escape(e.by)+'</td><td style="white-space:normal">'+escape(e.note)+'</td></tr>'))+
 '<p class="mg-note">Contact details are masked. This workspace cannot edit, message, or export records.</p></div>');
}
function view(){
 const r=S.report;
 if(!r)return '<div class="sf-mgmt">'+card('Management Intelligence','<p class="mg-empty">Loading authorized school reports…</p>')+'</div>';
 const f=S.filters,m=r.summary||{};
 const period=['today','week','month','year','custom'];
 const pnames=['Today','Week','Month','This year','Custom'];
 const tabs=['Overview','Schools','Calling','Follow-ups','Sources','Admissions','Counsellors','Leads'];
 const hero='<div class="mg-hero"><div class="mg-kicker">SCHOOLFLOW · EXECUTIVE INTELLIGENCE</div>'+
 '<h1>Admissions Intelligence</h1><p class="mg-sub">School performance, admissions conversion and counsellor accountability across your assigned campuses.</p>'+
 '<div class="mg-periods">'+period.map((p,i)=>'<button type="button" data-action="management-period" data-period="'+p+'" aria-pressed="'+(f.period===p)+'">'+pnames[i]+'</button>').join('')+'</div></div>';
 const schoolOpts=[['all','All assigned schools'],...(r.schools||[]).map(x=>[String(x.id),x.name])];
 const filters='<form id="managementFilters" class="mg-filter">'+
 '<input name="period" type="hidden" value="'+escape(f.period)+'">'+
 sel('school_id','School',schoolOpts)+sel('source_type','Lead source',
 [['all','All sources'],['group_website','Group website'],['individual_website','School website'],['chatbot','Chatbot'],['manual','Manual'],['import','Import'],['other','Other']])+
 sel('form_type','Form',[['all','All forms'],['inline','Inline'],['popup','Popup'],['api','API'],['chat','Chat'],['manual','Manual'],['import','Import'],['unknown','Unknown']])+
 field('Campaign','<input name="campaign" value="'+escape(f.campaign||'')+'" placeholder="All campaigns">')+
 field('Class','<input name="grade" value="'+escape(f.grade||'')+'" placeholder="All classes">')+
 (f.period==='custom'?field('From','<input type="date" required name="from" value="'+escape(f.from||r.filters.from)+'">')+
 field('To','<input type="date" required name="to" value="'+escape(f.to||r.filters.to)+'">'):'')+
 '<button class="btn primary" type="submit">Apply filters</button><button class="btn small" type="button" data-action="management-refresh">Refresh</button></form>';
 const counters='<div class="mg-grid">'+[
 ['Total enquiries',m.enquiries,'Created in the selected period'],['Confirmed admissions',m.confirmed,'Current stage of enquiry cohort'],
 ['Hot leads',m.hot,'Current classification of cohort'],['Overdue follow-ups',m.overdue,'Current open backlog']
 ].map(x=>kpi(...x)).join('')+'</div>';
 const nav='<div class="mg-tabbar">'+tabs.map(t=>'<button type="button" data-action="management-tab" data-tab="'+t+'" aria-pressed="'+(S.tab===t)+'">'+t+'</button>').join('')+'</div>';
 const schools=card('School performance',table(['School','Enquiries','Confirmed %','Overdue','SLA breaches'],
 (r.school_performance||[]).map(x=>'<tr><td>'+escape(x.name)+'</td><td>'+num(x.enquiries)+'</td><td>'+pct(x.confirmed,x.enquiries)+'</td><td>'+num(x.overdue)+'</td><td>'+num(x.sla_breached)+'</td></tr>')),'Cohort and current backlog');
 const trend=r.trend||[],max=Math.max(1,...trend.map(x=>x.count));
 const graph=card('Enquiry trend','<div class="mg-chart" role="img" aria-label="Enquiries recorded during the selected reporting period">'+
 trend.slice(-48).map(x=>'<div class="mg-bar" style="height:'+Math.max(3,Math.round(x.count/max*100))+'%" title="'+escape(x.date)+': '+num(x.count)+' enquiries"></div>').join('')+'</div>'+
 '<div class="mg-chart-label">'+escape(trend[0]?.date||'No activity')+' — '+escape(trend[trend.length-1]?.date||'')+'</div>','Enquiry creation date');
 const attention=card('Needs management attention','<div class="mg-attention">'+
 [['Overdue follow-ups',m.overdue],['First-contact SLA breaches',m.sla_breached],['Unassigned new enquiries',m.unassigned],['Hot new enquiries',m.hot]].map(([name,value],i)=>
 '<div class="mg-alert"><b>'+escape(name)+'</b><span class="mg-chip '+(i<2&&value?'critical':'')+'">'+num(value)+'</span></div>').join('')+'</div>','Operational exceptions');
 const calling=card('Call outcomes',pairs(r.call_outcomes||[]),num(m.calls)+' calls during period');
 const recent=card('Recent enquiries',table(['Student','School','Source','Status','Action'],(r.recent_leads||[]).map(x=>
 '<tr><td>'+escape(x.student)+'</td><td>'+escape(x.school)+'</td><td>'+escape(x.source_name)+'</td><td>'+escape(x.status)+'</td><td><button class="mg-link" data-action="management-lead" data-id="'+x.id+'">View</button></td></tr>')));
 let body='';
 switch(S.tab){
 case 'Schools':body=schools;break;
 case 'Calling':body='<div class="mg-panels">'+calling+card('Calling metrics','<div class="mg-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">'+
 [['Call attempts',m.calls],['Connected calls',m.connected_calls],['Distinct leads called',m.unique_called_leads],['Cohort contacted',m.contacted]].map(x=>kpi(x[0],x[1],'')).join('')+'</div>')+'</div>';break;
 case 'Follow-ups':body=card('Follow-up status','<div class="mg-grid">'+[['Overdue',m.overdue],['Due today',m.due_today],['Upcoming seven days',m.due_next_7_days],['Unscheduled',m.unscheduled]].map(x=>kpi(x[0],x[1],'Open lead backlog')).join('')+'</div>')+
 card('Priority follow-ups',table(['Student','School','Issue','Due'],(r.attention||[]).map(x=>
 '<tr><td><button class="mg-link" data-action="management-lead" data-id="'+x.lead_id+'">'+escape(x.student)+'</button></td><td>'+escape(x.school)+'</td><td>'+escape(x.kind)+'</td><td>'+escape(x.date||'—')+'</td></tr>')));break;
 case 'Sources':body='<div class="mg-panels">'+card('Origin',pairs(r.sources))+card('Form surfaces',pairs(r.forms))+'</div><div class="mg-panels">'+card('Campaigns',pairs(r.campaigns))+card('Landing pages',pairs(r.landing_pages))+'</div>';break;
 case 'Admissions':body='<div class="mg-panels">'+card('Admission stages',pairs(r.stages))+card('Disqualification reasons',pairs(r.disqualification_reasons))+'</div>'+
 card('Lead disposition',pairs([['Hot',m.hot],['Warm',m.warm],['Cold',m.cold],['Unrated',m.unrated],['Disqualified',m.disqualified]].map(([name,count])=>({name,count}))));break;
 case 'Counsellors':body=card('Counsellor / caller performance',table(['Staff','Assigned','Contacted','Confirmed','Calls placed'],(r.staff||[]).map(x=>
 '<tr><td>'+escape(x.name)+'</td><td>'+num(x.assigned)+'</td><td>'+num(x.contacted)+'</td><td>'+num(x.confirmed)+'</td><td>'+num(x.calls_placed)+'</td></tr>')));break;
 case 'Leads':{
  const d=S.leads;
  body=card('Read-only lead register',d?table(['Student','School','Class','Campaign','Status','Follow-up','Detail'],(d.rows||[]).map(x=>
  '<tr><td>'+escape(x.student)+'</td><td>'+escape(x.school)+'</td><td>'+escape(x.grade)+'</td><td>'+escape(x.utm_campaign||'—')+'</td><td>'+escape(x.status)+'</td><td>'+escape(x.followup_date||'—')+'</td><td><button class="mg-link" data-action="management-lead" data-id="'+x.id+'">View</button></td></tr>'))+
  '<div class="button-row" style="margin-top:12px"><button class="btn small" data-action="management-prev" '+(d.page<=1?'disabled':'')+'>Previous</button>'+
  '<span>'+d.page+' / '+d.pages+' · '+num(d.total)+' leads</span><button class="btn small" data-action="management-next" '+(d.page>=d.pages?'disabled':'')+'>Next</button></div>':'<p class="mg-empty">Loading enquiries…</p>');break;
 }
 default:body='<div class="mg-panels">'+graph+attention+'</div>'+schools+'<div class="mg-panels">'+calling+recent+'</div>';
 }
 return '<div class="sf-mgmt">'+hero+filters+counters+nav+detail()+body+
 '<p class="mg-note">Reporting dates '+escape(r.filters.from)+' to '+escape(r.filters.to)+' (Asia/Kolkata). Enquiries use creation date; calls use call date; overdue/SLA counts reflect the present backlog. Confirmed stages are current snapshots, not historical point-in-time conversion. Demo records are excluded.</p></div>';
}
window.SFManagementUI={view,load,click,submit,isReady:()=>!!S.report,reset:()=>{S.report=null;S.leads=null;S.detail=null;S.request++;}};
})();
