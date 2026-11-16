@extends('layout')
@section('title','模型和提示词设置')
@section('content')
<div class="headline"><div><div class="eyebrow">MODELS & CREATIVE PROMPTS</div><h1>模型与提示词设置</h1><p class="muted">文本与图片模型左右独立配置。提示词提供可编辑的公开参考规则，也可切换成完全自定义版本。</p></div></div>

<div class="model-settings-grid">
<section class="panel settings-panel"><h2>01 / 文本模型</h2><p class="muted small">用于分镜、纯口播/混合口播识别、画面文案和资产清单。</p>
<form method="post" action="{{ route('settings.save') }}" class="form-stack" data-model-kind="text" data-inline-settings="model">@csrf
<input type="hidden" name="kind" value="text">
<label>文本 API Base URL<input type="url" name="api_base" value="{{ old('kind')==='text'?old('api_base',$textApiBase):$textApiBase }}" required placeholder="https://api.example.com/v1"><span class="hint">兼容 /chat/completions，只填写 Base URL。</span></label>
<label>模型名称<input name="model" value="{{ old('kind')==='text'?old('model',$textModel):$textModel }}" maxlength="191" required placeholder="如 deepseek-chat"><span class="hint">可以通过下方按钮检测服务商提供的模型名称，选择后自动填入；也可直接填写。</span></label>
<div class="model-discovery-controls">
<button type="button" class="button outline model-detect"><span aria-hidden="true">⌕</span> 检测可用模型</button>
<select class="model-detected-list" aria-label="选择检测到的文本模型" hidden disabled></select>
<label class="model-show-all" hidden><input type="checkbox">显示全部模型</label>
</div>
<p class="model-discovery-state hint" role="status" aria-live="polite">检测使用当前 API 地址和密钥，不会修改已保存的设置。</p>
<label>文本 API Key<input name="api_key" type="password" autocomplete="new-password" placeholder="{{ $textHasKey?'已加密保存，留空保留':'输入 API Key' }}"><span class="hint">{{ $textHasKey?'✓ 已保存密钥':'尚未设置密钥' }}</span></label>
<button class="button primary wide" type="submit">保存文本模型</button></form></section>

<section class="panel settings-panel"><h2>02 / 图片模型</h2><p class="muted small">使用独立的图片 API，为普通角色、场景和道具生成真实图片文件。</p>
<form method="post" action="{{ route('settings.save') }}" class="form-stack" data-model-kind="image" data-inline-settings="model">@csrf
<input type="hidden" name="kind" value="image">
<label>图片 API Base URL<input type="url" name="api_base" value="{{ old('kind')==='image'?old('api_base',$imageApiBase):$imageApiBase }}" required placeholder="https://api.example.com/v1"><span class="hint">使用 chatgpt2api 新版时填写以 /v1 结尾的 Base URL；同步兼容模式需支持 /images/generations。</span></label>
<label>模型名称<input name="model" value="{{ old('kind')==='image'?old('model',$imageModel):$imageModel }}" maxlength="191" required placeholder="如 gpt-image-1"><span class="hint">先检测可用模型，再选择名称；其他兼容服务商也可手动填写。</span></label>
<div class="model-discovery-controls">
<button type="button" class="button outline model-detect"><span aria-hidden="true">⌕</span> 检测可用模型</button>
<select class="model-detected-list" aria-label="选择检测到的图片模型" hidden disabled></select>
<label class="model-show-all" hidden><input type="checkbox">显示全部模型</label>
</div>
<p class="model-discovery-state hint" role="status" aria-live="polite">模型列表不保证接口具备实际生图权限或余额；选择后请点击保存图片模型。</p>
<label>图片生成模式<select name="delivery">
<option value="chatgpt2api_tasks" @selected($imageDelivery==='chatgpt2api_tasks')>chatgpt2api 3.x · 异步任务（推荐，防 524、断线恢复）</option>
<option value="openai_sync" @selected($imageDelivery==='openai_sync')>通用 OpenAI 兼容 · 同步生成（传统模式）</option>
</select><span class="hint">异步模式使用 /api/image-tasks/generations 与 /api/image-tasks 查询；保存独立任务 ID，避免重复计费。普通图片模型不支持此协议时请选择同步。</span></label>
<label>图片 API Key<input name="api_key" type="password" autocomplete="new-password" placeholder="{{ $imageHasKey?'已加密保存，留空保留':'输入 API Key' }}"><span class="hint">{{ $imageHasKey?'✓ 已保存密钥':'尚未设置密钥' }}</span></label>
<button class="button primary wide" type="submit">保存图片模型</button></form></section>
</div>

<div class="section-heading space"><div><div class="eyebrow">PROMPT STUDIO</div><h2>03 / 提示词管理</h2></div><span class="muted small">6 类独立模板 · 按资产类别调用</span></div>
<p class="muted">图片提示词按“人物 / 场景 / 道具”分开设置：对应类型的资产自动调用对应模板。每类可以单独选择参考版或自定义版，彼此互不影响；单个资产也能再单独覆盖。旧通用图片模板保留历史数据但不再参与生图。</p>
<div class="model-settings-grid prompt-settings-grid">
@foreach($prompts as $kind=>$item)
<section class="panel prompt-settings-panel">
<div class="section-heading"><h2>{{ $item['label'] }}</h2><span class="chip">{{ $item['mode']==='custom'?'自定义生效':'参考版生效' }}</span></div>
<p class="muted small">来源：<a class="source-link" href="{{ $item['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ $item['source'] }}</a></p>
<form method="post" action="{{ route('settings.prompts.save') }}" class="form-stack" data-inline-settings="prompt">@csrf
<input type="hidden" name="kind" value="{{ $kind }}">
<label>使用哪份提示词？
<select name="mode">
<option value="reference" @selected($item['mode']==='reference')>公开指南参考版（可编辑）</option>
<option value="custom" @selected($item['mode']==='custom')>我的自定义版</option>
</select></label>
<label>参考版提示词<textarea name="reference" rows="8" maxlength="25000" required>{{ $item['reference'] }}</textarea><span class="hint">修改后保存，即替换此分类的参考版提示词。</span></label>
<label>自定义提示词<textarea name="custom" rows="8" maxlength="25000" placeholder="写入你自己的风格、分镜规范、镜头语言或素材约束…">{{ $item['custom'] }}</textarea><span class="hint">选择自定义模式后生效；可以留空，留空代表不附加这一类自定义提示词。</span></label>
<button class="button primary" type="submit">保存并应用此类提示词</button>
</form>
<form method="post" action="{{ route('settings.prompts.reset',$kind) }}" class="prompt-reset" data-inline-settings="reset">@csrf
<button class="button ghost" type="submit">恢复参考版默认内容</button>
</form>
</section>
@endforeach
</div>
<section class="panel"><p class="muted small">强制素材规则不受自定义提示词影响：真实 Logo、品牌产品、型号及包装必须上传客户素材，不能用 AI 猜测。图片模型和视频平台的接口能力各不相同；当前对可灵和 Seedance 的实现是提示词适配，不直接提交视频生成任务。</p></section>

<script>
const modelDetectionEndpoint=@json(route('settings.models.detect'));
const modelDetectionCsrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
document.querySelectorAll('form[data-model-kind]').forEach(form=>{
  const kind=form.dataset.modelKind;
  const button=form.querySelector('.model-detect');
  const field=form.querySelector('input[name="model"]');
  const apiBase=form.querySelector('input[name="api_base"]');
  const apiKey=form.querySelector('input[name="api_key"]');
  const select=form.querySelector('.model-detected-list');
  const allLabel=form.querySelector('.model-show-all');
  const allToggle=allLabel.querySelector('input');
  const status=form.querySelector('.model-discovery-state');
  let detected=[],relevant=[];
  function renderOptions(){
    const source=allToggle.checked?detected:relevant;
    select.replaceChildren();
    const placeholder=document.createElement('option');
    placeholder.value='';
    placeholder.textContent='请选择检测到的模型（共 '+source.length+' 个）';
    select.append(placeholder);
    for(const id of source){
      const option=document.createElement('option');
      option.value=id;
      option.textContent=id;
      select.append(option);
    }
    select.value=source.includes(field.value)?field.value:'';
    select.hidden=false;
    select.disabled=false;
  }
  function resetResults(){
    detected=[];relevant=[];
    select.replaceChildren();
    select.hidden=true;select.disabled=true;
    allLabel.hidden=true;allToggle.checked=false;
  }
  apiBase.addEventListener('input',resetResults);
  apiKey.addEventListener('input',resetResults);
  select.addEventListener('change',()=>{
    if(select.value){
      field.value=select.value;
      status.textContent='已选择 '+select.value+'，点击“保存'+(kind==='image'?'图片':'文本')+'模型”后生效。';
    }
  });
  allToggle.addEventListener('change',renderOptions);
  button.addEventListener('click',async()=>{
    const base=apiBase.value.trim();
    if(!base){
      status.textContent='请先填写 API Base URL。';return;
    }
    button.disabled=true;
    resetResults();
    status.textContent='正在向当前 API 查询 /models，请检查接口是否支持…';
    try{
      const response=await fetch(modelDetectionEndpoint,{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'X-CSRF-TOKEN':modelDetectionCsrf,'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({kind,api_base:base,api_key:apiKey.value})
      });
      const data=await response.json();
      if(!response.ok)throw new Error(data.error||data.message||'检测失败 HTTP '+response.status);
      detected=Array.isArray(data.all_models)?data.all_models:[];
      relevant=Array.isArray(data.models)?data.models:[];
      if(!relevant.length)throw new Error('接口没有返回可用模型名称，请手动填写');
      allLabel.hidden=detected.length<=relevant.length;
      renderOptions();
      status.textContent='检测成功：'+relevant.length+' 个优先候选、'+detected.length+' 个全部模型。'
        +'列表仅用于选取名称，不能证明模型具备当前接口能力。';
    }catch(error){
      resetResults();
      status.textContent='检测失败：'+(error?.message||String(error))+'。仍可手动填写模型名称。';
    }finally{
      button.disabled=false;
    }
  });
});

/* Keep model configuration and all six prompt templates editable in place. */
document.addEventListener('submit',async event=>{
  const form=event.target;
  if(!(form instanceof HTMLFormElement)||!form.dataset.inlineSettings)return;
  event.preventDefault();
  const kind=form.dataset.inlineSettings;
  if(kind==='reset'&&!confirm('恢复此分类内置参考模板？自定义内容会保留，但切回参考版。'))return;
  const button=form.querySelector('button[type="submit"]');
  if(button)button.disabled=true;
  let feedback=form.nextElementSibling;
  if(!feedback||!feedback.classList.contains('inline-save-feedback')){
    feedback=document.createElement('p');
    feedback.className='inline-save-feedback';
    feedback.setAttribute('role','status');
    feedback.setAttribute('aria-live','polite');
    form.insertAdjacentElement('afterend',feedback);
  }
  feedback.textContent='正在保存…';
  feedback.classList.remove('is-error','is-success');
  try{
    const response=await fetch(form.action,{
      method:'POST',body:new FormData(form),
      credentials:'same-origin',redirect:'follow',cache:'no-store',
      headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
    });
    const type=(response.headers.get('content-type')||'').toLowerCase();
    if(response.redirected||!type.includes('json'))
      throw new Error('登录状态失效或接口没有返回 JSON（HTTP '+response.status+'）');
    const data=await response.json();
    if(!response.ok||data.ok===false){
      const detail=Object.values(data.errors||{}).flat().join('；');
      throw new Error(detail||data.message||'保存失败（HTTP '+response.status+'）');
    }
    if(kind==='model'){
      const field=form.querySelector('input[name="api_key"]');
      if(field&&field.value.trim()){
        field.value='';
        const hint=field.parentElement?.querySelector('.hint');
        if(hint)hint.textContent='✓ 密钥已加密保存';
      }
    }else if(kind==='prompt'){
      const badge=form.closest('.prompt-settings-panel')?.querySelector('.chip');
      if(badge)badge.textContent=data.mode==='custom'?'自定义生效':'参考版生效';
    }else if(kind==='reset'){
      const panel=form.closest('.prompt-settings-panel');
      const editor=panel?.querySelector('form[data-inline-settings="prompt"]');
      const mode=editor?.querySelector('select[name="mode"]');
      const reference=editor?.querySelector('textarea[name="reference"]');
      if(mode)mode.value='reference';
      if(reference)reference.value=String(data.reference??'');
      const badge=panel?.querySelector('.chip');
      if(badge)badge.textContent='参考版生效';
    }
    feedback.textContent='✓ '+(data.message||'已保存');
    feedback.classList.add('is-success');
  }catch(err){
    feedback.textContent='✕ '+(err?.message||String(err));
    feedback.classList.add('is-error');
  }finally{if(button)button.disabled=false;}
});
</script>
@endsection
