const $=s=>document.querySelector(s);
const esc=s=>String(s??'').replace(/[&<>\"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[c]));

async function postJSON(url,payload){
  const response=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  let data;try{data=await response.json()}catch{data={ok:false,error:'The server did not return a valid response.'}}
  if(!response.ok&&data.ok===undefined)data.ok=false;
  return data;
}
function showMessage(el,text,success=false){el.textContent=text;el.classList.add('show');el.classList.toggle('ok',success)}
