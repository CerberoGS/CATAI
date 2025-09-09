/* ================== Análisis de Mercado ================== */

// Bloques de análisis
const blocks = document.getElementById('blocks');
const defaultRes = ['60min','15min','5min'];

function blockTemplate(i, reso) {
  return `
    <div class="rounded-lg border p-3">
      <div class="font-semibold text-gray-800 mb-2">Análisis ${i+1}</div>
      <label class="block text-sm font-medium text-gray-700">Temporalidad</label>
      <select class="w-full mt-1 rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 reso">
        <option value="1min" ${reso==='1min'?'selected':''}>1 min</option>
        <option value="5min" ${reso==='5min'?'selected':''}>5 min</option>
        <option value="15min" ${reso==='15min'?'selected':''}>15 min</option>
        <option value="30min" ${reso==='30min'?'selected':''}>30 min</option>
        <option value="60min" ${reso==='60min'?'selected':''}>1 hora (60min)</option>
        <option value="daily" ${reso==='daily'?'selected':''}>1 día</option>
        <option value="weekly" ${reso==='weekly'?'selected':''}>1 semana</option>
      </select>
      <div class="mt-2 grid grid-cols-2 gap-2">
        <label class="inline-flex items-center"><input type="checkbox" class="ind mr-2" data-k="rsi14" checked/> <span class="text-sm">RSI(14)</span></label>
        <label class="inline-flex items-center"><input type="checkbox" class="ind mr-2" data-k="sma20" checked/> <span class="text-sm">SMA(20)</span></label>
        <label class="inline-flex items-center"><input type="checkbox" class="ind mr-2" data-k="ema20" checked/> <span class="text-sm">EMA(20)</span></label>
        <label class="inline-flex items-center"><input type="checkbox" class="ind mr-2" data-k="ema40"/> <span class="text-sm">EMA(40)</span></label>
        <label class="inline-flex items-center"><input type="checkbox" class="ind mr-2" data-k="ema100"/> <span class="text-sm">EMA(100)</span></label>
        <label class="inline-flex items-center"><input type="checkbox" class="ind mr-2" data-k="ema200"/> <span class="text-sm">EMA(200)</span></label>
      </div>
    </div>
  `;
}

// Inicializar bloques
if (blocks) {
  blocks.innerHTML = defaultRes.map((r,i)=>blockTemplate(i,r)).join('');
}

async function analyze() {
  const runBtn = document.getElementById('run');
  const results = document.getElementById('results');
  const loading = document.getElementById('loading');
  const out = document.getElementById('out');
  
  const token = Config.getToken();
  if (!token) { 
    alert('Inicia sesión para analizar.'); 
    return; 
  }

  const symbolInput = document.getElementById('symbol');
  const symbolSelect = document.getElementById('symbol-select');
  const providerSel = document.getElementById('provider');
  
  const symbol = (symbolInput?.value || symbolSelect?.value || 'TSLA').toUpperCase();
  const provider = providerSel?.value || 'auto';
  
  const blocksEls = Array.from(blocks?.children || []);
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

  results?.classList.remove('hidden');
  loading?.classList.remove('hidden');
  if (out) out.textContent = '';

  try {
    const tsData = await Config.postWithFallback(['time_series_safe.php','time_series.php'], { 
      symbol, 
      resolutions, 
      indicators, 
      provider 
    }, true);
    
    // Log para debug
    fetch(Config.API_BASE + '/log_debug.php', {
      method: 'POST',
      headers: Config.withAuthHeaders({ 'Content-Type': 'application/json' }, true),
      body: JSON.stringify({
        type: 'time_series',
        symbol,
        provider,
        resolutions,
        indicators,
        response: tsData
      })
    }).catch(()=>{});

    const seriesByRes = tsData.seriesByRes || {};
    const entries = Object.entries(seriesByRes);
    if (!entries.length) throw new Error('Sin datos de series.');

    let buySignals = 0, sellSignals = 0;
    let lastPrice = null;
    const perRes = [];

    for (const [reso, obj] of entries) {
      if (obj?.error) continue;
      const last = obj.indicators?.last || {};
      lastPrice = last.price;
      if (last.rsi14 != null) {
        if (last.rsi14 < 30) buySignals++; else if (last.rsi14 > 70) sellSignals++;
      }
      if (last.price != null) {
        if (last.ema200 != null) (last.price > last.ema200 ? buySignals++ : sellSignals++);
        else if (last.sma20 != null) (last.price > last.sma20 ? buySignals++ : sellSignals++);
      }
      perRes.push({ reso, last, provider: obj.provider });
    }
    const finalRec = buySignals > sellSignals ? 'COMPRAR' : (sellSignals > buySignals ? 'VENDER' : 'NEUTRAL');

    const up = (lastPrice!=null && !Number.isNaN(lastPrice)) ? `&underlying_price=${encodeURIComponent(lastPrice)}` : '';
    const optProv = 'auto'; // optionsProviderPref del index.html
    const chain = await Config.apiGet(`options_safe.php?symbol=${encodeURIComponent(symbol)}&provider=${encodeURIComponent(optProv)}&realtime=false&greeks=true${up}`, true).catch(()=>({ chain: [] }));
    
    // Log para debug
    fetch(Config.API_BASE + '/log_debug.php', {
      method: 'POST',
      headers: Config.withAuthHeaders({ 'Content-Type': 'application/json' }, true),
      body: JSON.stringify({
        type: 'options',
        symbol,
        provider,
        response: chain
      })
    }).catch(()=>{});

    let callPick = null, putPick = null;
    let optionsNote = '';
    const hybridNote = (chain && typeof chain.note === 'string') ? chain.note : '';
    if (chain && Array.isArray(chain.chain) && chain.chain.length) {
      const hasStrikes = chain.chain.some(c => c.strike != null);
      if (!hasStrikes) {
        optionsNote = 'Finnhub';
      } else {
        const firstExp = chain.chain[0]?.expiration;
        const sameExp = chain.chain.filter(c => c.expiration === firstExp);
        if (sameExp && sameExp.length) {
          if (lastPrice != null) {
            let minCallDiff = Infinity, minPutDiff = Infinity;
            for (const c of sameExp) {
              const diff = Math.abs((c.strike ?? 0) - lastPrice);
              if (c.type === 'call' && diff < minCallDiff) { minCallDiff = diff; callPick = c; }
              if (c.type === 'put'  && diff < minPutDiff) { minPutDiff  = diff; putPick  = c; }
            }
          } else {
            callPick = sameExp.find(c => c.type === 'call') || null;
            putPick  = sameExp.find(c => c.type === 'put')  || null;
          }
        }
      }
    }

    const aiProvider = document.getElementById('ai-provider')?.value || 'auto';
    const aiModel = document.getElementById('ai-model')?.value?.trim() || '';
    let detalleRes = '';
    perRes.forEach(r => {
      detalleRes += `- Resolución: ${r.reso} [${r.provider}] | Precio: ${Config.fmt(r.last.price)} | RSI14: ${Config.fmt(r.last.rsi14)} | SMA20: ${Config.fmt(r.last.sma20)} | EMA20: ${Config.fmt(r.last.ema20)} | EMA40: ${Config.fmt(r.last.ema40)} | EMA100: ${Config.fmt(r.last.ema100)} | EMA200: ${Config.fmt(r.last.ema200)}\n`;
    });
    if (!detalleRes) detalleRes = 'Sin datos por resolución.';

    let opcionesPrompt = '';
    if (chain && Array.isArray(chain.chain) && chain.chain.length) {
      opcionesPrompt = chain.chain.slice(0, 10).map(c => {
        return `Tipo: ${c.type || '—'}, Strike: ${Config.fmt(c.strike)}, Bid: ${Config.fmt(c.bid)}, Ask: ${Config.fmt(c.ask)}, IV: ${Config.fmt(c.iv)}, Delta: ${Config.fmt(c.delta)}, Exp: ${c.expiration || '—'}`;
      }).join('\n');
      if (!opcionesPrompt) opcionesPrompt = 'Sin opciones relevantes.';
      if (hybridNote) opcionesPrompt = `[${hybridNote}]\n` + opcionesPrompt;
    } else {
      opcionesPrompt = 'Sin datos de opciones disponibles.';
    }

    const prompt = `Analiza el activo ${symbol} con los siguientes datos:\n` +
      `BUY=${buySignals}, SELL=${sellSignals}, Recomendación final=${finalRec}, Último precio=${Config.fmt(lastPrice)}.\n` +
      `DETALLE POR RESOLUCIÓN:\n${detalleRes}\n` +
      `OPCIONES:\n${opcionesPrompt}`;

    const aiRes = await Config.apiPost('ai_analyze.php', {
      provider: aiProvider, 
      model: aiModel, 
      prompt,
      systemPrompt: "Eres analista de opciones intradía. No inventes datos. Da recomendaciones claras y riesgos."
    }, true).catch(()=>({ text: '' }));

    // Log para debug
    fetch(Config.API_BASE + '/log_debug.php', {
      method: 'POST',
      headers: Config.withAuthHeaders({ 'Content-Type': 'application/json' }, true),
      body: JSON.stringify({
        type: 'ai',
        symbol,
        provider: aiProvider,
        model: aiModel || null,
        prompt,
        response: aiRes
      })
    }).catch(()=>{});

    let text = '';
    text += `RESUMEN\n`;
    text += `• Símbolo: ${symbol}\n`;
    if (lastPrice != null) text += `• Último precio: ${Config.fmt(lastPrice)}\n`;
    text += `• Señales → BUY: ${buySignals} | SELL: ${sellSignals}\n`;
    text += `• Recomendación: ${finalRec}\n`;
    text += `\nDETALLE POR RESOLUCIÓN\n`;
    perRes.forEach(r => {
      text += `- ${r.reso} [${r.provider}]  P=${Config.fmt(r.last.price)}  RSI14=${Config.fmt(r.last.rsi14)}  SMA20=${Config.fmt(r.last.sma20)}  EMA20=${Config.fmt(r.last.ema20)}  EMA40=${Config.fmt(r.last.ema40)}  EMA100=${Config.fmt(r.last.ema100)}  EMA200=${Config.fmt(r.last.ema200)}\n`;
    });
    if (chain && Array.isArray(chain.chain) && chain.chain.length) {
      if (optionsNote) {
        text += `\nOPCIONES (${optionsNote})\n`;
        text += `• En tu plan no hay strikes; se listan expiraciones con IV promedio:\n`;
        const exps = chain.chain.slice(0, 20);
        for (const c of exps) text += `  - ${c.expiration || '—'}  IV: ${Config.fmt(c.iv)}\n`;
        if (chain.chain.length > 20) text += `  ... (${chain.chain.length - 20} más)\n`;
      } else {
        text += `\nOPCIONES (prox expiración)\n`;
        if (callPick) text += `• CALL ATM aprox  ${callPick.contract || '—'}  strike ${Config.fmt(callPick.strike)}  bid ${Config.fmt(callPick.bid)}  ask ${Config.fmt(callPick.ask)}  IV ${Config.fmt(callPick.iv)}  Δ ${Config.fmt(callPick.delta)}\n`;
        if (putPick)  text += `• PUT  ATM aprox  ${putPick.contract  || '—'}  strike ${Config.fmt(putPick.strike)}  bid ${Config.fmt(putPick.bid)}  ask ${Config.fmt(putPick.ask)}  IV ${Config.fmt(putPick.iv)}  Δ ${Config.fmt(putPick.delta)}\n`;
      }
    } else {
      text += `\nOPCIONES: sin datos disponibles (historial reciente)\n`;
    }
  const amount = parseFloat(document.getElementById('amount')?.value || '1000');
  const tp = parseFloat(document.getElementById('tp')?.value || '5');
  const sl = parseFloat(document.getElementById('sl')?.value || '3');
    if (!Number.isNaN(tp) && !Number.isNaN(sl) && lastPrice) {
      const target = lastPrice * (1 + tp/100);
      const stop = lastPrice * (1 - sl/100);
      text += `\nPLAN SUBYACENTE\n• TP ${tp}%: ${Config.fmt(target)}  • SL ${sl}%: ${Config.fmt(stop)}  • Capital: $${amount}\n`;
    }
    const aiText = aiRes?.text || (aiRes?.error ? `(IA error: ${aiRes.error}${aiRes.detail? ' — '+aiRes.detail: ''})` : '(IA sin respuesta)');
    if (aiText && aiText.trim()) {
      text += `\nIA (${aiProvider}${aiModel?": "+aiModel:""})\n${aiText}\n`;
    }
    loading?.classList.add('hidden');
    if (out) out.textContent = text;
  } catch (e) {
    loading?.classList.add('hidden');
    if (out) out.textContent = 'Error: ' + (e?.message || e);
    try {
      fetch(Config.API_BASE + '/log_debug.php', {
        method: 'POST',
        headers: Config.withAuthHeaders({ 'Content-Type': 'application/json' }, true),
        body: JSON.stringify({
          type: 'error',
          where: 'analyze',
          message: (e?.message || String(e)),
          stack: (e?.stack || null)
        })
      }).catch(()=>{});
    } catch {}
  }
}

// ====== Guardar análisis (modal) ======
const modalSave = document.getElementById('modal-save');
const msOpen = document.getElementById('btn-save-analysis');
const msClose = document.getElementById('ms-close');
const msCancel = document.getElementById('ms-cancel');
const msSave = document.getElementById('ms-save');
const msMsg = document.getElementById('ms-msg');

function msShow(show){ 
  if(!modalSave) return; 
  modalSave.classList.toggle('hidden', !show); 
  modalSave.classList.toggle('flex', !!show); 
}

// Función para generar título inteligente basado en el análisis
function generateSmartTitle(symbol, analysisText) {
  if (!symbol || !analysisText) return `Análisis ${symbol || 'Símbolo'}`;
  
  const text = analysisText.toLowerCase();
  const now = new Date();
  const dateStr = now.toLocaleDateString('es-ES');
  
  // Detectar recomendación
  let recommendation = '';
  if (text.includes('comprar') || text.includes('buy')) {
    recommendation = 'COMPRA';
  } else if (text.includes('vender') || text.includes('sell')) {
    recommendation = 'VENTA';
  } else if (text.includes('neutral')) {
    recommendation = 'NEUTRAL';
  }
  
  // Detectar temporalidad
  let timeframe = '';
  if (text.includes('1min') || text.includes('1 min')) {
    timeframe = '1min';
  } else if (text.includes('5min') || text.includes('5 min')) {
    timeframe = '5min';
  } else if (text.includes('15min') || text.includes('15 min')) {
    timeframe = '15min';
  } else if (text.includes('30min') || text.includes('30 min')) {
    timeframe = '30min';
  } else if (text.includes('60min') || text.includes('1 hora')) {
    timeframe = '1h';
  } else if (text.includes('daily') || text.includes('diario')) {
    timeframe = 'Diario';
  } else if (text.includes('weekly') || text.includes('semanal')) {
    timeframe = 'Semanal';
  }
  
  // Detectar señales técnicas
  let signals = [];
  if (text.includes('rsi') && (text.includes('< 30') || text.includes('sobreventa'))) {
    signals.push('RSI Oversold');
  }
  if (text.includes('rsi') && (text.includes('> 70') || text.includes('sobrecompra'))) {
    signals.push('RSI Overbought');
  }
  if (text.includes('ema') && text.includes('cruce')) {
    signals.push('EMA Cross');
  }
  if (text.includes('rompimiento') || text.includes('breakout')) {
    signals.push('Breakout');
  }
  if (text.includes('soporte') || text.includes('support')) {
    signals.push('Support');
  }
  if (text.includes('resistencia') || text.includes('resistance')) {
    signals.push('Resistance');
  }
  
  // Construir título
  let title = `${symbol}`;
  
  if (timeframe) {
    title += ` ${timeframe}`;
  }
  
  if (recommendation) {
    title += ` - ${recommendation}`;
  }
  
  if (signals.length > 0) {
    title += ` (${signals.slice(0, 2).join(', ')})`;
  }
  
  title += ` - ${dateStr}`;
  
  return title;
}

msOpen?.addEventListener('click', ()=>{ 
  msShow(true); 
  if (msMsg) msMsg.textContent='';
  
  // Llenar información del análisis
  const symbol = (document.getElementById('symbol')?.value || document.getElementById('symbol-select')?.value || '').toUpperCase();
  const analysisText = document.getElementById('out')?.textContent || '';
  
  // Generar título inteligente
  const suggestedTitle = generateSmartTitle(symbol, analysisText);
  
  // Generar contenido del modal dinámicamente
  const msBody = document.getElementById('ms-body');
  if (msBody) {
    msBody.innerHTML = `
      <div class="grid md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm text-gray-700">Título</label>
          <input id="ms-title" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" value="${suggestedTitle.replace(/"/g,'&quot;')}">
        </div>
        <div>
          <label class="block text-sm text-gray-700">Resultado</label>
          <select id="ms-outcome" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            <option value="">--</option>
            <option value="pos">Positivo</option>
            <option value="neg">Negativo</option>
            <option value="neutro">Neutro</option>
          </select>
        </div>
        <div class="flex items-center gap-2">
          <input id="ms-traded" type="checkbox">
          <label class="text-sm text-gray-700">Operé según este análisis</label>
        </div>
      </div>
      <div>
        <label class="block text-sm text-gray-700">Notas</label>
        <textarea id="ms-notes" rows="4" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="Entrada, gestión, salida, aprendizajes..."></textarea>
      </div>
      <div>
        <label class="block text-sm text-gray-700">Análisis (texto)</label>
        <pre class="bg-gray-50 p-2 rounded text-xs overflow-auto max-h-64 text-gray-800">${(analysisText||'No hay análisis para mostrar').replace(/</g,'&lt;')}</pre>
      </div>
      <div>
        <label class="block text-sm text-gray-700">Adjuntar capturas</label>
        <input id="ms-files" type="file" accept="image/*" multiple class="mt-1"/>
        <div id="ms-previews" class="mt-2 grid grid-cols-4 gap-2"></div>
      </div>
    `;
  }
});

msClose?.addEventListener('click', ()=> msShow(false));
msCancel?.addEventListener('click', ()=> msShow(false));

// Delegación de eventos para archivos
document.addEventListener('change', (e)=>{
  const inp = e.target.closest('#ms-files'); 
  if (!inp) return;
  const files = Array.from(inp.files||[]);
  const previews = document.getElementById('ms-previews');
  if (!previews) return;
  
  previews.innerHTML='';
  for (const f of files.slice(0,6)){
    const url = URL.createObjectURL(f);
    const img = document.createElement('img'); 
    img.src=url; 
    img.className='rounded border max-h-24 object-cover w-full';
    previews.appendChild(img);
  }
});

async function uploadImagesIfAny(){
  const filesInput = document.getElementById('ms-files');
  const files = Array.from(filesInput?.files||[]);
  const out = [];
  for (const f of files.slice(0,6)){
    const fd = new FormData(); 
    fd.append('file', f);
    const url = `${Config.API_BASE}/analysis_upload.php`;
    const r = await fetch(url, { 
      method:'POST', 
      headers: Config.withAuthHeaders({}, true), 
      body: fd 
    });
    const txt = await r.text(); 
    let j; 
    try{ 
      j = JSON.parse(txt); 
    } catch { 
      throw new Error('Respuesta no JSON'); 
    }
    if (!r.ok || !j?.ok) throw new Error(j?.error || 'Fallo al subir');
    out.push({ url: j.url, mime: j.mime, size: j.size });
  }
  return out;
}

msSave?.addEventListener('click', async ()=>{
  try{
    if (msMsg) msMsg.textContent = 'Guardando...';
    const symbol = (document.getElementById('symbol')?.value || document.getElementById('symbol-select')?.value || '').toUpperCase();
    if (!symbol) { 
      if (msMsg) msMsg.textContent='Ingresa un símbolo primero'; 
      return; 
    }
    
    const settings = collectSettingsFromUI();
    settings.symbol = symbol;
    const analysisText = document.getElementById('out')?.textContent || '';
    let atts = [];
    try{ 
      atts = await uploadImagesIfAny(); 
    } catch(e){ 
      atts = []; 
    }
    
    // Obtener valores de los elementos dinámicos del modal
    const title = document.getElementById('ms-title')?.value?.trim() || null;
    const notes = document.getElementById('ms-notes')?.value || null;
    const traded = document.getElementById('ms-traded')?.checked || false;
    const outcome = document.getElementById('ms-outcome')?.value || '';
    
    const payload = {
      symbol,
      title,
      timeframe: (Array.isArray(settings.resolutions_json) && settings.resolutions_json[0]) ? settings.resolutions_json[0] : null,
      analysis_text: analysisText,
      snapshot_json: settings,
      user_notes: notes,
      traded: !!traded,
      outcome: outcome,
      attachments: atts,
    };
    const res = await Config.postWithFallback(['analysis_save_safe.php','analysis_save.php'], payload, true);
    if (res && res.ok) { 
      if (msMsg) msMsg.textContent = 'Guardado'; 
      msShow(false); 
      Config.toast('Análisis guardado.'); 
    }
    else { 
      if (msMsg) msMsg.textContent = 'Guardado parcial'; 
    }
  } catch(e){ 
    if (msMsg) msMsg.textContent = 'Error: '+(e?.message||e); 
  }
});

function collectSettingsFromUI() {
  const blocksEls = Array.from(blocks?.children || []);
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

// Copiar resultados
document.addEventListener('click', async (e)=>{
  if (e.target && e.target.id === 'btn-copy') {
    try {
      const txt = document.getElementById('out')?.textContent || '';
      await navigator.clipboard.writeText(txt);
      Config.toast('Resultados copiados al portapapeles');
    } catch {
      Config.toast('No se pudo copiar');
    }
  }
});

async function saveAnalysis(symbol) {
  try {
    const results = document.getElementById('results');
    const analysisText = results.querySelector('.prose').textContent;
    
    const analysisData = {
      symbol,
      analysis: analysisText,
      outcome: 'pending',
      traded: false,
      attachments: []
    };

    await Config.apiPost('analysis_save_safe.php', analysisData, true);
    Notifications.toast('Análisis guardado exitosamente', 'success');
    
  } catch (error) {
    console.error('Error saving analysis:', error);
    Notifications.toast('Error al guardar análisis', 'error');
  }
}

// Función helper para formatear números
function fmt(x) { 
  return (x==null || Number.isNaN(x)) ? '—' : (typeof x==='number' ? x.toFixed(2) : x); 
}

// Exportar funciones para uso global
window.Analysis = {
  analyze,
  blockTemplate,
  generateSmartTitle,
  collectSettingsFromUI,
  msShow,
  uploadImagesIfAny,
  fmt: Config.fmt
};

// Hacer funciones disponibles globalmente para compatibilidad
window.analyze = analyze;
window.saveAnalysis = saveAnalysis;
window.collectSettingsFromUI = collectSettingsFromUI;
