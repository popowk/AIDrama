'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const view=fs.readFileSync('resources/views/project.blade.php','utf8');
const css=fs.readFileSync('public/app.css','utf8');

const card=view.slice(view.indexOf('<div class="asset-preview-visual">'),
  view.indexOf('<div class="asset-preview-controls">'));
assert(card.includes('<a class="asset-preview-trigger"'),'Original preview is an anchor');
assert(card.includes('href="{{ route(\'asset.file\''),'Preview has usable direct authenticated URL');
assert(card.includes('onclick="return openAssetPreview(this,event)"'),
  'Static preview opens modal and follows href if modal cannot be opened');
assert(card.includes('在新窗口打开原图'),'Visible direct-open link always available');
assert(view.includes('id="asset-lightbox-original"'),'Modal has direct image fallback');
assert(view.includes('const trigger=document.createElement(\'a\')'),
  'Dynamic image generation uses a link, not a JS-only button');
assert(view.includes('preview.replaceChildren(trigger,original,link)'),
  'Dynamic preview preserves full-image open and download controls');
assert(css.includes('.asset-open-original'),'Original-image link must be visible');

const start=view.indexOf('function openAssetPreview(button,event){');
const stop=view.indexOf('function toggleAssetPreviewMode()',start);
assert(start>=0&&stop>start,'Missing modal-open function');
const source=view.slice(start,stop);
const siteRoute=url=>url;
function makeDocuments(showModal){
  const image={src:'',alt:''},title={textContent:''},download={href:''};
  const mode={textContent:''},original={href:''};
  const dialog={open:false,showModal,close(){this.open=false;},dataset:{}};
  const ids={
    'asset-lightbox':dialog,'asset-lightbox-image':image,
    'asset-lightbox-title':title,'asset-lightbox-download':download,
    'asset-lightbox-mode':mode,'asset-lightbox-original':original,
  };
  return {document:{getElementById:id=>ids[id]},dialog,image,title,download,original};
}
const trigger={href:'/private/original.png',dataset:{
  previewSrc:'/private/original.png?v=123',
  previewName:'悟空',downloadUrl:'/private/download.png',
}};
{
  const fake=makeDocuments(function(){this.open=true;});
  const open=new Function('siteRoute','document',source+';return openAssetPreview;')
    (siteRoute,fake.document);
  assert.equal(open(trigger,{button:0}),false,'Supported modal should prevent navigation');
  assert.equal(fake.dialog.open,true,'Modal opens');
  assert.equal(fake.image.src,trigger.dataset.previewSrc,'Full image uses original URL');
  assert.equal(fake.original.href,trigger.dataset.previewSrc,'Modal fallback points to original');
  assert.equal(open(trigger,{button:0,metaKey:true}),true,'Cmd-click opens new tab');
}
{
  const fake=makeDocuments(undefined);
  const open=new Function('siteRoute','document',source+';return openAssetPreview;')
    (siteRoute,fake.document);
  assert.equal(open(trigger,{button:0}),true,'No dialog API follows raw image href');
}
{
  const fake=makeDocuments(function(){throw new Error('Dialog not supported');});
  const open=new Function('siteRoute','document',source+';return openAssetPreview;')
    (siteRoute,fake.document);
  assert.equal(open(trigger,{button:0}),true,'Dialog error follows raw image href');
}
console.log('Existing and newly generated images can open in modal or direct original fallback');
