(() => {
 const form=document.querySelector('form.s-form-grid');
 if(!form)return;
 const submit=form.querySelector('button[name="save"]');
 const status=document.getElementById('aiPrioritySuggestStatus');
 let submitting=false;
 form.addEventListener('submit',event=>{
  if(submitting){event.preventDefault();return;}
  if(!form.reportValidity()){event.preventDefault();return;}
  submitting=true;
  submit.disabled=true;
  submit.textContent='Creating…';
  submit.setAttribute('aria-busy','true');
  if(status)status.textContent='Creating your task. Priority is being assigned before it is saved.';
 });
})();
