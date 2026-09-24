import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = (await readFile(new URL('../../public/assets/views/overview.js', import.meta.url), 'utf8'))
  .replace(/^import .*;\n/gm, '')
  .replace('export const overviewView', 'const overviewView');

function element() {
  const listeners = new Map();
  const attributes = new Map();
  return {
    hidden: false, disabled: false, textContent: '', innerHTML: '', attributes,
    addEventListener(name, listener) { listeners.set(name, listener); },
    dispatch(name, event = {}) { listeners.get(name)?.(event); },
    setAttribute(name, value) { attributes.set(name, value); },
    classList: { toggle() {} },
  };
}

function measurement(sequence) {
  return { sequence, measured_at: '2026-09-24T10:00:00Z', temperature_c: 4,
    humidity_rh: 62, battery_mv: null };
}

function page(pageNumber, total = 40, snapshotId = 41) {
  return { page: pageNumber, per_page: 25, total,
    total_pages: Math.ceil(total / 25), has_previous: pageNumber > 1,
    has_next: pageNumber < Math.ceil(total / 25), snapshot_id: snapshotId };
}

function fixture() {
  const requests = [];
  const messages = [];
  const focusCalls = [];
  const scrollCalls = [];
  const elements = new Map();
  for (const id of ['recent-table', 'recent-pagination', 'recent-page-status', 'recent-prev', 'recent-next',
    'overview-refresh', 'overview-device', 'overview-point', 'add-device', 'overview-chart', 'chart-latest']) {
    elements.set(`#${id}`, element());
  }
  elements.set('#recent-heading', {
    focus(options) { focusCalls.push(options); },
    scrollIntoView(options) { scrollCalls.push(options); },
  });
  const hours = [6, 24, 72, 168].map((value) => ({ ...element(), dataset: { hours: String(value) } }));
  const sandbox = {
    URLSearchParams,
    api(path) { return new Promise((resolve, reject) => requests.push({ path, resolve, reject })); },
    formatDate: (value) => value,
    formatNumber: (value, unit = '') => `${value}${unit}`,
    observeChartResize() {},
    document: {
      querySelector(selector) { return elements.get(selector) || null; },
      querySelectorAll(selector) { return selector === '[data-hours]' ? hours : []; },
    },
    window: { addEventListener() {}, matchMedia() { return { matches: false }; } },
  };
  vm.runInNewContext(`${source}\nrender = () => renderRecent();\nglobalThis.paginationTest = {
    state, overviewView, changeRecentPage, renderRecent, setContext(value) { context = value; }
  };`, sandbox);
  const view = sandbox.paginationTest;
  view.setContext({ showMessage(message) { messages.push(message); } });
  view.state.device = 'sensor-a';
  view.state.point = 'temperature-1';
  view.state.data = {
    selection: { device_uid: 'sensor-a', measurement_point: 'temperature-1' },
    selected_device: { battery: { power_source: 'mains' } },
    recent_measurements: Array.from({ length: 25 }, (_, index) => measurement(41 - index)),
    recent_pagination: page(1),
  };
  view.renderRecent();
  return { view, elements, requests, messages, hours, focusCalls, scrollCalls };
}

test('next page uses the lightweight endpoint and a stable snapshot without replacing overview data', async () => {
  const { view, elements, requests, focusCalls, scrollCalls } = fixture();
  const selectedDevice = view.state.data.selected_device;
  const nav = elements.get('#recent-pagination');
  assert.equal(elements.get('#recent-page-status').textContent, 'Seite 1 von 2 · 1–25 von 40 Messwerten');
  assert.equal(elements.get('#recent-prev').disabled, true);
  assert.equal(elements.get('#recent-next').disabled, false);

  const pending = view.changeRecentPage(1);
  assert.equal(requests.length, 1);
  assert.equal(requests[0].path, '/api/v1/dashboard/overview?recent_only=1&recent_page=2&device=sensor-a&point=temperature-1&recent_snapshot_id=41');
  assert.equal(nav.attributes.get('aria-busy'), 'true');
  assert.equal(elements.get('#recent-next').disabled, true);
  requests[0].resolve({ selection: { device_uid: 'sensor-a', measurement_point: 'temperature-1' },
    recent_measurements: Array.from({ length: 15 }, (_, index) => measurement(16 - index)),
    recent_pagination: page(2) });
  await pending;
  assert.equal(view.state.data.selected_device, selectedDevice);
  assert.equal(view.state.recentPage, 2);
  assert.equal(elements.get('#recent-page-status').textContent, 'Seite 2 von 2 · 26–40 von 40 Messwerten');
  assert.equal(elements.get('#recent-prev').disabled, false);
  assert.equal(elements.get('#recent-next').disabled, true);
  assert.equal(nav.attributes.get('aria-busy'), 'false');
  assert.match(elements.get('#recent-table').innerHTML, /<td data-label="Sequenz">16<\/td>/);
  assert.equal(focusCalls.length, 1);
  assert.equal(focusCalls[0].preventScroll, true);
  assert.equal(scrollCalls.length, 1);
  assert.equal(scrollCalls[0].behavior, 'smooth');
  assert.equal(scrollCalls[0].block, 'start');
});

test('duplicate taps, bounds and failed requests retain the current page', async () => {
  const { view, requests, messages, elements, scrollCalls } = fixture();
  await view.changeRecentPage(-1);
  assert.equal(requests.length, 0);
  const first = view.changeRecentPage(1);
  await view.changeRecentPage(1);
  assert.equal(requests.length, 1);
  requests[0].reject(new Error('Verbindung fehlgeschlagen'));
  await first;
  assert.equal(view.state.recentPage, 1);
  assert.deepEqual(messages, ['Verbindung fehlgeschlagen']);
  assert.equal(elements.get('#recent-next').disabled, false);
  assert.equal(scrollCalls.length, 0);
});

test('an unexpected backend selection cannot replace the current sensor history', async () => {
  const { view, requests, messages, elements, scrollCalls } = fixture();
  const originalTable = elements.get('#recent-table').innerHTML;
  const pending = view.changeRecentPage(1);
  requests[0].resolve({ selection: { device_uid: 'sensor-b', measurement_point: 'temperature-1' },
    recent_measurements: [measurement(999)], recent_pagination: page(2) });
  await pending;
  assert.equal(view.state.recentPage, 1);
  assert.equal(elements.get('#recent-table').innerHTML, originalTable);
  assert.deepEqual(messages, ['Messwerte konnten nicht geladen werden.']);
  assert.equal(scrollCalls.length, 0);
});

test('a device refresh invalidates a slow page response and clears the old snapshot', async () => {
  const { view, requests, elements } = fixture();
  const stale = view.changeRecentPage(1);
  view.state.device = 'sensor-b';
  view.state.recentPage = 1;
  const fresh = view.overviewView.load();
  assert.match(requests[1].path, /device=sensor-b/);
  assert.match(requests[1].path, /recent_page=1/);
  assert.doesNotMatch(requests[1].path, /recent_snapshot_id/);
  requests[1].resolve({ devices: [], selection: { device_uid: 'sensor-b', measurement_point: 'temperature-1' },
    selected_device: { battery: { power_source: 'mains' } }, recent_measurements: [measurement(100)],
    recent_pagination: page(1, 1, 100) });
  await fresh;
  requests[0].resolve({ selection: { device_uid: 'sensor-a', measurement_point: 'temperature-1' },
    recent_measurements: [measurement(16)], recent_pagination: page(2) });
  await stale;
  assert.equal(view.state.device, 'sensor-b');
  assert.equal(view.state.recentPage, 1);
  assert.match(elements.get('#recent-table').innerHTML, /<td data-label="Sequenz">100<\/td>/);
  assert.doesNotMatch(elements.get('#recent-table').innerHTML, /<td data-label="Sequenz">16<\/td>/);
});

test('device, point and chart-window changes request the first measurement page', () => {
  const { view, requests, elements, hours } = fixture();
  view.overviewView.init({ showMessage() {} });
  view.state.recentPage = 2;
  elements.get('#overview-device').dispatch('change', { target: { value: 'sensor-b' } });
  assert.equal(view.state.recentPage, 1);
  assert.match(requests[0].path, /device=sensor-b/);
  assert.match(requests[0].path, /recent_page=1/);

  view.state.recentPage = 2;
  elements.get('#overview-point').dispatch('change', { target: { value: 'temperature-2' } });
  assert.equal(view.state.recentPage, 1);
  assert.match(requests[1].path, /point=temperature-2/);
  assert.match(requests[1].path, /recent_page=1/);

  view.state.recentPage = 2;
  hours[0].dispatch('click');
  assert.equal(view.state.recentPage, 1);
  assert.match(requests[2].path, /hours=6/);
  assert.match(requests[2].path, /recent_page=1/);

  view.state.recentPage = 2;
  elements.get('#overview-refresh').dispatch('click');
  assert.equal(view.state.recentPage, 1);
  assert.match(requests[3].path, /recent_page=1/);
});
