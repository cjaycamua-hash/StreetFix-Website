const $=selector=>document.querySelector(selector);
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const page=document.body.dataset.page;
const requestedTask=Number(new URLSearchParams(location.search).get('task'));
const statusLabel={'Assigned':'Pending','In Progress':'In Progress','Resolved':'Resolved'};
const filterStatus={progress:'In Progress',pending:'Assigned',completed:'Resolved'};
let sfMap,locMap,tasks=[],selected=Number.isInteger(requestedTask)&&requestedTask>0?requestedTask:null,filter='all',query='',tab='details';

const sev=task=>`<span class="sev ${esc(task.severity)}">${esc(task.severity)}</span>`;
const pill=task=>`<span class="pill s-${esc(task.status).replace(/ /g,'-')}">${esc(statusLabel[task.status]||task.status)}</span>`;
const thumb=task=>`<img class="thumb" src="${esc((task.photos&&task.photos[0])||task.photo)}" alt="" loading="lazy">`;
const detailsUrl=task=>`task_details.php?task=${encodeURIComponent(task.id)}`;

// LGU notifications: a toast shows each new notice for 5 seconds; the Notifications tab keeps the full list.
let notices=[],noticeKey='';
const storeGet=key=>{try{return Number(localStorage.getItem(key))||0}catch{return 0}};
const storeSet=(key,value)=>{try{localStorage.setItem(key,String(value))}catch{}};
const unreadNotices=()=>notices.filter(notice=>notice.id>storeGet(`${noticeKey}_read`));
function updateNoticeBadge(){
  const nav=$('#nav-notif');if(!nav)return;
  const unread=unreadNotices().length;
  nav.textContent=unread?`Notifications (${unread})`:'Notifications';
}
function showToast(notice){
  const stack=$('#toast-stack');if(!stack)return;
  const toast=document.createElement('div');toast.className='toast';toast.setAttribute('role','status');
  toast.innerHTML=`<a href="${detailsUrl({id:notice.task_id})}"><b>${esc(notice.code)} · ${esc(notice.road)}</b><span>${esc(notice.message)}</span></a><button type="button" class="toast-close" aria-label="Dismiss notification">×</button>`;
  const remove=()=>{if(!toast.isConnected)return;toast.classList.add('out');window.setTimeout(()=>toast.remove(),300)};
  toast.querySelector('.toast-close').addEventListener('click',remove);
  stack.append(toast);
  window.setTimeout(remove,5000);
}
function handleNotices(list,workerId){
  notices=list;noticeKey=`sf_lgu_${workerId||0}`;
  const shown=storeGet(`${noticeKey}_toast`),fresh=notices.filter(notice=>notice.id>shown).sort((a,b)=>a.id-b.id);
  if(fresh.length){
    fresh.slice(-3).forEach(showToast);
    storeSet(`${noticeKey}_toast`,Math.max(...fresh.map(notice=>notice.id)));
  }
  updateNoticeBadge();
  if(page==='notifications')renderNotices();
}
function renderNotices(){
  const list=$('#notifs');if(!list)return;
  const readUpTo=storeGet(`${noticeKey}_read`),unread=notices.filter(notice=>notice.id>readUpTo).length;
  list.innerHTML=notices.length?notices.map(notice=>`<li class="${notice.id>readUpTo?'new':''}"><a class="rowlink" href="${detailsUrl({id:notice.task_id})}">${esc(notice.code)} · ${esc(notice.road)}</a><div>${esc(notice.message)}</div><small>${esc(notice.date)}</small></li>`).join(''):'<li class="empty">No LGU notifications yet. You will be notified when a report is assigned to your LGU.</li>';
  const readAll=$('#read-all');if(readAll)readAll.hidden=!unread;
}
$('#read-all')?.addEventListener('click',()=>{
  if(notices.length)storeSet(`${noticeKey}_read`,Math.max(...notices.map(notice=>notice.id)));
  updateNoticeBadge();renderNotices();
});

async function loadTasks(){
  const response=await fetch('api/tasks.php?scope=all',{cache:'no-store'});
  if(response.status===401||response.status===403){location.href='../auth/login.php';return}
  const data=await response.json();
  if(!response.ok||!data.ok)throw new Error(data.error||'Could not load tasks.');
  tasks=data.tasks;
  handleNotices(data.notifications||[],data.worker_id);
  sfMap?.draw(tasks.filter(task=>task.status!=='Resolved'));
  if(page==='task-details'&&!selected)selected=(tasks.find(task=>task.status!=='Resolved')||tasks[0]||{}).id||null;
  render();
}
function render(){
  if(page==='dashboard')renderDashboard();
  else if(page==='assigned')renderTable();
  else if(page==='notifications')renderNotices();
  else if(page==='task-details')renderDetail();
}
function renderDashboard(){
  const count=status=>tasks.filter(task=>task.status===status).length;
  $('#t-assigned').textContent=count('Assigned');$('#t-progress').textContent=count('In Progress');$('#t-done').textContent=count('Resolved');
  const recent=tasks.slice().sort((a,b)=>b.id-a.id).slice(0,5);
  $('#recent').innerHTML=recent.length?recent.map(task=>`<a class="recent-item" href="${detailsUrl(task)}">${thumb(task)}<div><b>${esc(task.code)}</b><small>${esc(task.road)} · ${esc(task.date)}${task.completion_submitted&&task.status==='In Progress'?' · <span class="await">Awaiting admin</span>':''}</small></div><span class="badges">${sev(task)}${pill(task)}</span></a>`).join(''):'<p class="empty">No reports are assigned to your account yet.</p>';
}
function renderTable(){
  const q=query.trim().toLowerCase();
  const rows=tasks.filter(task=>(filter==='all'||task.status===filterStatus[filter])&&(!q||[task.code,task.road,task.location_address,task.description,task.severity,statusLabel[task.status],task.status].join(' ').toLowerCase().includes(q)));
  $('#rows').innerHTML=rows.length?rows.map(task=>`<tr><td><a class="rowlink" href="${detailsUrl(task)}">${esc(task.code)}</a></td><td>${esc(task.road)}${task.location_address?`<small>${esc(task.location_address)}</small>`:''}</td><td>${sev(task)}</td><td>${pill(task)}${task.completion_submitted&&task.status==='In Progress'?'<span class="await">Awaiting admin</span>':''}</td><td>${esc(task.date)}</td><td><a class="btn sm ghost" href="${detailsUrl(task)}">Manage</a></td></tr>`).join('')
    :`<tr><td colspan="6" class="empty">${tasks.length?'No reports match this filter.':'No reports are assigned to your account yet.'}</td></tr>`;
}
function renderDetail(){
  locMap?.remove();locMap=null;
  const task=tasks.find(item=>item.id===selected);
  if(!task){$('#detail').innerHTML='<a class="dback" href="assigned_reports.php">← Assigned reports</a><p class="empty">This report is not assigned to your account.</p>';return}
  const done=task.status==='Resolved',active=task.status==='In Progress'&&!task.completion_submitted;
  const lguAssigned=task.lgu_assignment_status==='assigned';
  const stage=done?3:task.status==='Assigned'?0:task.completion_submitted?2:1;
  const evidence=(type,label,path)=>`<div class="photo"><label>${label} photo</label>${path?`<img src="${esc(path)}" alt="${label} evidence">`:''}
      ${active?`<input type="file" name="${type}_photo" accept="image/jpeg,image/png,image/webp" capture="environment" ${type==='before'?'required':''} aria-label="${label} repair photo"><img id="preview-${type}" alt="" hidden>`:''}</div>`;
  const mapUrl=`https://www.openstreetmap.org/?mlat=${encodeURIComponent(task.lat)}&mlon=${encodeURIComponent(task.lng)}#map=18/${encodeURIComponent(task.lat)}/${encodeURIComponent(task.lng)}`;
  const reportPhotos=(task.photos&&task.photos.length?task.photos:[task.photo]);
  $('#detail').innerHTML=`<a class="dback" href="assigned_reports.php">← Assigned reports</a>
    <div class="dhead"><h2>${esc(task.code)} – ${esc(task.road)}</h2><span class="sev ${esc(task.severity)}">${esc(task.severity)} severity</span></div>
    <div class="meta" style="margin:0 0 4px"><span>${esc(task.team_name||task.agency||'Assigned to you')}</span>${pill(task)}${task.completion_submitted&&task.status==='In Progress'?'<span>Awaiting admin verification</span>':''}</div>
    <div class="tabs" id="dtabs" role="tablist" aria-label="Task sections" style="margin-top:12px">${[['details','Details'],['location','Location'],['updates','Updates'],['photos','Photos']].map(([id,name])=>`<button type="button" role="tab" data-tab="${id}" class="${tab===id?'on':''}" aria-selected="${tab===id}">${name}</button>`).join('')}</div>
    <div data-pane="details" ${tab==='details'?'':'hidden'}><div class="two">
      <div><img class="dthumb" src="${esc(reportPhotos[0])}" alt="Photo of ${esc(task.road)}">
        <p class="dfact"><b>Reported by</b>${esc(task.reporter)}</p><p class="dfact"><b>Date reported</b>${esc(task.date)}</p>
        ${task.location_address?`<p class="dfact"><b>Address</b>${esc(task.location_address)}</p>`:''}
        <p class="dfact"><b>Description</b>${esc(task.description||'No extra description was provided.')}</p>
        ${task.agency?`<p class="dfact"><b>Responsible agency</b>${esc(task.agency)}</p>`:''}</div>
      <div class="dcard"><h3>Update status</h3>
        <div class="steps"><span class="done">${lguAssigned?'Pending':'Assigned'}</span><span class="${stage>0?'done':''}">In Progress</span><span class="${stage===2?'on':stage===3?'done':''}">${lguAssigned?'Resolved':'Admin verification'}</span></div>
        ${task.status==='Assigned'?`<p class="note">Start the repair when your crew begins work on this report.</p><button class="btn full" type="button" data-start="${task.id}">Start repair</button>`:''}
        ${active?`<form id="repair-form" data-report="${task.id}"><div class="photos">${evidence('before','Before',task.before_photo)}${evidence('after','After',task.after_photo)}</div>
          <div class="field"><label for="notes">Repair notes</label><textarea id="notes" name="notes" maxlength="2000" rows="4" required>${esc(task.notes)}</textarea></div>
          <div id="task-msg" class="msg" role="alert"></div><button class="btn full" type="submit">${lguAssigned?'Mark pothole resolved':'Submit repair for verification'}</button></form>`:''}
        ${task.completion_submitted&&!done?`<div class="okmsg">Repair notes and photos submitted. An administrator must verify the work before the report is marked resolved.</div>`:''}
        ${done?`<div class="okmsg">${lguAssigned?'This pothole has been marked Resolved by your LGU.':'This repair was verified and the report is resolved.'}${task.completed_at?` ${esc(task.completed_at)}.`:''}</div>`:''}
      </div></div></div>
    <div data-pane="location" ${tab==='location'?'':'hidden'}>${task.location_address?`<p class="dfact"><b>${esc(task.code)} · ${esc(task.road)}</b>${esc(task.location_address)}</p>`:`<p class="dfact"><b>${esc(task.code)} · ${esc(task.road)}</b></p>`}
      <div id="loc-map" aria-label="Map showing ${esc(task.code)}"></div><p><a class="maplink" href="${mapUrl}" target="_blank" rel="noopener">Open report location in OpenStreetMap</a></p></div>
    <div data-pane="updates" ${tab==='updates'?'':'hidden'}><ul class="timeline"><li><b>Reported</b> by ${esc(task.reporter)}<small>${esc(task.date)}</small></li><li><b>${esc(task.team_name||task.agency||'Your account')}</b> assigned to this report<small>Status: ${esc(statusLabel[task.status]||task.status)}</small></li>
      ${task.completion_submitted&&!lguAssigned?`<li><b>Repair submitted for admin verification</b>${task.notes?`<small>${esc(task.notes)}</small>`:''}${task.completed_at?`<small>${esc(task.completed_at)}</small>`:''}</li>`:''}${done?`<li><b>${lguAssigned?'Resolved by LGU staff':'Resolved'}</b>${lguAssigned?'':'<small>Verified by an administrator.</small>'}</li>`:''}</ul></div>
    <div data-pane="photos" ${tab==='photos'?'':'hidden'}><div class="photo-grid">${reportPhotos.map((photo,index)=>`<figure><a href="${esc(photo)}" target="_blank" rel="noopener"><img src="${esc(photo)}" alt="Original report photo ${index+1}" loading="lazy"></a><figcaption>Original report photo ${index+1}</figcaption></figure>`).join('')}
      ${task.before_photo?`<figure><a href="${esc(task.before_photo)}" target="_blank" rel="noopener"><img src="${esc(task.before_photo)}" alt="Before repair"></a><figcaption>Before repair</figcaption></figure>`:''}
      ${task.after_photo?`<figure><a href="${esc(task.after_photo)}" target="_blank" rel="noopener"><img src="${esc(task.after_photo)}" alt="After repair"></a><figcaption>After repair</figcaption></figure>`:''}</div></div>`;
  if(tab==='location')showLocation(task);
}
function showLocation(task){
  const el=$('#loc-map');if(!el||locMap)return;
  locMap=L.map(el).setView([task.lat,task.lng],17);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(locMap);
  const helper=StreetFixMap.create(locMap,code=>code===task.code?{reporter:task.reporter,date:task.date,address:task.location_address,description:task.description,photos:task.photos}:null);
  helper.draw([{code:task.code,road:task.road,severity:task.severity,status:task.status,lat:task.lat,lng:task.lng}]);
  helper.open(task.code);
  window.setTimeout(()=>locMap?.invalidateSize(),60);
}

$('#filters')?.addEventListener('click',event=>{
  const button=event.target.closest('[data-filter]');if(!button)return;
  filter=button.dataset.filter;
  document.querySelectorAll('#filters [data-filter]').forEach(item=>item.classList.toggle('on',item===button));
  renderTable();
});
$('#task-search')?.addEventListener('input',event=>{query=event.target.value;renderTable()});
$('#detail')?.addEventListener('click',event=>{
  const tabButton=event.target.closest('[data-tab]');
  if(tabButton){
    tab=tabButton.dataset.tab;
    document.querySelectorAll('#dtabs [data-tab]').forEach(item=>{const on=item===tabButton;item.classList.toggle('on',on);item.setAttribute('aria-selected',String(on))});
    document.querySelectorAll('#detail [data-pane]').forEach(pane=>{pane.hidden=pane.dataset.pane!==tab});
    if(tab==='location')showLocation(tasks.find(item=>item.id===selected));
    return;
  }
});
$('#detail')?.addEventListener('change',event=>{
  const input=event.target.closest('input[type=file]');if(!input||!input.files[0])return;
  const preview=$(`#preview-${input.name.replace('_photo','')}`);
  if(preview){preview.src=URL.createObjectURL(input.files[0]);preview.hidden=false}
});
$('#detail')?.addEventListener('click',async event=>{
  const button=event.target.closest('[data-start]');if(!button)return;
  button.disabled=true;
  try{
    const form=new FormData();form.set('action','start');form.set('report_id',button.dataset.start);
    const response=await fetch('api/task_update.php',{method:'POST',body:form}),data=await response.json();
    if(!response.ok||!data.ok)throw new Error(data.error||'Could not start this repair.');
    await loadTasks();
  }catch(error){window.alert(error.message);button.disabled=false}
});
$('#detail')?.addEventListener('submit',async event=>{
  if(event.target.id!=='repair-form')return;
  event.preventDefault();
  const form=event.target,button=form.querySelector('[type=submit]'),message=$('#task-msg');
  const before=form.elements.before_photo,after=form.elements.after_photo;
  if(!before?.files?.length||!after?.files?.length){message.textContent='Upload both before and after photos. After-photo evidence is required to mark the pothole resolved.';message.classList.add('show');return}
  button.disabled=true;message.classList.remove('show');
  const payload=new FormData(form);payload.set('action','complete');payload.set('report_id',form.dataset.report);
  for(const field of ['before_photo','after_photo']){const file=form.elements[field]?.files?.[0];if(file)payload.set(field,await window.sfShrinkImage(file))}
  try{
    const response=await fetch('api/task_update.php',{method:'POST',body:payload}),data=await response.json();
    if(!response.ok||!data.ok)throw new Error(data.error||'Could not submit repair evidence.');
    await loadTasks();
  }catch(error){message.textContent=error.message;message.classList.add('show');button.disabled=false}
});
$('#logout').addEventListener('click',async()=>{await fetch('api/logout.php',{method:'POST'});location.href='../auth/login.php'});
if($('#map')){
  const map=L.map('map').setView([14.7366,120.9600],13);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
  sfMap=StreetFixMap.create(map,code=>{const t=tasks.find(item=>item.code===code);return t&&{reporter:t.reporter,date:t.date,address:t.location_address,description:t.description,photos:t.photos,action:{href:detailsUrl(t),label:'Open task'}}});
}
const failure=error=>{const message=esc(error.message);const target=$('#recent')||$('#rows')||$('#detail')||$('#notifs');if(target)target.innerHTML=$('#rows')?`<tr><td colspan="6" class="empty">${message}</td></tr>`:`<p class="empty">${message}</p>`};
loadTasks().catch(failure);
if(page!=='task-details')window.setInterval(()=>{if(!document.hidden)loadTasks().catch(console.error)},60000);
