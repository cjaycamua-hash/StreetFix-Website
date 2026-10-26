const statusList=['Submitted','Under Review','Verified','Assigned','In Progress','Resolved','Rejected','Duplicate','Cancelled'];
const statusColor={'Submitted':'var(--me)','Under Review':'var(--me)','Verified':'var(--pu)','Assigned':'var(--bl)','In Progress':'var(--bl)','Resolved':'var(--lo)','Rejected':'var(--gray)','Duplicate':'var(--gray)','Cancelled':'var(--gray)'};
const severityColor={High:'var(--hi)',Medium:'var(--me)',Low:'var(--lo)'};

if($('#citizen-dashboard')||$('#reports')){
  const api='api/';
  const colors=statusColor;
  const start=[14.7366,120.9600];
  let reports=[],map,sfMap,picker,selection,mapFitted=false,lastAddressSearch=0;
  const focusCode=new URLSearchParams(location.search).get('report');
  const dialog=$('#report-dialog');

  async function getJSON(url,options={}){
    const response=await fetch(url,options);
    if(response.status===401){location.href='../auth/login.php';return null}
    const data=await response.json();
    if(!response.ok)throw new Error(data.error||'Request failed.');
    return data;
  }
  async function logout(){await fetch(api+'logout.php',{method:'POST'});location.href='../auth/login.php'}
  $('#logout').addEventListener('click',logout);

  function initMap(){
    map=L.map('map').setView(start,13);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
    sfMap=StreetFixMap.create(map,code=>{const r=reports.find(item=>item.code===code);return r&&{reporter:'You',date:r.date,address:r.location_address,description:r.description,photos:r.photos}});
  }
  function setPin(layer,point){
    if(selection)selection.setLatLng(point);
    else selection=L.circleMarker(point,{radius:9,color:'#fff',weight:3,fillColor:'#f24333',fillOpacity:1}).addTo(layer);
    $('#lat').value=Number(point.lat).toFixed(6);$('#lng').value=Number(point.lng).toFixed(6);
    $('#loc-text').textContent=`Selected location: ${Number(point.lat).toFixed(5)}, ${Number(point.lng).toFixed(5)}`;
  }
  function requestGPS(){
    if($('#lat').value)return;
    if(!navigator.geolocation){$('#loc-text').textContent='GPS is not available. Tap the map to pin the location.';return}
    $('#loc-text').textContent='Requesting your location…';
    navigator.geolocation.getCurrentPosition(position=>{
      const point={lat:position.coords.latitude,lng:position.coords.longitude};setPin(picker,point);picker.setView(point,17);
    },()=>{$('#loc-text').textContent='Location permission was denied or unavailable. Tap the map or search for an address.'},{enableHighAccuracy:true,timeout:12000});
  }
  async function loadReports(){
    const data=await getJSON(api+'reports_mine.php');if(!data)return;
    reports=data.reports;
    const count=(...statuses)=>reports.filter(r=>statuses.includes(r.status)).length;
    $('#t-total').textContent=reports.length;
    $('#t-pending').textContent=count('Submitted','Under Review','Verified');
    $('#t-active').textContent=count('Verified','Assigned','In Progress');
    $('#t-done').textContent=count('Resolved');
    $('#rows').innerHTML=reports.length?reports.map(r=>`<tr><td>${esc(r.code)}</td><td>${esc(r.road)}${r.location_address?`<small>${esc(r.location_address)}</small>`:''}<a class="photo-link map-link" href="map.php?report=${encodeURIComponent(r.code)}">View on Map</a>${r.description?`<small>${esc(r.description)}</small>`:''}${r.assigned_lgu?`<small>Assigned LGU: ${esc(r.assigned_lgu)}</small>`:''}${r.lgu_assignment_status==='manual_review'?'<small>Location is awaiting manual LGU review.</small>':''}</td>
      <td><span class="sev ${esc(r.severity)}">${esc(r.severity)}</span></td><td><span class="pill s-${r.status.replace(/ /g,'-')}">${esc(r.status)}</span></td>
      <td>${esc(r.date)}</td><td>${(r.photos||[r.photo]).map((photo,index)=>`<a class="photo-link" href="${esc(photo)}" target="_blank" rel="noopener">Photo ${index+1}</a>`).join('<br>')}<br><button class="btn sm ghost" type="button" data-history="${r.id}">History</button></td>
      <td>${r.status==='Resolved'?(r.rating?`Rated ${r.rating}/5`:`<button class="btn sm ghost" type="button" data-feedback="${r.id}">Rate repair</button>`):(r.can_cancel?`<button class="btn sm ghost" type="button" data-cancel="${r.id}" data-code="${esc(r.code)}" data-delete="${r.cancel_deletes?1:0}">${r.cancel_deletes?'Delete report':'Cancel report'}</button>`:'—')}</td></tr>`).join('')
      :'<tr><td colspan="7" class="empty">No reports yet. Select “Report a pothole” to file your first report.</td></tr>';
    const used=statusList.filter(s=>count(s));
    $('#bar').innerHTML=used.map(s=>`<div title="${esc(s)}: ${count(s)}" style="width:${count(s)/Math.max(reports.length,1)*100}%;background:${colors[s]}"></div>`).join('');
    $('#legend').innerHTML=used.map(s=>`<li><span>${esc(s)}</span><b>${count(s)}</b></li>`).join('')||'<li>No reports yet.</li>';
    $('#recent').innerHTML=reports.slice(0,4).map(r=>`<a class="recent-item" href="#map-section" data-open="${esc(r.code)}"><img class="thumb" src="${esc((r.photos&&r.photos[0])||r.photo)}" alt="" loading="lazy"><div><b>${esc(r.code)}</b><small>${esc(r.road)} · ${esc(r.date)}${r.assigned_lgu?` · ${esc(r.assigned_lgu)}`:''}</small></div><span class="pill s-${r.status.replace(/ /g,'-')}">${esc(r.status)}</span></a>`).join('')||'<p class="empty">No activity yet. Your reports will appear here.</p>';
  }
  async function loadMap(){
    const data=await getJSON(api+'reports_map.php'+(focusCode?'?focus='+encodeURIComponent(focusCode):''),{cache:'no-store'});if(!data)return;
    sfMap.draw(data.reports);
  }
  async function loadNotifications(){
    const data=await getJSON(api+'notifications.php');if(!data)return;
    const nav=$('#nav-notif');if(nav)nav.textContent=data.unread?`Notifications (${data.unread})`:'Notifications';
    const list=$('#notifs'),readAll=$('#read-all');
    if(list)list.innerHTML=data.notifications.length?data.notifications.map(item=>`<li class="${item.unread?'new':''}">${esc(item.message)}<small>${esc(item.date)}</small></li>`).join(''):'<li class="empty">No updates yet. You will be notified when your report changes.</li>';
    if(readAll)readAll.hidden=!data.unread;
  }
  async function refresh(){try{await Promise.all([loadReports(),loadMap(),loadNotifications()])}catch(error){console.error(error)}}

  initMap();
  const readAll=$('#read-all');
  if(readAll)readAll.addEventListener('click',async()=>{await postJSON(api+'notifications.php',{});loadNotifications()});
  document.querySelectorAll('[data-new-report]').forEach(button=>button.addEventListener('click',()=>{
    dialog.showModal();
    if(!picker){
      picker=L.map('pick-map').setView(map.getCenter(),map.getZoom());
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(picker);
      picker.on('click',event=>setPin(picker,event.latlng));
    }
    window.setTimeout(()=>picker.invalidateSize(),60);
    requestGPS();
  }));
  $('#cancel').addEventListener('click',()=>dialog.close());
  $('#use-gps').addEventListener('click',()=>{ $('#lat').value=''; requestGPS(); });
  $('#find-address').addEventListener('click',async()=>{
    const query=$('#location-address').value.trim();
    if(!query){$('#loc-text').textContent='Enter an address or nearby place first.';return}
    if(Date.now()-lastAddressSearch<1100){$('#loc-text').textContent='Please wait a moment before searching again.';return}
    lastAddressSearch=Date.now();
    $('#loc-text').textContent='Searching OpenStreetMap…';
    try{
      const response=await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(query)}`,{headers:{Accept:'application/json'}});
      if(!response.ok)throw new Error('Address search is temporarily unavailable.');
      const matches=await response.json();
      if(!matches.length)throw new Error('No matching address found. Try a more specific search or tap the map.');
      const point={lat:Number(matches[0].lat),lng:Number(matches[0].lon)};
      $('#location-address').value=String(matches[0].display_name||query).slice(0,255);
      setPin(picker,point);picker.setView(point,17);
    }catch(error){$('#loc-text').textContent=error.message}
  });
  /* Photo picker: every new choice is ADDED to the photos already chosen (max 5); each thumbnail has an × to remove it. */
  const MAX_PHOTOS=5,photoInput=$('#photos'),photoList=$('#photo-previews'),photoKey=file=>file.name+'|'+file.size+'|'+file.lastModified;
  let chosen=[];
  // Keep the real <input> in step with the chosen photos so the browser's own label ("2 files", "No file chosen") is correct.
  function syncPhotoInput(){
    try{const dt=new DataTransfer();chosen.forEach(file=>dt.items.add(file));photoInput.files=dt.files}
    catch(e){if(!chosen.length)photoInput.value=''}
  }
  function renderPhotos(){
    syncPhotoInput();
    photoList.innerHTML='';photoList.style.cssText='display:flex;flex-wrap:wrap;gap:10px;margin-top:8px';   // inline so the size never depends on a cached stylesheet
    chosen.forEach((file,index)=>{
      const item=document.createElement('div');item.className='photo-chip';item.style.cssText='position:relative;width:64px;height:64px';
      const img=document.createElement('img');img.alt='Selected photo '+(index+1);img.style.cssText='display:block;width:100%;height:100%;object-fit:cover;border-radius:8px';img.src=URL.createObjectURL(file);img.onload=()=>URL.revokeObjectURL(img.src);
      const remove=document.createElement('button');remove.type='button';remove.className='photo-remove';remove.style.cssText='position:absolute;top:-7px;right:-7px;width:22px;height:22px;padding:0;line-height:1';remove.textContent='\u00d7';remove.setAttribute('aria-label','Remove photo '+(index+1));
      remove.addEventListener('click',()=>{chosen.splice(index,1);renderPhotos()});
      item.append(img,remove);photoList.append(item);
    });
  }
  photoInput.addEventListener('change',()=>{
    const message=$('#report-msg');message.classList.remove('show');
    let full=false;
    for(const file of Array.from(photoInput.files)){
      if(chosen.some(item=>photoKey(item)===photoKey(file)))continue;
      if(file.size>8*1024*1024){showMessage(message,'Each photo must be no larger than 8 MB.');continue}
      if(chosen.length>=MAX_PHOTOS){full=true;continue}
      chosen.push(file);
    }
    if(full)showMessage(message,'You can add up to 5 photos. Remove one to add another.');
    renderPhotos();
  });
  $('#report-form').addEventListener('reset',()=>{chosen=[];renderPhotos()});
  $('#report-form').addEventListener('submit',async event=>{
    event.preventDefault();
    const form=event.currentTarget,message=$('#report-msg'),button=form.querySelector('[type=submit]');
    message.classList.remove('show');
    if(!form.reportValidity())return;
    const photos=chosen;
    if(photos.length<1||photos.length>5){showMessage(message,'Choose between 1 and 5 pothole photos.');return}
    if(Array.from(photos).some(photo=>photo.size>8*1024*1024)){showMessage(message,'Each photo must be no larger than 8 MB.');return}
    if(!$('#lat').value){showMessage(message,'Pin the pothole location on the map or use GPS.');return}
    button.disabled=true;
    try{
      const body=new FormData(form);body.delete('photos[]');for(const photo of await Promise.all(Array.from(photos).map(window.sfShrinkImage)))body.append('photos[]',photo);
      const response=await fetch(api+'report_create.php',{method:'POST',body});
      const result=await response.json();
      if(!response.ok||!result.ok){showMessage(message,result.error||'Could not save this report.');button.disabled=false;return}
      dialog.close();form.reset();if(selection)selection.remove();selection=null;$('#lat').value=$('#lng').value='';$('#loc-text').textContent='Tap the map to pin the pothole.';
      window.alert(`Report ${result.code} was reported.`);
      await refresh();
    }catch(error){showMessage(message,'The report could not be submitted. Check the server connection.');console.error(error)}
    button.disabled=false;
  });
  $('#rows').addEventListener('click',async event=>{
    const history=event.target.closest('[data-history]'),feedback=event.target.closest('[data-feedback]'),cancel=event.target.closest('[data-cancel]');
    if(history){
      try{
        const data=await getJSON(`${api}history.php?report_id=${encodeURIComponent(history.dataset.history)}`);
        if(data)$('#history-items').innerHTML=data.history.map(item=>`<li><b>${esc(item.status)}</b>${item.note?` — ${esc(item.note)}`:''}<small>${esc(item.created_at)}</small></li>`).join('')||'<li>No history yet.</li>';
        $('#history-dialog').showModal();
      }catch(error){window.alert(error.message)}
    }
    if(cancel){
      const removes=cancel.dataset.delete==='1';
      const question=removes
        ?`Delete report ${cancel.dataset.code}?\n\nNo one has reviewed it yet, so it will be removed completely, including its photos. This cannot be undone.`
        :`Cancel report ${cancel.dataset.code}?\n\nIt is already being reviewed, so it will stay on record marked as Cancelled and no repair will be scheduled. This cannot be undone.`;
      if(!window.confirm(question))return;
      cancel.disabled=true;
      try{
        const result=await postJSON(api+'report_cancel.php',{report_id:cancel.dataset.cancel});
        if(!result.ok){window.alert(result.error||'The report could not be cancelled.');cancel.disabled=false}
        await refresh();
      }catch(error){window.alert('The report could not be cancelled. Check the server connection.');cancel.disabled=false}
    }
    if(feedback){$('#feedback-report').value=feedback.dataset.feedback;$('#feedback-msg').classList.remove('show');$('#feedback-dialog').showModal()}
  });
  $('#recent').addEventListener('click',event=>{
    const item=event.target.closest('[data-open]');if(!item)return;
    event.preventDefault();
    const code=item.dataset.open,pin=sfMap.open(code);
    if(pin){$('#map-section').scrollIntoView({behavior:'smooth',block:'center'})}
    else{$('#reports').scrollIntoView({behavior:'smooth',block:'start'})}
  });
  $('#close-history').addEventListener('click',()=>$('#history-dialog').close());
  $('#cancel-feedback').addEventListener('click',()=>$('#feedback-dialog').close());
  $('#feedback-form').addEventListener('submit',async event=>{
    event.preventDefault();const form=event.currentTarget,button=form.querySelector('[type=submit]'),message=$('#feedback-msg');
    button.disabled=true;message.classList.remove('show');
    try{
      const result=await postJSON(api+'feedback.php',Object.fromEntries(new FormData(form)));
      if(result.ok){$('#feedback-dialog').close();form.reset();await loadReports()}
      else showMessage(message,result.error||'Feedback was not saved.');
    }catch(error){showMessage(message,error.message)}
    button.disabled=false;
  });
  refresh().then(()=>{
    // "View on Map" from My reports: open the pothole's pin (a duplicate resolves to the original's pin).
    if(!focusCode||!sfMap)return;
    const mine=reports.find(r=>r.code===focusCode);
    sfMap.open((mine&&mine.map_code)||focusCode);
  });window.setInterval(()=>{if(!document.hidden)refresh()},60000);
}
