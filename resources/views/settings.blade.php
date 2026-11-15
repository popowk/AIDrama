@extends('layout')
@section('title','模型设置')
@section('content')
<div class="headline"><div><div class="eyebrow">MODEL CONNECTIONS</div><h1>模型配置</h1><p class="muted">文本分析模型与图片生成模型单独配置。API Key 加密保存于服务器，留空则继续使用已保存密钥。</p></div></div>
<div class="model-settings-grid">
<section class="panel settings-panel"><h2>01 / 文本模型</h2><p class="muted">用于理解剧本、拆分镜头、生成角色、场景和道具的文字资产。</p>
<form method="post" action="{{ route('settings.save') }}" class="form-stack">@csrf
<input type="hidden" name="kind" value="text">
<label>文本 API Base URL<input type="url" name="api_base" value="{{ old('kind')==='text'?old('api_base',$textApiBase):$textApiBase }}" required placeholder="https://api.example.com/v1"><span class="hint">兼容 OpenAI /chat/completions 接口，只填 API Base URL。</span></label>
<label>文本模型名称<input name="model" value="{{ old('kind')==='text'?old('model',$textModel):$textModel }}" maxlength="191" required placeholder="例如 deepseek-chat"></label>
<label>文本 API Key<input type="password" name="api_key" autocomplete="new-password" placeholder="{{ $textHasKey?'已保存，留空保留':'输入 API Key' }}"><span class="hint">{{ $textHasKey?'✓ 已保存文本密钥':'尚未设置文本密钥' }}</span></label>
<button class="button primary" type="submit">保存文本模型</button>
</form></section>
<section class="panel settings-panel"><h2>02 / 图片模型</h2><p class="muted">独立保存图片模型的接口、名称和密钥，为角色三视图、场景图及道具图生成准备。</p>
<form method="post" action="{{ route('settings.save') }}" class="form-stack">@csrf
<input type="hidden" name="kind" value="image">
<label>图片 API Base URL<input type="url" name="api_base" value="{{ old('kind')==='image'?old('api_base',$imageApiBase):$imageApiBase }}" required placeholder="https://api.example.com/v1"><span class="hint">服务商 API 根地址；与文本模型可以使用不同服务商。</span></label>
<label>图片模型名称<input name="model" value="{{ old('kind')==='image'?old('model',$imageModel):$imageModel }}" maxlength="191" required placeholder="例如 gpt-image-1"></label>
<label>图片 API Key<input type="password" name="api_key" autocomplete="new-password" placeholder="{{ $imageHasKey?'已保存，留空保留':'输入 API Key' }}"><span class="hint">{{ $imageHasKey?'✓ 已保存图片密钥':'尚未设置图片密钥' }}</span></label>
<button class="button outline" type="submit">保存图片模型</button>
</form></section></div>
<section class="panel"><p class="muted small">目前分镜生成由文本模型完成。图片模型已支持独立配置和密钥保存，但真实图片生成接口尚未接入；不会假装已经生成图片。</p></section>
@endsection
