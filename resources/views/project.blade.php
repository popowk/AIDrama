@extends('layout')
@section('title',$project->name)
@section('content')
<div class="headline"><div><a class="breadcrumb" href="{{ route('home') }}">← 返回项目</a><h1>{{ $project->name }}</h1><p class="muted">{{ $project->style }} · 每镜最长 {{ $project->shot_limit }} 秒 · {{ ['draft'=>'待生成','generating'=>'生成中','ready'=>'已生成','error'=>'上次生成失败'][$project->status]??$project->status }}</p></div><a class="button outline" href="{{ route('project.export',$project->id) }}">导出项目 JSON</a></div>
<div class="project-grid">
<section class="panel"><div class="section-heading"><h2>01 / 剧本和生成参数</h2></div>
<form method="post" action="{{ route('project.update',$project->id) }}" class="form-stack">@csrf
<label>项目名称<input name="name" value="{{ $project->name }}" maxlength="191" required></label>
<div class="inline-fields"><label>画风<input name="style" value="{{ $project->style }}" maxlength="191" required></label>
<label>镜头时长上限<select name="shot_limit"><option value="15" @selected($project->shot_limit==15)>15 秒</option><option value="30" @selected($project->shot_limit==30)>30 秒</option></select></label></div>
<label>完整剧本<textarea name="script" rows="11" maxlength="100000" required>{{ $project->script }}</textarea></label>
<div class="button-row"><button class="button outline" type="submit">保存剧本</button></div></form>
<form method="post" action="{{ route('project.generate',$project->id) }}" onsubmit="return confirm('重新生成分镜会覆盖当前项目中已经保存的分镜编辑及资产清单，确定继续吗？')">@csrf
<button class="button primary wide" type="submit" @disabled($project->status==='generating')>✦ {{ $shots->count()?'重新生成分镜与资产':'AI 生成分镜与资产清单' }}</button><p class="hint">模型可能需要较长响应时间；当前版本为同步处理，生成期间不要重复提交。</p></form>
</section>
<section class="panel"><div class="section-heading"><h2>02 / 统计概览</h2></div>
<div class="stats"><div><strong>{{ $shots->count() }}</strong><small>镜头数</small></div><div><strong>{{ $total }}s</strong><small>预计总时长</small></div><div><strong>{{ $assets->count() }}</strong><small>资产数</small></div></div>
<p class="hint">时长依据中文台词字符与开口前动作估算，不等同真实配音时长；超过上限的台词会继续拆分为连续镜头。</p>
<div class="section-heading space"><h2>03 / 资产清单</h2><span class="muted">文本规划版</span></div>
@if($assets->count())
@foreach($assets as $a)
<div class="asset"><span class="asset-type">{{ ['character'=>'人物','scene'=>'场景','prop'=>'道具'][$a->type]??$a->type }}</span><strong>{{ $a->name }}</strong><p>{{ $a->description }}</p>
@if($a->voice_description)<p class="muted small">声音设定：{{ $a->voice_description }}</p>@endif
<details><summary>查看资产图片提示词</summary><textarea rows="4" readonly>{{ $a->prompt }}</textarea></details>
</div>
@endforeach
@else<div class="empty small">生成分镜后会自动提取人物、场景和道具。角色三视图与声音参考素材生成将在后续版本接入。</div>@endif
</section></div>
<section class="panel shots-panel"><div class="section-heading"><h2>04 / 分镜表</h2><span class="muted">按时间顺序 · 支持逐镜修改和导出提示词</span></div>
@if($shots->count())
@foreach($shots as $shot)
<article class="shot-card"><div class="shot-head"><span class="num">{{ str_pad($shot->sequence,2,'0',STR_PAD_LEFT) }}</span><strong>{{ $shot->title }}</strong><span class="chip">{{ $shot->duration_seconds }} 秒</span></div>
<form method="post" action="{{ route('shot.save',[$project->id,$shot->id]) }}" class="form-stack">@csrf
<label>镜头标题<input name="title" value="{{ $shot->title }}" maxlength="191" required></label>
<label>画面与动作<textarea rows="3" name="visual" required>{{ $shot->visual }}</textarea></label>
<div class="inline-fields"><label>开口之前的动作<textarea rows="2" name="action_before">{{ $shot->action_before }}</textarea></label><label>台词<textarea rows="2" name="dialogue">{{ $shot->dialogue }}</textarea></label></div>
<div class="button-row"><label class="duration">时长（秒）<input type="number" min="1" max="{{ $project->shot_limit }}" name="duration_seconds" value="{{ $shot->duration_seconds }}" required></label><button class="button outline" type="submit">保存此镜头</button></div>
</form>
<div class="prompt-row"><select id="type-{{ $shot->id }}"><option value="kling">可灵 Kling</option><option value="seedance">Seedance</option><option value="stable-diffusion">Stable Diffusion 图片</option></select><button class="button ghost" type="button" onclick="loadPrompt({{ $shot->id }},{{ $project->id }})">生成适配提示词</button><button class="button ghost" type="button" onclick="copyPrompt({{ $shot->id }})">复制</button></div>
<textarea id="prompt-{{ $shot->id }}" class="prompt-output" rows="4" readonly placeholder="点击「生成适配提示词」查看结果..."></textarea>
</article>
@endforeach
@else<div class="empty">当前没有分镜。在上方保存剧本、配置 API 后，点击「AI 生成分镜与资产清单」。</div>@endif
</section>
<script>
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
