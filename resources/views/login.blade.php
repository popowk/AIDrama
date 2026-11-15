<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>登录 · AIDrama</title><link rel="stylesheet" href="{{ asset('app.css') }}"></head><body class="login-body">
<main class="login-card">
<div class="mark">▣</div><h1>AIDrama</h1><p class="muted">AI 影视创作工作台 · 单机版</p>
@if($errors->any())<div class="notice danger">{{ $errors->first() }}</div>@endif
@if(!$configured)<div class="notice danger">尚未初始化管理员密码。请在服务器项目目录运行：<code>php artisan aidrama:set-password</code></div>@endif
<form method="post" action="{{ route('login.submit') }}">@csrf<label>管理员密码<input type="password" name="password" required autocomplete="current-password" placeholder="输入管理员密码"></label><button type="submit" class="button primary wide">进入工作台</button></form>
<p class="muted small">密钥、剧本和资产只保存在本机服务器。请勿公开开放访问。</p>
</main>
</body></html>
