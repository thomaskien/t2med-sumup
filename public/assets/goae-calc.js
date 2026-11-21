// GOÄ-Rechner (gemeinsam für Kassenseite und Adminpanel): Einfachsatz + Endbetrag → Faktor und Preis.
function parseCents(value) {
  const v=String(value||'').trim().replace(/\s|€/g,'').replace(/\.(?=\d{3}(\D|$))/g,'').replace(',','.');
  if(!/^\d+(\.\d{1,2})?$/.test(v)) return null;
  return Math.round(Number(v)*100);
}
// Faktor in Zehntausendsteln (23000 = 2,3): bis zu 4 Nachkommastellen, damit Endbeträge immer exakt einstellbar sind.
const fee=(base,u)=>Math.floor((base*u+5000)/10000);
const commaFactor=u=>(u/10000).toFixed(4).replace(/0+$/,'').replace(/\.$/,'').replace('.',',');
const factorUnits=text=>{ const [w,f='']=String(text).replace(',','.').split('.'); return Number(w)*10000+Number(f.padEnd(4,'0').slice(0,4)); };
function bestFactor(base,target) {
  const ideal=target*10000/base; let best=null;
  for(const step of [100,10,1]) {                 // 2, 3, dann 4 Nachkommastellen
    const center=Math.round(ideal/step)*step; let exact=null;
    for(let u=center-4*step; u<=center+4*step; u+=step) {
      const unit=Math.max(10000,u), p=fee(base,unit);
      if(p===target && (!exact || Math.abs(unit-ideal)<Math.abs(exact[0]-ideal))) exact=[unit,p];
      if(!best || Math.abs(p-target)<Math.abs(best[1]-target) || (Math.abs(p-target)===Math.abs(best[1]-target) && Math.abs(unit-ideal)<Math.abs(best[0]-ideal))) best=[unit,p];
    }
    if(exact) { best=exact; break; }
  }
  return best;
}
