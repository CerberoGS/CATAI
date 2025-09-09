// Lightweight DOM text fixer for common UTF-8 mojibake (ISO-8859-1 misread)
// Applies only to visible text nodes; safe no-op if not needed.
(function(){
  const MAP = new Map([
    ['Ã¡','á'],['Ã©','é'],['Ã­','í'],['Ã³','ó'],['Ãº','ú'],
    ['ÃÁ','Á'],['Ã‰','É'],['ÃÍ','Í'],['Ã“','Ó'],['Ãš','Ú'],
    ['Ã±','ñ'],['Ã‘','Ñ'],
    ['Ã¼','ü'],['Ãœ','Ü'],
    ['Â¿','¿'],['Â¡','¡'],['Âº','º'],['Âª','ª'],['Â°','°'],
    ['â€“','–'],['â€”','—'],['â€˜','‘'],['â€™','’'],['â€œ','“'],['â€�','”'],['â€¦','…'],
  ]);
  function fixText(s){
    if (!s) return s;
    let out = s;
    MAP.forEach((to, from)=>{ if (out.includes(from)) out = out.split(from).join(to); });
    return out;
  }
  function walk(node){
    if (node.nodeType === Node.TEXT_NODE) {
      const fixed = fixText(node.nodeValue);
      if (fixed !== node.nodeValue) node.nodeValue = fixed;
      return;
    }
    for (const ch of node.childNodes) walk(ch);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ()=>walk(document.body));
  else walk(document.body);
})();

// Extra pass: targeted Spanish mojibake fixes (safe to run twice)
(function(){
  const pairs = [
    ['s��','sí'], ['S��mbolo','Símbolo'], ['s�mbolo','símbolo'], ['an�lisis','análisis'], ['obt�n','obtén'],
    ['Contrase�a','Contraseña'], ['Sesi�n','Sesión'], ['sesi�n','sesión'], ['expiraci�n','expiración'], ['�ltimo','último'],
    ['b�squeda','búsqueda'], ['vac�a','vacía'], ['p�rdida','pérdida'], ['pesta�a','pestaña'], ['intrad�a','intradía'],
    ['Recomendaci�n','Recomendación'], ['Duraci�n','Duración'], ['PETICI�N','PETICIÓN'], ['env�a','envía'],
    ['pr�ximo','próximo'], ['pr�xima','próxima'], ['Cargando lista�?�','Cargando lista…'], [' - ok:',' — ok:']
  ];
  const MAP2 = new Map(pairs);
  function fix(s){ if(!s) return s; let out = s; MAP2.forEach((to, from)=>{ if(out.includes(from)) out = out.split(from).join(to); }); return out; }
  function walk(n){
    if (n.nodeType === Node.TEXT_NODE) { const f=fix(n.nodeValue); if (f!==n.nodeValue) n.nodeValue=f; return; }
    for (const ch of n.childNodes) walk(ch);
  }
  function observe(){
    const mo = new MutationObserver(muts => {
      for (const m of muts) {
        if (m.type === 'childList') {
          m.addedNodes && m.addedNodes.forEach(n => walk(n));
        } else if (m.type === 'characterData') {
          const t = m.target; if (t && t.nodeType === Node.TEXT_NODE) { const f=fix(t.nodeValue); if (f!==t.nodeValue) t.nodeValue=f; }
        }
      }
    });
    mo.observe(document.body, { subtree:true, childList:true, characterData:true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ()=>{ walk(document.body); observe(); });
  else { walk(document.body); observe(); }
})();
