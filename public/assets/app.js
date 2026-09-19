'use strict';
const $ = id => document.getElementById(id);
const euro = cents => new Intl.NumberFormat('de-DE', {style:'currency', currency:'EUR'}).format(cents / 100);
let state = null, visitId = new URL(location.href).searchParams.get('v'), csrf = '', timer = null, busy = false;
let selected = new Set(), retrySelection = false, startRequest = null;
let selectedGroups = new Set(), reasons = {};
let readerTimer = null, readerChecking = false, readerMessage = '';
let receiptShare = null, receiptRecipient = null, receiptMailRequest = null;
let receiptPrintPayment = null;
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
  const ids=selectedIds();
  const sum = state.services.filter(s => ids.has(s.id)).reduce((total,s) => total+s.price_cents, 0) + manualCents();
  $('total').textContent = Number.isFinite(sum) ? euro(sum) : '—';
  $('pay').textContent = sum > 0 ? euro(sum) + ' mit Karte kassieren' : 'Mit Karte kassieren';
  $('pay').disabled = busy || !!state.reader_payment || !(sum > 0 && sum <= state.max_amount_cents);
}
function selectedIds() {
  const ids=new Set(selected);
  for(const group of state.groups) if(selectedGroups.has(group.id)) for(const id of group.services) ids.add(id);
  return ids;
}
function serviceDescription(s) {
  return s.goae_code ? 'GOÄ '+s.goae_code+' · Faktor '+s.factor.replace('.',',') : '';
}
function renderBasket() {
  const ids=selectedIds(); $('basket-lines').replaceChildren(); $('basket').hidden=!ids.size;
  for(const s of state.services.filter(s=>ids.has(s.id))) {
    const row=element('div','','basket-line'), line=element('div','','position');
    line.append(element('span',s.label),element('strong',euro(s.price_cents))); row.append(line);
    if(s.goae_code) row.append(element('small',serviceDescription(s),'muted'));
    const needsReason=s.goae_code && Math.round(Number(s.factor)*100)>state.fee_types[s.fee_type].threshold;
    if(needsReason) {
      const label=element('label','Begründung für diese Abrechnung (erforderlich)');
      const input=document.createElement('textarea'); input.maxLength=800; input.required=true; input.value=reasons[s.id] || '';
      input.oninput=()=>{reasons[s.id]=input.value;startRequest=null;}; label.append(input); row.append(label);
    }
    $('basket-lines').append(row);
  }
  updateTotal();
}
function renderServices() {
  $('services').replaceChildren(); $('manage-list').replaceChildren(); $('groups').replaceChildren(); $('group-list').replaceChildren(); $('group-members').replaceChildren();
  const active = new Set(state.services.map(s => s.id)); selected = new Set([...selected].filter(id => active.has(id)));
  const activeGroups = new Set(state.groups.map(g=>g.id)); selectedGroups=new Set([...selectedGroups].filter(id=>activeGroups.has(id)));
  $('groups-section').hidden=!state.groups.length;
  $('empty').hidden = state.services.length > 0;
  for (const service of state.services) {
    const label = element('label','','service'); const checkbox = document.createElement('input'); checkbox.type='checkbox'; checkbox.checked=selected.has(service.id);
    checkbox.addEventListener('change', () => { checkbox.checked ? selected.add(service.id) : selected.delete(service.id); startRequest=null; renderBasket(); });
    const name=element('span',service.label,'label'); if(service.goae_code) name.append(element('small',serviceDescription(service),'muted'));
    label.append(checkbox, name, element('span',euro(service.price_cents),'price')); $('services').append(label);
    const row = element('div','','manage-row'); row.append(element('span', service.label+' · '+euro(service.price_cents)+(service.goae_code?' · '+serviceDescription(service):'')));
    const edit = element('button','Bearbeiten','text-button'); edit.onclick=() => {
      $('service-id').value=service.id; $('service-label').value=service.label; $('service-price').value=(service.price_cents/100).toFixed(2).replace('.',',');
      $('service-goae').value=service.goae_code; $('service-factor').value=(service.factor || '2.3').replace('.',','); $('service-fee-type').value=service.fee_type;
      $('service-on-request').checked=!!service.on_request; $('goae-fields').open=!!service.goae_code;
      $('service-save').textContent='Änderung speichern'; $('edit-reset').hidden=false; $('service-label').focus();
    };
    const del = element('button','Löschen','text-button danger'); del.onclick=() => run(async() => { if(!confirm('„'+service.label+'“ aus der Auswahl löschen? Frühere Zahlungen bleiben erhalten.')) return; await api('service_delete',{id:service.id}); resetForm(); await load(); });
    row.append(edit,del); $('manage-list').append(row);
    const member=element('label','','service'), memberCheck=document.createElement('input'); memberCheck.type='checkbox'; memberCheck.value=service.id;
    member.append(memberCheck,element('span',service.label,'label'),element('span',euro(service.price_cents),'price')); $('group-members').append(member);
  }
  for(const group of state.groups) {
    const sum=state.services.filter(s=>group.services.includes(s.id)).reduce((total,s)=>total+s.price_cents,0);
    const label=element('label','','service'), checkbox=document.createElement('input'); checkbox.type='checkbox'; checkbox.checked=selectedGroups.has(group.id);
    checkbox.onchange=()=>{checkbox.checked?selectedGroups.add(group.id):selectedGroups.delete(group.id);startRequest=null;renderBasket();};
    const name=element('span',group.label,'label'); name.append(element('small',state.services.filter(s=>group.services.includes(s.id)).map(s=>s.label).join(', '),'muted'));
    label.append(checkbox,name,element('span',euro(sum),'price')); $('groups').append(label);
    const row=element('div','','manage-row'); row.append(element('span',group.label+' · '+euro(sum)));
    const edit=element('button','Bearbeiten','text-button'); edit.onclick=()=>{
      $('group-id').value=group.id; $('group-label').value=group.label;
      $('group-members').querySelectorAll('input').forEach(el=>el.checked=group.services.includes(el.value));
      $('group-save').textContent='Kombination speichern'; $('group-reset').hidden=false; $('group-label').focus();
    };
    const del=element('button','Löschen','text-button danger'); del.onclick=()=>run(async()=>{
      if(!confirm('Kombination „'+group.label+'“ löschen? Die Einzelleistungen bleiben erhalten.')) return;
      await api('group_delete',{id:group.id}); resetGroup(); await load();
    });
    row.append(edit,del); $('group-list').append(row);
  }
  renderBasket();
}
function resetForm() { $('service-form').reset(); $('service-id').value=''; $('goae-fields').open=false; $('service-save').textContent='Leistung hinzufügen'; $('edit-reset').hidden=true; }
function resetGroup() { $('group-form').reset(); $('group-id').value=''; $('group-save').textContent='Kombination hinzufügen'; $('group-reset').hidden=true; }
function manage(show) { $('manager').hidden=!show; $('manage-toggle').setAttribute('aria-expanded',String(show)); if(show) $('manager').scrollIntoView({behavior:'smooth',block:'nearest'}); }
function renderReader() {
  clearTimeout(readerTimer);
  const p=state.reader_payment;
  $('reader-busy').hidden=$('selection').hidden || (!p && !readerMessage);
  $('reader-title').textContent=p ? (p.payment_status==='cancel_requested' ? 'Abbruch wird geprüft' : 'Terminal belegt') : 'Terminal bereit';
  $('reader-description').textContent=p
    ? euro(p.amount_cents)+' · '+(p.error_message || readerMessage || 'Hier läuft noch ein anderer Vorgang. Du kannst ihn am Terminal abbrechen.')
    : readerMessage;
  $('reader-reference').textContent=p?.reference || ''; $('reader-reference').hidden=!p;
  for(const id of ['reader-cancel','reader-status']) { $(id).hidden=!p; $(id).disabled=busy || readerChecking; }
  $('reader-cancel').textContent=p?.payment_status==='cancel_requested' ? 'Abbruch erneut anfragen' : 'Anderen Vorgang abbrechen';
  if(p && !$('selection').hidden && !readerChecking) readerTimer=setTimeout(pollReader,3000);
}
async function refreshReader(action) {
  const id=state.reader_payment?.id; if(!id) return;
  const result=await api(action,{payment_id:id});
  if(state.reader_payment?.id!==id) return;
  state.reader_payment=result.reader_payment; readerMessage=result.message;
}
async function pollReader() {
  if(busy || readerChecking) { readerTimer=setTimeout(pollReader,3000); return; }
  readerChecking=true; renderReader();
  try { await refreshReader('reader_status'); }
  catch(e) { readerMessage=e.message; }
  finally { readerChecking=false; renderReader(); updateTotal(); }
}
function renderPayment() {
  const p = state.payment; const showing = p && !retrySelection;
  $('selection').hidden=!!showing; $('payment').hidden=!showing;
  $('receipt-data-note').hidden=!showing || p.payment_status!=='successful' || !p.local_receipt;
  renderReader();
  if (showing) manage(false);
  for (const id of ['document','receipt','receipt-mail','receipt-print','receipt-share','retry','cancel','mock-controls','mock-fhir-label']) $(id).hidden=true;
  if(!showing || receiptPrintPayment!==p.id) { $('receipt-print-status').hidden=true; $('receipt-print').textContent='Beleg drucken'; }
  if(!showing || p.payment_status!=='successful' || receiptShare?.paymentId!==p.id) { $('receipt-share-panel').hidden=true; receiptShare=null; }
  if(!showing || p.payment_status!=='successful' || receiptRecipient?.paymentId!==p.id) {
    receiptRecipient=null; receiptMailRequest=null; $('receipt-email').value=''; mailStatus('');
  }
  clearTimeout(timer);
  if (!showing) return;
  $('receipt').hidden=p.payment_status!=='successful';
  $('receipt').textContent=p.local_receipt ? 'Rechnung / Leistungsbeleg als PDF' : 'Zahlungsbeleg / PDF';
  $('receipt-share').hidden=p.payment_status!=='successful';
  $('receipt-print').hidden=p.payment_status!=='successful' || !state.printing_enabled;
  if(p.payment_status==='successful') renderReceiptMail();
  $('payment-amount').textContent=euro(p.amount_cents); $('payment-reference').textContent=p.invoice_number ? 'Rechnungsnummer: '+p.invoice_number : p.reference;
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
  $('practice-hint').hidden=state.local_receipts;
  if(!$('service-date').value) $('service-date').value=state.today;
  $('service-date').max=state.today;
  renderServices(); renderPayment();
}
async function poll() {
  if(busy) { timer=setTimeout(poll,2200); return; }
  try { state.payment=await api('status',{payment_id:state.payment.id}); renderPayment(); }
  catch(e) { message(e.message); timer=setTimeout(poll,5000); }
}
$('manual').addEventListener('input',()=>{ startRequest=null; updateTotal(); });
$('service-date').oninput=()=>{startRequest=null;};
$('manage-toggle').onclick=()=>manage($('manager').hidden);
$('manage-close').onclick=()=>manage(false);
$('edit-reset').onclick=resetForm;
$('service-fee-type').onchange=()=>{
  const fee=state.fee_types[$('service-fee-type').value];
  if(Number($('service-factor').value.replace(',','.'))*100>fee.max) $('service-factor').value=String(fee.threshold/100).replace('.',',');
};
$('service-form').onsubmit=e=>{ e.preventDefault(); run(async()=>{ await api('service_save',{id:$('service-id').value,label:$('service-label').value,price:$('service-price').value,goae_code:$('service-goae').value,factor:$('service-factor').value,fee_type:$('service-fee-type').value,on_request:$('service-on-request').checked}); resetForm(); await load(); }); };
$('group-reset').onclick=resetGroup;
$('group-form').onsubmit=e=>{e.preventDefault();run(async()=>{
  await api('group_save',{id:$('group-id').value,label:$('group-label').value,services:[...$('group-members').querySelectorAll('input:checked')].map(el=>el.value)});
  resetGroup(); await load();
});};
$('pay').onclick=()=>run(async()=>{
  if(!$('service-date').reportValidity()) return;
  for(const input of $('basket').querySelectorAll('textarea')) if(!input.reportValidity()) return;
  const ids=selectedIds();
  const data={services:[...selected].sort(),groups:[...selectedGroups].sort(),reasons:Object.fromEntries(Object.entries(reasons).filter(([id,value])=>ids.has(id) && value.trim())),service_date:$('service-date').value,manual_amount:$('manual').value.trim()};
  // Schlüssel und Anfrage vor dem Netzaufruf speichern; bei verlorener Antwort wiederverwenden.
  if(!startRequest) startRequest={...data,request_id:crypto.randomUUID()};
  sessionStorage.setItem('ks-start-'+visitId,JSON.stringify(startRequest));
  try { state.payment=await api('start',startRequest); }
  catch(error) { try { await load(); } catch {} throw error; }
  retrySelection=false; state.reader_payment=null; readerMessage='';
  sessionStorage.removeItem('ks-start-'+visitId); startRequest=null; renderPayment();
});
$('retry').onclick=()=>{ retrySelection=true; startRequest=null; renderPayment(); updateTotal(); };
$('cancel').onclick=()=>run(async()=>{ state.payment=await api('cancel',{payment_id:state.payment.id}); });
$('reader-cancel').onclick=()=>run(()=>refreshReader('reader_cancel'));
$('reader-status').onclick=()=>run(()=>refreshReader('reader_status'));
$('receipt').onclick=()=>{
  if(busy || state?.payment?.payment_status!=='successful') return;
  const query=new URLSearchParams({v:visitId,p:state.payment.id});
  window.open('/receipt.php?'+query, '_blank', 'noopener,noreferrer');
};
function mailStatus(text) { $('receipt-mail-status').textContent=text; $('receipt-mail-status').hidden=!text; }
function printStatus(text) { $('receipt-print-status').textContent=text; $('receipt-print-status').hidden=!text; }
$('receipt-print').onclick=()=>run(async()=>{
  if(!state.printing_enabled || state.payment?.payment_status!=='successful') return;
  const key='ks-print-'+visitId+'-'+state.payment.id;
  // Auch nach Neuladen wird bei verlorener Antwort derselbe Auftrag geprüft.
  let requestId=sessionStorage.getItem(key);
  if(!requestId) { requestId=crypto.randomUUID(); sessionStorage.setItem(key,requestId); }
  receiptPrintPayment=state.payment.id;
  printStatus('Beleg wird für den Druck aufbereitet …');
  try {
    const result=await api('receipt_print',{payment_id:state.payment.id,request_id:requestId});
    printStatus(result.message); sessionStorage.removeItem(key);
    $('receipt-print').textContent=['submitted','simulated'].includes(result.status) ? 'Beleg erneut drucken' : 'Druck erneut versuchen';
  } catch(error) {
    printStatus('Druckübergabe nicht bestätigt. Ein weiterer Klick prüft denselben Druckauftrag.');
    $('receipt-print').textContent='Druckauftrag prüfen / erneut versuchen'; throw error;
  }
});
function renderReceiptMail() {
  const button=$('receipt-mail'); button.hidden=false;
  if(!state.mail_enabled) { button.textContent='Beleg mailen – E-Mail-Versand nicht eingerichtet'; button.disabled=true; return; }
  if(!receiptRecipient) {
    const recipient=receiptRecipient={paymentId:state.payment.id,email:'',loading:true,edited:false,sent:false};
    // Nur die Adresse lesen; der Versand startet ausschließlich mit dem Mail-Button.
    api('receipt_recipient',{payment_id:recipient.paymentId}).then(result=>{
      if(receiptRecipient!==recipient) return;
      recipient.loading=false;
      if(!recipient.edited) { recipient.email=result.email; $('receipt-email').value=result.email; }
      if(result.message) mailStatus(result.message);
      renderReceiptMail();
    }).catch(()=>{
      if(receiptRecipient!==recipient) return;
      recipient.loading=false; mailStatus('E-Mail-Adresse konnte nicht geladen werden. Bitte selbst eintragen.'); renderReceiptMail();
    });
  }
  button.disabled=busy || receiptRecipient.loading;
  button.textContent=receiptRecipient.loading ? 'E-Mail-Adresse wird geladen …' : receiptRecipient.email
    ? (receiptRecipient.sent ? 'Beleg erneut mailen an „' : 'Beleg mailen an „')+receiptRecipient.email+'“'
    : 'Beleg mailen – E-Mail-Adresse eingeben';
}
function showReceiptDetails() {
  receiptShare ??= {paymentId:state.payment.id,url:''};
  $('receipt-share-panel').hidden=false; $('receipt-share-details').hidden=false;
  $('receipt-pdf-download').href='/receipt.php?'+new URLSearchParams({v:visitId,p:state.payment.id,download:'1'});
  $('receipt-original-link').hidden=!receiptShare?.url;
  $('receipt-email-form').hidden=!state.mail_enabled;
  $('receipt-mail-unconfigured').hidden=state.mail_enabled;
}
$('receipt-mail').onclick=()=>{
  if(busy || !state.mail_enabled || !receiptRecipient || receiptRecipient.loading) return;
  if(!receiptRecipient.email) { showReceiptDetails(); $('receipt-email').focus(); return; }
  sendReceiptEmail();
};
$('receipt-share').onclick=()=>run(async()=>{
  const paymentId=state.payment.id;
  showReceiptDetails();
  const result=await api('receipt_share',{payment_id:paymentId});
  receiptShare={...result,paymentId};
  showReceiptDetails();
  $('receipt-share-message').textContent=result.message;
  $('receipt-link').value=result.url;
  if(result.url) $('receipt-open').href=result.url; else $('receipt-open').removeAttribute('href');
});
$('receipt-copy').onclick=()=>run(async()=>{
  if(!receiptShare?.url) return;
  try { await navigator.clipboard.writeText(receiptShare.url); $('receipt-share-message').textContent='Beleglink kopiert.'; }
  catch { $('receipt-link').focus(); $('receipt-link').select(); $('receipt-share-message').textContent='Bitte den markierten Link kopieren.'; }
});
$('receipt-email').oninput=()=>{
  if(!receiptRecipient) return;
  receiptRecipient.email=$('receipt-email').value.trim(); receiptRecipient.edited=true; receiptRecipient.sent=false;
  renderReceiptMail();
};
function sendReceiptEmail() {
  if(busy || !state.mail_enabled || receiptRecipient?.paymentId!==state.payment.id) return;
  const email=$('receipt-email').value.trim();
  if(!email || /[\r\n]/.test(email) || !$('receipt-email').checkValidity()) { showReceiptDetails(); $('receipt-email').reportValidity(); return; }
  run(async()=>{
    if(!receiptMailRequest || receiptMailRequest.email!==email) receiptMailRequest={request_id:crypto.randomUUID(),email,payment_id:state.payment.id};
    mailStatus('PDF wird erstellt und E-Mail versendet …');
    try {
      const result=await api('receipt_email',receiptMailRequest);
      mailStatus(result.message);
      receiptRecipient.sent=['sent','simulated'].includes(result.status) && receiptRecipient.email===email;
      receiptMailRequest=null;
    } catch(error) { mailStatus('Versand nicht bestätigt. Ein weiterer Klick prüft denselben Versandversuch.'); throw error; }
  });
}
$('receipt-email-form').onsubmit=e=>{ e.preventDefault(); sendReceiptEmail(); };
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
    if(saved) { try { startRequest=JSON.parse(saved); selected=new Set(startRequest.services); selectedGroups=new Set(startRequest.groups || []); reasons=startRequest.reasons || {}; $('service-date').value=startRequest.service_date || ''; $('manual').value=startRequest.manual_amount; } catch { sessionStorage.removeItem('ks-start-'+visitId); } }
    await load();
  } else if(['localhost','127.0.0.1'].includes(location.hostname)) $('demo').hidden=false;
}
init().catch(e=>message(e.message));
