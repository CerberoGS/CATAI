/* ================== Gestión de Configuración ================== */

// Proveedores IA dinámicos
async function buildAIProviders() {
  const sel = document.getElementById('ai-provider');
  const modelSelect = document.getElementById('ai-model');
  const suggestions = {
    gemini:   ['gemini-2.0-flash','gemini-1.5-flash','gemini-1.5-pro'],
    openai:   ['gpt-4o-mini','gpt-4o','gpt-4.1-mini'],
    claude:   ['claude-3-5-sonnet-latest','claude-3-haiku-latest'],
    xai:      ['grok-2-mini','grok-2'],
    deepseek: ['deepseek-chat','deepseek-reasoner'],
  };
  try {
    const resp = await Config.apiGet('user_keys_get_safe.php', true);
    const keys = resp.keys || {};
    const providers = ['gemini','openai','claude','xai','deepseek'].filter(p => keys[p]?.has);
    const current = sel?.value;
    if (sel) {
      sel.innerHTML = '<option value="auto">Auto</option>' + providers.map(p => `<option value="${p}">${p[0].toUpperCase()+p.slice(1)}</option>`).join('');
      if (providers.includes(current)) sel.value = current; else sel.value = 'auto';
    }

    const rebuildModels = () => {
      const p = sel?.value;
      const list = suggestions[p] || [];
      const prev = modelSelect?.value;
      if (modelSelect) {
        const opts = ['<option value="">Auto (sugerido)</option>'].concat(list.map(m => `<option value="${m}">${m}</option>`));
        modelSelect.innerHTML = opts.join('');
        if (prev && list.includes(prev)) modelSelect.value = prev; else modelSelect.value = '';
        modelSelect.disabled = (p === 'auto');
      }
    };
    sel?.addEventListener('change', rebuildModels);
    rebuildModels();
  } catch {}
}

function applySettings(s) {
  if (!s) return;

  const dp = s.data_provider ?? null;
  const sp = s.series_provider ?? s.seriesProvider ?? s.provider ?? null;
  const provToUse = dp || sp;
  const providerSel = document.getElementById('provider');
  if (provToUse && providerSel) providerSel.value = Config.serverToUiProvider(provToUse);

  const aiProvSel = document.getElementById('ai-provider');
  const aip = s.ai_provider ?? s.aiProvider ?? 'auto';
  if (aiProvSel) {
    aiProvSel.value = aip;
    try { aiProvSel.dispatchEvent(new Event('change')); } catch {}
  }
  
  const aiModelIn = document.getElementById('ai-model');
  const savedModel = (s.ai_model ?? s.aiModel ?? '') || '';
  if (savedModel && aiModelIn) {
    const exists = Array.from(aiModelIn.options).some(o => o.value === savedModel);
    if (!exists) {
      const opt = document.createElement('option');
      opt.value = savedModel; 
      opt.textContent = savedModel; 
      aiModelIn.appendChild(opt);
    }
    aiModelIn.value = savedModel;
  } else if (aiModelIn) {
    aiModelIn.value = '';
  }

  try {
    const optp = s.options_provider ?? s.optionsProvider ?? 'auto';
    if (typeof optp === 'string' && optp) {
      // optionsProviderPref = optp; // Variable global del index.html
    }
  } catch {}

  let res = s.resolutions_json ?? s.resolutions ?? s.resolution ?? null;
  if (typeof res === 'string') { 
    try { res = JSON.parse(res); } catch {} 
  }
  if (!Array.isArray(res) || res.length === 0) res = ['60min','15min','5min'];

  let inds = s.indicators_json ?? s.indicators ?? null;
  if (typeof inds === 'string') { 
    try { inds = JSON.parse(inds); } catch {} 
  }
  if (!inds || typeof inds !== 'object') inds = {};

  const blocks = document.getElementById('blocks');
  if (blocks) {
    blocks.innerHTML = res.slice(0,3).map((r,i)=>Analysis.blockTemplate(i,r)).join('');
    Array.from(blocks.children).forEach((el) => {
      const reso = el.querySelector('.reso')?.value;
      const sel = inds[reso] || {};
      el.querySelectorAll('.ind').forEach(ch => { 
        ch.checked = !!sel[ch.dataset.k]; 
      });
    });
  }

  if (window.Universe) {
    Universe.loadUniverse();
  }

  try {
    const amountEl = document.getElementById('amount');
    const tpEl = document.getElementById('tp');
    const slEl = document.getElementById('sl');
    if (typeof s.amount === 'number' && amountEl) amountEl.value = String(s.amount);
    if (typeof s.tp === 'number' && tpEl) tpEl.value = String(s.tp);
    if (typeof s.sl === 'number' && slEl) slEl.value = String(s.sl);
  } catch {}
}

async function loadSettingsIntoUI() {
  try {
    const s = await Config.getWithFallback(['settings_get_safe.php','settings_get.php'], true);
    if (!s) return;
    await buildAIProviders();
    if (s.settings) {
      applySettings(s.settings);
    } else {
      applySettings(s);
    }
  } catch (e) { /* silencioso */ }
}

// ================== Guardar ajustes ==================
const btnSaveSettings = document.getElementById('btn-save-settings');

btnSaveSettings?.addEventListener('click', async () => {
  Config.btnBusy(btnSaveSettings, true);
  try {
    const settings = collectSettingsFromUI();
    await Config.postWithFallback(['settings_set_safe.php','settings_set.php'], settings, true);
    Config.toast('Ajustes guardados correctamente.');
    await loadSettingsIntoUI();
    await buildAIProviders();
  } catch (e) {
    Config.toast('No se pudieron guardar los ajustes: ' + (e?.message || e));
  } finally {
    Config.btnBusy(btnSaveSettings, false);
  }
});

function collectSettingsFromUI() {
  const blocksEls = Array.from(document.getElementById('blocks')?.children || []);
  const resolutions = [];
  const indicators = {};
  blocksEls.forEach(el => {
    const reso = el.querySelector('.reso')?.value;
    const inds = {};
    el.querySelectorAll('.ind').forEach(ch => { 
      inds[ch.dataset.k] = ch.checked; 
    });
    resolutions.push(reso);
    indicators[reso] = inds;
  });
  const amount = parseFloat(document.getElementById('amount')?.value || '0');
  const tp = parseFloat(document.getElementById('tp')?.value || '0');
  const sl = parseFloat(document.getElementById('sl')?.value || '0');
  return {
    data_provider: Config.uiToServerProvider(document.getElementById('provider')?.value || 'auto'),
    resolutions_json: resolutions,
    indicators_json: indicators,
    ai_provider: document.getElementById('ai-provider')?.value || 'auto',
    ai_model: document.getElementById('ai-model')?.value?.trim() || null,
    amount: Number.isFinite(amount) ? amount : 0,
    tp: Number.isFinite(tp) ? tp : 0,
    sl: Number.isFinite(sl) ? sl : 0,
  };
}

// Función para resetear configuraciones a valores por defecto
function resetSettings() {
  // Resetear valores por defecto
  const providerEl = document.getElementById('provider');
  const aiProviderEl = document.getElementById('ai-provider');
  const aiModelEl = document.getElementById('ai-model');
  const symbolEl = document.getElementById('symbol');
  const amountEl = document.getElementById('amount');
  const tpEl = document.getElementById('tp');
  const slEl = document.getElementById('sl');
  
  if (providerEl) providerEl.value = 'auto';
  if (aiProviderEl) aiProviderEl.value = 'auto';
  if (aiModelEl) aiModelEl.value = '';
  if (symbolEl) symbolEl.value = '';
  if (amountEl) amountEl.value = '1000';
  if (tpEl) tpEl.value = '5';
  if (slEl) slEl.value = '3';
  
  // Resetear resoluciones
  const defaultRes = ['60min','15min','5min'];
  document.querySelectorAll('.reso').forEach((sel, i) => {
    if (defaultRes[i]) {
      sel.value = defaultRes[i];
    }
  });
  
  // Resetear indicadores
  document.querySelectorAll('.ind').forEach(chk => {
    const defaultChecked = ['rsi14', 'sma20', 'ema20'];
    chk.checked = defaultChecked.includes(chk.dataset.k);
  });
  
  Config.toast('Configuración reseteada a valores por defecto', 'info');
}

// Exportar funciones para uso global
window.Settings = {
  loadSettingsIntoUI,
  saveSettings: () => btnSaveSettings?.click(),
  resetSettings,
  applySettings,
  collectSettingsFromUI,
  buildAIProviders
};
