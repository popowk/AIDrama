'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const view=fs.readFileSync('resources/views/settings.blade.php','utf8');
for(const [marker,count] of [
  ['data-inline-settings="model"',2],
  ['data-inline-settings="prompt"',2], // one template form and one querySelector
  ['data-inline-settings="reset"',1],
  ['credentials:\'same-origin\'',1],
]){
  const found=view.split(marker).length-1;
  assert(found>=count,'Missing settings AJAX hook '+marker+' (found '+found+')');
}
const source=view.split('<script>').at(-1).split('</script>')[0];
let script=source;
let start=script.indexOf('@json(');
while(start>=0){
  let depth=1,index=start+6,quote=null;
  for(;index<script.length&&depth>0;index++){
    const ch=script[index];
    if(quote){if(ch==='\\'){index++;continue;}if(ch===quote)quote=null;}
    else if(ch==="'"||ch==='"')quote=ch;
    else if(ch==='(')depth++;
    else if(ch===')')depth--;
  }
  assert.equal(depth,0,'Unclosed Blade @json in model settings JS');
  script=script.slice(0,start)+'null'+script.slice(index);
  start=script.indexOf('@json(');
}
script=script.replace(/\{\{[\s\S]*?\}\}/g,'_route_');
new Function(script);
console.log('Model and category prompt settings save without reload, JS syntax validated');
