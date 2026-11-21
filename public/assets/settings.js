'use strict';
const $ = id => document.getElementById(id);
let csrf = '';
const TESTS = {sumup: ['sumup', 'Verbindung zu SumUp prüfen'], printing: ['receipt_printer', 'Testbon drucken'],
  office_printing: ['office_printer', 'Testseite drucken'], terminzettel: ['terminzettel', 'Beispiel-Terminzettel drucken'], invoice_export: ['export', 'Schreibzugriff prüfen'], letterxpress: ['letterxpress', 'Verbindung und Guthaben prüfen']};
// Felder, die nur bei bestimmten Werten sichtbar sind.
const SHOW = {
  'sumup.api_key': v => v.mode === 'live', 'sumup.affiliate_key': v => v.mode === 'live', 'sumup.app_id': v => v.mode === 'live',
  'sumup.merchant_code': v => v.mode === 'live', 'sumup.reader_id': v => v.mode === 'live',
  'fhir.base_url': v => v.mode === 'live', 'fhir.launch_urls': v => v.mode === 'live', 'fhir.api_key': v => v.mode === 'live',
  'fhir.entry_code': v => v.mode === 'live', 'fhir.ca_pem': v => v.mode === 'live', 'fhir.pin_certificate': v => v.mode === 'live',
  'letterxpress.username': v => v.enabled, 'letterxpress.api_key': v => v.enabled, 'letterxpress.mode': v => v.enabled, 'letterxpress.color': v => v.enabled,
  'letterxpress.duplex': v => v.enabled, 'letterxpress.shipping': v => v.enabled, 'letterxpress.registered': v => v.enabled,
  'printing.queue': v => v.transport === 'cups', 'printing.cups_server': v => v.transport === 'cups', 'printing.share': v => v.transport === 'samba',
  'terminzettel.header_size': v => !!(v.header_text || '').trim(), 'terminzettel.header_bold': v => !!(v.header_text || '').trim(),
  'terminzettel.heading_size': v => !!(v.heading || '').trim(), 'terminzettel.footer_size': v => !!(v.footer_text || '').trim(),
  'terminzettel.qr_position': v => v.qr_enabled, 'terminzettel.qr_size': v => v.qr_enabled, 'terminzettel.qr_summary': v => v.qr_enabled,
  'tax.rate': v => !v.small_business, 'tax.manual_vat': v => !v.small_business, 'tax.exempt_note': v => !v.small_business, 'tax.small_business_note': v => v.small_business,
  'mail.password': v => !!v.username, 'invoice_export.password': v => !!v.username, 'invoice_export.domain': v => !!v.username,
};

function el(tag, text, cls, attrs) {
  const node = document.createElement(tag);
  if (text != null) node.textContent = text;
  if (cls) node.className = cls;
  for (const [k, v] of Object.entries(attrs || {})) node.setAttribute(k, v);
  return node;
}
// Hilfe-Symbol mit Erklärung, Beispiel und Bedeutung der Auswahlmöglichkeiten (Maus, Tastatur, Antippen).
function tip(field) {
  if (!field.tip && !field.example && !field.options) return null;
  const box = el('span', null, 'tip-box', {role: 'tooltip'});
  if (field.tip) box.append(el('span', field.tip, 'tip-text'));
  if (field.options && field.choices) for (const [value, text] of Object.entries(field.options)) {
    const line = el('span', null, 'tip-option'); line.append(el('strong', (field.choices[value] || value) + ': '), document.createTextNode(text)); box.append(line);
  }
  if (field.example) { const line = el('span', null, 'tip-example'); line.append(el('strong', 'Beispiel: '), el('code', field.example)); box.append(line); }
  const button = el('button', 'i', 'tip', {type: 'button', 'aria-label': 'Hilfe zu ' + field.label});
  button.onclick = event => { event.preventDefault(); const open = !button.classList.contains('open');
    document.querySelectorAll('.tip.open').forEach(t => t.classList.remove('open')); button.classList.toggle('open', open); };
  button.append(box);
  return button;
}
document.addEventListener('click', event => { if (!event.target.closest('.tip')) document.querySelectorAll('.tip.open').forEach(t => t.classList.remove('open')); });
document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.tip.open').forEach(t => t.classList.remove('open')); });

const REAUTH = 'Passwort zur Bestätigung erforderlich.';
// Heikle Änderungen von einem gemerkten Gerät: Passwort abfragen und die Anfrage einmal wiederholen.
function askPassword() {
  const dialog = $('confirm-dialog'), input = $('confirm-password'); input.value = '';
  return new Promise(resolve => {
    const done = value => { dialog.close(); $('confirm-form').onsubmit = null; $('confirm-cancel').onclick = null; resolve(value); };
    $('confirm-form').onsubmit = event => { event.preventDefault(); done(input.value); };
    $('confirm-cancel').onclick = () => done(null);
    dialog.addEventListener('cancel', () => done(null), {once: true});
    dialog.showModal(); input.focus();
  });
}
async function api(action, data = {}) {
  try { return await rawApi(action, data); }
  catch (error) {
    if (error.message !== REAUTH) throw error;
    const confirm = await askPassword();
    if (confirm === null) throw new Error('Abgebrochen.');
    return rawApi(action, {...data, confirm});
  }
}
async function rawApi(action, data = {}) {
  const response = await fetch('/settings-api.php', {method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify({action, ...data})});
  const result = await response.json().catch(() => ({}));
  if (response.status === 401 && !['login', 'setup', 'status'].includes(action)) { show('login'); throw new Error(result.error || 'Bitte anmelden.'); }
  if (!response.ok) throw new Error(result.error || 'Fehler ' + response.status);
  return result;
}
function message(text, error) {
  const m = $('message'); m.hidden = !text; m.textContent = text || ''; m.classList.toggle('error', !!error);
  if (text) window.scrollTo({top: 0, behavior: 'smooth'});
}
function show(view) { for (const id of ['setup', 'login', 'settings']) $(id).hidden = id !== view; }

function values(form, section) {
  const out = {};
  for (const field of section.fields) {
    const input = form.elements[field.key]; if (!input) continue;
    out[field.key] = field.type === 'bool' ? input.checked : input.value;
  }
  return out;
}
function applyVisibility(form, section) {
  const current = values(form, section);
  const enabled = section.fields.some(f => f.key === 'enabled') ? current.enabled : true;
  for (const field of section.fields) {
    const row = form.querySelector('[data-field="' + field.key + '"]');
    const rule = SHOW[section.id + '.' + field.key];
    row.hidden = (field.key !== 'enabled' && !enabled) || (rule ? !rule(current) : false);
  }
}
function render(data) {
  csrf = data.csrf || csrf;
  $('mode').hidden = false;
  $('mode').textContent = data.mode === 'live' ? (data.fhir_mode === 'live' ? 'LIVE' : 'LIVE · T2MED TESTMODUS') : 'TESTMODUS';
  $('mode').classList.toggle('live', data.mode === 'live');
  $('warning').hidden = !data.warning; $('warning').textContent = data.warning || '';
  renderInvoicePrinter(data.invoice_printer || {});
  if (data.clients) renderClients(data.clients);
  if (data.export_years) renderExport(data.export_years, data.mode);
  if (data.export_years) t2medPaidSetup(data.export_years, data.mode);
  const root = $('sections');
  document.querySelectorAll('[data-generated]').forEach(node => node.remove());
  for (const section of data.sections) {
    const card = el('section', null, 'card settings-card', {id: 's-' + section.id, 'data-generated': '1'});
    card.append(el('h2', section.label));
    const form = el('form', null, 'settings-form');
    for (const field of section.fields) {
      const row = el('div', null, 'field', {'data-field': field.key});
      const id = 'f-' + section.id + '-' + field.key;
      let input;
      if (field.type === 'bool') {
        const label = el('label', null, 'check'); input = el('input', null, null, {type: 'checkbox', id, name: field.key});
        input.checked = !!field.value; label.append(input, document.createTextNode(' ' + field.label));
        const line = el('div', null, 'label-row'); line.append(label); const t = tip(field); if (t) line.append(t); row.append(line);
      } else {
        const line = el('div', null, 'label-row'); line.append(el('label', field.label, null, {for: id}));
        const t = tip(field); if (t) line.append(t); row.append(line);
        if (field.type === 'choice') {
          input = el('select', null, null, {id, name: field.key});
          for (const [value, label] of Object.entries(field.choices)) { const o = el('option', label, null, {value}); if (value === field.value) o.selected = true; input.append(o); }
        } else if (field.type === 'list' || field.type === 'pem' || field.type === 'text') {
          input = el('textarea', null, field.type === 'text' ? 'plain' : null, {id, name: field.key, rows: field.type === 'pem' ? '6' : '3', spellcheck: field.type === 'text' ? 'true' : 'false'});
          if (field.type !== 'pem') input.value = field.value || '';
          else input.placeholder = field.value.set ? 'Zertifikat gespeichert – leer lassen zum Behalten, „-“ zum Entfernen' : '-----BEGIN CERTIFICATE----- …';
        } else {
          input = el('input', null, null, {id, name: field.key, type: field.type === 'secret' ? 'password' : (field.type === 'int' || field.type === 'money' ? 'number' : 'text'), autocomplete: 'off', spellcheck: 'false'});
          if (field.type === 'secret') input.placeholder = field.value.set ? 'gespeichert – leer lassen zum Behalten' : 'noch nicht gesetzt';
          else input.value = field.value ?? '';
          if (field.type === 'money') { input.min = '1'; input.step = '1'; }
        }
        if (field.example && field.type !== 'secret' && field.type !== 'pem' && field.type !== 'choice') input.placeholder = 'z. B. ' + field.example.replace(/\n/g, ' · ');
        row.append(input);
        if (field.type === 'choice' && field.options) {
          // Erklärung der gerade gewählten Möglichkeit direkt unter der Auswahl.
          const explain = el('p', null, 'muted small option-help');
          const update = () => { explain.textContent = field.options[input.value] || ''; };
          input.addEventListener('change', update); update(); row.append(explain);
        }
      }
      if (field.help) row.append(el('p', field.help, 'muted small'));
      form.append(row);
    }
    const buttons = el('div', null, 'row-buttons');
    const save = el('button', 'Speichern', 'primary', {type: 'submit'}); buttons.append(save);
    if (TESTS[section.id]) {
      const [kind, label] = TESTS[section.id]; const test = el('button', label, 'secondary', {type: 'button'});
      test.onclick = () => busy(test, async () => { message((await api('test', {kind})).message); });
      buttons.append(test);
    }
    form.append(buttons);
    if (section.id === 'fhir') form.append(t2medSetupHelp());
    form.oninput = form.onchange = event => {
      // Anderes Druckermodell: Breite leeren, der Server setzt die passende Breite.
      if (section.id === 'printing' && event.target.name === 'model' && form.elements.width_dots) form.elements.width_dots.value = '';
      applyVisibility(form, section);
    };
    form.onsubmit = event => { event.preventDefault(); busy(save, async () => {
      const result = await api('save', {section: section.id, values: values(form, section)});
      render(result); message(result.message); location.hash = 's-' + section.id;
    }); };
    card.append(form); root.append(card);
    applyVisibility(form, section);
    if (section.id === 'terminzettel') terminzettelCard(card, form, section, data.terminzettel || {});
  }
  layoutTabs(data);
}

// ---- Drucker „SumUp-Rechnung“ (wird beim Einschalten automatisch angelegt) ---------------
function renderInvoicePrinter(info) {
  $('invoice-printer-help').hidden = !info.enabled;
  const box = $('invoice-printer-status'); box.replaceChildren();
  if (!info.enabled) return;
  if (!info.installed) { box.append(el('p', 'Der Druckdienst für „SumUp-Rechnung“ fehlt auf diesem Server. Bitte einmal den aktuellen Server-Installer ausführen; danach genügt hier „Speichern“.', 'inline-error')); return; }
  if (!info.queue) {
    const p = el('p', 'Der Drucker ist noch nicht angelegt. ', 'inline-error');
    const button = el('button', 'Jetzt einrichten', 'secondary', {type: 'button'});
    button.onclick = () => busy(button, async () => { const r = await api('save', {section: 'invoice_inbox', values: {enabled: true}}); render(r); message(r.message); });
    p.append(button); box.append(p); return;
  }
  if (!info.queue.ours) { box.append(el('p', 'Ein anderer Drucker heißt bereits „SumUp-Rechnung“. Bitte diesen umbenennen oder entfernen.', 'inline-error')); return; }
  box.append(el('p', 'Eingerichtet (' + info.queue.state + '). In T2med die Rechnung auf diesen Drucker drucken – sie erscheint dann beim Patienten zum Kassieren.', 'notice'));
  const ol = el('ol', null, 'steps');
  ol.append(el('li', 'Windows: Drucker hinzufügen → „Freigegebenen Drucker über den Namen auswählen“: ' + info.url + ' – Treiber „Microsoft IPP Class Driver“, Papier A4. ' + ippIpNote(info.url, info.ips)));
  const driver = el('li', 'Windows 11: Fehlt der Treiber „Microsoft IPP Class Driver“ oder akzeptiert Windows die Adresse nicht, PowerShell als Administrator öffnen und ausführen:');
  driver.append(el('pre', ippPowerShell('SumUp-Rechnung', info.url, info.ips, 'A4'), 'audit-list'));
  ol.append(driver);
  const mac = el('li'); mac.append(document.createTextNode('Mac: „SumUp-Rechnung“ hinzufügen, als Treiber die Datei '), el('a', 'SumUp-Rechnung-PDF.ppd', null, {href: '/assets/SumUp-Rechnung-PDF.ppd', download: 'SumUp-Rechnung-PDF.ppd'}), document.createTextNode(' wählen.'));
  ol.append(mac); box.append(ol);
}

// ---- Aktualisierung von GitHub ----------------------------------------------------------
let updateTimer = null, updateOpened = false;
const when = t => t ? new Date(t * 1000).toLocaleString('de-DE', {dateStyle: 'short', timeStyle: 'short'}) : '';
async function loadUpdate(force) {
  try { renderUpdate(await api('update_check', {force})); }
  catch (error) { $('update-box').replaceChildren(el('p', error.message, 'inline-error')); }
}
function renderUpdate(info) {
  const box = $('update-box'); box.replaceChildren();
  const running = ['running', 'requested'].includes(info.status?.state);
  const failed = info.status?.state === 'failed';
  // Die Glocke steht in der Reiterleiste und ist auf jedem Reiter sichtbar, sobald eine neue Version bereitsteht.
  const group = document.querySelector('#group-update > summary');
  if (group) group.textContent = 'Aktualisierung' + (running ? ' · läuft …' : info.available ? ' · Neue Version ' + info.remote + ' verfügbar' : '');
  // Immer sichtbar: grün = aktuelle Version, rot = Update verfügbar. Ein Klick öffnet die Aktualisierung.
  const bell = $('update-bell'); bell.hidden = false;
  bell.className = 'bell ' + (info.main_available ? 'main' : info.available ? 'update' : 'current');
  bell.title = info.main_available ? 'Neue stabile Version ' + info.main_remote + ' im Hauptzweig – zur Aktualisierung' : info.available ? 'Neue Version ' + info.remote + ' verfügbar – zur Aktualisierung' : 'Die Version ist aktuell – zur Aktualisierung';
  bell.setAttribute('aria-label', bell.title);
  if ((info.available || running || failed) && !updateOpened) { updateOpened = true; openGroupOf('s-update'); }
  if (running) { updateProgress(info.status); return; }
  const facts = el('dl', null, 'update-facts');
  const add = (k, v) => { facts.append(el('dt', k), el('dd', v)); };
  add('Installiert', info.current || '?');
  if (info.configured) add('Kanal', ({stable: 'Stabil (ursprüngliches Projekt)', beta: 'Beta (abonniert)', custom: 'Eigene Quelle'})[info.channel] || info.channel);
  if (info.configured) add('Quelle', info.source.repo + ' (Zweig ' + info.source.branch + ')');
  if (info.configured) add('Auf GitHub', info.remote ? info.remote + ' · geprüft ' + when(info.checked_at) : '–');
  box.append(facts);
  if (info.configured) {
    // Beta-Kanal (dieser Fork) per Kästchen abonnieren; ohne Häkchen kommen Updates nur aus dem ursprünglichen Projekt (Stabil).
    const label = el('label', null, 'check'); const sub = el('input', null, null, {type: 'checkbox', id: 'update-beta'});
    sub.checked = info.channel === 'beta'; sub.disabled = running;
    sub.onchange = () => busy(sub, async () => {
      try { const r = await api('update_channel', {channel: sub.checked ? 'beta' : 'stable'}); message(r.message); renderUpdate(r); }
      catch (error) { sub.checked = !sub.checked; throw error; }
    });
    label.append(sub, document.createTextNode(' Beta-Versionen abonnieren'));
    const field = el('div', null, 'field'); field.append(label,
      el('p', info.channel === 'custom' ? 'Zurzeit ist eine eigene Quelle in update.conf eingetragen. Mit dem Kästchen wechselst du zu Beta, ohne Häkchen zu den stabilen Versionen.'
        : 'Ohne Häkchen kommen Updates nur aus dem ursprünglichen Projekt (stabile Versionen). Mit Häkchen bekommst du zusätzlich die Beta-Versionen aus diesem Fork früher, mit neuen Funktionen, die noch Fehler enthalten können. Das Abbestellen installiert keine ältere Version, sondern wartet auf die nächste stabile Version.', 'muted small'));
    box.append(field);
  }
  if (!info.configured) { box.append(el('p', 'Keine Aktualisierungsquelle eingerichtet. Bitte einmal den aktuellen Server-Installer ausführen.', 'inline-error')); return; }
  if (info.error) box.append(el('p', info.error, 'inline-error'));
  if (info.status?.state === 'failed') box.append(el('p', info.status.message, 'inline-error'));
  if (info.status?.state === 'done' && !info.available && info.status.to === info.current) box.append(el('p', 'Zuletzt aktualisiert am ' + when(info.status.finished) + ' (' + info.status.from + ' → ' + info.status.to + ').', 'muted small'));
  const buttons = el('div', null, 'row-buttons');
  if (info.available) {
    box.append(el('p', 'Version ' + info.remote + ' ist verfügbar.', 'notice'));
    if (info.notes.length) { const ul = el('ul', null, 'update-notes'); for (const n of info.notes) ul.append(el('li', n)); box.append(ul); }
    if (!info.service) box.append(el('p', 'Der Aktualisierungsdienst fehlt noch. Bitte einmal den aktuellen Server-Installer ausführen.', 'inline-error'));
    const go = el('button', 'Jetzt auf ' + info.remote + ' aktualisieren', 'primary', {type: 'button'}); go.disabled = !info.service;
    go.onclick = () => busy(go, async () => { const r = await api('update_start'); message(r.message); updateProgress({state: 'requested', log: []}); });
    buttons.append(go);
    box.append(el('p', 'Dauert einige Minuten. Kartenzahlung und diese Seite sind dabei kurz nicht erreichbar – am besten außerhalb der Sprechzeit. Schlägt etwas fehl, wird der vorherige Stand automatisch wiederhergestellt.', 'muted small'));
  } else if (info.remote) box.append(el('p', 'Die installierte Version ist aktuell.', 'muted'));
  const again = el('button', 'Jetzt auf neue Version prüfen', 'secondary', {type: 'button'});
  again.onclick = () => busy(again, () => loadUpdate(true));
  buttons.append(again); box.append(buttons);
  if (info.status?.log?.length && info.status.state === 'failed') box.append(updateLog(info.status.log));
}
function updateLog(lines) { const d = el('details', null, 'tz-how'); d.append(el('summary', 'Protokoll der letzten Aktualisierung'), el('pre', lines.join('\n'), 'code update-log')); return d; }
function updateProgress(status) {
  const box = $('update-box'); box.replaceChildren(el('p', status.state === 'requested' ? 'Aktualisierung angefordert – startet gleich …' : 'Aktualisierung läuft. Bitte dieses Fenster offen lassen.', 'notice'));
  if (status.log?.length) box.append(el('pre', status.log.join('\n'), 'code update-log'));
  clearTimeout(updateTimer);
  updateTimer = setTimeout(async () => {
    let next;
    try { next = await api('update_status'); }
    catch { box.firstChild.textContent = 'Dienste werden neu gestartet …'; updateTimer = setTimeout(() => updateProgress(status), 3000); return; }
    if (next.state === 'done') { message('Aktualisierung abgeschlossen: ' + next.to + '. Die Seite wird neu geladen.'); setTimeout(() => location.reload(), 2500); return; }
    if (next.state === 'failed') { loadUpdate(false); message(next.message, true); return; }
    updateProgress(next);
  }, 3000);
}

// ---- Reiter: laufende Arbeit, Praxisangaben, Drucker, einmalige Einrichtung ---------------
// Einmalige Einrichtung in einklappbaren Themenblöcken (alles Weitere steht in den Reitern daneben).
const GROUPS = [
  {id: 'update', label: 'Aktualisierung', cards: ['s-update']},
  {id: 'praxis', label: 'Praxis & Rechnungen', cards: ['s-practice', 's-tax', 's-app', 's-mail', 's-letterxpress', 's-invoice_export']},
  {id: 'zahlung', label: 'Zahlung & T2med', cards: ['s-sumup', 's-fhir', 's-invoice_inbox', 'invoice-printer-help']},
  {id: 'drucker', label: 'Drucker', cards: ['s-printers', 's-printing', 's-office_printing']},
  {id: 'terminzettel', label: 'Terminzettel', cards: ['s-terminzettel']},
  {id: 'netz', label: 'Arbeitsplätze & Netzwerk', cards: ['s-clients', 's-firewall']},
  {id: 'sicherheit', label: 'Sicherheit', cards: ['s-audit', 's-access', 's-password']},
];
const TABS = [
  {id: 'abrechnung', label: 'Abrechnung', hint: 'laufend', cards: ['s-inbox-list', 's-t2med-paid', 's-export']},
  {id: 'leistungen', label: 'Leistungen', hint: 'GOÄ', cards: ['s-catalog']},
  {id: 'einrichtung', label: 'Einrichtung', hint: 'einmalig', cards: ['s-checklist', ...GROUPS.flatMap(g => g.cards)], groups: GROUPS},
];
let lastOpenCards = [];
function openGroupOf(cardId) { $(cardId)?.closest('details.setup-group')?.setAttribute('open', ''); }
let activeTab = null;
function tabOf(cardId) { return (TABS.find(t => t.cards.includes(cardId)) || {}).id; }
function layoutTabs(data) {
  const panels = $('tab-panels'), bar = $('tabs');
  if (!bar.querySelector('.tab')) {
    for (const tab of TABS) {
      const button = el('button', null, 'tab', {type: 'button', role: 'tab', id: 'tab-' + tab.id, 'aria-controls': 'panel-' + tab.id});
      button.append(el('span', tab.label));
      if (tab.hint) button.append(el('small', tab.hint));
      button.append(el('span', null, 'tab-badge', {id: 'badge-' + tab.id, hidden: ''}));
      button.onclick = () => { activate(tab.id); history.replaceState(null, '', '#' + tab.id); };
      bar.insertBefore(button, $('update-bell'));
      panels.append(el('div', null, 'tab-panel', {id: 'panel-' + tab.id, role: 'tabpanel', 'aria-labelledby': 'tab-' + tab.id}));
    }
  }
  for (const tab of TABS) {
    if (!tab.groups) { for (const id of tab.cards) { const card = $(id); if (card) $('panel-' + tab.id).append(card); } continue; }
    const panel = $('panel-' + tab.id); const lead = $('s-checklist'); if (lead) panel.append(lead);
    for (const group of tab.groups) {
      let box = $('group-' + group.id);
      if (!box) { box = el('details', null, 'setup-group', {id: 'group-' + group.id}); box.append(el('summary', group.label)); }
      panel.append(box);
      for (const id of group.cards) { const card = $(id); if (card) box.append(card); }
    }
  }
  const open = renderChecklist(data);
  if (!activeTab) {
    for (const id of lastOpenCards) openGroupOf(id);
    openGroupOf(location.hash.slice(1));
    const hash = location.hash.slice(1);
    activate(TABS.some(t => t.id === hash) ? hash : tabOf(hash) || (open ? 'einrichtung' : 'abrechnung'));
    if (tabOf(hash)) setTimeout(() => $(hash)?.scrollIntoView({block: 'start'}), 50);
  } else activate(activeTab);
}
function activate(id) {
  activeTab = id;
  for (const tab of TABS) {
    $('panel-' + tab.id).hidden = tab.id !== id;
    $('tab-' + tab.id).setAttribute('aria-selected', String(tab.id === id));
  }
}
// Verweise wie #s-printers öffnen den passenden Reiter und springen zur Karte.
window.addEventListener('hashchange', () => {
  const hash = location.hash.slice(1), tab = TABS.some(t => t.id === hash) ? hash : tabOf(hash);
  if (!tab) return;
  activate(tab);
  openGroupOf(hash);
  if (tabOf(hash)) $(hash)?.scrollIntoView({behavior: 'smooth', block: 'start'});
});
function showUpdate() { activate('einrichtung'); openGroupOf('s-update'); history.replaceState(null, '', '#s-update'); $("group-update")?.scrollIntoView({behavior: 'smooth', block: 'start'}); }
document.addEventListener('DOMContentLoaded', () => { const bell = $('update-bell'); if (bell) bell.onclick = e => { e.preventDefault(); showUpdate(); }; });
function badge(tab, count) { const b = $('badge-' + tab); if (!b) return; b.hidden = !count; b.textContent = count ? String(count) : ''; }

const GUIDE_URL = 'https://github.com/hau-si/kienzle-suite-beta/blob/rechnungseingang/docs/T2MED-EINRICHTUNG.md';
/** Anleitung für T2med: Drittanbieter-Zugriff mit Menüleisten-Button (Aufruf-Adresse zum Kopieren). */
function t2medSetupHelp() {
  const d = el('details', null, 'tz-how'); d.append(el('summary', 'In T2med einrichten: Button „Kartenzahlung“ in der Funktionsleiste'));
  const ol = el('ol');
  ol.append(el('li', 'In T2med in der Suche „Drittanbieter-Zugriffe verwalten“ öffnen (fehlt die Funktion, muss ein Admin sie für den Benutzer erst freischalten) und den Eintrag mit dem Stift bearbeiten.'));
  const li = el('li', 'Als Adresse eintragen (genau so, mit den Platzhaltern) und eine Bezeichnung vergeben, z. B. „Kartenzahlung“:'); li.append(el('br'), el('code', 'kienzle-sumup://?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}', 'code-copy')); ol.append(li);
  ol.append(el('li', 'Auf Plus klicken, oben rechts mit dem Häkchen speichern und in der Liste davor auf „aktualisieren“ (Pfeile) klicken.'));
  ol.append(el('li', 'Dann „Funktionsleisten verwalten“: Konfiguration anlegen oder bearbeiten (Name, Benutzer, Benutzergruppe, Station), „Aktion hinzufügen“, die Aktion unter der vergebenen Bezeichnung suchen, Farbe und Kürzel oder Icon wählen und bestätigen.'));
  ol.append(el('li', 'Der Starter-Aufruf funktioniert erst, wenn auf dem Arbeitsplatz das Starter-Paket installiert ist (Karte „Arbeitsplätze einrichten“).'));
  ol.append(el('li', 'Die FHIR-Adresse, die T2med beim Aufruf mitgibt, muss unter „Erlaubte fhirBasisUrl aus T2med“ stehen. Bei einer Fehlermeldung zeigt die Seite die tatsächlichen Werte.'));
  const guide = el('li'); guide.append('Mit Bildern: ', el('a', 'Anleitung „T2med einrichten“', null, {href: GUIDE_URL, target: '_blank', rel: 'noopener'})); ol.append(guide);
  d.append(ol); return d;
}

/** Überblick der einmaligen Schritte; liefert die Zahl offener Pflichtpunkte. */
function renderChecklist(data) {
  const value = (section, key) => (data.sections.find(s => s.id === section)?.fields.find(f => f.key === key) || {}).value;
  const tz = data.terminzettel || {};
  const invoiceQueue = !!(data.invoice_printer && data.invoice_printer.queue && data.invoice_printer.queue.ours);
  const groups = [
    {title: 'In diesem Webfenster', hint: 'Einstellungen dieses Servers', items: [
      {done: true, label: 'Admin-Passwort festgelegt', card: 's-password'},
      {done: !!(value('practice', 'name') || '').trim() && !!(value('practice', 'street') || '').trim(), label: 'Praxisname und Anschrift (für Rechnungen und Barzahlungen)', card: 's-practice'},
      {done: data.mode === 'live', label: data.mode === 'live' ? 'SumUp live verbunden' : 'SumUp: noch Testmodus – Zugangsdaten eintragen und auf Live stellen', card: 's-sumup'},
      {done: !!value('printing', 'enabled'), optional: true, label: 'Bondrucker eingerichtet', card: 's-printers'},
      {done: !!value('office_printing', 'enabled'), optional: true, label: 'Praxisdrucker (A4) eingerichtet', card: 's-printers'},
      {done: !!(tz.installed && tz.target), optional: true, label: tz.installed ? 'Terminzettel druckt auf einen Bondrucker' : 'Terminzettel: Druckdienst nicht installiert', card: 's-terminzettel'},
      {done: invoiceQueue, optional: true, label: invoiceQueue ? 'Drucker „SumUp-Rechnung“ für T2med-Rechnungen auf dem Server angelegt' : 'Drucker „SumUp-Rechnung“ auf dem Server anlegen (T2med-Rechnungen übernehmen einschalten)', card: 's-invoice_inbox'},
      {done: !!value('letterxpress', 'enabled'), optional: true, label: value('letterxpress', 'enabled') ? 'Rechnung per Post (Letterxpress) angebunden' : 'Rechnung per Post über Letterxpress versenden (optional: Zugang eintragen)', card: 's-letterxpress'},
      {done: !!value('invoice_export', 'enabled'), optional: true, label: value('invoice_export', 'enabled') ? 'Rechnungen werden in einen Netzwerkordner abgelegt (Steuerberatung)' : 'Rechnungen in einen Netzwerkordner ablegen (optional, z. B. für die Steuerberatung)', card: 's-invoice_export'},
      {info: true, label: 'Firewall: Zugriff auf das Praxisnetz beschränken (empfohlen)', card: 's-firewall'},
    ]},
    {title: 'Auf den Client-PCs', hint: 'Kassen- und Arbeitsplatzrechner', items: [
      {info: true, label: 'Starter-Paket auf jedem Kassen-PC einmal installieren (Windows, macOS oder Linux)', card: 's-clients'},
      {info: true, label: 'Drucker „Terminzettel“ am Arbeitsplatz installieren (Windows: IPP-Drucker hinzufügen)', card: 's-terminzettel', detail: tz.printer_url || ''},
      ...(invoiceQueue ? [{info: true, label: 'Drucker „SumUp-Rechnung“ am Arbeitsplatz installieren (Windows: IPP-Drucker hinzufügen, Papier A4)', card: 'invoice-printer-help', detail: (data.invoice_printer && data.invoice_printer.url) || ''}] : []),
    ]},
    {title: 'In T2med', hint: 'Einstellungen in der Praxissoftware', items: [
      {done: data.fhir_mode === 'live', label: data.fhir_mode === 'live' ? 'FHIR-Schnittstelle eingerichtet (T2med-Anbindung live)' : 'FHIR-Schnittstelle einrichten: T2med-Anbindung noch im Testmodus', card: 's-fhir'},
      {info: true, label: 'Drittanbieter-Zugriff mit Funktionsleisten-Button „Kartenzahlung“ anlegen (Aufruf-Adresse steht bei der T2med-Anbindung)', card: 's-fhir', href: GUIDE_URL, hrefLabel: 'Anleitung mit Bildern'},
      {info: true, label: 'In T2med unter Einstellungen → Kalender → Terminliste (DIN A6) den Drucker „Terminzettel“ wählen', card: 's-terminzettel', detail: tz.printer_url || ''},
      ...(invoiceQueue ? [{info: true, label: 'Rechnungen in T2med auf den zuvor installierten Drucker „SumUp-Rechnung“ drucken', card: 'invoice-printer-help'}] : []),
    ]},
  ];
  const items = groups.flatMap(g => g.items);
  const list = $('checklist'); list.replaceChildren();
  for (const group of groups) {
    const heading = el('h3', group.title, 'check-group'); heading.append(el('small', ' · ' + group.hint)); list.append(heading);
    const ul = el('ul', null, 'checklist');
    for (const item of group.items) {
      const li = el('li', null, item.info ? 'info' : item.done ? 'done' : item.optional ? 'optional' : 'open');
      li.append(el('span', item.info ? 'i' : item.done ? '✓' : '○', 'mark-icon'));
      const text = el('span'); text.append(el('a', item.label, null, {href: '#' + item.card}));
      if (item.href) text.append(' · ', el('a', item.hrefLabel || 'Anleitung', null, {href: item.href, target: '_blank', rel: 'noopener'}));
      if (item.detail) { text.append(el('br'), el('small', item.detail, 'mono')); }
      li.append(text);
      if (item.optional && !item.done) li.append(el('small', ' optional'));
      ul.append(li);
    }
    list.append(ul);
  }
  const open = items.filter(i => !i.done && !i.info && !i.optional).length;
  lastOpenCards = items.filter(i => !i.done && !i.info && !i.optional).map(i => i.card);
  badge('einrichtung', open);
  return open;
}
async function busy(button, action) {
  button.disabled = true;
  try { await action(); } catch (error) { message(error.message, true); } finally { button.disabled = false; }
}
async function loadSettings() {
  const data = await api('get');
  if (data.role === 'staff') { staffMode(data); return; }
  document.body.classList.remove('staff'); $('password-title').textContent = 'Admin-Passwort ändern';
  render(data); renderAccess(data.access); show('settings'); loadPrinters(); loadUpdate(false); loadFirewall(); loadInbox(); loadCatalog();
}

// Mitarbeiter-Zugang: nur die freigegebenen Bereiche, keine Einstellungen oder Schlüssel.
function staffMode(data) {
  csrf = csrf || ''; document.body.classList.add('staff');
  $('mode').hidden = false; $('mode').textContent = data.mode === 'live' ? (data.fhir_mode === 'live' ? 'LIVE' : 'LIVE · T2MED TESTMODUS') : 'TESTMODUS'; $('mode').classList.toggle('live', data.mode === 'live');
  $('tabs').hidden = true; $('sections').replaceChildren(); $('warning').hidden = true;
  const panel = $('tab-panels'); panel.replaceChildren(); panel.hidden = false;
  const rights = data.rights || [], can = r => rights.includes(r);
  const take = id => { const card = $(id); if (card) { card.hidden = false; panel.append(card); } };
  if (can('paid')) { take('s-t2med-paid'); t2medPaidSetup(data.export_years || [], data.mode); }
  if (can('inbox') || can('letter')) { take('s-inbox-list'); loadInbox(); }
  if (can('catalog')) { take('s-catalog'); loadCatalog(); }
  if (!panel.children.length) panel.append(el('p', 'Für diesen Zugang sind keine Bereiche freigegeben. Bitte beim Administrator melden.', 'muted'));
  take('s-password'); $('password-title').textContent = 'Passwort ändern';
  show('settings');
}
function renderAccess(access) {
  const form = $('access-form'); form.replaceChildren();
  const field = (label, input, hint) => { const f = el('div', null, 'field'); f.append(el('label', label), input); if (hint) f.append(el('p', hint, 'muted small')); return f; };
  // Zeitangaben mit Einheit; gespeichert werden Minuten bzw. Stunden (bis 6 Monate = 183 Tage).
  const timeField = (label, hint, minutes, units, id) => {
    const best = [...units].reverse().find(u => minutes % u[1] === 0) || units[0];
    const num = el('input', null, null, {type: 'number', min: '1', step: '1', value: String(minutes / best[1]), id});
    const unit = el('select', null, null, {'aria-label': label + ' – Einheit'});
    for (const [name, factor] of units) unit.append(el('option', name, null, {value: String(factor)}));
    unit.value = String(best[1]);
    const row = el('div', null, 'row-buttons'); row.append(num, unit);
    const f = el('div', null, 'field'); f.append(el('label', label), row, el('p', hint, 'muted small')); form.append(f);
    return () => Number(num.value) * Number(unit.value);
  };
  const idleMinutes = timeField('Abmeldung bei Inaktivität', 'Nach so langer Zeit ohne Aktion muss man sich neu anmelden (5 Minuten bis 6 Monate).', access.idle_minutes, [['Minuten', 1], ['Stunden', 60], ['Tage', 1440]], 'access-idle');
  const maxHours = timeField('Höchstdauer einer Anmeldung', 'Auch bei ständiger Nutzung wird nach dieser Zeit abgemeldet (1 Stunde bis 6 Monate).', access.max_hours, [['Stunden', 1], ['Tage', 24]], 'access-max');
  form.append(el('h3', 'Mitarbeiter-Zugang'));
  form.append(el('p', 'Ein zweiter Zugang mit eigenem Passwort, der nur die angekreuzten Bereiche sieht. Er hat keinen Zugriff auf Schlüssel, Zugangsdaten und Einstellungen. Das Passwort wird beim Anmelden im selben Feld eingegeben.', 'muted small'));
  const boxes = {};
  for (const r of access.rights) {
    const label = el('label', null, 'check'); const box = el('input', null, null, {type: 'checkbox'}); box.checked = access.staff_rights.includes(r.key); boxes[r.key] = box;
    label.append(box, document.createTextNode(' ' + r.label)); form.append(label);
  }
  const pw = el('input', null, null, {type: 'password', autocomplete: 'new-password', minlength: '10', id: 'access-staff-pw'});
  form.append(field(access.staff ? 'Neues Mitarbeiter-Passwort (leer lassen = unverändert)' : 'Mitarbeiter-Passwort festlegen (mind. 10 Zeichen)', pw));
  const buttons = el('div', null, 'row-buttons'); const save = el('button', 'Speichern', 'primary', {type: 'submit'}); buttons.append(save);
  const send = remove => busy(save, async () => {
    const result = await api('access_save', {idle_minutes: idleMinutes(), max_hours: maxHours(), rights: Object.keys(boxes).filter(k => boxes[k].checked), staff_password: pw.value, remove_staff: remove});
    renderAccess(result); message(result.message);
  });
  form.onsubmit = event => { event.preventDefault(); send(false); };
  if (access.staff) { const del = el('button', 'Mitarbeiter-Zugang entfernen', 'secondary', {type: 'button'}); del.onclick = () => { if (confirm('Mitarbeiter-Zugang wirklich entfernen?')) send(true); }; buttons.append(del); }
  form.append(buttons);
  renderDevices();
}
// Gemerkte Geräte: ansehen und einzeln oder alle abmelden.
async function renderDevices(data) {
  let box = $('access-devices');
  if (!box) { box = el('div', null, null, {id: 'access-devices'}); $('s-access').append(box); }
  try { data = data || await api('devices'); } catch (error) { box.replaceChildren(el('p', error.message, 'muted')); return; }
  box.replaceChildren(el('h3', 'Gemerkte Geräte'));
  if (!data.devices.length) { box.append(el('p', 'Keine Geräte gemerkt. Beim Anmelden gibt es dafür das Häkchen „Dieses Gerät merken“.', 'muted small')); return; }
  const table = el('table'); const head = el('tr');
  for (const h of ['Gerät', 'Zugang', 'Angemeldet', 'Zuletzt benutzt', 'Netz', '']) head.append(el('th', h));
  table.append(head);
  for (const d of data.devices) {
    const tr = el('tr'); for (const v of [d.label + (d.current ? ' (dieses Gerät)' : ''), d.role === 'staff' ? 'Mitarbeiter' : 'Admin', when(d.created_at), when(d.last_used), d.net]) tr.append(el('td', v));
    const td = el('td'); const out = el('button', 'Abmelden', 'secondary small-button', {type: 'button'});
    out.onclick = () => busy(out, async () => { const r = await api('device_revoke', {id: d.id}); message(r.message); if (d.current) { csrf = ''; show('login'); } else renderDevices(r); });
    td.append(out); tr.append(td); table.append(tr);
  }
  box.append(table);
  const all = el('button', 'Alle gemerkten Geräte abmelden', 'secondary', {type: 'button'});
  all.onclick = () => busy(all, async () => { const r = await api('device_revoke', {id: 'all'}); message(r.message); csrf = ''; show('login'); });
  box.append(all);
}

$('setup-form').onsubmit = event => { event.preventDefault(); busy(event.submitter, async () => {
  if ($('setup-password').value !== $('setup-password2').value) throw new Error('Die Passwörter stimmen nicht überein.');
  const token = new URLSearchParams(location.hash.slice(1)).get('setup');
  const result = await api('setup', {token, password: $('setup-password').value});
  csrf = result.csrf; history.replaceState(null, '', '/settings.php'); message('Admin-Passwort festgelegt. Jetzt die Einstellungen eintragen.');
  await loadSettings();
}); };
$('login-form').onsubmit = event => { event.preventDefault(); busy(event.submitter, async () => {
  const result = await api('login', {password: $('login-password').value, remember: $('login-remember').checked}); csrf = result.csrf; $('login-password').value = ''; message(''); await loadSettings();
}); };
$('password-form').onsubmit = event => { event.preventDefault(); busy(event.submitter, async () => {
  const result = await api('password', {current: $('pw-current').value, new: $('pw-new').value});
  csrf = result.csrf; $('password-form').reset(); message(result.message);
}); };
$('logout').onclick = async () => { await api('logout').catch(() => {}); csrf = ''; show('login'); };
$('printer-setup').onclick = () => busy($('printer-setup'), async () => {
  const data = await api('printer_setup');
  const link = el('a', null, null, {href: URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'})), download: 'kienzle-sumup-drucker.json'});
  document.body.append(link); link.click(); link.remove();
  message('Einrichtungsdatei heruntergeladen. Sie enthält den Rechnungseingangs-Schlüssel – nur auf den Druckserver kopieren und danach löschen.');
});

// ---- Terminzettel: Hinweise und Live-Vorschau -------------------------------------------
let previewTimer = null, previewSeq = 0;
// Druckeradresse (http://Name:631/printers/X) zusätzlich mit den IP-Adressen des Servers, z. B. für Zugriffe aus dem VPN.
function ippByIp(url, ips) {
  const path = String(url).replace(/^http:\/\/[^/]+/, '');
  return (ips || []).map(i => ({name: i.name, ip: i.ip, url: 'http://' + i.ip + ':631' + path}));
}
function ippPowerShell(queue, url, ips, paper) {
  const byIp = ippByIp(url, ips), use = byIp.length ? byIp[0].url : url;
  return 'Enable-WindowsOptionalFeature -Online -FeatureName Printing-Foundation-InternetPrinting-Client -NoRestart\nAdd-PrinterDriver -Name "Microsoft IPP Class Driver"\n$url = "' + use + '"\nAdd-PrinterPort -Name $url -ErrorAction SilentlyContinue\nAdd-Printer -Name "' + queue + '" -DriverName "Microsoft IPP Class Driver" -PortName $url' + (paper ? '\nSet-PrintConfiguration -PrinterName "' + queue + '" -PaperSize ' + paper : '');
}
function ippIpNote(url, ips) {
  const byIp = ippByIp(url, ips);
  return byIp.length ? 'Per IP-Adresse (z. B. aus dem VPN): ' + byIp.map(i => i.url + ' (' + i.name + ')').join(' oder ') + '. ' : '';
}

function terminzettelCard(card, form, section, info) {
  const status = el('div', null, 'tz-status');
  if (!info.installed) status.append(el('p', 'Der Terminzettel-Druckdienst ist auf diesem Server noch nicht installiert. Bitte einmal „sudo bash scripts/install-server.sh“ ausführen.', 'inline-error'));
  else if (!info.target) { const p = el('p', null, 'inline-error'); p.append(document.createTextNode('Noch kein Bondrucker an diesem Server. '), el('a', 'Jetzt unter „Drucker einrichten“ anlegen', null, {href: '#s-printers'}), document.createTextNode(' – der Terminzettel druckt dann automatisch dort.')); status.append(p); }
  else status.append(el('p', 'Druckt auf den Bondrucker „' + info.target.queue + '“ (' + (info.target.model === 'tm-m30iii' ? 'Epson TM-m30III, 80 mm' : 'Epson TM-m10, 58 mm') + ').', 'notice'));
  const how = el('details', null, 'tz-how'); how.append(el('summary', 'Drucker „Terminzettel“ auf den Arbeitsplätzen hinzufügen'));
  const ol = el('ol');
  ol.append(el('li', 'Windows: Einstellungen → Drucker → Drucker hinzufügen → „Der gewünschte Drucker ist nicht aufgeführt“ → „Freigegebenen Drucker über den Namen auswählen“: ' + info.printer_url + ' – Treiber „Microsoft IPP Class Driver“. ' + ippIpNote(info.printer_url, info.ips) + 'Aus einem anderen Netz (VPN) die IP-Adresse statt des Servernamens verwenden.'));
  const driver = el('li', 'Windows 11: Fehlt der Treiber „Microsoft IPP Class Driver“ oder akzeptiert Windows die Adresse nicht, PowerShell als Administrator öffnen und ausführen (dann ist der Drucker gleich fertig eingerichtet):');
  driver.append(el('pre', ippPowerShell('Terminzettel', info.printer_url, info.ips), 'audit-list'));
  driver.append(el('span', 'Meldet die erste Zeile, dass ein Neustart nötig ist, Windows neu starten und die Befehle ab Zeile 2 wiederholen.', 'muted small'));
  ol.append(driver);
  ol.append(el('li', 'In T2med unter Einstellungen → Kalender → Terminliste (DIN A6) den Drucker „Terminzettel“ wählen.'));
  ol.append(el('li', 'Keine Verbindung? Das VPN-Netz muss unter „Netzwerk & Firewall“ erlaubt und „Druckfreigabe (Port 631 …)“ angehakt sein. Test in der Windows-PowerShell: Test-NetConnection <Adresse> -Port 631. Bei „Zugriff verweigert“ (403) lässt CUPS nur das lokale Netz zu: am Server sudo cupsctl --remote-any ausführen und die Firewall dabei eingeschaltet lassen.'));
  const mac = el('li'); mac.append(document.createTextNode('Mac: „Terminzettel“ unter Drucker hinzufügen wählen, als Treiber '), el('a', 'Terminzettel-PDF.ppd', null, {href: '/assets/Terminzettel-PDF.ppd', download: 'Terminzettel-PDF.ppd'}), document.createTextNode(' (Verwenden → Andere …).')); ol.append(mac);
  how.append(ol);
  const grid = el('div', null, 'tz-grid'); const left = el('div'); const right = el('aside', null, 'tz-preview');
  right.append(el('div', 'Vorschau (Beispieldaten)', 'tz-preview-title'));
  const img = el('img', null, null, {alt: 'Vorschau des Terminbons', id: 'tz-image'}); const note = el('p', null, 'muted small', {id: 'tz-note'});
  right.append(img, note);
  card.insertBefore(status, form); left.append(form, how); grid.append(left, right); card.append(grid);
  const refresh = () => { clearTimeout(previewTimer); previewTimer = setTimeout(async () => {
    const seq = ++previewSeq; right.classList.add('loading');
    try {
      const result = await api('terminzettel_preview', {values: values(form, section)});
      if (seq !== previewSeq) return;
      img.src = result.image; note.textContent = (result.notes || []).join(' ') || 'Zeigt den Bon so, wie er gedruckt wird (Breite ' + ((info.target || {}).model === 'tm-m30iii' ? '80' : '58') + ' mm).';
      note.classList.remove('inline-error');
    } catch (error) { if (seq === previewSeq) { note.textContent = error.message; note.classList.add('inline-error'); } }
    finally { if (seq === previewSeq) right.classList.remove('loading'); }
  }, 450); };
  form.addEventListener('input', refresh); form.addEventListener('change', refresh); refresh();
}

// ---- Drucker einrichten ---------------------------------------------------------------
const PRINTER_FIELDS = {
  kind: {label: 'Art des Druckers', tip: 'Wofür der Drucker verwendet wird. Bondrucker drucken Belege, der A4-Drucker Rechnungen.',
    options: {'tm-m10': 'Schmaler Bondrucker (58 mm) mit Netzwerk/WLAN oder USB.', 'tm-m30iii': 'Breiter Bondrucker (80 mm). Im Webmenü des Druckers „Sicherer Druck“ ausschalten.',
      office: 'Normaler Bürodrucker, z. B. Brother, HP, Canon, Epson, Kyocera. Er muss IPP bzw. AirPrint können (fast alle Netzwerkdrucker seit 2015).'}},
  address: {label: 'IP-Adresse oder Rechnername des Druckers', example: '192.168.1.80',
    tip: 'Steht beim Bondrucker auf dem Statusbon (Einschalten mit gedrückter Vorschubtaste), beim A4-Drucker im Druckermenü unter Netzwerk. Einfacher: oben „suchen“.'},
  name: {label: 'Name (optional)', example: 'TM-m30III', tip: 'Wie der Drucker hier heißen soll. Leer lassen für den Vorschlag. Nur Buchstaben, Ziffern, - und _.'},
};
let printerKinds = {};
function printerRole(queue) { return queue.role === 'receipt' ? 'Bondrucker' : queue.role === 'office' ? 'Praxisdrucker (A4)' : ''; }
function renderPrinters(data) {
  printerKinds = data.kinds || printerKinds;
  $('printers-unavailable').hidden = data.available; $('printers-body').hidden = !data.available;
  const list = $('printer-list'); list.replaceChildren();
  if (!data.queues.length) list.append(el('p', 'Noch kein Drucker eingerichtet.', 'muted'));
  for (const q of data.queues) {
    const row = el('div', null, 'manage-row printer-row');
    const text = el('span'); text.append(el('strong', q.name), document.createTextNode(' · ' + q.address + (q.state ? ' · ' + q.state : '')));
    if (q.role) text.append(el('em', '  – verwendet als ' + printerRole(q), 'role'));
    row.append(text);
    // Virtuelle Drucker für T2med (Rechnungseingang, Terminzettel) werden über ihre eigenen Bereiche verwaltet.
    if (/^(kienzle-sumup|terminzettel):/.test(q.uri)) { text.append(el('em', '  – virtueller Drucker für T2med', 'role')); list.append(row); continue; }
    const kind = el('select', null, 'compact', {'aria-label': 'Verwenden als'});
    for (const [id, k] of Object.entries(printerKinds)) kind.append(el('option', 'als ' + k.label.replace(' im Netzwerk (IPP / AirPrint)', ''), null, {value: id}));
    kind.value = q.role === 'office' ? 'office' : (/m30/i.test(q.name + q.description) ? 'tm-m30iii' : (q.role === 'receipt' || /tm|bon/i.test(q.name) ? 'tm-m10' : 'office'));
    const use = el('button', 'Verwenden', 'secondary', {type: 'button'});
    use.onclick = () => busy(use, async () => { const r = await api('printer_use', {name: q.name, kind: kind.value}); render(r); renderPrinters(r.printers); message(r.message); });
    const test = el('button', 'Testdruck', 'secondary', {type: 'button'}); test.hidden = !q.role;
    test.onclick = () => busy(test, async () => { message((await api('test', {kind: q.role === 'office' ? 'office_printer' : 'receipt_printer'})).message); });
    row.append(kind, use, test);
    if (q.managed) {
      const remove = el('button', 'Entfernen', 'text-button danger', {type: 'button'});
      remove.onclick = () => busy(remove, async () => { const r = await api('printer_remove', {name: q.name}); renderPrinters(r.printers); message(r.message); });
      row.append(remove);
    }
    list.append(row);
  }
}
function printerForm(prefill = {}) {
  const form = $('printer-form'); form.replaceChildren();
  for (const [key, field] of Object.entries(PRINTER_FIELDS)) {
    const row = el('div', null, 'field'); const id = 'p-' + key;
    const line = el('div', null, 'label-row'); line.append(el('label', field.label, null, {for: id}));
    const choices = key === 'kind' ? Object.fromEntries(Object.entries(printerKinds).map(([k, v]) => [k, v.label])) : null;
    const t = tip({...field, choices}); if (t) line.append(t); row.append(line);
    let input;
    if (key === 'kind') {
      input = el('select', null, null, {id, name: key});
      for (const [value, label] of Object.entries(choices)) input.append(el('option', label, null, {value}));
      const explain = el('p', null, 'muted small option-help');
      const update = () => { explain.textContent = field.options[input.value] || ''; const n = form.elements.name; if (n && !n.dataset.touched) n.placeholder = 'Vorschlag: ' + (printerKinds[input.value] || {}).name; };
      input.addEventListener('change', update); row.append(input, explain); setTimeout(update);
    } else {
      input = el('input', null, null, {id, name: key, type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: 'z. B. ' + field.example});
      if (key === 'name') input.oninput = () => { input.dataset.touched = '1'; };
      row.append(input);
    }
    if (prefill[key]) input.value = prefill[key];
    form.append(row);
  }
  const buttons = el('div', null, 'row-buttons'); const add = el('button', 'Drucker einrichten', 'primary', {type: 'submit'}); buttons.append(add); form.append(buttons);
  form.onsubmit = event => { event.preventDefault(); busy(add, async () => {
    const r = await api('printer_add', {kind: form.elements.kind.value, address: form.elements.address.value, name: form.elements.name.value});
    render(r); renderPrinters(r.printers); $('printer-found').replaceChildren(); printerForm(); message(r.message); location.hash = 's-printers';
  }); };
}
$('printer-search').onclick = () => busy($('printer-search'), async () => {
  const box = $('printer-found'); box.replaceChildren(el('p', 'Suche läuft (bis zu 30 Sekunden) …', 'muted'));
  try {
    const {found} = await api('printer_discover');
    box.replaceChildren();
    if (!found.length) { box.append(el('p', 'Keine Drucker gefunden. Ist der Drucker eingeschaltet und im selben Netzwerk? Sonst unten die IP-Adresse eintragen.', 'muted')); return; }
    box.append(el('p', 'Gefunden – „Auswählen“ übernimmt den Drucker ins Formular:', 'muted small'));
    for (const d of found) {
      const row = el('div', null, 'manage-row'); const text = el('span'); text.append(el('strong', d.name), document.createTextNode(' · ' + d.connection));
      const pick = el('button', 'Auswählen', 'secondary', {type: 'button'});
      pick.onclick = () => { printerForm({kind: d.kind || 'office', address: d.uri}); $('printer-form').elements.kind.dispatchEvent(new Event('change')); $('printer-form').scrollIntoView({behavior: 'smooth', block: 'center'}); };
      row.append(text, pick); box.append(row);
    }
  } catch (error) { box.replaceChildren(); throw error; }
});

// ---- Export für die Steuerberatung ------------------------------------------------------
const MONTH_NAMES = ['Ganzes Jahr', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
function renderExport(years, mode) {
  const form = $('export-form'); if (form.dataset.ready) return; form.dataset.ready = '1';
  const row = el('div', null, 'export-row');
  const year = el('select', null, null, {name: 'year', 'aria-label': 'Jahr'}); for (const y of years) year.append(el('option', String(y), null, {value: String(y)}));
  const month = el('select', null, null, {name: 'month', 'aria-label': 'Zeitraum'}); MONTH_NAMES.forEach((n, i) => month.append(el('option', n, null, {value: String(i)})));
  const yearField = el('div', null, 'field'); yearField.append(el('label', 'Jahr'), year);
  const monthField = el('div', null, 'field'); const ml = el('div', null, 'label-row'); ml.append(el('label', 'Zeitraum'));
  const t = tip({label: 'Zeitraum', tip: 'Ganzes Jahr für den Jahresabschluss, ein Monat z. B. für die monatliche Umsatzsteuer-Voranmeldung. Maßgeblich ist das Zahlungsdatum.'}); if (t) ml.append(t);
  monthField.append(ml, month); row.append(yearField, monthField); form.append(row);
  const names = el('label', null, 'check'); const nameBox = el('input', null, null, {type: 'checkbox', name: 'names'}); names.append(nameBox, document.createTextNode(' Patientennamen aufnehmen'));
  const nl = el('div', null, 'label-row'); nl.append(names);
  const nt = tip({label: 'Patientennamen', tip: 'Nur ankreuzen, wenn die Steuerberatung die Namen braucht. Ohne Namen reichen Rechnungsnummer, Datum und Betrag für die Buchhaltung.'}); if (nt) nl.append(nt);
  form.append(nl);
  let testBox = null;
  if (mode !== 'live') { const test = el('label', null, 'check'); testBox = el('input', null, null, {type: 'checkbox', name: 'test'}); test.append(testBox, document.createTextNode(' Testzahlungen einbeziehen (nur zum Ausprobieren)')); form.append(test); }
  const now = new Date(); month.value = '0'; year.value = String(now.getFullYear());
  const buttons = el('div', null, 'row-buttons'); const link = el('a', 'Excel-Tabelle herunterladen', 'button primary', {download: ''}); buttons.append(link); form.append(buttons);
  const update = () => { link.href = '/tax-export.php?' + new URLSearchParams({year: year.value, month: month.value, names: nameBox.checked ? '1' : '0', test: testBox && testBox.checked ? '1' : '0'}); };
  form.onchange = update; update();
  link.onclick = () => message('Excel-Tabelle für ' + (month.value === '0' ? 'das Jahr ' + year.value : MONTH_NAMES[month.value] + ' ' + year.value) + ' wird geladen.');
}

// ---- Bezahlte T2med-Rechnungen abhaken -------------------------------------------------
let t2medFilter = {status: 'open', year: 0, month: 0, test: false};
function t2medPaidSetup(years, mode) {
  const box = $('t2med-paid'); if (box.dataset.ready) return; box.dataset.ready = '1';
  const bar = el('div', null, 'export-row t2med-bar');
  const status = el('select', null, null, {'aria-label': 'Anzeigen'}); status.append(el('option', 'Noch nicht abgehakt', null, {value: 'open'}), el('option', 'Alle', null, {value: 'all'}));
  const year = el('select', null, null, {'aria-label': 'Jahr'}); year.append(el('option', 'Alle Jahre', null, {value: '0'})); for (const y of years) year.append(el('option', String(y), null, {value: String(y)}));
  const month = el('select', null, null, {'aria-label': 'Monat'}); MONTH_NAMES.forEach((n, i) => month.append(el('option', i ? n : 'Alle Monate', null, {value: String(i)})));
  for (const [label, input] of [['Anzeigen', status], ['Jahr', year], ['Monat', month]]) { const f = el('div', null, 'field'); f.append(el('label', label), input); bar.append(f); }
  box.append(bar);
  let test = null;
  if (mode !== 'live') { const l = el('label', null, 'check'); test = el('input', null, null, {type: 'checkbox'}); l.append(test, document.createTextNode(' Testzahlungen anzeigen (nur zum Ausprobieren – nie in T2med abhaken)')); box.append(l); }
  const summary = el('p', null, 'muted small', {id: 't2med-summary'}); const table = el('div', null, 't2med-table', {id: 't2med-table'});
  const buttons = el('div', null, 'row-buttons');
  const done = el('button', 'Markierte als in T2med abgehakt', 'primary', {type: 'button'});
  const undo = el('button', 'Markierte wieder öffnen', 'secondary', {type: 'button'});
  const link = el('a', 'Liste als Excel herunterladen', 'button secondary', {download: ''});
  buttons.append(done, undo, link); box.append(summary, table, buttons);
  const read = () => { t2medFilter = {status: status.value, year: Number(year.value), month: year.value === '0' ? 0 : Number(month.value), test: !!(test && test.checked)}; month.disabled = year.value === '0';
    link.href = '/tax-export.php?' + new URLSearchParams({kind: 't2med', status: t2medFilter.status, year: t2medFilter.year, month: t2medFilter.month, test: t2medFilter.test ? '1' : '0'}); };
  const load = async () => { read(); try { renderT2medPaid(await api('t2med_paid', t2medFilter)); } catch (error) { summary.textContent = error.message; } };
  const mark = (flag, button) => busy(button, async () => {
    const ids = [...table.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value);
    if (!ids.length) throw new Error('Bitte zuerst Rechnungen ankreuzen.');
    const result = await api('t2med_done', {ids, done: flag, filter: t2medFilter}); renderT2medPaid(result); message(result.message);
  });
  done.onclick = () => mark(true, done); undo.onclick = () => mark(false, undo);
  for (const input of [status, year, month, test].filter(Boolean)) input.onchange = load;
  load();
}
function renderT2medPaid(data) {
  const table = $('t2med-table'); table.replaceChildren();
  const euro = c => (c / 100).toLocaleString('de-DE', {style: 'currency', currency: 'EUR'});
  const date = d => d ? d.split('-').reverse().join('.') : '';
  const sum = data.rows.reduce((t, r) => t + r.amount_cents, 0);
  const voided = data.rows.filter(r => r.voided).length;
  badge('abrechnung', data.open_total);
  $('t2med-summary').textContent = data.rows.length ? (data.rows.length - voided) + ' Rechnungen, zusammen ' + euro(sum) + (voided ? ' (' + voided + ' storniert, nicht mitgezählt)' : '') + '. Noch nicht abgehakt insgesamt: ' + data.open_total + '.' : 'Keine Rechnungen für diese Auswahl.' + (data.open_total ? '' : ' Alles abgehakt.');
  if (!data.rows.length) return;
  const t = el('table'); const head = el('tr');
  const all = el('input', null, null, {type: 'checkbox', 'aria-label': 'Alle auswählen'}); const th = el('th'); th.append(all); head.append(th);
  for (const h of ['Re.-Nr.', 'Re.-Datum', 'Patient', 'Geb.', 'Betrag', 'Bezahlt', 'Abgehakt', '']) head.append(el('th', h));
  t.append(head);
  for (const r of data.rows) {
    const tr = el('tr', null, (r.done_date ? 'done ' : '') + (r.voided ? 'voided' : '')); const td = el('td'); const c = el('input', null, null, {type: 'checkbox', value: r.id, 'aria-label': 'Rechnung ' + r.number}); td.append(c); tr.append(td);
    const amount = r.voided ? 'storniert' : euro(r.amount_cents) + (r.corrected ? ' (bezahlt: ' + euro(r.original_cents) + ')' : '');
    for (const v of [r.number + (r.test ? ' (Test)' : ''), date(r.invoice_date), r.patient, date(r.birthdate), amount, date(r.paid_date) + ' ' + r.paid_time, date(r.done_date) || '–']) tr.append(el('td', v));
    const fix = el('button', 'Korrigieren', 'secondary small-button', {type: 'button'});
    fix.onclick = e => { e.stopPropagation(); correctionPanel(r, table, euro); };
    const last = el('td'); last.append(fix); tr.append(last);
    tr.onclick = e => { if (e.target === c || e.target === fix) return; c.checked = !c.checked; };
    t.append(tr);
  }
  all.onchange = () => t.querySelectorAll('td input').forEach(c => { c.checked = all.checked; });
  table.append(t);
}
// Nachträgliche Korrektur einer bezahlten Rechnung (nur Statistik und Excel; keine Erstattung bei SumUp).
function correctionPanel(r, table, euro) {
  const old = table.querySelector('.t2med-correct'); if (old) old.remove();
  const box = el('div', null, 't2med-correct');
  box.append(el('h3', 'Korrektur: Rechnung ' + r.number + (r.patient ? ' – ' + r.patient : '')));
  box.append(el('p', 'Per Karte bezahlt: ' + euro(r.original_cents) + '. Die Korrektur ändert nur die Statistik und die Excel-Liste. Eine Erstattung an den Patienten erfolgt nicht automatisch, sie muss im SumUp-Konto vorgenommen werden. Das Original bleibt gespeichert.', 'muted small'));
  const f1 = el('div', null, 'field'); f1.append(el('label', 'Richtiger Betrag in Euro'));
  const amount = el('input', null, null, {type: 'text', inputmode: 'decimal', value: ((r.voided ? r.original_cents : r.amount_cents) / 100).toFixed(2).replace('.', ',')}); f1.append(amount);
  const f2 = el('div', null, 'field'); f2.append(el('label', 'Notiz (optional, z. B. Grund)'));
  const note = el('input', null, null, {type: 'text', maxlength: '200', value: r.note || ''}); f2.append(note);
  const buttons = el('div', null, 'row-buttons');
  const send = (change, button, confirmText) => { if (confirmText && !confirm(confirmText)) return; busy(button, async () => {
    const result = await api('t2med_correct', {id: r.id, change, amount: amount.value, note: note.value, filter: t2medFilter}); renderT2medPaid(result); message(result.message); }); };
  const save = el('button', 'Betrag ändern', 'primary', {type: 'button'}); save.onclick = () => send('amount', save);
  const cancel = el('button', 'Zahlung stornieren', 'secondary', {type: 'button'}); cancel.onclick = () => send('void', cancel, 'Zahlung für Rechnung ' + r.number + ' stornieren? Sie zählt dann nicht mehr in der Statistik. Das Geld wird dadurch NICHT erstattet.');
  buttons.append(save, cancel);
  if (r.voided || r.corrected) { const undo = el('button', 'Korrektur zurücknehmen', 'secondary', {type: 'button'}); undo.onclick = () => send('restore', undo); buttons.append(undo); }
  const close = el('button', 'Schließen', 'secondary', {type: 'button'}); close.onclick = () => box.remove(); buttons.append(close);
  box.append(f1, f2, buttons);
  table.append(box); box.scrollIntoView({block: 'nearest'});
}

// ---- Arbeitsplätze ---------------------------------------------------------------------
const CLIENT_STEPS = {
  windows: ['ZIP-Datei mit Rechtsklick → „Alle extrahieren …“ entpacken.', '„install-windows.cmd“ doppelklicken. Bei „Windows hat den PC geschützt“: „Weitere Informationen“ → „Trotzdem ausführen“.',
    'Die Zertifikatswarnung mit „Ja“ bestätigen – das Fenster liegt manchmal hinter anderen Fenstern (Taskleiste prüfen).', 'Fertig, wenn „Kienzle-SumUp eingerichtet“ erscheint.'],
  macos: ['ZIP-Datei doppelklicken.', '„install-macos.command“ mit Rechtsklick → „Öffnen“ starten.', 'Mac-Passwort für das Zertifikat eingeben.'],
  linux: ['ZIP-Datei entpacken.', 'Im entpackten Ordner im Terminal: bash install-linux.sh', 'server.crt im Browser als vertrauenswürdig importieren.'],
};
function guessSystem() { const p = navigator.userAgent; return /Windows/.test(p) ? 'windows' : /Mac OS X|Macintosh/.test(p) ? 'macos' : /Linux|X11/.test(p) ? 'linux' : 'windows'; }
function renderClients(clients) {
  const box = $('client-downloads'); box.replaceChildren(); const steps = $('client-steps'); steps.replaceChildren();
  const mine = guessSystem();
  for (const c of clients || []) {
    const link = el('a', 'Paket für ' + c.label + ' laden', c.id === mine ? 'button primary' : 'button secondary', {href: '/client-download.php?os=' + c.id, download: ''});
    link.onclick = () => { renderSteps(c.id); message('Paket für ' + c.label + ' wird geladen. Anleitung steht unten und in LIESMICH.txt.'); };
    box.append(link);
  }
  const t = tip({label: 'Arbeitsplätze', tip: 'Jeder Arbeitsplatz, an dem aus T2med kassiert wird, braucht den Starter einmal. Danach öffnet der Knopf in T2med die Kartenzahlung.' }); if (t) box.append(t);
  renderSteps(mine);
  function renderSteps(id) {
    steps.replaceChildren(el('strong', 'So geht es unter ' + (clients.find(c => c.id === id) || {}).label + ':'));
    const ol = el('ol'); for (const s of CLIENT_STEPS[id]) ol.append(el('li', s)); steps.append(ol);
    const c = clients.find(x => x.id === id);
    if (c && !c.bundled) steps.append(el('p', 'Hinweis: Der Server hat die Starterprogramme nicht vorrätig; der Arbeitsplatz lädt sie bei der Einrichtung von GitHub (Internet nötig).', 'muted small'));
  }
}
async function loadPrinters() { try { renderPrinters(await api('printers')); } catch (error) { message(error.message, true); } printerForm(); }

(async () => {
  try {
    const status = await api('status');
    csrf = status.csrf || '';
    if (status.setup_required) {
      show('setup');
      const hasToken = new URLSearchParams(location.hash.slice(1)).get('setup');
      $('setup-form').hidden = !hasToken; $('setup-missing').hidden = !!hasToken;
    } else if (status.logged_in) await loadSettings();
    else show('login');
  } catch (error) { message(error.message, true); }
})();

// Sicherheitsprotokoll: letzte Vorgänge der Einstellungsseite anzeigen.
$('audit-load').onclick = async () => {
  try {
    const result = await api('audit');
    const box = $('audit-list');
    box.textContent = result.lines.length ? result.lines.join('\n') : 'Noch keine Einträge.';
    box.hidden = false;
  } catch (error) { message(error.message, true); }
};

// ---- Leistungen und GOÄ-Positionen --------------------------------------------------------
let catalogData = null;
const eur = c => (c / 100).toLocaleString('de-DE', {style: 'currency', currency: 'EUR'});
const feeLabels = {standard: 'Ärztlich', technical: 'Technisch (A, E, O)', lab: 'Labor (M / Nr. 437)'};
async function loadCatalog() {
  try { renderCatalog(await api('catalog')); }
  catch (error) { $('catalog-box').replaceChildren(el('p', error.message, 'muted')); }
}
function catField(labelText, input, hint) {
  const f = el('div', null, 'field'); f.append(el('label', labelText), input); if (hint) f.append(el('small', hint, 'muted')); return f;
}
function renderCatalog(data) {
  catalogData = data;
  const box = $('catalog-box'); box.replaceChildren();
  const apply = (result) => { renderCatalog(result); message(result.message); };
  // Liste
  if (!data.services.length) box.append(el('p', 'Noch keine Leistungen angelegt.', 'muted'));
  else {
    const table = el('table', null, 'fw-table'), head = el('tr');
    for (const h of ['Bezeichnung', 'GOÄ', 'Faktor', 'Preis', 'MwSt.', '']) head.append(el('th', h));
    table.append(head);
    for (const sv of data.services) {
      const tr = el('tr');
      const name = el('td', sv.label + (sv.on_request ? ' (auf Verlangen)' : ''));
      const factor = sv.goae_code && sv.factor ? sv.factor.replace('.', ',') + (sv.fee_type !== 'standard' ? ' · ' + (feeLabels[sv.fee_type] || '') : '') : '–';
      tr.append(name, el('td', sv.goae_code || '–'), el('td', factor), el('td', eur(sv.price_cents)), el('td', sv.vat ? 'ja' : '–'));
      const actions = el('td'); const edit = el('button', 'Bearbeiten', 'secondary small-button', {type: 'button'});
      edit.onclick = () => fillCatalogForm(sv);
      const del = el('button', 'Löschen', 'secondary small-button', {type: 'button'});
      del.onclick = () => { if (!confirm('„' + sv.label + '“ aus der Auswahl löschen? Frühere Zahlungen bleiben erhalten.')) return; busy(del, async () => apply(await api('catalog_service_delete', {id: sv.id}))); };
      actions.append(edit, ' ', del); tr.append(actions); table.append(tr);
    }
    box.append(table);
  }
  // Formular
  box.append(el('h3', 'Leistung anlegen oder ändern', null, {id: 'cat-form-title'}));
  const form = el('form', null, 'settings-form', {id: 'cat-form'});
  const id = el('input', null, null, {type: 'hidden', id: 'cat-id'});
  const label = el('input', null, null, {type: 'text', id: 'cat-label', maxlength: '160', required: '', placeholder: 'z. B. Attest'});
  const price = el('input', null, null, {type: 'text', id: 'cat-price', inputmode: 'decimal', maxlength: '12', required: '', placeholder: '10,00'});
  const vat = el('input', null, null, {type: 'checkbox', id: 'cat-vat'});
  const vatRow = el('label', null, 'check'); vatRow.append(vat, document.createTextNode(' mit MwSt. (im Preis enthalten)'));
  if (data.tax.small_business) { vat.disabled = true; vatRow.append(el('small', ' – Kleinunternehmerregelung ist eingeschaltet, Rechnungen werden ohne MwSt. erstellt.', 'muted')); }
  const onRequest = el('input', null, null, {type: 'checkbox', id: 'cat-on-request'});
  const onRow = el('label', null, 'check'); onRow.append(onRequest, document.createTextNode(' Leistung auf Verlangen'));
  const onTip = tip({label: 'Leistung auf Verlangen', tip: 'Für Leistungen, die der Patient ausdrücklich wünscht und die medizinisch nicht notwendig sind. Nach § 1 Abs. 2 GOÄ dürfen solche Leistungen nur auf Verlangen berechnet werden und müssen in der Rechnung entsprechend gekennzeichnet sein. Die App vermerkt „Auf Verlangen“ bei der Position im Beleg und im Akteneintrag in T2med; Preis, Faktor und Steuer ändern sich dadurch nicht. Bei medizinisch notwendigen Leistungen nicht ankreuzen.'});
  const onWrap = el('div', null, 'tip-row'); onWrap.append(onRow); if (onTip) onWrap.append(onTip);
  // GOÄ
  const goae = el('details', null, 'catalog-details', {id: 'cat-goae'}); goae.append(el('summary', 'GOÄ-Angaben und Rechner (optional)'));
  const code = el('input', null, null, {type: 'text', id: 'cat-code', maxlength: '20', placeholder: 'z. B. 250'});
  const base = el('input', null, null, {type: 'text', id: 'cat-base', inputmode: 'decimal', maxlength: '12', placeholder: 'z. B. 10,72'});
  const target = el('input', null, null, {type: 'text', id: 'cat-target', inputmode: 'decimal', maxlength: '12', placeholder: 'z. B. 25,00'});
  const factor = el('input', null, null, {type: 'text', id: 'cat-factor', inputmode: 'decimal', maxlength: '8', value: '2,3'});
  const type = el('select', null, null, {id: 'cat-type'});
  for (const [k, v] of Object.entries(data.fee_types)) type.append(el('option', (feeLabels[k] || k) + ' · Schwelle ' + commaFactor(v.threshold * 100) + ' / max. ' + commaFactor(v.max * 100), null, {value: k}));
  const reason = el('textarea', null, null, {id: 'cat-reason', maxlength: '800', rows: '2', placeholder: 'z. B. überdurchschnittlicher Zeitaufwand wegen …'});
  const result = el('p', null, 'goae-result', {role: 'status', hidden: ''});
  goae.append(catField('GOÄ-Ziffer', code), catField('Einfachsatz (1,0-fach) in Euro', base), catField('Gewünschter Endbetrag in Euro', target), result,
    catField('Steigerungsfaktor', factor), catField('Gebührenrahmen', type),
    catField('Feste Begründung bei Faktor über dem Schwellenwert (optional)', reason), el('p', 'Wird an der Kasse vorausgefüllt und kann dort pro Abrechnung geändert werden. Steht auf der Rechnung und im Beleg.', 'muted small'),
    el('p', 'Rechner: Einfachsatz und gewünschten Endbetrag eintragen, Faktor und Preis werden berechnet (Gebühr = Einfachsatz × Faktor, auf Cent gerundet; bis zu 4 Nachkommastellen). Ohne Einfachsatz ergänzen GOÄ und Faktor nur den Beleg, der Preis bleibt wie eingetragen. Oberhalb der Schwelle wird bei der Abrechnung eine Begründung erfasst.', 'muted small'));
  const show = (text, level) => { result.hidden = !text; result.textContent = text || ''; result.className = 'goae-result' + (level ? ' ' + level : ''); };
  const calc = source => {
    const b = parseCents(base.value), limits = data.fee_types[type.value];
    if (!b) { show(''); return; }
    let h, p;
    if (source === 'factor') {
      const f = String(factor.value).replace(',', '.');
      if (!/^\d(\.\d{1,4})?$/.test(f)) { show('Bitte einen Faktor wie 2,3 oder 2,332 eingeben (höchstens 4 Nachkommastellen).', 'warn'); return; }
      h = factorUnits(f); p = fee(b, h); target.value = (p / 100).toFixed(2).replace('.', ',');
    } else {
      const t = parseCents(target.value);
      if (!t) { show('Gewünschten Endbetrag eintragen, dann wird der Faktor berechnet.'); return; }
      [h, p] = bestFactor(b, t);
      if (h > limits.max * 100) { show('Über dem Höchstsatz (' + commaFactor(limits.max * 100) + '-fach): höchstens ' + eur(fee(b, limits.max * 100)) + ' möglich. Bitte anderen Endbetrag oder Gebührenrahmen wählen.', 'error'); return; }
      factor.value = commaFactor(h);
    }
    if (h > limits.max * 100) { show('Faktor über dem Höchstsatz (' + commaFactor(limits.max * 100) + ').', 'error'); return; }
    price.value = (p / 100).toFixed(2).replace('.', ',');
    const t2 = parseCents(target.value), parts = ['Faktor ' + commaFactor(h) + ' × ' + eur(b) + ' = ' + eur(p) + ' → als Preis übernommen.'];
    if (source !== 'factor' && t2 && p !== t2) parts.push('Genau ' + eur(t2) + ' ist auch mit vier Nachkommastellen beim Faktor nicht möglich.');
    else if (source !== 'factor' && h % 100 !== 0) parts.push('Der Faktor hat ' + (h % 10 === 0 ? '3' : '4') + ' Nachkommastellen, damit der Betrag genau ' + eur(p) + ' ergibt.');
    if (h > limits.threshold * 100) parts.push('Über dem Schwellenwert ' + commaFactor(limits.threshold * 100) + ': Bei der Abrechnung ist eine Begründung nötig.');
    show(parts.join(' '), h > limits.threshold * 100 ? 'warn' : 'ok');
  };
  for (const [input, source] of [[base, 'target'], [target, 'target'], [factor, 'factor']]) input.addEventListener('input', () => calc(source));
  type.addEventListener('change', () => calc('target'));
  const save = el('button', 'Leistung hinzufügen', 'primary', {type: 'submit', id: 'cat-save'});
  const reset = el('button', 'Abbrechen', 'secondary', {type: 'button', hidden: ''});
  const clear = () => { form.reset(); id.value = ''; factor.value = '2,3'; goae.open = false; show(''); save.textContent = 'Leistung hinzufügen'; reset.hidden = true; $('cat-form-title').textContent = 'Leistung anlegen oder ändern'; };
  reset.onclick = clear;
  const buttons = el('div', null, 'row-buttons'); buttons.append(save, reset);
  form.append(id, catField('Bezeichnung', label), catField('Preis in Euro', price), vatRow, goae, onWrap, buttons);
  form.onsubmit = e => { e.preventDefault(); busy(save, async () => apply(await api('catalog_service_save', {id: id.value, label: label.value, price: price.value,
    goae_code: code.value, fee_type: type.value, factor: factor.value, base: base.value, on_request: onRequest.checked, default_reason: reason.value, vat: vat.checked && !vat.disabled}))); };
  window.fillCatalogForm = sv => {
    id.value = sv.id; label.value = sv.label; price.value = (sv.price_cents / 100).toFixed(2).replace('.', ','); code.value = sv.goae_code || '';
    factor.value = (sv.factor || '2.3').replace('.', ','); type.value = sv.fee_type; onRequest.checked = !!sv.on_request; reason.value = sv.default_reason || ''; vat.checked = !!sv.vat;
    base.value = sv.base_cents ? (sv.base_cents / 100).toFixed(2).replace('.', ',') : ''; target.value = sv.base_cents ? price.value : '';
    goae.open = !!sv.goae_code; show(''); save.textContent = 'Änderung speichern'; reset.hidden = false;
    $('cat-form-title').textContent = 'Leistung ändern: ' + sv.label; label.focus();
  };
  box.append(form);
  // Kombinationen
  box.append(el('h3', 'Kombinationen'));
  box.append(el('p', 'Mehrere Einzelleistungen, die an der Kasse mit einem Klick gewählt werden (z. B. Laborprofil mit Blutentnahme).', 'muted small'));
  for (const g of data.groups) {
    const sum = data.services.filter(s => g.services.includes(s.id)).reduce((t, s) => t + s.price_cents, 0);
    const row = el('div', null, 'manage-row');
    row.append(el('span', g.label + ' · ' + eur(sum) + ' (' + data.services.filter(s => g.services.includes(s.id)).map(s => s.label).join(', ') + ')'));
    const edit = el('button', 'Bearbeiten', 'secondary small-button', {type: 'button'});
    const del = el('button', 'Löschen', 'secondary small-button', {type: 'button'});
    del.onclick = () => { if (!confirm('Kombination „' + g.label + '“ löschen? Die Einzelleistungen bleiben erhalten.')) return; busy(del, async () => apply(await api('catalog_group_delete', {id: g.id}))); };
    edit.onclick = () => { gid.value = g.id; glabel.value = g.label; members.querySelectorAll('input').forEach(c => { c.checked = g.services.includes(c.value); }); gsave.textContent = 'Kombination speichern'; greset.hidden = false; glabel.focus(); };
    row.append(edit, ' ', del); box.append(row);
  }
  const gform = el('form', null, 'settings-form');
  const gid = el('input', null, null, {type: 'hidden'});
  const glabel = el('input', null, null, {type: 'text', maxlength: '160', required: '', placeholder: 'z. B. Laborprofil mit Blutentnahme'});
  const members = el('div');
  for (const sv of data.services) { const l = el('label', null, 'check'); const c = el('input', null, null, {type: 'checkbox', value: sv.id}); l.append(c, document.createTextNode(' ' + sv.label + ' · ' + eur(sv.price_cents))); members.append(l); }
  const gsave = el('button', 'Kombination hinzufügen', 'primary', {type: 'submit'});
  const greset = el('button', 'Abbrechen', 'secondary', {type: 'button', hidden: ''});
  greset.onclick = () => { gform.reset(); gid.value = ''; gsave.textContent = 'Kombination hinzufügen'; greset.hidden = true; };
  const gbuttons = el('div', null, 'row-buttons'); gbuttons.append(gsave, greset);
  gform.append(gid, catField('Name der Kombination', glabel), members, gbuttons);
  gform.onsubmit = e => { e.preventDefault(); busy(gsave, async () => apply(await api('catalog_group_save', {id: gid.value, label: glabel.value, services: [...members.querySelectorAll('input:checked')].map(c => c.value)}))); };
  if (data.services.length) box.append(gform);
}

// ---- Rechnungseingang -------------------------------------------------------------------
const inboxState = {offen: 'Offen', bezahlt: 'Bezahlt', 'am Terminal': 'Am Terminal', ersetzt: 'Ersetzt', abgelaufen: 'Abgelaufen'};
function renderInbox(data) {
  const box = $('inbox-box');
  box.replaceChildren();
  if (!data.enabled) { box.append(el('p', '„T2med-Rechnungen übernehmen“ ist ausgeschaltet (Karte weiter unten).', 'muted')); return; }
  const euro = c => (c / 100).toLocaleString('de-DE', {style: 'currency', currency: 'EUR'});
  if (!data.items.length) box.append(el('p', 'Noch keine Rechnung eingegangen.', 'muted'));
  else {
    const table = el('table', null, 'fw-table'), head = el('tr');
    const selected = new Set();
    const bar = el('div', null, 'row-buttons');
    const countText = el('span', '', 'muted');
    const toggleAll = el('input', null, null, {type: 'checkbox', 'aria-label': 'Alle auswählbaren Rechnungen markieren'});
    const boxes = [];
    const refresh = () => {
      for (const b of bar.querySelectorAll('button')) b.disabled = selected.size === 0 || b.dataset.busy === '1';
      countText.textContent = selected.size ? selected.size + ' ausgewählt' : 'Nichts ausgewählt';
      toggleAll.checked = boxes.length > 0 && boxes.every(b => b.checked);
    };
    toggleAll.onchange = () => { for (const b of boxes) { b.checked = toggleAll.checked; b.onchange(); } };
    const selectedRows = () => data.items.filter(r => selected.has(r.id));
    const bulk = (label, action, ask, filter) => {
      const b = el('button', label, 'secondary', {type: 'button'});
      b.onclick = () => {
        const rows = selectedRows().filter(filter);
        if (!rows.length) { message('Für diese Aktion ist keine der markierten Rechnungen geeignet.'); return; }
        if (!confirm(ask(rows))) return;
        busy(b, async () => { const res = await api(action, {ids: rows.map(r => r.id)}); renderInbox(res); message(res.message); });
      };
      return b;
    };
    if (data.letter_enabled) bar.append(bulk('Auswahl per Post senden', 'inbox_letter_many',
      rows => rows.length + ' Rechnung(en) (' + euro(rows.reduce((a, r) => a + r.amount_cents, 0)) + ') ' + (data.letter_mode === 'live' ? 'jetzt per Post verschicken' : 'im TESTMODUS an Letterxpress übergeben (es wird nichts verschickt)') + '?\n\nDabei gehen die Rechnungen mit Patientendaten an Letterxpress. Bereits live gesendete Rechnungen werden nicht erneut gesendet.',
      r => r.postable && !(r.letter || {}).locked));
    bar.append(bulk('Auswahl löschen', 'inbox_delete_many', rows => rows.length + ' Rechnung(en) aus dem Eingang löschen?', r => r.deletable), countText);
    box.append(bar);
    const first = el('th'); first.append(toggleAll); head.append(first);
    for (const h of ['Eingang', 'Nummer', 'Betrag', 'Patient', 'Geb.', 'Status', ...(data.letter_enabled ? ['Post'] : []), '']) head.append(el('th', h));
    table.append(head);
    for (const r of data.items) {
      const tr = el('tr');
      const pick = el('td'), check = el('input', null, null, {type: 'checkbox', 'aria-label': 'Rechnung ' + r.number + ' auswählen'});
      check.disabled = !(r.deletable || (data.letter_enabled && r.postable));
      check.checked = false;
      check.onchange = () => { if (check.checked) selected.add(r.id); else selected.delete(r.id); refresh(); };
      if (!check.disabled) boxes.push(check);
      pick.append(check); tr.append(pick);
      const born = r.birthdate ? r.birthdate.split('-').reverse().join('.') : '';
      for (const v of [when(r.received_at), r.number, euro(r.amount_cents), r.patient, born, inboxState[r.status] || r.status]) tr.append(el('td', v));
      if (data.letter_enabled) {
        const l = r.letter || {};
        const cell = el('td');
        if (l.status) {
          const text = l.status === 'unknown' ? 'Postversand unklar – bitte den Postausgang bei Letterxpress prüfen' : (l.mode === 'live' ? 'Per Post gesendet am ' : 'Testbrief an Letterxpress am ') + when(l.sent_at);
          const icon = el('span', l.status === 'unknown' ? '✉ ?' : '✉', 'post-glyph ' + (l.status === 'unknown' ? 'post-unknown' : l.mode === 'live' ? 'post-sent' : 'post-test'), {title: text, 'aria-label': text, role: 'img'});
          cell.append(icon);
        }
        tr.append(cell);
      }
      const last = el('td');
      if (data.letter_enabled && r.postable && !(r.letter || {}).locked) {
        const l = r.letter || {};
        const post = el('button', 'Per Post', 'secondary small-button', {type: 'button', 'aria-label': 'Rechnung ' + r.number + ' per Post senden'});
        post.onclick = () => {
          const live = data.letter_mode === 'live';
          const text = 'Rechnung ' + r.number + ' (' + r.patient + ', ' + euro(r.amount_cents) + ') ' + (live ? 'jetzt per Post über Letterxpress versenden?' : 'im TESTMODUS an Letterxpress übergeben (es wird nichts verschickt)?') + '\n\nDabei gehen die Rechnung mit Patientendaten an Letterxpress.' + (live ? ' Das kostet Porto und Druck.' : '');
          if (!confirm(text)) return;
          busy(post, async () => { const res = await api('inbox_letter', {id: r.id}); renderInbox(res); message(res.message); });
        };
        last.append(post, ' ');
      }
      if (r.deletable) {
        const del = el('button', 'Löschen', 'secondary small-button', {type: 'button', 'aria-label': 'Rechnung ' + r.number + ' löschen'});
        del.onclick = () => { if (!confirm('Rechnung ' + r.number + ' (' + r.patient + ', ' + euro(r.amount_cents) + ') aus dem Eingang löschen?')) return; busy(del, async () => { const res = await api('inbox_delete', {id: r.id}); renderInbox(res); message(res.message); }); };
        last.append(del);
      }
      tr.append(last); table.append(tr);
    }
    box.append(table);
    refresh();
  }
  if (data.rejected.length) {
    box.append(el('h3', 'Abgelehnte Drucke'));
    box.append(el('p', 'Diese Rechnungen kamen an, wurden aber nicht übernommen. Die Zeilenübersicht zeigt die erkannten Beschriftungen (Namen und Daten sind unkenntlich gemacht). Fehlt eine Beschriftung, trage sie unter „T2med-Rechnungen übernehmen“ als eigenen Textschnipsel ein.', 'muted small'));
    for (const r of data.rejected) {
      const details = el('details', null, 'inbox-reject');
      details.append(el('summary', when(r.at) + ' – ' + r.reason + (r.source ? ' [' + r.source + ']' : '')));
      details.append(el('pre', r.lines.length ? r.lines.join('\n') : 'Keine Beschriftungszeilen erkannt.', 'audit-list'));
      box.append(details);
    }
    const clear = el('button', 'Liste leeren', 'secondary', {type: 'button'});
    clear.onclick = () => busy(clear, async () => { const r = await api('inbox_clear'); renderInbox(r); message(r.message); });
    box.append(clear);
  }
}
async function loadInbox() {
  try { renderInbox(await api('inbox_overview')); }
  catch (error) { $('inbox-box').replaceChildren(el('p', error.message, 'muted')); }
}
$('inbox-load').onclick = loadInbox;

// ---- Netzwerk & Firewall ---------------------------------------------------------------
let fwTimer = null, fwInfo = null;
const fwStateText = {off: 'Aus', requested: 'Wird geladen …', pending: 'Wartet auf Bestätigung', active: 'Aktiv', reverted: 'Zurückgesetzt (nicht bestätigt)', failed: 'Fehler'};
async function loadFirewall() {
  try { fwInfo = await api('firewall_info'); renderFirewall(fwInfo); }
  catch (error) { $('fw-box').replaceChildren(el('p', error.message, 'inline-error')); }
}
function fwCheck(label, checked, id, help) {
  const wrap = el('label', null, 'check'), box = el('input', null, null, {type: 'checkbox', id});
  box.checked = checked; wrap.append(box, document.createTextNode(' ' + label));
  const out = el('div'); out.append(wrap); if (help) out.append(el('p', help, 'muted small'));
  return out;
}
function renderFirewall(info) {
  const box = $('fw-box'); box.replaceChildren();
  const st = info.status, net = info.network;
  if (!info.service) box.append(el('p', 'Der Firewall-Dienst fehlt. Bitte in den Einstellungen aktualisieren oder einmal sudo bash scripts/install-server.sh ausführen.', 'inline-error'));
  box.append(el('p', 'Firewall: ' + (fwStateText[st.state] || st.state) + (st.state === 'active' ? ' – erlaubt: ' + st.networks.join(', ') : ''), 'notice' + (st.state === 'failed' || st.state === 'reverted' ? ' error' : '')));
  if (st.message && ['failed', 'reverted'].includes(st.state)) box.append(el('p', st.message, 'inline-error'));
  if (st.state === 'pending') {
    const left = Math.max(0, st.deadline - st.now);
    const warn = el('div', null, 'notice'); warn.append(el('strong', 'Funktioniert diese Seite noch? '), document.createTextNode('Dann jetzt bestätigen – sonst werden die Regeln in '), el('strong', left + ' Sekunden', null, {id: 'fw-left'}), document.createTextNode(' zurückgesetzt.'));
    const ok = el('button', 'Ja, alles funktioniert – dauerhaft übernehmen', 'primary', {type: 'button'}); ok.style.marginTop = '10px';
    ok.onclick = () => busy(ok, async () => { message((await api('firewall_confirm')).message); await loadFirewall(); });
    warn.append(el('br'), ok); box.append(warn); watchFirewall(st);
  } else if (st.state === 'requested') watchFirewall(st);

  // Erkanntes Netz
  box.append(el('h3', 'Erkanntes Netzwerk'));
  if (!net.tools) box.append(el('p', 'Die Netzwerkabfrage (ip) ist auf diesem Server nicht verfügbar. Netze bitte unten von Hand eintragen.', 'muted'));
  const table = el('table', null, 'fw-table'); table.append(el('tr')); 
  for (const h of ['Anschluss', 'Adresse', 'Netz', 'Freigeben']) table.firstChild.append(el('th', h));
  const allowed = new Set(info.config.cidrs); let rows = 0;
  for (const nic of net.interfaces) for (const a of nic.addresses) {
    const tr = el('tr'); rows++;
    tr.append(el('td', nic.name + (nic.state === 'up' ? '' : ' (' + nic.state + ')')), el('td', a.ip), el('td', a.network));
    const cell = el('td');
    if (a.private) {
      const c = el('input', null, null, {type: 'checkbox', 'data-net': a.network, 'aria-label': a.network + ' freigeben'}); c.checked = allowed.has(a.network);
      cell.append(c);
    } else cell.append(el('span', 'öffentlich – nicht möglich', 'muted small'));
    tr.append(cell); table.append(tr);
  }
  if (rows) box.append(table);
  const facts = [];
  if (net.gateway) facts.push('Router: ' + net.gateway.ip);
  facts.push('Dieses Gerät: ' + info.client.ip + (info.client.network ? ' (Netz ' + info.client.network + ')' : ''));
  box.append(el('p', facts.join(' · '), 'muted'));
  const known = new Set(info.config.cidrs), extra = info.config.cidrs.filter(c => !net.interfaces.some(n => n.addresses.some(a => a.network === c)));
  const label = el('label', 'Weitere Netze (z. B. anderes Praxisnetz oder VPN), eines pro Zeile', null, {for: 'fw-extra'});
  const area = el('textarea', null, null, {id: 'fw-extra', rows: '2', placeholder: '192.168.2.0/24'}); area.value = extra.join('\n');
  const fieldWrap = el('div', null, 'field settings-form'); fieldWrap.append(label, area); box.append(fieldWrap);
  if (net.neighbors.length) {
    const d = el('details'); d.append(el('summary', 'Geräte, die der Server zuletzt gesehen hat (' + net.neighbors.length + ')'));
    d.append(el('p', 'Zur Kontrolle: Arbeitsplätze, Drucker, Druckserver und der T2med-Rechner müssen in einem der freigegebenen Netze liegen.', 'muted small'));
    d.append(el('p', net.neighbors.map(n => n.ip).join(', '), 'small'));
    box.append(d);
  }
  // Optionen
  const opts = el('div', null, 'settings-form');
  opts.append(fwCheck('SSH (Fernwartung des Servers) nur aus diesen Netzen erlauben', info.config.ssh, 'fw-ssh', 'Der SSH-Port wird automatisch erkannt. Ohne Haken bleibt SSH unverändert.'));
  opts.append(fwCheck('Druckfreigabe (Port 631, z. B. Terminzettel-Drucker für T2med) nur aus diesen Netzen erlauben', info.config.ipp, 'fw-ipp', 'Nötig, wenn T2med auf Drucker dieses Servers druckt.'));
  opts.append(fwCheck('Alles andere von außen sperren (strenger)', info.config.strict, 'fw-strict', 'Blockiert auch alle übrigen eingehenden Verbindungen. Nur ankreuzen, wenn auf diesem Server keine weiteren Dienste für das Netz laufen (z. B. Dateifreigaben).'));
  box.append(opts);
  // Dienste der Kienzle-Projekte
  const svcBox = el('div', null, 'settings-form'); svcBox.append(el('h3', 'Weitere Dienste auf diesem Server'));
  svcBox.append(el('p', 'Kreuze an, was hier läuft oder von hier aus erreichbar sein muss. Angekreuzte Dienste sind nur aus den freigegebenen Netzen erreichbar – außer WireGuard und die App-Anbindung, die aus dem Internet kommen. Ohne „Alles andere sperren“ bleiben nicht angekreuzte Dienste unverändert.', 'muted small'));
  const listenSet = new Set(net.listening.map(l => String(l.port)));
  for (const [id, svc] of Object.entries(info.catalog)) {
    const ports = ['tcp', 'udp'].flatMap(proto => (svc[proto] || []).map(p => p + '/' + proto)).join(', ');
    const running = (svc.tcp || []).some(p => listenSet.has(p));
    const wrap = fwCheck(svc.label + ' (' + ports + ')' + (svc.scope === 'any' ? ' – von überall' : ''), info.config.services.includes(id), 'fw-svc-' + id, svc.note + (running ? ' Läuft auf diesem Server.' : ''));
    wrap.querySelector('input').dataset.service = id; svcBox.append(wrap);
  }
  const customLabel = el('label', 'Eigene Ports (einer pro Zeile, z. B. tcp 8080 oder udp 5000-5100)', null, {for: 'fw-custom'});
  const customArea = el('textarea', null, null, {id: 'fw-custom', rows: '2', placeholder: 'tcp 8080'}); customArea.value = (info.config.custom || []).join('\n');
  const customField = el('div', null, 'field'); customField.append(customLabel, customArea); svcBox.append(customField);
  box.append(svcBox);
  const listening = net.listening.filter(l => !l.local_only);
  if (listening.length) {
    const d = el('details'); d.append(el('summary', 'Von außen erreichbare Ports dieses Servers (' + listening.length + ')'));
    d.append(el('p', listening.map(l => l.port).join(', '), 'small'));
    d.append(el('p', 'Ports, die hier nicht verwaltet werden (nicht Weboberfläche, SSH, Druck oder oben angekreuzt), bleiben ohne „Alles andere sperren“ unverändert.', 'muted small'));
    box.append(d);
  }
  const buttons = el('div', null, 'row-buttons');
  const apply = el('button', 'Firewall anwenden', 'primary', {type: 'button'}), off = el('button', 'Firewall ausschalten', 'secondary', {type: 'button'}), refresh = el('button', 'Netzwerk neu abfragen', 'secondary', {type: 'button'});
  apply.disabled = !info.service || ['pending', 'requested'].includes(st.state); off.disabled = !info.service || st.state === 'off';
  apply.onclick = () => busy(apply, async () => {
    const v = fwValues();
    if (v.strict) {
      // Warnen, welche gerade lauschenden Ports „alles andere sperren“ treffen würde.
      const kept = new Set([String(info.web_port)]);
      if (v.ssh) net.listening.filter(l => [22, 2222].includes(l.port)).forEach(l => kept.add(String(l.port)));
      if (v.ipp) kept.add('631');
      for (const id of v.services) for (const p of [...(info.catalog[id].tcp || []), ...(info.catalog[id].udp || [])]) kept.add(p);
      for (const c of v.custom.split(/[\n,;]+/)) { const m = c.trim().match(/^(?:tcp|udp)[ :]+(\d+)/i); if (m) kept.add(m[1]); }
      const blocked = net.listening.filter(l => !l.local_only && !kept.has(String(l.port))).map(l => l.port);
      if (blocked.length && !confirm('„Alles andere sperren“ würde diese Ports blockieren, auf denen hier etwas lauscht: ' + blocked.join(', ') + '.\n\nWirklich fortfahren? (Abbrechen und den passenden Dienst oben ankreuzen oder unter „Eigene Ports“ eintragen.)')) return;
    }
    const r = await api('firewall_apply', v);
    message(r.message); await loadFirewall();
  });
  off.onclick = () => { if (confirm('Firewall ausschalten? Danach ist der Server im Netz wieder ohne Einschränkung erreichbar.')) busy(off, async () => { message((await api('firewall_disable')).message); setTimeout(loadFirewall, 2500); }); };
  refresh.onclick = () => busy(refresh, loadFirewall);
  buttons.append(apply, off, refresh); box.append(buttons);
}
function fwValues() {
  return {networks: [...document.querySelectorAll('#fw-box input[data-net]:checked')].map(c => c.dataset.net), extra: $('fw-extra').value,
    ssh: $('fw-ssh').checked, ipp: $('fw-ipp').checked, strict: $('fw-strict').checked,
    services: [...document.querySelectorAll('#fw-box input[data-service]:checked')].map(c => c.dataset.service), custom: $('fw-custom').value};
}
// Fortschritt: nach dem Anwenden alle 2 Sekunden Zustand holen; läuft der Zähler, Sekunden aktualisieren.
function watchFirewall(st) {
  clearInterval(fwTimer);
  let remaining = st.state === 'pending' ? Math.max(0, st.deadline - st.now) : null;
  fwTimer = setInterval(async () => {
    if (remaining !== null) { remaining--; const n = $('fw-left'); if (n) n.textContent = Math.max(0, remaining) + ' Sekunden'; }
    try {
      const next = await api('firewall_status');
      if (next.state !== st.state) { clearInterval(fwTimer); await loadFirewall(); }
    } catch (error) { /* Seite kurz nicht erreichbar: weiter versuchen, bis die Zeit abläuft */ }
    if (remaining !== null && remaining < -10) { clearInterval(fwTimer); loadFirewall().catch(() => {}); }
  }, 1000);
}
