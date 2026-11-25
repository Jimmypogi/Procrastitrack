(() => {
 const button=document.getElementById('adminThemeToggle');if(!button)return;
 const paint=()=>{const dark=document.documentElement.dataset.theme==='dark';button.setAttribute('aria-pressed',String(dark));button.textContent=dark?'Light mode':'Dark mode';};
 button.addEventListener('click',()=>{const theme=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=theme;try{localStorage.setItem('pt-admin-theme',theme);}catch(e){}paint();});
 window.addEventListener('storage',event=>{if(event.key==='pt-admin-theme'){document.documentElement.dataset.theme=event.newValue==='dark'?'dark':'light';paint();}});paint();
})();