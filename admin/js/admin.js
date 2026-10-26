const $=selector=>document.querySelector(selector);
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const statuses=['Submitted','Under Review','Verified','Assigned','In Progress','Resolved','Rejected','Duplicate','Cancelled'];
const severityColors={High:'var(--hi)',Medium:'var(--me)',Low:'var(--lo)'};
const statusColors={'Submitted':'var(--me)','Under Review':'var(--me)','Verified':'var(--pu)','Assigned':'var(--bl)','In Progress':'var(--bl)','Resolved':'var(--lo)','Rejected':'var(--gray)','Duplicate':'var(--gray)','Cancelled':'var(--gray)'};
let all=[],reports=[],teams=[],lgus=[],users=[],logs=[],map,sfMap,logFilter='';
const sevRank={Low:1,Medium:2,High:3};
const statusGroups={Pending:['Submitted','Under Review','Verified','Duplicate'],'In progress':['Assigned','In Progress'],Completed:['Resolved']};
const sevPills=list=>`<span class="sevs">${list.map(level=>`<span class="sev ${esc(level)}">${esc(level)}</span>`).join('<span class="sev-sep">–</span>')}</span>`;
const focusCode=new URLSearchParams(location.search).get('report');
const roleLabels={maintenance:'maintenance',citizen:'citizen',admin:'administrator'};

async function readJSON(url,options={}){
  const response=await fetch(url,options);
  if(response.status===401||response.status===403){location.href='../auth/login.php';return null}
  const data=await response.json();
  if(!response.ok||!data.ok)throw new Error(data.error||'Could not load StreetFix data.');
  return data;
}
async function postJSON(url,body){
  const response=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  const data=await response.json();
  if(!response.ok||!data.ok)throw new Error(data.error||'The change could not be saved.');
  return data;
}
function filtered(){
  const query=$('#q').value.trim().toLowerCase(),status=$('#f-status').value,severity=$('#f-sev').value;
  return reports.filter(report=>{
    const text=[report,...report.members].map(item=>`${item.code} ${item.road} ${item.by} ${item.assignee}`).join(' ').toLowerCase();
    const statusOk=!status||(status.startsWith('group:')?statusGroups[status.slice(6)].includes(report.status):report.status===status);
    return(!query||text.includes(query))&&statusOk&&(!severity||report.sevs.includes(severity));
  });
}
function reportAction(report){
  const options=teams.filter(team=>Number(team.active_members)>0).map(team=>`<option value="${team.id}" ${Number(report.team_id)===Number(team.id)?'selected':''}>${esc(team.name)} (${team.active_members})</option>`).join('');
  if(report.status==='Verified'&&report.lgu_assignment_status==='manual_review'){
    return `<select data-manual-lgu="${report.id}" aria-label="Manually assign ${esc(report.code)} to an LGU"><option value="">Choose LGU after location review…</option>${lgus.map(lgu=>`<option value="${lgu.id}">${esc(lgu.name)}</option>`).join('')}</select>`;
  }
  if(report.status==='Verified'&&report.lgu_assignment_status==='unverified'){
    return `<button class="btn sm ok" data-status="Verified" data-id="${report.id}">Match location to LGU</button>`;
  }
  if(['Submitted','Under Review','Duplicate'].includes(report.status)){
    const actions=[`<button class="btn sm ok" data-status="Verified" data-id="${report.id}">Verify</button>`,`<button class="btn sm ghost" data-status="Rejected" data-id="${report.id}">Reject</button>`];
    if(report.status!=='Duplicate')actions.push(`<button class="btn sm ghost" data-merge="${report.id}" data-code="${esc(report.code)}">Group as duplicate</button>`);
    return `<div class="acts">${actions.join('')}</div>`;
  }
  if(report.status==='Verified'){
    return `<span class="note">Location requires manual review</span>`;
  }
  if(report.status==='In Progress'&&report.repair_submitted)return `<button class="btn sm ok" data-status="Resolved" data-id="${report.id}">Verify repair</button>`;
  if(report.status==='In Progress'&&report.lgu_name)return `<span class="note">In progress at ${esc(report.lgu_name)}</span>`;
  if(report.status==='In Progress')return `<span class="note">Repair in progress</span><select data-reassign="${report.id}" aria-label="Reassign ${esc(report.code)}">${options}</select>`;
  if(report.status==='Assigned'&&report.lgu_name)return `<span class="note">Assigned to ${esc(report.lgu_name)}</span>`;
  if(report.status==='Assigned')return `<select data-reassign="${report.id}" aria-label="Reassign ${esc(report.code)}">${options}</select>`;
  return '—';
}
function memberHTML(member){
  const state=member.duplicate_reviewed?'Confirmed duplicate':'Auto-flagged · awaiting review';
  return `<li><b>${esc(member.code)}</b> ${sevPills([member.severity])}<small>Reported by ${esc(member.by)} · ${esc(member.date)}</small>${member.description?`<small>${esc(member.description)}</small>`:''}<small>${esc(state)} · ${(member.photos||[member.photo]).map((photo,index)=>`<a href="${esc(photo)}" target="_blank" rel="noopener">Photo ${index+1}</a>`).join(' · ')}</small>
    <div class="acts">${member.duplicate_reviewed?'':`<button class="btn sm ghost" data-confirm-duplicate="${member.id}">Confirm duplicate</button>`}<button class="btn sm ghost" data-status="Under Review" data-id="${member.id}">Not a duplicate</button></div></li>`;
}
function renderReports(){
  // Dashboard figures count every submitted report, including those grouped as duplicates (the table below is grouped).
  const count=(...allowed)=>all.filter(report=>allowed.includes(report.status)).length;
  $('#t-total').textContent=all.length;
  $('#t-review').textContent=count('Submitted','Under Review','Verified','Duplicate');
  $('#t-repair').textContent=count('Assigned','In Progress');
  $('#t-done').textContent=count('Resolved');
  const visible=filtered();
  $('#rows').innerHTML=visible.length?visible.map(report=>`<tr><td><b>${esc(report.code)}</b><small>Reported by ${esc(report.by)} · ${esc(report.date)}</small><a href="map.php?report=${encodeURIComponent(report.code)}">View on Map</a>
    ${report.members.length?`<details class="group"><summary>${report.members.length} grouped duplicate report${report.members.length>1?'s':''}</summary><ul>${report.members.map(memberHTML).join('')}</ul></details>`:''}</td>
    <td>${esc(report.road)}${report.location_address?`<small>${esc(report.location_address)}</small>`:''}${report.description?`<small>${esc(report.description)}</small>`:''}    ${report.lgu_name?`<small>Assigned LGU: ${esc(report.lgu_name)}</small>`:report.expected_lgu?`<small>LGU for this location: ${esc(report.expected_lgu)}</small>`:''}
    ${report.lgu_assignment_status==='manual_review'?`<small class="note">${esc(report.lgu_assignment_note||'Location requires manual LGU review.')}</small>`:''}
    ${report.status==='Verified'&&report.lgu_assignment_status==='unverified'?'<small class="note">Match the stored GPS/map coordinates before routing this legacy report.</small>':''}
    ${report.team_name?`<small>Assigned to ${esc(report.team_name)}</small>`:''}
    <div class="photo-links">${(report.photos||[report.photo]).map((photo,index)=>`<a href="${esc(photo)}" target="_blank" rel="noopener">Report photo ${index+1}</a>`).join(' · ')}</div>
    <form class="route-form" data-route="${report.id}"><input name="agency" maxlength="190" value="${esc(report.agency)}" aria-label="Responsible agency for ${esc(report.code)}" required><input name="agency_email" type="email" maxlength="190" value="${esc(report.agency_email)}" placeholder="Contact email" aria-label="Agency contact email for ${esc(report.code)}"><button class="btn sm ghost" type="submit">Route</button></form>
    ${report.agency_email?`<small><a href="mailto:${encodeURIComponent(report.agency_email)}?subject=${encodeURIComponent('StreetFix report '+report.code)}">Email agency</a></small>`:''}
    ${report.repair_submitted?`<small>${esc(report.repair_notes)}</small><small><a href="${esc(report.before_photo)}" target="_blank" rel="noopener">Before photo</a> · <a href="${esc(report.after_photo)}" target="_blank" rel="noopener">After photo</a></small>`:''}
    ${report.feedback?`<small>Feedback (${report.rating}/5): ${esc(report.feedback)}</small>`:''}</td>
    <td>${sevPills(report.sevs)}</td><td><span class="pill s-${report.status.replace(/ /g,'-')}">${esc(report.status)}</span></td><td class="count-cell">${report.members.length+1}${report.members.length?`<small>incl. ${report.members.length} duplicate${report.members.length>1?'s':''}</small>`:''}</td><td>${reportAction(report)}</td></tr>`).join('')
    :'<tr><td colspan="6" class="empty">No reports match these filters.</td></tr>';
  $('#sev').innerHTML=['High','Medium','Low'].map(level=>{
    const amount=all.filter(report=>report.severity===level).length;
    return `<div class="meter"><span>${level}</span><div><i style="width:${amount/Math.max(all.length,1)*100}%;background:${severityColors[level]}"></i></div><b>${amount}</b></div>`;
  }).join('');
  renderDashboard();
  $('#legend').innerHTML=statuses.filter(status=>count(status)).map(status=>`<li><span style="color:${statusColors[status]}">${esc(status)}</span><b>${count(status)}</b></li>`).join('')||'<li>No reports yet.</li>';
}
function renderDashboard(){
  const chart=$('#overview-chart'),recent=$('#recent');if(!chart||!recent)return;
  const now=new Date(),months=[...Array(6)].map((_,i)=>{const d=new Date(now.getFullYear(),now.getMonth()-5+i,1);return{key:`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`,label:d.toLocaleString('en',{month:'short'})}});
  const series=[['Completed','#1b7f4b',statusGroups.Completed],['In progress','#2f5fb8',statusGroups['In progress']],['Pending','#F24333',statusGroups.Pending]].map(([name,color,list])=>({name,color,values:months.map(m=>all.filter(r=>r.date.startsWith(m.key)&&list.includes(r.status)).length)}));
  const max=Math.max(1,...series.flatMap(s=>s.values)),W=400,H=170,left=24,bottom=24,x=i=>left+i*(W-left-12)/5,y=v=>H-bottom-v/max*(H-bottom-14);
  const ticks=[...new Set([0,Math.ceil(max/2),max])];
  chart.innerHTML=`<svg viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="Reports per month by status, last six months"><g stroke="var(--line)">${ticks.map(t=>`<path d="M${left} ${y(t)}H${W-8}"/>`).join('')}</g><g fill="var(--mut)" font-size="9">${ticks.map(t=>`<text x="${left-6}" y="${y(t)+3}" text-anchor="end">${t}</text>`).join('')}${months.map((m,i)=>`<text x="${x(i)}" y="${H-8}" text-anchor="middle">${m.label}</text>`).join('')}</g>${series.map(s=>`<polyline fill="none" stroke="${s.color}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="${s.values.map((v,i)=>`${x(i)},${y(v)}`).join(' ')}"/>`).join('')}</svg><div class="chart-legend">${series.map(s=>`<span><i style="background:${s.color}"></i>${s.name}</span>`).join('')}</div>`;
  recent.innerHTML=reports.slice(0,5).map(r=>`<a class="recent-item" href="reports.php?q=${encodeURIComponent(r.code)}"><img class="thumb" src="${esc(r.photo)}" alt="" loading="lazy"><div><b>${esc(r.code)}</b><small>${esc(r.road)} · ${esc(r.date)}</small></div><span class="sev ${esc(r.severity)}">${esc(r.severity)}</span></a>`).join('')||'<p class="empty">No reports yet.</p>';
}
async function loadMap(){
  if(!sfMap)return;
  const data=await readJSON('api/admin_map.php'+(focusCode?'?focus='+encodeURIComponent(focusCode):''),{cache:'no-store'});
  if(!data)return;
  sfMap.draw(data.reports);
}
function renderUsers(){
  const roleFilter=$('#role-filter').value;
  document.querySelectorAll('#role-filter option').forEach(option=>{
    option.dataset.label=option.dataset.label||option.textContent;
    option.textContent=`${option.dataset.label} (${users.filter(user=>user.role===option.value).length})`;
  });
  const teamFilter=$('#team-filter'),isMaintenance=roleFilter==='maintenance',previousTeam=teamFilter.value;
  const maintenanceUsers=users.filter(user=>user.role==='maintenance');
  const lguTeams=teams.filter(team=>team.lgu_id);
  teamFilter.innerHTML=`<option value="">All LGU teams (${maintenanceUsers.length})</option><option value="none">No team assigned (${maintenanceUsers.filter(user=>!user.team_id).length})</option>`
    +lguTeams.map(team=>`<option value="${team.id}">${esc(team.lgu_name||team.name)} (${maintenanceUsers.filter(user=>Number(user.team_id)===Number(team.id)).length})</option>`).join('');
  teamFilter.value=[...teamFilter.options].some(option=>option.value===previousTeam)?previousTeam:'';
  teamFilter.disabled=!isMaintenance;
  const teamValue=isMaintenance?teamFilter.value:'';
  const visibleUsers=users.filter(user=>user.role===roleFilter&&(!teamValue||(teamValue==='none'?!user.team_id:Number(user.team_id)===Number(teamValue))));
  $('#users').innerHTML=visibleUsers.map(user=>`<tr><td>${esc(user.first_name)} ${esc(user.last_name)}</td><td>${esc(user.email)}</td><td>${esc(user.role)}</td>
    <td>${user.role==='maintenance'?`<small>${esc(user.team_name||'No team assigned')}</small><select data-team="${user.id}" aria-label="Maintenance Team for ${esc(user.first_name)} ${esc(user.last_name)}"><option value="">Assign to LGU team…</option>${teams.filter(team=>team.lgu_id).map(team=>`<option value="${team.id}" ${Number(user.team_id)===Number(team.id)?'selected':''}>${esc(team.name)}</option>`).join('')}</select>`:'—'}</td>
    <td>${user.role==='maintenance'?esc(user.lgu_name||'—'):'—'}</td>
    <td>${user.role==='maintenance'?`<button class="btn sm ghost" type="button" data-reset-password="${user.id}">Reset password</button>`:'—'}</td>
    <td><span class="pill ${Number(user.is_active)?'s-Resolved':'s-Rejected'}">${Number(user.is_active)?'Active':'Inactive'}</span>
    ${user.role==='admin'?'':`<button class="btn sm ghost" type="button" data-user="${user.id}" data-active="${Number(user.is_active)?0:1}">${Number(user.is_active)?'Deactivate':'Reactivate'}</button>`}</td></tr>`).join('')||`<tr><td colspan="7" class="empty">${teamValue?'No maintenance accounts found for this LGU team.':`No ${roleLabels[roleFilter]} accounts found.`}</td></tr>`;
}
function renderMaintenanceTeams(){
  const target=$('#team-directory-rows');if(!target)return;
  target.innerHTML=lgus.map(lgu=>{
    const team=teams.find(item=>Number(item.lgu_id)===Number(lgu.id));
    return `<tr><td><b>${esc(team?.name||`${lgu.name} Maintenance Team`)}</b></td><td>${esc(lgu.name)}</td>
      <td>${team?Number(team.active_members):0}${team&&Number(team.active_members)===0?'<small class="note">No active staff accounts; create one to open this queue.</small>':''}</td>
      <td>${team?`<button class="btn sm ghost" type="button" data-create-team-account="${team.id}">Add staff account</button>`:`<button class="btn sm ghost" type="button" data-create-lgu-team="${lgu.id}">Create team</button>`}</td></tr>`;
  }).join('')||'<tr><td colspan="4" class="empty">No LGUs are configured.</td></tr>';
}
function renderLguDirectory(){
  const target=$('#lgu-directory-rows');if(!target)return;
  target.innerHTML=lgus.map(lgu=>`<tr><td><b>${esc(lgu.name)}</b></td><td>${esc(lgu.lgu_type)}</td><td>${esc(lgu.location)}</td><td>${esc(lgu.administrative_area)}</td></tr>`).join('')
    ||'<tr><td colspan="4" class="empty">No LGUs are configured.</td></tr>';
}
function renderLogs(){
  const query=$('#q').value.trim().toLowerCase();
  document.querySelectorAll('#log-tabs [data-log]').forEach(button=>button.classList.toggle('on',button.dataset.log===logFilter));
  const visible=logs.filter(log=>(!logFilter||log.category===logFilter)&&(!query||`${log.code} ${log.event} ${log.actor} ${log.note}`.toLowerCase().includes(query)));
  $('#log-rows').innerHTML=visible.length?visible.map(log=>`<tr><td>${esc(log.at)}</td><td><a href="reports.php?q=${encodeURIComponent(log.code)}"><b>${esc(log.code)}</b></a></td><td>${esc(log.event)}</td><td>${esc(log.actor)}</td><td>${log.note?esc(log.note):'—'}</td></tr>`).join(''):'<tr><td colspan="5" class="empty">No log entries match.</td></tr>';
}
async function loadLogs(){
  if(document.body.dataset.page!=='logs')return;
  const data=await readJSON('api/admin_logs.php',{cache:'no-store'});if(!data)return;
  logs=data.logs;renderLogs();
}
async function load(){
  // Opening the Reports tab moves every newly submitted report to Under Review (one bulk query on the server).
  if(document.body.dataset.page==='reports'){
    try{await postJSON('api/admin_update.php',{action:'auto_review'})}catch(error){console.error(error)}
  }
  const [reportData,userData]=await Promise.all([readJSON('api/admin_reports.php'),readJSON('api/admin_users.php')]);
  if(!reportData||!userData)return;
  all=reportData.reports;teams=reportData.teams;lgus=reportData.lgus;users=userData.users;
  // Duplicates are grouped under the report they duplicate: one row per pothole, with combined severity.
  const codes=new Set(all.map(report=>report.code));
  reports=all.filter(report=>!report.duplicate_of||!codes.has(report.duplicate_of));
  reports.forEach(report=>{
    report.members=all.filter(member=>member.duplicate_of===report.code&&member!==report);
    report.sevs=[...new Set([report,...report.members].map(item=>item.severity))].sort((a,b)=>sevRank[a]-sevRank[b]);
  });
  renderReports();renderUsers();renderMaintenanceTeams();
  renderLguDirectory();
  $('#new-user-team').innerHTML='<option value="">Choose LGU Maintenance Team</option>'+teams.filter(team=>team.lgu_id).map(team=>`<option value="${team.id}">${esc(team.lgu_name)} · ${esc(team.name)}</option>`).join('');
  $('#new-team-lgu').innerHTML='<option value="">Choose LGU</option>'+lgus.map(lgu=>`<option value="${lgu.id}">${esc(lgu.name)}</option>`).join('');
}
async function updateReport(id,status,teamId){
  try{await postJSON('api/admin_update.php',{id,status,team_id:teamId});await Promise.all([load(),loadMap()])}
  catch(error){window.alert(error.message)}
}
async function loadSettings(){
  const data=await readJSON('api/admin_settings.php');if(!data)return;
  const form=$('#settings-form');
  for(const [key,value] of Object.entries(data.settings))if(form.elements[key])form.elements[key].value=value;
}

const initialSearch=new URLSearchParams(location.search).get('q');if(initialSearch)$('#q').value=initialSearch;
$('#q').addEventListener('keydown',event=>{if(event.key==='Enter'&&!['reports','logs'].includes(document.body.dataset.page)){event.preventDefault();location.href='reports.php?q='+encodeURIComponent(event.target.value.trim())}});
$('#f-status').insertAdjacentHTML('beforeend',`<optgroup label="Status groups">${Object.keys(statusGroups).map(group=>`<option value="group:${esc(group)}">${esc(group)}</option>`).join('')}</optgroup><optgroup label="Individual statuses">${statuses.map(status=>`<option value="${esc(status)}">${esc(status)}</option>`).join('')}</optgroup>`);
['input','change'].forEach(eventName=>['#q','#f-status','#f-sev'].forEach(selector=>$(selector).addEventListener(eventName,renderReports)));
$('#q').addEventListener('input',renderLogs);
$('#log-tabs').addEventListener('click',event=>{const button=event.target.closest('[data-log]');if(!button)return;logFilter=button.dataset.log;renderLogs()});
$('#role-filter').addEventListener('change',renderUsers);
$('#team-filter').addEventListener('change',renderUsers);
$('#rows').addEventListener('click',event=>{
  const button=event.target.closest('[data-status]');if(button)updateReport(Number(button.dataset.id),button.dataset.status);
  const merge=event.target.closest('[data-merge]');
  if(merge){
    const into=window.prompt(`Group ${merge.dataset.code} under which report? Enter that report's ID (for example SF-1001).`);
    if(into&&into.trim())postJSON('api/admin_update.php',{action:'merge_duplicate',id:Number(merge.dataset.merge),into:into.trim()}).then(load).catch(error=>window.alert(error.message));
  }
  const confirm=event.target.closest('[data-confirm-duplicate]');
  if(confirm)postJSON('api/admin_update.php',{action:'confirm_duplicate',id:Number(confirm.dataset.confirmDuplicate)}).then(load).catch(error=>window.alert(error.message));
});
$('#rows').addEventListener('change',event=>{
  const select=event.target.closest('[data-assign]');if(select&&select.value)updateReport(Number(select.dataset.assign),'Assigned',Number(select.value));
  const reassignment=event.target.closest('[data-reassign]');
  if(reassignment&&reassignment.value)postJSON('api/admin_update.php',{action:'reassign',id:Number(reassignment.dataset.reassign),team_id:Number(reassignment.value)}).then(load).catch(error=>window.alert(error.message));
  const manualLgu=event.target.closest('[data-manual-lgu]');
  if(manualLgu&&manualLgu.value&&window.confirm(`Manually assign ${manualLgu.options[manualLgu.selectedIndex].text} after reviewing this report's map location?`)){
    postJSON('api/admin_update.php',{action:'manual_assign_lgu',id:Number(manualLgu.dataset.manualLgu),lgu_id:Number(manualLgu.value)}).then(load).catch(error=>window.alert(error.message));
  }
});
$('#rows').addEventListener('submit',async event=>{
  const form=event.target.closest('[data-route]');if(!form)return;
  event.preventDefault();
  try{await postJSON('api/admin_update.php',{action:'route',id:Number(form.dataset.route),...Object.fromEntries(new FormData(form))});await load()}
  catch(error){window.alert(error.message)}
});
$('#users').addEventListener('click',async event=>{
  const button=event.target.closest('[data-user]');if(!button)return;
  try{await postJSON('api/admin_users.php',{action:'set_active',id:Number(button.dataset.user),active:Number(button.dataset.active)});await load()}
  catch(error){window.alert(error.message)}
});
$('#users').addEventListener('change',async event=>{
  const select=event.target.closest('[data-team]');if(!select)return;
  if(!select.value)return;
  try{await postJSON('api/admin_users.php',{action:'set_team',id:Number(select.dataset.team),team_id:Number(select.value)});await load()}
  catch(error){window.alert(error.message);await load()}
});
$('#team-directory-rows').addEventListener('click',async event=>{
  const createTeam=event.target.closest('[data-create-lgu-team]');
  if(createTeam){
    try{await postJSON('api/admin_users.php',{action:'create_lgu_team',lgu_id:Number(createTeam.dataset.createLguTeam)});await load()}
    catch(error){window.alert(error.message)}
    return;
  }
  const addAccount=event.target.closest('[data-create-team-account]');
  if(addAccount){
    $('#new-user-team').value=addAccount.dataset.createTeamAccount;
    $('#user-form').scrollIntoView({behavior:'smooth',block:'center'});
    $('#user-form').elements.first_name.focus();
  }
});
$('#user-form').addEventListener('submit',async event=>{
  event.preventDefault();const form=event.currentTarget,button=form.querySelector('[type=submit]'),message=$('#user-msg');
  message.classList.remove('show');button.disabled=true;
  try{
    const result=await postJSON('api/admin_users.php',{action:'create_maintenance',...Object.fromEntries(new FormData(form))});
    if(result.ok){form.reset();$('#role-filter').value='maintenance';$('#team-filter').value='';await load()}
  }catch(error){message.textContent=error.message;message.classList.add('show')}
  button.disabled=false;
});
$('#team-form').addEventListener('submit',async event=>{
  event.preventDefault();const form=event.currentTarget,message=$('#team-msg');
  message.classList.remove('show');
  try{await postJSON('api/admin_users.php',{action:'create_lgu_team',...Object.fromEntries(new FormData(form))});form.reset();await load()}
  catch(error){message.textContent=error.message;message.classList.add('show')}
});
document.querySelectorAll('#user-form input[name=first_name],#user-form input[name=last_name]').forEach(input=>input.addEventListener('input',()=>{
  const start=input.selectionStart,end=input.selectionEnd,fixed=input.value.replace(/(^|[\s\-'’])(\p{L})/gu,(m,sep,letter)=>sep+letter.toUpperCase());
  if(fixed!==input.value){input.value=fixed;try{input.setSelectionRange(start,end)}catch(e){}}
}));
let generatedCredentials=[];
function showCredentials(credentials){
  generatedCredentials=credentials;
  $('#credential-rows').innerHTML=credentials.map(item=>`<tr><td>${esc(item.lgu||'Maintenance Team')}</td><td>${esc(item.username)}</td><td><code>${esc(item.password)}</code></td></tr>`).join('');
  $('#generated-credentials').hidden=credentials.length===0;
}
$('#provision-lgu-accounts').addEventListener('click',async event=>{
  const button=event.currentTarget,message=$('#provision-msg');
  button.disabled=true;message.textContent='';message.classList.remove('show');
  try{
    const result=await postJSON('api/admin_users.php',{action:'provision_lgu_accounts'});
    if(result.created){showCredentials(result.credentials);message.textContent=`Created ${result.created} of ${result.total} LGU accounts. Save the credentials now.`}
    else message.textContent=`All ${result.total} LGUs already have an account; no duplicates were created.`;
    message.classList.add('show');await load();
  }catch(error){message.textContent=error.message;message.classList.add('show')}
  button.disabled=false;
});
$('#reset-all-maintenance-passwords').addEventListener('click',async event=>{
  if(!window.confirm('Reset every Maintenance Team password? All current maintenance passwords will stop working. New plain-text passwords will be shown once.'))return;
  const button=event.currentTarget,message=$('#manage-msg');
  button.disabled=true;message.textContent='';message.classList.remove('show');
  try{
    const result=await postJSON('api/admin_users.php',{action:'reset_all_maintenance_passwords'});
    showCredentials(result.credentials);message.textContent=`Reset ${result.count} Maintenance Team account passwords. Save the credentials now.`;
    message.classList.add('show');
  }catch(error){message.textContent=error.message;message.classList.add('show')}
  button.disabled=false;
});
$('#download-credentials').addEventListener('click',()=>{
  const quote=value=>{
    let text=String(value??'');
    if(/^[\s\u0000-\u001f]*[=+\-@]/.test(text))text=`'${text}`;
    return `"${text.replace(/"/g,'""')}"`;
  };
  const csv='\uFEFF'+[['LGU','Username / login email','Temporary password'],...generatedCredentials.map(item=>[item.lgu,item.username,item.password])]
    .map(row=>row.map(quote).join(',')).join('\r\n');
  const link=document.createElement('a');link.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));link.download='streetfix-lgu-maintenance-credentials.csv';link.click();URL.revokeObjectURL(link.href);
});
$('#users').addEventListener('click',async event=>{
  const button=event.target.closest('[data-reset-password]');if(!button)return;
  if(!window.confirm('Reset this maintenance account password? The new password will be shown once.'))return;
  try{
    const result=await postJSON('api/admin_users.php',{action:'reset_password',id:Number(button.dataset.resetPassword)});
    showCredentials(result.credentials);
    $('#generated-credentials').scrollIntoView({behavior:'smooth',block:'center'});
  }catch(error){window.alert(error.message)}
});
$('#settings-form').addEventListener('submit',async event=>{
  event.preventDefault();const form=event.currentTarget,message=$('#settings-msg'),button=form.querySelector('[type=submit]');
  message.classList.remove('show');button.disabled=true;
  try{await postJSON('api/admin_settings.php',Object.fromEntries(new FormData(form)));message.textContent='Agency settings saved.';message.classList.add('show')}
  catch(error){message.textContent=error.message;message.classList.add('show')}
  button.disabled=false;
});
$('#export').addEventListener('click',()=>{
  const quote=value=>{
    let text=String(value??'');
    if(/^[\s\u0000-\u001f]*[=+\-@]/.test(text))text=`'${text}`;
    return `"${text.replace(/"/g,'""')}"`;
  };
  const rows=[['Report','Road','Address','Reporter','Severity','Status','No. of reports','Grouped duplicate reports','Maintenance team','Responsible agency','Agency contact','Latitude','Longitude','Repair notes','Feedback rating','Feedback comments'],
    ...filtered().map(r=>[r.code,r.road,r.location_address,r.by,r.sevs.join(' - '),r.status,r.members.length+1,r.members.map(m=>m.code).join(' '),r.team_name,r.agency,r.agency_email,r.lat,r.lng,r.repair_notes,r.rating,r.feedback])];
  const csv='\uFEFF'+rows.map(row=>row.map(quote).join(',')).join('\r\n');
  const link=document.createElement('a');link.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));link.download='streetfix-reports.csv';link.click();URL.revokeObjectURL(link.href);
});
/* jsPDF (~400 KB) is only downloaded when someone actually exports a PDF. */
let pdfLibs;
const loadPdfLibs=()=>pdfLibs||(pdfLibs=['https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js','https://unpkg.com/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js'].reduce((chain,src)=>chain.then(()=>new Promise((ok,fail)=>{const s=document.createElement('script');s.src=src;s.onload=ok;s.onerror=()=>fail(new Error('load failed'));document.head.appendChild(s)})),Promise.resolve()).catch(error=>{pdfLibs=null;throw error}));
$('#export-pdf').addEventListener('click',async()=>{
try{
  try{await loadPdfLibs()}catch(e){}
  if(!window.jspdf||!window.jspdf.jsPDF){window.alert('The PDF library could not be loaded. Check your internet connection and try again.');return}
  const rows=filtered();
  const doc=new window.jspdf.jsPDF({orientation:'landscape',unit:'pt',format:'a4'});
  const status=$('#f-status').value||'All',severity=$('#f-sev').value||'All',search=$('#q').value.trim();
  doc.setFontSize(16);doc.text('StreetFix - Pothole Reports',40,40);
  doc.setFontSize(9);
  doc.text(`Generated: ${new Date().toLocaleString()}   |   Status: ${status}   |   Severity: ${severity}${search?`   |   Search: ${search}`:''}   |   Total: ${rows.length} pothole(s)`,40,56);
  doc.autoTable({
    startY:68,styles:{fontSize:8,cellPadding:3,overflow:'linebreak'},headStyles:{fillColor:[30,41,59]},
    head:[['Report','Road / Address','Reporter','Severity','Status','No. of reports','Team / Agency','Coordinates','Repair notes','Rating']],
    body:rows.map(r=>[r.code,[r.road,r.location_address].filter(Boolean).join('\n'),r.by,r.sevs.join(' - '),r.status,r.members.length+1,[r.team_name,r.agency].filter(Boolean).join('\n'),`${r.lat}, ${r.lng}`,r.repair_notes||'',r.rating?`${r.rating}/5${r.feedback?' - '+r.feedback:''}`:'']),
    columnStyles:{1:{cellWidth:130},8:{cellWidth:110}},
    didDrawPage:()=>{doc.setFontSize(8);doc.text(`Page ${doc.internal.getNumberOfPages()}`,doc.internal.pageSize.getWidth()-70,doc.internal.pageSize.getHeight()-20)}
  });
  doc.save('streetfix-reports.pdf');
}catch(error){console.error(error);window.alert('PDF export failed: '+error.message)}
});
$('#logout').addEventListener('click',async()=>{await fetch('api/logout.php',{method:'POST'});location.href='../auth/login.php'});
if($('#map')&&document.body.dataset.page==='map'){
map=L.map('map').setView([14.7366,120.9600],13);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
sfMap=StreetFixMap.create(map,code=>{const r=reports.find(item=>item.code===code);return r&&{reporter:[...new Set([r,...r.members].map(item=>item.by))].join(', '),date:r.date,address:r.location_address,description:r.description,photos:[r,...r.members].flatMap(item=>item.photos||[item.photo])}});
}
Promise.all([load(),loadSettings(),loadMap(),loadLogs()]).then(()=>{
  // "View on Map" from the Reports tab: zoom to that report and open its popup (needs both pins and report details loaded).
  const focusTarget=all.find(item=>item.code===focusCode);
  if(focusCode&&sfMap&&!sfMap.open((focusTarget&&focusTarget.duplicate_of)||focusCode))window.alert(focusCode+' could not be found on the map.');
}).catch(error=>{window.alert(error.message)});
/* Poll only while the tab is visible (background tabs used to hit the server every few seconds). */
const poll=(fn,ms)=>window.setInterval(()=>{if(!document.hidden)fn().catch(error=>console.error(error))},ms);
poll(load,60000);poll(loadLogs,60000);poll(loadMap,60000);
