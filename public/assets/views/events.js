import { api } from '../api.js?v=20260809-2';
import { closeDialog, errorMessage, openDialog } from '../dialog.js?v=20260809-2';
import { escapeHtml, eventLabel, formatDate, statusLabel, statusPill } from '../format.js?v=20260809-2';

const state = { status: 'all', device: '', days: 30, initialized: false };
let context;

export const eventsView = {
  init(app) {
    context = app;
    if (state.initialized) return; state.initialized = true;
    document.querySelector('#event-state').addEventListener('change', (event) => { state.status = event.target.value; load(); });
    document.querySelector('#event-device').addEventListener('change', (event) => { state.device = event.target.value; load(); });
    document.querySelector('#event-days').addEventListener('change', (event) => { state.days = Number(event.target.value); load(); });
  },
  async load() { await ensureDevices(); await load(); },
};

async function ensureDevices() {
  if (!context.devices.length) context.devices = (await api('/api/v1/dashboard/overview?hours=24')).devices;
  document.querySelector('#event-device').innerHTML = '<option value="">Alle Geräte</option>' + context.devices.map((device) => `<option value="${escapeHtml(device.device_uid)}" ${state.device === device.device_uid ? 'selected' : ''}>${escapeHtml(device.name)}</option>`).join('');
}

async function load() {
  const params = new URLSearchParams({ state: state.status, days: String(state.days) }); if (state.device) params.set('device', state.device);
  const result = await api(`/api/v1/dashboard/events?${params}`);
  const openCount = result.events.filter((event) => event.state !== 'resolved').length;
  const badge = document.querySelector('#event-badge'); badge.textContent = String(openCount); badge.hidden = openCount === 0;
  document.querySelector('#event-table').innerHTML = result.events.map((event) => `<tr data-id="${event.id}" data-action><td>${formatDate(event.opened_at)}${event.closed_at ? `<small>Ende ${formatDate(event.closed_at)}</small>` : ''}</td><td><strong>${escapeHtml(event.device_name)}</strong><small>${escapeHtml(event.point_name || 'Geräteereignis')}</small></td><td>${escapeHtml(eventLabel(event.event_type))}</td><td>${statusPill(event.severity === 'critical' ? 'Kritisch' : 'Hinweis', event.severity)}</td><td>${statusPill(statusLabel(event.state), event.state === 'open' ? 'critical' : event.state === 'resolved' ? 'complete' : 'warning')}</td><td>${event.action_count}</td></tr>`).join('') || '<tr class="empty-row"><td colspan="6">Keine Abweichungen im gewählten Zeitraum.</td></tr>';
  document.querySelectorAll('#event-table tr[data-id]').forEach((row) => row.addEventListener('click', () => showDetail(Number(row.dataset.id))));
}

async function showDetail(id) {
  const detail = await api(`/api/v1/dashboard/events/${id}`);
  const event = detail.event;
  const actions = detail.actions.map((action) => `<div class="editor-panel"><p class="eyebrow">Maßnahme ${action.id} · Revision ${action.current_revision} · ${escapeHtml(statusLabel(action.state))}</p><dl class="detail-list"><div><dt>Ursache</dt><dd>${escapeHtml(action.cause)}</dd></div><div><dt>Handlung</dt><dd>${escapeHtml(action.action_taken)}</dd></div><div><dt>Betroffene Ware</dt><dd>${escapeHtml(action.product_disposition)}</dd></div><div><dt>Verantwortlich</dt><dd>${escapeHtml(action.responsible_name)} · ${formatDate(action.performed_at)}</dd></div>${action.verified_at ? `<div><dt>Geprüft</dt><dd>${escapeHtml(action.verified_by)} · ${formatDate(action.verified_at)}<br>${escapeHtml(action.verification_note)}</dd></div>` : ''}</dl>${context.user.role !== 'auditor' && !action.verified_at ? `<div class="dialog-actions"><button class="secondary-button" type="button" data-revise="${action.id}">Korrektur anhängen</button><button class="secondary-button" type="button" data-verify="${action.id}">Maßnahme prüfen</button></div>` : ''}</div>`).join('');
  openDialog({ heading: eventLabel(event.event_type), kicker: `${event.device_name} · ${formatDate(event.opened_at)}`, html: `<dl class="detail-list"><div><dt>Status</dt><dd>${statusLabel(event.state)}</dd></div><div><dt>Messstelle</dt><dd>${escapeHtml(event.point_name || 'Gerät')}</dd></div><div><dt>Beobachtung</dt><dd>${event.observed_value ?? '–'} ${event.threshold_min != null || event.threshold_max != null ? `(Grenzen ${event.threshold_min ?? '–'} bis ${event.threshold_max ?? '–'})` : ''}</dd></div><div><dt>Bestätigt</dt><dd>${event.acknowledged_by ? `${escapeHtml(event.acknowledged_by)} · ${formatDate(event.acknowledged_at)}` : 'Noch nicht'}</dd></div></dl>${actions || '<p class="form-note">Noch keine Korrekturmaßnahme dokumentiert.</p>'}<div class="dialog-actions">${context.user.role !== 'auditor' && event.state === 'open' ? '<button class="secondary-button" type="button" data-ack>Bestätigen</button>' : ''}${context.user.role !== 'auditor' && event.state !== 'resolved' ? '<button class="primary-button" type="button" data-action>Maßnahme erfassen</button>' : ''}</div>`, onOpen(root) {
    root.querySelector('[data-ack]')?.addEventListener('click', async () => { await api(`/api/v1/dashboard/events/${id}/acknowledge`, { method: 'POST' }); context.showMessage('Abweichung wurde bestätigt.', true); closeDialog(); await load(); });
    root.querySelector('[data-action]')?.addEventListener('click', () => actionDialog(id));
    root.querySelectorAll('[data-revise]').forEach((button) => button.addEventListener('click', () => actionDialog(id, detail.actions.find((action) => action.id === Number(button.dataset.revise)))));
    root.querySelectorAll('[data-verify]').forEach((button) => button.addEventListener('click', () => verifyDialog(Number(button.dataset.verify))));
  } });
}

function actionDialog(eventId, existing = null) {
  const options = [context.user].map((user) => `<option value="${user.id}">${escapeHtml(user.display_name)}</option>`).join('');
  const heading = existing ? 'Korrektur anhängen' : 'Korrekturmaßnahme';
  openDialog({ heading, kicker: 'Append-only Nachweis', html: `<form id="action-form"><label>Ursache<textarea name="cause" required maxlength="4000">${escapeHtml(existing?.cause || '')}</textarea></label><label>Durchgeführte Handlung<textarea name="action_taken" required maxlength="4000">${escapeHtml(existing?.action_taken || '')}</textarea></label><label>Umgang mit betroffener Ware<textarea name="product_disposition" required maxlength="4000">${escapeHtml(existing?.product_disposition || '')}</textarea></label><label>Vorbeugende Folgemaßnahme<textarea name="preventive_follow_up" maxlength="4000">${escapeHtml(existing?.preventive_follow_up || '')}</textarea></label><div class="form-grid"><label>Durchgeführt am<input name="performed_at" type="datetime-local" required></label><label>Verantwortlich<select name="responsible_user_id">${options}</select></label></div><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">${existing ? 'Revision speichern' : 'Maßnahme dokumentieren'}</button></div></form>`, onOpen(root) {
    const form = root.querySelector('form'); const performed = existing?.performed_at ? new Date(`${existing.performed_at.replace(' ', 'T')}Z`) : new Date(); form.performed_at.value = new Date(performed.getTime() - performed.getTimezoneOffset() * 60000).toISOString().slice(0,16);
    root.querySelector('[data-cancel]').addEventListener('click', closeDialog);
    form.addEventListener('submit', async (event) => { event.preventDefault(); const data = new FormData(form); const body = { cause: data.get('cause'), action_taken: data.get('action_taken'), product_disposition: data.get('product_disposition'), preventive_follow_up: data.get('preventive_follow_up') || null, performed_at: new Date(data.get('performed_at')).toISOString(), responsible_user_id: Number(data.get('responsible_user_id')) }; if (existing) body.expected_revision = Number(existing.current_revision); try { await api(existing ? `/api/v1/dashboard/actions/${existing.id}` : `/api/v1/dashboard/events/${eventId}/actions`, { method: existing ? 'PUT' : 'POST', body }); context.showMessage(existing ? 'Korrektur wurde als neue Revision angehängt.' : 'Korrekturmaßnahme wurde unveränderlich protokolliert.', true); closeDialog(); await load(); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } });
  } });
}

function verifyDialog(actionId) {
  openDialog({ heading: 'Maßnahme prüfen', kicker: 'Abschluss mit Passwortbestätigung', html: `<form id="verify-form"><label>Prüfnotiz<textarea name="note" required maxlength="4000"></textarea></label><label>Aktuelles Passwort<input name="password" type="password" autocomplete="current-password" required></label><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Prüfung abschließen</button></div></form>`, onOpen(root) { root.querySelector('[data-cancel]').addEventListener('click', closeDialog); const form = root.querySelector('form'); form.addEventListener('submit', async (event) => { event.preventDefault(); const data = new FormData(form); try { await api(`/api/v1/dashboard/actions/${actionId}/verify`, { method: 'POST', body: { note: data.get('note'), password: data.get('password') } }); context.showMessage('Maßnahme wurde geprüft und signiert.', true); closeDialog(); await load(); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } }); } });
}
