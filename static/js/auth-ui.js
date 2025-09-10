/* ================== Auth UI ================== */
const authForm   = document.getElementById('auth-form');
const authMsg    = document.getElementById('auth-msg');
const authStatus = document.getElementById('auth-status');
const emailIn    = document.getElementById('auth-email');
const passIn     = document.getElementById('auth-pass');
const btnLogin   = document.getElementById('btn-login');
const btnReg     = document.getElementById('btn-register');
const actionsBar = document.getElementById('auth-actions');
const actionsBarNav = document.getElementById('auth-actions-nav');
const emailShow  = document.getElementById('auth-email-show');
const themeToggle = document.getElementById('theme-toggle');

// Modo Oscuro - Funcionalidad Incremental
let isDarkMode = localStorage.getItem('darkMode') === 'true';

// Aplicar modo oscuro al cargar
if (isDarkMode) {
  document.body.classList.add('dark-mode');
  updateThemeButton();
}

// Toggle del modo oscuro
themeToggle?.addEventListener('click', () => {
  isDarkMode = !isDarkMode;
  document.body.classList.toggle('dark-mode', isDarkMode);
  localStorage.setItem('darkMode', isDarkMode);
  updateThemeButton();
});

function updateThemeButton() {
  if (themeToggle) {
    if (isDarkMode) {
      themeToggle.textContent = '☀️ Modo Claro';
    } else {
      themeToggle.textContent = '🌙 Modo Oscuro';
    }
  }
}

const btnLogout  = document.getElementById('btn-logout');
const btnLoadSettings = document.getElementById('btn-load-settings');
const btnConfig  = document.getElementById('btn-config');

async function setLoggedIn(email) {
  authForm?.classList.add('hidden');
  actionsBar?.classList.remove('hidden');
  actionsBarNav?.classList.remove('hidden');
  if (emailShow) emailShow.textContent = email || '';
  if (authStatus) authStatus.textContent = 'Sesión iniciada';
  if (authMsg) authMsg.textContent = '';
  updateThemeButton();
  
  // Inicializar otros componentes después del login
  if (window.Universe) {
    await Universe.buildProviderSelect();
  }
  if (window.Settings) {
    await Settings.buildAIProviders();
  }
}

function setLoggedOut(msg='No has iniciado sesión') {
  authForm?.classList.remove('hidden');
  actionsBar?.classList.add('hidden');
  actionsBarNav?.classList.add('hidden');
  if (authStatus) authStatus.textContent = msg;
  if (authMsg) authMsg.textContent = '';
  Config.clearToken();
}

async function tryRestoreSession() {
  const token = Config.getToken();
  if (!token) { 
    setLoggedOut(); 
    return; 
  }
  
  try {
    const me = await Config.getWithFallback(['auth_me_safe.php','auth_me.php'], true);
    await setLoggedIn(me.email);
    try { 
      await updateAdminUI(me); 
    } catch {}
    if (window.Settings) {
      await Settings.loadSettingsIntoUI();
      await Settings.buildAIProviders();
    }
  } catch {
    Config.clearToken();
    setLoggedOut('Sesión expirada. Ingresa de nuevo.');
  }
}

// Event listeners para autenticación
btnLogin?.addEventListener('click', async () => {
  if (authMsg) authMsg.textContent = '';
  Config.btnBusy(btnLogin, true);
  try {
    const j = await Config.postWithFallback(['auth_login_safe.php','auth_login.php'], { 
      email: emailIn?.value?.trim(), 
      password: passIn?.value?.trim() 
    }, false);
    Config.setToken(j.token);
    await setLoggedIn(j.user?.email);
    try { 
      await updateAdminUI(); 
    } catch {}
    if (window.Settings) {
      await Settings.loadSettingsIntoUI();
    }
  } catch (e) {
    if (authMsg) authMsg.textContent = e?.message || 'Error de login';
  } finally { 
    Config.btnBusy(btnLogin, false); 
  }
});

btnReg?.addEventListener('click', async () => {
  if (authMsg) authMsg.textContent = '';
  Config.btnBusy(btnReg, true);
  try {
    const j = await Config.postWithFallback(['auth_register_safe.php','auth_register.php'], { 
      email: emailIn?.value?.trim(), 
      password: passIn?.value?.trim(), 
      name: '' 
    }, false);
    Config.setToken(j.token);
    await setLoggedIn(j.user?.email);
    try { 
      await updateAdminUI(); 
    } catch {}
  } catch (e) {
    if (authMsg) authMsg.textContent = e?.message || 'Error de registro';
  } finally { 
    Config.btnBusy(btnReg, false); 
  }
});

btnLogout?.addEventListener('click', () => {
  Config.clearToken();
  setLoggedOut('Sesión cerrada.');
});

btnLoadSettings?.addEventListener('click', async () => {
  Config.btnBusy(btnLoadSettings, true);
  try {
    const s = await Config.getWithFallback(['settings_get_safe.php','settings_get.php'], true);
    if (!s) { 
      Config.toast('No hay ajustes guardados aún.'); 
      return; 
    }
    if (window.Settings) {
      await Settings.buildAIProviders();
      if (s.settings) { 
        Settings.applySettings(s.settings); 
      } else { 
        Settings.applySettings(s); 
      }
    }
    Config.toast('Ajustes cargados.');
  } catch (e) {
    Config.toast('No se pudieron cargar los ajustes: ' + (e?.message || e));
  } finally { 
    Config.btnBusy(btnLoadSettings, false); 
  }
});

// Exportar/Importar ajustes
const btnExport = document.getElementById('btn-export-settings');
const btnImport = document.getElementById('btn-import-settings');
const importInput = document.createElement('input');
importInput.type = 'file'; 
importInput.accept = 'application/json'; 
importInput.style.display = 'none';
document.body.appendChild(importInput);

btnExport?.addEventListener('click', async () => {
  try {
    const s = await Config.getWithFallback(['settings_get_safe.php','settings_get.php'], true);
    const settings = s?.settings || s || {};
    const blob = new Blob([JSON.stringify(settings, null, 2)], {type:'application/json'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); 
    a.href = url; 
    a.download = 'settings.json';
    document.body.appendChild(a); 
    a.click(); 
    a.remove(); 
    URL.revokeObjectURL(url);
  } catch (e) { 
    Config.toast('No se pudo exportar: ' + (e?.message||e)); 
  }
});

btnImport?.addEventListener('click', () => importInput.click());
importInput.addEventListener('change', async () => {
  const file = importInput.files?.[0]; 
  if (!file) return;
  try {
    const text = await file.text();
    let data = JSON.parse(text);
    if (data && data.settings) data = data.settings; // tolerar wrapper
    await Config.postWithFallback(['settings_set_safe.php','settings_set.php'], data, true);
    Config.toast('Ajustes importados/guardados.');
    if (window.Settings) {
      await Settings.loadSettingsIntoUI();
      await Settings.buildAIProviders();
    }
  } catch (e) { 
    Config.toast('Importación fallida: ' + (e?.message||e)); 
  }
  importInput.value = '';
});

btnConfig?.addEventListener('click', () => {
  window.location.href = 'config.html';
});

const btnJournal = document.getElementById('btn-journal');
btnJournal?.addEventListener('click', ()=>{ 
  window.location.href = 'journal.html'; 
});

const btnFeedback = document.getElementById('btn-feedback');
btnFeedback?.addEventListener('click', ()=>{ 
  try{ 
    if (window.Feedback) {
      Feedback.fbShow(true); 
    }
  }catch{} 
});

// ===== Admin helpers =====
function isAdminFromMe(me){
  try{
    if (!me) return false;
    if (me.role === 'admin') return true;
    if (Array.isArray(me.roles) && me.roles.includes('admin')) return true;
    if (me.is_admin === true) return true;
  }catch{}
  return false;
}

async function updateAdminUI(me){
  try{
    let info = me;
    if (!info) { 
      info = await Config.getWithFallback(['auth_me_safe.php','auth_me.php'], true); 
    }
    const isAdmin = isAdminFromMe(info);
    const tri = document.getElementById('btn-feedback-triage');
    const adm = document.getElementById('btn-admin');
    if (tri) { 
      if (isAdmin) tri.classList.remove('hidden'); 
      else tri.classList.add('hidden'); 
    }
    if (adm) { 
      if (isAdmin) adm.classList.remove('hidden'); 
      else adm.classList.add('hidden'); 
    }
  }catch{}
}

// Exportar funciones para uso en otros módulos
window.AuthUI = {
  setLoggedIn,
  setLoggedOut,
  tryRestoreSession,
  updateAdminUI,
  isAdminFromMe,
  isDarkMode: () => isDarkMode
};
