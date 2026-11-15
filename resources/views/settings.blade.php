@extends('layout')
@section('title','模型设置')
@section('content')
<div class="headline"><div><div class="eyebrow">MODEL CONNECTION</div><h1>个人模型 API</h1><p class="muted">使用你自己的 OpenAI 兼容文本接口，密钥在服务器端加密保存。</p></div></div>
<section class="panel settings-panel"><h2>文本分析模型</h2><form method="post" action="{{ route('settings.save') }}" class="form-stack">@csrf
<label>API Base URL<input type="url" name="api_base" value="{{ old('api_base',$apiBase) }}" required placeholder="https://api.example.com/v1"><span class="hint">仅允许公网 HTTPS 域名，例如 /v1 或 /compatible-mode/v1；不要填写 chat/completions。</span></label>
<label>模型名称<input name="model" value="{{ old('model',$model) }}" maxlength="191" required placeholder="例如：deepseek-chat"></label>
<label>API Key<input name="api_key" type="password" value="" autocomplete="new-password" placeholder="{{ $hasKey?'已保存（留空则保留原密钥）':'输入你的 API Key' }}"><span class="hint">{{ $hasKey?'✅ 已配置密钥。出于安全考虑不回显原值。':'尚未配置密钥。' }}</span></label>
<button class="button primary" type="submit">保存设置</button></form>
<hr><p class="muted small">目前通过 OpenAI 兼容 <code>/chat/completions</code> 接口生成分镜和资产列表；图片、视频、配音等模型将在后续加入独立配置。请使用可信服务商。</p>
</section>
@endsection
