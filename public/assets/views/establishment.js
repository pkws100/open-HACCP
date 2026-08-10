import { api } from '../api.js?v=20260810-1';
import { closeDialog, errorMessage, openDialog } from '../dialog.js?v=20260810-1';
import { escapeHtml, formatDate, statusPill } from '../format.js?v=20260810-1';

let context;
let initialized = false;
let users = [];
let data;

export const establishmentView = {
  init(app) {
    context = app;
    if (initialized) return; initialized = true;
    document.querySelector('#establishment-form')?.addEventListener('submit', saveEstablishment);
  },
  load,
};

async function load() {
  const [compliance, userResult] = await Promise.all([api('/api/v1/dashboard/establishment'), api('/api/v1/dashboard/users')]);
  data = compliance; users = userResult.users.filter((user) => user.active);
  fillEstablishment(); renderPoints();
}

function fillEstablishment() {
  const form = document.querySelector('#establishment-form'); const establishment = data.establishment;
  ['legal_name','trade_name','authority_reference','address_line1','address_line2','postal_code','city','country_code','timezone','general_retention_months'].forEach((name) => { if (form.elements[name]) form.elements[name].value = establishment[name] ?? ''; });
  form.haccp_responsible_user_id.innerHTML = '<option value="">Bitte auswählen</option>' + users.map((user) => `<option value="${user.id}" ${user.id === establishment.haccp_responsible_user_id ? 'selected' : ''}>${escapeHtml(user.display_name)}</option>`).join('');
}

async function saveEstablishment(event) {
  event.preventDefault(); const form = event.currentTarget; const values = new FormData(form); const button = form.querySelector('[type="submit"]'); button.disabled = true;
  try {
    await api('/api/v1/dashboard/establishment', { method: 'PUT', body: { legal_name: values.get('legal_name'), trade_name: values.get('trade_name') || null, authority_reference: values.get('authority_reference') || null, address_line1: values.get('address_line1'), address_line2: values.get('address_line2') || null, postal_code: values.get('postal_code'), city: values.get('city'), country_code: values.get('country_code'), timezone: values.get('timezone'), haccp_responsible_user_id: values.get('haccp_responsible_user_id') ? Number(values.get('haccp_responsible_user_id')) : null, general_retention_months: Number(values.get('general_retention_months')) } });
    context.showMessage('Betriebsangaben wurden gespeichert und auditiert.', true); await load();
  } catch (error) { context.showMessage(error.message); }
  finally { button.disabled = false; }
}

function renderPoints() {
  document.querySelector('#compliance-table').innerHTML = data.measurement_points.map((point) => `<tr data-point="${point.id}" data-action><td data-label="Messstelle"><strong>${escapeHtml(point.device_name)}</strong><small>${escapeHtml(point.point_name || point.name)} · ${escapeHtml(point.location || 'ohne Ortsangabe')}</small></td><td data-label="Rechtsprofil">${point.legal_profile === 'quick_frozen' ? 'Tiefkühlmodul' : 'Allgemeiner HACCP-Nachweis'}</td><td data-label="Klassifizierung">${escapeHtml(point.control_classification || '–')}</td><td data-label="Verantwortlich">${escapeHtml(point.responsible_name || 'Nicht zugewiesen')}</td><td data-label="Instrument">${point.conformity_status === 'documented' ? statusPill('Dokumentiert', 'complete') : statusPill('Nicht dokumentiert', 'warning')}<small>${escapeHtml([point.instrument_manufacturer, point.instrument_model].filter(Boolean).join(' ') || 'Keine Instrumentenangabe')}</small></td><td data-label="Version">${point.config_version ?? 0}<small>${formatDate(point.effective_from)}</small></td></tr>`).join('') || '<tr class="empty-row"><td colspan="6">Keine aktiven Messstellen.</td></tr>';
  document.querySelectorAll('#compliance-table tr[data-point]').forEach((row) => row.addEventListener('click', () => pointDialog(data.measurement_points.find((point) => Number(point.id) === Number(row.dataset.point)))));
}

function pointDialog(point) {
  const responsible = '<option value="">Bitte auswählen</option>' + users.map((user) => `<option value="${user.id}" ${Number(point.responsible_user_id) === user.id ? 'selected' : ''}>${escapeHtml(user.display_name)}</option>`).join('');
  openDialog({ heading: point.point_name || point.name, kicker: `${point.device_name} · Compliance-Version ${point.config_version ?? 0}`, html: `<form id="compliance-form"><div class="form-grid"><label>Rechtsprofil<select name="legal_profile"><option value="general_haccp" ${point.legal_profile !== 'quick_frozen' ? 'selected' : ''}>Allgemeiner DE/EU-HACCP</option><option value="quick_frozen" ${point.legal_profile === 'quick_frozen' ? 'selected' : ''}>Tiefkühlmodul</option></select></label><label>Klassifizierung<select name="classification"><option value="GHP" ${point.control_classification === 'GHP' ? 'selected' : ''}>GHP</option><option value="OPRP" ${point.control_classification === 'OPRP' ? 'selected' : ''}>OPRP</option><option value="CCP" ${point.control_classification === 'CCP' ? 'selected' : ''}>CCP</option></select></label><label>Überwachungszweck<input name="purpose" maxlength="255" value="${escapeHtml(point.monitoring_purpose || 'Temperaturüberwachung')}" required></label><label>Verantwortlich<select name="responsible">${responsible}</select></label><label>Aufbewahrung Monate<input name="retention" type="number" min="1" max="120" value="${point.retention_months || 24}" required></label><label>Feuchte kritisch<select name="humidity"><option value="false" ${!Number(point.humidity_is_critical) ? 'selected' : ''}>Nein</option><option value="true" ${Number(point.humidity_is_critical) ? 'selected' : ''}>Ja</option></select></label></div><fieldset><legend>Instrument und Nachweis</legend><div class="form-grid"><label>Hersteller<input name="manufacturer" maxlength="160" value="${escapeHtml(point.instrument_manufacturer || '')}"></label><label>Modell<input name="model" maxlength="160" value="${escapeHtml(point.instrument_model || '')}"></label><label>Seriennummer<input name="serial" maxlength="160" value="${escapeHtml(point.instrument_serial || '')}"></label><label>Konformitätsstatus<select name="conformity"><option value="not_documented" ${point.conformity_status !== 'documented' && point.conformity_status !== 'expired' ? 'selected' : ''}>Nicht dokumentiert</option><option value="documented" ${point.conformity_status === 'documented' ? 'selected' : ''}>Dokumentiert</option><option value="expired" ${point.conformity_status === 'expired' ? 'selected' : ''}>Abgelaufen</option></select></label><label>Nachweisreferenz<input name="reference" maxlength="255" value="${escapeHtml(point.conformity_reference || '')}"></label><label>Kalibriert am<input name="calibrated" type="date" value="${escapeHtml(point.calibrated_at || '')}"></label><label>Prüfung fällig<input name="due" type="date" value="${escapeHtml(point.verification_due_at || '')}"></label></div></fieldset><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Neue Version speichern</button></div></form>`, onOpen(root) {
    root.querySelector('input[name="calibrated"]').closest('label').insertAdjacentHTML('beforebegin', `<label>Kalibrierungsreferenz<input name="calibration_reference" maxlength="255" value="${escapeHtml(point.calibration_reference || '')}"></label><label>Prüfreferenz<input name="verification_reference" maxlength="255" value="${escapeHtml(point.verification_reference || '')}"></label>`);
    root.querySelector('[data-cancel]').addEventListener('click', closeDialog); const form = root.querySelector('form');
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); const values = new FormData(form);
      try {
        await api(`/api/v1/dashboard/measurement-points/${point.id}/compliance`, { method: 'PUT', body: {
          expected_config_version: Number(point.config_version || 0), legal_profile: values.get('legal_profile'),
          control_classification: values.get('classification'), monitoring_purpose: values.get('purpose'),
          humidity_is_critical: values.get('humidity') === 'true', retention_months: Number(values.get('retention')),
          responsible_user_id: values.get('responsible') ? Number(values.get('responsible')) : null,
          instrument_manufacturer: values.get('manufacturer') || null, instrument_model: values.get('model') || null,
          instrument_serial: values.get('serial') || null, conformity_status: values.get('conformity'),
          conformity_reference: values.get('reference') || null,
          calibration_reference: values.get('calibration_reference') || null,
          verification_reference: values.get('verification_reference') || null,
          calibrated_at: values.get('calibrated') || null, verification_due_at: values.get('due') || null,
        } });
        closeDialog(); context.showMessage('Messstellenprofil wurde als neue Version gespeichert.', true); await load();
      } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); }
    });
  } });
}
