import { api } from '../api.js?v=20260809-2';
import { lineChart, accessibleTable } from '../charts.js?v=20260809-2';
import { openDialog, closeDialog, errorMessage } from '../dialog.js?v=20260809-2';
import { alarmLabel, batteryIcon, escapeHtml, formatDate, formatNumber, metric, signalIcon, statusPill } from '../format.js?v=20260809-2';

const state = { device: '', point: '', hours: 24, data: null, initialized: false };
let context;

export const overviewView = {
  init(app) {
    context = app;
    if (state.initialized) return; state.initialized = true;
    document.querySelector('#overview-refresh').addEventListener('click', load);
    document.querySelector('#overview-device').addEventListener('change', (event) => { state.device = event.target.value; state.point = ''; load(); });
    document.querySelector('#overview-point').addEventListener('change', (event) => { state.point = event.target.value; load(); });
    document.querySelectorAll('[data-hours]').forEach((button) => button.addEventListener('click', () => { state.hours = Number(button.dataset.hours); document.querySelectorAll('[data-hours]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button)); load(); }));
    document.querySelector('#add-device').addEventListener('click', enrollmentDialog);
    window.addEventListener('resize', () => state.data && renderChart());
  },
  load,
};

async function load() {
  const params = new URLSearchParams({ hours: String(state.hours) });
  if (state.device) params.set('device', state.device);
  if (state.point) params.set('point', state.point);
  const data = await api(`/api/v1/dashboard/overview?${params}`);
  state.data = data; state.device = data.selection?.device_uid || ''; state.point = data.selection?.measurement_point || '';
  context.devices = data.devices;
  render();
}

function render() {
  const data = state.data;
  const deviceSelect = document.querySelector('#overview-device');
  deviceSelect.innerHTML = data.devices.map((device) => `<option value="${escapeHtml(device.device_uid)}" ${device.device_uid === state.device ? 'selected' : ''}>${escapeHtml(device.name)}</option>`).join('');
  const pointSelect = document.querySelector('#overview-point');
  pointSelect.innerHTML = data.measurement_points.map((point) => `<option value="${escapeHtml(point.code)}" ${point.code === state.point ? 'selected' : ''}>${escapeHtml(point.name)}</option>`).join('');
  const kpi = data.kpis || {};
  document.querySelector('#overview-metrics').innerHTML = [
    metric('Aktuelle Temperatur', formatNumber(kpi.latest_temperature_c, ' °C'), alarmLabel(kpi.alarm_status)),
    metric('Durchschnitt', formatNumber(kpi.average_temperature_c, ' °C'), `${formatNumber(kpi.minimum_temperature_c)} bis ${formatNumber(kpi.maximum_temperature_c)} °C`),
    metric('Luftfeuchte', formatNumber(kpi.latest_humidity_rh, ' %'), `Ø ${formatNumber(kpi.average_humidity_rh, ' %')}`),
    metric('Messwerte', formatNumber(kpi.measurement_count), `im ${data.window_hours}-Stunden-Fenster`),
  ].join('');
  document.querySelector('#chart-range').textContent = `${data.window_hours} Stunden`;
  renderChart(); renderFocus(); renderDevices(); renderRecent();
}

function renderChart() {
  const values = state.data?.series || [];
  document.querySelector('#overview-chart-empty').hidden = values.length > 0;
  lineChart(document.querySelector('#overview-chart'), [
    { name: 'Temperatur', values: values.map((row) => ({ at: row.measured_at, value: row.temperature_c })), color: '#61d6c3' },
    { name: 'Feuchte', values: values.map((row) => ({ at: row.measured_at, value: row.humidity_rh })), color: '#6e91bc', width: 1.4 },
  ]);
  accessibleTable(document.querySelector('#overview-chart-table'), 'Temperatur- und Feuchteverlauf', ['Zeitpunkt', 'Temperatur °C', 'Feuchte %'], values.map((row) => [formatDate(row.measured_at), row.temperature_c, row.humidity_rh]));
}

function renderFocus() {
  const data = state.data; const device = data.selected_device;
  if (!device) { document.querySelector('#device-focus').innerHTML = '<p>Kein aktives Gerät.</p>'; return; }
  const settings = data.settings; const kpi = data.kpis || {};
  document.querySelector('#device-focus').innerHTML = `<div><p class="eyebrow">Ausgewähltes Gerät</p><h2>${escapeHtml(device.name)}</h2><p>${escapeHtml(data.selected_measurement_point?.location || device.device_uid)}</p><div class="focus-reading"><strong>${formatNumber(kpi.latest_temperature_c, ' °C')}</strong><span>${escapeHtml(alarmLabel(kpi.alarm_status))} · Bereich ${formatNumber(settings?.alarm?.temperature_min_c)} bis ${formatNumber(settings?.alarm?.temperature_max_c)} °C</span></div></div><div class="focus-status"><div><span>Batterie</span><strong>${batteryIcon(device.battery.state)} ${formatNumber(device.battery.millivolts, ' mV')}</strong></div><div><span>Funksignal</span><strong>${signalIcon(device.wifi.bars)} ${formatNumber(device.wifi.rssi_dbm, ' dBm')}</strong></div><div><span>Firmware</span><strong>${escapeHtml(device.firmware_version || '–')}</strong></div><div><span>Letzte Verbindung</span><strong>${formatDate(device.last_seen_at)}</strong></div></div><div class="focus-actions">${context.user.role !== 'auditor' ? '<button class="secondary-button" id="device-settings" type="button">Grenzwerte</button><button class="secondary-button" id="battery-replaced" type="button">Batterie gewechselt</button>' : ''}</div>`;
  document.querySelector('#device-settings')?.addEventListener('click', settingsDialog);
  document.querySelector('#battery-replaced')?.addEventListener('click', batteryDialog);
}

function renderDevices() {
  document.querySelector('#device-table').innerHTML = state.data.devices.map((device) => `<tr data-uid="${escapeHtml(device.device_uid)}"><td><strong>${escapeHtml(device.name)}</strong><small>${escapeHtml(device.device_uid)}</small></td><td>${formatNumber(device.latest_temperature_c, ' °C')}</td><td>${statusPill(alarmLabel(device.alarm.state), ['below_min','above_max'].includes(device.alarm.state) ? 'critical' : device.alarm.state)}</td><td>${batteryIcon(device.battery.state)} ${formatNumber(device.battery.millivolts, ' mV')}</td><td>${signalIcon(device.wifi.bars)} ${formatNumber(device.wifi.rssi_dbm, ' dBm')}</td><td>${formatDate(device.last_seen_at)}</td></tr>`).join('') || '<tr class="empty-row"><td colspan="6">Keine aktiven Geräte.</td></tr>';
  document.querySelectorAll('#device-table tr[data-uid]').forEach((row) => row.addEventListener('click', () => { state.device = row.dataset.uid; state.point = ''; load(); }));
}

function renderRecent() {
  document.querySelector('#recent-table').innerHTML = (state.data.recent_measurements || []).map((row) => `<tr><td>${formatDate(row.measured_at)}</td><td>${row.sequence}</td><td><strong>${formatNumber(row.temperature_c, ' °C')}</strong></td><td>${formatNumber(row.humidity_rh, ' %')}</td><td>${formatNumber(row.battery_mv, ' mV')}</td></tr>`).join('') || '<tr class="empty-row"><td colspan="5">Noch keine Messwerte vorhanden.</td></tr>';
}

function settingsDialog() {
  const settings = state.data.settings;
  openDialog({ heading: state.data.selected_device.name, kicker: `Geräteeinstellungen · Version ${settings.config_version}`, html: `<form id="settings-form"><div class="form-grid"><label>Temperaturalarm<select name="enabled"><option value="true" ${settings.alarm.enabled ? 'selected' : ''}>Aktiv</option><option value="false" ${!settings.alarm.enabled ? 'selected' : ''}>Deaktiviert</option></select></label><span></span><label>Minimum °C<input name="min" type="number" step="0.1" min="-100" max="150" value="${settings.alarm.temperature_min_c ?? ''}"></label><label>Maximum °C<input name="max" type="number" step="0.1" min="-100" max="150" value="${settings.alarm.temperature_max_c ?? ''}"></label><label>Batterie niedrig mV<input name="low" type="number" min="0" max="10000" value="${settings.battery.low_threshold_mv}" required></label><label>Batterie voll mV<input name="full" type="number" min="0" max="10000" value="${settings.battery.full_threshold_mv}" required></label></div><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Speichern</button></div></form>`, onOpen(root) {
    root.querySelector('[data-cancel]').addEventListener('click', closeDialog);
    root.querySelector('form').addEventListener('submit', async (event) => { event.preventDefault(); const form = event.currentTarget; const values = new FormData(form); const min = values.get('min') === '' ? null : Number(values.get('min')); const max = values.get('max') === '' ? null : Number(values.get('max')); try { await api(`/api/v1/dashboard/devices/${encodeURIComponent(state.device)}/settings`, { method: 'PUT', body: { expected_config_version: settings.config_version, alarm: { enabled: values.get('enabled') === 'true', temperature_min_c: min, temperature_max_c: max }, battery: { low_threshold_mv: Number(values.get('low')), full_threshold_mv: Number(values.get('full')) } } }); closeDialog(); context.showMessage('Geräteeinstellungen wurden versioniert gespeichert.', true); await load(); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } });
  } });
}

function batteryDialog() {
  openDialog({ heading: 'Batteriewechsel dokumentieren', kicker: state.data.selected_device.name, html: `<form id="battery-form"><div class="form-grid"><label>Chemie / Profil<input name="chemistry" value="Alkaline" maxlength="64" required></label><label>Zellen in Reihe<input name="series_count" type="number" min="1" max="16" value="4" required></label><label>Nennkapazität mAh<input name="capacity" type="number" min="1" max="100000" placeholder="optional"></label></div><p class="form-note">Der Wechsel beginnt einen neuen Prognosezyklus. Eine Restlaufzeit wird erst bei ausreichendem Verlauf angezeigt.</p><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Wechsel erfassen</button></div></form>`, onOpen(root) { root.querySelector('[data-cancel]').addEventListener('click', closeDialog); root.querySelector('form').addEventListener('submit', async (event) => { event.preventDefault(); const values = new FormData(event.currentTarget); await api(`/api/v1/dashboard/devices/${encodeURIComponent(state.device)}/battery-replaced`, { method: 'POST', body: { chemistry: values.get('chemistry'), series_count: Number(values.get('series_count')), nominal_capacity_mah: values.get('capacity') ? Number(values.get('capacity')) : null, forecast_enabled: true } }); closeDialog(); context.showMessage('Batteriewechsel wurde als neuer Zyklus dokumentiert.', true); }); } });
}

function enrollmentDialog() {
  openDialog({ heading: 'Neues Gerät anlernen', kicker: 'Einmalige Einrichtung', html: `<form id="enrollment-form"><div class="form-grid"><label>Gerätename<input name="name" required maxlength="160"></label><label>Geräte-UID<input name="device_uid" pattern="[a-z0-9][a-z0-9-]{2,63}" placeholder="wird optional erzeugt"></label><label>Messstellenkennung<input name="point_code" value="temperature-1" required></label><label>Messstellenname<input name="point_name" value="Temperatursensor" required></label><label>Sensortyp<input name="sensor_type" value="SHT45" required></label><label>Ort<input name="location"></label><label>Temperatur min. °C<input name="min" type="number" step="0.1" value="2"></label><label>Temperatur max. °C<input name="max" type="number" step="0.1" value="7"></label></div><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Gerät vorbereiten</button></div></form>`, onOpen(root) { root.querySelector('[data-cancel]').addEventListener('click', closeDialog); root.querySelector('form').addEventListener('submit', async (event) => { event.preventDefault(); const form = event.currentTarget; const data = new FormData(form); try { const result = await api('/api/v1/dashboard/devices', { method: 'POST', body: { ...(data.get('device_uid') ? { device_uid: data.get('device_uid') } : {}), name: data.get('name'), measurement_point: { code: data.get('point_code'), name: data.get('point_name'), sensor_type: data.get('sensor_type'), location: data.get('location') || null }, alarm: { enabled: true, temperature_min_c: Number(data.get('min')), temperature_max_c: Number(data.get('max')) }, battery: { low_threshold_mv: 5600, full_threshold_mv: 6000 } } }); root.innerHTML = `<p class="form-note">Diese Zugangsdaten werden nur einmal angezeigt. Sicher in das Provisionierungsportal des Sensors übertragen.</p><dl class="detail-list"><div><dt>Server</dt><dd class="mono">${escapeHtml(result.setup_package.api_base_url)}</dd></div><div><dt>Geräte-UID</dt><dd class="mono">${escapeHtml(result.setup_package.device_uid)}</dd></div><div><dt>Messstelle</dt><dd class="mono">${escapeHtml(result.setup_package.measurement_point)}</dd></div><div><dt>Geräteschlüssel</dt><dd class="mono">${escapeHtml(result.setup_package.device_key)}</dd></div></dl><div class="dialog-actions"><button class="primary-button" type="button" data-done>Ich habe die Daten gesichert</button></div>`; root.querySelector('[data-done]').addEventListener('click', async () => { closeDialog(); await load(); }); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } }); } });
}
