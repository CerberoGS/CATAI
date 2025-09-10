/* ================== Account Management ================== */

let userData = {};

// Cargar datos del usuario al inicializar
async function loadUserData() {
  try {
    // Usar el endpoint que ya funciona
    const response = await fetch('api/auth_me_safe.php', {
      headers: {
        'Authorization': 'Bearer ' + localStorage.getItem('auth_token')
      }
    });
    
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    
    const data = await response.json();
    
    if (data && data.email) {
      userData = data;
      updateUserDisplay();
    } else {
      console.error('Error loading user data:', data);
      Config.toast('Error al cargar datos del usuario: ' + (data.error || 'Error desconocido'));
    }
  } catch (error) {
    console.error('Error loading user data:', error);
    Config.toast('Error al cargar datos del usuario: ' + error.message);
  }
}

function updateUserDisplay() {
  // Actualizar información del usuario en la UI
  const nameElement = document.getElementById('uName');
  const emailElement = document.getElementById('uEmail');
  const roleElement = document.getElementById('userRole');
  
  if (nameElement) nameElement.textContent = userData.name || 'No disponible';
  if (emailElement) emailElement.textContent = userData.email || 'No disponible';
  
  if (roleElement) {
    const role = userData.role || 'user';
    roleElement.textContent = role === 'admin' ? 'Admin' : 'Usuario';
    roleElement.className = role === 'admin' 
      ? 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark-mode:bg-red-900 dark-mode:text-red-200'
      : 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark-mode:bg-blue-900 dark-mode:text-blue-200';
  }
}

// Funciones para cambios de perfil
async function changePassword() {
  const currentPassword = document.getElementById('current-password').value;
  const newPassword = document.getElementById('new-password').value;
  const confirmPassword = document.getElementById('confirm-password').value;

  if (!currentPassword || !newPassword || !confirmPassword) {
    Config.toast('Todos los campos son requeridos');
    return;
  }

  if (newPassword !== confirmPassword) {
    Config.toast('Las contraseñas no coinciden');
    return;
  }

  if (newPassword.length < 8) {
    Config.toast('La nueva contraseña debe tener al menos 8 caracteres');
    return;
  }

  try {
    const response = await Config.apiPost('password_change_safe.php', {
      current_password: currentPassword,
      new_password: newPassword
    }, true);

    if (response && response.ok) {
      Config.toast('Contraseña cambiada correctamente');
      closeModal();
    } else {
      Config.toast('Error: ' + (response.error || 'Error desconocido'));
    }
  } catch (error) {
    Config.toast('Error al cambiar contraseña: ' + error.message);
  }
}

async function changeEmail() {
  const newEmail = document.getElementById('new-email').value;

  if (!newEmail) {
    Config.toast('El nuevo email es requerido');
    return;
  }

  try {
    const response = await Config.apiPost('email_change_safe.php', {
      new_email: newEmail
    }, true);

    if (response && response.ok) {
      Config.toast('Email cambiado correctamente');
      userData.email = response.new_email;
      updateUserDisplay();
      closeModal();
    } else {
      Config.toast('Error: ' + (response.error || 'Error desconocido'));
    }
  } catch (error) {
    Config.toast('Error al cambiar email: ' + error.message);
  }
}

async function changeName() {
  const newName = document.getElementById('new-name').value;

  if (!newName) {
    Config.toast('El nuevo nombre es requerido');
    return;
  }

  try {
    const response = await Config.apiPost('name_change_safe.php', {
      new_name: newName
    }, true);

    if (response && response.ok) {
      Config.toast('Nombre cambiado correctamente');
      userData.name = response.new_name;
      updateUserDisplay();
      closeModal();
    } else {
      Config.toast('Error: ' + (response.error || 'Error desconocido'));
    }
  } catch (error) {
    Config.toast('Error al cambiar nombre: ' + error.message);
  }
}

async function showUsageDetails() {
  try {
    const response = await Config.apiGet('user_stats_safe.php', true);
    
    if (response && response.ok) {
      const stats = response.stats;
      const content = `
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div class="bg-gray-50 dark-mode:bg-gray-800 p-4 rounded-lg">
              <h3 class="font-semibold text-gray-900 dark-mode:text-gray-100">Análisis Totales</h3>
              <p class="text-2xl font-bold text-blue-600 dark-mode:text-blue-400">${stats.total_analyses}</p>
            </div>
            <div class="bg-gray-50 dark-mode:bg-gray-800 p-4 rounded-lg">
              <h3 class="font-semibold text-gray-900 dark-mode:text-gray-100">Esta Semana</h3>
              <p class="text-2xl font-bold text-green-600 dark-mode:text-green-400">${stats.analyses_this_week}</p>
            </div>
            <div class="bg-gray-50 dark-mode:bg-gray-800 p-4 rounded-lg">
              <h3 class="font-semibold text-gray-900 dark-mode:text-gray-100">Hoy</h3>
              <p class="text-2xl font-bold text-purple-600 dark-mode:text-purple-400">${stats.analyses_today}</p>
            </div>
            <div class="bg-gray-50 dark-mode:bg-gray-800 p-4 rounded-lg">
              <h3 class="font-semibold text-gray-900 dark-mode:text-gray-100">Símbolos Únicos</h3>
              <p class="text-2xl font-bold text-orange-600 dark-mode:text-orange-400">${stats.unique_symbols}</p>
            </div>
          </div>
          
          <div class="bg-gray-50 dark-mode:bg-gray-800 p-4 rounded-lg">
            <h3 class="font-semibold text-gray-900 dark-mode:text-gray-100 mb-2">Actividad Reciente</h3>
            <div class="space-y-2 max-h-40 overflow-y-auto">
              ${response.recent_activity.map(activity => `
                <div class="flex justify-between items-center text-sm">
                  <span class="font-medium text-gray-900 dark-mode:text-gray-100">${activity.symbol}</span>
                  <span class="text-gray-600 dark-mode:text-gray-400">${activity.title || 'Sin título'}</span>
                  <span class="text-xs text-gray-500 dark-mode:text-gray-500">${new Date(activity.created_at).toLocaleDateString()}</span>
                </div>
              `).join('')}
            </div>
          </div>
          
          <div class="flex items-center justify-end">
            <button onclick="closeModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Cerrar</button>
          </div>
        </div>
      `;
      showModal('Detalles de Uso', content);
    } else {
      Config.toast('Error al cargar estadísticas: ' + (response.error || 'Error desconocido'));
    }
  } catch (error) {
    Config.toast('Error al cargar estadísticas: ' + error.message);
  }
}

// Modal functions
function showModal(title, content) {
  const modal = document.getElementById('modal');
  const modalTitle = document.getElementById('modal-title');
  const modalBody = document.getElementById('modal-body');
  
  if (modal && modalTitle && modalBody) {
    modalTitle.textContent = title;
    modalBody.innerHTML = content;
    modal.classList.remove('hidden');
  }
}

function closeModal() {
  const modal = document.getElementById('modal');
  if (modal) {
    modal.classList.add('hidden');
  }
}

// Modal functions para mostrar formularios
function showEditEmail() {
  const content = `
    <div class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Email actual</label>
        <p class="text-sm text-gray-600 dark-mode:text-gray-400">${userData.email || 'No disponible'}</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Nuevo email</label>
        <input id="new-email" type="email" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm" placeholder="nuevo@email.com" />
      </div>
      <div class="flex items-center justify-end gap-2">
        <button onclick="closeModal()" class="px-4 py-2 text-gray-600 dark-mode:text-gray-400 hover:text-gray-800 dark-mode:hover:text-gray-200">Cancelar</button>
        <button onclick="changeEmail()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Cambiar Email</button>
      </div>
    </div>
  `;
  showModal('Cambiar Email', content);
}

function showEditName() {
  const content = `
    <div class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Nombre actual</label>
        <p class="text-sm text-gray-600 dark-mode:text-gray-400">${userData.name || 'No disponible'}</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Nuevo nombre</label>
        <input id="new-name" type="text" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm" placeholder="Tu nombre completo" />
      </div>
      <div class="flex items-center justify-end gap-2">
        <button onclick="closeModal()" class="px-4 py-2 text-gray-600 dark-mode:text-gray-400 hover:text-gray-800 dark-mode:hover:text-gray-200">Cancelar</button>
        <button onclick="changeName()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Cambiar Nombre</button>
      </div>
    </div>
  `;
  showModal('Cambiar Nombre', content);
}

function showChangePassword() {
  const content = `
    <div class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Contraseña actual</label>
        <input id="current-password" type="password" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm" placeholder="Tu contraseña actual" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Nueva contraseña</label>
        <input id="new-password" type="password" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm" placeholder="Mínimo 8 caracteres" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Confirmar nueva contraseña</label>
        <input id="confirm-password" type="password" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm" placeholder="Repite la nueva contraseña" />
      </div>
      <div class="flex items-center justify-end gap-2">
        <button onclick="closeModal()" class="px-4 py-2 text-gray-600 dark-mode:text-gray-400 hover:text-gray-800 dark-mode:hover:text-gray-200">Cancelar</button>
        <button onclick="changePassword()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Cambiar Contraseña</button>
      </div>
    </div>
  `;
  showModal('Cambiar Contraseña', content);
}

// Función para cargar actividad reciente
async function loadRecentActivity() {
  try {
    const response = await Config.apiGet('analysis_list_safe.php?limit=5', true);
    if (response && response.analyses) {
      const activityContainer = document.getElementById('activity');
      if (activityContainer) {
        activityContainer.innerHTML = response.analyses.map(analysis => `
          <div class="flex justify-between items-center text-sm">
            <span class="font-medium text-gray-900 dark-mode:text-gray-100">${analysis.symbol}</span>
            <span class="text-gray-600 dark-mode:text-gray-400">${analysis.title || 'Sin título'}</span>
            <span class="text-xs text-gray-500 dark-mode:text-gray-500">${new Date(analysis.created_at).toLocaleDateString()}</span>
          </div>
        `).join('');
      }
    }
  } catch (error) {
    console.error('Error loading activity:', error);
    const activityContainer = document.getElementById('activity');
    if (activityContainer) {
      activityContainer.innerHTML = '<div class="text-sm text-gray-500">Error al cargar actividad</div>';
    }
  }
}

// Función para cargar estadísticas básicas
async function loadBasicStats() {
  try {
    const response = await Config.apiGet('user_stats_safe.php', true);
    if (response && response.stats) {
      const stats = response.stats;
      
      // Actualizar métricas en la UI
      const totalAnalyses = document.getElementById('total-analyses');
      const weeklyAnalyses = document.getElementById('weekly-analyses');
      const todayAnalyses = document.getElementById('today-analyses');
      const aiUsage = document.getElementById('ai-usage');
      
      if (totalAnalyses) totalAnalyses.textContent = stats.total_analyses || 0;
      if (weeklyAnalyses) weeklyAnalyses.textContent = stats.analyses_this_week || 0;
      if (todayAnalyses) todayAnalyses.textContent = stats.analyses_today || 0;
      if (aiUsage) aiUsage.textContent = stats.ai_usage_today || 0;
    }
  } catch (error) {
    console.error('Error loading stats:', error);
  }
}

// Inicialización
document.addEventListener('DOMContentLoaded', async () => {
  await loadUserData();
  await loadRecentActivity();
  await loadBasicStats();
});

// Exportar funciones para uso global
window.Account = {
  loadUserData,
  updateUserDisplay,
  changePassword,
  changeEmail,
  changeName,
  showUsageDetails,
  showEditEmail,
  showEditName,
  showChangePassword,
  loadRecentActivity,
  loadBasicStats
};
