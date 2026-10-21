const fs=require('node:fs'),assert=require('node:assert/strict');
const app=fs.readFileSync(__dirname+'/../public_html/static/app.js','utf8');
const css=fs.readFileSync(__dirname+'/../public_html/static/style.css','utf8');
for(const item of ['sf-kpi-grid','sf-priority-grid','sf-funnel-list','sf-recent-list','sf-dashboard-empty','data-action="close-menu"','data-action="open-search"','aria-label="SchoolFlow main navigation"','Chatbot Leads','My Work'])assert.ok(app.includes(item),'missing workspace element: '+item);
for(const item of ['.sf-dashboard-body','.sf-kpi-grid','.nav-scrim','@media(max-width:580px)'])assert.ok(css.includes(item),'missing workspace CSS: '+item);
console.log('PASS: dashboard workspace, navigation and responsive styles');
