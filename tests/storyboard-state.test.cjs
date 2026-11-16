'use strict';
const assert = require('node:assert/strict');
const {normalizeSource, hasUnsavedChanges, canExpand} = require('../public/storyboard-state.js');

const paste = '镜号\n画面\n台词\n1\n大棚外景\n你好\n';
assert.equal(normalizeSource(paste), '镜号\n画面\n台词\n1\n大棚外景\n你好');
assert.equal(normalizeSource('  \r\n'+paste.replace(/\n/g,'\r\n')+'\t'),normalizeSource(paste));
assert.equal(hasUnsavedChanges(paste, normalizeSource(paste)),false);
assert.equal(hasUnsavedChanges(paste+'2\n新增镜头\n','镜号\n画面\n台词\n1\n大棚外景\n你好'),true);

const saved = {
  projectIsGenerating: false, saveInProgress: false,
  serverCanExpand: true,
  selectedKind:'storyboard',savedKind:'storyboard',
  currentSource:paste,savedSource:normalizeSource(paste),
};
assert.equal(canExpand(saved),true,'Saving a paste ending with a newline must enable AI button');
for(const changed of [
  {serverCanExpand:false},
  {saveInProgress:true},
  {projectIsGenerating:true},
  {selectedKind:'script'},
  {savedKind:'script'},
  {currentSource:paste+'2\n新的镜头\n'},
]) assert.equal(canExpand({...saved,...changed}),false,JSON.stringify(changed));

assert.equal(canExpand({...saved,savedSource:'  \r\n'+paste.replace(/\n/g,'\r\n')+'  '}),true);
console.log('Storyboard save-state normalization and expansion eligibility passed');
