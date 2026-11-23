'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const view=fs.readFileSync('resources/views/project.blade.php','utf8');
const ctl=fs.readFileSync('app/Http/Controllers/StudioController.php','utf8');
const css=fs.readFileSync('public/app.css','utf8');
const start=view.indexOf('action="{{ route(\'shot.save\'');
const end=view.indexOf('</form>',start);
assert(start>=0&&end>start,'Shot form missing');
const shot=view.slice(start,end);
assert(!shot.includes('name="action_before"'),'No redundant pre-dialogue action field');
assert(!shot.includes('name="dialogue"'),'No redundant dialogue input');
assert(!shot.includes('name="delivery"'),'No redundant voice-mode selector');
assert(shot.includes('name="visual"')&&shot.includes('name="duration_seconds"'),
    'Keep core shot visual and duration editing');
assert(view.includes('class="shot-assets-form"') &&
  view.includes('type="checkbox" name="asset_ids[]"') &&
  view.includes('onclick="recognizeShotAssets('),
  'All shot assets render as manual multi-select with AI re-identification');
assert(view.includes('form.dataset.saveUrl') &&
  view.includes('body:JSON.stringify({asset_ids:ids})'),
  'Checkbox changes autosave explicit empty as JSON array');
assert(view.includes("payload.set('asset_ids_present','1')") &&
  view.includes("payload.append('asset_ids[]',String(id))"),
  'Prompt input includes current selected checkbox assets');
assert(view.includes('function expandPromptArea(area)') &&
  view.includes('area.scrollHeight+8') &&
  view.includes('expandPromptArea(area);'),
  'Prompt textarea automatically expands to full generated content');
assert(css.includes('.shot-reference-option')&&css.includes('.prompt-output'),
  'Assets and prompt autosize styling exists');
assert(ctl.includes('ShotAssetSelection::save') &&
  ctl.includes('ShotAssetSelection::recommend') &&
  ctl.includes('builder->generate($project,$item,$v[\'target\'],$selection)'),
  'Server stores human choices, runs AI, respects selected assets at generation');

// Real Blade project JS remains valid after stripping Blade templates.
let js=view.split('<script>').at(-1).split('</script>')[0];
let startJson=js.indexOf('@json(');
while(startJson>=0){
  let depth=1,idx=startJson+6,quote=null;
  for(;idx<js.length&&depth>0;idx++){
    const ch=js[idx];
    if(quote){if(ch==='\\'){idx++;continue;}if(ch===quote)quote=null;}
    else if(ch==='"'||ch==="'")quote=ch;
    else if(ch==='(')depth++;
    else if(ch===')')depth--;
  }
  assert.equal(depth,0,'Unclosed Blade @json');
  js=js.slice(0,startJson)+'null'+js.slice(idx);
  startJson=js.indexOf('@json(');
}
js=js.replace(/\{\{[\s\S]*?\}\}/g,'_route_');
new Function(js);
console.log('Simplified shot fields, AI multi-select autosave, and expanding prompt textarea validated');
