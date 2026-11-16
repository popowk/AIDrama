@extends('layout')
@section('title','程序更新')
@section('content')
<div class="headline"><div><div class="eyebrow">SYSTEM UPDATES</div><h1>检查更新</h1><p class="muted">从 GitHub 的 AIDrama 正式 Release 获取更新，保留数据库、API 密钥与创作素材。</p></div></div>
<section class="panel settings-panel">
<h2>程序版本</h2>
<div class="stats"><div><strong id="current-version">{{ $current }}</strong><small>当前版本</small></div><div><strong id="latest-version">—</strong><small>最新版本</small></div><div><strong id="update-state">待检查</strong><small>更新状态</small></div></div>
<div class="button-row update-buttons">
<button class="button outline" type="button" id="check-update">检查更新</button>
<button class="button primary" type="button" id="apply-update" disabled>一键更新</button>
</div>
<p id="update-message" class="muted" role="status" aria-live="polite">点击检查更新以查看最新版本。</p>
<p class="hint">下载完整 ZIP → SHA256 校验 → 预备版本 → 数据库迁移 → 切换版本。不会通过脚本执行外部 shell 命令，也不依赖 symlink()。</p>
<p class="hint">更新前建议在宝塔备份数据库。新版本若包含数据库变更，一键更新时会自动执行迁移；如果迁移失败，不会切换活动程序，但 MySQL 可能已执行部分结构变更。</p>
</section>
<section class="panel settings-panel library-update-panel">
<div class="section-heading"><h2>风格库与技能库</h2><span class="chip">独立数据更新</span></div>
<p class="hint">与程序版本分别检测。更新只下载 GitHub 官方仓库中经过校验的 JSON 文字提示词，不执行插件代码；不会删除自定义模板或历史项目。</p>
@foreach(['styles'=>'视觉风格库','skills'=>'创作技能库'] as $kind=>$label)
<div class="library-update-row" data-library="{{ $kind }}">
    <div class="library-update-desc">
        <strong>{{ $label }}</strong>
        <p class="muted small">{{ $kind==='styles'?'材质、色彩、灯光、镜头语言等描述性风格提示词':'分镜时序、三视图、画面审查、镜头连续性等可组合创作规则' }}</p>
        <p class="muted small">
          当前：<span class="library-current">{{ $libraryCurrent[$kind]['version'] }}</span>
          · 最新：<span class="library-latest">待检查</span>
          · <span class="library-status">{{ $libraryCurrent[$kind]['count'] }} 项 · 待检查</span>
        </p>
    </div>
    <button type="button" class="button outline library-check" data-kind="{{ $kind }}">检查</button>
    <button type="button" class="button primary library-apply" data-kind="{{ $kind }}" disabled>更新</button>
</div>
@endforeach
<p id="library-message" class="hint" role="status" aria-live="polite">点击顶部「检查更新」会同时检查程序、风格库与技能库，也可以单独检查。</p>
<p class="hint">正在使用的项目会继续采用原有配置；更新库后，新发起的分镜或图片生成可使用更新后的规则。历史已生成图片不会被替换。</p>
</section>
<script>
const checkBtn=document.getElementById('check-update');
const applyBtn=document.getElementById('apply-update');
const label=document.getElementById('update-message');
const updateState=document.getElementById('update-state');
const csrf=document.querySelector('meta[name="csrf-token"]').content;
const endpointCheck=@json(route('updates.check'));
const endpointApply=@json(route('updates.apply'));
const endpointStatus=@json(route('updates.status'));
let versionAvailable=false,updating=false,pollTimer=null;
function setMessage(m){label.textContent=m;}
async function refreshStatus(){
 try{
  const response=await fetch(endpointStatus,{credentials:'same-origin',cache:'no-store'});
  const data=await response.json();
  if(!response.ok)return;
  const status=data.status||'idle';
  const labels={idle:'待检查',checking:'检查中',downloading:'下载中',verifying:'校验中',extracting:'解压中',migrating:'迁移中',activating:'切换中',done:'更新完成',failed:'更新失败'};
  updateState.textContent=labels[status]||status;
  if(updating&&data.message)setMessage(data.message);
  if(status==='done'&&updating){
   updating=false;applyBtn.disabled=true;checkBtn.disabled=false;
   if(pollTimer)clearInterval(pollTimer);
   setMessage((data.message||'更新完成')+'。页面即将刷新。');
   window.location.reload();
  }
  if(status==='failed'&&updating){
   updating=false;checkBtn.disabled=false;applyBtn.disabled=!versionAvailable;
   if(pollTimer)clearInterval(pollTimer);
   setMessage(data.message||'更新失败，请查看服务器日志。');
  }
 }catch(e){}
}
checkBtn.addEventListener('click',async()=>{
 checkBtn.disabled=true;setMessage('正在连接 GitHub 检查版本…');
 try{
  const res=await fetch(endpointCheck,{credentials:'same-origin',cache:'no-store'});
  const data=await res.json();
  if(!res.ok)throw new Error(data.error||'无法检查更新');
  document.getElementById('current-version').textContent=data.current;
  document.getElementById('latest-version').textContent=data.latest;
  versionAvailable=Boolean(data.available);
  applyBtn.disabled=!versionAvailable;
  applyBtn.textContent=data.migration_only?'补齐数据库结构':'一键更新';
  updateState.textContent=versionAvailable?(data.migration_only?'数据库待迁移':'可更新'):'最新版本';
  setMessage(data.migration_only
    ? '当前程序版本已是最新，但还有 '+Number(data.pending_migrations||0)+' 个数据库迁移未执行。点击补齐数据库结构。'
    : (versionAvailable?'检测到 '+data.latest+'，可以一键更新。':'当前已是最新正式版。'));
 }catch(e){setMessage('检查失败：'+e.message);versionAvailable=false;applyBtn.disabled=true;}
 finally{checkBtn.disabled=false;}
});
applyBtn.addEventListener('click',async()=>{
 if(updating||!versionAvailable)return;
 if(!confirm('即将安装 GitHub 最新正式版，并自动执行所需数据库迁移。建议先在宝塔备份 MySQL。确认继续？'))return;
 updating=true;applyBtn.disabled=true;checkBtn.disabled=true;
 setMessage('更新任务开始。下载和数据库迁移期间请保持页面打开，等待进度提示…');
 pollTimer=setInterval(refreshStatus,2500);
 try{
  const res=await fetch(endpointApply,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},credentials:'same-origin'});
  const data=await res.json();
  if(!res.ok)throw new Error(data.error||'更新失败');
  setMessage(data.message||'更新操作已完成');
  await refreshStatus();
 }catch(e){
  // The browser may time out while the PHP request continues; check persisted status.
  await refreshStatus();
  if(updating)setMessage('更新请求连接已中断，仍在检查服务器进度：'+e.message);
 }
});

const endpointLibraryCheck=@json(route('updates.libraries.check'));
const endpointLibraryApply=@json(route('updates.libraries.apply'));
const libraryNotice=document.getElementById('library-message');
const libraryRows={};
document.querySelectorAll('[data-library]').forEach(row=>{
  libraryRows[row.dataset.library]=row;
});
async function checkLibraryVersions(){
  for(const row of Object.values(libraryRows)){
    row.querySelector('.library-status').textContent='正在连接 GitHub…';
    row.querySelector('.library-apply').disabled=true;
    row.querySelector('.library-check').disabled=true;
  }
  try{
    const response=await fetch(endpointLibraryCheck,{
      credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'
    });
    const body=await response.json();
    if(!response.ok)throw new Error(body.error||'无法检查素材库更新');
    for(const kind of ['styles','skills']){
      const row=libraryRows[kind],info=body[kind];
      if(!row||!info)continue;
      row.querySelector('.library-current').textContent=info.current||'—';
      row.querySelector('.library-latest').textContent=info.latest||'查询失败';
      row.querySelector('.library-status').textContent=info.error||
        (info.available?'发现新版本':'已是最新版本');
      row.querySelector('.library-apply').disabled=!info.available;
    }
    libraryNotice.textContent='风格库和技能库版本检查完成，可单独选择更新。';
  }catch(e){
    libraryNotice.textContent='库版本检查失败：'+(e.message||String(e));
    for(const row of Object.values(libraryRows))
      row.querySelector('.library-status').textContent='暂时无法检查';
  }finally{
    for(const row of Object.values(libraryRows))
      row.querySelector('.library-check').disabled=false;
  }
}
checkBtn.addEventListener('click',()=>checkLibraryVersions());
for(const row of Object.values(libraryRows)){
  row.querySelector('.library-check').addEventListener('click',()=>checkLibraryVersions());
  row.querySelector('.library-apply').addEventListener('click',async event=>{
    const kind=event.currentTarget.dataset.kind;
    const label=kind==='styles'?'视觉风格库':'创作技能库';
    if(!confirm('将更新'+label+'的官方文字描述内容；不会覆盖你的自定义提示词或已有项目。确定继续？'))return;
    const apply=event.currentTarget;
    apply.disabled=true;
    row.querySelector('.library-status').textContent='正在更新…';
    libraryNotice.textContent='正在下载并验证'+label+'的独立数据版本…';
    try{
      const res=await fetch(endpointLibraryApply,{
        method:'POST',credentials:'same-origin',
        headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','Content-Type':'application/json'},
        body:JSON.stringify({kind})
      });
      const result=await res.json();
      if(!res.ok)throw new Error(result.error||'更新失败');
      row.querySelector('.library-current').textContent=result.version;
      row.querySelector('.library-latest').textContent=result.version;
      row.querySelector('.library-status').textContent='已是最新版本';
      libraryNotice.textContent=result.message||label+'已更新';
    }catch(e){
      row.querySelector('.library-status').textContent='更新失败';
      libraryNotice.textContent=label+'更新失败：'+(e.message||String(e));
    }
  });
}
refreshStatus();
</script>
@endsection
