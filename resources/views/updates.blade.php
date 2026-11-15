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
  updateState.textContent=versionAvailable?'可更新':'最新版本';
  setMessage(versionAvailable?'检测到 '+data.latest+'，可以一键安装。':'当前已是最新正式版。');
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
refreshStatus();
</script>
@endsection
