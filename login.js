(() => {
 const button = document.getElementById('passwordVisibility');
 const password = document.getElementById('adminPassword');
 button.addEventListener('click', () => {
  const visible = password.type === 'password';
  password.type = visible ? 'text' : 'password';
  button.textContent = visible ? 'Hide' : 'Show';
  button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
  button.setAttribute('aria-pressed', String(visible));
 });
})();
