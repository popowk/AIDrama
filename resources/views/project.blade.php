@extends('layout')
@section('title',$project->name)
@section('content')
<div class="headline"><div><a class="breadcrumb" href="{{ route('home') }}">← 返回项目</a><h1>{{ $project->name }}</h1><p class="muted">{{ $project->style }} · 每镜最长 {{ $project->shot_limit }} 秒 · {{ ['draft'=>'待生成','generating'=>'生成中','ready'=>'已生成','error'=>'上次生成失败'][$project->status]??$project->status }}
@if($project->detected_mode) · 已识别：{{ $project->detected_mode==='talking'?'纯口播':'文案＋口播' }}@endif</p></div><a class="button outline" href="{{ route('project.export',$project->id) }}">导出项目 JSON</a></div>
<div class="project-grid">
<section class="panel"><div class="section-heading"><h2>01 / 剧本和生成参数</h2></div>
<form method="post" action="{{ route('project.update',$project->id) }}" class="form-stack">@csrf
<label>项目名称<input name="name" value="{{ $project->name }}" maxlength="191" required></label>
<div class="inline-fields"><label>画风<input name="style" value="{{ $project->style }}" maxlength="191" required></label>
<label>镜头时长上限<select name="shot_limit"><option value="15" @selected($project->shot_limit==15)>15 秒</option><option value="30" @selected($project->shot_limit==30)>30 秒</option></select></label></div>
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
<div class="section-heading space"><h2>03 / 资产清单</h2><span class="muted">真实上传 / AI 生成</span></div>
<p class="hint">Logo、产品型号、产品外观必须上传原始素材；普通角色、场景和非品牌道具可使用图片模型生成。素材仅在管理员登录后可查看。</p>
@if($assets->count())
@foreach($assets as $a)
<div class="asset asset-media" id="asset-{{ $a->id }}">
<div class="asset-info">
<span class="asset-type">{{ ['character'=>'人物','scene'=>'场景','prop'=>'道具'][$a->type]??$a->type }}</span>
<strong>{{ $a->name }}</strong>
<span class="chip">{{ $a->source_kind==='upload'?'必须使用上传素材':'允许 AI 生成' }}</span>
<p>{{ $a->description }}</p>
@if($a->voice_description)<p class="muted small">声音设定：{{ $a->voice_description }}</p>@endif
<p class="muted small asset-status" id="asset-status-{{ $a->id }}">@if($a->file_path){{ $a->source_kind==='upload'?'已上传真实参考图':'AI 图片已生成' }}@elseif($a->source_kind==='upload')待客户上传原图@else{{ ['pending'=>'待生成','generating'=>'正在生成图片','error'=>'生成失败，可以重试'][$a->image_status]??$a->image_status }}@endif</p>
@if($a->image_status==='error'&&$a->image_error)<p class="muted small">{{ $a->image_error }}</p>@endif
<details><summary>查看资产生图提示词</summary><textarea rows="3" readonly>{{ $a->prompt }}</textarea></details>
<div class="asset-buttons">
@if($a->source_kind==='upload')
<form action="{{ route('asset.upload',[$project->id,$a->id]) }}" method="post" enctype="multipart/form-data" class="asset-upload">@csrf
<label class="small">客户真实素材（PNG/JPG/WebP，≤10MB）<input name="image" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required></label>
<button class="button outline" type="submit">{{ $a->file_path?'替换真实素材':'上传真实素材' }}</button>
</form>
@else
<button class="button outline" type="button" onclick="generateAsset(this,{{ $project->id }},{{ $a->id }})" @disabled($a->image_status==='generating')>{{ $a->file_path?'重新生成图片':'AI 生成图片' }}</button>
@endif
</div>
</div>
<div class="asset-preview">
@if($a->file_path)
<a href="{{ route('asset.file',[$project->id,$a->id]) }}" target="_blank" rel="noopener"><img src="{{ route('asset.file',[$project->id,$a->id]) }}" alt="{{ $a->name }}参考图" loading="lazy"></a>
@else
<div class="asset-placeholder">{{ $a->source_kind==='upload'?'等待客户上传':'等待生成图片' }}</div>
@endif
</div>
</div>
@endforeach
@else<div class="empty small">生成分镜后自动提取人物、场景和道具，并判断哪些必须上传原图。</div>@endif
</section></div>
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
   if(data.status==='done'&&!taskReloaded){taskReloaded=true;window.location.reload();}
 }catch(e){}
}
if(initialGeneration==='generating'){
 refreshGeneration();
 setInterval(refreshGeneration,3500);
}
async function generateAsset(button,project,id){
  if(button.disabled)return;
  button.disabled=true;
  const original=button.textContent;
  const status=document.getElementById('asset-status-'+id);
  if(status)status.textContent='正在请求图片模型，可能需要几分钟…';
  try{
    const res=await fetch('/projects/'+project+'/assets/'+id+'/generate',{
      method:'POST',
      headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,'Accept':'application/json'},
      credentials:'same-origin'
    });
    const data=await res.json();
    if(!res.ok)throw new Error(data.error||'HTTP '+res.status);
    if(data.status==='ready'){window.location.reload();return;}
    if(status)status.textContent=data.message||'图片生成已开始，请刷新页面查看状态。';
  }catch(e){if(status)status.textContent='图片生成出错：'+e.message;}
  finally{button.disabled=false;button.textContent=original;}
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
