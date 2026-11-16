@extends('layout')
@section('title',$project->name)
@section('content')
<div class="headline"><div><a class="breadcrumb" href="{{ route('home') }}">← 返回项目</a><h1>{{ $project->name }}</h1><p class="muted">{{ $project->style }} · {{ \App\Services\FrameProfile::label($project->frame_ratio??'landscape') }} · 每镜最长 {{ $project->shot_limit }} 秒 · {{ ['draft'=>'待生成','generating'=>'生成中','ready'=>'已生成','error'=>'上次生成失败'][$project->status]??$project->status }}
@if($project->detected_mode) · 已识别：{{ $project->detected_mode==='talking'?'纯口播':'文案＋口播' }}@endif</p></div><a class="button outline" href="{{ route('project.export',$project->id) }}">导出项目 JSON</a></div>
<div class="project-grid">
<section class="panel" id="script-editor"><div class="section-heading"><h2>01 / 剧本和生成参数</h2></div>
<form method="post" id="project-save-form" action="{{ route('project.update',$project->id) }}" class="form-stack">@csrf
<label>项目名称<input name="name" value="{{ $project->name }}" maxlength="191" required></label>
<div class="inline-fields">
<label>镜头时长上限<select name="shot_limit"><option value="15" @selected($project->shot_limit==15)>15 秒</option><option value="30" @selected($project->shot_limit==30)>30 秒</option></select></label>
<label>视频创作模式
<select name="video_mode" id="project-video-mode">
<option value="short" @selected(($project->video_mode??'short')==='short')>短视频（默认 · 紧凑节奏 / 智能合镜）</option>
<option value="story" @selected(($project->video_mode??'short')==='story')>正常故事片（保留原有叙事节奏）</option>
</select></label>
<label>背景音乐
<select name="allow_background_music">
<option value="0" @selected(!($project->allow_background_music??false))>不生成背景音乐（默认）</option>
<option value="1" @selected((bool)($project->allow_background_music??false))>允许背景音乐</option>
</select></label>
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
<p class="hint">短视频会尽量把同场景的表情、动作与口播安排在同镜头中，并在总时长允许时合并分镜；不自动生成字幕。台词允许多个角色轮流说话。</p>
<label>创作形式<select name="script_mode"><option value="auto" @selected($project->script_mode==='auto')>自动识别纯口播 / 文案＋口播</option><option value="talking" @selected($project->script_mode==='talking')>纯口播（角色面对镜头说话）</option><option value="mixed" @selected($project->script_mode==='mixed')>文案＋口播（画面展示与旁白）</option></select></label>
<label>原稿输入方式
<select name="input_kind" id="project-input-kind">
<option value="script" @selected(($project->input_kind??'script')==='script')>完整剧本</option>
<option value="storyboard" @selected(($project->input_kind??'script')==='storyboard')>粘贴分镜表格 → AI 逐镜扩写剧本</option>
</select></label>
<div id="project-storyboard-pane" @if(($project->input_kind??'script')!=='storyboard') hidden @endif>
<label>原始分镜表格<textarea name="storyboard_source" id="project-storyboard-source" rows="11" maxlength="100000" placeholder="从 Excel 直接粘贴；或粘贴 Markdown 表格，列名如：镜号 | 画面 | 台词 | 画面字幕">{{ $project->storyboard_source??'' }}</textarea></label>
<p class="hint">支持 Excel、Markdown、CSV，以及从网页复制后按“一行一个单元格”展开的表格。自动按镜号组合镜头，最多 40 镜。保存后可逐镜扩写，原始表格不会被覆盖。</p>
<div id="storyboard-preview" class="storyboard-preview" aria-live="polite"></div>
</div>
<div id="project-script-pane" @if(($project->input_kind??'script')==='storyboard' && trim((string)$project->script)==='') hidden @endif>
<label><span id="project-script-label">{{ ($project->input_kind??'script')==='storyboard'?'扩写后的完整剧本（可编辑）':'完整剧本' }}</span>
<textarea name="script" id="project-script-source" rows="11" maxlength="100000" @required(($project->input_kind??'script')!=='storyboard')>{{ $project->script }}</textarea></label>
</div>
<div class="button-row"><button class="button outline" type="submit" id="save-project-script-btn">保存剧本</button></div>
<p id="project-save-status" class="hint" role="status" aria-live="polite"></p>
</form>
<form method="post" action="{{ route('project.expand',$project->id) }}" id="project-expand-form" @if(($project->input_kind??'script')!=='storyboard') hidden @endif>@csrf
<button class="button primary wide" type="submit" id="expand-storyboard-btn" @disabled($project->status==='generating'||empty($project->storyboard_source))>✦ AI 按分镜逐段生成剧本</button>
<p class="hint" id="expand-help">先保存粘贴的分镜表格，再点击这里。逐镜扩写不会删除已有分镜或资产。</p>
@if($project->storyboard_dirty??false)<p class="hint">原始表格已更改，旧剧本和素材已保留。请重新扩写，之后才能用新内容生成分镜。</p>@endif
</form>
@if(count($scriptSegments))
<div id="script-segments" class="segment-workspace">
<div class="section-heading space"><h3>逐镜扩写的剧本 · {{ count($scriptSegments) }} 段</h3><span class="muted">可逐段修改并合并回完整剧本</span></div>
<form method="post" action="{{ route('project.segments.save',$project->id) }}" class="form-stack" data-inline-save="segments">@csrf
@foreach($scriptSegments as $index=>$segment)
<details class="segment-card" @if($index===0) open @endif>
<summary><strong>{{ str_pad($segment['sequence']??($index+1),2,'0',STR_PAD_LEFT) }} · {{ $segment['title']??'镜头' }}</strong><span class="muted small">{{ mb_strimwidth((string)($segment['visual']??''),0,65,'…') }}</span></summary>
<label>本镜的完整剧本<textarea rows="7" name="parts[{{ $index }}]" maxlength="20000" required>{{ $segment['script']??'' }}</textarea></label>
</details>
@endforeach
<div class="button-row"><button class="button outline" type="submit" @disabled($project->status==='generating')>保存全部分段剧本并同步完整剧本</button></div>
</form></div>
@endif
<div class="post-script-generation">
<form method="post" action="{{ route('project.generate',$project->id) }}" onsubmit="return confirm('生成成功后将替换当前分镜和资产，原有数据在模型返回前会保留，确定提交吗？')">@csrf
<button class="button primary wide" id="generate-shots-btn" type="submit" @disabled($project->status==='generating'||trim((string)$project->script)===''||(($project->input_kind??'script')==='storyboard'&&($project->storyboard_dirty??false)))>✦ {{ $shots->count()?'重新生成分镜与资产':'AI 生成分镜与资产清单' }}</button></form>
<div id="generation-state" class="task-state" data-status="{{ $project->status }}">
  @if($project->status==='generating')<strong>正在等待文本模型返回</strong><p class="muted">任务已保存。页面会自动显示进度，处理可能需要数分钟；无需重复提交。</p>
  @elseif($project->status==='error')<strong>上次生成没有完成</strong><p class="muted">原有分镜不会因请求失败而被清空，检查模型后可以重新生成。</p>
  @else<p class="hint">生成任务会保存在数据库。API 最长等待约 145 秒，遇到限流或暂时故障会自动重试一次。</p>@endif
</div>
</div>
</section>
<section class="panel"><div class="section-heading"><h2>02 / 统计概览</h2></div>
<div class="stats"><div><strong>{{ $shots->count() }}</strong><small>镜头数</small></div><div><strong>{{ $total }}s</strong><small>预计总时长</small></div><div><strong id="asset-active-count">{{ $assets->count() }}</strong><small>使用中的资产</small></div></div>
<p class="hint">时长依据中文台词字符与开口前动作估算，不等同真实配音时长；超过上限的台词会继续拆分为连续镜头。</p>
<div class="section-heading space" id="assets-panel"><h2>03 / 资产清单</h2>
<button class="button ghost" id="generate-all-assets-btn" type="button"
  onclick="generateAllAssets(this,{{ $project->id }})"
  @disabled(!$assets->contains(fn($a)=>$a->source_kind==='generate'))>一键生成普通资产图</button>
</div>
<p id="batch-image-status" class="hint" role="status"></p>
<p class="hint">chatgpt2api 3.x 异步模式：提交后可关闭页面，任务 ID 会保留；再次打开页面自动查询结果。批量操作只提交任务，不重复生图。</p>
<p class="hint">Logo、产品型号、产品外观必须上传原始素材；普通角色、场景和非品牌道具可使用图片模型生成。素材仅在管理员登录后可查看。</p>
@if($allAssets->count())
@foreach($allAssets as $a)
<div class="asset-fold-card @if($a->is_archived) is-archived @endif"
     id="asset-shell-{{ $a->id }}" data-archived="{{ $a->is_archived?'1':'0' }}"
     data-asset-name="{{ $a->name }}" data-source-kind="{{ $a->source_kind }}">
  <div class="asset-fold-header">
    <div class="asset-fold-heading">
      <span class="asset-type">{{ ['character'=>'人物','scene'=>'场景','prop'=>'道具'][$a->type]??$a->type }}</span>
      <strong>{{ $a->name }}</strong>
      <span class="asset-fold-label muted small">{{ $a->is_archived?'已折叠 · 不参与生图':'' }}</span>
    </div>
    <form method="post" class="asset-fold-action" action="{{ $a->is_archived?route('asset.restore',[$project->id,$a->id]):route('asset.archive',[$project->id,$a->id]) }}"
      data-archive-url="{{ route('asset.archive',[$project->id,$a->id]) }}"
      data-restore-url="{{ route('asset.restore',[$project->id,$a->id]) }}"
      data-asset-id="{{ $a->id }}" data-inline-toggle="asset">
      @csrf
      <button class="button outline" type="submit" @disabled($a->image_status==='generating'||$project->status==='generating')>{{ $a->is_archived?'恢复资产':'移除 · 折叠' }}</button>
    </form>
  </div>
  <p class="asset-fold-feedback hint" role="status" aria-live="polite"></p>
  <div class="asset-fold-body" @if($a->is_archived) hidden @endif>
    <div class="asset asset-media" id="asset-{{ $a->id }}" data-pending="{{ $a->image_status==='generating'?'1':'0' }}">
<div class="asset-info">
<span class="chip">{{ $a->source_kind==='upload'?'必须使用上传素材':'允许 AI 生成' }}</span>
<p>{{ $a->description }}</p>
@if($a->voice_description)<p class="muted small">声音设定：{{ $a->voice_description }}</p>@endif
<p class="muted small asset-status" id="asset-status-{{ $a->id }}">@if($a->file_path){{ $a->source_kind==='upload'?'已上传真实参考图':'AI 图片已生成' }}@elseif($a->source_kind==='upload')待客户上传原图@else{{ ['pending'=>'待生成','generating'=>'异步生成中，自动查询结果','error'=>'生成失败，可查看原因后重试'][$a->image_status]??$a->image_status }}@endif</p>
@if($a->image_status==='error'&&$a->image_error)<p class="muted small">{{ $a->image_error }}</p>@endif
<details class="asset-prompt-editor" @if(session('open_asset_prompt')===$a->id) open @endif>
<summary>{{ ($a->prompt_override??false)?'编辑手动生图提示词':'查看 / 编辑生图提示词' }} <span class="muted small">· {{ ['character'=>'人物','scene'=>'场景','prop'=>'道具'][$a->type]??$a->type }}模板{{ ($a->prompt_override??false)?'（单资产覆盖中）':'' }}</span></summary>
<p class="hint">默认从模型设置中读取{{ ['character'=>'人物','scene'=>'场景','prop'=>'道具'][$a->type]??'道具' }}专用提示词。此处保存后仅覆盖当前资产，不影响其他资产；留空保存将恢复类别模板。</p>
<form method="post" action="{{ route('asset.prompt.save',[$project->id,$a->id]) }}" class="asset-prompt-form" data-inline-save="asset-prompt">@csrf
<label class="small">实际传给图片模型的提示词
<textarea name="prompt" rows="7" maxlength="30000" @disabled($a->image_status==='generating'||$project->status==='generating')>{{ \App\Services\AssetImagePrompt::build($project,$a) }}</textarea></label>
<button class="button outline" type="submit" @disabled($a->image_status==='generating'||$project->status==='generating')>保存该资产提示词</button>
</form>
<form method="post" action="{{ route('asset.prompt.reset',[$project->id,$a->id]) }}" class="asset-prompt-reset" data-inline-save="asset-prompt-reset">@csrf
<button class="button ghost" type="submit" @disabled($a->image_status==='generating'||$project->status==='generating')>恢复使用类别提示词</button></form>
</details>
<div class="asset-buttons">
<form method="post" action="{{ route('asset.frameRatio',[$project->id,$a->id]) }}" class="asset-ratio-form" data-inline-save="asset-ratio">
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
<form action="{{ route('asset.upload',[$project->id,$a->id]) }}" method="post" enctype="multipart/form-data" class="asset-upload" data-inline-save="asset-upload">@csrf
<label class="small">客户真实素材（PNG/JPG/WebP，≤10MB）<input name="image" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required></label>
<button class="button outline" type="submit">{{ $a->file_path?'替换真实素材':'上传真实素材' }}</button>
</form>
@else
<button class="button outline asset-generate" data-asset-id="{{ $a->id }}" data-has-image="{{ $a->file_path?'1':'0' }}" data-generate-url="{{ route('asset.generate',[$project->id,$a->id],false) }}" data-status-url="{{ route('asset.status',[$project->id,$a->id],false) }}" type="button" onclick="generateAsset(this,{{ $project->id }},{{ $a->id }})" @disabled($a->image_status==='generating'||$a->is_archived||$project->status==='generating')>{{ $a->file_path?'重新生成图片':'AI 生成图片' }}</button>
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
  </div>
</div>
@endforeach
@else<div class="empty small">生成分镜后会在这里显示人物、场景与道具。</div>@endif
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
<article class="shot-card" id="shot-{{ $shot->id }}"><div class="shot-head"><span class="num">{{ str_pad($shot->sequence,2,'0',STR_PAD_LEFT) }}</span><strong>{{ $shot->title }}</strong><span class="chip">{{ $shot->duration_seconds }} 秒</span></div>
<form method="post" action="{{ route('shot.save',[$project->id,$shot->id]) }}" class="form-stack" data-inline-save="shot">@csrf
<label>镜头标题<input name="title" value="{{ $shot->title }}" maxlength="191" required></label>
<label>画面与动作<textarea rows="3" name="visual" required>{{ $shot->visual }}</textarea></label>
<div class="inline-fields"><label>开口之前必须完成的动作<textarea rows="2" name="action_before" placeholder="可边说边做的动作请写在画面与动作里">{{ $shot->action_before }}</textarea></label>
<label>口播/台词（同一框可多人轮流说）
<textarea rows="4" name="dialogue" placeholder="悟空：二师兄，看俺这个！&#10;八戒：这东西可真省心。">{{ $shot->dialogue }}</textarea>
<small class="muted">多人对白请每行写“人物名：台词”，保留先后顺序；同一镜头可边动作边对话。</small></label></div>
<div class="inline-fields"><label class="legacy-screen-copy" @if(($project->video_mode??'short')==='short') hidden @endif>故事片原有画面文案<textarea rows="2" name="on_screen_text" placeholder="可选；视频提示词默认不生成字幕">{{ $shot->on_screen_text }}</textarea></label><label>声音呈现<select name="delivery"><option value="on_camera" @selected($shot->delivery==='on_camera')>人物面对镜头说话</option><option value="conversation" @selected($shot->delivery==='conversation')>人物之间对话（看向对方）</option><option value="voiceover" @selected($shot->delivery==='voiceover')>画外音 / 旁白</option><option value="none" @selected($shot->delivery==='none')>无口播</option></select></label></div>
<div class="button-row"><label class="duration">时长（秒）<input type="number" min="1" max="{{ $project->shot_limit }}" name="duration_seconds" value="{{ $shot->duration_seconds }}" required></label><button class="button outline" type="submit">保存此镜头</button></div>
</form>
<div class="prompt-row"><select id="type-{{ $shot->id }}"><option value="kling">可灵 Kling</option><option value="seedance">Seedance</option><option value="stable-diffusion">Stable Diffusion 图片</option></select><button class="button ghost" type="button" onclick="loadPrompt({{ $shot->id }},{{ $project->id }})">生成适配提示词</button><button class="button ghost" type="button" onclick="copyPrompt({{ $shot->id }})">复制</button></div>
<textarea id="prompt-{{ $shot->id }}" class="prompt-output" rows="4" readonly placeholder="点击「生成适配提示词」查看结果..."></textarea>
</article>
@endforeach
@else<div class="empty">当前没有分镜。先输入完整剧本，或粘贴分镜表格并逐镜扩写，再点击「AI 生成分镜与资产清单」。</div>@endif
</section>
{{-- This helper is served inline: the updater syncs CSS but not new public JS files. --}}
<script>{!! file_get_contents(public_path('storyboard-state.js')) !!}</script>
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
const projectVideoMode=document.getElementById('project-video-mode');
projectVideoMode?.addEventListener('change',()=>{
  const story=projectVideoMode.value==='story';
  document.querySelectorAll('.legacy-screen-copy').forEach(label=>label.hidden=!story);
});
const inputKind=document.getElementById('project-input-kind');
const storyboardSource=document.getElementById('project-storyboard-source');
const scriptSource=document.getElementById('project-script-source');
const storyboardState=window.AIDramaStoryboardState;
let savedStoryboard=@json((string)($project->storyboard_source??''));
let savedKind=@json((string)($project->input_kind??'script'));
let serverCanExpand=@json(($project->input_kind??'script')==='storyboard'&&$project->status!=='generating'&&!empty($project->storyboard_source));
let projectIsGenerating=@json($project->status==='generating');
let saveInProgress=false;
let storyboardDirty=@json((bool)($project->storyboard_dirty??false));
let hasSavedScript=@json(trim((string)$project->script)!=='');
const saveForm=document.getElementById('project-save-form');
const saveButton=document.getElementById('save-project-script-btn');
const saveStatus=document.getElementById('project-save-status');
const generationButton=document.getElementById('generate-shots-btn');
function syncGenerationButton(){
  if(generationButton){
    generationButton.disabled=projectIsGenerating||saveInProgress||!hasSavedScript||
       inputKind?.value!==savedKind||
       (inputKind?.value==='storyboard'&&(storyboardDirty||
         storyboardState.hasUnsavedChanges(storyboardSource?.value,savedStoryboard)));
  }
}
function showSourceMode(){
 if(!inputKind)return;
 const storyboard=inputKind.value==='storyboard';
 document.getElementById('project-storyboard-pane').hidden=!storyboard;
 document.getElementById('project-expand-form').hidden=!storyboard;
 document.getElementById('project-script-pane').hidden=storyboard&&!scriptSource.value.trim();
 scriptSource.required=!storyboard;
 storyboardSource.required=storyboard;
 document.getElementById('project-script-label').textContent=storyboard?'扩写后的完整剧本（可编辑）':'完整剧本';
 syncExpandButton();
 syncGenerationButton();
}
function syncExpandButton(){
 const button=document.getElementById('expand-storyboard-btn');
 const help=document.getElementById('expand-help');
 if(!button||!storyboardSource)return;
 const changed=storyboardState.hasUnsavedChanges(storyboardSource.value,savedStoryboard);
 const eligible=storyboardState.canExpand({
   projectIsGenerating,saveInProgress,serverCanExpand,
   selectedKind:inputKind?.value,savedKind,
   currentSource:storyboardSource.value,savedSource:savedStoryboard
 });
 button.disabled=!eligible;
 if(projectIsGenerating)help.textContent='已有生成任务正在运行，请查看下方任务状态。';
 else if(saveInProgress)help.textContent='正在保存原始分镜…';
 else if(changed||inputKind?.value!==savedKind)
   help.textContent='表格尚未保存，点击“保存剧本”后即可扩写。';
 else if(eligible)help.textContent='✓ 已保存，可以点击上方按钮逐镜扩写剧本。';
 else help.textContent='请先粘贴有效分镜并保存，之后即可扩写。';
 syncGenerationButton();
}
let previewTimer=0,previewRequest=0;
function showStoryboardPreview(){
 clearTimeout(previewTimer);
 const preview=document.getElementById('storyboard-preview');
 const source=storyboardSource?.value.trim()||'';
 if(!preview)return;
 preview.replaceChildren();
 if(!source)return;
 const note=document.createElement('p');
 note.className='hint';note.textContent='正在解析分镜表格…';
 preview.appendChild(note);
 const requestNo=++previewRequest;
 previewTimer=setTimeout(async()=>{
   try{
     const response=await fetch(@json(route('project.storyboard.preview',$project->id)),{
       method:'POST',credentials:'same-origin',
       headers:{'Accept':'application/json','Content-Type':'application/json',
                'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
       body:JSON.stringify({storyboard_source:source})
     });
     const body=await response.json();
     if(requestNo!==previewRequest)return;
     preview.replaceChildren();
     if(!response.ok){
       const msg=body?.errors?.storyboard_source?.[0]||body?.message||'表格解析失败';
       const p=document.createElement('p');p.className='hint storyboard-preview-error';
       p.textContent=msg;preview.appendChild(p);return;
     }
     const p=document.createElement('p');p.className='hint';
     p.textContent='✓ 已识别 '+Number(body.count)+' 个分镜（显示前 5 镜），列与原始台词保持对应';
     preview.appendChild(p);
     const table=document.createElement('table');table.className='storyboard-mini-table';
     const head=document.createElement('tr');
     ['镜号','画面','台词','画面字幕'].forEach(label=>{
       const cell=document.createElement('th');cell.textContent=label;head.appendChild(cell);
     });
     table.appendChild(head);
     (body.rows||[]).forEach(row=>{
       const tr=document.createElement('tr');
       [row.original_number,row.visual,row.dialogue,row.on_screen_text].forEach(value=>{
         const cell=document.createElement('td');
         cell.textContent=String(value??'');tr.appendChild(cell);
       });
       table.appendChild(tr);
     });
     preview.appendChild(table);
   }catch(err){
     if(requestNo!==previewRequest)return;
     note.textContent='预览请求失败，请检查网络和登录状态；仍可点击保存查看校验信息。';
   }
 },320);
}
saveForm?.addEventListener('submit',async function(event){
 event.preventDefault();
 if(!saveButton)return;
 saveInProgress=true;
 saveButton.disabled=true;
 syncExpandButton();
 saveStatus.textContent='正在保存原稿和生成参数…';
 saveStatus.className='hint';
 try{
   const response=await fetch(saveForm.action,{
     method:'POST',credentials:'same-origin',redirect:'follow',
     headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
     body:new FormData(saveForm)
   });
   const contentType=(response.headers.get('content-type')||'').toLowerCase();
   if(!contentType.includes('application/json'))
     throw new Error('登录已过期或服务器未返回保存结果（HTTP '+response.status+'），请重新登录');
   const body=await response.json();
   if(!response.ok){
     const error=Object.values(body.errors||{}).flat().join('；');
     throw new Error(error||body.message||'保存失败（HTTP '+response.status+'）');
   }
   savedStoryboard=storyboardState.normalizeSource(body.storyboard_source??'');
   savedKind=String(body.input_kind??inputKind.value);
   serverCanExpand=body.can_expand===true;
   storyboardDirty=Boolean(body.storyboard_dirty);
   hasSavedScript=Boolean(body.has_script);
   const ready=storyboardState.canExpand({
     projectIsGenerating,saveInProgress:false,serverCanExpand,
     selectedKind:inputKind.value,savedKind,
     currentSource:storyboardSource?.value,savedSource:savedStoryboard
   });
   saveStatus.textContent='✓ 已保存。'+(inputKind.value==='storyboard'
     ?(ready?'现在可以点击“AI 按分镜逐段生成剧本”。':'如果扩写按钮仍不可用，请检查表格内容或刷新页面。')
     :'可以继续生成分镜与资产。');
   saveStatus.className='hint storyboard-save-success';
   syncExpandButton();
   syncGenerationButton();
 }catch(err){
   saveStatus.textContent='✕ 未保存：'+String(err?.message||err);
   saveStatus.className='hint storyboard-preview-error';
 }finally{
   saveInProgress=false;
   saveButton.disabled=false;
   syncExpandButton();
   syncGenerationButton();
 }
});
inputKind?.addEventListener('change',showSourceMode);
storyboardSource?.addEventListener('input',()=>{syncExpandButton();showStoryboardPreview();});
scriptSource?.addEventListener('input',()=>{if(inputKind.value==='script')hasSavedScript=false;syncGenerationButton();});
showSourceMode();showStoryboardPreview();
if(['#script-editor','#script-segments'].includes(window.location.hash)){
 const anchor=window.location.hash;
 window.addEventListener('load',()=>document.querySelector(anchor)?.scrollIntoView({block:'start'}),{once:true});
}
const generationUrl=@json(route('generation.status',$project->id));
const runUrl=@json(route('generation.run',$project->id));
const initialGeneration=@json($project->status);
let runningRequest=false,taskReloaded=false;
const generationBox=document.getElementById('generation-state');
function renderGeneration(info){
 if(!generationBox)return;
 const scriptTask=info.kind==='expand';
 if(info.status==='queued') generationBox.innerHTML='<strong>排队等待执行</strong><p class="muted">'+(scriptTask?'等待逐镜剧本扩写':'等待生成分镜和资产')+'。</p>';
 else if(info.status==='running') generationBox.innerHTML='<strong>'+(scriptTask?'正在逐镜扩写剧本…':'文本模型处理中…')+'</strong><p class="muted">已发送请求；当前尝试次数：'+Number(info.attempts||1)+'。</p>';
 else if(info.status==='failed') {
   const strong=document.createElement('strong');strong.textContent='生成失败，可重试';
   const p=document.createElement('p');p.className='muted';p.textContent=info.error||'请求失败，请检查模型连接。';
   generationBox.replaceChildren(strong,p);
 } else if(info.status==='done') generationBox.innerHTML='<strong>✓ '+(scriptTask?'剧本扩写完成':'分镜已生成')+'</strong><p class="muted">正在刷新最新结果…</p>';
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
     window.location.replace(target+'?result='+Date.now()+(data.kind==='expand'?'#script-segments':'#generation-state'));
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
// Inline edits: save the record without navigating away or losing scroll position.
async function sendInlineForm(form){
  const response=await fetch(form.action,{
    method:'POST',body:new FormData(form),credentials:'same-origin',redirect:'follow',
    headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
    cache:'no-store'
  });
  const contentType=(response.headers.get('content-type')||'').toLowerCase();
  if(response.redirected || !contentType.includes('json'))
    throw new Error('登录状态已过期或接口没有返回 JSON（HTTP '+response.status+'），请刷新或重新登录');
  const result=await response.json();
  if(!response.ok||result.ok===false) {
    const detail=Object.values(result.errors||{}).flat().join('；');
    throw new Error(detail||result.message||'操作失败（HTTP '+response.status+'）');
  }
  return result;
}

function syncAssetTotals(){
  const active=document.querySelectorAll('.asset-fold-card:not(.is-archived)').length;
  const total=document.getElementById('asset-active-count');
  if(total)total.textContent=String(active);
  const generateAll=document.getElementById('generate-all-assets-btn');
  if(generateAll)
    generateAll.disabled=!document.querySelector('.asset-fold-card:not(.is-archived) .asset-generate');
}

async function toggleAssetFold(form){
  const id=Number(form.dataset.assetId);
  const shell=document.getElementById('asset-shell-'+id);
  const button=form.querySelector('button[type="submit"]');
  if(!shell||!button)return;
  const note=shell.querySelector('.asset-fold-feedback');
  const wasArchived=shell.dataset.archived==='1';
  button.disabled=true;
  if(note){note.textContent='正在保存…';note.classList.remove('is-error');}
  try{
    const result=await sendInlineForm(form);
    if(result.archived!==!wasArchived)
      throw new Error('服务器返回的资产状态与操作不匹配，请检查状态');
    const archived=Boolean(result.archived);
    shell.dataset.archived=archived?'1':'0';
    shell.classList.toggle('is-archived',archived);
    const body=shell.querySelector('.asset-fold-body');
    if(body)body.hidden=archived;
    const foldLabel=shell.querySelector('.asset-fold-label');
    if(foldLabel)foldLabel.textContent=archived?'已折叠 · 不参与生图':'';
    form.action=archived?form.dataset.restoreUrl:form.dataset.archiveUrl;
    button.textContent=archived?'恢复资产':'移除 · 折叠';
    if(note)note.textContent='';
    // Previously adapted prompts may still mention assets now folded.
    // Clear them and require a fresh model-generated prompt on next click.
    document.querySelectorAll('.prompt-output').forEach(area=>area.value='');
    syncAssetTotals();
  }catch(error){
    if(note){note.textContent='操作失败：'+(error.message||String(error));note.classList.add('is-error');}
  }finally{button.disabled=false;}
}

function inlineSaveMessage(form,message,failed){
  let status=form.nextElementSibling;
  if(!status||!status.classList.contains('inline-save-feedback')){
    status=document.createElement('p');
    status.className='inline-save-feedback';
    status.setAttribute('role','status');status.setAttribute('aria-live','polite');
    form.insertAdjacentElement('afterend',status);
  }
  status.textContent=(failed?'✕ ':'✓ ')+message;
  status.classList.toggle('is-error',failed);
  status.classList.toggle('is-success',!failed);
}

function siteRoute(url){
  if(!url)return null;
  try{
    const parsed=new URL(url,window.location.href);
    return parsed.origin===window.location.origin?parsed.toString():null;
  }catch(error){return null;}
}
function updateAssetPreview(id,result){
  if(!result?.image_url||!result?.thumbnail_url||!result?.download_url)return;
  const shell=document.getElementById('asset-shell-'+id);
  const card=document.getElementById('asset-'+id);
  const preview=card?.querySelector('.asset-preview');
  if(!shell||!preview)return;
  const imageUrl=siteRoute(result.image_url);
  const thumbnailUrl=siteRoute(result.thumbnail_url);
  const downloadUrl=siteRoute(result.download_url);
  if(!imageUrl||!thumbnailUrl||!downloadUrl)return;
  const cache='?v='+Date.now();
  const trigger=document.createElement('button');
  trigger.type='button';trigger.className='asset-preview-trigger';
  trigger.dataset.previewSrc=imageUrl+cache;
  trigger.dataset.previewName=shell.dataset.assetName||'资产参考图';
  trigger.dataset.downloadUrl=downloadUrl;
  trigger.setAttribute('aria-label','预览'+trigger.dataset.previewName+'参考图');
  const img=document.createElement('img');
  img.src=thumbnailUrl+cache;
  img.alt=trigger.dataset.previewName+'缩略图';
  img.loading='lazy';img.decoding='async';img.width=176;img.height=132;
  const label=document.createElement('span');
  label.className='asset-preview-label';label.textContent='点击放大预览';
  trigger.append(img,label);
  trigger.addEventListener('click',()=>openAssetPreview(trigger));
  const link=document.createElement('a');
  link.className='button outline asset-download-button';
  link.href=downloadUrl;link.textContent='↓ 下载原图';
  preview.replaceChildren(trigger,link);
  const button=card.querySelector('.asset-generate');
  if(button){button.dataset.hasImage='1';button.textContent='重新生成图片';}
  const upload=card.querySelector('.asset-upload button[type="submit"]');
  if(upload)upload.textContent='替换真实素材';
  card.dataset.pending='0';
  const status=card.querySelector('.asset-status');
  if(status)status.textContent=shell.dataset.sourceKind==='upload'?'已上传真实参考图':'AI 图片已生成';
}

document.addEventListener('submit',async event=>{
  const form=event.target;
  if(!(form instanceof HTMLFormElement))return;
  if(form.matches('[data-inline-toggle="asset"]')){
    event.preventDefault();
    await toggleAssetFold(form);
    return;
  }
  const kind=form.dataset.inlineSave;
  if(!kind)return;
  event.preventDefault();
  const submit=form.querySelector('button[type="submit"]');
  if(submit)submit.disabled=true;
  inlineSaveMessage(form,'正在保存…',false);
  try{
    const result=await sendInlineForm(form);
    const shell=form.closest('.asset-fold-card');
    const card=form.closest('.shot-card');
    if(kind==='asset-prompt'||kind==='asset-prompt-reset'){
      const field=shell?.querySelector('.asset-prompt-form textarea');
      if(field){
        field.value=String(result.prompt??'');
        field.dataset.lastSavedPrompt=field.value;
      }
      const summary=shell?.querySelector('.asset-prompt-editor summary');
      if(summary)summary.textContent=result.prompt_override
        ?'编辑手动生图提示词（单资产覆盖中）':'查看 / 编辑生图提示词（使用类别模板）';
    }else if(kind==='asset-ratio'){
      const field=shell?.querySelector('.asset-prompt-form textarea');
      if(field && !result.prompt_override &&
        field.value===(field.dataset.lastSavedPrompt??field.defaultValue)){
        field.value=String(result.prompt??'');
        field.dataset.lastSavedPrompt=field.value;
      }
    }else if(kind==='asset-upload'){
      const id=Number(shell?.id.replace('asset-shell-',''));
      if(id)updateAssetPreview(id,result);
      const input=form.querySelector('input[type="file"]');
      if(input)input.value='';
    }else if(kind==='segments'){
      if(scriptSource)scriptSource.value=String(result.script??'');
      hasSavedScript=Boolean(result.has_script);
      storyboardDirty=false;
      syncGenerationButton();
      const fullScriptPanel=document.getElementById('project-script-pane');
      if(fullScriptPanel&&hasSavedScript)fullScriptPanel.hidden=false;
    }else if(kind==='shot'&&card){
      const heading=card.querySelector('.shot-head strong');
      if(heading)heading.textContent=String(result.title??'');
      const duration=card.querySelector('.shot-head .chip');
      if(duration)duration.textContent=String(result.duration_seconds??'')+' 秒';
      const prompt=card.querySelector('.prompt-output');
      if(prompt)prompt.value='';
    }
    inlineSaveMessage(form,result.message||'保存成功',false);
  }catch(error){
    inlineSaveMessage(form,'保存失败：'+(error.message||String(error)),true);
  }finally{if(submit)submit.disabled=false;}
});

document.querySelectorAll('.asset-prompt-form textarea').forEach(field=>{
  field.dataset.lastSavedPrompt=field.value;
});

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
      if(status)status.textContent='图片已生成，正在更新预览…';
      updateAssetPreview(id,data);
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
  }finally{
    button.disabled=false;
    button.textContent=button.dataset.hasImage==='1'?'重新生成图片':original;
  }
  return success;
}
async function generateAllAssets(button,project){
  const buttons=Array.from(document.querySelectorAll(
    '.asset-fold-card:not(.is-archived) .asset-generate[data-has-image="0"]'
  )).filter(item=>!item.disabled);
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
  syncAssetTotals();
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
    if(data.status==='ready'){
      updateAssetPreview(id,data);
      if(button)button.disabled=false;
      return;
    }
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
    if(card.dataset.pending==='1' && card.closest('.asset-fold-card')?.dataset.archived!=='1')
      pollImageAsset(Number(card.id.replace('asset-','')),assetProjectId);
  });
  check();setInterval(check,4000);
}
async function loadPrompt(shot,project){
 const type=document.getElementById('type-'+shot).value;
 const area=document.getElementById('prompt-'+shot);
 area.value='文本大模型正在生成适配提示词…';
 try {
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content;
  if(!csrf)throw new Error('登录已失效，请刷新页面');
  const shotForm=document.getElementById('shot-'+shot)?.querySelector('form[data-inline-save="shot"]');
  if(!shotForm)throw new Error('无法读取当前分镜编辑框');
  const payload=new FormData(shotForm);
  payload.set('target',type);
  const res=await fetch('/projects/'+project+'/shots/'+shot+'/prompt',{
    method:'POST',credentials:'same-origin',
    headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},
    body:payload
  });
  const data=await res.json();
  if(!res.ok)throw new Error(data.message||'HTTP '+res.status);
  area.value=data.prompt||'';
 } catch(e){area.value='视频提示词生成失败：'+e.message}
}
async function copyPrompt(shot){
 const text=document.getElementById('prompt-'+shot).value;
 if(!text)return;
 try {await navigator.clipboard.writeText(text);alert('已复制');}catch(e){alert('无法自动复制，请手动选中文字复制')}
}
</script>
@endsection
