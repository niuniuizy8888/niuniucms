(function () {
'use strict';
var el=document.getElementById('collectionWizard'); if(!el) return;
var modal=bootstrap.Modal.getOrCreateInstance(el), content=document.getElementById('cwContent'), error=document.getElementById('cwError'), next=document.getElementById('cwNext'), back=document.getElementById('cwBack'), count=document.getElementById('cwCount');
var state=null, generation=0, busy=false, saved=false;
function esc(v){var x=document.createElement('span');x.textContent=String(v==null?'':v);return x.innerHTML.replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function fail(msg){error.textContent=msg;error.hidden=false;}
function request(action,data){data.safe_token=el.dataset[state.kind+'Token'];return fetch(el.dataset[state.kind+action],{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams(data)}).then(function(r){if(!r.ok)throw Error('请求失败（'+r.status+'），请重试');return r.json();}).then(function(r){if(r.code!==0)throw Error(r.message||'操作失败');return r.data;});}
function chosen(){return state.data.rows.filter(function(r){return state.selected[r.id];});}
function hasPlayerName(){return state.kind==='source'&&state.data.model===0;}
function validPlayerName(){if(!hasPlayerName())return true;var name=state.playerName.trim();if(!name||Array.from(name).length>100||/[\u0000-\u001f\u007f]/.test(name)){fail('请填写 1～100 个字符的播放器名称，不支持换行或控制字符');return false;}state.playerName=name;return true;}
var sourceField=document.getElementById('cwSourceField'), sourceSelect=document.getElementById('cwSource');
function chooseSource(){return state.kind==='template'&&(state.key==='v'||state.key==='x');}
function sourcePath(r){return r.path||r.name;}
function indexCategories(){
 var local=new Map(), byParent=new Map(), parents=new Map();
 state.data.local.forEach(function(c){
  local.set(c.cid,c);
  if(!byParent.has(c.cup))byParent.set(c.cup,new Map());
  var siblings=byParent.get(c.cup);
  siblings.set(c.name,siblings.has(c.name)?0:c.cid);
 });
 state.data.local.forEach(function(c){
  var names=[c.name], seen=new Set([c.cid]), p=local.get(c.cup);
  while(p&&!seen.has(p.cid)){names.unshift(p.name);seen.add(p.cid);p=local.get(p.cup);}
  c.path=names.join(' / ');
 });
 state.data.rows.forEach(function(r){
  var p=parents.get(r.parent);
  r.path=state.data.hierarchical&&p?p.path+' / '+r.name:r.name;
  parents.set(r.id,r);
 });
 state.byId=parents;state.byParent=byParent;state.localById=local;
}
function updateTreeTargets(){
 if(!state.data.hierarchical)return;
 state.data.rows.forEach(function(r){
  if(state.selected[r.id]&&state.manual[r.id]){state.targets[r.id]=state.manual[r.id];return;}
  if(r.status==='已绑定'||r.previous_target){state.targets[r.id]=String(r.target);return;}
  var parent=r.parent==='0'?0:Number(state.targets[r.parent]);
  var siblings=state.byParent.get(parent), id=siblings&&siblings.get(r.local_name||r.name);
  state.targets[r.id]=id?String(id):'create';
 });
}
function neededParents(){
 var needed=new Map();
 if(state.data.hierarchical)chosen().forEach(function(r){
  while(r&&state.targets[r.id]==='create'&&r.parent!=='0'){
   r=state.byId.get(r.parent);
   if(r&&!state.selected[r.id]&&state.targets[r.id]==='create')needed.set(r.id,r);
  }
 });
 return Array.from(needed.values());
}
function hierarchyHelp(){
 if(state.data.hierarchical)return '将按来源层级创建分类。只勾选子分类时会补齐所需父级，采集范围以勾选项为准。';
 if(state.data.model===0)return '该站提供平级分类，按唯一同名匹配。遇到同名项，请手动选择本地分类。';
 return '同时选中父分类时保留层级，未选父分类时作为顶级分类导入。';
}
function updateCount(){count.textContent='已选择 '+chosen().length+' / '+state.data.rows.length+' 个分类';}
function render(){
 error.hidden=true;sourceField.hidden=!chooseSource()||state.step!==1;back.hidden=state.step===1;next.disabled=false;next.textContent=state.step===3?'确认执行':'下一步';
 el.querySelectorAll('[data-step]').forEach(function(li){li.classList.toggle('active',Number(li.dataset.step)===state.step);});updateCount();
 if(state.step===1){
  var missing=state.data.rows.filter(function(r){return !r.target;}).length;
  var pending=Object.entries(state.data.pending_bindings||{}).map(function(entry){
   var target=state.localById.get(Number(entry[1]));
   return esc(entry[0])+(target?' → '+esc(target.path):'');
  });
  var warning=pending.length?'<div class="cw-notice warn"><strong>旧分类绑定待确认</strong><div>'+pending.join('、')+'。请勾选对应的来源分类并选择本地目标，保存后恢复采集。</div></div>':'';
  content.innerHTML=warning+'<div class="cw-notice '+(missing?'warn':'')+'"><strong>'+(state.data.empty_local?'分类未创建':missing?'有 '+missing+' 个分类需要确认':'已匹配本地分类')+'</strong><div>'+(state.kind==='source'?'勾选需要采集的分类，选择已有分类或新建。未勾选的分类不采集；已有绑定也将在确认后解除。':'勾选要导入的分类，已有分类会复用，不重复创建。')+'</div></div><div class="cw-toolbar"><input class="form-control form-control-sm" id="cwSearch" placeholder="搜索分类"><button class="btn btn-primary btn-sm" data-bulk="all">全选</button><button class="btn btn-outline-secondary btn-sm" data-bulk="none">取消全选</button><button class="btn btn-light btn-sm" data-bulk="matched">仅选已匹配</button></div><div class="cw-table"><table class="table"><thead><tr><th>选择</th><th>来源分类</th><th>状态</th><th>导入 / 绑定到</th></tr></thead><tbody id="cwRows"></tbody></table></div><p class="small text-muted mt-2 mb-0">全选和取消全选作用于全部分类。'+hierarchyHelp()+'</p>';
  drawRows('');
 }else if(state.step===2){
  if(state.kind==='template'){content.innerHTML='<h5>导入设置</h5><div class="cw-notice">已勾选 '+chosen().length+' 个分类。已有分类会复用，新建分类显示在导航中。'+hierarchyHelp()+'</div><p class="mt-3 text-muted">下一步可检查完整清单，确认后才保存。</p>';return;}
  content.innerHTML=(state.data.model===1?'<h5>文章采集设置</h5><div class="cw-notice">仅采集已选择的文章分类，未勾选的分类不入库。</div>':'<h5>播放器</h5><div class="mb-3"><label class="form-label" for="cwPlayerName">播放器显示名称（前台线路名）</label><input type="text" class="form-control" id="cwPlayerName" maxlength="100" required value="'+esc(state.playerName)+'" placeholder="例如：高速线路1" aria-describedby="cwPlayerNameHelp"><div class="form-text" id="cwPlayerNameHelp">可填写高速线路1、高速线路2等名称，前台播放线路将显示此名称，已有视频也会同步显示。</div></div><label class="cw-option"><input type="radio" name="cwPlayer" value="keep" '+(state.player==='keep'?'checked':'')+'><span><strong>'+(state.data.has_player?'保留现有播放器':'使用站内播放器')+'</strong><small>'+(state.data.has_player?'保留解析地址及其他播放器设置，显示名称可在上方修改。':'使用站内播放器，不添加官方解析地址。')+'</small></span></label><label class="cw-option"><input type="radio" name="cwPlayer" value="official" '+(state.player==='official'?'checked':'')+' '+(!state.data.official_player?'disabled':'')+'><span><strong>使用官方播放器</strong><small>'+(state.data.official_player?'使用此资源站提供的播放器，优先于全局弹幕播放器；将替换该来源现有配置。':'此资源站未提供官方播放器，暂不可选。')+'</small></span></label>')+'<h5 class="mt-4">完成后</h5><select class="form-select" id="cwRun"><option value="save">仅保存配置，稍后采集</option><option value="day">保存并采集今日更新</option><option value="all">保存并采集全部内容</option><option value="async_all">保存并异步采集全部（站点需支持海外访问）</option></select><p class="text-muted small mt-2">仅采集本次选中的分类。异步采集由云端执行，站点需支持海外访问，云端才能回调入库；本机地址无法使用。定时任务可在列表中单独设置。</p>';document.getElementById('cwRun').value=state.run;
 }else{
  var list=chosen(), parents=neededParents(), creates=list.filter(function(r){return state.targets[r.id]==='create';}).length;
  content.innerHTML='<h5>确认你的选择</h5><p class="text-muted">'+esc(state.data.name)+' · 确认后统一保存分类'+(state.kind==='source'?(state.data.model===1?'与绑定设置':'、绑定与播放器设置'):'')+'</p><div class="cw-metrics"><div><strong>'+(creates+parents.length)+'</strong><span>预计新建</span></div><div><strong>'+(list.length-creates)+'</strong><span>使用已有分类</span></div><div><strong>'+(state.data.rows.length-list.length)+'</strong><span>未勾选</span></div></div>'+(parents.length?'<p>将同时补齐 '+parents.length+' 个父分类：'+parents.map(function(r){return esc(sourcePath(r));}).join('、')+'</p>':'')+(state.kind==='source'?'<div class="cw-notice mb-3">'+(state.data.model===1?'':'前台线路名：<strong>'+esc(state.playerName)+'</strong><br>播放器：<strong>'+esc(state.player==='official'?'使用官方播放器（替换此来源配置）':state.data.has_player?'保留现有播放器':'使用站内播放器')+'</strong><br>')+'完成后：'+esc({save:'仅保存配置',day:'采集今日更新',all:'采集全部内容',async_all:'异步采集全部（站点需支持海外访问）'}[state.run])+'</div>':'')+'<div class="cw-table"><table class="table"><thead><tr><th>选中的分类</th><th>执行操作</th></tr></thead><tbody>'+list.map(function(r){var t=state.targets[r.id], local=state.data.local.find(function(c){return String(c.cid)===t;});return '<tr><td>'+esc(sourcePath(r))+'</td><td>'+esc(t==='create'?'新建分类':('绑定 / 复用：'+(local?local.path:'')))+'</td></tr>';}).join('')+'</tbody></table></div><details class="mt-3"><summary>查看未勾选分类（'+(state.data.rows.length-list.length)+'）</summary><p class="text-muted small mt-2">'+state.data.rows.filter(function(r){return !state.selected[r.id];}).map(function(r){return esc(sourcePath(r));}).join('、')+'</p></details>';
 }
}
function targetOptions(value, expanded){
 var local=expanded?state.data.local:[state.localById.get(Number(value))].filter(Boolean);
 return '<option value="create" '+(value==='create'?'selected':'')+'>＋ 新建此分类</option>'+local.map(function(c){
  return '<option value="'+c.cid+'" '+(value===String(c.cid)?'selected':'')+'>'+esc(c.path)+'</option>';
 }).join('');
}
function expandTargets(event){
 var t=event.target.closest('select[data-target]');
 if(!t||t.disabled||t.dataset.expanded)return;
 t.innerHTML=targetOptions(state.targets[t.dataset.target],true);
 t.dataset.expanded='1';
}
content.addEventListener('pointerdown',expandTargets);
content.addEventListener('focusin',expandTargets);
function drawRows(q){
 var body=document.getElementById('cwRows'), query=q.toLowerCase();
 body.innerHTML=state.data.rows.filter(function(r){return sourcePath(r).toLowerCase().includes(query);}).map(function(r){
  var id=esc(r.id), name=esc(sourcePath(r)), target=state.targets[r.id], pick=state.selected[r.id];
  var matched=target!=='create', status=matched?(target===String(r.target)?r.status:'已匹配'):(r.status.endsWith('待确认')?r.status:'待新建');
  return '<tr data-row="'+id+'"><td><input type="checkbox" aria-label="选择 '+name+'" data-pick="'+id+'" '+(pick?'checked':'')+'></td><td>'+name+'</td>'+
   '<td><span class="cw-status '+(matched?'matched':'missing')+'">'+esc(status)+'</span></td>'+
   '<td><select class="form-select form-select-sm" aria-label="'+name+' 的目标分类" data-target="'+id+'" '+(!pick?'disabled':'')+'>'+targetOptions(target,false)+'</select></td></tr>';
 }).join('')||'<tr><td colspan="4" class="text-center text-muted">没有符合条件的分类</td></tr>';
}
function preparePreview(){
 state.data=null;state.step=1;state.player='keep';state.playerName='';state.run='save';
 state.selected=Object.create(null);state.targets=Object.create(null);state.manual=Object.create(null);
 error.hidden=true;next.disabled=true;next.textContent='下一步';back.hidden=true;count.textContent='预览不会修改数据';
 el.querySelectorAll('[data-step]').forEach(function(li){li.classList.toggle('active',li.dataset.step==='1');});
}
function loadPreview(){
 var g=++generation;
 preparePreview();
 if(chooseSource()&&!sourceSelect.value){content.textContent='请选择要导入分类的资源站。';return;}
 content.textContent='正在读取分类并匹配本地数据…';
 request('Preview',{key:state.key,model:el.dataset.sourceModel||'0',from_code:chooseSource()?sourceSelect.value:''}).then(function(data){
  if(g!==generation)return;
  state.data=data;state.playerName=data.player_name||'';indexCategories();
  data.rows.forEach(function(r){state.selected[r.id]=!!r.selected;state.targets[r.id]=r.target?String(r.target):'create';});
  render();
 }).catch(function(e){
  if(g!==generation)return;
  fail(e.message);content.innerHTML='<button type="button" class="btn btn-outline-secondary btn-sm" data-preview-retry>重新读取分类</button>';
 });
}
document.addEventListener('click',function(e){
 var b=e.target.closest('[data-setup-kind]');if(!b||busy)return;e.preventDefault();
 var g=++generation;saved=false;state={kind:b.dataset.setupKind,key:b.dataset.setupKey};
 preparePreview();sourceField.hidden=!chooseSource();
 document.getElementById('cwTitle').textContent=state.kind==='source'?(el.dataset.sourceModel==='1'?'文章一键采集设置':'视频一键采集设置'):'选择导入分类';
 modal.show();
 if(!chooseSource()){loadPreview();return;}
 sourceSelect.replaceChildren(new Option('请选择资源站',''));sourceSelect.disabled=true;content.textContent='正在读取资源站…';
 request('Sources',{}).then(function(sources){
  if(g!==generation)return;
  function preferred(s){return /X资源|X分类|成人/i.test((s.type||'').replace(/\s/g,''))===(state.key==='x');}
  sources.sort(function(a,b){return Number(preferred(b))-Number(preferred(a));});
  sources.forEach(function(s){sourceSelect.add(new Option((s.type?s.type+' · ':'')+s.name,s.code));});
  sourceSelect.disabled=!sources.length;
  content.textContent=sources.length?'选择资源站后可预览分类。确认导入只会保存本地分类。':'暂无可用的视频资源站，请检查商店连接或已配置的资源站。';
 }).catch(function(e){if(g===generation){fail(e.message);content.textContent='请关闭后重新打开，再读取资源站。';}});
});
sourceSelect.addEventListener('change',loadPreview);
content.addEventListener('input',function(e){if(e.target.id==='cwSearch')drawRows(e.target.value);if(e.target.id==='cwPlayerName')state.playerName=e.target.value;});
content.addEventListener('change',function(e){var t=e.target;if(t.dataset.pick){state.selected[t.dataset.pick]=t.checked;updateTreeTargets();drawRows(document.getElementById('cwSearch').value);updateCount();}if(t.dataset.target){state.targets[t.dataset.target]=t.value;state.manual[t.dataset.target]=t.value;updateTreeTargets();drawRows(document.getElementById('cwSearch').value);}if(t.name==='cwPlayer')state.player=t.value;if(t.id==='cwRun')state.run=t.value;});
content.addEventListener('click',function(e){
 if(e.target.closest('[data-preview-retry]')){loadPreview();return;}
 var button=e.target.closest('[data-bulk]');if(!button)return;
 var action=button.dataset.bulk, query=document.getElementById('cwSearch').value;
 state.data.rows.forEach(function(r){
  if(action==='matched'&&!state.selected[r.id]&&state.manual[r.id]!==state.targets[r.id])delete state.manual[r.id];
  state.selected[r.id]=action==='matched'?state.targets[r.id]!=='create'&&!r.status.endsWith('待确认'):action==='all';
 });
 updateTreeTargets();drawRows(query);updateCount();
});
back.addEventListener('click',function(){if(!busy){state.step--;render();}});
next.addEventListener('click',function(){if(saved){location.reload();return;}if(busy||!state||!state.data)return;if(!chosen().length){fail('请至少勾选一个分类，再继续。');return;}if(state.step>=2&&!validPlayerName())return;if(state.step<3){state.step++;render();return;}busy=true;next.disabled=true;back.disabled=true;error.hidden=true;next.textContent='正在保存…';var choices=Object.create(null);chosen().forEach(function(r){choices[r.id]=state.targets[r.id];});var payload={plan:state.data.plan,choices:JSON.stringify(choices),player:state.player,run:state.run};if(hasPlayerName())payload.player_name=state.playerName;request('Apply',payload).then(function(d){saved=true;content.innerHTML='<div class="text-center py-4"><h4>设置已完成</h4><p class="text-muted mt-3">新建 '+d.created+' 个分类'+(d.parents_created?'（含补齐的 '+d.parents_created+' 个父分类）':'')+(state.kind==='source'?' · 绑定 '+d.bound+' 个':'')+' · 未勾选 '+d.skipped+' 个</p><p>'+(d.next?(state.run==='async_all'?'即将请求启动云端异步采集。':'即将进入采集进度页面。'):(state.kind==='template'?'可在分类管理中查看导入结果。':'你可以继续采集，或在列表中配置定时任务。'))+'</p></div>';back.hidden=true;next.textContent='完成并刷新';if(d.next)location.href=d.next;}).catch(function(e){fail(e.message);next.textContent='重试保存';}).finally(function(){busy=false;next.disabled=false;back.disabled=false;});});
el.addEventListener('hide.bs.modal',function(e){if(busy)e.preventDefault();});el.addEventListener('hidden.bs.modal',function(){generation++;if(saved)location.reload();});
})();
