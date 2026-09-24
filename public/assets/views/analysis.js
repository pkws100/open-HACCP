import { api } from '../api.js?v=20260810-1';
import { accessibleTable, chartColor, metricTrendChart, observeChartResize } from '../charts.js?v=20260925-2';
import { eventStackChart } from '../analysis-events-chart.js?v=20260925-1';
import { escapeHtml, eventLabel, formatDate, formatNumber, metric } from '../format.js?v=20260923-1';

const state = {
  days: 30, device: '', point: '', chartDevice: '', chartPoint: '', data: null, initialized: false,
  show: { temperature: true, humidity: true, rssi: true, transmissions: true },
};
const charts = Object.fromEntries(['measurements', 'events', 'battery', 'connections'].map((key) =>
  [key, { rows: [], selectedIndex: -1, pinned: false, geometry: null, ariaText: '' }]));
const dayFormat = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' });
const preciseTemperature = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 3 });
let context;
let requestSequence = 0;

export const analysisView = {
  init(app) {
    context = app;
    if (state.initialized) return;
    state.initialized = true;
    document.querySelector('#analysis-device').addEventListener('change', async (event) => {
      ++requestSequence;
      state.device = event.target.value;
      state.point = '';
      try { await loadPoints(); await loadAnalysis(); }
      catch (error) { context.showMessage(error.message); }
    });
    document.querySelector('#analysis-point').addEventListener('change', (event) => {
      state.point = event.target.value;
      loadAnalysis().catch((error) => context.showMessage(error.message));
    });
    document.querySelectorAll('[data-days]').forEach((button) => button.addEventListener('click', () => {
      state.days = Number(button.dataset.days);
      document.querySelectorAll('[data-days]').forEach((candidate) =>
        candidate.classList.toggle('is-active', candidate === button));
      loadAnalysis().catch((error) => context.showMessage(error.message));
    }));
    document.querySelector('#analysis-chart-device').addEventListener('change', (event) => {
      state.chartDevice = event.target.value;
      state.chartPoint = '';
      renderMeasurementFocus();
      resetChart('measurements');
      renderMeasurements();
    });
    document.querySelector('#analysis-chart-point').addEventListener('change', (event) => {
      state.chartPoint = event.target.value;
      resetChart('measurements');
      renderMeasurements();
    });
    document.querySelectorAll('[data-analysis-toggle]').forEach((button) => button.addEventListener('click', () => {
      const key = button.dataset.analysisToggle;
      const other = ({ temperature: 'humidity', humidity: 'temperature', rssi: 'transmissions', transmissions: 'rssi' })[key];
      if (state.show[key] && !state.show[other]) return;
      state.show[key] = !state.show[key];
      updateToggles();
      draw(key === 'temperature' || key === 'humidity' ? 'measurements' : 'connections');
    }));
    Object.keys(charts).forEach(bindChart);
    document.querySelectorAll('[data-analysis-latest]').forEach((button) =>
      button.addEventListener('click', () => resetSelection(button.dataset.analysisLatest)));
    window.addEventListener('resize', () => state.data && redrawAll());
    window.addEventListener('haccp:themechange', () => state.data && redrawAll());
    observeChartResize(Object.keys(charts).map((key) => document.querySelector('#analysis-' + key)),
      () => state.data && redrawAll());
    updateToggles();
  },
  async load() {
    await ensureDevices();
    await loadPoints();
    await loadAnalysis();
  },
};

function options(select, entries, selected) {
  select.replaceChildren(...entries.map(([value, label]) => {
    const option = document.createElement('option');
    option.value = String(value);
    option.textContent = String(label);
    option.selected = String(value) === String(selected);
    return option;
  }));
}

async function ensureDevices() {
  if (!context.devices.length) context.devices = (await api('/api/v1/dashboard/overview?hours=24')).devices;
  options(document.querySelector('#analysis-device'),
    [['', 'Gesamte Flotte'], ...context.devices.map((device) => [device.device_uid, device.name])], state.device);
}

async function loadPoints() {
  const select = document.querySelector('#analysis-point');
  const device = state.device;
  if (!device) {
    options(select, [['', 'Alle Messstellen']], '');
    select.disabled = true;
    return;
  }
  const overview = await api('/api/v1/dashboard/overview?hours=24&device=' + encodeURIComponent(device));
  if (state.device !== device) return;
  options(select, [['', 'Alle Messstellen'], ...overview.measurement_points.map((point) =>
    [point.id, point.name])], state.point);
  select.disabled = false;
}

async function loadAnalysis() {
  const sequence = ++requestSequence;
  const params = new URLSearchParams({ days: String(state.days) });
  if (state.device) params.set('device', state.device);
  if (state.point) params.set('measurement_point_id', state.point);
  let data;
  try { data = await api('/api/v1/dashboard/analysis?' + params); }
  catch (error) { if (sequence === requestSequence) throw error; return; }
  if (sequence !== requestSequence) return;
  state.data = data;
  Object.keys(charts).forEach(resetChart);
  render();
}

function resetChart(key) {
  Object.assign(charts[key], { rows: [], selectedIndex: -1, pinned: false, geometry: null, ariaText: '' });
}

function quantity(count, singular, plural) {
  return count + ' ' + (Number(count) === 1 ? singular : plural);
}

function render() {
  renderMetrics();
  renderMeasurementFocus();
  renderMeasurements();
  renderEvents();
  renderBattery();
  renderConnections();
}

function redrawAll() { Object.keys(charts).forEach(draw); }

function renderMetrics() {
  const data = state.data;
  const deviceName = state.device
    ? context.devices.find((device) => device.device_uid === state.device)?.name || state.device
    : 'Gesamte Flotte';
  const pointName = state.point
    ? document.querySelector('#analysis-point').selectedOptions[0]?.textContent || 'Messstelle'
    : 'alle Messstellen';
  document.querySelector('#analysis-scope').textContent =
    (state.device ? 'Gerät: ' + deviceName : deviceName) + ' · ' +
    (state.point ? 'Messstelle: ' + pointName : pointName) + ' · ' +
    quantity(data.fleet.devices, 'aktives Gerät', 'aktive Geräte') +
    (state.point
      ? ' · Messstellenfilter: Messwerte und Ereignisse. Signal, Übertragungen und Verfügbarkeit gelten für das gesamte Gerät.'
      : ' · Verfügbarkeit und Übertragungen beziehen sich auf Geräte.') +
    ' Die Verfügbarkeit ist eine Schätzung mit dem aktuellen Sendeintervall; bei Taktwechseln im Zeitraum kann sie abweichen.' +
    ' Die Zeiträume sind rollierende 24-Stunden-Tage; der erste und letzte UTC-Kalendertag können deshalb nur teilweise enthalten sein.';
  const availability = data.availability.length
    ? data.availability.reduce((sum, row) => sum + Number(row.availability_percent), 0) / data.availability.length
    : null;
  document.querySelector('#analysis-metrics').innerHTML = [
    metric('Verfügbarkeit (Schätzung)', formatNumber(availability, ' %'), 'Ø je Gerät · empfangen / erwartet'),
    metric('Messwerte', formatNumber(data.fleet.measurements), 'vollständige Anzahl · ' + data.range.days + ' Tage'),
    metric('Offene Ereignisse', formatNumber(data.fleet.open_events), 'aktueller Stand, unabhängig vom Zeitraum'),
    metric('Ablehnungen', formatNumber(data.fleet.rejections), 'zurückgewiesene Werte im Zeitraum'),
  ].join('');
}

function renderMeasurementFocus() {
  const data = state.data;
  document.querySelector('#analysis-chart-device-wrap').hidden = Boolean(state.device);
  if (state.device) state.chartDevice = state.device;
  else if (!context.devices.some((device) => device.device_uid === state.chartDevice)) {
    const active = new Set(data.measurements.map((row) => row.device_uid));
    state.chartDevice = context.devices.find((device) => active.has(device.device_uid))?.device_uid
      || context.devices[0]?.device_uid || '';
  }
  options(document.querySelector('#analysis-chart-device'),
    context.devices.map((device) => [device.device_uid, device.name]), state.chartDevice);
  const deviceRows = data.measurements.filter((row) => row.device_uid === state.chartDevice);
  const pointCodes = [...new Set(deviceRows.map((row) => row.point_code).filter(Boolean))].sort();
  if (state.point) state.chartPoint = deviceRows[0]?.point_code || '';
  else if (!pointCodes.includes(state.chartPoint))
    state.chartPoint = deviceRows.at(-1)?.point_code || pointCodes[0] || '';
  document.querySelector('#analysis-chart-point-wrap').hidden = Boolean(state.point) || pointCodes.length <= 1;
  options(document.querySelector('#analysis-chart-point'), pointCodes.map((code) => [code, code]), state.chartPoint);
}

function renderMeasurements() {
  const data = state.data;
  const rows = data.measurements.filter((row) =>
    row.device_uid === state.chartDevice && (!state.chartPoint || row.point_code === state.chartPoint));
  charts.measurements.rows = rows;
  const sampled = data.measurements_sampling;
  const deviceName = context.devices.find((device) => device.device_uid === state.chartDevice)?.name || 'kein Gerät';
  document.querySelector('#analysis-measurements-note').textContent =
    (state.device ? '' : 'Die Flottenkennzahlen bleiben gemeinsam; die Kurven zeigen ' + deviceName + '. ') +
    'Temperatur und Feuchte haben eigene Skalen. Verschiedene Geräte und Messstellen werden nicht verbunden. ' +
    (sampled?.sampled
      ? 'Für die gesamte Filterauswahl wurden ' + formatNumber(sampled.returned_count) + ' von ' +
        formatNumber(sampled.total_count) + ' echten Messungen zeitlich verteilt ausgewählt; ' +
        'die aktuelle Kurve zeigt davon ' + formatNumber(rows.length) + '.' : '');
  document.querySelector('#analysis-measurements-empty').hidden = rows.length > 0;
  accessibleTable(document.querySelector('#analysis-measurements-table'), 'Messwerte im dargestellten Verlauf',
    ['Zeitpunkt', 'Gerät', 'Messstelle', 'Temperatur °C', 'Feuchte % rF'],
    rows.slice(-1000).map((row) =>
      [formatDate(row.measured_at), escapeHtml(row.device_uid), escapeHtml(row.point_code), row.temperature_c, row.humidity_rh]));
  draw('measurements');
}

function renderEvents() {
  accessibleTable(document.querySelector('#analysis-events-table'), 'Ereignisse nach Tag, Art und Schwere',
    ['Tag UTC', 'Art', 'Schwere', 'Anzahl'],
    state.data.events_by_day.map((row) =>
      [escapeHtml(row.day), escapeHtml(eventLabel(row.event_type)), escapeHtml(row.severity), row.event_count]));
  draw('events');
}

function batteryStatus(battery) {
  switch (battery.status) {
    case 'device_required': return 'Wählen Sie oben ein einzelnes Gerät. Eine Flottenprognose würde unterschiedliche Stromquellen vermischen.';
    case 'unavailable': return 'Dieses Gerät meldet keine gemessene Batteriespannung. Bei USB- oder Powerbank-Betrieb wird kein Spannungswert erfunden.';
    case 'disabled': return 'Die Prognose ist für den aktuellen Batteriezyklus deaktiviert.';
    case 'non_declining': return 'Die Spannung fällt derzeit nicht verlässlich; eine Restlaufzeit lässt sich daraus nicht bestimmen.';
    case 'insufficient_data': return 'Für eine Prognose sind mindestens 20 Werte über sieben Tage mit fallendem Verlauf erforderlich.';
    default: return 'Eine Batterieprognose liegt derzeit nicht vor.';
  }
}

function renderBattery() {
  const battery = state.data.battery || {};
  const rows = Array.isArray(battery.series) ? battery.series : [];
  charts.battery.rows = rows;
  document.querySelector('#battery-eta').textContent = battery.status === 'estimated'
    ? '≈ ' + formatNumber(battery.estimated_days_remaining) + ' Tage' : '–';
  document.querySelector('#battery-note').textContent = battery.status === 'estimated'
    ? 'Geschätzte Zeit bis zur Niedrigschwelle · Trend ' + formatNumber(battery.slope_mv_per_day, ' mV/Tag') +
      ' · Sicherheit ' + ({ low: 'gering', medium: 'mittel', high: 'hoch' }[battery.confidence] || 'unbekannt')
    : rows.length ? batteryStatus(battery) : '';
  const thresholdLegend = document.querySelector('#analysis-battery-threshold-legend');
  const threshold = Number(battery.low_threshold_mv);
  thresholdLegend.hidden = !rows.length || !Number.isFinite(threshold) || threshold <= 0;
  if (!thresholdLegend.hidden)
    thresholdLegend.lastChild.textContent = 'Niedrigschwelle · ' + formatNumber(threshold, ' mV');
  const empty = rows.length === 0;
  document.querySelector('#analysis-battery').hidden = empty;
  document.querySelector('#analysis-battery-readout').hidden = empty;
  document.querySelector('#analysis-battery-hint').hidden = empty;
  document.querySelector('#analysis-battery-empty').hidden = !empty;
  document.querySelector('#analysis-battery-empty').textContent = empty ? batteryStatus(battery) : '';
  accessibleTable(document.querySelector('#analysis-battery-table'), 'Gemessener Batterieverlauf',
    ['Zeitpunkt', 'Spannung mV', 'Niedrigschwelle mV'],
    rows.slice(-1000).map((row) => [formatDate(row.at), row.mv, battery.low_threshold_mv]));
  if (rows.length) draw('battery');
  else updateSlider('battery');
}

function utcMillis(value) {
  const text = String(value).trim().replace(' ', 'T');
  const zoned = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(text) ? text : text + 'Z';
  return Date.parse(zoned.replace(/(\.\d{3})\d+(?=Z|[+-]\d{2}:?\d{2}$)/i, '$1'));
}

function connectionDays(data) {
  const byDay = new Map(data.connections_by_day.map((row) => [row.day, row]));
  const from = String(data.range.from).slice(0, 10);
  const to = String(data.range.to).slice(0, 10);
  const fromTime = utcMillis(data.range.from);
  const toTime = utcMillis(data.range.to);
  const rows = [];
  for (let time = Date.parse(from + 'T00:00:00Z'), last = Date.parse(to + 'T00:00:00Z');
    Number.isFinite(time) && time <= last; time += 86400000) {
    const day = new Date(time).toISOString().slice(0, 10);
    const source = byDay.get(day);
    const noon = Date.parse(day + 'T12:00:00Z');
    rows.push({
      day, at: new Date(Math.min(toTime, Math.max(fromTime, noon))).toISOString(),
      rssi: source?.average_rssi_dbm == null ? null : Number(source.average_rssi_dbm),
      transmissions: source ? Number(source.transmissions) : 0,
    });
  }
  return rows;
}

function renderConnections() {
  const data = state.data;
  charts.connections.rows = connectionDays(data);
  const empty = data.connections_by_day.length === 0;
  document.querySelector('#analysis-connections').hidden = empty;
  document.querySelector('#analysis-connections-hint').hidden = empty;
  document.querySelector('#analysis-connections-empty').hidden = !empty;
  document.querySelector('#analysis-connections-note').textContent =
    'Täglicher Mittelwert des WLAN-Signals und Anzahl der Übertragungen auf getrennten Skalen. ' +
    (state.device ? 'Werte für das gewählte Gerät.' : 'Flottenmittel des Signals und Summe aller Geräte.') +
    ' Ein fehlender RSSI wird nicht als 0 dBm dargestellt.';
  const availabilityRows = data.availability.map((row) =>
    ['Verfügbarkeit ' + escapeHtml(row.name), row.transmissions + '/' + row.expected_transmissions,
      null, null, formatNumber(row.availability_percent, ' %')]);
  accessibleTable(document.querySelector('#analysis-connections-table'),
    'Verbindungsqualität und Verfügbarkeit',
    ['Tag UTC / Gerät', 'Übertragungen', 'RSSI dBm', 'WLAN ms', 'Ablehnungen / Verfügbarkeit'],
    [...data.connections_by_day.map((row) =>
      [escapeHtml(row.day), row.transmissions, row.average_rssi_dbm, row.average_wifi_connect_ms, row.rejected_measurements]),
    ...availabilityRows]);
  if (!empty) draw('connections');
  else updateReadout('connections');
}

function chartHeight(key, seriesCount) {
  const width = document.querySelector('#analysis-' + key).clientWidth;
  if (key === 'events') return width < 420 ? 250 : 275;
  if (key === 'battery') return width < 420 ? 235 : 275;
  return width < 420 ? (seriesCount === 1 ? 265 : 325)
    : (seriesCount === 1 ? 300 : key === 'measurements' ? 380 : 350);
}

function definitions(key) {
  if (key === 'measurements') return [
    state.show.temperature && { key: 'temperature_c', label: 'Temperatur', unit: '°C', color: chartColor('accent') },
    state.show.humidity && { key: 'humidity_rh', label: 'Luftfeuchtigkeit', unit: '% rF',
      color: chartColor('humidity'), dashed: true },
  ].filter(Boolean);
  if (key === 'battery') return [{
    key: 'mv', label: 'Spannung', unit: 'mV', color: chartColor('warning'),
    reference: Number(state.data.battery.low_threshold_mv) > 0
      ? { value: Number(state.data.battery.low_threshold_mv), color: chartColor('danger'), dashed: true } : null,
  }];
  return [
    state.show.rssi && { key: 'rssi', label: 'Ø Funksignal', unit: 'dBm', color: chartColor('accent') },
    state.show.transmissions && { key: 'transmissions', label: 'Übertragungen', unit: 'Anzahl/Tag',
      color: chartColor('humidity'), dashed: true },
  ].filter(Boolean);
}

function draw(key) {
  if (!state.data) return;
  const control = charts[key];
  const canvas = document.querySelector('#analysis-' + key);
  if (canvas.hidden) return;
  let geometry;
  if (key === 'events') {
    canvas.dataset.chartHeight = String(chartHeight(key, 1));
    geometry = eventStackChart(canvas, state.data.events_by_day, {
      startAt: state.data.range.from, endAt: state.data.range.to,
      selectedDay: control.rows[control.selectedIndex]?.day,
    });
    control.rows = geometry.days;
  } else {
    const series = definitions(key);
    canvas.dataset.chartHeight = String(chartHeight(key, series.length));
    geometry = metricTrendChart(canvas, control.rows, {
      timeKey: key === 'measurements' ? 'measured_at' : 'at',
      series, selectedIndex: control.selectedIndex,
      startAt: key === 'battery' ? control.rows[0]?.at : state.data.range.from,
      endAt: state.data.range.to,
    });
  }
  control.geometry = geometry;
  if (!geometry.positions.some((position) => position.index === control.selectedIndex)) {
    control.selectedIndex = geometry.positions.at(-1)?.index ?? -1;
    control.pinned = false;
    if (control.selectedIndex >= 0) {
      if (key === 'events') {
        control.geometry = eventStackChart(canvas, state.data.events_by_day, {
          startAt: state.data.range.from, endAt: state.data.range.to,
          selectedDay: control.rows[control.selectedIndex]?.day,
        });
      } else { draw(key); return; }
    }
  }
  updateReadout(key);
}

function dayLabel(day) { return dayFormat.format(new Date(day + 'T12:00:00Z')) + ' (UTC)'; }
function temperature(value) {
  return value == null ? '–' : preciseTemperature.format(Number(value)) + ' °C';
}

function updateReadout(key) {
  const control = charts[key];
  const row = control.rows[control.selectedIndex];
  const set = (suffix, value) =>
    { document.querySelector('#analysis-' + key + '-' + suffix).textContent = value; };
  if (key === 'measurements') {
    set('time', row ? formatDate(row.measured_at) : 'Noch kein Messwert');
    set('temperature', 'Temperatur ' + temperature(row?.temperature_c));
    set('humidity', 'Luftfeuchtigkeit ' + (row?.humidity_rh == null ? '–' : formatNumber(row.humidity_rh, ' % rF')));
    control.ariaText = row ? formatDate(row.measured_at) + ', Temperatur ' + temperature(row.temperature_c) +
      ', Luftfeuchtigkeit ' + formatNumber(row.humidity_rh, ' % rF') : 'Noch kein Messwert';
  } else if (key === 'events') {
    set('day', row ? dayLabel(row.day) : 'Noch kein Tag');
    set('count', row ? row.total + ' neu · ' + quantity(row.warning, 'Warnung', 'Warnungen') +
      ' · ' + quantity(row.critical, 'kritisches Ereignis', 'kritische Ereignisse') : '–');
    const types = row ? state.data.events_by_day.filter((event) => event.day === row.day) : [];
    document.querySelector('#analysis-events-breakdown').textContent = types.length
      ? types.map((event) => event.event_count + ' × ' + eventLabel(event.event_type)).join(' · ')
      : 'An diesem Tag wurden keine Ereignisse neu eröffnet.';
    control.ariaText = row ? dayLabel(row.day) + ', ' +
      quantity(row.total, 'neues Ereignis', 'neue Ereignisse') : 'Noch kein Tag';
  } else if (key === 'battery') {
    set('time', row ? formatDate(row.at) : 'Noch kein Batteriewert');
    set('value', 'Spannung ' + (row?.mv == null ? '–' : formatNumber(row.mv, ' mV')));
    control.ariaText = row ? formatDate(row.at) + ', Spannung ' + formatNumber(row.mv, ' mV') : 'Noch kein Batteriewert';
  } else {
    set('day', row ? dayLabel(row.day) : 'Noch kein Tag');
    set('rssi', 'Ø Funksignal ' + (row?.rssi == null ? 'nicht gemeldet' : formatNumber(row.rssi, ' dBm')));
    set('count', 'Übertragungen ' + (row ? formatNumber(row.transmissions) : '–'));
    control.ariaText = row ? dayLabel(row.day) + ', ' + row.transmissions + ' Übertragungen, Funksignal ' +
      (row.rssi == null ? 'nicht gemeldet' : formatNumber(row.rssi, ' dBm')) : 'Noch kein Tag';
  }
  updateSlider(key);
}

function updateSlider(key) {
  const control = charts[key];
  const canvas = document.querySelector('#analysis-' + key);
  const positions = control.geometry?.positions || [];
  const rank = positions.findIndex((position) => position.index === control.selectedIndex);
  canvas.tabIndex = rank < 0 || canvas.hidden ? -1 : 0;
  canvas.setAttribute('aria-disabled', String(rank < 0 || canvas.hidden));
  canvas.setAttribute('aria-valuemin', '1');
  canvas.setAttribute('aria-valuemax', String(Math.max(1, positions.length)));
  canvas.setAttribute('aria-valuenow', String(Math.max(1, rank + 1)));
  canvas.setAttribute('aria-valuetext', control.ariaText || 'Keine Daten');
  document.querySelector('[data-analysis-latest="' + key + '"]').hidden =
    rank < 0 || (!control.pinned && rank === positions.length - 1);
}

function updateToggles() {
  document.querySelectorAll('[data-analysis-toggle]').forEach((button) => {
    const key = button.dataset.analysisToggle;
    const other = ({ temperature: 'humidity', humidity: 'temperature', rssi: 'transmissions', transmissions: 'rssi' })[key];
    button.setAttribute('aria-pressed', String(state.show[key]));
    button.disabled = state.show[key] && !state.show[other];
  });
}

function pointerIndex(event, geometry) {
  const positions = geometry?.positions || [];
  if (!positions.length) return null;
  const rect = event.currentTarget.getBoundingClientRect();
  if (!rect.width) return null;
  const x = (event.clientX - rect.left) * geometry.width / rect.width;
  if (x < geometry.left - 12 || x > geometry.right + 12) return null;
  let low = 0; let high = positions.length - 1;
  while (low < high) {
    const middle = Math.floor((low + high) / 2);
    if (positions[middle].x < x) low = middle + 1;
    else high = middle;
  }
  const next = positions[low];
  const previous = positions[Math.max(0, low - 1)];
  return Math.abs(next.x - x) < Math.abs(previous.x - x) ? next.index : previous.index;
}

function selectIndex(key, index, pinned) {
  if (index == null) return;
  const control = charts[key];
  if (control.selectedIndex === index && control.pinned === pinned) return;
  control.selectedIndex = index;
  control.pinned = pinned;
  draw(key);
}

function resetSelection(key) {
  selectIndex(key, charts[key].geometry?.positions.at(-1)?.index, false);
}

function bindChart(key) {
  const canvas = document.querySelector('#analysis-' + key);
  canvas.addEventListener('pointermove', (event) => {
    if ((charts[key].pinned && !event.buttons) || (event.pointerType === 'touch' && !event.buttons)) return;
    selectIndex(key, pointerIndex(event, charts[key].geometry), false);
  });
  canvas.addEventListener('pointerdown', (event) =>
    selectIndex(key, pointerIndex(event, charts[key].geometry), true));
  canvas.addEventListener('pointerleave', () => {
    if (!charts[key].pinned) resetSelection(key);
  });
  canvas.addEventListener('keydown', (event) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Escape'].includes(event.key)) return;
    const positions = charts[key].geometry?.positions || [];
    if (!positions.length) return;
    event.preventDefault();
    if (event.key === 'Escape') { resetSelection(key); return; }
    const rank = positions.findIndex((position) => position.index === charts[key].selectedIndex);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? positions.length - 1
      : Math.max(0, Math.min(positions.length - 1, rank + (event.key === 'ArrowLeft' ? -1 : 1)));
    selectIndex(key, positions[next].index, true);
  });
}
