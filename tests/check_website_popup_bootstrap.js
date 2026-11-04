// Execute the exact dynamic PHP embed bootstrap in a browser-like VM.
// This catches dead visible rails, incorrect form-loader selection and
// ambiguous school website campaign auto-targeting.
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {execFileSync}=require('node:child_process');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const formGuid='71be8d09-2fc1-4df5-a6dd-c2e87a1e5668';
const campaignGuid='f47ac10a-5a49-4d2e-a5d2-58d254e0901f';
function run(variant,url,attrs={}){
 const source=execFileSync('php',['tests/fixtures/emit_website_embed.php',variant],
  {cwd:root,encoding:'utf8'});
 const appended=[],warnings=[];
 const current={
  src:'https://crm.example.test/scrm/embed/'+formGuid,
  getAttribute:key=>Object.hasOwn(attrs,key)?attrs[key]:null,
  hasAttribute:key=>Object.hasOwn(attrs,key)
 };
 const document={
  currentScript:current,
  createElement(tag){
   assert.equal(tag,'script');
   return {attributes:{},setAttribute(k,v){this.attributes[k]=v;},
    getAttribute(k){return this.attributes[k];}};
  },
  head:{appendChild:script=>appended.push(script)}
 };
 vm.runInNewContext(source,{document,location:{href:url},URL,
  console:{warn:m=>warnings.push(m)}},{filename:'sf-public-embed.js'});
 return {appended,warnings,source};
}
const page='https://school.test/admissions/';
const enabled=run('linked-one',page);
assert.equal(enabled.appended.length,1,'plain embed resolves active school popup');
assert.match(enabled.appended[0].src,/\/schoolflow-short-form\.js\?v=/,
 'not the disabled legacy detailed popup');
assert.equal(enabled.appended[0].attributes['data-schoolflow-mode'],'popup');
assert.equal(enabled.appended[0].attributes['data-schoolflow-popup-campaign'],campaignGuid,
 'campaign GUID forwarded to public-form authorization');
assert.equal(enabled.appended[0].attributes['data-schoolflow-button-style'],'rail',
 'existing website right-side yellow rail retained');
assert.equal(run('linked-one','https://different.test/admissions/').appended.length,0,
 'cross-domain cannot be auto-bound');
assert.equal(run('linked-off',page).appended.length,0,
 'disabled campaign must not render a clickable dead tab');
const conflict=run('linked-conflict',page);
assert.equal(conflict.appended.length,0,'ambiguous campaign selects neither');
assert.match(conflict.warnings.join(' '),/specific campaign embed/,
 'warn admin to copy a campaign-specific embed');
assert.equal(run('linked-specific',page).appended.length,1,
 'exact-page campaign can be auto-bound on its own URL');
assert.equal(run('linked-specific','https://school.test/other/').appended.length,0,
 'exact-page campaign cannot spill into another page');
assert.equal(run('legacy',page).appended.length,1,'legacy group embed remains supported');
assert.match(run('legacy',page).appended[0].src,/lexicon-form\.js\?v=/,
 'old group websites continue loading detailed form');
const explicit=run('linked-off',page,{'data-schoolflow-form':'short',
 'data-schoolflow-mode':'popup','data-schoolflow-popup-campaign':campaignGuid});
assert.match(explicit.appended[0].src,/schoolflow-short-form\.js/,
 'explicit campaign script still loads and validates against public config');
const detailedCss=fs.readFileSync(path.join(root,'public_html/static/lexicon-form.css'),'utf8');
const shortCss=fs.readFileSync(path.join(root,'public_html/static/schoolflow-short-form.css'),'utf8');
assert.match(detailedCss,/\.side-tab\[hidden\][^{]*\{display:none!important\}/,
 'legacy hidden rail cannot be forced visible');
assert.match(shortCss,/\.sf2-trigger\[hidden\][^{]*\{display:none!important\}/,
 'short hidden campaign trigger cannot be forced visible');
console.log('PASS: installed plain school-site embed, matching campaign, disabled/ambiguous fail-closed, legacy compatibility, hidden rail CSS');
