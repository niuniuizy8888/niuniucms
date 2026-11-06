/* Attachment management: bounded work, durable progress, explicit retention. */
document.addEventListener('DOMContentLoaded', function () {
 'use strict';
 const $ = id => document.getElementById(id), boot = JSON.parse($('attachmentBoot').textContent);
 const modalElement = $('syncModal'), modal = new bootstrap.Modal(modalElement);
 const referenceElement=$('referenceModal'),referenceModal=new bootstrap.Modal(referenceElement);let referenceId=0;
 const form = $('syncForm'); let data = boot.data, page = 1, step = 1, current = null, visible = false, ticking = false, timer = null, selected = [], indexOnly = false, creating = false;
 let requestSequence=0, tokenSequence=0, requestedJobId=0;
 let watchTimer=null, elapsedTimer=null, watching=false, transportStopped=false, authStopped=false, detailPage=1, detailFilter='', statusReceivedAt=0;
 let detailQuery={cursor:null,direction:'next'}, detailBounds={}, detailLoading=false, detailAgain=false;
 const restoreKey='nncms_attachment_job:'+location.pathname;
 const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const labels = {local:'本地',ftp:'FTP',external:'外链',running:'处理中',paused:'已暂停',finished:'已完成',stopped:'已停止'};
 const targetLabels = {local:'同步到本地',ftp:'同步到 FTP',index:'扫描附件'};
 const safeUrl = value => /^(?:https?:\/\/|\/(?!\/))/i.test(value) ? value : '';
 function reloadWithProgress(){const id=requestedJobId || (current&&current.id);if(id)sessionStorage.setItem(restoreKey,String(id));location.reload();}
 function notice(message, error, auth) {
  $('attNotice').hidden=false; $('attNotice').textContent=message; $('attNotice').classList.toggle('error',!!error);
  if(auth){const button=document.createElement('button');button.className='btn btn-sm btn-primary ms-3';button.textContent='刷新页面并恢复进度';button.onclick=reloadWithProgress;$('attNotice').append(button);}
 }
 function requestError(message,auth,refresh){const error=Error(message);error.auth=!!auth;error.refresh=!!refresh;return error;}
 function needsRefresh(error){return error.auth||error.refresh;}
 async function request(op, values) {
  const sequence=++requestSequence;
  const params = values instanceof FormData ? values : new FormData();
  if (!(values instanceof FormData)) Object.entries(values || {}).forEach(([key,value]) => Array.isArray(value) ? value.forEach(v=>params.append(key+'[]',v)) : params.set(key,value));
  params.set('op',op); params.set('safe_token',boot.token);params.set('ui_protocol','3');params.set('ui_revision',boot.revision||'');
  const controller=new AbortController(), timeout=setTimeout(()=>controller.abort(),op==='step'?120000:20000);
  let response;
  try { response = await fetch(location.href,{method:'POST',body:params,credentials:'same-origin',signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}}); }
  catch(e) { throw requestError(e.name==='AbortError'?'连接等待超时。已完成结果会保留；请查看当前处理结果后继续。':'连接中断。已完成结果会保留，可查看进度后继续。',false); }
  finally { clearTimeout(timeout); }
  const token=response.headers.get('X-NNCMS-CSRF-Token');
  // A slow earlier request must not overwrite a newer token from a progress/control request.
  if(token && sequence>tokenSequence){boot.token=token;tokenSequence=sequence;}
  if(!response.ok) throw requestError([401,403].includes(response.status)?'登录状态失效，请刷新后重试':'服务器响应异常（HTTP '+response.status+'）。请查看已保存的结果后继续。',[401,403].includes(response.status));
  let json;const bodyTimeout=setTimeout(()=>controller.abort(),20000);
  try { json=await response.json(); } catch(e) { throw requestError(e.name==='AbortError'?'服务器结果接收超时，请查看已保存的进度后继续。':'会话已失效或服务器响应异常，请刷新页面后重试',e.name!=='AbortError'); } finally {clearTimeout(bodyTimeout);}
  if(json.code!==0){const message=json.message || json.msg || '操作失败';throw requestError(message,/令牌|会话|登录/.test(message),json.refresh);}
  return json.data;
 }
 async function busy(button, action, output) {
  button.disabled=true;
  try { await action(); } catch(e) { if(output) {output.textContent=e.message;} if(!output||needsRefresh(e))notice(e.message,true,needsRefresh(e)); }
  finally {button.disabled=false;}
 }
 function tab(name) {
  document.querySelectorAll('[data-panel]').forEach(el=>el.hidden=el.dataset.panel!==name);
  document.querySelectorAll('[data-tab]').forEach(el=>el.classList.toggle('active',el.dataset.tab===name));
 }
 document.querySelectorAll('[data-tab]').forEach(el=>el.addEventListener('click',()=>tab(el.dataset.tab)));
 function render() {
  $('assetCount').textContent='共 '+data.total+' 张图片'; $('pageLabel').textContent='第 '+page+' 页'; $('prevPage').disabled=page<=1; $('nextPage').disabled=page*24>=data.total;
  $('selectAllAssets').checked=false;
  $('assetGrid').innerHTML=data.assets.length ? data.assets.map(a=>{
   const refs=data.references.filter(r=>Number(r.asset_id)===Number(a.id)), ref=refs[0];
   const state=a.backend==='external'?'尚未下载':a.backend==='local'?(a.local_exists?'已下载到本地':'本地文件缺失，可重新同步补下载'):(a.local_exists?'已上传 FTP，本地副本已保留':'已上传 FTP，未保留本地副本');
   const source=a.backend!=='external'&&a.source_url&&a.source_url!==a.url?'<details class="att-source-details"><summary>原始地址已保留'+(a.source_count>1?'（'+a.source_count+' 个来源）':'')+'</summary><span class="att-detail-url">'+esc(a.source_url)+'</span></details>':'';
   return '<article class="att-asset"><div class="att-asset-preview"><img loading="lazy" src="'+esc(safeUrl(a.display_url))+'" alt="图片预览"><input type="checkbox" class="asset-select" value="'+a.id+'" aria-label="选择图片 '+a.id+'"></div><div class="att-asset-body"><span class="att-badge">'+labels[a.backend]+'</span><span class="small text-muted float-end">'+(a.bytes ? (a.bytes/1024).toFixed(1)+' KB' : '')+'</span><div class="att-asset-title">'+(ref?esc(ref.title)+' <small class="text-muted">'+(ref.reference_count>1?'等 '+ref.reference_count+' 条内容':'')+'</small>':'尚未扫描到关联内容')+'</div><span class="att-url" title="'+esc(a.url)+'">'+esc(a.url)+'</span><div class="small text-muted mb-2">'+state+'</div>'+source+'<div class="d-flex gap-2 flex-wrap"><button class="btn btn-sm btn-light" data-copy="'+a.id+'">复制地址</button><button class="btn btn-sm btn-light" data-references="'+a.id+'">查看引用</button><button class="btn btn-sm btn-outline-primary" data-redownload="'+a.id+'">重新下载</button><button class="btn btn-sm btn-light" data-delete="'+a.id+'">删除图片</button></div></div></article>';
  }).join(''):'<div class="att-empty"><h3>还没有符合条件的图片记录</h3><p>点击「扫描已有图片」整理已有外链和本地图片，或直接开始一键同步。</p></div>';
  $('taskList').innerHTML=data.jobs.length ? data.jobs.map(j=>'<article class="att-task"><div><strong>#'+j.id+' · '+targetLabels[j.options.target]+'</strong> <span class="att-badge">'+(j.state==='running' && j.options.mode==='page'?'等待本页继续':labels[j.state])+'</span><small>已检查 '+j.scanned+' 条内容 · 已就绪 '+(j.counts.reused||0)+' · 本次成功 '+j.counts.done+' · 失败 '+j.counts.failed+' · '+(j.state==='stopped'?'未处理':'待处理')+' '+j.counts.pending+'</small></div><button class="btn btn-outline-primary" data-job="'+j.id+'">查看进度</button></article>').join(''):'<div class="att-empty"><h3>暂无同步任务</h3><p>从“一键同步图片”开始，执行记录会保存在这里。</p></div>';
 }
 async function refresh() { data=await request('overview',{page,backend:$('backendFilter').value,q:$('assetSearch').value}); render(); }
 $('searchAssets').onclick=()=>busy($('searchAssets'),async()=>{page=1;await refresh();});
 $('refreshTasks').onclick=()=>busy($('refreshTasks'),refresh);
 $('prevPage').onclick=()=>busy($('prevPage'),async()=>{page--;await refresh();});
 $('nextPage').onclick=()=>busy($('nextPage'),async()=>{page++;await refresh();});
 $('selectAllAssets').onchange=()=>document.querySelectorAll('.asset-select').forEach(el=>el.checked=$('selectAllAssets').checked);
 $('selectNone').onclick=()=>{document.querySelectorAll('.asset-select').forEach(el=>el.checked=false);$('selectAllAssets').checked=false;};
 async function copy(text,button) {
  try {await navigator.clipboard.writeText(text);} catch(e) {const t=document.createElement('textarea');t.value=text;document.body.append(t);t.select();const ok=document.execCommand('copy');t.remove();if(!ok)throw Error('复制失败，请手动全选命令后复制');}
  const old=button.textContent; button.textContent='已复制完整内容';setTimeout(()=>button.textContent=old,2000);
 }
 $('assetGrid').onclick=async e=>{
  const b=e.target.closest('button'); if(!b)return;
  if(b.dataset.copy) return busy(b,()=>copy(data.assets.find(a=>Number(a.id)===Number(b.dataset.copy)).display_url,b));
  if(b.dataset.redownload) return openWizard('local',[b.dataset.redownload],false,true);
  if(b.dataset.references||b.dataset.delete) return busy(b,async()=>{const refs=await request('references',{id:b.dataset.references||b.dataset.delete});referenceId=refs.asset_id;$('referenceMessage').textContent=refs.message;$('referenceError').hidden=true;$('referenceMore').hidden=!refs.has_more;$('confirmDeleteAsset').hidden=refs.blocked||!b.dataset.delete;$('referenceList').innerHTML=refs.rows.length?refs.rows.map(r=>'<article class="att-detail-item"><strong>'+esc(r.label)+' #'+r.id+' · '+esc(r.title||'未命名内容')+'</strong><div class="d-flex justify-content-between gap-2 mt-2"><span>'+esc(r.fields)+(r.kind==='recycle'?' · 原内容 #'+r.target_id:'')+'</span><a href="'+esc(r.url)+'" target="_blank" rel="noopener">打开所在页面</a></div></article>').join(''):'<div class="att-detail-empty">没有内容引用这张图片</div>';referenceModal.show();});
 };
 $('referenceRedownload').onclick=()=>{const id=String(referenceId);referenceElement.addEventListener('hidden.bs.modal',()=>openWizard('local',[id],false,true),{once:true});referenceModal.hide();};
 $('confirmDeleteAsset').onclick=()=>busy($('confirmDeleteAsset'),async()=>{try{const result=await request('delete',{id:referenceId});referenceModal.hide();await refresh();notice(result.message);}catch(e){$('referenceError').textContent=e.message;$('referenceError').hidden=false;if(needsRefresh(e))notice(e.message,true,true);}});
 function syncValues() {const p=new FormData(form);selected.forEach(id=>p.append('assets[]',id));if(indexOnly)p.set('target','index');return p;}
 function showStep() {
  document.querySelectorAll('[data-step]').forEach(el=>el.hidden=Number(el.dataset.step)!==step);
  document.querySelectorAll('.att-steps span').forEach((el,i)=>el.classList.toggle('active',i+1===step));
  $('wizardBack').hidden=step===1 || (selected.length>0&&step===2); $('wizardNext').textContent=step===3?'确认并开始':'下一步';
  $('syncTitle').textContent=indexOnly?'扫描已有图片':(['','选择需要处理的图片','选择存储方式','确认同步范围'][step]);
 }
 function openWizard(target, ids, scan, redownload) {
  current=null;requestedJobId=0;transportStopped=false;authStopped=false;resetDetails();clearTimeout(watchTimer);selected=ids||[];indexOnly=!!scan;form.reset();step=selected.length?2:1;
  form.elements.target.value=target||'local';form.elements.delete_local.value=boot.keep_local?'0':'1';
  $('redownloadImages').checked=!!redownload;
  $('retentionOptions').hidden=target!=='ftp';$('wizardError').hidden=true;$('syncForm').hidden=false;$('wizardSteps').hidden=false;$('syncProgress').hidden=true;$('wizardNext').hidden=false;
  ['pauseJob','resumeJob','stopJob','retryJob','refreshSession'].forEach(id=>$(id).hidden=true);
  $('progressNotice').textContent=''; $('selectionHint').textContent='只处理勾选的类型和范围。';
  document.querySelector('#wizardSteps span').textContent=selected.length?'1 已选 '+selected.length+' 张':'1 选择范围';
  $('selectedImages').hidden=!selected.length;
  const typeNames={'vod.pic':'视频封面','vod.pic_slide':'横版海报','art.pic':'文章封面','art.content':'文章正文图片'};
  $('selectedImages').innerHTML=selected.length?'<strong>已选择 '+selected.length+' 张图片</strong><p class="small mb-2">自动核对当前内容引用，无需手动扫描或重新选择分类。</p>'+data.assets.filter(a=>selected.includes(String(a.id))).map(a=>{const r=data.references.find(ref=>Number(ref.asset_id)===Number(a.id));const types=r?(r.types||'').split(',').map(t=>typeNames[t]||'图片').join('、'):'图片';return '<div class="att-selected-item"><img src="'+esc(safeUrl(a.display_url))+'" alt="所选图片"><div><strong>'+esc(r?r.title:'附件 #'+a.id)+'</strong><small>'+esc(types)+' · '+labels[a.backend]+'</small></div></div>';}).join(''):'';
  if(scan)form.querySelectorAll('[name="fields[]"]').forEach(el=>el.checked=true);
  showStep();modal.show();
 }
 $('syncOpen').onclick=()=>openWizard();$('indexImages').onclick=()=>openWizard('local',[],true);
 $('syncSelected').onclick=()=>{const ids=Array.from(document.querySelectorAll('.asset-select:checked'),el=>el.value);if(!ids.length)return notice('请先勾选图片');openWizard('local',ids);};
 $('migrateFtp').onclick=()=>openWizard('ftp');
 $('allFields').onclick=()=>form.querySelectorAll('[name="fields[]"]').forEach(el=>el.checked=true);
 $('noFields').onclick=()=>form.querySelectorAll('[name="fields[]"]').forEach(el=>el.checked=false);
 form.querySelectorAll('[name="target"]').forEach(el=>el.onchange=()=>$('retentionOptions').hidden=form.elements.target.value!=='ftp');
 $('wizardBack').onclick=()=>{step=indexOnly?1:step-1;showStep();};
 $('wizardNext').onclick=()=>busy($('wizardNext'),async()=>{
  $('wizardError').hidden=true;
  try {
   if(step===1 && !form.querySelector('[name="fields[]"]:checked'))throw Error('请至少选择一种图片');
   if(step===3){if(creating)return;creating=true;try{current=await request('create',syncValues());showJob(current);schedule(100);}finally{creating=false;}return;}
   if(step===2 || indexOnly){const p=await request('preview',syncValues());$('syncConfirm').textContent=p.message+'。处理方式：'+targetLabels[p.options.target]+(p.options.target==='ftp'?'；'+(p.options.keep_local?'保留本地图片':'成功后删除本地副本'):'')+'。'+(p.options.redownload?'已选择从原始地址重新下载，失败保留原图。':'')+(selected.length?'仅处理选中的 '+selected.length+' 张图片。':'');step=3;}else step++;
   showStep();
  } catch(e){$('wizardError').textContent=e.message;$('wizardError').hidden=false;if(needsRefresh(e))notice(e.message,true,true);}
 });
 function quote(s){return '"'+s.replace(/[\\"$`]/g,'\\$&')+'"';}
 function resetDetails(){detailPage=1;detailFilter='';detailQuery={cursor:null,direction:'next'};detailBounds={};$('detailState').value='';}
 function renderDetails(detail) {
  detailPage=detail.page;detailBounds=detail;
  const stateLabels={done:detail.target==='index'?'扫描成功':'同步成功',reused:'已就绪 · 未重复下载',failed:'失败',pending:'待处理',skipped:'已跳过'};
  $('progressDetails').innerHTML=detail.rows.length?detail.rows.map(r=>{
   const finished=Number(r.finished_at)>0?new Date(Number(r.finished_at)*1000).toLocaleTimeString('zh-CN',{hour12:false}):'';
   const duration=Number(r.finished_at)>0?(Number(r.elapsed_ms)/1000).toFixed(1)+' 秒':'未记录耗时';
   const record=(r.kind==='vod'?'视频 VID ':r.kind==='art'?'文章 AID ':'附件 #')+r.record_id;
   const result=r.result_url&&['done','reused'].includes(r.state)?'<div class="att-detail-url">'+(detail.target==='index'?'图片地址：':'保存位置：')+esc(r.result_url)+(Number(r.bytes)>0?' · '+(r.bytes/1024).toFixed(1)+' KB':'')+'</div>':'';
   return '<article class="att-detail-item" data-item="'+r.id+'"><div class="att-detail-head"><span class="att-detail-status '+esc(r.state)+'">'+esc(stateLabels[r.state]||'状态待刷新')+'</span><strong>'+esc(r.title||record)+'</strong><span class="att-detail-time">'+(r.state==='pending'?'等待处理':esc(finished+' · '+duration))+'</span></div><div class="att-detail-url">'+esc(record)+' · 来源：'+esc(r.source)+'</div>'+result+(r.error?'<div class="'+(r.state==='failed'?'att-detail-error':'att-detail-note')+'">'+esc(r.error)+'</div>':'')+(r.state==='failed'?'<button type="button" class="btn btn-sm btn-outline-primary mt-2" data-retry-item="'+r.id+'">重试此图</button>':'')+'</article>';
  }).join(''):'<div class="att-detail-empty">'+(current&&Number(current.phase)<2?'正在整理图片，处理明细会在这里显示':'没有符合条件的处理记录')+'</div>';
  $('detailPage').textContent='第 '+detail.page+' / '+Math.max(1,Math.ceil(detail.total/20))+' 页 · 共 '+detail.total+' 条';
  $('detailPrev').disabled=!detail.has_prev;$('detailNext').disabled=!detail.has_next;
 }
 async function refreshDetails() {
  if(!current)return;if(detailLoading){detailAgain=true;return;}detailLoading=true;
  const id=current.id,state=detailFilter,p=detailPage,query={...detailQuery};
  try{const detail=await request('details',{id,page:p,state,cursor:query.cursor===null?'':query.cursor,direction:query.direction});
   if(current&&current.id===id&&detailFilter===state&&detailPage===p&&detailQuery.cursor===query.cursor)renderDetails(detail);
  }finally{detailLoading=false;if(detailAgain){detailAgain=false;if(visible&&current)await refreshDetails();}}
 }
 async function detailAction(button){await busy(button,refreshDetails);$('detailPrev').disabled=!detailBounds.has_prev;$('detailNext').disabled=!detailBounds.has_next;}
 $('detailState').onchange=()=>{detailFilter=$('detailState').value;detailPage=1;detailQuery={cursor:null,direction:'next'};detailAction($('detailState'));};
 $('detailLatest').onclick=()=>{detailPage=1;detailQuery={cursor:null,direction:'next'};detailAction($('detailLatest'));};
 $('detailPrev').onclick=()=>{if(!detailBounds.has_prev)return;detailPage=Math.max(1,detailPage-1);detailQuery={cursor:detailBounds.prev_cursor,direction:'prev'};detailAction($('detailPrev'));};
 $('detailNext').onclick=()=>{if(!detailBounds.has_next)return;detailPage++;detailQuery={cursor:detailBounds.next_cursor,direction:'next'};detailAction($('detailNext'));};
 function renderElapsed() {
  const item=current&&current.current;
  $('currentElapsed').textContent=item?'已等待 '+Math.max(0,Math.floor(Number(current.server_time)+(Date.now()-statusReceivedAt)/1000-Number(item.started_at)))+' 秒':'';
 }
 function showJob(j) {
  if(!current||Number(current.id)!==Number(j.id)){resetDetails();transportStopped=false;authStopped=false;}
  current=j;requestedJobId=j.id;$('refreshSession').hidden=!authStopped;$('selectedImages').hidden=true;$('syncForm').hidden=true;$('wizardSteps').hidden=true;$('syncProgress').hidden=false;$('wizardBack').hidden=true;$('wizardNext').hidden=true;$('wizardError').hidden=true;
  statusReceivedAt=Date.now();
  $('syncTitle').textContent=targetLabels[j.options.target]+' · #'+j.id;
  const scanning=Number(j.phase)<2, done=j.counts.done+j.counts.failed+j.counts.skipped, reused=j.counts.reused||0, work=j.work_total===undefined?j.total-reused:j.work_total;
  const savedSelection=!!(j.options.assets&&j.options.assets.length);
  $('progressState').textContent=j.state==='running'?(transportStopped?(j.current?'等待服务器保存当前结果':'请求已暂停，可从保存进度继续'):scanning?(savedSelection?'正在核对当前图片引用':'正在扫描图片'):'正在同步图片'):labels[j.state]+(j.counts.failed?'，有图片失败':'');
  $('progressCounts').textContent=scanning?(savedSelection?'已找到 '+j.scanned+' 条关联 · 分批核对中':'已检查 '+j.scanned+' / '+j.options.records+' 条内容'):work===0&&reused?'本次无需下载 · 已就绪 '+reused+' 张':'本次处理 '+done+' / '+work+' 张'+(reused?' · 已就绪 '+reused+' 张':'');
  $('progressBar').style.width=(scanning?(savedSelection?Number(j.phase)*50:Math.min(100,j.scanned/Math.max(1,j.options.records)*100)):(work?done/work*100:100))+'%';
  $('executionHint').textContent=j.options.mode==='page'?'逐张处理并保存结果。来源下载超时为 20 秒，失败后继续下一张；FTP 上传按存储设置的超时执行。关闭窗口后暂停发送下一张，可从已保存进度继续。':'后台任务已登记，处理结果会自动刷新。已有执行程序会继续处理；否则请执行下面的宝塔命令。';
  if(j.options.redownload)$('executionHint').textContent+=' 本任务从原始地址重新下载；失败保留原图，共用同一来源的内容复用本任务的下载结果。';
  if(!j.options.work_version&&j.options.target==='local')$('executionHint').textContent+=' 这是旧任务记录，成功数可能包含本地复用；继续时会分批复查待处理项，已有本地文件直接标为已就绪。';
  if(j.state==='finished')$('executionHint').textContent='全部 '+j.total+' 条记录已处理：本次成功 '+j.counts.done+'、复用已有文件 '+reused+'、失败 '+j.counts.failed+'、跳过 '+j.counts.skipped+'。明细翻页只用于查看记录，不影响任务执行。';
  $('progressNotice').textContent='已就绪 '+reused+' · 本次成功 '+j.counts.done+' · 失败 '+j.counts.failed;
  $('resultCounts').innerHTML=[['reused','已就绪'],['done','本次成功'],['failed','失败'],['skipped','跳过'],['pending',j.state==='stopped'?'未处理':'待处理']].map(([key,label])=>'<div class="'+key+'">'+label+'<b>'+(j.counts[key]||0)+'</b></div>').join('');
  const active=j.current, upcoming=j.next, item=active||upcoming;
  $('currentImage').hidden=!item||!['running','paused'].includes(j.state);
  if(item){$('currentTitle').textContent=(active?'正在处理：':'下一张：')+item.title;$('currentSource').textContent=item.source;$('currentHint').textContent=active?'正在等待图片响应，完成后会显示成功或具体失败原因。':j.state==='paused'||transportStopped?'已保留进度，继续后从这里处理。':'等待下一次请求，已完成的图片不会重复下载。';}
  renderElapsed();
  $('progressErrors').textContent=j.counts.failed?'有 '+j.counts.failed+' 张图片失败；原链接已保留，可筛选“失败”查看原因并重试。':'';
  $('pauseJob').hidden=j.state!=='running';$('resumeJob').hidden=j.state!=='paused'&&!(transportStopped&&j.state==='running');$('resumeJob').disabled=!!(j.busy&&j.current);$('stopJob').hidden=!['running','paused'].includes(j.state);$('retryJob').hidden=!j.counts.failed || !['finished','paused'].includes(j.state);
  if(authStopped){$('progressState').textContent='登录校验需要刷新，任务进度已保留';$('resumeJob').hidden=true;}
  if(j.details&&detailPage===1&&detailFilter===''){detailQuery={cursor:null,direction:'next'};renderDetails(j.details);}
  const url=new URL('api.php',location.href);url.search='?route=images&job='+j.id;
  $('imageCommand').value='curl -fsSL --connect-timeout 15 --max-time 3600 --max-redirs 10000 -H '+quote('X-NNCMS-Token: '+boot.api_token)+' '+quote(url.href.replace(/'/g,'%27'));
  $('commandDetails').hidden=j.options.mode!=='background';$('commandDetails').open=j.options.mode==='background';
  startWatch();
 }
 function startWatch(){clearTimeout(watchTimer);if(visible&&current&&current.state==='running'&&!authStopped)watchTimer=setTimeout(watch,1500);}
 async function watch(){if(watching||!visible||!current)return;watching=true;const id=current.id;
  try{const j=await request('status',{id});if(visible&&current&&current.id===id){showJob(j);if(detailFilter||detailPage!==1)await refreshDetails();if(j.state!=='running')await refresh();}}
  catch(e){if(visible&&current&&current.id===id&&needsRefresh(e)){authStopped=true;transportStopped=true;clearTimeout(timer);$('progressState').textContent=e.refresh?'页面已更新，任务进度已保留':'登录校验需要刷新，任务进度已保留';$('progressNotice').textContent=e.message;$('refreshSession').hidden=false;$('resumeJob').hidden=true;}}
  finally{watching=false;startWatch();}
 }
 function schedule(ms){clearTimeout(timer);if(visible&&current&&current.state==='running'&&current.options.mode==='page'&&!transportStopped&&!authStopped)timer=setTimeout(tick,ms||1200);}
 async function tick(){if(ticking||!visible||!current||current.state!=='running'||transportStopped)return;ticking=true;const id=current.id;
  try{const j=await request(current.busy&&current.current?'status':'step',{id});if(visible&&current&&current.id===id){showJob(j);if(detailFilter||detailPage!==1)await refreshDetails();}}
  catch(e){if(visible&&current&&current.id===id){transportStopped=true;authStopped=!!needsRefresh(e);clearTimeout(timer);
   if(!needsRefresh(e)){try{const saved=await request('status',{id});if(visible&&current&&current.id===id){showJob(saved);if(saved.state==='finished')transportStopped=false;}}catch(ignored){}}
   if(current&&current.id===id&&current.state!=='finished'){$('progressState').textContent=e.refresh?'页面已更新，任务进度已保留':e.auth?'登录校验需要刷新，任务进度已保留':'请求中断，已完成结果已保存';$('progressNotice').textContent=e.message;$('resumeJob').hidden=!!needsRefresh(e);$('refreshSession').hidden=!needsRefresh(e);}
  }}
  finally{ticking=false;}
  if(current&&current.state==='running'){schedule(current.busy&&current.current?1500:200);startWatch();}else refresh().catch(e=>notice(e.message,true,needsRefresh(e)));
 }
 async function controlJob(op,itemId){
  const id=current.id;
  if(op==='resume'){
   const latest=await request('status',{id});showJob(latest);
   if(latest.busy&&latest.current)return notice('服务器仍在处理当前图片，请等待结果保存后继续。');
   if(latest.state==='finished'){transportStopped=false;return;}
   if(latest.state==='running'){transportStopped=false;showJob(latest);schedule(100);return;}
  }
  const j=await request(op,{id,item_id:itemId||0});transportStopped=false;authStopped=false;showJob(j);schedule(100);await refresh();
 }
 ['pause','resume','stop','retry'].forEach(op=>$(op+'Job').onclick=()=>busy($(op+'Job'),async()=>{if(op==='stop'&&!confirm('停止后将保留已处理结果；未处理图片保持原样。确定停止？'))return;await controlJob(op);}));
 $('progressDetails').onclick=e=>{const b=e.target.closest('[data-retry-item]');if(b)busy(b,()=>controlJob('retry',Number(b.dataset.retryItem)));};
 $('taskList').onclick=e=>{const b=e.target.closest('[data-job]');if(!b)return;requestedJobId=Number(b.dataset.job);busy(b,async()=>{const j=await request('status',{id:b.dataset.job});showJob(j);modal.show();schedule(100);});};
 modalElement.addEventListener('shown.bs.modal',()=>{visible=true;schedule(100);startWatch();clearInterval(elapsedTimer);elapsedTimer=setInterval(renderElapsed,1000);});
 modalElement.addEventListener('hide.bs.modal',()=>{visible=false;clearTimeout(timer);clearTimeout(watchTimer);clearInterval(elapsedTimer);if(current&&current.state==='running'&&current.options.mode==='page')request('pause',{id:current.id}).then(refresh).catch(e=>notice(e.message,true,needsRefresh(e)));});
 $('copyImageCommand').onclick=()=>busy($('copyImageCommand'),()=>copy($('imageCommand').value,$('copyImageCommand')));
 $('refreshSession').onclick=reloadWithProgress;
 function ftpPreview(){const suffix='/upload/images/ab/example.jpg';$('ftpPathPreview').textContent=($('ftpDir').value||'/').replace(/\/$/,'')+suffix;$('ftpUrlPreview').textContent=($('ftpUrl').value||'https://img.example.com').replace(/\/$/,'')+suffix;}
 $('ftpDir').oninput=ftpPreview;$('ftpUrl').oninput=ftpPreview;
 $('saveStorage').onclick=()=>busy($('saveStorage'),async()=>{const result=await request('save',new FormData($('storageForm')));$('saveStorageResult').textContent=result.message;$('storageForm').elements.password.value='';$('storageForm').elements.password.placeholder='已保存，留空不变';boot.keep_local=$('storageForm').elements.keep_local.checked;},$('saveStorageResult'));
 [['testConnection',false],['testUpload',true]].forEach(([id,upload])=>$(id).onclick=()=>busy($(id),async()=>{$('probeResult').textContent='正在测试…';const p=new FormData($('storageForm'));p.set('upload',upload?'1':'0');$('probeResult').textContent=(await request('probe',p)).message;},$('probeResult')));
 render();ftpPreview();
 const restoreId=Number(sessionStorage.getItem(restoreKey));sessionStorage.removeItem(restoreKey);
 if(Number.isSafeInteger(restoreId)&&restoreId>0){requestedJobId=restoreId;request('status',{id:restoreId}).then(j=>{showJob(j);modal.show();}).catch(e=>notice(e.message,true,needsRefresh(e)));}
});
