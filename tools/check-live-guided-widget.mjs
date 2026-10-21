// Read-only live deployment diagnostic: no account, enquiry, or API mutation.
const base='https://stage.digiti.in/scrm';
const page='https://lexiconedu.in/demo/';
async function load(url){
 try{
  const r=await fetch(url,{redirect:'follow',signal:AbortSignal.timeout(14000),
    headers:{'User-Agent':'Mozilla/5.0 (SchoolFlow read-only release check)','Accept':'*/*'}});
  return {status:r.status,text:await r.text(),url:r.url,cache:r.headers.get('cache-control')||'',type:r.headers.get('content-type')||'',etag:r.headers.get('etag')||''};
 }catch(e){return {status:'error',text:'',reason:String(e.message||e)};}
}
function report(name,r,markers=[]){
 const found=markers.filter(m=>r.text.includes(m));
 console.log(JSON.stringify({name,status:r.status,length:r.text.length,cache:r.cache||null,
  type:r.type||null,etag:r.etag||null,found,missing:markers.filter(m=>!found.includes(m)),
  cloudflareChallenge:r.text.includes('Just a moment...'),error:r.reason||null}));
}
const html=await load(page);
report('demo_page',html,['lexicon-form.js']);
const tags=[...html.text.matchAll(/<script\b[^>]*>/gi)].map(m=>m[0]);
const formTags=tags.filter(t=>/lexicon-form\.js|\/scrm\/embed\/[a-f0-9-]{36}\.js/i.test(t));
console.log(JSON.stringify({name:'demo_embed_count',count:formTags.length,
 kinds:formTags.map(x=>x.includes('/embed/')?'guid_embed':'legacy_lexicon'),
 sources:formTags.map(x=>(x.match(/\bsrc=(["'])(.*?)\1/i)||[])[2]||'').map(x=>x.replace(/https:\/\/[^/]+/i,''))}));
for(const [name,url] of [
 ['chat_static',base+'/static/admissions-chat.js'],
 ['chat_fresh',base+'/static/admissions-chat.js?check=lead-first-261945a-20261021'],
 ['lexicon_static',base+'/static/lexicon-form.js?v=1'],
 ['lexicon_fresh',base+'/static/lexicon-form.js?check=lead-first-261945a-20261021'],
 ['guided_endpoint',base+'/embed/3b8a718d-4521-4c31-8c48-5898524be892.js']
]){
 const r=await load(url);
 report(name,r,name.startsWith('chat_')?['sfac-register','chat-start','sfac-followup']:
 name.startsWith('lexicon_')?['loadAdmissionsChat()']:['meta.versions']);
}
console.log('READ_ONLY_CHECK_COMPLETE');
