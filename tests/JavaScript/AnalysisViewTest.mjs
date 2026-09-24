import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = (await readFile(new URL('../../public/assets/views/analysis.js', import.meta.url), 'utf8'))
  .replace(/^import .*;\n/gm, '')
  .replace('export const analysisView', 'const analysisView');

function element(id = '') {
  const listeners = new Map();
  const attributes = new Map();
  return {
    id, hidden: false, disabled: false, value: '', textContent: '', innerHTML: '', tabIndex: 0,
    clientWidth: 500, dataset: {}, attributes, children: [], lastChild: null,
    classList: { toggle() {} },
    addEventListener(name, callback) { listeners.set(name, callback); },
    dispatch(name, event = {}) { return listeners.get(name)?.(event); },
    setAttribute(name, value) { attributes.set(name, value); },
    replaceChildren(...children) { this.children = children; },
    get selectedOptions() { return this.children.filter((child) => child.selected); },
    getBoundingClientRect() { return { left: 0, width: 500 }; },
  };
}

function response(overrides = {}) {
  return {
    range: { days: 30, from: '2026-09-23 00:00:00', to: '2026-09-24 23:59:59' },
    fleet: { devices: 2, measurements: 0, open_events: 0, rejections: 0 },
    availability: [], measurements: [], measurements_sampling: { sampled: false },
    events_by_day: [], battery: { status: 'device_required', series: [] }, connections_by_day: [],
    ...overrides,
  };
}

function sample(device_uid, point_code, minute, temperature_c = 4) {
  return { device_uid, point_code, measured_at: `2026-09-24T08:${String(minute).padStart(2, '0')}:00Z`,
    temperature_c, humidity_rh: 61 };
}

function fixture() {
  const elements = new Map();
  const requests = [];
  const messages = [];
  const chartCalls = [];
  const ids = [...source.matchAll(/querySelector\('(#[\w-]+)'\)/g)].map((match) => match[1].slice(1));
  for (const id of new Set([...ids, ...['measurements', 'events', 'battery', 'connections'].flatMap((key) => [
    `analysis-${key}`, `analysis-${key}-time`, `analysis-${key}-day`, `analysis-${key}-count`,
    `analysis-${key}-temperature`, `analysis-${key}-humidity`, `analysis-${key}-rssi`,
    `analysis-${key}-value`,
  ])])) elements.set('#' + id, element(id));
  elements.get('#analysis-battery-threshold-legend').lastChild = element();
  const days = [7, 30, 90].map((value) => Object.assign(element(), { dataset: { days: String(value) } }));
  const toggles = ['temperature', 'humidity', 'rssi', 'transmissions'].map((value) =>
    Object.assign(element(), { dataset: { analysisToggle: value } }));
  const latest = ['measurements', 'events', 'battery', 'connections'].map((value) =>
    Object.assign(element(), { dataset: { analysisLatest: value }, hidden: true }));
  const byLatest = new Map(latest.map((button) => [button.dataset.analysisLatest, button]));
  const sandbox = {
    URLSearchParams,
    api(path) {
      if (path.startsWith('/api/v1/dashboard/overview?')) {
        return Promise.resolve({ measurement_points: [{ id: 11, name: 'Punkt A' }] });
      }
      return new Promise((resolve, reject) => requests.push({ path, resolve, reject }));
    },
    accessibleTable(container, _caption, _headers, rows) { container.innerHTML = JSON.stringify(rows); },
    chartColor(key) { return key; },
    eventStackChart(_canvas, rows, options) {
      chartCalls.push({ key: 'events', rows, options });
      const days = rows.map((row) => ({ day: row.day, warning: row.severity === 'warning' ? row.event_count : 0,
        critical: row.severity === 'critical' ? row.event_count : 0, total: row.event_count }));
      return { days, positions: days.map((row, index) => ({ index, x: 50 + index * 100 })),
        left: 50, right: 450, width: 500, height: 300 };
    },
    metricTrendChart(canvas, rows, options) {
      chartCalls.push({ key: canvas.id, rows, options });
      const positions = rows.flatMap((row, index) => options.series.some((series) =>
        row[series.key] != null && Number.isFinite(Number(row[series.key]))) ? [{ index, x: 50 + index * 100 }] : []);
      return { positions, left: 50, right: 450, width: 500, height: 300 };
    },
    observeChartResize() {},
    escapeHtml(value) { return String(value ?? ''); },
    eventLabel(value) { return value; },
    formatDate(value) { return value; },
    formatNumber(value, unit = '') { return value == null ? '–' : String(value) + unit; },
    metric(label, value, note) { return `<metric label="${label}">${value} · ${note}</metric>`; },
    document: {
      createElement(tag) { return element(tag); },
      querySelector(selector) {
        const latestMatch = /^\[data-analysis-latest="(\w+)"\]$/.exec(selector);
        return latestMatch ? byLatest.get(latestMatch[1]) : elements.get(selector) || null;
      },
      querySelectorAll(selector) {
        return { '[data-days]': days, '[data-analysis-toggle]': toggles,
          '[data-analysis-latest]': latest }[selector] || [];
      },
    },
    window: { addEventListener() {} },
  };
  vm.runInNewContext(`${source}\nglobalThis.analysisTest = {
    analysisView, state, charts, render, renderConnections, connectionDays, setContext(value) { context = value; }
  };`, sandbox);
  const view = sandbox.analysisTest;
  const context = { devices: [
    { device_uid: 'device-a', name: 'Kühlschrank' },
    { device_uid: 'device-b', name: 'Tiefkühlschrank' },
  ], showMessage(message) { messages.push(message); } };
  view.setContext(context);
  view.analysisView.init(context);
  return { view, elements, requests, messages, chartCalls, days, toggles, latest };
}

function lastChart(calls, key) {
  return calls.filter((call) => call.key === key).at(-1);
}

test('a slower analysis response cannot overwrite a newer timeframe', async () => {
  const { view, requests, days, elements } = fixture();
  const oldLoad = view.analysisView.load();
  await new Promise(setImmediate);
  assert.equal(requests.length, 1);
  assert.match(requests[0].path, /days=30/);

  days[0].dispatch('click');
  assert.equal(requests.length, 2);
  assert.match(requests[1].path, /days=7/);
  const newer = response({ range: { days: 7, from: '2026-09-17 00:00:00', to: '2026-09-24 23:59:59' },
    fleet: { devices: 2, measurements: 7, open_events: 0, rejections: 0 } });
  requests[1].resolve(newer);
  await new Promise(setImmediate);
  requests[0].resolve(response({ fleet: { devices: 2, measurements: 30, open_events: 0, rejections: 0 } }));
  await oldLoad;
  assert.equal(view.state.data, newer);
  assert.match(elements.get('#analysis-metrics').innerHTML, /7 Tage/);
  assert.doesNotMatch(elements.get('#analysis-metrics').innerHTML, /30 Tage/);
});

test('a late device-wide response cannot replace a newer measurement-point filter', async () => {
  const { view, elements, requests } = fixture();
  const deviceChange = elements.get('#analysis-device').dispatch('change', { target: { value: 'device-a' } });
  await new Promise(setImmediate);
  assert.equal(requests.length, 1);
  assert.match(requests[0].path, /device=device-a/);
  assert.doesNotMatch(requests[0].path, /measurement_point_id/);

  elements.get('#analysis-point').dispatch('change', { target: { value: '11' } });
  assert.equal(requests.length, 2);
  assert.match(requests[1].path, /device=device-a/);
  assert.match(requests[1].path, /measurement_point_id=11/);
  const pointData = response({ fleet: { devices: 1, measurements: 1, open_events: 0, rejections: 0 } });
  requests[1].resolve(pointData);
  await new Promise(setImmediate);
  requests[0].resolve(response({ fleet: { devices: 1, measurements: 99, open_events: 0, rejections: 0 } }));
  await deviceChange;
  assert.equal(view.state.data, pointData);
  assert.equal(view.state.point, '11');
  assert.match(elements.get('#analysis-metrics').innerHTML, /Messwerte">1/);
});

test('fleet trend draws one device and one measurement point at a time', () => {
  const { view, elements, chartCalls } = fixture();
  view.state.data = response({ measurements: [
    sample('device-a', 'point-1', 0, 1), sample('device-a', 'point-2', 5, 2),
    sample('device-b', 'point-1', 10, 3), sample('device-b', 'point-2', 15, 4),
  ] });
  view.render();
  assert.deepEqual(Array.from(lastChart(chartCalls, 'analysis-measurements').rows, (row) => row.temperature_c), [2]);
  assert.match(elements.get('#analysis-measurements-note').textContent, /Kühlschrank/);

  elements.get('#analysis-chart-device').dispatch('change', { target: { value: 'device-b' } });
  assert.deepEqual(Array.from(lastChart(chartCalls, 'analysis-measurements').rows, (row) => row.temperature_c), [4]);
  elements.get('#analysis-chart-point').dispatch('change', { target: { value: 'point-1' } });
  assert.deepEqual(Array.from(lastChart(chartCalls, 'analysis-measurements').rows, (row) => row.temperature_c), [3]);
  assert.match(elements.get('#analysis-measurements-note').textContent, /Tiefkühlschrank/);
});

test('missing RSSI remains null while a zero-transmission day remains zero', () => {
  const { view, elements, chartCalls } = fixture();
  view.state.data = response({ connections_by_day: [
    { day: '2026-09-24', average_rssi_dbm: null, transmissions: 0,
      average_wifi_connect_ms: null, rejected_measurements: 0 },
  ] });
  view.renderConnections();
  const plotted = lastChart(chartCalls, 'analysis-connections');
  assert.equal(plotted.rows[0].rssi, null);
  assert.equal(plotted.rows[0].transmissions, 0);
  assert.equal(plotted.rows[1].rssi, null);
  assert.equal(plotted.rows[1].transmissions, 0);
  assert.equal(elements.get('#analysis-connections-rssi').textContent, 'Ø Funksignal nicht gemeldet');
  assert.equal(elements.get('#analysis-connections-count').textContent, 'Übertragungen 0');
  assert.deepEqual(Array.from(plotted.options.series, (series) => series.unit), ['dBm', 'Anzahl/Tag']);
});

test('no measured battery voltage shows an honest empty status instead of a graph', () => {
  const { view, elements, chartCalls } = fixture();
  view.state.data = response({ battery: { status: 'unavailable', series: [] } });
  view.render();
  assert.equal(elements.get('#analysis-battery').hidden, true);
  assert.equal(elements.get('#analysis-battery').tabIndex, -1);
  assert.equal(elements.get('#analysis-battery').attributes.get('aria-disabled'), 'true');
  assert.equal(elements.get('#analysis-battery-empty').hidden, false);
  assert.match(elements.get('#analysis-battery-empty').textContent, /keine gemessene Batteriespannung/);
  assert.equal(chartCalls.some((call) => call.key === 'analysis-battery'), false);
});

test('arrow keys and pointer selection update the accessible measurement readout', () => {
  const { view, elements } = fixture();
  view.state.data = response({ measurements: [
    sample('device-a', 'point-1', 0, 1), sample('device-a', 'point-1', 5, 2),
    sample('device-a', 'point-1', 10, 3),
  ] });
  view.render();
  const canvas = elements.get('#analysis-measurements');
  assert.equal(canvas.attributes.get('aria-valuenow'), '3');
  let prevented = false;
  canvas.dispatch('keydown', { key: 'ArrowLeft', preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  assert.equal(canvas.attributes.get('aria-valuenow'), '2');
  assert.match(elements.get('#analysis-measurements-time').textContent, /08:05/);
  assert.equal(canvas.attributes.get('aria-valuetext').includes('Temperatur 2 °C'), true);

  canvas.dispatch('pointerdown', { currentTarget: canvas, clientX: 50, pointerType: 'touch', buttons: 1 });
  assert.equal(canvas.attributes.get('aria-valuenow'), '1');
  assert.equal(elements.get('#analysis-measurements-time').textContent.includes('08:00'), true);
  canvas.dispatch('keydown', { key: 'Escape', preventDefault() {} });
  assert.equal(canvas.attributes.get('aria-valuenow'), '3');
  assert.equal(elements.get('#analysis-measurements-time').textContent.includes('08:10'), true);
});

test('calibrated temperatures retain three decimals in the visible and accessible readout', () => {
  const { view, elements } = fixture();
  view.state.data = response({ measurements: [sample('device-a', 'point-1', 0, 4.125)] });
  view.render();
  assert.equal(elements.get('#analysis-measurements-temperature').textContent, 'Temperatur 4,125 °C');
  assert.match(elements.get('#analysis-measurements').attributes.get('aria-valuetext'), /4,125 °C/);
});
