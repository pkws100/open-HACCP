import { api } from '../api.js?v=20260810-1';
import { sensorTrendChart, accessibleTable, observeChartResize } from '../charts.js?v=20260924-1';
import { openDialog, closeDialog, errorMessage } from '../dialog.js?v=20260810-1';
import { alarmLabel, escapeHtml, formatDate, formatNumber, metric, powerLabel, signalIcon, statusPill } from '../format.js?v=20260923-2';

const state = { device: '', point: '', hours: 24, recentPage: 1, data: null, initialized: false };
const trend = { showTemperature: true, showHumidity: true, selectedIndex: -1, pinned: false, geometry: null };
const photoAccept = 'image/jpeg,image/png,image/webp,image/heic,image/heif';
const preciseTemperature = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 3 });
const signedOffset = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 3, signDisplay: 'exceptZero' });
let context;
let photoUploadInProgress = false;
let loadSequence = 0;
let recentRequestSequence = 0;
let recentPagePending = false;
let overviewPending = false;

export const overviewView = {
  init(app) {
    context = app;
    if (state.initialized) return; state.initialized = true;
    document.querySelector('#overview-refresh').addEventListener('click', () => { state.recentPage = 1; load(); });
    document.querySelector('#overview-device').addEventListener('change', (event) => { state.device = event.target.value; state.point = ''; state.recentPage = 1; load(); });
    document.querySelector('#overview-point').addEventListener('change', (event) => { state.point = event.target.value; state.recentPage = 1; load(); });
    document.querySelectorAll('[data-hours]').forEach((button) => button.addEventListener('click', () => { state.hours = Number(button.dataset.hours); state.recentPage = 1; document.querySelectorAll('[data-hours]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button)); load(); }));
    document.querySelector('#recent-prev').addEventListener('click', () => changeRecentPage(-1));
    document.querySelector('#recent-next').addEventListener('click', () => changeRecentPage(1));
    document.querySelector('#add-device').addEventListener('click', enrollmentDialog);
    window.addEventListener('resize', () => state.data && renderChart());
    window.addEventListener('haccp:themechange', () => state.data && renderChart());
    observeChartResize([document.querySelector('#overview-chart')], () => state.data && renderChart());
    document.querySelectorAll('[data-chart-series]').forEach((button) => button.addEventListener('click', () => {
      const key = button.dataset.chartSeries === 'temperature' ? 'showTemperature' : 'showHumidity';
      if (trend[key] && !trend[key === 'showTemperature' ? 'showHumidity' : 'showTemperature']) return;
      trend[key] = !trend[key];
      updateTrendButtons();
      renderChart();
    }));
    const chart = document.querySelector('#overview-chart');
    chart.addEventListener('pointermove', (event) => {
      if ((trend.pinned && !event.buttons) || (event.pointerType === 'touch' && !event.buttons)) return;
      const index = chartIndexAtPointer(event);
      if (index !== null && index !== trend.selectedIndex) { trend.selectedIndex = index; renderChart(); }
    });
    chart.addEventListener('pointerdown', (event) => {
      const index = chartIndexAtPointer(event);
      if (index === null) return;
      trend.selectedIndex = index; trend.pinned = true; renderChart();
    });
    chart.addEventListener('pointerleave', () => {
      if (trend.pinned || !state.data?.series?.length) return;
      trend.selectedIndex = state.data.series.length - 1; renderChart();
    });
    chart.addEventListener('keydown', (event) => {
      const count = state.data?.series?.length || 0;
      if (!count || !['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Escape'].includes(event.key)) return;
      event.preventDefault();
      if (event.key === 'Escape') { resetChartSelection(); return; }
      trend.selectedIndex = event.key === 'Home' ? 0 : event.key === 'End' ? count - 1
        : Math.max(0, Math.min(count - 1, trend.selectedIndex + (event.key === 'ArrowLeft' ? -1 : 1)));
      trend.pinned = true; renderChart();
    });
    document.querySelector('#chart-latest').addEventListener('click', resetChartSelection);
    updateTrendButtons();
  },
  load,
};

async function load({ revealDevice = false } = {}) {
  const sequence = ++loadSequence;
  ++recentRequestSequence;
  recentPagePending = false;
  overviewPending = true;
  renderRecentPagination();
  const params = new URLSearchParams({ hours: String(state.hours), recent_page: String(state.recentPage) });
  if (state.device) params.set('device', state.device);
  if (state.point) params.set('point', state.point);
  let data;
  try { data = await api(`/api/v1/dashboard/overview?${params}`); }
  catch (error) { if (sequence !== loadSequence) return; overviewPending = false; renderRecentPagination(); throw error; }
  if (sequence !== loadSequence) return;
  overviewPending = false;
  state.recentPage = data.recent_pagination?.page || 1;
  state.data = data; state.device = data.selection?.device_uid || ''; state.point = data.selection?.measurement_point || '';
  trend.selectedIndex = (data.series?.length || 0) - 1; trend.pinned = false;
  context.devices = data.devices;
  render();
  if (revealDevice) revealSelectedDevice();
}

function render() {
  const data = state.data;
  const deviceSelect = document.querySelector('#overview-device');
  deviceSelect.innerHTML = data.devices.map((device) => `<option value="${escapeHtml(device.device_uid)}" ${device.device_uid === state.device ? 'selected' : ''}>${escapeHtml(device.name)}</option>`).join('');
  const pointSelect = document.querySelector('#overview-point');
  pointSelect.innerHTML = data.measurement_points.map((point) => `<option value="${escapeHtml(point.code)}" ${point.code === state.point ? 'selected' : ''}>${escapeHtml(point.name)}</option>`).join('');
  const kpi = data.kpis || {};
  document.querySelector('#overview-metrics').innerHTML = [
    metric(Number(kpi.latest_temperature_offset_c) !== 0 && kpi.latest_temperature_offset_c != null ? 'Aktuelle Temperatur (korrigiert)' : 'Aktuelle Temperatur', temperatureLabel(kpi.latest_temperature_c), alarmLabel(kpi.alarm_status)),
    metric('Durchschnitt', formatNumber(kpi.average_temperature_c, ' °C'), `${formatNumber(kpi.minimum_temperature_c)} bis ${formatNumber(kpi.maximum_temperature_c)} °C`),
    metric('Luftfeuchte', formatNumber(kpi.latest_humidity_rh, ' %'), `Ø ${formatNumber(kpi.average_humidity_rh, ' %')}`),
    metric('Messwerte', formatNumber(kpi.measurement_count), `im ${data.window_hours}-Stunden-Fenster`),
  ].join('');
  document.querySelector('#chart-range').textContent = data.window_hours >= 48
    ? `${data.window_hours / 24} Tage` : `${data.window_hours} Stunden`;
  renderFocus(); renderChart(); renderDevices(); renderRecent();
  const values = data.series || [];
  accessibleTable(document.querySelector('#overview-chart-table'), 'Temperatur- und Feuchteverlauf', ['Zeitpunkt', 'Temperatur °C', 'Feuchte %'], values.map((row) => [formatDate(row.measured_at), row.temperature_c, row.humidity_rh]));
}

function temperatureLabel(value) {
  return value == null ? '–' : `${preciseTemperature.format(Number(value))} °C`;
}

function calibrationNote(rawTemperature, offset) {
  if (rawTemperature == null || offset == null || Number(offset) === 0) return '';
  return `Korrigiert · Rohwert ${temperatureLabel(rawTemperature)} · Abgleich ${signedOffset.format(Number(offset))} °C`;
}

function renderChart() {
  const values = state.data?.series || [];
  document.querySelector('#overview-chart-empty').hidden = values.length > 0;
  document.querySelector('#chart-interaction-hint').textContent = values.length >= 2500
    ? 'Es werden höchstens die 2.500 neuesten Messwerte im gewählten Zeitraum gezeigt. Kurve berühren oder Pfeiltasten verwenden.'
    : 'Kurve berühren oder mit den Pfeiltasten einen Messzeitpunkt auswählen.';
  const chart = document.querySelector('#overview-chart');
  const wide = window.matchMedia('(min-width: 1051px)').matches;
  const focusHeight = document.querySelector('#device-focus')?.offsetHeight || 0;
  chart.dataset.chartHeight = String(wide ? Math.max(500, Math.min(700, focusHeight - 175)) : chart.clientWidth < 470 ? 410 : 500);
  trend.geometry = sensorTrendChart(chart, values, {
    selectedIndex: trend.selectedIndex,
    showTemperature: trend.showTemperature,
    showHumidity: trend.showHumidity,
    hours: state.hours,
  });
  updateChartReadout(values);
}

function updateTrendButtons() {
  document.querySelectorAll('[data-chart-series]').forEach((button) => {
    const temperature = button.dataset.chartSeries === 'temperature';
    const visible = temperature ? trend.showTemperature : trend.showHumidity;
    const otherVisible = temperature ? trend.showHumidity : trend.showTemperature;
    button.setAttribute('aria-pressed', String(visible));
    button.disabled = visible && !otherVisible;
  });
}

function updateChartReadout(values) {
  const chart = document.querySelector('#overview-chart');
  const row = values[trend.selectedIndex];
  const time = row ? formatDate(row.measured_at) : 'Noch kein Messwert';
  const temperature = row ? temperatureLabel(row.temperature_c) : '–';
  const humidity = row?.humidity_rh == null ? '–' : `${formatNumber(row.humidity_rh)} % rF`;
  document.querySelector('#chart-readout-time').textContent = time;
  document.querySelector('#chart-readout-temperature').textContent = `Temperatur ${temperature}`;
  document.querySelector('#chart-readout-humidity').textContent = `Luftfeuchtigkeit ${humidity}`;
  document.querySelector('#chart-latest').hidden = !row || (!trend.pinned && trend.selectedIndex === values.length - 1);
  chart.tabIndex = row ? 0 : -1;
  chart.setAttribute('aria-disabled', String(!row));
  chart.setAttribute('aria-valuemin', '1');
  chart.setAttribute('aria-valuemax', String(Math.max(1, values.length)));
  chart.setAttribute('aria-valuenow', String(Math.max(1, trend.selectedIndex + 1)));
  chart.setAttribute('aria-valuetext', row ? `${time}, Temperatur ${temperature}, Luftfeuchtigkeit ${humidity}` : 'Noch kein Messwert');
}

function chartIndexAtPointer(event) {
  const geometry = trend.geometry;
  const positions = geometry?.positions || [];
  if (!positions.length) return null;
  const rect = event.currentTarget.getBoundingClientRect();
  if (!rect.width) return null;
  const x = (event.clientX - rect.left) * geometry.width / rect.width;
  if (x < geometry.left - 12 || x > geometry.right + 12) return null;
  let low = 0; let high = positions.length - 1;
  while (low < high) {
    const middle = Math.floor((low + high) / 2);
    if (positions[middle].x < x) low = middle + 1; else high = middle;
  }
  const next = positions[low]; const previous = positions[Math.max(0, low - 1)];
  return Math.abs(next.x - x) < Math.abs(previous.x - x) ? next.index : previous.index;
}

function resetChartSelection() {
  trend.selectedIndex = (state.data?.series?.length || 0) - 1;
  trend.pinned = false;
  if (state.data) renderChart();
}

function renderFocus() {
  const data = state.data; const device = data.selected_device;
  if (!device) { document.querySelector('#device-focus').innerHTML = '<p>Kein aktives Gerät.</p>'; return; }
  const settings = data.settings; const kpi = data.kpis || {}; const point = data.selected_measurement_point; const photo = point?.photo;
  const canUploadPhoto = point && context.user.role !== 'auditor';
  const photoMarkup = photo
    ? `<button class="focus-photo" id="focus-photo" type="button" aria-label="Foto von ${escapeHtml(point.name)} groß anzeigen"><img src="${escapeHtml(photo.thumbnail_url)}" alt="${escapeHtml(photoAlt(point))}"><span>Bild öffnen · Revision ${photo.revision}</span></button>`
    : canUploadPhoto
      ? `<label class="focus-photo-empty photo-upload-target"><span aria-hidden="true">＋</span><strong>Foto hinzufügen</strong><small>${escapeHtml(point.name)}</small><input type="file" data-photo-upload accept="${photoAccept}" aria-label="Foto für ${escapeHtml(point.name)} hinzufügen"></label>`
      : `<div class="focus-photo-empty"><span aria-hidden="true">＋</span><strong>Noch kein Foto</strong><small>${escapeHtml(point?.name || 'Messstelle')}</small></div>`;
  const photoActions = canUploadPhoto ? `<label class="secondary-button file-button">Foto aufnehmen<input type="file" data-photo-upload accept="${photoAccept}" capture="environment" aria-label="Foto aufnehmen"></label><label class="secondary-button file-button">Bild auswählen<input type="file" data-photo-upload accept="${photoAccept}" aria-label="Bild auswählen"></label>` : '';
  const delivery = device.configuration_delivery || {};
  const deliveryLabel = delivery.up_to_date ? `Übernommen · v${delivery.applied_version}` : delivery.applied_version ? `Ausstehend · v${delivery.applied_version}/${delivery.current_version}` : 'Noch nicht bestätigt';
  const focusCalibration = calibrationNote(kpi.latest_raw_temperature_c, kpi.latest_temperature_offset_c);
  document.querySelector('#device-focus').innerHTML = `${photoMarkup}<div><p class="eyebrow">Ausgewählte Messstelle</p><h2 id="selected-device-heading" tabindex="-1">${escapeHtml(device.name)}</h2><p>${escapeHtml(point?.name || device.device_uid)}${point?.location ? ` · ${escapeHtml(point.location)}` : ''}</p><div class="focus-reading"><strong>${temperatureLabel(kpi.latest_temperature_c)}</strong><span>${escapeHtml(alarmLabel(kpi.alarm_status))} · Bereich ${formatNumber(settings?.alarm?.temperature_min_c)} bis ${formatNumber(settings?.alarm?.temperature_max_c)} °C</span>${focusCalibration ? `<small>${escapeHtml(focusCalibration)}</small>` : ''}</div></div><div class="focus-status"><div><span>Stromversorgung</span><strong>${powerLabel(device.battery)}</strong></div><div><span>Funksignal</span><strong>${signalIcon(device.wifi.bars)} ${formatNumber(device.wifi.rssi_dbm, ' dBm')}</strong></div><div><span>Firmware</span><strong>${escapeHtml(device.firmware_version || '–')}</strong></div><div><span>Konfiguration</span><strong>${escapeHtml(deliveryLabel)}</strong></div><div><span>Letzte Verbindung</span><strong>${formatDate(device.last_seen_at)}</strong></div></div><div class="focus-actions">${photoActions}${photo ? '<button class="secondary-button" id="photo-history" type="button">Bildverlauf</button>' : ''}<button class="secondary-button" id="device-diagnostics" type="button">Geräteinformationen</button>${context.user.role !== 'auditor' ? `<button class="secondary-button" id="device-identity" type="button">Namen & Ort</button><button class="secondary-button" id="device-settings" type="button">Grenzwerte, Takt &amp; Abgleich</button>${['battery', 'battery_unmonitored'].includes(device.battery.power_source) ? '<button class="secondary-button" id="battery-replaced" type="button">Batterie gewechselt</button>' : ''}` : ''}</div>`;
  document.querySelector('#focus-photo')?.addEventListener('click', photoHistoryDialog);
  document.querySelector('#photo-history')?.addEventListener('click', photoHistoryDialog);
  document.querySelectorAll('[data-photo-upload]').forEach((input) => input.addEventListener('change', (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    uploadPhoto(file);
  }));
  document.querySelector('#device-settings')?.addEventListener('click', settingsDialog);
  document.querySelector('#device-identity')?.addEventListener('click', identityDialog);
  document.querySelector('#device-diagnostics')?.addEventListener('click', diagnosticsDialog);
  document.querySelector('#battery-replaced')?.addEventListener('click', batteryDialog);
}

function renderDevices() {
  document.querySelector('#device-table').innerHTML = state.data.devices.map((device) => {
    const selected = device.device_uid === state.device;
    return `<tr data-uid="${escapeHtml(device.device_uid)}" class="${selected ? 'is-selected' : ''}"><td data-label="Gerät"><button class="device-select-button" type="button" aria-current="${selected ? 'true' : 'false'}" aria-label="${escapeHtml(device.name)}: Details und Einstellungen anzeigen"><span class="device-cell">${device.photo ? `<img src="${escapeHtml(device.photo.thumbnail_url)}" alt="">` : '<span class="device-thumb-empty" aria-hidden="true"></span>'}<span><strong>${escapeHtml(device.name)}</strong><small>${escapeHtml(device.device_uid)}</small><span class="device-select-hint" aria-live="polite">${selected ? 'Ausgewählt · Details ansehen' : 'Details und Einstellungen ansehen'} →</span></span></span></button></td><td data-label="Temperatur">${formatNumber(device.latest_temperature_c, ' °C')}</td><td data-label="Alarm">${statusPill(alarmLabel(device.alarm.state), ['below_min','above_max'].includes(device.alarm.state) ? 'critical' : device.alarm.state)}</td><td data-label="Versorgung">${powerLabel(device.battery)}</td><td data-label="Signal">${signalIcon(device.wifi.bars)} ${formatNumber(device.wifi.rssi_dbm, ' dBm')}</td><td data-label="Verbindung">${formatDate(device.last_seen_at)}</td></tr>`;
  }).join('') || '<tr class="empty-row"><td colspan="6">Keine aktiven Geräte.</td></tr>';
  document.querySelectorAll('#device-table tr[data-uid]').forEach((row) => row.addEventListener('click', () => selectDevice(row.dataset.uid)));
}

function markDeviceSelection(uid, loading = false) {
  document.querySelectorAll('#device-table tr[data-uid]').forEach((row) => {
    const selected = row.dataset.uid === uid;
    row.classList.toggle('is-selected', selected);
    row.classList.toggle('is-loading', selected && loading);
    row.querySelector('button').setAttribute('aria-current', String(selected));
    row.querySelector('.device-select-hint').textContent = selected && loading
      ? 'Wird geladen …'
      : selected ? 'Ausgewählt · Details ansehen →' : 'Details und Einstellungen ansehen →';
  });
}

function revealSelectedDevice() {
  if (!window.matchMedia('(max-width: 760px)').matches) return;
  if (document.querySelector('[data-view="overview"]')?.hidden) return;
  const heading = document.querySelector('#selected-device-heading');
  heading?.focus({ preventScroll: true });
  heading?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
}

async function selectDevice(uid) {
  if (uid === state.data?.selection?.device_uid) {
    ++loadSequence;
    ++recentRequestSequence;
    recentPagePending = false;
    overviewPending = false;
    state.device = uid; state.point = state.data.selection.measurement_point || '';
    markDeviceSelection(uid);
    renderRecentPagination();
    revealSelectedDevice();
    return;
  }
  const previousDevice = state.data?.selection?.device_uid || '';
  const previousPoint = state.data?.selection?.measurement_point || '';
  const previousPage = state.recentPage;
  state.device = uid; state.point = ''; state.recentPage = 1;
  markDeviceSelection(uid, true);
  const sequence = loadSequence + 1;
  try { await load({ revealDevice: true }); }
  catch (error) {
    if (sequence !== loadSequence) return;
    state.device = previousDevice; state.point = previousPoint; state.recentPage = previousPage;
    markDeviceSelection(previousDevice);
    context.showMessage(error.message);
  }
}

function renderRecent() {
  const powerSource = state.data.selected_device?.battery?.power_source;
  const missingBatteryLabel = powerSource === 'mains' ? 'Netzbetrieb' : powerSource === 'battery_unmonitored' ? 'Batteriebetrieb · Wert nicht verfügbar' : '–';
  document.querySelector('#recent-table').innerHTML = (state.data.recent_measurements || []).map((row) => {
    const note = calibrationNote(row.raw_temperature_c, row.temperature_offset_c);
    return `<tr><td data-label="Zeitpunkt">${formatDate(row.measured_at)}</td><td data-label="Sequenz">${row.sequence}</td><td data-label="Temperatur"><strong>${temperatureLabel(row.temperature_c)}</strong>${note ? `<small>${escapeHtml(note)}</small>` : ''}</td><td data-label="Feuchte">${formatNumber(row.humidity_rh, ' %')}</td><td data-label="Batterie">${row.battery_mv == null ? missingBatteryLabel : formatNumber(row.battery_mv, ' mV')}</td></tr>`;
  }).join('') || '<tr class="empty-row"><td colspan="5">Noch keine Messwerte vorhanden.</td></tr>';
  renderRecentPagination();
}

function renderRecentPagination() {
  const nav = document.querySelector('#recent-pagination');
  if (!nav) return;
  const pagination = state.data?.recent_pagination;
  nav.hidden = !pagination;
  if (!pagination) return;
  const page = Number(pagination.page);
  const total = Number(pagination.total);
  const perPage = Number(pagination.per_page);
  const start = total ? (page - 1) * perPage + 1 : 0;
  const end = Math.min(total, page * perPage);
  document.querySelector('#recent-page-status').textContent = total
    ? `Seite ${page} von ${pagination.total_pages} · ${start}–${end} von ${total} Messwerten${recentPagePending ? ' · wird geladen' : ''}`
    : 'Keine Messwerte';
  const pending = recentPagePending || overviewPending;
  document.querySelector('#recent-prev').disabled = pending || !pagination.has_previous;
  document.querySelector('#recent-next').disabled = pending || !pagination.has_next;
  nav.setAttribute('aria-busy', String(pending));
}

async function changeRecentPage(direction) {
  const pagination = state.data?.recent_pagination;
  if (!pagination || recentPagePending || overviewPending) return;
  const targetPage = Number(pagination.page) + direction;
  if (targetPage < 1 || targetPage > Number(pagination.total_pages)) return;

  const sequence = ++recentRequestSequence;
  recentPagePending = true;
  renderRecentPagination();
  const params = new URLSearchParams({ recent_only: '1', recent_page: String(targetPage) });
  if (state.device) params.set('device', state.device);
  if (state.point) params.set('point', state.point);
  if (pagination.snapshot_id) params.set('recent_snapshot_id', String(pagination.snapshot_id));
  try {
    const result = await api(`/api/v1/dashboard/overview?${params}`);
    if (sequence !== recentRequestSequence) return;
    if (!result.recent_pagination || !Array.isArray(result.recent_measurements)
      || result.selection?.device_uid !== state.device || result.selection?.measurement_point !== state.point) {
      throw new Error('Messwerte konnten nicht geladen werden.');
    }
    state.data.recent_measurements = result.recent_measurements;
    state.data.recent_pagination = result.recent_pagination;
    state.recentPage = result.recent_pagination.page;
    recentPagePending = false;
    renderRecent();
    const heading = document.querySelector('#recent-heading');
    heading?.focus({ preventScroll: true });
    heading?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  } catch (error) {
    if (sequence !== recentRequestSequence) return;
    recentPagePending = false;
    renderRecentPagination();
    context.showMessage(error.message);
  }
}

async function uploadPhoto(file) {
  if (!file || photoUploadInProgress) return;
  if (file.size > 12 * 1024 * 1024) { context.showMessage('Das Foto darf höchstens 12 MiB groß sein.'); return; }
  photoUploadInProgress = true;
  document.querySelectorAll('[data-photo-upload]').forEach((input) => { input.disabled = true; });
  const form = new FormData(); form.append('photo', file);
  const pointId = state.data.selected_measurement_point.id;
  try {
    context.showMessage('Foto wird hochgeladen und verarbeitet …', true);
    await api(`/api/v1/dashboard/measurement-points/${pointId}/photos`, { method: 'POST', body: form });
    context.showMessage('Das Messstellenfoto wurde sicher verarbeitet und versioniert.', true);
    await load();
  } catch (error) { context.showMessage(error.message); }
  finally {
    photoUploadInProgress = false;
    document.querySelectorAll('[data-photo-upload]').forEach((input) => { input.disabled = false; });
  }
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

function identityDialog() {
  const device = state.data.selected_device;
  const point = state.data.selected_measurement_point;
  if (!point) return;
  openDialog({ heading: 'Namen & Ort bearbeiten', kicker: device.device_uid, html: `<form id="identity-form"><div class="form-grid"><label>Gerätename<input name="device_name" value="${escapeHtml(device.name)}" maxlength="160" required></label><label>Messstellenname<input name="point_name" value="${escapeHtml(point.name)}" maxlength="160" required></label><label>Ort<input name="location" value="${escapeHtml(point.location || '')}" maxlength="255"></label></div><p class="form-note">Die Gerätekennung und Messstellenkennung bleiben bestehen. Bereits gespeicherte Messwerte bleiben erhalten.</p><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Namen speichern</button></div></form>`, onOpen(root) {
    root.querySelector('[data-cancel]').addEventListener('click', closeDialog);
    root.querySelector('form').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const values = new FormData(form);
      try {
        await api(`/api/v1/dashboard/devices/${encodeURIComponent(device.device_uid)}/identity`, { method: 'PUT', body: {
          name: String(values.get('device_name')).trim(),
          measurement_point: { code: point.code, name: String(values.get('point_name')).trim(), location: String(values.get('location')).trim() || null },
        } });
        closeDialog();
        context.showMessage('Namen und Ort wurden gespeichert.', true);
        await load();
      } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); }
    });
  } });
}

function settingsDialog() {
  const settings = state.data.settings;
  const currentUploadInterval = Number(settings.schedule.upload_interval_seconds);
  const uploadIntervals = [
    [60, 'Jede Minute'],
    [300, 'Alle 5 Minuten'],
    [900, 'Alle 15 Minuten'],
    [1800, 'Alle 30 Minuten'],
    [3600, 'Alle 60 Minuten · 24 × täglich'],
    [7200, '12 × täglich'],
    [10800, '8 × täglich'],
    [14400, '6 × täglich'],
    [17280, '5 × täglich'],
    [21600, '4 × täglich'],
    [28800, '3 × täglich'],
    [43200, '2 × täglich'],
    [86400, '1 × täglich'],
  ];
  if (!uploadIntervals.some(([seconds]) => seconds === currentUploadInterval)) {
    uploadIntervals.push([currentUploadInterval, `Aktuell: alle ${formatNumber(currentUploadInterval, ' Sekunden')}`]);
    uploadIntervals.sort(([a], [b]) => a - b);
  }
  const pointFields = settings.schedule.measurement_points.map((point) => `<label>${escapeHtml(point.measurement_point)} · Minuten<input name="point:${escapeHtml(point.measurement_point)}" type="number" step="0.5" min="0.5" max="1440" value="${point.interval_seconds / 60}" required></label>`).join('');
  const calibrationFields = settings.schedule.measurement_points.map((point) => {
    const configured = settings.calibration?.measurement_points?.find((candidate) => candidate.measurement_point === point.measurement_point);
    const offset = configured?.temperature_offset_c ?? 0;
    const pointName = state.data.measurement_points?.find((candidate) => candidate.code === point.measurement_point)?.name || point.measurement_point;
    const pointLabel = pointName === point.measurement_point ? pointName : `${pointName} (${point.measurement_point})`;
    return `<label>Messstelle ${escapeHtml(pointLabel)} · Abgleich °C<input name="calibration:${escapeHtml(point.measurement_point)}" type="number" step="0.001" min="-10" max="10" value="${escapeHtml(offset)}" required></label>`;
  }).join('');
  const batteryFields = ['mains', 'battery_unmonitored'].includes(state.data.selected_device.battery.power_source)
    ? `<input type="hidden" name="low" value="${settings.battery.low_threshold_mv}"><input type="hidden" name="full" value="${settings.battery.full_threshold_mv}">`
    : `<label>Batterie niedrig mV<input name="low" type="number" min="0" max="10000" value="${settings.battery.low_threshold_mv}" required></label><label>Batterie voll mV<input name="full" type="number" min="0" max="10000" value="${settings.battery.full_threshold_mv}" required></label>`;
  const sensorName = state.data.selected_measurement_point?.sensor_type || state.data.selected_device.device_info?.sensor_model || 'Sensor';
  openDialog({ heading: state.data.selected_device.name, kicker: `Geräteeinstellungen · Version ${settings.config_version}`, html: `<form id="settings-form"><fieldset><legend>Grenzwerte</legend><div class="form-grid"><label>Temperaturalarm<select name="enabled"><option value="true" ${settings.alarm.enabled ? 'selected' : ''}>Aktiv</option><option value="false" ${!settings.alarm.enabled ? 'selected' : ''}>Deaktiviert</option></select></label><span></span><label>Minimum °C<input name="min" type="number" step="0.1" min="-100" max="150" value="${settings.alarm.temperature_min_c ?? ''}"></label><label>Maximum °C<input name="max" type="number" step="0.1" min="-100" max="150" value="${settings.alarm.temperature_max_c ?? ''}"></label>${batteryFields}</div></fieldset><fieldset><legend>Messung und Übertragung</legend><div class="form-grid"><label>Standard-Messintervall · Minuten<input name="default-interval" type="number" step="0.5" min="0.5" max="1440" value="${settings.schedule.default_measurement_interval_seconds / 60}" required></label><label>Übertragungsintervall<select name="upload-interval-seconds">${uploadIntervals.map(([seconds, label]) => `<option value="${seconds}" ${seconds === currentUploadInterval ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}</select></label>${pointFields}</div><p class="form-note">Die Firmware bestätigt die übernommene Version beim nächsten HTTPS-Kontakt. Messwerte bleiben während WLAN-Ausfällen lokal gepuffert.</p></fieldset><fieldset><legend>Temperaturabgleich</legend><p class="form-note">Angezeigter Wert = Rohwert + Abgleich. Zeigt der Sensor 3 °C zu viel, geben Sie −3 °C ein. Vergleichen Sie die Lufttemperatur direkt neben dem ${escapeHtml(sensorName)} mit einem Referenzthermometer, nicht mit einer Infrarot-Oberflächentemperatur. Der Abgleich gilt für neue Messungen; bisherige Messwerte bleiben erhalten.</p><div class="form-grid">${calibrationFields}</div></fieldset><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="secondary-button" type="reset">Eingaben zurücksetzen</button><button class="primary-button" type="submit">Versioniert speichern</button></div></form>`, onOpen(root) {
    root.querySelector('[data-cancel]').addEventListener('click', closeDialog);
    root.querySelector('form').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const values = new FormData(form);
      const min = values.get('min') === '' ? null : Number(values.get('min'));
      const max = values.get('max') === '' ? null : Number(values.get('max'));
      const measurementPoints = settings.schedule.measurement_points.map((point) => ({
        measurement_point: point.measurement_point,
        interval_seconds: Math.round(Number(values.get(`point:${point.measurement_point}`)) * 60),
      }));
      const calibrationPoints = settings.schedule.measurement_points.map((point) => ({
        measurement_point: point.measurement_point,
        temperature_offset_c: Number(values.get(`calibration:${point.measurement_point}`)),
      }));
      try {
        await api(`/api/v1/dashboard/devices/${encodeURIComponent(state.device)}/settings`, { method: 'PUT', body: {
          expected_config_version: settings.config_version,
          alarm: { enabled: values.get('enabled') === 'true', temperature_min_c: min, temperature_max_c: max },
          battery: { low_threshold_mv: Number(values.get('low')), full_threshold_mv: Number(values.get('full')) },
          schedule: { default_measurement_interval_seconds: Math.round(Number(values.get('default-interval')) * 60), upload_interval_seconds: Number(values.get('upload-interval-seconds')), measurement_points: measurementPoints },
          calibration: { measurement_points: calibrationPoints },
        } });
        closeDialog();
        context.showMessage('Konfiguration gespeichert. Neue Messungen nutzen den Abgleich; das Messintervall bestätigt der Sensor beim nächsten Kontakt.', true);
        await load();
      } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); }
    });
  } });
}

function diagnosticsDialog() {
  const device = state.data.selected_device;
  const info = device.device_info || state.data.diagnostics?.device_info || {};
  const operation = state.data.diagnostics?.operational_status || {};
  const errors = state.data.diagnostics?.diagnostic_errors || [];
  openDialog({ heading: device.name, kicker: 'Firmware- und Betriebszustand', html: `<dl class="detail-list"><div><dt>Board</dt><dd>${escapeHtml(info.board_model || 'Noch nicht gemeldet')}</dd></div><div><dt>Chip</dt><dd>${escapeHtml(info.chip_model || '–')}${info.chip_revision !== undefined ? ` · Revision ${info.chip_revision}` : ''}</dd></div><div><dt>Sensor</dt><dd>${escapeHtml(info.sensor_model || '–')} · ${escapeHtml(info.sensor_status || 'unbekannt')}</dd></div><div><dt>Flash / PSRAM</dt><dd>${formatNumber(info.flash_bytes !== undefined ? info.flash_bytes / 1048576 : null, ' MiB')} / ${formatNumber(info.psram_bytes !== undefined ? info.psram_bytes / 1048576 : null, ' MiB')}</dd></div><div><dt>Pufferstand beim letzten Senden</dt><dd>${formatNumber(operation.queue_depth)} / ${formatNumber(info.queue_capacity)} Messwerte</dd></div><div><dt>Aufwachgrund</dt><dd>${escapeHtml(operation.wake_reason || '–')} · Reset ${escapeHtml(operation.reset_reason || '–')}</dd></div><div><dt>WLAN-Fehler gemeldet</dt><dd>${formatNumber(operation.wifi_failures_since_report)} · maximal ${formatNumber(operation.max_consecutive_wifi_failures)} in Folge</dd></div><div><dt>HTTPS-Fehler gemeldet</dt><dd>${formatNumber(operation.upload_failures_since_report)}</dd></div><div><dt>Sleep-Fallbacks</dt><dd>${formatNumber(operation.sleep_fallbacks_since_report)}</dd></div><div><dt>Diagnosecodes</dt><dd>${errors.length ? errors.map(escapeHtml).join(', ') : 'Keine'}</dd></div></dl>` });
}

function batteryDialog() {
  const unmonitored = state.data.selected_device.battery.power_source === 'battery_unmonitored';
  openDialog({ heading: 'Batteriewechsel dokumentieren', kicker: state.data.selected_device.name, html: `<form id="battery-form"><div class="form-grid"><label>Chemie / Profil<input name="chemistry" value="${unmonitored ? '' : 'Alkaline'}" maxlength="64" required></label><label>Zellen in Reihe<input name="series_count" type="number" min="1" max="16" value="${unmonitored ? '' : '4'}" required></label><label>Nennkapazität mAh<input name="capacity" type="number" min="1" max="100000" placeholder="optional"></label></div><p class="form-note">${unmonitored ? 'Der Wechsel wird dokumentiert. Ohne gemessene Batteriespannung ist keine Restlaufzeit verfügbar.' : 'Der Wechsel beginnt einen neuen Prognosezyklus. Eine Restlaufzeit wird erst bei ausreichendem Verlauf angezeigt.'}</p><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Wechsel erfassen</button></div></form>`, onOpen(root) { root.querySelector('[data-cancel]').addEventListener('click', closeDialog); root.querySelector('form').addEventListener('submit', async (event) => { event.preventDefault(); const values = new FormData(event.currentTarget); await api(`/api/v1/dashboard/devices/${encodeURIComponent(state.device)}/battery-replaced`, { method: 'POST', body: { chemistry: values.get('chemistry'), series_count: Number(values.get('series_count')), nominal_capacity_mah: values.get('capacity') ? Number(values.get('capacity')) : null, forecast_enabled: true } }); closeDialog(); context.showMessage('Batteriewechsel wurde als neuer Zyklus dokumentiert.', true); }); } });
}

function enrollmentDialog() {
  openDialog({ heading: 'Neues Gerät anlernen', kicker: 'Einmalige Einrichtung', html: `<form id="enrollment-form"><div class="form-grid"><label>Gerätename<input name="name" required maxlength="160"></label><label>Geräte-UID<input name="device_uid" pattern="[a-z0-9][a-z0-9-]{2,63}" placeholder="wird optional erzeugt"></label><label>Messstellenkennung<input name="point_code" value="temperature-1" required></label><label>Messstellenname<input name="point_name" value="Temperatursensor" required></label><label>Sensortyp<select name="sensor_type" required><option value="">Bitte wählen</option><option value="DHT22">DHT22 / AM2302</option><option value="SHT45">SHT45</option></select></label><label>Ort<input name="location"></label><label>Temperatur min. °C<input name="min" type="number" step="0.1" value="2"></label><label>Temperatur max. °C<input name="max" type="number" step="0.1" value="7"></label></div><div class="form-message" hidden></div><div class="dialog-actions"><button class="secondary-button" type="button" data-cancel>Abbrechen</button><button class="primary-button" type="submit">Gerät vorbereiten</button></div></form>`, onOpen(root) { root.querySelector('[data-cancel]').addEventListener('click', closeDialog); root.querySelector('form').addEventListener('submit', async (event) => { event.preventDefault(); const form = event.currentTarget; const data = new FormData(form); try { const result = await api('/api/v1/dashboard/devices', { method: 'POST', body: { ...(data.get('device_uid') ? { device_uid: data.get('device_uid') } : {}), name: data.get('name'), measurement_point: { code: data.get('point_code'), name: data.get('point_name'), sensor_type: data.get('sensor_type'), location: data.get('location') || null }, alarm: { enabled: true, temperature_min_c: Number(data.get('min')), temperature_max_c: Number(data.get('max')) }, battery: { low_threshold_mv: 5600, full_threshold_mv: 6000 } } }); root.innerHTML = `<p class="form-note">Diese Zugangsdaten werden nur einmal angezeigt. Sicher in das Provisionierungsportal des Sensors übertragen.</p><dl class="detail-list"><div><dt>Server</dt><dd class="mono">${escapeHtml(result.setup_package.api_base_url)}</dd></div><div><dt>Geräte-UID</dt><dd class="mono">${escapeHtml(result.setup_package.device_uid)}</dd></div><div><dt>Messstelle</dt><dd class="mono">${escapeHtml(result.setup_package.measurement_point)}</dd></div><div><dt>Geräteschlüssel</dt><dd class="mono">${escapeHtml(result.setup_package.device_key)}</dd></div></dl><div class="dialog-actions"><button class="primary-button" type="button" data-done>Ich habe die Daten gesichert</button></div>`; root.querySelector('[data-done]').addEventListener('click', async () => { closeDialog(); await load(); }); } catch (error) { form.querySelector('.form-message').outerHTML = errorMessage(error); } }); } });
}
