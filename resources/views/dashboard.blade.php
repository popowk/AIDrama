@extends('layout')
@section('title','项目')
@section('content')
<div class="headline"><div><div class="eyebrow">CREATIVE WORKSPACE</div><h1>我的创作项目</h1><p class="muted">从剧本到分镜，再到角色与场景资产，一处完成。</p></div><a class="button outline" href="{{ route('settings') }}">⚙ 模型配置</a></div>
<div class="columns">
<section class="panel create"><h2>新建项目</h2><form method="post" action="{{ route('project.create') }}" class="form-stack">@csrf
<label>项目名称<input name="name" required maxlength="191" placeholder="例如：悟空八戒的大棚监控故事"></label>
<div class="inline-fields"><label>统一画风<select name="style"><option>3D国风动画</option><option>写实电影</option><option>日系动漫</option><option>二维国漫</option><option>赛博朋克</option><option>纪录片实拍</option></select></label>
<label>单镜头最长<select name="shot_limit"><option value="15" selected>15 秒（默认）</option><option value="30">30 秒</option></select></label></div>
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
@endsection
