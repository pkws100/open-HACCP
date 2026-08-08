import { api } from '../api.js?v=20260809-2';
import { escapeHtml, formatDate, statusLabel, statusPill } from '../format.js?v=20260809-2';

let context;
let initialized = false;
let pollTimer;
let catalogLoaded = false;

export const exportsView = {
  init(app) {
    context = app;
    if (initialized) return; initialized = true;
    const form = document.querySelector('#export-form');
    const now = new Date(); const from = new Date(now); from.setDate(from.getDate() - 30);
    form.from.value = from.toISOString().slice(0, 10); form.to.value = now.toISOString().slice(0, 10);
    if (context.user.role === 'auditor') form.mode.querySelector('[value="extended"]')?.remove();
    form.mode.addEventListener('change', () => { document.querySelector('#extended-fields').hidden = form.mode.value !== 'extended'; });
    document.querySelector('#export-devices').addEventListener('change', filterPoints);
    form.addEventListener('submit', submit);
    document.querySelector('#exports-refresh').addEventListener('click', loadJobs);
  },
  async load() { await Promise.all([loadCatalog(), loadPreflight(), loadJobs()]); },
};

async function loadCatalog() {
  if (catalogLoaded) return;
  const overview = await api('/api/v1/dashboard/overview?hours=24');
  context.devices = overview.devices;
  const details = await Promise.all(overview.devices.map((device) => api(`/api/v1/dashboard/overview?hours=24&device=${encodeURIComponent(device.device_uid)}`)));
  document.querySelector('#export-devices').innerHTML = overview.devices.map((device) => `<option value="${escapeHtml(device.device_uid)}">${escapeHtml(device.name)}</option>`).join('');
  document.querySelector('#export-points').innerHTML = details.flatMap((detail) => detail.measurement_points.map((point) => `<option value="${point.id}" data-device="${escapeHtml(detail.selection.device_uid)}">${escapeHtml(detail.selected_device.name)} · ${escapeHtml(point.name)}</option>`)).join('');
  catalogLoaded = true;
}

function filterPoints() {
  const devices = selectedValues(document.querySelector('#export-devices'));
  document.querySelectorAll('#export-points option').forEach((option) => {
    option.hidden = devices.length > 0 && !devices.includes(option.dataset.device);
    if (option.hidden) option.selected = false;
  });
}

async function loadPreflight() {
  const result = await api('/api/v1/dashboard/compliance/preflight');
  const box = document.querySelector('#export-preflight'); box.classList.toggle('is-draft', !result.complete);
  box.innerHTML = result.complete ? '<strong>Preflight vollständig.</strong> Der Nachweis kann ohne Entwurfsmarkierung erzeugt werden.' : `<strong>Nachweis unvollständig.</strong> Der Export wird als Entwurf markiert.<br>${result.issues.map(escapeHtml).join(' · ')}`;
}

async function submit(event) {
  event.preventDefault();
  const form = event.currentTarget; const button = form.querySelector('[type="submit"]');
  const fields = [...document.querySelectorAll('#extended-fields input:checked')].map((input) => input.value);
  const deviceUids = selectedValues(document.querySelector('#export-devices'));
  const pointIds = selectedValues(document.querySelector('#export-points')).map(Number);
  button.disabled = true; button.textContent = 'Wird eingereiht …';
  try {
    const result = await api('/api/v1/dashboard/exports', { method: 'POST', body: { from: form.from.value, to: form.to.value, mode: form.mode.value, format: form.format.value, legal_profile: form.legal_profile.value, device_uids: deviceUids, measurement_point_ids: pointIds, extended_fields: fields } });
    context.showMessage(result.split ? `${result.jobs.length} Teil-Exporte wurden eingereiht.` : `Export ${result.job.public_id} wurde eingereiht.`, true); await loadJobs();
  } catch (error) { context.showMessage(error.message); }
  finally { button.disabled = false; button.textContent = 'Export einreihen'; }
}

function selectedValues(select) { return [...select.selectedOptions].map((option) => option.value); }

async function loadJobs() {
  window.clearTimeout(pollTimer);
  const result = await api('/api/v1/dashboard/exports');
  document.querySelector('#export-table').innerHTML = result.jobs.map((job) => `<tr><td>${formatDate(job.created_at)}<small>${escapeHtml(job.public_id)}</small></td><td>${job.mode === 'authority' ? 'Kernumfang' : 'Erweitert'}${job.draft ? '<small>Entwurf</small>' : ''}</td><td>${job.format === 'csv' ? 'CSV-Paket' : job.format.toUpperCase()}</td><td>${statusPill(statusLabel(job.status), job.status)}</td><td>${escapeHtml(job.requested_by || '')}</td><td>${job.status === 'complete' ? `<span class="mono">${escapeHtml((job.sha256 || '').slice(0, 16))}…</span><br><a class="table-action" href="/api/v1/dashboard/exports/${encodeURIComponent(job.public_id)}/download">Herunterladen</a>` : job.status === 'failed' ? `<span title="${escapeHtml(job.error_message || '')}">${escapeHtml(job.error_code || 'Fehler')}</span>` : job.status === 'expired' ? 'Datei abgelaufen' : '–'}</td></tr>`).join('') || '<tr class="empty-row"><td colspan="6">Noch keine Exportaufträge.</td></tr>';
  if (result.jobs.some((job) => ['queued', 'running'].includes(job.status))) pollTimer = window.setTimeout(loadJobs, 4000);
}
