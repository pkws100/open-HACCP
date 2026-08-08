const dateTime = new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Berlin' });
const number = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1 });

export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[character]);
}

export function formatDate(value) {
  if (!value) return '–';
  const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('T') ? '' : 'Z'));
  return Number.isNaN(date.getTime()) ? String(value) : dateTime.format(date);
}

export function formatNumber(value, suffix = '') {
  return value == null ? '–' : `${number.format(Number(value))}${suffix}`;
}

export function alarmLabel(state) {
  return ({ normal: 'Im Bereich', below_min: 'Unter Minimum', above_max: 'Über Maximum', disabled: 'Deaktiviert', no_data: 'Keine Daten' })[state] || state || '–';
}

export function roleLabel(role) {
  return ({ administrator: 'Administrator', operator: 'Mitarbeitende', auditor: 'Prüfer' })[role] || role;
}

export function eventLabel(type) {
  return ({ temperature_below_min: 'Temperatur unter Minimum', temperature_above_max: 'Temperatur über Maximum', device_offline: 'Gerät offline', battery_low: 'Batterie niedrig', signal_weak: 'Funksignal schwach', measurement_rejected: 'Messung abgelehnt', sequence_gap: 'Sequenzlücke', firmware_diagnostic: 'Firmware-Diagnose' })[type] || type;
}

export function statusLabel(status) {
  return ({ open: 'Offen', acknowledged: 'Bestätigt', action_recorded: 'Maßnahme erfasst', verified: 'Geprüft', resolved: 'Geschlossen', queued: 'Eingereiht', running: 'Wird erzeugt', complete: 'Bereit', failed: 'Fehlgeschlagen', expired: 'Abgelaufen' })[status] || status;
}

export function metric(label, value, note = '') {
  return `<div class="metric"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong><small>${escapeHtml(note)}</small></div>`;
}

export function statusPill(label, state = '') {
  return `<span class="status-pill ${escapeHtml(state)}">${escapeHtml(label)}</span>`;
}

export function batteryIcon(state) {
  return `<span class="battery-icon ${escapeHtml(state || 'unknown')}" aria-label="Batterie ${escapeHtml(state || 'unbekannt')}"><i></i><i></i><i></i></span>`;
}

export function signalIcon(bars) {
  return `<span class="signal-icon bars-${Number(bars || 0)}" aria-label="Signal ${bars == null ? 'unbekannt' : `${bars} von 4 Balken`}"><i></i><i></i><i></i><i></i></span>`;
}
