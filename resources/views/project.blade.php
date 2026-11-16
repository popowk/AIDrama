@extends('layout')
@section('title',$project->name)
@section('content')
<div class="headline"><div><a class="breadcrumb" href="{{ route('home') }}">← 返回项目</a><h1>{{ $project->name }}</h1><p class="muted">{{ $project->style }} · {{ \App\Services\FrameProfile::label($project->frame_ratio??'landscape') }} · 每镜最长 {{ $project->shot_limit }} 秒 · {{ ['draft'=>'待生成','generating'=>'生成中','ready'=>'已生成','error'=>'上次生成失败'][$project->status]??$project->status }}
@if($project->detected_mode) · 已识别：{{ $project->detected_mode==='talking'?'纯口播':'文案＋口播' }}@endif</p></div><a class="button outline" href="{{ route('project.export',$project->id) }}">导出项目 JSON</a></div>
<div class="project-grid">
<section class="panel"><div class="section-heading"><h2>01 / 剧本和生成参数</h2></div>
<form method="post" action="{{ route('project.update',$project->id) }}" class="form-stack">@csrf
<label>项目名称<input name="name" value="{{ $project->name }}" maxlength="191" required></label>
<div class="inline-fields">
<label>镜头时长上限<select name="shot_limit"><option value="15" @selected($project->shot_limit==15)>15 秒</option><option value="30" @selected($project->shot_limit==30)>30 秒</option></select></label>
</div>
<label id="project-custom-style" @if(($project->style_preset??'custom')!=='custom') hidden @endif>
自定义画风名称<input name="style" value="{{ $project->style }}" maxlength="191"
  placeholder="例如：原创武侠3D动画" @disabled(($project->style_preset??'custom')!=='custom') @required(($project->style_preset??'custom')==='custom')>
</label>
<div class="inline-fields">
<label>项目画面比例<select name="frame_ratio">
<option value="landscape" @selected(($project->frame_ratio??'landscape')==='landscape')>横屏 16:9</option>
<option value="portrait" @selected(($project->frame_ratio??'landscape')==='portrait')>竖屏 9:16</option>
</select><small class="muted">生图与后续视频提示词默认遵守；已生成的原图不裁切</small></label>
<label>参考风格库<select name="style_preset" id="project-style-selector">
@foreach($stylePresets as $key=>$preset)
<option value="{{ $key }}" @selected(($project->style_preset??'custom')===$key)>{{ $preset['label'] }}</option>
@endforeach
</select><small class="muted">附加详细的构图、光影、色彩、材质规则</small></label>
</div>
<details class="creative-skill-picker"><summary>技能库（已选 {{ count($selectedSkills) }} 项 · 可组合）</summary>
<input type="hidden" name="skills_present" value="1">
<div class="creative-skill-options">
@foreach($creativeSkills as $key=>$skill)
<label class="creative-skill-option">
<input type="checkbox" name="skill_keys[]" value="{{ $key }}" @checked(in_array($key,$selectedSkills,true))>
<span><strong>{{ $skill['label'] }}</strong><small>{{ $skill['description'] }}</small></span>
</label>
@endforeach
</div></details>
<label>创作形式<select name="script_mode"><option value="auto" @selected($project->script_mode==='auto')>自动识别纯口播 / 文案＋口播</option><option value="talking" @selected($project->script_mode==='talking')>纯口播（角色面对镜头说话）</option><option value="mixed" @selected($project->script_mode==='mixed')>文案＋口播（画面展示、字幕与旁白）</option></select></label>
<label>完整剧本<textarea name="script" rows="11" maxlength="100000" required>{{ $project->script }}</textarea></label>
<div class="button-row"><button class="button outline" type="submit">保存剧本</button></div></form>
<form method="post" action="{{ route('project.generate',$project->id) }}" onsubmit="return confirm('生成成功后将替换当前分镜和资产，原有数据在模型返回前会保留，确定提交吗？')">@csrf
<button class="button primary wide" type="submit" @disabled($project->status==='generating')>✦ {{ $shots->count()?'重新生成分镜与资产':'AI 生成分镜与资产清单' }}</button></form>
<div id="generation-state" class="task-state" data-status="{{ $project->status }}">
  @if($project->status==='generating')<strong>正在等待文本模型返回</strong><p class="muted">任务已保存。页面会自动显示进度，处理可能需要数分钟；无需重复提交。</p>
  @elseif($project->status==='error')<strong>上次生成没有完成</strong><p class="muted">原有分镜不会因请求失败而被清空，检查模型后可以重新生成。</p>
  @else<p class="hint">生成任务会保存在数据库。API 最长等待约 145 秒，遇到限流或暂时故障会自动重试一次。</p>@endif
</div>
</section>
<section class="panel"><div class="section-heading"><h2>02 / 统计概览</h2></div>
<div class="stats"><div><strong>{{ $shots->count() }}</strong><small>镜头数</small></div><div><strong>{{ $total }}s</strong><small>预计总时长</small></div><div><strong>{{ $assets->count() }}</strong><small>资产数</small></div></div>
<p class="hint">时长依据中文台词字符与开口前动作估算，不等同真实配音时长；超过上限的台词会继续拆分为连续镜头。</p>
<div class="section-heading space"><h2>03 / 资产清单</h2>
@if($assets->contains(fn($a)=>$a->source_kind==='generate'))
<button class="button ghost" type="button" onclick="generateAllAssets(this,{{ $project->id }})">一键生成普通资产图</button>
@else<span class="muted">真实上传 / AI 生成</span>@endif
</div>
<p id="batch-image-status" class="hint" role="status"></p>
<p class="hint">chatgpt2api 3.x 异步模式：提交后可关闭页面，任务 ID 会保留；再次打开页面自动查询结果。批量操作只提交任务，不重复生图。</p>
<p class="hint">Logo、产品型号、产品外观必须上传原始素材；普通角色、场景和非品牌道具可使用图片模型生成。素材仅在管理员登录后可查看。</p>
@if($assets->count())
@foreach($assets as $a)
<div class="asset asset-media" id="asset-{{ $a->id }}" data-pending="{{ $a->image_status==='generating'?'1':'0' }}">
<div class="asset-info">
<span class="asset-type">{{ ['character'=>'人物','scene'=>'场景','prop'=>'道具'][$a->type]??$a->type }}</span>
<strong>{{ $a->name }}</strong>
<span class="chip">{{ $a->source_kind==='upload'?'必须使用上传素材':'允许 AI 生成' }}</span>
<p>{{ $a->description }}</p>
@if($a->voice_description)<p class="muted small">声音设定：{{ $a->voice_description }}</p>@endif
<p class="muted small asset-status" id="asset-status-{{ $a->id }}">@if($a->file_path){{ $a->source_kind==='upload'?'已上传真实参考图':'AI 图片已生成' }}@elseif($a->source_kind==='upload')待客户上传原图@else{{ ['pending'=>'待生成','generating'=>'异步生成中，自动查询结果','error'=>'生成失败，可查看原因后重试'][$a->image_status]??$a->image_status }}@endif</p>
@if($a->image_status==='error'&&$a->image_error)<p class="muted small">{{ $a->image_error }}</p>@endif
<details><summary>查看资产生图提示词</summary><textarea rows="3" readonly>{{ $a->prompt }}</textarea></details>
<div class="asset-buttons">
<form method="post" action="{{ route('asset.frameRatio',[$project->id,$a->id]) }}" class="asset-ratio-form">
@csrf
<label class="small">该资产画幅（覆盖项目默认）
<select name="frame_ratio" @disabled($a->image_status==='generating')>
<option value="inherit" @selected(($a->frame_ratio??'inherit')==='inherit')>沿用项目默认 · {{ \App\Services\FrameProfile::label($project->frame_ratio??'landscape') }}</option>
<option value="landscape" @selected(($a->frame_ratio??'inherit')==='landscape')>横屏 16:9</option>
<option value="portrait" @selected(($a->frame_ratio??'inherit')==='portrait')>竖屏 9:16</option>
<option value="square" @selected(($a->frame_ratio??'inherit')==='square')>方形 1:1</option>
</select></label>
<button class="button ghost" type="submit" @disabled($a->image_status==='generating')>保存画幅</button>
</form>
@if($a->source_kind==='upload')
<form action="{{ route('asset.upload',[$project->id,$a->id]) }}" method="post" enctype="multipart/form-data" class="asset-upload">@csrf
<label class="small">客户真实素材（PNG/JPG/WebP，≤10MB）<input name="image" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required></label>
<button class="button outline" type="submit">{{ $a->file_path?'替换真实素材':'上传真实素材' }}</button>
</form>
@else
<button class="button outline asset-generate" data-asset-id="{{ $a->id }}" data-has-image="{{ $a->file_path?'1':'0' }}" data-generate-url="{{ route('asset.generate',[$project->id,$a->id],false) }}" data-status-url="{{ route('asset.status',[$project->id,$a->id],false) }}" type="button" onclick="generateAsset(this,{{ $project->id }},{{ $a->id }})" @disabled($a->image_status==='generating')>{{ $a->file_path?'重新生成图片':'AI 生成图片' }}</button>
@endif
</div>
</div>
<div class="asset-preview">
@if($a->file_path)
<button type="button" class="asset-preview-trigger"
    data-preview-src="{{ route('asset.file',[$project->id,$a->id]) }}?v={{ substr(sha1((string)$a->file_path),0,14) }}"
    data-preview-name="{{ $a->name }}"
    data-download-url="{{ route('asset.download',[$project->id,$a->id]) }}"
    onclick="openAssetPreview(this)"
    aria-label="预览{{ $a->name }}参考图">
    <img src="{{ route('asset.thumbnail',[$project->id,$a->id]) }}?v={{ substr(sha1((string)$a->file_path),0,14) }}" alt="{{ $a->name }}缩略图" loading="lazy" decoding="async" width="176" height="132"
         style="display:block;width:100%;max-width:176px;height:132px;max-height:132px;object-fit:contain;">
    <span class="asset-preview-label">点击放大预览</span>
</button>
<a class="button outline asset-download-button" href="{{ route('asset.download',[$project->id,$a->id]) }}">↓ 下载原图</a>
@else
<div class="asset-placeholder">{{ $a->source_kind==='upload'?'等待客户上传':'等待生成图片' }}</div>
@endif
</div>
</div>
@endforeach
@else<div class="empty small">生成分镜后自动提取人物、场景和道具，并判断哪些必须上传原图。</div>@endif
</section></div>
<dialog id="asset-lightbox" class="asset-lightbox" aria-label="资产图片大图预览">
    <div class="asset-lightbox-head">
        <strong id="asset-lightbox-title">参考图预览</strong>
        <div class="asset-lightbox-tools"><button type="button" id="asset-lightbox-mode" class="button ghost" onclick="toggleAssetPreviewMode()">100% 原始尺寸</button>
<button type="button" class="button ghost" onclick="closeAssetPreview()" aria-label="关闭图片预览">关闭 ×</button></div>
    </div>
    <div class="asset-lightbox-stage">
        <img id="asset-lightbox-image" alt="" decoding="async" />
    </div>
    <div class="asset-lightbox-actions">
        <span class="muted small">默认完整显示 · 可切换 100% 原始尺寸 · 按 Esc 关闭</span>
        <a id="asset-lightbox-download" class="button outline" href="#">↓ 下载原图（资产名称）</a>
    </div>
</dialog>
<section class="panel shots-panel"><div class="section-heading"><h2>04 / 分镜表</h2><span class="muted">按时间顺序 · 支持逐镜修改和导出提示词</span></div>
@if($shots->count())
@foreach($shots as $shot)
<article class="shot-card"><div class="shot-head"><span class="num">{{ str_pad($shot->sequence,2,'0',STR_PAD_LEFT) }}</span><strong>{{ $shot->title }}</strong><span class="chip">{{ $shot->duration_seconds }} 秒</span></div>
<form method="post" action="{{ route('shot.save',[$project->id,$shot->id]) }}" class="form-stack">@csrf
<label>镜头标题<input name="title" value="{{ $shot->title }}" maxlength="191" required></label>
<label>画面与动作<textarea rows="3" name="visual" required>{{ $shot->visual }}</textarea></label>
<div class="inline-fields"><label>开口之前的动作<textarea rows="2" name="action_before">{{ $shot->action_before }}</textarea></label><label>口播/台词<textarea rows="2" name="dialogue">{{ $shot->dialogue }}</textarea></label></div>
<div class="inline-fields"><label>画面文字/字幕<textarea rows="2" name="on_screen_text" placeholder="画面叠加显示的文案">{{ $shot->on_screen_text }}</textarea></label><label>声音呈现<select name="delivery"><option value="on_camera" @selected($shot->delivery==='on_camera')>人物面对镜头说话</option><option value="voiceover" @selected($shot->delivery==='voiceover')>画外音 / 旁白</option><option value="none" @selected($shot->delivery==='none')>无口播</option></select></label></div>
<div class="button-row"><label class="duration">时长（秒）<input type="number" min="1" max="{{ $project->shot_limit }}" name="duration_seconds" value="{{ $shot->duration_seconds }}" required></label><button class="button outline" type="submit">保存此镜头</button></div>
</form>
<div class="prompt-row"><select id="type-{{ $shot->id }}"><option value="kling">可灵 Kling</option><option value="seedance">Seedance</option><option value="stable-diffusion">Stable Diffusion 图片</option></select><button class="button ghost" type="button" onclick="loadPrompt({{ $shot->id }},{{ $project->id }})">生成适配提示词</button><button class="button ghost" type="button" onclick="copyPrompt({{ $shot->id }})">复制</button></div>
<textarea id="prompt-{{ $shot->id }}" class="prompt-output" rows="4" readonly placeholder="点击「生成适配提示词」查看结果..."></textarea>
</article>
@endforeach
@else<div class="empty">当前没有分镜。在上方保存剧本、配置 API 后，点击「AI 生成分镜与资产清单」。</div>@endif
</section>
<script>
const projectStyleSelector=document.getElementById('project-style-selector');
if(projectStyleSelector){
  const customLabel=document.getElementById('project-custom-style');
  const customField=customLabel?.querySelector('input[name="style"]');
  const syncStyle=()=>{
    const custom=projectStyleSelector.value==='custom';
    customLabel.hidden=!custom;
    customField.disabled=!custom;
    customField.required=custom;
  };
  projectStyleSelector.addEventListener('change',syncStyle);
  syncStyle();
}
const generationUrl=@json(route('generation.status',$project->id));
const runUrl=@json(route('generation.run',$project->id));
const initialGeneration=@json($project->status);
let runningRequest=false,taskReloaded=false;
const generationBox=document.getElementById('generation-state');
function renderGeneration(info){
 if(!generationBox)return;
 if(info.status==='queued') generationBox.innerHTML='<strong>排队等待执行</strong><p class="muted">任务已持久保存，马上请求文本模型。</p>';
 else if(info.status==='running') generationBox.innerHTML='<strong>文本模型处理中…</strong><p class="muted">已发送请求，请查看模型返回情况。接口较慢时允许多等一段时间；当前尝试次数：'+Number(info.attempts||1)+'。</p>';
 else if(info.status==='failed') {
   const strong=document.createElement('strong');strong.textContent='生成失败，可重试';
   const p=document.createElement('p');p.className='muted';p.textContent=info.error||'请求失败，请检查模型连接。';
   generationBox.replaceChildren(strong,p);
 } else if(info.status==='done') generationBox.innerHTML='<strong>✓ 分镜已生成</strong><p class="muted">正在刷新最新结果…</p>';
}
function runGeneration(){
 if(runningRequest)return;
 runningRequest=true;
 fetch(runUrl,{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,'Accept':'application/json'},credentials:'same-origin',keepalive:false})
   .catch(()=>{}).finally(()=>{runningRequest=false});
}
async function refreshGeneration(){
 try{
   const res=await fetch(generationUrl,{credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'});
   if(!res.ok)return;
   const data=await res.json();
   renderGeneration(data);
   if(data.status==='queued')runGeneration();
   if(data.status==='failed'){
     const submit=document.querySelector('form[action="{{ route('project.generate',$project->id) }}"] button[type=submit]');
     if(submit){submit.disabled=false;submit.textContent='重新尝试生成';}
   }
   if(data.status==='done'&&!taskReloaded){
     taskReloaded=true;
     // An explicit fragment and fresh navigation override Safari/Chrome's
     // previous scroll restoration to a large image in the asset list.
     const target=@json(route('project.show',$project->id));
     window.location.replace(target+'?result='+Date.now()+'#generation-state');
   }
 }catch(e){}
}
if(initialGeneration==='generating'){
 refreshGeneration();
 setInterval(refreshGeneration,3500);
}
// Native dialog keeps the full-resolution image out of the asset list.
function openAssetPreview(button){
  const dialog=document.getElementById('asset-lightbox');
  const image=document.getElementById('asset-lightbox-image');
  const title=document.getElementById('asset-lightbox-title');
  const download=document.getElementById('asset-lightbox-download');
  const url=button.dataset.previewSrc;
  if(!dialog||!image||!title||!download||!url)return;
  const name=button.dataset.previewName||'参考图';
  dialog.dataset.mode='fit';
  const modeButton=document.getElementById('asset-lightbox-mode');
  if(modeButton)modeButton.textContent='100% 原始尺寸';
  title.textContent=name+' · 原图预览';
  image.alt=name+'参考图';
  image.src=url;
  download.href=button.dataset.downloadUrl||url;
  if(typeof dialog.showModal==='function')dialog.showModal();
  else window.open(url,'_blank','noopener');
}
function toggleAssetPreviewMode(){
  const dialog=document.getElementById('asset-lightbox');
  const modeButton=document.getElementById('asset-lightbox-mode');
  if(!dialog)return;
  const actual=dialog.dataset.mode!=='actual';
  dialog.dataset.mode=actual?'actual':'fit';
  if(modeButton)modeButton.textContent=actual?'适合窗口（完整显示）':'100% 原始尺寸';
}
function closeAssetPreview(){
  document.getElementById('asset-lightbox')?.close();
}
const imageDialog=document.getElementById('asset-lightbox');
if(imageDialog){
  imageDialog.addEventListener('click',event=>{if(event.target===imageDialog)imageDialog.close();});
  imageDialog.addEventListener('close',()=>{
    const image=document.getElementById('asset-lightbox-image');
    if(image)image.removeAttribute('src');
  });
}
// Keep a precise failure stage instead of surfacing opaque Safari exceptions
// such as "The string did not match the expected pattern".
async function generateAsset(button,project,id,refresh=true){
  if(button.disabled)return false;
  button.disabled=true;
  const original=button.textContent;
  const status=document.getElementById('asset-status-'+id);
  if(status)status.textContent='正在请求图片模型，可能需要几分钟…';
  let success=false,stage='准备请求',httpStatus=0;
  try{
    const path=button.dataset.generateUrl;
    if(!path || !path.startsWith('/') || path.startsWith('//'))
      throw new Error('生图接口地址无效，请检查站点路由配置');
    const csrf=document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if(!csrf)throw new Error('当前页面缺少登录校验信息，请刷新后重新登录');
    stage='浏览器发送请求';
    const res=await fetch(path,{
      method:'POST',
      headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},
      credentials:'same-origin',redirect:'follow',cache:'no-store'
    });
    httpStatus=res.status;
    stage='读取服务器响应';
    const type=(res.headers.get('content-type')||'').toLowerCase();
    if(res.redirected && /\/login(?:[?#]|$)/.test(res.url||''))
      throw new Error('登录状态已过期，请重新登录后生成');
    const body=await res.text();
    if(!type.includes('json'))
      throw new Error('服务器返回的不是 JSON（HTTP '+res.status+'，'+(type||'未知类型')+'），请检查 PHP / 反向代理日志');
    stage='解析 JSON 响应';
    let data;
    try{data=JSON.parse(body);}
    catch(e){throw new Error('服务器返回的 JSON 无效（HTTP '+res.status+'）');}
    if(!res.ok){
      stage='服务端拒绝生图';
      if(res.status===419)throw new Error('页面会话已过期，请刷新后重新登录');
      if(res.status===429)throw new Error('操作频繁（HTTP 429），请稍后再试');
      throw new Error(data.error||data.message||'服务端错误 HTTP '+res.status);
    }
    stage='处理生成结果';
    if(data.status==='ready'){
      success=true;
      if(status)status.textContent='图片生成成功，正在显示预览…';
      if(refresh)window.location.reload();
    }else if(data.status==='generating'){
      success=true; // A durable remote task is accepted; completion is polled separately.
      const card=document.getElementById('asset-'+id);
      if(card)card.dataset.pending='1';
      if(status)status.textContent=data.message||'图片任务已提交，自动查询生成结果';
    }else{
      throw new Error(data.error||data.message||'服务端未返回预期的图片任务状态');
    }
  }catch(e){
    const detail=(e && e.message)?String(e.message):String(e);
    const patternError=/The string did not match the expected pattern|Invalid URL|Failed to construct.*URL/i.test(detail);
    if(status)status.textContent='图片生成失败【'+stage+(httpStatus?' / HTTP '+httpStatus:'')+'】：'+
      (patternError?'浏览器无法识别当前请求地址，请刷新页面；仍失败请提供浏览器控制台和网络请求信息。':detail);
    console.error('[AIDrama] Asset image request failed',{
      project,id,stage,httpStatus,errorName:e?.name||'Error',message:detail,
      endpoint:button.dataset.generateUrl||'(missing)'
    });
    // The server may still be generating after a browser/network disconnect.
    // Poll its status rather than silently submitting a duplicate paid call.
    if(stage==='浏览器发送请求'||stage==='读取服务器响应') {
      const card=document.getElementById('asset-'+id);
      if(card)card.dataset.pending='1';
    }
  }finally{button.disabled=false;button.textContent=original;}
  return success;
}
async function generateAllAssets(button,project){
  const buttons=Array.from(document.querySelectorAll('.asset-generate[data-has-image="0"]'));
  if(buttons.length===0){alert('当前没有待生成的普通资产');return;}
  if(!confirm('将顺序生成 '+buttons.length+' 个普通资产，调用图片模型可能产生 API 费用。客户产品与 Logo 不会自动生成，确认继续？'))return;
  button.disabled=true;
  let done=0;
  const status=document.getElementById('batch-image-status');
  for(const item of buttons){
    if(status)status.textContent='正在生成 '+(done+1)+' / '+buttons.length+' 项普通资产…';
    const ok=await generateAsset(item,project,Number(item.dataset.assetId),false);
    if(ok)done++;
  }
  if(status)status.textContent='批量提交完成，已提交或生成 '+done+' / '+buttons.length+' 项；完成后将自动显示图片。';
  button.disabled=false;
  if(done>0)window.location.reload();
}
async function pollImageAsset(id,project){
  const card=document.getElementById('asset-'+id);
  if(!card||card.dataset.pending!=='1')return;
  try{
    const button=card.querySelector('.asset-generate');
    const url=button?.dataset.statusUrl;
    if(!url)return;
    const r=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'});
    if(!r.ok)return;
    const data=await r.json();
    const status=document.getElementById('asset-status-'+id);
    if(data.status==='ready'){window.location.reload();return;}
    if(data.status==='generating'){
      if(status)status.textContent=data.message||'图片正在服务器生成（'+(data.stage||'等待')+'），请不要重复提交';
      if(button)button.disabled=true;
    }else{
      card.dataset.pending='0';
      if(data.status==='error' && status)
        status.textContent='图片生成失败（服务器）：'+(data.error||'可重新尝试');
      if(button)button.disabled=false;
    }
  }catch(e){console.warn('[AIDrama] Image status query failed',id,e?.name);}
}
const assetProjectId=@json($project->id);
const imageAssetCards=document.querySelectorAll('.asset-media');
if(imageAssetCards.length){
  const check=()=>imageAssetCards.forEach(card=>{
    if(card.dataset.pending==='1')
      pollImageAsset(Number(card.id.replace('asset-','')),assetProjectId);
  });
  check();setInterval(check,4000);
}
async function loadPrompt(shot,project){
 const type=document.getElementById('type-'+shot).value;
 const area=document.getElementById('prompt-'+shot);
 area.value='生成中...';
 try {
  const res=await fetch('/projects/'+project+'/shots/'+shot+'/prompt?target='+encodeURIComponent(type),{credentials:'same-origin'});
  if(!res.ok)throw new Error('HTTP '+res.status);
  const data=await res.json();area.value=data.prompt||'';
 } catch(e){area.value='获取提示词失败：'+e.message}
}
async function copyPrompt(shot){
 const text=document.getElementById('prompt-'+shot).value;
 if(!text)return;
 try {await navigator.clipboard.writeText(text);alert('已复制');}catch(e){alert('无法自动复制，请手动选中文字复制')}
}
</script>
@endsection
