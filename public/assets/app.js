import { api, setCsrfToken } from './api.js?v=20260810-1';
import { overviewView } from './views/overview.js?v=20260812-1';
import { analysisView } from './views/analysis.js?v=20260812-1';
import { eventsView } from './views/events.js?v=20260810-1';
import { exportsView } from './views/exports.js?v=20260810-1';
import { usersView } from './views/users.js?v=20260810-1';
import { establishmentView } from './views/establishment.js?v=20260810-1';
import { closeDialog, errorMessage, openDialog } from './dialog.js?v=20260810-1';
import { roleLabel } from './format.js?v=20260810-1';
import { applyTheme, bindThemeControls, themePreference } from './theme.js?v=20260810-1';

const views = { overview: overviewView, analysis: analysisView, events: eventsView, exports: exportsView, users: usersView, establishment: establishmentView };
const message = document.querySelector('#global-message');
let messageTimer;

const session = await api('/api/v1/auth/me');
setCsrfToken(session.csrf_token);
applyTheme(session.user.theme_preference || 'system');
const context = {
  user: session.user,
  api,
  showMessage(text, success = false) {
    window.clearTimeout(messageTimer);
    message.textContent = text; message.className = `global-message${success ? ' is-success' : ''}`; message.hidden = false;
    messageTimer = window.setTimeout(() => { message.hidden = true; }, 5500);
  },
  devices: [],
};

document.querySelector('#user-name').textContent = session.user.display_name;
document.querySelector('#user-initials').textContent = session.user.display_name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
document.querySelector('#mobile-user-name').textContent = session.user.display_name;
document.querySelector('#mobile-user-role').textContent = roleLabel(session.user.role);
if (session.user.role !== 'administrator') document.querySelectorAll('[data-admin-only]').forEach((element) => element.remove());
if (session.user.role === 'auditor') document.querySelectorAll('[data-write]').forEach((element) => {
  if (!element.closest('#export-form')) element.hidden = true;
});

for (const view of Object.values(views)) view.init?.(context);

async function route() {
  let name = window.location.hash.replace('#', '') || 'overview';
  if (!views[name] || (['users', 'establishment'].includes(name) && session.user.role !== 'administrator')) name = 'overview';
  document.querySelectorAll('[data-view]').forEach((section) => { const active = section.dataset.view === name; section.hidden = !active; section.classList.toggle('is-active', active); });
  document.querySelectorAll('[data-view-link]').forEach((link) => link.setAttribute('aria-current', link.dataset.viewLink === name ? 'page' : 'false'));
  document.querySelector('#primary-nav').classList.remove('is-open');
  closeMobileMore();
  document.querySelector('#mobile-menu').setAttribute('aria-expanded', 'false');
  document.querySelector('#workspace').focus({ preventScroll: true });
  try { await views[name].load?.(context); } catch (error) { context.showMessage(error.message); console.error(error); }
}

window.addEventListener('hashchange', route);
document.querySelector('#mobile-menu').addEventListener('click', (event) => { const nav = document.querySelector('#primary-nav'); const open = nav.classList.toggle('is-open'); event.currentTarget.setAttribute('aria-expanded', String(open)); });
document.querySelector('#user-menu').addEventListener('click', passwordDialog);
document.querySelector('#mobile-password').addEventListener('click', () => { closeMobileMore(); passwordDialog(); });
const logout = async () => { try { await api('/api/v1/auth/logout', { method: 'POST' }); } finally { window.location.replace('/login'); } };
document.querySelector('#logout').addEventListener('click', logout);
document.querySelector('#mobile-logout').addEventListener('click', logout);
document.querySelector('#mobile-more-open').addEventListener('click', openMobileMore);
document.querySelector('#mobile-more-close').addEventListener('click', closeMobileMore);
document.querySelector('#mobile-more-backdrop').addEventListener('click', closeMobileMore);
bindThemeControls(async (preference) => {
  const previous = themePreference();
  applyTheme(preference);
  try {
    await api('/api/v1/auth/me/preferences', { method: 'PUT', body: { theme: preference } });
  } catch (error) {
    applyTheme(previous);
    context.showMessage(error.message);
  }
});
await route();

function openMobileMore() {
  document.querySelector('#mobile-more').hidden = false;
  document.querySelector('#mobile-more-backdrop').hidden = false;
  document.querySelector('#mobile-more-open').setAttribute('aria-expanded', 'true');
  window.requestAnimationFrame(() => document.querySelector('#mobile-more').classList.add('is-open'));
  document.querySelector('#mobile-more-close').focus();
}

function closeMobileMore() {
  const panel = document.querySelector('#mobile-more');
  if (!panel || panel.hidden) return;
  panel.classList.remove('is-open');
  document.querySelector('#mobile-more-open').setAttribute('aria-expanded', 'false');
  document.querySelector('#mobile-more-backdrop').hidden = true;
  window.setTimeout(() => { panel.hidden = true; }, 180);
}

function passwordDialog() {
  openDialog({ heading: 'Eigenes Passwort ändern', kicker: session.user.display_name, html: `<form id="own-password-form"><label>Aktuelles Passwort<input name="current" type="password" autocomplete="current-password" required></label><label>Neues Passwort<input name="next" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label><label>Neues Passwort wiederholen<input name="confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Passwort ändern</button></div></form>`, onOpen(root) {
    root.querySelector('[data-cancel]').addEventListener('click', closeDialog);
    const form = root.querySelector('form'); form.addEventListener('submit', async (event) => { event.preventDefault(); const data = new FormData(form); if (data.get('next') !== data.get('confirmation')) { form.querySelector('.form-message').outerHTML = '<div class="form-message">Die neuen Passwörter stimmen nicht überein.</div>'; return; } try { await api('/api/v1/auth/me/password', { method: 'PUT', body: { current_password: data.get('current'), new_password: data.get('next') } }); window.location.replace('/login'); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } });
  } });
}
