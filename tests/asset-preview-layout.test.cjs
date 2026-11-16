'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const view = fs.readFileSync('resources/views/project.blade.php','utf8');
const css = fs.readFileSync('public/app.css','utf8');
const from=view.indexOf('<div class="asset asset-media"');
const until=view.indexOf('@endforeach',from);
assert(from>=0&&until>from,'Missing asset card template');
const card=view.slice(from,until);
const preview=card.indexOf('<div class="asset-preview">');
const visual=card.indexOf('<div class="asset-preview-visual">');
const ratio=card.indexOf('class="asset-ratio-form"');
const generate=card.indexOf('class="button outline asset-generate"');
assert(preview>=0&&visual>preview&&ratio>visual&&generate>ratio,
  'Image ratio and generation must live in the right preview column');
assert.equal((card.match(/name="frame_ratio"/g)||[]).length,1,'Exactly one ratio selector per asset');
assert.equal((card.match(/class="asset-generate"/g)||[]).length,0,'Look for the real button selector');
assert(card.includes('asset-ratio-indicator'),'Small inline save check is missing');
assert(!card.includes('保存画幅'),'Explicit save button must be removed');
assert(card.indexOf('class="asset-info"')<preview,'Asset details remain on the left');
assert(css.includes('.asset-preview-controls .asset-ratio-form'),'Missing right side layout rules');

const update=view.slice(view.indexOf('function updateAssetPreview('),view.indexOf("document.addEventListener('submit'",view.indexOf('function updateAssetPreview(')));
assert(update.includes("querySelector('.asset-preview-visual')"),
  'Thumbnail-only DOM replacement must not destroy relocated ratio/generate buttons');
const save=view.slice(view.indexOf('function showRatioNotice('),view.indexOf('document.querySelectorAll(\'.asset-prompt-form textarea\')'));
assert(save.includes("field.matches('.asset-ratio-form select[name=\"frame_ratio\"]')"),
  'Auto-save needs an explicit select change handler');
assert(save.includes('form.requestSubmit()'),'Selector change should submit existing save endpoint');
assert(save.includes("showRatioNotice(form,'✓','success')"),'Expected small tick on confirmed save');
assert(save.includes("},1800)"),'Tick should disappear automatically');
assert(save.includes('ratioField.disabled=true')&&save.includes('ratioField.disabled=false'),
  'Prevent overlapping saves while waiting on server');
assert(save.includes("ratioField.value=ratioField.dataset.savedValue||'inherit'"),
  'Server failure should revert unsaved selection with error feedback');
const fold=view.slice(view.indexOf('async function toggleAssetFold('),view.indexOf('function inlineSaveMessage('));
assert(fold.includes("const ratioField=shell.querySelector('.asset-ratio-form select[name=\"frame_ratio\"]')"),
  'Restore should re-enable ratio selector without refreshing');
assert(fold.includes("const generateButton=shell.querySelector('.asset-generate')"),
  'Restore should re-enable AI generation button without refreshing');
console.log('Asset right-hand controls, no-button auto-save and short-lived checkmark verified');
