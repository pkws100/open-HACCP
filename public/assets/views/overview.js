import { api } from '../api.js?v=20260810-1';
import { lineChart, accessibleTable, chartColor, observeChartResize } from '../charts.js?v=20260812-1';
import { openDialog, closeDialog, errorMessage } from '../dialog.js?v=20260810-1';
import { alarmLabel, batteryIcon, escapeHtml, formatDate, formatNumber, metric, signalIcon, statusPill } from '../format.js?v=20260810-1';

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
    window.addEventListener('haccp:themechange', () => state.data && renderChart());
    observeChartResize([document.querySelector('#overview-chart')], () => state.data && renderChart());
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
    { name: 'Temperatur', values: values.map((row) => ({ at: row.measured_at, value: row.temperature_c })), color: chartColor('accent') },
    { name: 'Feuchte', values: values.map((row) => ({ at: row.measured_at, value: row.humidity_rh })), color: chartColor('humidity'), width: 1.4 },
  ]);
  accessibleTable(document.querySelector('#overview-chart-table'), 'Temperatur- und Feuchteverlauf', ['Zeitpunkt', 'Temperatur °C', 'Feuchte %'], values.map((row) => [formatDate(row.measured_at), row.temperature_c, row.humidity_rh]));
}

function renderFocus() {
  const data = state.data; const device = data.selected_device;
  if (!device) { document.querySelector('#device-focus').innerHTML = '<p>Kein aktives Gerät.</p>'; return; }
  const settings = data.settings; const kpi = data.kpis || {}; const point = data.selected_measurement_point; const photo = point?.photo;
  const photoMarkup = photo
    ? `<button class="focus-photo" id="focus-photo" type="button" aria-label="Foto von ${escapeHtml(point.name)} groß anzeigen"><img src="${escapeHtml(photo.thumbnail_url)}" alt="${escapeHtml(photoAlt(point))}"><span>Bild öffnen · Revision ${photo.revision}</span></button>`
    : `<div class="focus-photo-empty"><span aria-hidden="true">＋</span><strong>Noch kein Foto</strong><small>${escapeHtml(point?.name || 'Messstelle')}</small></div>`;
  const photoActions = point && context.user.role !== 'auditor' ? `<label class="secondary-button file-button">Foto aufnehmen<input type="file" id="photo-camera" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" capture="environment"></label><label class="secondary-button file-button">Bild auswählen<input type="file" id="photo-library" accept="image/jpeg,image/png,image/webp,image/heic,image/heif"></label>` : '';
  const delivery = device.configuration_delivery || {};
  const deliveryLabel = delivery.up_to_date ? `Übernommen · v${delivery.applied_version}` : delivery.applied_version ? `Ausstehend · v${delivery.applied_version}/${delivery.current_version}` : 'Noch nicht bestätigt';
  document.querySelector('#device-focus').innerHTML = `${photoMarkup}<div><p class="eyebrow">Ausgewählte Messstelle</p><h2>${escapeHtml(device.name)}</h2><p>${escapeHtml(point?.name || device.device_uid)}${point?.location ? ` · ${escapeHtml(point.location)}` : ''}</p><div class="focus-reading"><strong>${formatNumber(kpi.latest_temperature_c, ' °C')}</strong><span>${escapeHtml(alarmLabel(kpi.alarm_status))} · Bereich ${formatNumber(settings?.alarm?.temperature_min_c)} bis ${formatNumber(settings?.alarm?.temperature_max_c)} °C</span></div></div><div class="focus-status"><div><span>Batterie</span><strong>${batteryIcon(device.battery.state)} ${formatNumber(device.battery.millivolts, ' mV')}</strong></div><div><span>Funksignal</span><strong>${signalIcon(device.wifi.bars)} ${formatNumber(device.wifi.rssi_dbm, ' dBm')}</strong></div><div><span>Firmware</span><strong>${escapeHtml(device.firmware_version || '–')}</strong></div><div><span>Konfiguration</span><strong>${escapeHtml(deliveryLabel)}</strong></div><div><span>Letzte Verbindung</span><strong>${formatDate(device.last_seen_at)}</strong></div></div><div class="focus-actions">${photoActions}${photo ? '<button class="secondary-button" id="photo-history" type="button">Bildverlauf</button>' : ''}<button class="secondary-button" id="device-diagnostics" type="button">Geräteinformationen</button>${context.user.role !== 'auditor' ? '<button class="secondary-button" id="device-settings" type="button">Grenzwerte & Takt</button><button class="secondary-button" id="battery-replaced" type="button">Batterie gewechselt</button>' : ''}</div>`;
  document.querySelector('#focus-photo')?.addEventListener('click', photoHistoryDialog);
  document.querySelector('#photo-history')?.addEventListener('click', photoHistoryDialog);
  document.querySelectorAll('#photo-camera, #photo-library').forEach((input) => input.addEventListener('change', (event) => uploadPhoto(event.target.files?.[0])));
  document.querySelector('#device-settings')?.addEventListener('click', settingsDialog);
  document.querySelector('#device-diagnostics')?.addEventListener('click', diagnosticsDialog);
  document.querySelector('#battery-replaced')?.addEventListener('click', batteryDialog);
}

function renderDevices() {
  document.querySelector('#device-table').innerHTML = state.data.devices.map((device) => `<tr data-uid="${escapeHtml(device.device_uid)}"><td data-label="Gerät"><div class="device-cell">${device.photo ? `<img src="${escapeHtml(device.photo.thumbnail_url)}" alt="">` : '<span class="device-thumb-empty" aria-hidden="true"></span>'}<span><strong>${escapeHtml(device.name)}</strong><small>${escapeHtml(device.device_uid)}</small></span></div></td><td data-label="Temperatur">${formatNumber(device.latest_temperature_c, ' °C')}</td><td data-label="Alarm">${statusPill(alarmLabel(device.alarm.state), ['below_min','above_max'].includes(device.alarm.state) ? 'critical' : device.alarm.state)}</td><td data-label="Batterie">${batteryIcon(device.battery.state)} ${formatNumber(device.battery.millivolts, ' mV')}</td><td data-label="Signal">${signalIcon(device.wifi.bars)} ${formatNumber(device.wifi.rssi_dbm, ' dBm')}</td><td data-label="Verbindung">${formatDate(device.last_seen_at)}</td></tr>`).join('') || '<tr class="empty-row"><td colspan="6">Keine aktiven Geräte.</td></tr>';
  document.querySelectorAll('#device-table tr[data-uid]').forEach((row) => row.addEventListener('click', () => { state.device = row.dataset.uid; state.point = ''; load(); }));
}

function renderRecent() {
  document.querySelector('#recent-table').innerHTML = (state.data.recent_measurements || []).map((row) => `<tr><td data-label="Zeitpunkt">${formatDate(row.measured_at)}</td><td data-label="Sequenz">${row.sequence}</td><td data-label="Temperatur"><strong>${formatNumber(row.temperature_c, ' °C')}</strong></td><td data-label="Feuchte">${formatNumber(row.humidity_rh, ' %')}</td><td data-label="Batterie">${formatNumber(row.battery_mv, ' mV')}</td></tr>`).join('') || '<tr class="empty-row"><td colspan="5">Noch keine Messwerte vorhanden.</td></tr>';
}

async function uploadPhoto(file) {
  if (!file) return;
  if (file.size > 12 * 1024 * 1024) { context.showMessage('Das Foto darf höchstens 12 MiB groß sein.'); return; }
  const form = new FormData(); form.append('photo', file);
  try {
    await api(`/api/v1/dashboard/measurement-points/${state.data.selected_measurement_point.id}/photos`, { method: 'POST', body: form });
    context.showMessage('Das Messstellenfoto wurde sicher verarbeitet und versioniert.', true);
    await load();
  } catch (error) { context.showMessage(error.message); }
}

async function photoHistoryDialog() {
  const point = state.data.selected_measurement_point;
  const payload = await api(`/api/v1/dashboard/measurement-points/${point.id}/photos`);
  showPhotoHistory(payload, payload.photos.find((photo) => photo.is_current)?.photo_id || payload.photos[0]?.photo_id);
}

function showPhotoHistory(payload, selectedId) {
  const selected = payload.photos.find((photo) => photo.photo_id === selectedId) || payload.photos[0];
  if (!selected) { closeDialog(); return; }
  const canDelete = context.user.role === 'administrator';
  openDialog({ heading: payload.measurement_point.name, kicker: `Bildverlauf · ${payload.photos.length} Revision${payload.photos.length === 1 ? '' : 'en'}`, html: `<div class="photo-viewer"><img class="photo-viewer-main" src="${escapeHtml(selected.full_url)}" alt="${escapeHtml(photoAlt(payload.measurement_point))}"><div class="photo-viewer-meta"><span>Revision ${selected.revision}</span><span>${formatDate(selected.created_at)}${selected.created_by ? ` · ${escapeHtml(selected.created_by)}` : ''}</span></div><div class="photo-history-strip" aria-label="Bildrevisionen">${payload.photos.map((photo) => `<button type="button" data-photo-id="${escapeHtml(photo.photo_id)}" class="${photo.photo_id === selected.photo_id ? 'is-active' : ''}" aria-label="Revision ${photo.revision} anzeigen"><img src="${escapeHtml(photo.thumbnail_url)}" alt=""><span>R${photo.revision}</span></button>`).join('')}</div>${canDelete ? `<div class="photo-delete-zone"><button type="button" class="danger-button" data-delete-photo>Diese Revision löschen</button><div data-delete-form></div></div>` : ''}</div>`, onOpen(root) {
    root.querySelectorAll('[data-photo-id]').forEach((button) => button.addEventListener('click', () => showPhotoHistory(payload, button.dataset.photoId)));
    root.querySelector('[data-delete-photo]')?.addEventListener('click', () => {
      root.querySelector('[data-delete-form]').innerHTML = `<form id="photo-delete-form"><p class="form-note">Das Bild wird endgültig entfernt. Der Audit-Nachweis ohne Bildinhalt bleibt bestehen.</p><label>Aktuelles Passwort<input name="password" type="password" autocomplete="current-password" required></label><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-delete-cancel>Abbrechen</button><button class="danger-button" type="submit">Endgültig löschen</button></div></form>`;
      const form = root.querySelector('#photo-delete-form');
      form.querySelector('[data-delete-cancel]').addEventListener('click', () => { root.querySelector('[data-delete-form]').innerHTML = ''; });
      form.addEventListener('submit', async (event) => { event.preventDefault(); const password = new FormData(form).get('password'); try { await api(`/api/v1/dashboard/photos/${encodeURIComponent(selected.photo_id)}`, { method: 'DELETE', body: { current_password: password } }); closeDialog(); context.showMessage('Die Bildrevision wurde endgültig gelöscht.', true); await load(); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } });
    });
  } });
}

function photoAlt(point) {
  return `Messstelle ${point.name}${point.location ? `, ${point.location}` : ''}`;
}

function settingsDialog() {
  const settings = state.data.settings;
  const transmissionsPerDay = 86400 / settings.schedule.upload_interval_seconds;
  const frequencies = [...new Set([1, 3, 5, 6, 8, 12, 24, transmissionsPerDay])].sort((a, b) => a - b);
  const pointFields = settings.schedule.measurement_points.map((point) => `<label>${escapeHtml(point.measurement_point)} · Minuten<input name="point:${escapeHtml(point.measurement_point)}" type="number" step="0.5" min="0.5" max="1440" value="${point.interval_seconds / 60}" required></label>`).join('');
  openDialog({ heading: state.data.selected_device.name, kicker: `Geräteeinstellungen · Version ${settings.config_version}`, html: `<form id="settings-form"><fieldset><legend>Grenzwerte</legend><div class="form-grid"><label>Temperaturalarm<select name="enabled"><option value="true" ${settings.alarm.enabled ? 'selected' : ''}>Aktiv</option><option value="false" ${!settings.alarm.enabled ? 'selected' : ''}>Deaktiviert</option></select></label><span></span><label>Minimum °C<input name="min" type="number" step="0.1" min="-100" max="150" value="${settings.alarm.temperature_min_c ?? ''}"></label><label>Maximum °C<input name="max" type="number" step="0.1" min="-100" max="150" value="${settings.alarm.temperature_max_c ?? ''}"></label><label>Batterie niedrig mV<input name="low" type="number" min="0" max="10000" value="${settings.battery.low_threshold_mv}" required></label><label>Batterie voll mV<input name="full" type="number" min="0" max="10000" value="${settings.battery.full_threshold_mv}" required></label></div></fieldset><fieldset><legend>Messung und Übertragung</legend><div class="form-grid"><label>Standard-Messintervall · Minuten<input name="default-interval" type="number" step="0.5" min="0.5" max="1440" value="${settings.schedule.default_measurement_interval_seconds / 60}" required></label><label>Übertragungen pro Tag<select name="transmissions-per-day">${frequencies.map((frequency) => `<option value="${frequency}" ${frequency === transmissionsPerDay ? 'selected' : ''}>${formatNumber(frequency)} × täglich</option>`).join('')}</select></label>${pointFields}</div><p class="form-note">Die Firmware bestätigt die übernommene Version beim nächsten HTTPS-Kontakt. Messwerte bleiben während WLAN-Ausfällen lokal gepuffert.</p></fieldset><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Versioniert speichern</button></div></form>`, onOpen(root) {
    root.querySelector('[data-cancel]').addEventListener('click', closeDialog);
    root.querySelector('form').addEventListener('submit', async (event) => { event.preventDefault(); const form = event.currentTarget; const values = new FormData(form); const min = values.get('min') === '' ? null : Number(values.get('min')); const max = values.get('max') === '' ? null : Number(values.get('max')); const measurementPoints = settings.schedule.measurement_points.map((point) => ({ measurement_point: point.measurement_point, interval_seconds: Math.round(Number(values.get(`point:${point.measurement_point}`)) * 60) })); try { await api(`/api/v1/dashboard/devices/${encodeURIComponent(state.device)}/settings`, { method: 'PUT', body: { expected_config_version: settings.config_version, alarm: { enabled: values.get('enabled') === 'true', temperature_min_c: min, temperature_max_c: max }, battery: { low_threshold_mv: Number(values.get('low')), full_threshold_mv: Number(values.get('full')) }, schedule: { default_measurement_interval_seconds: Math.round(Number(values.get('default-interval')) * 60), upload_interval_seconds: Math.round(86400 / Number(values.get('transmissions-per-day'))), measurement_points: measurementPoints } } }); closeDialog(); context.showMessage('Gerätekonfiguration gespeichert; die Sensorbestätigung steht bis zum nächsten Kontakt aus.', true); await load(); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } });
  } });
}

function diagnosticsDialog() {
  const device = state.data.selected_device;
  const info = device.device_info || state.data.diagnostics?.device_info || {};
  const operation = state.data.diagnostics?.operational_status || {};
  const errors = state.data.diagnostics?.diagnostic_errors || [];
  openDialog({ heading: device.name, kicker: 'Firmware- und Betriebszustand', html: `<dl class="detail-list"><div><dt>Board</dt><dd>${escapeHtml(info.board_model || 'Noch nicht gemeldet')}</dd></div><div><dt>Chip</dt><dd>${escapeHtml(info.chip_model || '–')}${info.chip_revision !== undefined ? ` · Revision ${info.chip_revision}` : ''}</dd></div><div><dt>Sensor</dt><dd>${escapeHtml(info.sensor_model || '–')} · ${escapeHtml(info.sensor_status || 'unbekannt')}</dd></div><div><dt>Flash / PSRAM</dt><dd>${formatNumber(info.flash_bytes !== undefined ? info.flash_bytes / 1048576 : null, ' MiB')} / ${formatNumber(info.psram_bytes !== undefined ? info.psram_bytes / 1048576 : null, ' MiB')}</dd></div><div><dt>Offline-Puffer</dt><dd>${formatNumber(operation.queue_depth)} / ${formatNumber(info.queue_capacity)} Messwerte</dd></div><div><dt>Aufwachgrund</dt><dd>${escapeHtml(operation.wake_reason || '–')} · Reset ${escapeHtml(operation.reset_reason || '–')}</dd></div><div><dt>WLAN-Fehler gemeldet</dt><dd>${formatNumber(operation.wifi_failures_since_report)} · maximal ${formatNumber(operation.max_consecutive_wifi_failures)} in Folge</dd></div><div><dt>HTTPS-Fehler gemeldet</dt><dd>${formatNumber(operation.upload_failures_since_report)}</dd></div><div><dt>Sleep-Fallbacks</dt><dd>${formatNumber(operation.sleep_fallbacks_since_report)}</dd></div><div><dt>Diagnosecodes</dt><dd>${errors.length ? errors.map(escapeHtml).join(', ') : 'Keine'}</dd></div></dl>` });
}

function batteryDialog() {
  openDialog({ heading: 'Batteriewechsel dokumentieren', kicker: state.data.selected_device.name, html: `<form id="battery-form"><div class="form-grid"><label>Chemie / Profil<input name="chemistry" value="Alkaline" maxlength="64" required></label><label>Zellen in Reihe<input name="series_count" type="number" min="1" max="16" value="4" required></label><label>Nennkapazität mAh<input name="capacity" type="number" min="1" max="100000" placeholder="optional"></label></div><p class="form-note">Der Wechsel beginnt einen neuen Prognosezyklus. Eine Restlaufzeit wird erst bei ausreichendem Verlauf angezeigt.</p><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Wechsel erfassen</button></div></form>`, onOpen(root) { root.querySelector('[data-cancel]').addEventListener('click', closeDialog); root.querySelector('form').addEventListener('submit', async (event) => { event.preventDefault(); const values = new FormData(event.currentTarget); await api(`/api/v1/dashboard/devices/${encodeURIComponent(state.device)}/battery-replaced`, { method: 'POST', body: { chemistry: values.get('chemistry'), series_count: Number(values.get('series_count')), nominal_capacity_mah: values.get('capacity') ? Number(values.get('capacity')) : null, forecast_enabled: true } }); closeDialog(); context.showMessage('Batteriewechsel wurde als neuer Zyklus dokumentiert.', true); }); } });
}

function enrollmentDialog() {
  openDialog({ heading: 'Neues Gerät anlernen', kicker: 'Einmalige Einrichtung', html: `<form id="enrollment-form"><div class="form-grid"><label>Gerätename<input name="name" required maxlength="160"></label><label>Geräte-UID<input name="device_uid" pattern="[a-z0-9][a-z0-9-]{2,63}" placeholder="wird optional erzeugt"></label><label>Messstellenkennung<input name="point_code" value="temperature-1" required></label><label>Messstellenname<input name="point_name" value="Temperatursensor" required></label><label>Sensortyp<input name="sensor_type" value="SHT45" required></label><label>Ort<input name="location"></label><label>Temperatur min. °C<input name="min" type="number" step="0.1" value="2"></label><label>Temperatur max. °C<input name="max" type="number" step="0.1" value="7"></label></div><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Gerät vorbereiten</button></div></form>`, onOpen(root) { root.querySelector('[data-cancel]').addEventListener('click', closeDialog); root.querySelector('form').addEventListener('submit', async (event) => { event.preventDefault(); const form = event.currentTarget; const data = new FormData(form); try { const result = await api('/api/v1/dashboard/devices', { method: 'POST', body: { ...(data.get('device_uid') ? { device_uid: data.get('device_uid') } : {}), name: data.get('name'), measurement_point: { code: data.get('point_code'), name: data.get('point_name'), sensor_type: data.get('sensor_type'), location: data.get('location') || null }, alarm: { enabled: true, temperature_min_c: Number(data.get('min')), temperature_max_c: Number(data.get('max')) }, battery: { low_threshold_mv: 5600, full_threshold_mv: 6000 } } }); root.innerHTML = `<p class="form-note">Diese Zugangsdaten werden nur einmal angezeigt. Sicher in das Provisionierungsportal des Sensors übertragen.</p><dl class="detail-list"><div><dt>Server</dt><dd class="mono">${escapeHtml(result.setup_package.api_base_url)}</dd></div><div><dt>Geräte-UID</dt><dd class="mono">${escapeHtml(result.setup_package.device_uid)}</dd></div><div><dt>Messstelle</dt><dd class="mono">${escapeHtml(result.setup_package.measurement_point)}</dd></div><div><dt>Geräteschlüssel</dt><dd class="mono">${escapeHtml(result.setup_package.device_key)}</dd></div></dl><div class="dialog-actions"><button class="primary-button" type="button" data-done>Ich habe die Daten gesichert</button></div>`; root.querySelector('[data-done]').addEventListener('click', async () => { closeDialog(); await load(); }); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } }); } });
}
