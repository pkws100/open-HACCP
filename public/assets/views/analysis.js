import { api } from '../api.js?v=20260810-1';
import { accessibleTable, barChart, lineChart, chartColor, observeChartResize } from '../charts.js?v=20260810-1';
import { escapeHtml, formatNumber, metric } from '../format.js?v=20260810-1';

const state = { days: 30, device: '', point: '', data: null, initialized: false };
let context;

export const analysisView = {
  init(app) {
    context = app;
    if (state.initialized) return; state.initialized = true;
    document.querySelector('#analysis-device').addEventListener('change', async (event) => { state.device = event.target.value; state.point = ''; await loadPoints(); await load(); });
    document.querySelector('#analysis-point').addEventListener('change', (event) => { state.point = event.target.value; load(); });
    document.querySelectorAll('[data-days]').forEach((button) => button.addEventListener('click', () => { state.days = Number(button.dataset.days); document.querySelectorAll('[data-days]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button)); load(); }));
    window.addEventListener('resize', () => state.data && render());
    window.addEventListener('haccp:themechange', () => state.data && render());
    observeChartResize([
      document.querySelector('#analysis-measurements'),
      document.querySelector('#analysis-events'),
      document.querySelector('#analysis-battery'),
      document.querySelector('#analysis-connections'),
    ], () => state.data && render());
  },
  async load() {
    await ensureDevices(); await loadPoints(); await load();
  },
};

async function ensureDevices() {
  if (!context.devices.length) context.devices = (await api('/api/v1/dashboard/overview?hours=24')).devices;
  const select = document.querySelector('#analysis-device');
  select.innerHTML = '<option value="">Gesamte Flotte</option>' + context.devices.map((device) => `<option value="${escapeHtml(device.device_uid)}" ${state.device === device.device_uid ? 'selected' : ''}>${escapeHtml(device.name)}</option>`).join('');
}

async function loadPoints() {
  const select = document.querySelector('#analysis-point');
  if (!state.device) { select.innerHTML = '<option value="">Alle Messstellen</option>'; select.disabled = true; return; }
  const overview = await api(`/api/v1/dashboard/overview?hours=24&device=${encodeURIComponent(state.device)}`);
  select.disabled = false;
  select.innerHTML = '<option value="">Alle Messstellen</option>' + overview.measurement_points.map((point) => `<option value="${point.id}" ${String(point.id) === state.point ? 'selected' : ''}>${escapeHtml(point.name)}</option>`).join('');
}

async function load() {
  const params = new URLSearchParams({ days: String(state.days) });
  if (state.device) params.set('device', state.device);
  if (state.point) params.set('measurement_point_id', state.point);
  state.data = await api(`/api/v1/dashboard/analysis?${params}`);
  render();
}

function render() {
  const data = state.data;
  const availability = data.availability.length ? data.availability.reduce((sum, row) => sum + row.availability_percent, 0) / data.availability.length : null;
  document.querySelector('#analysis-metrics').innerHTML = [
    metric('Verfügbarkeit', formatNumber(availability, ' %'), `${data.fleet.devices} aktive Geräte`),
    metric('Messwerte', data.fleet.measurements, `${data.range.days} Tage`),
    metric('Offene Ereignisse', data.fleet.open_events, 'noch nicht geschlossen'),
    metric('Ablehnungen', data.fleet.rejections, 'im Zeitraum'),
  ].join('');

  lineChart(document.querySelector('#analysis-measurements'), [
    { values: data.measurements.map((row) => ({ at: row.measured_at, value: row.temperature_c })), color: chartColor('accent') },
    { values: data.measurements.map((row) => ({ at: row.measured_at, value: row.humidity_rh })), color: chartColor('humidity'), width: 1.3 },
  ]);
  accessibleTable(document.querySelector('#analysis-measurements-table'), 'Messwerte', ['Zeitpunkt', 'Gerät', 'Messstelle', 'Temperatur', 'Feuchte'], data.measurements.slice(-1000).map((row) => [row.measured_at, row.device_uid, row.point_code, row.temperature_c, row.humidity_rh]));

  const dailyEvents = aggregateEvents(data.events_by_day);
  barChart(document.querySelector('#analysis-events'), dailyEvents, { color: chartColor('warning') });
  accessibleTable(document.querySelector('#analysis-events-table'), 'Ereignisse nach Tag, Art und Schwere', ['Tag', 'Art', 'Schwere', 'Anzahl'], data.events_by_day.map((row) => [row.day, row.event_type, row.severity, row.event_count]));

  const battery = data.battery;
  document.querySelector('#battery-eta').textContent = battery.status === 'estimated' ? `≈ ${battery.estimated_days_remaining} Tage` : '–';
  document.querySelector('#battery-note').textContent = battery.status === 'estimated'
    ? `${formatNumber(battery.slope_mv_per_day, ' mV/Tag')} · Konfidenz ${({ low: 'niedrig', medium: 'mittel', high: 'hoch' })[battery.confidence]}`
    : battery.status === 'device_required' ? 'Für eine Batterieprognose bitte ein einzelnes Gerät auswählen.' : 'Noch keine belastbare Prognose. Erforderlich sind mindestens 20 Werte über sieben Tage mit fallendem Trend.';
  const batterySeries = (battery.series || []).map((row) => ({ at: row.at, value: row.mv }));
  const thresholdSeries = battery.low_threshold_mv && batterySeries.length ? batterySeries.map((row) => ({ at: row.at, value: battery.low_threshold_mv })) : [];
  lineChart(document.querySelector('#analysis-battery'), [
    { values: batterySeries, color: chartColor('warning') },
    { values: thresholdSeries, color: chartColor('danger'), width: 1.2 },
  ], { unit: ' mV' });
  accessibleTable(document.querySelector('#analysis-battery-table'), 'Batterieverlauf', ['Zeitpunkt', 'Millivolt', 'Niedrigschwelle'], (battery.series || []).map((row) => [row.at, row.mv, battery.low_threshold_mv]));

  lineChart(document.querySelector('#analysis-connections'), [
    { values: data.connections_by_day.map((row) => ({ at: `${row.day}T12:00:00Z`, value: Number(row.average_rssi_dbm) })), color: chartColor('accent') },
    { values: data.connections_by_day.map((row) => ({ at: `${row.day}T12:00:00Z`, value: Number(row.transmissions) })), color: chartColor('humidity'), width: 1.3 },
  ]);
  const availabilityRows = data.availability.map((row) => [`Verfügbarkeit ${row.name}`, `${row.transmissions}/${row.expected_transmissions}`, null, null, `${row.availability_percent} %`]);
  accessibleTable(document.querySelector('#analysis-connections-table'), 'Verbindungsqualität und Verfügbarkeit', ['Tag / Gerät', 'Übertragungen', 'RSSI dBm', 'WLAN ms', 'Ablehnungen / Verfügbarkeit'], [...data.connections_by_day.map((row) => [row.day, row.transmissions, row.average_rssi_dbm, row.average_wifi_connect_ms, row.rejected_measurements]), ...availabilityRows]);
}

function aggregateEvents(rows) {
  const totals = new Map();
  rows.forEach((row) => totals.set(row.day, (totals.get(row.day) || 0) + Number(row.event_count)));
  return [...totals.entries()].map(([label, value]) => ({ label, value }));
}
