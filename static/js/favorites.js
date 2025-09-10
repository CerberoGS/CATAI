/* ================== Sistema de Favoritos para Símbolos ================== */

class FavoritesManager {
  constructor() {
    this.favorites = new Set();
    this.maxFavorites = 20;
    this.storageKey = 'catai_favorites';
    this.recentSymbols = [];
    this.maxRecent = 10;
    this.loadFromStorage();
    this.initUI();
  }

  loadFromStorage() {
    try {
      const stored = localStorage.getItem(this.storageKey);
      if (stored) {
        const favorites = JSON.parse(stored);
        this.favorites = new Set(favorites);
      }
    } catch (e) {
      console.warn('Error loading favorites:', e);
    }
  }

  saveToStorage() {
    try {
      localStorage.setItem(this.storageKey, JSON.stringify([...this.favorites]));
    } catch (e) {
      console.warn('Error saving favorites:', e);
    }
  }

  addFavorite(symbol) {
    if (this.favorites.size >= this.maxFavorites) {
      Notifications.toast(`Máximo ${this.maxFavorites} favoritos permitidos`, 'warning');
      return false;
    }
    
    this.favorites.add(symbol);
    this.saveToStorage();
    this.updateUI();
    Notifications.toast(`${symbol} añadido a favoritos`, 'success');
    return true;
  }

  removeFavorite(symbol) {
    this.favorites.delete(symbol);
    this.saveToStorage();
    this.updateUI();
    Notifications.toast(`${symbol} eliminado de favoritos`, 'info');
  }

  toggleFavorite(symbol) {
    if (this.favorites.has(symbol)) {
      this.removeFavorite(symbol);
    } else {
      this.addFavorite(symbol);
    }
  }

  isFavorite(symbol) {
    return this.favorites.has(symbol);
  }

  addRecent(symbol) {
    if (!symbol || this.recentSymbols.includes(symbol)) return;
    
    this.recentSymbols.unshift(symbol);
    if (this.recentSymbols.length > this.maxRecent) {
      this.recentSymbols = this.recentSymbols.slice(0, this.maxRecent);
    }
    this.updateUI();
  }

  initUI() {
    // Crear botón de favoritos en el header
    const header = document.querySelector('header .flex.items-center.space-x-4');
    if (header) {
      const favoritesButton = document.createElement('button');
      favoritesButton.id = 'favorites-button';
      favoritesButton.className = 'relative text-sm text-gray-600 hover:text-gray-800 dark-mode:text-gray-300 dark-mode:hover:text-gray-100 transition-colors';
      favoritesButton.innerHTML = `
        <span class="flex items-center space-x-1">
          <span>⭐</span>
          <span>Favoritos</span>
          <span id="favorites-count" class="bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full">0</span>
        </span>
      `;
      
      // Crear dropdown de favoritos
      const dropdown = document.createElement('div');
      dropdown.id = 'favorites-dropdown';
      dropdown.className = 'absolute right-0 mt-2 w-64 bg-white rounded-lg shadow-lg border hidden z-50';
      dropdown.innerHTML = `
        <div class="p-3">
          <div class="flex items-center justify-between mb-2">
            <h3 class="text-sm font-medium text-gray-900">Símbolos Favoritos</h3>
            <button id="close-favorites" class="text-gray-400 hover:text-gray-600">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>
          <div id="favorites-list" class="space-y-1 max-h-48 overflow-y-auto">
            <p class="text-sm text-gray-500 text-center py-4">No hay favoritos aún</p>
          </div>
          <div class="mt-3 pt-3 border-t">
            <h4 class="text-xs font-medium text-gray-700 mb-2">Recientes</h4>
            <div id="recent-list" class="space-y-1">
              <p class="text-xs text-gray-500">No hay símbolos recientes</p>
            </div>
          </div>
        </div>
      `;
      
      favoritesButton.appendChild(dropdown);
      header.appendChild(favoritesButton);
      
      // Event listeners
      favoritesButton.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
      });
      
      document.getElementById('close-favorites').addEventListener('click', () => {
        dropdown.classList.add('hidden');
      });
      
      // Cerrar dropdown al hacer click fuera
      document.addEventListener('click', (e) => {
        if (!favoritesButton.contains(e.target)) {
          dropdown.classList.add('hidden');
        }
      });
    }
    
    this.updateUI();
  }

  updateUI() {
    const countElement = document.getElementById('favorites-count');
    const favoritesList = document.getElementById('favorites-list');
    const recentList = document.getElementById('recent-list');
    
    if (countElement) {
      countElement.textContent = this.favorites.size;
    }
    
    if (favoritesList) {
      if (this.favorites.size === 0) {
        favoritesList.innerHTML = '<p class="text-sm text-gray-500 text-center py-4">No hay favoritos aún</p>';
      } else {
        favoritesList.innerHTML = [...this.favorites].map(symbol => `
          <div class="flex items-center justify-between p-2 hover:bg-gray-50 rounded">
            <button onclick="favoritesManager.selectSymbol('${symbol}')" class="text-sm text-gray-900 hover:text-blue-600 flex-1 text-left">
              ${symbol}
            </button>
            <button onclick="favoritesManager.removeFavorite('${symbol}')" class="text-red-500 hover:text-red-700 ml-2">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>
        `).join('');
      }
    }
    
    if (recentList) {
      if (this.recentSymbols.length === 0) {
        recentList.innerHTML = '<p class="text-xs text-gray-500">No hay símbolos recientes</p>';
      } else {
        recentList.innerHTML = this.recentSymbols.map(symbol => `
          <button onclick="favoritesManager.selectSymbol('${symbol}')" class="block w-full text-left text-xs text-gray-600 hover:text-blue-600 py-1">
            ${symbol}
          </button>
        `).join('');
      }
    }
  }

  selectSymbol(symbol) {
    document.getElementById('symbol').value = symbol;
    document.getElementById('favorites-dropdown').classList.add('hidden');
    Notifications.toast(`Símbolo ${symbol} seleccionado`, 'info', 2000);
  }
}

// Inicializar gestor de favoritos
const favoritesManager = new FavoritesManager();

// Atajos de teclado
document.addEventListener('keydown', (e) => {
  if (e.ctrlKey && e.key === 'f') {
    e.preventDefault();
    const symbol = document.getElementById('symbol').value.trim().toUpperCase();
    if (symbol) {
      favoritesManager.toggleFavorite(symbol);
    }
  }
  
  if (e.ctrlKey && e.key === 'l') {
    e.preventDefault();
    const dropdown = document.getElementById('favorites-dropdown');
    if (dropdown) {
      dropdown.classList.toggle('hidden');
    }
  }
});

// Exportar para uso en otros módulos
window.Favorites = {
  manager: favoritesManager,
  addRecent: (symbol) => favoritesManager.addRecent(symbol)
};
