(() => {
 const form = document.querySelector('.settings-security-form');
 const fields = form.querySelectorAll('input[autocomplete]');
 document.getElementById('showPasswords').addEventListener('change',event => {
  fields.forEach(field => { field.type=event.target.checked?'text':'password'; });
 });
 const password=document.getElementById('newPassword');
 const confirm=document.getElementById('confirmPassword');
 function validate(){confirm.setCustomValidity(confirm.value && confirm.value!==password.value?'Your new passwords do not match.':'');}
 password.addEventListener('input',validate);
 confirm.addEventListener('input',validate);
})();
