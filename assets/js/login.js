(()=>{
  const form=document.getElementById('loginForm');
  const message=document.getElementById('message');
  const button=document.getElementById('submit');
  form.addEventListener('submit',async event=>{
    event.preventDefault(); message.className=''; message.textContent=''; button.disabled=true;
    try{
      const response=await fetch('api/certificate-auth.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'login',username:form.username.value.trim(),password:form.password.value})});
      const data=await response.json();
      if(!response.ok||!data.ok)throw new Error(data.message||'Sign in failed.');
      message.className='ok'; message.textContent='Signed in. Opening FleetDesk…';
      window.location.replace('index.php');
    }catch(error){message.textContent=error.message||'Could not reach FleetDesk. Please try again.';button.disabled=false;}
  });
})();
