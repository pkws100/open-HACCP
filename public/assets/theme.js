const state = window.HaccpTheme || { key: 'haccp-theme', allowed: ['system', 'light', 'dark'], preference: 'system' };
const media = window.matchMedia('(prefers-color-scheme: light)');

export function themePreference() {
  return state.preference;
}

export function applyTheme(preference, { persist = true, announce = true } = {}) {
  const next = state.allowed.includes(preference) ? preference : 'system';
  const resolved = next === 'system' ? (media.matches ? 'light' : 'dark') : next;
  state.preference = next;
  document.documentElement.dataset.themePreference = next;
  document.documentElement.dataset.theme = resolved;
  document.documentElement.style.colorScheme = resolved;
  if (persist) window.localStorage.setItem(state.key, next);
  const themeColor = document.querySelector('meta[name="theme-color"]');
  if (themeColor) themeColor.content = resolved === 'light' ? '#f3f7f6' : '#0b1212';
  document.querySelectorAll('[data-theme-choice]').forEach((button) => {
    const selected = button.dataset.themeChoice === next;
    button.classList.toggle('is-active', selected);
    button.setAttribute('aria-pressed', String(selected));
  });
  if (announce) window.dispatchEvent(new CustomEvent('haccp:themechange', { detail: { preference: next, resolved } }));
  return next;
}

export function bindThemeControls(onChange) {
  document.querySelectorAll('[data-theme-choice]').forEach((button) => {
    button.addEventListener('click', () => onChange(button.dataset.themeChoice));
  });
  applyTheme(state.preference, { persist: false, announce: false });
}

media.addEventListener('change', () => {
  if (state.preference === 'system') applyTheme('system', { persist: false });
});
