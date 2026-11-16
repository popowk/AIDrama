<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title','AIDrama') · AIDrama</title>
<link rel="stylesheet" href="{{ asset('app.css') }}?v={{ rawurlencode(trim((string) @file_get_contents(base_path('VERSION')))) }}">
</head>
<body>
<header class="header"><a href="{{ route('home') }}" class="brand"><span class="brand-icon">▣</span><span>AIDrama<small>AI 影视创作工作台</small></span></a>
<nav class="nav"><a href="{{ route('home') }}">项目</a><a href="{{ route('settings') }}">模型设置</a><a href="{{ route('updates.index') }}">检查更新</a><form method="post" action="{{ route('logout') }}">@csrf<button class="link-button">退出</button></form></nav></header>
<div class="shell">
@if(session('ok')) <div class="notice success">{{ session('ok') }}</div> @endif
@if($errors->any()) <div class="notice danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div> @endif
@yield('content')
</div>
<footer class="footer">AIDrama · 单机版 v{{ trim(str_replace('v','',@file_get_contents(base_path('VERSION'))?:'0.1.0')) }} · 数据仅保存在你的服务器</footer>
</body></html>
