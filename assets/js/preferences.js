(() => {
'use strict';
if (window.preferencesReady) return;
window.preferencesReady = true;
const config = window.appPreferences;
const originals = new WeakMap();
const rows = [
['More','Más','Plus'],
['Where to next?','¿Adónde vamos?','Où aller ensuite ?'],
['My profile','Mi perfil','Mon profil'],
['Sign out','Cerrar sesión','Se déconnecter'],
['Study Sessions','Sesiones de estudio','Séances d’étude'],
['Log Delay','Registrar retraso','Consigner un retard'],
['MAKE IT YOURS','A TU MANERA','À VOTRE IMAGE'],
['A calmer workspace, set up your way.','Un espacio más tranquilo, a tu manera.','Un espace plus serein, à votre image.'],
['Manage profile','Gestionar perfil','Gérer le profil'],
['YOUR WORKSPACE','TU ESPACIO','VOTRE ESPACE'],
['Appearance','Apariencia','Apparence'],
['Password & security','Contraseña y seguridad','Mot de passe et sécurité'],
['Small changes. Better focus.','Pequeños cambios. Más concentración.','Petits changements. Meilleure concentration.'],
['Choose the look and updates that work for you.','Elige el aspecto y las alertas que prefieras.','Choisissez votre apparence et vos notifications.'],
['Find a comfortable view for your study sessions.','Elige una vista cómoda para estudiar.','Choisissez un affichage confortable pour étudier.'],
['Auto-saved','Guardado automático','Enregistrement automatique'],
['Light','Claro','Clair'],
['Dark','Oscuro','Sombre'],
['Color theme','Tema de color','Thème de couleur'],
['Settings sections','Secciones de ajustes','Rubriques des paramètres'],
['Your appearance preference follows your account.','Tu preferencia de apariencia se guarda en tu cuenta.','Votre préférence d’apparence est liée à votre compte.'],
['A little nudge, when you need it.','Un pequeño recordatorio cuando lo necesites.','Un petit rappel quand vous en avez besoin.'],
['Receive task updates and overdue alerts by email.','Recibe novedades de tareas y alertas de retraso por correo.','Recevez les mises à jour des tâches et les alertes de retard par e-mail.'],
['Task reminders','Recordatorios de tareas','Rappels de tâches'],
['Allow deadline reminders for your tasks.','Permite recordatorios de fechas límite para tus tareas.','Autorisez les rappels d’échéance pour vos tâches.'],
['Make your workspace feel familiar.','Personaliza tu espacio de trabajo.','Personnalisez votre espace de travail.'],
['Interface language','Idioma de la interfaz','Langue de l’interface'],
['Changes supported navigation and interface labels.','Cambia los textos de navegación e interfaz compatibles.','Modifie les libellés de navigation et d’interface pris en charge.'],
['Keep your account protected with a strong password.','Protege tu cuenta con una contraseña segura.','Protégez votre compte avec un mot de passe fort.'],
['Use at least 6 characters. A longer, unique password is best.','Usa al menos 6 caracteres. Es mejor una contraseña larga y única.','Utilisez au moins 6 caractères. Un mot de passe long et unique est préférable.'],
['Show passwords','Mostrar contraseñas','Afficher les mots de passe'],
['Your current password is required to save.','Se requiere tu contraseña actual para guardar.','Votre mot de passe actuel est requis pour enregistrer.'],
['Updating your name, email, or university?','¿Quieres cambiar tu nombre, correo o universidad?','Vous souhaitez modifier votre nom, e-mail ou université ?'],
['Head to your profile','Ir a tu perfil','Accéder à votre profil'],
['Settings','Configuración','Paramètres'],
['Preferences','Preferencias','Préférences'],
['Profile','Perfil','Profil'],
['Personal information','Información personal','Informations personnelles'],
['Full Name','Nombre completo','Nom complet'],
['Email','Correo electrónico','Adresse e-mail'],
['University','Universidad','Université'],
['Major','Carrera','Filière'],
['Save Profile','Guardar perfil','Enregistrer le profil'],
['Email Notifications','Notificaciones por correo','Notifications par e-mail'],
['Reminder Notifications','Recordatorios','Notifications de rappel'],
['Language','Idioma','Langue'],
['Changes save automatically.','Los cambios se guardan automáticamente.','Les modifications sont enregistrées automatiquement.'],
['Change Password','Cambiar contraseña','Changer le mot de passe'],
['Current Password','Contraseña actual','Mot de passe actuel'],
['New Password','Nueva contraseña','Nouveau mot de passe'],
['Confirm New Password','Confirmar nueva contraseña','Confirmer le nouveau mot de passe'],
['Update Password','Actualizar contraseña','Mettre à jour le mot de passe'],
['Manage your account, notifications, and security preferences.','Administra tu cuenta, notificaciones y preferencias de seguridad.','Gérez votre compte, vos notifications et vos préférences de sécurité.'],
['Dark mode','Modo oscuro','Mode sombre'],
['Notifications','Notificaciones','Notifications'],
['Logout','Cerrar sesión','Déconnexion'],
['Workspace','Espacio de trabajo','Espace de travail'],
['Account','Cuenta','Compte'],
['Dashboard','Inicio','Tableau de bord'],
['My Tasks','Mis tareas','Mes tâches'],
['Calendar','Calendario','Calendrier'],
['Productivity','Productividad','Productivité'],
['Study Coach','Asistente de estudio','Assistant d’étude'],
['Check-ins','Registros','Bilans'],
['Academic focus','Enfoque académico','Concentration scolaire'],
['Task Management','Gestión de tareas','Gestion des tâches'],
['Create, track, and prioritize your assignments in one place.','Crea, sigue y prioriza tus tareas en un solo lugar.','Créez, suivez et priorisez vos devoirs au même endroit.'],
['Manage your assignments','Administra tus tareas','Gérez vos devoirs'],
['Use the filters to find a task quickly.','Usa los filtros para encontrar una tarea.','Utilisez les filtres pour trouver une tâche.'],
['Search','Buscar','Rechercher'],
['Search tasks','Buscar tareas','Rechercher des tâches'],
['All tasks','Todas las tareas','Toutes les tâches'],
['Pending','Pendiente','En attente'],
['In progress','En curso','En cours'],
['Completed','Completadas','Terminées'],
['All priority','Todas las prioridades','Toutes les priorités'],
['All subjects','Todas las materias','Toutes les matières'],
['Task','Tarea','Tâche'],['Subject','Materia','Matière'],
['Deadline','Fecha límite','Échéance'],['Priority','Prioridad','Priorité'],
['Status','Estado','Statut'],['Actions','Acciones','Actions'],
['More','Más','Plus'],['Complete','Completar','Terminer'],
['View','Ver','Voir'],['Edit','Editar','Modifier'],['Delete','Eliminar','Supprimer'],
['High','Alta','Haute'],['Medium','Media','Moyenne'],['Low','Baja','Basse'],
['Overdue','Vencidas','En retard'],['Study Time','Tiempo de estudio','Temps d’étude'],
['Total Tasks','Total de tareas','Total des tâches'],
['Study Session','Sesión de estudio','Séance d’étude'],
['Log Delay','Registrar retraso','Consigner un retard'],
['Add Task','Añadir tarea','Ajouter une tâche'],
['Task List','Lista de tareas','Liste des tâches'],
['Progress & insights','Progreso y análisis','Progrès et analyses'],
['Saving…','Guardando…','Enregistrement…'],
['Saved','Guardado','Enregistré'],
['Could not save. Please try again.','No se pudo guardar. Inténtalo de nuevo.','Échec de l’enregistrement. Réessayez.']
];
const dictionary = new Map(rows.map(row => [row[0].toLowerCase(), row]));
const tr = text => {
 const row = dictionary.get(text.toLowerCase());
 return row ? row[config.language === 'es' ? 1 : config.language === 'fr' ? 2 : 0] : text;
};
function translate() {
 observer.disconnect();
 document.documentElement.lang = config.language;
 // Translate interface labels only; never task titles, names, notes, or typed values.
 const selectors = '.sidebar-nav,.brand-copy,.dropdown-menu,.settings-hero,.settings-nav,.settings-section,.settings-profile-note,.settings-card .panel-header,.settings-card label,.settings-card button,.settings-card .panel-date,.settings-card input[aria-label],.settings-card select[aria-label],.topbar-btn,.task-categories,.task-filter-form,.task-table-scroll thead,[data-i18n]';
 document.querySelectorAll(selectors).forEach(root => {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  let node;
  while ((node = walker.nextNode())) {
   if (!originals.has(node)) originals.set(node, node.nodeValue);
   const original = originals.get(node);
   const clean = original.trim();
   // Existing headings may carry a decorative emoji.
   const label = clean.replace(/^[^\p{L}\p{N}]+/u, '').trim();
   if (dictionary.has(label.toLowerCase())) node.nodeValue = original.replace(label, tr(label));
  }
  for (const attr of ['title','aria-label','data-label','placeholder']) {
   if (!root.hasAttribute(attr)) continue;
   const key = 'data-original-' + attr;
   if (!root.hasAttribute(key)) root.setAttribute(key,root.getAttribute(attr));
   root.setAttribute(attr,tr(root.getAttribute(key)));
  }
 });
 observer.observe(document.body,{childList:true,subtree:true});
}
const observer = new MutationObserver(translate);
const status = document.getElementById('preferenceStatus');
let statusTimer;
function announce(text, error = false) {
 clearTimeout(statusTimer);
 status.textContent = tr(text);
 status.dataset.error = String(error);
 if (!error) statusTimer = setTimeout(() => { status.textContent = ''; },3500);
}
const theme = document.getElementById('themeToggle');
function paintTheme() {
 document.body.classList.toggle('dark-mode',config.dark_mode);
 theme.setAttribute('aria-pressed',String(config.dark_mode));
 document.querySelectorAll('[data-settings-theme]').forEach(button => {
  button.setAttribute('aria-pressed',String((button.dataset.settingsTheme === 'dark') === config.dark_mode));
 });
}
async function save(key,value,control,rollback) {
 control.disabled = true;
 announce('Saving…');
 try {
  const response = await fetch('save_preferences.php',{
   method:'POST',credentials:'same-origin',
   body:new URLSearchParams({csrf_token:config.csrf,preference:key,value})
  });
  const result = await response.json();
  if (!response.ok || !result.saved) throw new Error('Save failed');
  announce('Saved');
 } catch (error) {
  rollback();
  announce('Could not save. Please try again.',true);
 } finally { control.disabled = false; }
}
theme.addEventListener('click',() => {
 const previous = config.dark_mode;
 config.dark_mode = !previous; paintTheme();
 save('dark_mode',config.dark_mode?'1':'0',theme,() => { config.dark_mode = previous; paintTheme(); });
});
document.querySelectorAll('[data-settings-theme]').forEach(button => button.addEventListener('click',async () => {
 const previous = config.dark_mode;
 const next = button.dataset.settingsTheme === 'dark';
 if (next === previous || theme.disabled) return;
 const choices = document.querySelectorAll('[data-settings-theme]');
 choices.forEach(choice => { choice.disabled=true; });
 config.dark_mode=next; paintTheme();
 await save('dark_mode',next?'1':'0',theme,() => { config.dark_mode=previous; paintTheme(); });
 choices.forEach(choice => { choice.disabled=false; });
}));
const form = document.getElementById('preferencesForm');
if (form) {
 form.addEventListener('submit',event => event.preventDefault());
 form.querySelectorAll('input[type=checkbox],select').forEach(control => {
  let savedValue = control.type==='checkbox' ? control.checked : control.value;
  control.addEventListener('change',async () => {
   const old = savedValue;
   const value = control.type==='checkbox' ? control.checked : control.value;
   if (control.name==='language') { config.language=value; translate(); }
   await save(control.name,control.type==='checkbox'?(value?'1':'0'):value,control,() => {
    if (control.type==='checkbox') control.checked=old;
    else { control.value=old; config.language=old; translate(); }
   });
   savedValue=control.type==='checkbox'?control.checked:control.value;
  });
 });
}
paintTheme(); translate();
})();
