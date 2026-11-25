(() => {
let lastInteraction=Date.now();
for(const event of ['pointerdown','keydown','scroll','touchstart']) window.addEventListener(event,()=>{lastInteraction=Date.now();},{passive:true});
async function ping(){
 if(document.hidden||Date.now()-lastInteraction>120000)return;
 try{await fetch('presence.php',{method:'POST',body:new URLSearchParams({csrf_token:window.appPreferences.csrf}),credentials:'same-origin'});}catch(e){}
}
document.addEventListener('visibilitychange',()=>{if(!document.hidden){lastInteraction=Date.now();ping();}});
ping();setInterval(ping,45000);
})();