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

    // ===== UX: Renderizado agradable =====
    const recColor = finalRec === 'COMPRAR' ? 'bg-green-100 text-green-800' : (finalRec === 'VENDER' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800');
    const recColorDark = finalRec === 'COMPRAR' ? 'dark-mode:bg-green-900 dark-mode:text-green-200' : (finalRec === 'VENDER' ? 'dark-mode:bg-red-900 dark-mode:text-red-200' : 'dark-mode:bg-yellow-900 dark-mode:text-yellow-200');

    const rowsPerRes = perRes.map(r => `
      <tr class="border-b last:border-0 border-gray-200 dark-mode:border-gray-700">
        <td class="px-3 py-2 text-sm whitespace-nowrap">${r.reso}</td>
        <td class="px-3 py-2 text-sm">${Config.fmt(r.last.price)}</td>
        <td class="px-3 py-2 text-sm">${Config.fmt(r.last.rsi14)}</td>
        <td class="px-3 py-2 text-sm">${Config.fmt(r.last.sma20)}</td>
        <td class="px-3 py-2 text-sm">${Config.fmt(r.last.ema20)}</td>
        <td class="px-3 py-2 text-sm hidden md:table-cell">${Config.fmt(r.last.ema40)}</td>
        <td class="px-3 py-2 text-sm hidden md:table-cell">${Config.fmt(r.last.ema100)}</td>
        <td class="px-3 py-2 text-sm hidden md:table-cell">${Config.fmt(r.last.ema200)}</td>
      </tr>
    `).join('');

    const optionsHtml = (() => {
      if (!chain || !Array.isArray(chain.chain) || !chain.chain.length) {
        return `<p class="text-sm text-gray-600 dark-mode:text-gray-300">Sin datos de opciones disponibles recientemente.</p>`;
      }

      // Datos base
      const items = chain.chain;
      const expirations = Array.from(new Set(items.map(c => c.expiration).filter(Boolean)));
      // Preferencias persistentes (símbolo y global)
      const keySymbol = `oc_prefs_${symbol}`;
      const keyGlobal = `oc_prefs_global`;
      function loadKey(k){ try{ return JSON.parse(localStorage.getItem(k)||'{}'); }catch{ return {}; } }
      function saveKey(k, p){ try{ localStorage.setItem(k, JSON.stringify(p)); }catch{} }
      function getPrefs(){
        const g = loadKey(keyGlobal);
        if (g && g.global) return g;
        const s = loadKey(keySymbol);
        return s || {};
      }
      function setPrefs(p){
        if (p.global){ saveKey(keyGlobal, p); }
        else { saveKey(keySymbol, p); }
      }
      const prefs = getPrefs();
      const defaultExp = (prefs.exp && expirations.includes(prefs.exp)) ? prefs.exp : expirations[0];
      const windowDefault = Number.isFinite(prefs.win) ? prefs.win : 5;

      // Helper render
      function render(exp, win){
        const byExp = items.filter(c => c.expiration === exp);
        if (!byExp.length) return `<p class=\"text-sm text-gray-600 dark-mode:text-gray-300\">Sin strikes para ${exp}</p>`;
        const strikes = Array.from(new Set(byExp.map(c => c.strike))).sort((a,b)=>(a??0)-(b??0));
        const atmStrike = (lastPrice!=null)
          ? strikes.reduce((p, s) => Math.abs((s??0)-lastPrice) < Math.abs((p??0)-lastPrice) ? s : p, strikes[0])
          : strikes[Math.floor(strikes.length/2)];
        const centerIdx = strikes.indexOf(atmStrike);
        const start = Math.max(0, centerIdx - win);
        const end = Math.min(strikes.length - 1, centerIdx + win);
        const windowStrikes = strikes.slice(start, end + 1);

        // Estrategia y modo de precio
        const defaultStrategy = prefs.strategy || ((finalRec === 'COMPRAR') ? 'simple_call' : (finalRec === 'VENDER' ? 'simple_put' : 'simple_call'));
        const strategy = (document.getElementById('oc-strategy')?.value) || defaultStrategy;
        const priceMode = (document.getElementById('oc-price')?.value) || (prefs.price || 'mid');

        function px(mode, legType, opt){
          if (!opt) return null;
          const b = Number(opt.bid); const a = Number(opt.ask);
          const last = Number(opt.last);
          if (mode === 'bid') return Number.isFinite(b)? b : (Number.isFinite(last)? last : null);
          if (mode === 'ask') return Number.isFinite(a)? a : (Number.isFinite(last)? last : null);
          // mid
          if (Number.isFinite(b) && Number.isFinite(a)) return (b + a) / 2;
          return Number.isFinite(last)? last : (Number.isFinite(b)? b : (Number.isFinite(a)? a : null));
        }

        // Selección de legs según estrategia
        let legs = [];
        const callAtm = byExp.find(x => x.type==='call' && x.strike===atmStrike) || null;
        const putAtm  = byExp.find(x => x.type==='put'  && x.strike===atmStrike) || null;
        const nextAbove = strikes[centerIdx+1] ?? strikes[centerIdx];
        const nextBelow = strikes[centerIdx-1] ?? strikes[centerIdx];
        const callAbove = byExp.find(x => x.type==='call' && x.strike===nextAbove) || null;
        const putBelow  = byExp.find(x => x.type==='put'  && x.strike===nextBelow) || null;

        if (strategy === 'simple_call') {
          legs = [{ side:'BUY', type:'call', strike: atmStrike, opt: callAtm }];
        } else if (strategy === 'simple_put') {
          legs = [{ side:'BUY', type:'put', strike: atmStrike, opt: putAtm }];
        } else if (strategy === 'vertical_call_debit') {
          legs = [
            { side:'BUY',  type:'call', strike: atmStrike, opt: callAtm },
            { side:'SELL', type:'call', strike: nextAbove, opt: callAbove }
          ];
        } else if (strategy === 'vertical_put_debit') {
          legs = [
            { side:'BUY',  type:'put', strike: atmStrike, opt: putAtm },
            { side:'SELL', type:'put', strike: nextBelow, opt: putBelow }
          ];
        }

        const legNet = legs.reduce((sum, l)=>{
          const p = px(priceMode, l.type, l.opt);
          if (!Number.isFinite(p)) return sum;
          return sum + (l.side==='BUY' ? +p : -p);
        }, 0);

        const rows = windowStrikes.map(s => {
          const c = byExp.find(x => x.type === 'call' && x.strike === s) || {};
          const p = byExp.find(x => x.type === 'put'  && x.strike === s) || {};
          const isAtm = s === atmStrike;
          const isLegCall = legs.some(l => l.type==='call' && l.strike===s);
          const isLegPut  = legs.some(l => l.type==='put'  && l.strike===s);
          const trClass = [
            isAtm ? 'bg-indigo-50 dark-mode:bg-indigo-900/30' : '',
          ].join(' ');
          const cellHLCall = isLegCall ? 'ring-2 ring-indigo-400 dark-mode:ring-indigo-300 ring-offset-0' : '';
          const cellHLPut  = isLegPut  ? 'ring-2 ring-indigo-400 dark-mode:ring-indigo-300 ring-offset-0' : '';

          // Moneyness: ITM shading
          const isCallITM = (lastPrice!=null && s!=null) ? (s < lastPrice) : false; // Call ITM: strike < spot
          const isPutITM  = (lastPrice!=null && s!=null) ? (s > lastPrice) : false; // Put ITM: strike > spot
          const itmCallBg = isCallITM ? 'bg-violet-50 dark-mode:bg-violet-900/30' : '';
          const itmPutBg  = isPutITM  ? 'bg-violet-50 dark-mode:bg-violet-900/30' : '';
          const atmBadge  = isAtm ? '<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-800 dark-mode:bg-indigo-900 dark-mode:text-indigo-200">ATM</span>' : '';
          return `
            <tr class=\"${trClass} border-b last:border-0 border-gray-200 dark-mode:border-gray-700\">
              <td class=\"px-2 py-1 text-xs md:text-sm ${cellHLCall} ${itmCallBg}\">${Config.fmt(c.last)}</td>
              <td class=\"px-2 py-1 text-xs md:text-sm ${cellHLCall} ${itmCallBg}\">${Config.fmt(c.bid)}</td>
              <td class=\"px-2 py-1 text-xs md:text-sm ${cellHLCall} ${itmCallBg}\">${Config.fmt(c.ask)}</td>
              <td class=\"px-2 py-1 text-xs md:text-sm ${cellHLCall} ${itmCallBg}\">${Config.fmt(c.iv)}</td>
              <td class=\"px-2 py-1 text-xs md:text-sm ${cellHLCall} ${itmCallBg}\">${Config.fmt(c.delta)}</td>
              <td class=\"px-2 py-1 text-xs md:text-sm font-medium text-center\">${Config.fmt(s)}${atmBadge}</td>
              <td class=\"px-2 py-1 text-xs md:text-sm ${cellHLPut} ${itmPutBg}\">${Config.fmt(p.delta)}</td>
              <td class=\"px-2 py-1 text-xs md:text-sm ${cellHLPut} ${itmPutBg}\">${Config.fmt(p.iv)}</td>
              <td class=\"px-2 py-1 text-xs md:text-sm ${cellHLPut} ${itmPutBg}\">${Config.fmt(p.bid)}</td>
              <td class=\"px-2 py-1 text-xs md:text-sm ${cellHLPut} ${itmPutBg}\">${Config.fmt(p.ask)}</td>
              <td class=\"px-2 py-1 text-xs md:text-sm ${cellHLPut} ${itmPutBg}\">${Config.fmt(p.last)}</td>
            </tr>`;
        }).join('');

        // Plan TP/SL sobre el neto (debit/credit)
        const tpPct = parseFloat(document.getElementById('tp')?.value || '5');
        const slPct = parseFloat(document.getElementById('sl')?.value || '3');
        const entryText = Number.isFinite(legNet) ? Config.fmt(legNet) : '—';
        const tpText = (Number.isFinite(legNet) && Number.isFinite(tpPct)) ? Config.fmt(legNet * (legNet>=0 ? (1+tpPct/100) : (1 - tpPct/100))) : '—';
        const slText = (Number.isFinite(legNet) && Number.isFinite(slPct)) ? Config.fmt(legNet * (legNet>=0 ? (1-slPct/100) : (1 + slPct/100))) : '—';

        return `
          <div class=\"mb-2 flex flex-wrap items-center gap-3 text-sm\">
            <label class=\"text-gray-600 dark-mode:text-gray-300\">Expiración</label>
            <select id=\"oc-exp\" class=\"border rounded px-2 py-1 text-sm\">${expirations.map(e => `<option ${e===exp?'selected':''}>${e}</option>`).join('')}</select>
            <label class=\"text-gray-600 dark-mode:text-gray-300\">± Strikes</label>
            <input id=\"oc-win\" type=\"number\" min=\"1\" max=\"15\" value=\"${win}\" class=\"w-20 border rounded px-2 py-1 text-sm\"/>
            <label class=\"text-gray-600 dark-mode:text-gray-300\">Estrategia</label>
            <select id=\"oc-strategy\" class=\"border rounded px-2 py-1 text-sm\">
              <option value=\"simple_call\" ${strategy==='simple_call'?'selected':''}>Simple CALL</option>
              <option value=\"simple_put\" ${strategy==='simple_put'?'selected':''}>Simple PUT</option>
              <option value=\"vertical_call_debit\" ${strategy==='vertical_call_debit'?'selected':''}>Vertical CALL (debit)</option>
              <option value=\"vertical_put_debit\" ${strategy==='vertical_put_debit'?'selected':''}>Vertical PUT (debit)</option>
            </select>
            <label class=\"text-gray-600 dark-mode:text-gray-300\">Precio</label>
            <select id=\"oc-price\" class=\"border rounded px-2 py-1 text-sm\">
              <option value=\"mid\" ${priceMode==='mid'?'selected':''}>Mid</option>
              <option value=\"bid\" ${priceMode==='bid'?'selected':''}>Bid</option>
              <option value=\"ask\" ${priceMode==='ask'?'selected':''}>Ask</option>
            </select>
            <label class=\"inline-flex items-center gap-1 ml-2 text-gray-600 dark-mode:text-gray-300\"><input id=\"oc-global\" type=\"checkbox\" ${prefs.global?'checked':''}/> Recordar globalmente</label>
            <span class=\"ml-auto text-xs text-gray-500 dark-mode:text-gray-400\">ATM: ${Config.fmt(atmStrike)} ${lastPrice!=null?`· Px ${Config.fmt(lastPrice)}`:''}</span>
          </div>
          <div class=\"mb-2 flex flex-wrap items-center gap-2 text-[11px]\">
            <span class=\"px-2 py-0.5 rounded bg-violet-50 text-violet-800 dark-mode:bg-violet-900/30 dark-mode:text-violet-200\">ITM: Call strike < spot · Put strike > spot</span>
            <span class=\"px-2 py-0.5 rounded bg-gray-100 text-gray-800 dark-mode:bg-gray-700 dark-mode:text-gray-100\">OTM: sin valor intrínseco</span>
            <span class=\"px-2 py-0.5 rounded bg-indigo-100 text-indigo-800 dark-mode:bg-indigo-900 dark-mode:text-indigo-200\">ATM: strike ≈ spot</span>
          </div>
          <div class=\"overflow-x-auto rounded-lg border border-gray-200 dark-mode:border-gray-700\">
            <table class=\"min-w-full text-left text-xs md:text-sm\">
              <thead class=\"bg-gray-50 dark-mode:bg-gray-800\" title=\"ITM/OTM/ATM según moneyness: Call ITM si strike < spot; Put ITM si strike > spot; ATM ≈ spot\">
                <tr>
                  <th class=\"px-2 py-2\" colspan=\"5\" title=\"Lado Calls\">CALLS</th>
                  <th class=\"px-2 py-2 text-center\" title=\"Precio de ejercicio (strike)\">Strike</th>
                  <th class=\"px-2 py-2\" colspan=\"5\" title=\"Lado Puts\">PUTS</th>
                </tr>
                <tr class=\"text-[11px] md:text-xs text-gray-600 dark-mode:text-gray-300\">
                  <th class=\"px-2 py-1\" title=\"Última operación\">Last</th>
                  <th class=\"px-2 py-1\" title=\"Mejor precio comprador\">Bid</th>
                  <th class=\"px-2 py-1\" title=\"Mejor precio vendedor\">Ask</th>
                  <th class=\"px-2 py-1\" title=\"Volatilidad Implícita\">IV</th>
                  <th class=\"px-2 py-1\" title=\"Delta\">Δ</th>
                  <th class=\"px-2 py-1 text-center\"></th>
                  <th class=\"px-2 py-1\" title=\"Delta\">Δ</th>
                  <th class=\"px-2 py-1\" title=\"Volatilidad Implícita\">IV</th>
                  <th class=\"px-2 py-1\" title=\"Mejor precio comprador\">Bid</th>
                  <th class=\"px-2 py-1\" title=\"Mejor precio vendedor\">Ask</th>
                  <th class=\"px-2 py-1\" title=\"Última operación\">Last</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
          <div class=\"mt-2 text-sm\">
            <div class=\"inline-flex items-center gap-2 px-3 py-1 rounded bg-gray-100 text-gray-800 dark-mode:bg-gray-700 dark-mode:text-gray-100\">
              <span class=\"font-medium\">Setup</span>
              <span>${strategy.replaceAll('_',' ')}</span>
              <span>· Precio(${priceMode}): <strong>${entryText}</strong></span>
              <span>· TP: <strong>${tpText}</strong></span>
              <span>· SL: <strong>${slText}</strong></span>
            </div>
          </div>`;
      }

      // Primer render y wiring para filtros
      const first = render(defaultExp, windowDefault);
      setTimeout(() => {
        const container = document.getElementById('oc-container');
        if (!container) return;
        const refresh = () => {
          const expSel = document.getElementById('oc-exp')?.value || defaultExp;
          const win = parseInt(document.getElementById('oc-win')?.value || String(windowDefault), 10) || windowDefault;
          const stratSel = document.getElementById('oc-strategy')?.value || defaultStrategy;
          const priceSel = document.getElementById('oc-price')?.value || (prefs.price || 'mid');
          const global = !!document.getElementById('oc-global')?.checked;
          setPrefs({ exp: expSel, win, strategy: stratSel, price: priceSel, global });
          container.innerHTML = render(expSel, Math.max(1, Math.min(15, win)));
          // Re-attach after re-render
          setTimeout(() => {
            document.getElementById('oc-exp')?.addEventListener('change', refresh);
            document.getElementById('oc-win')?.addEventListener('input', refresh);
            document.getElementById('oc-strategy')?.addEventListener('change', refresh);
            document.getElementById('oc-price')?.addEventListener('change', refresh);
            document.getElementById('oc-global')?.addEventListener('change', refresh);
          });
        };
        document.getElementById('oc-exp')?.addEventListener('change', refresh);
        document.getElementById('oc-win')?.addEventListener('input', refresh);
        document.getElementById('oc-strategy')?.addEventListener('change', refresh);
        document.getElementById('oc-price')?.addEventListener('change', refresh);
        document.getElementById('oc-global')?.addEventListener('change', refresh);
      });

      return `<div id=\"oc-container\">${first}</div>`;
    })();

    const amount = parseFloat(document.getElementById('amount')?.value || '1000');
    const tp = parseFloat(document.getElementById('tp')?.value || '5');
    const sl = parseFloat(document.getElementById('sl')?.value || '3');
    const planHtml = (!Number.isNaN(tp) && !Number.isNaN(sl) && lastPrice)
      ? (() => {
          const target = lastPrice * (1 + tp/100);
          const stop = lastPrice * (1 - sl/100);
          return `<div class="text-sm">TP ${tp}% → ${Config.fmt(target)} · SL ${sl}% → ${Config.fmt(stop)} · Capital: $${amount}</div>`;
        })()
      : `<div class="text-sm text-gray-600 dark-mode:text-gray-300">Completa TP/SL para ver el plan sugerido.</div>`;

    const aiText = aiRes?.text || (aiRes?.error ? `(IA error: ${aiRes.error}${aiRes.detail? ' — '+aiRes.detail: ''})` : '(IA sin respuesta)');

    const html = `
      <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
          <span class="inline-flex items-center gap-2 text-sm px-3 py-1 rounded-full bg-gray-100 text-gray-800 dark-mode:bg-gray-700 dark-mode:text-gray-100">Símbolo <strong>${symbol}</strong></span>
          ${lastPrice!=null ? `<span class="text-sm px-3 py-1 rounded-full bg-gray-100 text-gray-800 dark-mode:bg-gray-700 dark-mode:text-gray-100">Precio: ${Config.fmt(lastPrice)}</span>` : ''}
          <span class="text-sm px-3 py-1 rounded-full ${recColor} ${recColorDark}">Recomendación: ${finalRec}</span>
          <span class="text-sm px-3 py-1 rounded-full bg-blue-100 text-blue-800 dark-mode:bg-blue-900 dark-mode:text-blue-200">BUY ${buySignals}</span>
          <span class="text-sm px-3 py-1 rounded-full bg-rose-100 text-rose-800 dark-mode:bg-rose-900 dark-mode:text-rose-200">SELL ${sellSignals}</span>
        </div>

        <div class="rounded-lg border border-gray-200 dark-mode:border-gray-700 overflow-hidden">
          <div class="px-4 py-2 text-sm font-medium bg-gray-50 dark-mode:bg-gray-800">Detalle por resolución</div>
          <div class="overflow-x-auto">
            <table class="min-w-full text-left">
              <thead class="bg-gray-50 dark-mode:bg-gray-800 text-xs uppercase">
                <tr>
                  <th class="px-3 py-2">Res</th>
                  <th class="px-3 py-2">Precio</th>
                  <th class="px-3 py-2">RSI14</th>
                  <th class="px-3 py-2">SMA20</th>
                  <th class="px-3 py-2">EMA20</th>
                  <th class="px-3 py-2 hidden md:table-cell">EMA40</th>
                  <th class="px-3 py-2 hidden md:table-cell">EMA100</th>
                  <th class="px-3 py-2 hidden md:table-cell">EMA200</th>
                </tr>
              </thead>
              <tbody>${rowsPerRes}</tbody>
            </table>
          </div>
        </div>

        <div class="rounded-lg border border-gray-200 dark-mode:border-gray-700 p-4">
          <div class="text-sm font-medium mb-2">Opciones (próxima expiración)</div>
          ${optionsHtml}
        </div>

        <div class="rounded-lg border border-gray-200 dark-mode:border-gray-700 p-4">
          <div class="text-sm font-medium mb-2">Plan del subyacente</div>
          ${planHtml}
        </div>

        ${aiText && aiText.trim() ? `
          <div class="rounded-lg border border-gray-200 dark-mode:border-gray-700 p-4">
            <div class="text-sm font-medium mb-2">IA (${aiProvider}${aiModel?": "+aiModel:""})</div>
            <div class="text-sm whitespace-pre-wrap">${aiText}</div>
          </div>` : ''}
      </div>
    `;

    loading?.classList.add('hidden');
    if (out) out.innerHTML = html;
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
