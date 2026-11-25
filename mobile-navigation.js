(() => {
 const trigger=document.getElementById('mobileMore');
 const dialog=document.getElementById('mobileNavigation');
 if(!trigger||!dialog)return;
 if(typeof dialog.showModal!=='function')return;
 const links=dialog.querySelector('.mobile-navigation-links');
 document.querySelectorAll('.sidebar-nav>a').forEach(source=>{
  const link=source.cloneNode(true);
  link.removeAttribute('id');link.removeAttribute('data-label');
  link.setAttribute('aria-label',source.getAttribute('aria-label')||source.textContent.trim());
  if(source.classList.contains('active'))link.setAttribute('aria-current','page');
  links.append(link);
 });
 const mainPages=['dashboard.php','tasks.php','calendar.php','study_coach.php'];
 const current=document.querySelector('.sidebar-nav>a.active');
 if(current&&!mainPages.includes(current.getAttribute('href')))trigger.classList.add('active');
 trigger.addEventListener('click',()=>dialog.showModal());
 document.getElementById('mobileNavigationClose').addEventListener('click',()=>dialog.close());
 dialog.addEventListener('click',event=>{
  const box=dialog.getBoundingClientRect();
  if(event.target===dialog&&(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom))dialog.close();
 });
 window.matchMedia('(max-width:768px)').addEventListener('change',event=>{if(!event.matches&&dialog.open)dialog.close();});
 // Keep the dashboard table's field names alongside its values on phone cards.
 document.querySelectorAll('.dashboard-task-overview .modern-table').forEach(table=>{
  const headings=Array.from(table.querySelectorAll('thead th'),th=>th.textContent.trim());
  table.querySelectorAll('tbody tr').forEach(row=>Array.from(row.cells).forEach((cell,i)=>{cell.dataset.label=headings[i]||'';}));
 });
 document.body.classList.add('mobile-nav-ready');
})();
