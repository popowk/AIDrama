@extends('layout')
@section('title','模型和提示词设置')
@section('content')
<div class="headline"><div><div class="eyebrow">MODELS & CREATIVE PROMPTS</div><h1>模型与提示词设置</h1><p class="muted">文本与图片模型左右独立配置。提示词提供可编辑的公开参考规则，也可切换成完全自定义版本。</p></div></div>

<div class="model-settings-grid">
<section class="panel settings-panel"><h2>01 / 文本模型</h2><p class="muted small">用于分镜、纯口播/混合口播识别、画面文案和资产清单。</p>
<form method="post" action="{{ route('settings.save') }}" class="form-stack">@csrf
<input type="hidden" name="kind" value="text">
<label>文本 API Base URL<input type="url" name="api_base" value="{{ old('kind')==='text'?old('api_base',$textApiBase):$textApiBase }}" required placeholder="https://api.example.com/v1"><span class="hint">兼容 /chat/completions，只填写 Base URL。</span></label>
<label>模型名称<input name="model" value="{{ old('kind')==='text'?old('model',$textModel):$textModel }}" maxlength="191" required placeholder="如 deepseek-chat"></label>
<label>文本 API Key<input name="api_key" type="password" autocomplete="new-password" placeholder="{{ $textHasKey?'已加密保存，留空保留':'输入 API Key' }}"><span class="hint">{{ $textHasKey?'✓ 已保存密钥':'尚未设置密钥' }}</span></label>
<button class="button primary wide" type="submit">保存文本模型</button></form></section>

<section class="panel settings-panel"><h2>02 / 图片模型</h2><p class="muted small">使用独立的图片 API，为普通角色、场景和道具生成真实图片文件。</p>
<form method="post" action="{{ route('settings.save') }}" class="form-stack">@csrf
<input type="hidden" name="kind" value="image">
<label>图片 API Base URL<input type="url" name="api_base" value="{{ old('kind')==='image'?old('api_base',$imageApiBase):$imageApiBase }}" required placeholder="https://api.example.com/v1"><span class="hint">使用 chatgpt2api 新版时填写以 /v1 结尾的 Base URL；同步兼容模式需支持 /images/generations。</span></label>
<label>模型名称<input name="model" value="{{ old('kind')==='image'?old('model',$imageModel):$imageModel }}" maxlength="191" required placeholder="如 gpt-image-1"></label>
<label>图片生成模式<select name="delivery">
<option value="chatgpt2api_tasks" @selected($imageDelivery==='chatgpt2api_tasks')>chatgpt2api 3.x · 异步任务（推荐，防 524、断线恢复）</option>
<option value="openai_sync" @selected($imageDelivery==='openai_sync')>通用 OpenAI 兼容 · 同步生成（传统模式）</option>
</select><span class="hint">异步模式使用 /api/image-tasks/generations 与 /api/image-tasks 查询；保存独立任务 ID，避免重复计费。普通图片模型不支持此协议时请选择同步。</span></label>
<label>图片 API Key<input name="api_key" type="password" autocomplete="new-password" placeholder="{{ $imageHasKey?'已加密保存，留空保留':'输入 API Key' }}"><span class="hint">{{ $imageHasKey?'✓ 已保存密钥':'尚未设置密钥' }}</span></label>
<button class="button primary wide" type="submit">保存图片模型</button></form></section>
</div>

<div class="section-heading space"><div><div class="eyebrow">PROMPT STUDIO</div><h2>03 / 提示词管理</h2></div><span class="muted small">4 类模板 · 参考和自定义均可修改</span></div>
<p class="muted">默认参考词依据开源工作流和服务商公开指南整理，并非厂商逐字官方模板。你可以编辑参考词、编辑自定义词，选择当前生效版本，或恢复内置参考默认值。</p>
<div class="model-settings-grid prompt-settings-grid">
@foreach($prompts as $kind=>$item)
<section class="panel prompt-settings-panel">
<div class="section-heading"><h2>{{ $item['label'] }}</h2><span class="chip">{{ $item['mode']==='custom'?'自定义生效':'参考版生效' }}</span></div>
<p class="muted small">来源：<a class="source-link" href="{{ $item['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ $item['source'] }}</a></p>
<form method="post" action="{{ route('settings.prompts.save') }}" class="form-stack">@csrf
<input type="hidden" name="kind" value="{{ $kind }}">
<label>使用哪份提示词？
<select name="mode">
<option value="reference" @selected($item['mode']==='reference')>公开指南参考版（可编辑）</option>
<option value="custom" @selected($item['mode']==='custom')>我的自定义版</option>
</select></label>
<label>参考版提示词<textarea name="reference" rows="8" maxlength="25000" required>{{ $item['reference'] }}</textarea><span class="hint">修改后保存，即替换此分类的参考版提示词。</span></label>
<label>自定义提示词<textarea name="custom" rows="8" maxlength="25000" placeholder="写入你自己的风格、分镜规范、镜头语言或素材约束…">{{ $item['custom'] }}</textarea><span class="hint">选择自定义模式后生效；空白时不能启用。</span></label>
<button class="button primary" type="submit">保存并应用此类提示词</button>
</form>
<form method="post" action="{{ route('settings.prompts.reset',$kind) }}" class="prompt-reset" onsubmit="return confirm('恢复内置参考模板？自定义内容会保留，但当前生效模式改回参考版。')">@csrf
<button class="button ghost" type="submit">恢复参考版默认内容</button>
</form>
</section>
@endforeach
</div>
<section class="panel"><p class="muted small">强制素材规则不受自定义提示词影响：真实 Logo、品牌产品、型号及包装必须上传客户素材，不能用 AI 猜测。图片模型和视频平台的接口能力各不相同；当前对可灵和 Seedance 的实现是提示词适配，不直接提交视频生成任务。</p></section>
@endsection
