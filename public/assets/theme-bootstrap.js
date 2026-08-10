(function () {
  const key = 'haccp-theme';
  const allowed = ['system', 'light', 'dark'];
  const stored = window.localStorage.getItem(key);
  const preference = allowed.includes(stored) ? stored : 'system';
  const resolved = preference === 'system'
    ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
    : preference;
  document.documentElement.dataset.themePreference = preference;
  document.documentElement.dataset.theme = resolved;
  window.HaccpTheme = { key, allowed, preference };
}());
