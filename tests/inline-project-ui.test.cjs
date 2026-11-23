'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const view=fs.readFileSync('resources/views/project.blade.php','utf8');
const controller=fs.readFileSync('app/Http/Controllers/AssetController.php','utf8');
for(const marker of [
  'class="asset-fold-card', 'data-inline-toggle="asset"',
  'data-inline-save="asset-prompt"', 'data-inline-save="asset-prompt-reset"',
  'data-inline-save="asset-ratio"', 'data-inline-save="asset-upload"',
  'data-inline-save="segments"', 'data-inline-save="shot"',
  'async function toggleAssetFold(form)', 'async function sendInlineForm(form)',
  'function updateAssetPreview(id,result)',
  'function updateShotReferenceList(shotId,references)',
  'function updateProjectShotReferences(map)',
  'updateProjectShotReferences(result.shot_references)',
]) assert(view.includes(marker),'Missing inline UI hook: '+marker);
assert(!view.includes('已移除资产（'),'Assets must fold in their original position');
assert(!view.includes('移除资产（可恢复）'),'No old separate archive flow');
assert(!view.includes('window.location.reload('),'Asset/image saves must not reload the page');
assert(!view.includes('confirm(\'将此资产'),'Folding must not ask for confirmation');
assert(view.includes('data-archived="{{ $a->is_archived'), 'Persisted folds must render correctly');
for(const marker of [
  "request()->expectsJson()", "'archived'=>true",
  "'archived'=>false", "'shot_references'=>ShotAssetSelection::projectReferences($id)",
  "'thumbnail_url'=>route(",
]) assert(controller.includes(marker),'Missing inline JSON output: '+marker);

let script=view.split('<script>').at(-1).split('</script>')[0];
let start=script.indexOf('@json(');
while(start>=0){
  let depth=1,i=start+6,quote=null;
  for(;i<script.length&&depth>0;i++){
    const ch=script[i];
    if(quote){
      if(ch==='\\'){i++;continue;}
      if(ch===quote)quote=null;
    }else if(ch==="'"||ch==='"')quote=ch;
    else if(ch==='(')depth++;
    else if(ch===')')depth--;
  }
  assert.equal(depth,0,'Malformed @json Blade expression');
  script=script.slice(0,start)+'null'+script.slice(i);
  start=script.indexOf('@json(');
}
script=script.replace(/\{\{[\s\S]*?\}\}/g,'_route_');
new Function(script);
console.log('Inline folding, AJAX save, preview updates and page JS syntax passed');
