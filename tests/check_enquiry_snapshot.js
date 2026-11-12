'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public_html/static/app.js', 'utf8');
const css = fs.readFileSync('public_html/static/style.css', 'utf8');
const start = source.indexOf('/** Visible lead origin is a source category');
const end = source.indexOf('function detailDrawer(){', start);
assert(start >= 0 && end > start, 'Enquiry snapshot helpers must be present');
assert(source.includes('<div class="detail-body">${enquirySnapshot(l)}'), 'Snapshot should be first in detail body');
assert(source.includes('id="sf-drawer-title"'), 'Student identity must remain present');
assert(css.includes('.sf-lead-snapshot'), 'Enquiry snapshot styles should be present');
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, ch =>
  ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const scope = vm.runInNewContext(source.slice(start,end)+
  '\n({enquiryOriginLabel,enquirySafeLandingHref,enquirySnapshot})', {URL, esc, auditStamp:(stamp)=>'12 Nov 2026, 10:19 am'});
assert.equal(scope.enquiryOriginLabel('individual_website'), 'School website');
assert.equal(scope.enquiryOriginLabel('group_website'), 'Group website');
assert.equal(scope.enquiryOriginLabel('campaign'), 'Campaign');
assert.equal(scope.enquirySafeLandingHref('javascript:alert(1)'), '');
assert.equal(scope.enquirySafeLandingHref('data:text/html,evil'), '');
assert.equal(scope.enquirySafeLandingHref('/relative'), '');
assert(scope.enquirySafeLandingHref('https://example.test/landing').startsWith('https://'));
const snapshot = scope.enquirySnapshot({
  guardian:'Guardian Example', phone:'+91 9876543210', email:'parent@example.test',
  city:'Pune', state:'Maharashtra', pincode:'411001',
  source_type:'group_website', source_name:'Group Admissions',
  source_domain:'school.example.test', utm_campaign:'Admissions 2026',
  landing_page:'https://school.example.test/entry?src=email',
  created_at:'2026-11-12T04:49:00+00:00'
});
for(const item of ['Guardian Example','Pune, Maharashtra','411001','Group website',
  'Group Admissions','Admissions 2026','Landing URL']) {
  assert(snapshot.includes(item), 'Missing expected content: '+item);
}
assert(snapshot.includes('rel="noopener noreferrer"'), 'External landing links must be safe');
assert(snapshot.includes('Received'), 'Enquiry received timestamp must appear in lead details');
assert(snapshot.includes('12 Nov 2026, 10:19 am IST'), 'Enquiry timestamp is India-local and labelled IST');
assert(snapshot.includes('<time datetime="2026-11-12T04:49:00+00:00">'), 'Timestamp should retain server ISO value');
const unsafe = scope.enquirySnapshot({guardian:'<img src=x>', phone:'0', landing_page:'javascript:alert(1)'});
assert(!unsafe.includes('<img src=x>'), 'Escaped guardian name expected');
assert(!unsafe.includes('href="javascript:'), 'Javascript landing URL must not link');
assert(unsafe.includes('Not provided'), 'Missing contact fields must be labelled');
console.log('PASS: compact enquiry identity, contact, attribution, and landing URL safety');
