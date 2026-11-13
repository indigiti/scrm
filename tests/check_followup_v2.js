'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const src=fs.readFileSync('public_html/static/app.js','utf8');
const css=fs.readFileSync('public_html/static/style.css','utf8');
const php=fs.readFileSync('app_private/api.php','utf8');
const work=fs.readFileSync('app_private/admission_workflow.php','utf8');
const begin=src.indexOf('/** Follow-up V2: one atomic save');
const end=src.indexOf('function detailDrawer(){',begin);
assert(begin>=0&&end>begin,'Follow-up V2 workspace is installed');
const esc=v=>String(v??'').replace(/[&<>"']/g,ch=>
 ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const render=vm.runInNewContext(src.slice(begin,end)+'\nfollowupWorkspace',
 {esc,auditStamp:()=>'12 Nov 2026',btn:()=>'<button>Book visit</button>'});
const lead={id:3,temperature:'Warm',status:'New',followup_date:'2026-11-21'};
const hidden=render({lead,calls:[],visits:[]});
assert(hidden.includes('Save follow-up')&&hidden.includes('Next follow-up date'));
assert(hidden.includes('Lead temperature / disposition')&&hidden.includes('name="disposition"')&&!hidden.includes('id="sfDisqualDialog"'));
for(const temp of ['Hot','Warm','Cold'])assert.match(hidden,new RegExp('<button[^>]*class="sf-disposition-choice[^"]*" data-action="followup-pick-disposition" data-value="'+temp+'"'));
assert.match(hidden,/<button[^>]* disabled[^>]*data-value="Disqualified"/);
const closed=render({lead:{...lead,status:'Not Eligible'},calls:[],visits:[]});
assert.match(closed,/name="disposition" value="Warm"/);
const html=render({lead,calls:[],visits:[]},true);
for(const part of ['id="followupV2Form"','Call outcome','Call summary','required minlength="8"',
 'name="followup_date"','data-value="Hot"','data-value="Warm"','data-value="Cold"',
 'data-value="Disqualified"','id="sfDisqualDialog"','aria-modal="true"','Save follow-up']) {
 assert(html.includes(part),'Missing first-screen field: '+part);
}
assert(!html.includes('Parent communication'),'Parent communication must not be in Follow-up V2 workspace');
const chosen=render({lead:{...lead,status:'Not Eligible',disqualification_reason:'Fees',
 disqualification_note:'Reason <unsafe>'},calls:[],visits:[]},true);
assert(chosen.includes('value="Disqualified"'),'Disqualified must reopen as selected');
assert(chosen.includes('value="Fees" selected'),'Existing disqualification reason must be preselected');
assert(!chosen.includes('<unsafe>'),'Stored reasons must be escaped');
assert(src.includes("${beginPanel('followup')}${followupWorkspace(d,ext.enquiryPanels?.preferences?.followup_disposition===true)}"),
 'First lead tab must use the new follow-up workspace');
assert(src.includes("${beginPanel('more')}${drawerPanelStart('communication'"),
 'Parent communication must be moved to More');
assert(src.includes("if(action==='view-lead'){ext.detailTab='followup'"),
 'Opening a lead must show Follow-up first');
assert(src.includes("if(f.id==='followupV2Form')"), 'Follow-up form submit handler must exist');
assert(src.includes("'/followup','POST'"), 'Follow-up must save through one endpoint');
assert(src.includes('f.dataset.saving'), 'Repeated clicks must be guarded');
assert(css.includes('.sf-disqual-dialog[hidden]'), 'Reason dialog must hide when closed');
assert(php.includes("if ($sub==='followup' && $method==='POST')"), 'Atomic backend endpoint required');
assert(php.includes("sf_task_cancel_for_closed_lead"), 'Disqualification must close queued follow-ups');
assert(work.includes("const SF_TEMPERATURES = ['Unrated','Hot','Warm','Cold']"),
 'Disqualified must not be inserted into temperature categories');
console.log('PASS: Follow-up V2 first screen, mandatory fields, reason dialog, attribution preservation');
