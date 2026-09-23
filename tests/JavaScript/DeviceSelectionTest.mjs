import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = (await readFile(new URL('../../public/assets/views/overview.js', import.meta.url), 'utf8'))
  .replace(/^import .*;\n/gm, '')
  .replace('export const overviewView', 'const overviewView');

function fixture(uid, name) {
  return { device_uid: uid, name, photo: null, latest_temperature_c: 4, alarm: { state: 'normal' },
    battery: {}, wifi: { bars: 3, rssi_dbm: -60 }, last_seen_at: '2026-09-24T10:00:00Z' };
}

function selectionView({ mobile = true } = {}) {
  const requests = [];
  const messages = [];
  const focusCalls = [];
  const scrollCalls = [];
  const devices = [fixture('sensor-a', 'Kühlschrank'), fixture('sensor-b', 'Tiefkühlschrank'), fixture('sensor-c', 'Lager')];
  const heading = {
    focus(options) { focusCalls.push(options); },
    scrollIntoView(options) { scrollCalls.push(options); },
  };
  const table = {
    rows: [], markup: '',
    set innerHTML(markup) {
      this.markup = markup;
      this.rows = [...markup.matchAll(/<tr data-uid="([^"]+)" class="([^"]*)">/g)].map((match) => {
        const classes = new Set(match[2].split(' ').filter(Boolean));
        const attributes = new Map();
        const button = { attributes, setAttribute(name, value) { attributes.set(name, value); } };
        const hint = { textContent: '' };
        const listeners = {};
        return {
          dataset: { uid: match[1] }, button, hint, classes,
          classList: { toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); } },
          querySelector(selector) { return selector === 'button' ? button : hint; },
          addEventListener(name, listener) { listeners[name] = listener; },
          click() { listeners.click(); },
        };
      });
    },
  };
  const sandbox = {
    URLSearchParams,
    api(path) { return new Promise((resolve, reject) => requests.push({ path, resolve, reject })); },
    alarmLabel: (value) => value,
    escapeHtml: (value) => String(value),
    formatDate: (value) => value,
    formatNumber: (value, unit = '') => `${value}${unit}`,
    powerLabel: () => 'Netzbetrieb',
    signalIcon: () => 'Signal',
    statusPill: (value) => value,
    document: {
      querySelector(selector) { return selector === '#device-table' ? table : selector === '#selected-device-heading' ? heading : null; },
      querySelectorAll(selector) { return selector === '#device-table tr[data-uid]' ? table.rows : []; },
    },
    window: { matchMedia(query) { return { matches: query.includes('max-width') ? mobile : false }; } },
  };
  vm.runInNewContext(`${source}\nrender = () => renderDevices();\nglobalThis.selectionTest = { state, renderDevices, setContext(value) { context = value; } };`, sandbox);
  const view = sandbox.selectionTest;
  view.setContext({ devices, showMessage(message) { messages.push(message); } });
  view.state.device = 'sensor-a';
  view.state.data = { devices, selection: { device_uid: 'sensor-a', measurement_point: 'temperature-1' } };
  view.renderDevices();
  return { view, table, requests, messages, focusCalls, scrollCalls, devices };
}

function response(devices, uid) {
  return { devices, selection: { device_uid: uid, measurement_point: 'temperature-1' } };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('a fleet card shows selection immediately, then reveals the chosen heading on mobile', async () => {
  const { view, table, requests, focusCalls, scrollCalls, devices } = selectionView();
  assert.match(table.markup, /<button class="device-select-button" type="button" aria-current="true" aria-label="Kühlschrank: Details und Einstellungen anzeigen">/);
  assert.match(table.markup, /Details und Einstellungen ansehen/);

  table.rows[1].click();
  assert.equal(requests.length, 1);
  assert.ok(table.rows[1].classes.has('is-selected'));
  assert.ok(table.rows[1].classes.has('is-loading'));
  assert.equal(table.rows[1].button.attributes.get('aria-current'), 'true');
  assert.equal(table.rows[1].hint.textContent, 'Wird geladen …');
  assert.equal(scrollCalls.length, 0);

  requests[0].resolve(response(devices, 'sensor-b'));
  await settle();
  assert.equal(view.state.data.selection.device_uid, 'sensor-b');
  assert.equal(focusCalls.length, 1);
  assert.equal(focusCalls[0].preventScroll, true);
  assert.equal(scrollCalls[0].block, 'start');
  assert.equal(scrollCalls[0].behavior, 'smooth');
  assert.ok(table.rows[1].classes.has('is-selected'));
  assert.ok(!table.rows[1].classes.has('is-loading'));
});

test('the newest tap wins when older requests finish later', async () => {
  const { view, table, requests, scrollCalls, devices } = selectionView();
  table.rows[1].click();
  table.rows[2].click();
  assert.equal(requests.length, 2);
  requests[1].resolve(response(devices, 'sensor-c'));
  await settle();
  requests[0].resolve(response(devices, 'sensor-b'));
  await settle();
  assert.equal(view.state.device, 'sensor-c');
  assert.equal(view.state.data.selection.device_uid, 'sensor-c');
  assert.equal(scrollCalls.length, 1);
  assert.ok(table.rows[2].classes.has('is-selected'));
});

test('tapping the current card cancels an unfinished switch and still reveals its details', async () => {
  const { view, table, requests, scrollCalls, devices } = selectionView();
  table.rows[1].click();
  table.rows[0].click();
  assert.equal(view.state.device, 'sensor-a');
  assert.equal(view.state.point, 'temperature-1');
  assert.equal(scrollCalls.length, 1);
  requests[0].resolve(response(devices, 'sensor-b'));
  await settle();
  assert.equal(view.state.data.selection.device_uid, 'sensor-a');
  assert.ok(table.rows[0].classes.has('is-selected'));
  assert.equal(scrollCalls.length, 1);
});

test('a failed selection restores the original card without scrolling', async () => {
  const { view, table, requests, messages, scrollCalls } = selectionView();
  table.rows[1].click();
  requests[0].reject(new Error('Verbindung fehlgeschlagen'));
  await settle();
  assert.equal(view.state.device, 'sensor-a');
  assert.equal(view.state.point, 'temperature-1');
  assert.ok(table.rows[0].classes.has('is-selected'));
  assert.ok(!table.rows[1].classes.has('is-loading'));
  assert.deepEqual(messages, ['Verbindung fehlgeschlagen']);
  assert.equal(scrollCalls.length, 0);
});

test('desktop selection updates details without moving the page', async () => {
  const { table, requests, focusCalls, scrollCalls, devices } = selectionView({ mobile: false });
  table.rows[1].click();
  requests[0].resolve(response(devices, 'sensor-b'));
  await settle();
  assert.equal(focusCalls.length, 0);
  assert.equal(scrollCalls.length, 0);
  assert.ok(table.rows[1].classes.has('is-selected'));
});
