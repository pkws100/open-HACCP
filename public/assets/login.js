import { api, setCsrfToken, ApiError } from './api.js?v=20260810-1';
import { applyTheme, bindThemeControls } from './theme.js?v=20260810-1';

const loginForm = document.querySelector('#login-form');
const passwordForm = document.querySelector('#password-form');
const loginMessage = document.querySelector('#login-message');
const passwordMessage = document.querySelector('#password-message');
bindThemeControls((preference) => applyTheme(preference));

try {
  const session = await api('/api/v1/auth/me');
  setCsrfToken(session.csrf_token);
  applyTheme(session.user.theme_preference || 'system');
  if (!session.user.password_change_required) window.location.replace('/dashboard');
  else { loginForm.hidden = true; passwordForm.hidden = false; }
} catch (error) {
  if (!(error instanceof ApiError) || error.status !== 401) console.error(error);
}

loginForm.addEventListener('submit', async (event) => {
  event.preventDefault(); loginMessage.hidden = true;
  const data = new FormData(loginForm);
  const button = loginForm.querySelector('button'); button.disabled = true; button.textContent = 'Anmeldung wird geprüft …';
  try {
    const result = await api('/api/v1/auth/login', { method: 'POST', body: { username: data.get('username'), password: data.get('password') } });
    setCsrfToken(result.csrf_token);
    applyTheme(result.user.theme_preference || 'system');
    if (result.user.password_change_required) {
      loginForm.hidden = true; passwordForm.hidden = false;
      passwordForm.current_password.value = data.get('password'); passwordForm.new_password.focus();
    } else window.location.replace('/dashboard');
  } catch (error) {
    loginMessage.textContent = error.message; loginMessage.hidden = false;
  } finally { button.disabled = false; button.textContent = 'Sicher anmelden'; }
});

passwordForm.addEventListener('submit', async (event) => {
  event.preventDefault(); passwordMessage.hidden = true;
  const data = new FormData(passwordForm);
  if (data.get('new_password') !== data.get('confirmation')) { passwordMessage.textContent = 'Die neuen Passwörter stimmen nicht überein.'; passwordMessage.hidden = false; return; }
  const button = passwordForm.querySelector('button'); button.disabled = true;
  try {
    await api('/api/v1/auth/me/password', { method: 'PUT', body: { current_password: data.get('current_password'), new_password: data.get('new_password') } });
    passwordMessage.textContent = 'Passwort gespeichert. Sie werden zur erneuten Anmeldung weitergeleitet.'; passwordMessage.classList.add('is-success'); passwordMessage.hidden = false;
    window.setTimeout(() => window.location.replace('/login'), 900);
  } catch (error) { passwordMessage.textContent = error.message; passwordMessage.hidden = false; }
  finally { button.disabled = false; }
});
