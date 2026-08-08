import { api } from '../api.js?v=20260809-2';
import { closeDialog, errorMessage, openDialog } from '../dialog.js?v=20260809-2';
import { escapeHtml, formatDate, roleLabel, statusPill } from '../format.js?v=20260809-2';

let context;
let initialized = false;
let users = [];

export const usersView = {
  init(app) {
    context = app;
    if (initialized) return; initialized = true;
    document.querySelector('#add-user')?.addEventListener('click', createDialog);
  },
  load,
};

async function load() {
  const result = await api('/api/v1/dashboard/users'); users = result.users;
  document.querySelector('#user-table').innerHTML = users.map((user) => `<tr><td><strong>${escapeHtml(user.display_name)}</strong><small>${escapeHtml(user.email || 'Keine E-Mail')}</small></td><td>${escapeHtml(user.username)}${user.password_change_required ? '<small>Passwortwechsel ausstehend</small>' : ''}</td><td>${roleLabel(user.role)}</td><td>${statusPill(user.active ? 'Aktiv' : 'Deaktiviert', user.active ? 'complete' : 'failed')}</td><td>${formatDate(user.last_login_at)}</td><td><button class="table-action" data-edit="${user.id}">Bearbeiten</button><button class="table-action" data-reset="${user.id}">Passwort zurücksetzen</button></td></tr>`).join('');
  document.querySelectorAll('[data-edit]').forEach((button) => button.addEventListener('click', () => editDialog(users.find((user) => user.id === Number(button.dataset.edit)))));
  document.querySelectorAll('[data-reset]').forEach((button) => button.addEventListener('click', () => resetPassword(Number(button.dataset.reset))));
}

function createDialog() {
  openDialog({ heading: 'Benutzer hinzufügen', kicker: 'Persönlicher Zugriff', html: `<form id="user-form"><div class="form-grid"><label>Benutzername<input name="username" pattern="[a-z0-9][a-z0-9._-]{2,79}" required></label><label>Anzeigename<input name="display_name" maxlength="160" required></label><label>E-Mail<input name="email" type="email" maxlength="254"></label><label>Rolle<select name="role"><option value="operator">Mitarbeitende</option><option value="auditor">Prüfer</option><option value="administrator">Administrator</option></select></label></div><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Benutzer anlegen</button></div></form>`, onOpen(root) { root.querySelector('[data-cancel]').addEventListener('click', closeDialog); const form = root.querySelector('form'); form.addEventListener('submit', async (event) => { event.preventDefault(); const data = new FormData(form); try { const result = await api('/api/v1/dashboard/users', { method: 'POST', body: { username: data.get('username'), display_name: data.get('display_name'), email: data.get('email') || null, role: data.get('role') } }); showTemporary(result.user, result.temporary_password); await load(); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } }); } });
}

function editDialog(user) {
  openDialog({ heading: user.display_name, kicker: `Benutzer ${user.username}`, html: `<form id="user-edit-form"><div class="form-grid"><label>Anzeigename<input name="display_name" value="${escapeHtml(user.display_name)}" required maxlength="160"></label><label>E-Mail<input name="email" type="email" value="${escapeHtml(user.email || '')}" maxlength="254"></label><label>Rolle<select name="role"><option value="operator" ${user.role === 'operator' ? 'selected' : ''}>Mitarbeitende</option><option value="auditor" ${user.role === 'auditor' ? 'selected' : ''}>Prüfer</option><option value="administrator" ${user.role === 'administrator' ? 'selected' : ''}>Administrator</option></select></label><label>Status<select name="active"><option value="true" ${user.active ? 'selected' : ''}>Aktiv</option><option value="false" ${!user.active ? 'selected' : ''}>Deaktiviert</option></select></label></div><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Änderungen speichern</button></div></form>`, onOpen(root) { root.querySelector('[data-cancel]').addEventListener('click', closeDialog); const form = root.querySelector('form'); form.addEventListener('submit', async (event) => { event.preventDefault(); const data = new FormData(form); try { await api(`/api/v1/dashboard/users/${user.id}`, { method: 'PUT', body: { display_name: data.get('display_name'), email: data.get('email') || null, role: data.get('role'), active: data.get('active') === 'true' } }); closeDialog(); context.showMessage('Benutzer wurde aktualisiert.', true); await load(); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } }); } });
}

async function resetPassword(id) {
  if (!window.confirm('Alle Sitzungen dieses Benutzers werden beendet. Passwort wirklich zurücksetzen?')) return;
  try { const result = await api(`/api/v1/dashboard/users/${id}/reset-password`, { method: 'POST' }); const user = users.find((entry) => entry.id === id); showTemporary(user, result.temporary_password); await load(); } catch (error) { context.showMessage(error.message); }
}

function showTemporary(user, password) {
  openDialog({ heading: 'Temporäres Passwort', kicker: 'Nur einmal sichtbar', html: `<p class="form-note">Bitte sicher an ${escapeHtml(user.display_name)} übergeben. Beim ersten Login ist ein neues Passwort erforderlich.</p><dl class="detail-list"><div><dt>Benutzername</dt><dd class="mono">${escapeHtml(user.username)}</dd></div><div><dt>Temporäres Passwort</dt><dd class="mono">${escapeHtml(password)}</dd></div></dl><div class="dialog-actions"><button class="primary-button" type="button" data-done>Ich habe das Passwort gesichert</button></div>`, onOpen(root) { root.querySelector('[data-done]').addEventListener('click', closeDialog); } });
}
