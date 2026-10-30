// Regression: group enquiry form must not let "Campus" act as a selectable school.
const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const src=fs.readFileSync(__dirname+'/../public_html/static/lexicon-form.js','utf8');
const extract=(name)=>{
 const found=src.match(new RegExp(' function '+name+'\\\\([^]*?\\\\n \\}', 'm'));
 if(!found)throw new Error('Missing '+name+' helper');
 return found[0];
};
class Choice {
 constructor(text,value,defaultSelected=false,selected=false){
  this.text=text;this.value=value;this.defaultSelected=defaultSelected;
  this.selected=selected;this.disabled=false;this.hidden=false;
 }
}
function select(){
 return {
  disabled:true,options:[],_value:'',
  replaceChildren(...children){this.options=children;this._value=children.find(x=>x.selected)?.value||'';},
  add(option){this.options.push(option);},
  get value(){return this._value;},
  set value(value){this._value=this.options.some(x=>x.value===value)?value:'';}
 };
}
async function check(){
 const campus=select();
 let config={schools:[
  {code:'campus-1',name:'Lexicon - Hadapsar'},
  {code:'campus-2',name:'Lexicon - Kalyaninagar'},
  {code:'campus-3',name:'Lexicon - Wagholi'},
  {code:'campus-3',name:'Duplicate campus'},
  {code:'',name:'Campus'},
  {code:'empty-name',name:' '}
 ]};
 let message='';
 const context=vm.createContext({
  campus,Option:Choice,Date,Set,Array,Promise,
  get:async()=>config,feedback:v=>{message=v;},loadedAt:0
 });
 const helpers=src.slice(src.indexOf(' function campusPlaceholder(label){'),src.indexOf(' function open(){'));
 assert.ok(helpers.startsWith(' function campusPlaceholder'),'Campus helper is present');
 vm.runInContext(helpers,context);
 await vm.runInContext('loadSchools(true)',context);
 assert.equal(campus.disabled,false);
 assert.equal(campus.value,'','no default school selected');
 assert.equal(campus.options.length,4,'only unique nonempty enabled campus choices');
 assert.equal(campus.options[0].text,'Select Campus');
 assert.equal(campus.options[0].value,'');
 assert.equal(campus.options[0].disabled,true,'prompt cannot be selected');
 assert.equal(campus.options[0].hidden,true,'prompt absent from opened native dropdown');
 assert.deepEqual(campus.options.slice(1).map(x=>x.text),[
  'Lexicon - Hadapsar','Lexicon - Kalyaninagar','Lexicon - Wagholi'
 ]);
 campus.value='campus-2';
 await vm.runInContext('loadSchools(true)',context);
 assert.equal(campus.value,'campus-2','valid selection retained after refresh');
 config={schools:[{code:'campus-1',name:'Lexicon - Hadapsar'}]};
 await vm.runInContext('loadSchools(true)',context);
 assert.equal(campus.value,'','unavailable campus is not silently replaced by another');
 assert.equal(campus.options.length,2);
 config={schools:[]};
 await vm.runInContext('loadSchools(true)',context);
 assert.equal(campus.disabled,true,'empty available list disables selector');
 assert.match(message,/No campus is available/);
 assert.match(src,/campus\.disabled \|\| !campus\.value \|\| !Array\.from\(campus\.options\)\.some/,
  'submit requires a non-placeholder option');
 assert.match(src,/<option value="" disabled selected hidden>Select Class<\/option>/,
  'class must also start on a nonselectable prompt');
 console.log('PASS: group campus prompt disabled, list filtered, dynamic refresh and missing choices blocked');
}
check().catch(err=>{console.error(err);process.exitCode=1;});
