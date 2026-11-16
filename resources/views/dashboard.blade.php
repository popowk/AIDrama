@extends('layout')
@section('title','项目')
@section('content')
<div class="headline"><div><div class="eyebrow">CREATIVE WORKSPACE</div><h1>我的创作项目</h1><p class="muted">从剧本到分镜，再到角色与场景资产，一处完成。</p></div><a class="button outline" href="{{ route('settings') }}">⚙ 模型配置</a></div>
<div class="columns">
<section class="panel create"><h2>新建项目</h2><form method="post" action="{{ route('project.create') }}" class="form-stack">@csrf
<label>项目名称<input name="name" required maxlength="191" placeholder="例如：悟空八戒的大棚监控故事"></label>
<div class="inline-fields">
<label>参考风格库
<select name="style_preset" id="new-project-style-preset">
@foreach($stylePresets as $key=>$entry)
<option value="{{ $key }}" @selected($key==='guofeng_3d')>{{ $entry['label'] }} · {{ $entry['category']??'风格' }}</option>
@endforeach
</select></label>
<label>统一画风（可修改）<input name="style" id="new-project-style-name" value="3D国风动画" maxlength="191" required></label>
</div>
<p id="new-project-style-summary" class="hint">可选具体视觉风格描述，生图时自动加入镜头、光线和材质指令；你也可以手动修改名称。</p>
<div class="inline-fields">
<label>项目画幅
<select name="frame_ratio"><option value="landscape" selected>横屏 16:9（默认）</option><option value="portrait">竖屏 9:16</option></select></label>
<label>单镜头最长<select name="shot_limit"><option value="15" selected>15 秒（默认）</option><option value="30">30 秒</option></select></label>
</div>
<details class="creative-skill-picker"><summary>创作技能库（可选 · 按生成类型组合提示词）</summary>
<input type="hidden" name="skills_present" value="1">
<div class="creative-skill-options">
@foreach($creativeSkills as $key=>$skill)
<label class="creative-skill-option">
<input type="checkbox" name="skill_keys[]" value="{{ $key }}" @checked(in_array($key,['continuity','story_order','quality_check'],true))>
<span><strong>{{ $skill['label'] }}</strong><small>{{ $skill['description'] }}</small></span>
</label>
@endforeach
</div>
</details>
<label>创作形式<select name="script_mode"><option value="auto" selected>自动识别纯口播 / 文案＋口播</option><option value="talking">纯口播</option><option value="mixed">文案＋口播</option></select></label>
<label>完整剧本<textarea name="script" rows="12" maxlength="100000" required placeholder="粘贴剧本、台词和场景描述。建好项目后点击「AI 生成分镜」..."></textarea></label>
<button class="button primary wide" type="submit">创建项目 →</button></form></section>
<section class="panel"><div class="section-heading"><h2>最近的项目</h2><span class="muted">{{ $projects->total() }} 个</span></div>
@if($projects->count())
<div class="project-list">
@foreach($projects as $p)
<a class="project-row" href="{{ route('project.show',$p->id) }}"><div class="project-mark">▤</div><div class="grow"><strong>{{ $p->name }}</strong><small>{{ $p->style }} · 最长 {{ $p->shot_limit }} 秒 · {{ $p->updated_at }}</small></div><span class="status">{{ ['draft'=>'待制作','generating'=>'生成中','ready'=>'已生成','error'=>'需重试'][$p->status]??$p->status }}</span><span class="arrow">›</span></a>
@endforeach
</div><div class="pager">{{ $projects->links() }}</div>
@else<div class="empty">目前还没有项目。左侧创建你的第一个剧本项目。</div>@endif
</section></div>
<script>
const newStyleOptions=@json(collect($stylePresets)->map(fn($entry)=>['label'=>$entry['label'],'summary'=>$entry['summary']??''])->all());
document.getElementById('new-project-style-preset')?.addEventListener('change',event=>{
  const selected=newStyleOptions[event.target.value];
  if(!selected)return;
  if(event.target.value!=='custom')document.getElementById('new-project-style-name').value=selected.label;
  document.getElementById('new-project-style-summary').textContent=selected.summary||'使用自定义画风描述。';
});
</script>
@endsection
