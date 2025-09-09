(function(){
  function detectByPath(){
    const p = (location.pathname.split('/').pop()||'').toLowerCase();
    if (p.includes('index')) return { icon:'📈', title:'Analizador', subtitle:'Explora símbolos, configura y ejecuta análisis con opciones.' };
    if (p.includes('config')) return { icon:'🔧', title:'Configuración', subtitle:'Gestiona tus claves y preferencias de la app.' };
    if (p.includes('journal')) return { icon:'📝', title:'Bitácora', subtitle:'Consulta, filtra y gestiona tus análisis guardados.' };
    if (p.includes('account')) return { icon:'👤', title:'Mi Cuenta', subtitle:'Gestiona tu perfil, actividad y accesos.' };
    if (p.includes('feedback')) return { icon:'💬', title:'Feedback', subtitle:'Triage y administración de reportes enviados por los usuarios.' };
    if (p.includes('admin')) return { icon:'⚙️', title:'Panel de Administración', subtitle:'Monitorea el sistema, gestiona usuarios y revisa actividad.' };
    return { icon:'📄', title: document.title || 'Módulo', subtitle:'' };
  }

  function buildHero({icon, title, subtitle, actionsEl}){
    const wrap = document.createElement('div');
    wrap.className = 'hero';
    wrap.innerHTML = `
      <div class="hero-inner">
        <div class="hero-info">
          ${icon? `<div class="hero-icon" aria-hidden="true">${icon}</div>`:''}
          <div>
            <h1 class="hero-title">${title||''}</h1>
            ${subtitle? `<p class="hero-subtitle">${subtitle}</p>`:''}
          </div>
        </div>
        <div class="hero-actions"></div>
      </div>`;
    if (actionsEl){
      const slot = wrap.querySelector('.hero-actions');
      // normalizar estilos de acciones
      actionsEl.classList.add('hero-actions-slot');
      slot.appendChild(actionsEl);
    }
    return wrap;
  }

  function mount(){
    const mountEl = document.getElementById('app-hero');
    if (!mountEl) return;

    // Datos por atributos con fallback por ruta
    const autod = detectByPath();
    const icon = mountEl.getAttribute('data-icon') || autod.icon;
    const title = mountEl.getAttribute('data-title') || autod.title;
    const subtitle = mountEl.getAttribute('data-subtitle') || autod.subtitle;

    // Mover acciones si existen
    const actionsEl = document.getElementById('hero-actions');
    const hero = buildHero({ icon, title, subtitle, actionsEl });

    // Reemplazar contenedor
    mountEl.replaceWith(hero);
  }

  document.addEventListener('DOMContentLoaded', mount);
  window.UIHero = { mount };
})();


