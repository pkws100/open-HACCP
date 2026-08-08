import { escapeHtml } from './format.js?v=20260809-2';

const dialog = document.querySelector('#app-dialog');
const title = document.querySelector('#dialog-title');
const eyebrow = document.querySelector('#dialog-eyebrow');
const content = document.querySelector('#dialog-content');

export function openDialog({ heading, kicker = 'Bearbeiten', html, onOpen }) {
  title.textContent = heading;
  eyebrow.textContent = kicker;
  content.innerHTML = html;
  dialog.showModal();
  window.requestAnimationFrame(() => content.querySelector('input, select, textarea, button')?.focus());
  if (onOpen) onOpen(content, dialog);
  return { content, dialog };
}

export function closeDialog() {
  if (dialog.open) dialog.close();
}

dialog.querySelector('[data-dialog-close]').addEventListener('click', closeDialog);

export function errorMessage(error) {
  return `<div class="form-message" role="alert">${escapeHtml(error?.message || 'Die Aktion ist fehlgeschlagen.')}</div>`;
}
