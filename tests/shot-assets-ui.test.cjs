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
assert(!shot.includes('name="dialogue"'),'No separate dialogue input');
assert(!shot.includes('name="delivery"'),'No redundant voice-mode selector');
assert(!shot.includes('name="visual"'),'No separate visual input');
assert(shot.includes('name="shot_content"')&&shot.includes('name="duration_seconds"'),
    'Keep exactly one textarea for visual/action/dialogue and the shot duration');
assert(shot.includes('ShotText::format($shot)'),
    'Composite textarea renders both AI generated visual and stored speech');
assert(!shot.includes('class="shot-dialogue"')&&
    !view.includes('class="shot-dialogue-content"'),
    'Remove the second spoken-content section completely');
assert(css.includes('.shot-card textarea[name="shot_content"]'),
    'Unified shot textarea should be easy to read and expand');
assert(view.includes('class="shot-assets-form"') &&
  view.includes('type="checkbox" name="asset_ids[]"') &&
  view.includes('onclick="recognizeShotAssets('),
  'All shot assets render as manual multi-select with AI re-identification');
const referenceStart=view.indexOf('class="shot-reference-list"');
const referenceEnd=view.indexOf('class="prompt-row"',referenceStart);
assert(referenceStart>=0&&referenceEnd>referenceStart,
    'Shot asset panel should precede the prompt generator');
const reference=view.slice(referenceStart,referenceEnd);
assert(reference.includes('<form class="shot-assets-form"') &&
    !reference.includes('<details') && !reference.includes('<summary'),
    'Reference asset checkboxes remain visible for any project asset count');
assert(!view.includes('class="shot-assets-details"'),
    'Old conditional-collapsed asset list has been removed');
assert(view.includes('form.dataset.saveUrl') &&
  view.includes('body:JSON.stringify({asset_ids:ids})'),
  'Checkbox changes autosave explicit empty as JSON array');
assert(view.includes("payload.set('asset_ids_present','1')") &&
  view.includes("payload.append('asset_ids[]',String(id))"),
  'Prompt input includes current selected checkbox assets');
assert(view.includes('function sizeShotContent(field)') &&
  view.includes('sizeShotContent(field);') &&
  view.includes('textarea[name="shot_content"]'),
  'Visual, action and dialogue editor expands automatically');
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
