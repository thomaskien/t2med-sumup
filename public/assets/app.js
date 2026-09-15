'use strict';
const $ = id => document.getElementById(id);
const euro = cents => new Intl.NumberFormat('de-DE', {style:'currency', currency:'EUR'}).format(cents / 100);
let state = null, visitId = new URL(location.href).searchParams.get('v'), csrf = '', timer = null, busy = false;
let selected = new Set(), retrySelection = false, startRequest = null;
let receiptShare = null;
function message(text='') { $('message').textContent = text; $('message').hidden = !text; }
async function api(action, data={}) {
  const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 75000);
  try {
    const response = await fetch('/api.php', {method:'POST', credentials:'same-origin', cache:'no-store', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body:JSON.stringify({action, visit_id:visitId, ...data}), signal:controller.signal});
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Anfrage fehlgeschlagen.');
    return result;
  } catch(error) {
    if (error.name === 'AbortError' || error instanceof TypeError) throw new Error('Verbindung unterbrochen. Bitte erneut versuchen oder Seite laden. Der bestehende Vorgang bleibt gespeichert.');
    throw error;
  } finally { clearTimeout(timeout); }
}
async function run(fn) {
  if (busy) return; busy = true; message();
  document.querySelectorAll('button').forEach(b => b.disabled = true);
  try { await fn(); } catch(e) { message(e.message); }
  finally { busy = false; document.querySelectorAll('button').forEach(b => b.disabled = false); if(state) renderPayment(); updateTotal(); }
}
function element(tag, text, className='') { const el = document.createElement(tag); el.textContent = text; if(className) el.className=className; return el; }
function manualCents() {
  const value = $('manual').value.trim(); if (!value) return 0;
  const match = /^(0|[1-9]\d{0,6})(?:[,.](\d{1,2}))?$/.exec(value);
  return match ? Number(match[1])*100 + Number((match[2] || '').padEnd(2,'0')) : NaN;
}
function updateTotal() {
  if (!state) return;
  const sum = state.services.filter(s => selected.has(s.id)).reduce((total,s) => total+s.price_cents, 0) + manualCents();
  $('total').textContent = Number.isFinite(sum) ? euro(sum) : '—';
  $('pay').textContent = sum > 0 ? euro(sum) + ' mit Karte kassieren' : 'Mit Karte kassieren';
  $('pay').disabled = busy || !(sum > 0 && sum <= state.max_amount_cents);
}
function renderServices() {
  $('services').replaceChildren(); $('manage-list').replaceChildren();
  const active = new Set(state.services.map(s => s.id)); selected = new Set([...selected].filter(id => active.has(id)));
  $('empty').hidden = state.services.length > 0;
  for (const service of state.services) {
    const label = element('label','','service'); const checkbox = document.createElement('input'); checkbox.type='checkbox'; checkbox.checked=selected.has(service.id);
    checkbox.addEventListener('change', () => { checkbox.checked ? selected.add(service.id) : selected.delete(service.id); startRequest=null; updateTotal(); });
    label.append(checkbox, element('span',service.label,'label'), element('span',euro(service.price_cents),'price')); $('services').append(label);
    const row = element('div','','manage-row'); row.append(element('span', service.label+' · '+euro(service.price_cents)));
    const edit = element('button','Bearbeiten','text-button'); edit.onclick=() => { $('service-id').value=service.id; $('service-label').value=service.label; $('service-price').value=(service.price_cents/100).toFixed(2).replace('.',','); $('service-save').textContent='Änderung speichern'; $('edit-reset').hidden=false; $('service-label').focus(); };
    const del = element('button','Löschen','text-button danger'); del.onclick=() => run(async() => { if(!confirm('„'+service.label+'“ aus der Auswahl löschen? Frühere Zahlungen bleiben erhalten.')) return; await api('service_delete',{id:service.id}); resetForm(); await load(); });
    row.append(edit,del); $('manage-list').append(row);
  }
  updateTotal();
}
function resetForm() { $('service-form').reset(); $('service-id').value=''; $('service-save').textContent='Leistung hinzufügen'; $('edit-reset').hidden=true; }
function manage(show) { $('manager').hidden=!show; $('manage-toggle').setAttribute('aria-expanded',String(show)); if(show) $('manager').scrollIntoView({behavior:'smooth',block:'nearest'}); }
function renderPayment() {
  const p = state.payment; const showing = p && !retrySelection;
  $('selection').hidden=!!showing; $('payment').hidden=!showing;
  if (showing) manage(false);
  for (const id of ['document','receipt','receipt-share','retry','cancel','mock-controls','mock-fhir-label']) $(id).hidden=true;
  if(!showing || p.payment_status!=='successful' || receiptShare?.paymentId!==p.id) { $('receipt-share-panel').hidden=true; receiptShare=null; }
  clearTimeout(timer);
  if (!showing) return;
  $('receipt').hidden=p.payment_status!=='successful';
  $('receipt-share').hidden=p.payment_status!=='successful';
  $('payment-amount').textContent=euro(p.amount_cents); $('payment-reference').textContent=p.reference;
  $('payment-error').textContent=p.error_message; $('payment-error').hidden=!p.error_message;
  let title, description, symbol='…';
  if(p.doc_status==='written') {
    title='Vorgang abgeschlossen'; symbol='✓';
    description=state.fhir_mock ? 'Testdokumentation gespeichert. Es wurde nichts in t2med geschrieben. Dieses Fenster kann geschlossen werden.' : 'Die Zahlung wurde in t2med dokumentiert. Dieses Fenster kann geschlossen werden.';
  } else if(p.payment_status==='successful') {
    title='Zahlung erfolgreich'; symbol='✓';
    description='Mit „Dokumentation in der Akte“ wird die Zahlung in t2med dokumentiert und der Vorgang abgeschlossen.';
    $('document').hidden=false;
    $('document').textContent=p.doc_status==='unknown' || p.doc_status==='writing' ? 'Dokumentation prüfen / erneut versuchen' : 'Dokumentation in der Akte';
    $('mock-fhir-label').hidden=!state.mock;
    $('mock-fhir-fail').checked=state.mock_document_fail;
  } else if(['failed','cancelled'].includes(p.payment_status)) {
    title=p.payment_status==='failed'?'Zahlung nicht erfolgt':'Zahlung abgebrochen'; symbol='×'; description='Du kannst die Zahlung erneut starten.'; $('retry').hidden=false;
  } else {
    title=p.payment_status==='unknown' || p.payment_status==='starting'?'Zahlung wird geprüft':p.payment_status==='cancel_requested'?'Abbruch wird geprüft':'Zahlung läuft';
    description='Bitte den Hinweisen am Terminal folgen. Dieses Fenster bis zum Abschluss geöffnet lassen.';
    $('cancel').hidden=false; $('mock-controls').hidden=!state.mock;
    timer=setTimeout(poll,2200);
  }
  $('payment-title').textContent=title; $('payment-description').textContent=description; $('status-symbol').textContent=symbol;
}
async function load() {
  state=await api('state'); csrf=state.csrf;
  $('welcome').hidden=true; $('workspace').hidden=false;
  $('mode').hidden=!state.mock && !state.fhir_mock;
  $('mode').textContent=state.mock?'TESTMODUS':state.fhir_mock?'T2MED TESTMODUS':'';
  $('patient-name').textContent=state.patient.name;
  $('patient-birth').textContent=state.patient.birthdate ? 'Geboren am '+state.patient.birthdate.split('-').reverse().join('.') : '';
  renderServices(); renderPayment();
}
async function poll() {
  if(busy) { timer=setTimeout(poll,2200); return; }
  try { state.payment=await api('status',{payment_id:state.payment.id}); renderPayment(); }
  catch(e) { message(e.message); timer=setTimeout(poll,5000); }
}
$('manual').addEventListener('input',()=>{ startRequest=null; updateTotal(); });
$('manage-toggle').onclick=()=>manage($('manager').hidden);
$('manage-close').onclick=()=>manage(false);
$('edit-reset').onclick=resetForm;
$('service-form').onsubmit=e=>{ e.preventDefault(); run(async()=>{ await api('service_save',{id:$('service-id').value,label:$('service-label').value,price:$('service-price').value}); resetForm(); await load(); }); };
$('pay').onclick=()=>run(async()=>{
  const data={services:[...selected].sort(),manual_amount:$('manual').value.trim()};
  // Schlüssel und Anfrage vor dem Netzaufruf speichern; bei verlorener Antwort wiederverwenden.
  if(!startRequest) startRequest={...data,request_id:crypto.randomUUID()};
  sessionStorage.setItem('ks-start-'+visitId,JSON.stringify(startRequest));
  state.payment=await api('start',startRequest); retrySelection=false;
  sessionStorage.removeItem('ks-start-'+visitId); startRequest=null; renderPayment();
});
$('retry').onclick=()=>{ retrySelection=true; startRequest=null; renderPayment(); updateTotal(); };
$('cancel').onclick=()=>run(async()=>{ state.payment=await api('cancel',{payment_id:state.payment.id}); });
$('receipt').onclick=()=>{
  if(busy || state?.payment?.payment_status!=='successful') return;
  const query=new URLSearchParams({v:visitId,p:state.payment.id});
  window.open('/receipt.php?'+query, '_blank', 'noopener,noreferrer');
};
$('receipt-share').onclick=()=>run(async()=>{
  const paymentId=state.payment.id;
  const result=await api('receipt_share',{payment_id:paymentId});
  receiptShare={...result,paymentId};
  $('receipt-share-panel').hidden=false;
  $('receipt-share-message').textContent=result.message;
  $('receipt-share-details').hidden=!result.url;
  $('receipt-link').value=result.url;
  if(result.url) $('receipt-open').href=result.url; else $('receipt-open').removeAttribute('href');
  $('receipt-email').value=result.email;
});
$('receipt-copy').onclick=()=>run(async()=>{
  if(!receiptShare?.url) return;
  try { await navigator.clipboard.writeText(receiptShare.url); $('receipt-share-message').textContent='Beleglink kopiert.'; }
  catch { $('receipt-link').focus(); $('receipt-link').select(); $('receipt-share-message').textContent='Bitte den markierten Link kopieren.'; }
});
$('receipt-email-form').onsubmit=e=>{
  e.preventDefault();
  if(busy || !receiptShare?.url || receiptShare.paymentId!==state.payment.id) return;
  const email=$('receipt-email').value.trim();
  if(!email || /[\r\n]/.test(email) || !$('receipt-email-form').reportValidity()) return;
  const body='Guten Tag,\r\n\r\nhier finden Sie Ihren Zahlungsbeleg über '+euro(state.payment.amount_cents)+':\r\n'+receiptShare.url+'\r\n\r\nMit freundlichen Grüßen';
  location.href='mailto:'+encodeURIComponent(email)+'?subject='+encodeURIComponent('Ihr Zahlungsbeleg')+'&body='+encodeURIComponent(body);
};
$('document').onclick=()=>run(async()=>{
  state.payment=await api('document',{payment_id:state.payment.id}); renderPayment();
  if(state.payment.doc_status==='written') { state.completed=true; setTimeout(()=>window.close(),900); }
});
$('mock-success').onclick=()=>run(async()=>{ await api('mock',{payment_id:state.payment.id,status:'successful'}); await load(); });
$('mock-fail').onclick=()=>run(async()=>{ await api('mock',{payment_id:state.payment.id,status:'failed'}); await load(); });
$('mock-fhir-fail').onchange=()=>run(async()=>{ await api('mock',{document_fail:$('mock-fhir-fail').checked}); await load(); });
$('demo').onclick=()=>run(async()=>{ const result=await api('demo'); location.href=result.url; location.reload(); });
async function init() {
  const fragment=new URLSearchParams(location.hash.slice(1)); const ticket=fragment.get('launch');
  if(ticket) {
    history.replaceState(null,'',location.pathname);
    const result=await api('exchange',{ticket}); visitId=result.visit_id; csrf=result.csrf;
    history.replaceState(null,'','/?v='+encodeURIComponent(visitId));
  }
  if(visitId) {
    const saved=sessionStorage.getItem('ks-start-'+visitId);
    if(saved) { try { startRequest=JSON.parse(saved); selected=new Set(startRequest.services); $('manual').value=startRequest.manual_amount; } catch { sessionStorage.removeItem('ks-start-'+visitId); } }
    await load();
  } else if(['localhost','127.0.0.1'].includes(location.hostname)) $('demo').hidden=false;
}
init().catch(e=>message(e.message));
