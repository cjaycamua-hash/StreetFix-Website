// Name fields: capitalize the first letter of every word as the person types.
function capitalizeName(value){return value.replace(/(^|[\s\-'’])(\p{L})/gu,(m,sep,letter)=>sep+letter.toUpperCase())}
document.querySelectorAll('input[name=first_name],input[name=middle_name],input[name=last_name]').forEach(input=>{
  input.addEventListener('input',()=>{
    const start=input.selectionStart,end=input.selectionEnd,fixed=capitalizeName(input.value);
    if(fixed!==input.value){input.value=fixed;try{input.setSelectionRange(start,end)}catch(e){}}
  });
});

const birthdate=$('#birthdate');
if(birthdate){
  birthdate.max=new Date().toISOString().slice(0,10);
  birthdate.addEventListener('input',()=>{
    const date=new Date(`${birthdate.value}T00:00:00`);
    if(Number.isNaN(date.getTime())){$('#age').value='';return}
    const now=new Date();let age=now.getFullYear()-date.getFullYear();
    if(now.getMonth()<date.getMonth()||(now.getMonth()===date.getMonth()&&now.getDate()<date.getDate()))age--;
    $('#age').value=age>=0?age:'';
  });
}

for(const id of ['#login-form','#register-form']){
  const form=$(id);if(!form)continue;
  if(id==='#login-form'&&new URLSearchParams(location.search).has('registered'))showMessage($('#msg'),'Account created. Please log in.',true);
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    const message=$('#msg');message.classList.remove('show','ok');
    if(!form.reportValidity())return;
    const registration=id==='#register-form',payload=Object.fromEntries(new FormData(form));
    if(registration&&payload.password!==payload.confirm){showMessage(message,'Passwords do not match.');return}
    const button=form.querySelector('[type=submit]');button.disabled=true;
    try{
      const result=await postJSON(registration?'api/register.php':'api/login.php',payload);
      if(result.ok){if(!registration){try{sessionStorage.setItem('sf_tab_session','1')}catch(e){}}location.href=result.redirect}
      else showMessage(message,result.error||'Unable to continue.');
    }catch{showMessage(message,'Could not reach StreetFix. Check that Apache and MySQL are running.')}
    button.disabled=false;
  });
}
