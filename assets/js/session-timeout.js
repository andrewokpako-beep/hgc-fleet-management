(()=>{
'use strict';
const activityUrl='api/session-activity.php';let timeoutMs=30*60*1000,lastAction=Date.now(),lastPing=0,redirecting=false;
function signInAgain(){if(redirecting)return;redirecting=true;location.replace('login.php?session=expired')}
async function ping(){if(redirecting)return;try{const r=await fetch(activityUrl,{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});if(r.status===401||r.redirected){signInAgain();return}const d=await r.json();if(!r.ok||!d.ok){if(d.session_expired||r.status===401)signInAgain();return}timeoutMs=Math.max(5,Math.min(480,Number(d.idle_timeout_minutes)||30))*60000;lastPing=Date.now()}catch{}}
function activity(){const now=Date.now();if(now-lastAction>=timeoutMs){signInAgain();return}lastAction=now;if(now-lastPing>45000)ping()}
(async()=>{try{const r=await fetch(activityUrl,{credentials:'same-origin',cache:'no-store'});if(r.status===401||r.redirected){signInAgain();return}const d=await r.json();if(!r.ok||!d.ok){if(d.session_expired)signInAgain();return}timeoutMs=Math.max(5,Math.min(480,Number(d.idle_timeout_minutes)||30))*60000;lastAction=Date.now();ping()}catch{}})();
['keydown','pointerdown','touchstart','wheel','click'].forEach(name=>document.addEventListener(name,activity,{passive:true}));document.addEventListener('visibilitychange',()=>{if(!document.hidden&&Date.now()-lastAction>=timeoutMs)signInAgain()});window.setInterval(()=>{if(Date.now()-lastAction>=timeoutMs)signInAgain()},1000);
})();
