import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = (await readFile(new URL('../../public/assets/views/overview.js', import.meta.url), 'utf8'))
  .replace(/^import .*;\n/gm, '')
  .replace('export const overviewView', 'const overviewView');

function calibrationView({ configured = true } = {}) {
  const requests = [];
  const messages = [];
  const focus = { innerHTML: '' };
  const recent = { innerHTML: '' };
  const form = {
    values: {}, listeners: {},
    addEventListener(name, listener) { this.listeners[name] = listener; },
    querySelector() { return { outerHTML: '' }; },
  };
  const root = {
    querySelector(selector) { return selector === 'form' ? form : { addEventListener() {} }; },
  };
  let dialog;
  const sandbox = {
    Intl,
    FormData: class { constructor(currentForm) { this.values = currentForm.values; } get(key) { return this.values[key] ?? null; } },
    api: async (path, options) => { requests.push({ path, options }); return {}; },
    alarmLabel: (value) => value || 'Im Bereich',
    escapeHtml: (value) => String(value ?? ''),
    formatDate: (value) => value || '–',
    formatNumber: (value, suffix = '') => value == null ? '–' : `${value}${suffix}`,
    powerLabel: () => 'Netzbetrieb',
    signalIcon: () => '',
    openDialog(config) { dialog = config; config.onOpen(root); },
    closeDialog() {},
    errorMessage: (error) => error.message,
    document: {
      querySelector(selector) { return selector === '#device-focus' ? focus : selector === '#recent-table' ? recent : null; },
      querySelectorAll() { return []; },
    },
  };
  vm.runInNewContext(`${source}\nload = async () => {};\nglobalThis.calibrationTest = {
    state, settingsDialog, renderFocus, renderRecent, setContext(value) { context = value; }
  };`, sandbox);
  const view = sandbox.calibrationTest;
  view.setContext({ user: { role: 'administrator' }, showMessage(message) { messages.push(message); } });
  view.state.device = 'freezer';
  view.state.data = {
    selected_device: { device_uid: 'freezer', name: 'Tiefkühlschrank', battery: { power_source: 'mains' }, wifi: {}, configuration_delivery: {} },
    selected_measurement_point: { id: 5, code: 'temperature-1', name: 'Truhe', sensor_type: 'DHT22', location: 'Lager', photo: null },
    kpis: { latest_temperature_c: -21.375, latest_raw_temperature_c: -18.25,
      latest_temperature_offset_c: -3.125, alarm_status: 'normal' },
    recent_measurements: [{ sequence: 18, measured_at: '2026-09-24T10:00:00Z',
      temperature_c: -21.375, raw_temperature_c: -18.25, temperature_offset_c: -3.125,
      humidity_rh: 55, battery_mv: null }],
    settings: {
      config_version: 4,
      alarm: { enabled: true, temperature_min_c: -25, temperature_max_c: -18 },
      battery: { low_threshold_mv: 5600, full_threshold_mv: 6000 },
      schedule: { default_measurement_interval_seconds: 300, upload_interval_seconds: 21600,
        measurement_points: [
          { measurement_point: 'temperature-1', interval_seconds: 300 },
          { measurement_point: 'temperature-2', interval_seconds: 600 },
        ] },
      ...(configured ? { calibration: { measurement_points: [
        { measurement_point: 'temperature-1', temperature_offset_c: -3.125 },
        { measurement_point: 'temperature-2', temperature_offset_c: 0.001 },
      ] } } : {}),
    },
  };
  return { view, focus, recent, form, requests, messages, getDialog: () => dialog };
}

test('per-point offsets and their sign are shown, then submitted with the other versioned settings', async () => {
  const { view, form, requests, messages, getDialog } = calibrationView();
  view.settingsDialog();
  const html = getDialog().html;
  assert.match(html, /<legend>Temperaturabgleich<\/legend>/);
  assert.match(html, /Rohwert \+ Abgleich/);
  assert.match(html, /3 °C zu viel, geben Sie −3 °C ein/);
  assert.match(html, /Lufttemperatur direkt neben dem DHT22/);
  assert.match(html, /nicht mit einer Infrarot-Oberflächentemperatur/);
  assert.match(html, /Messstelle temperature-1 · Abgleich °C/);
  assert.match(html, /name="calibration:temperature-1" type="number" step="0\.001" min="-10" max="10" value="-3\.125"/);
  assert.match(html, /name="calibration:temperature-2" type="number" step="0\.001" min="-10" max="10" value="0\.001"/);

  form.values = {
    enabled: 'true', min: '-25', max: '-18', low: '5600', full: '6000',
    'default-interval': '5', 'upload-interval-seconds': '21600',
    'point:temperature-1': '5', 'point:temperature-2': '10',
    'calibration:temperature-1': '-3.125', 'calibration:temperature-2': '0.001',
  };
  await form.listeners.submit({ preventDefault() {}, currentTarget: form });
  assert.equal(requests.length, 1);
  assert.equal(requests[0].path, '/api/v1/dashboard/devices/freezer/settings');
  assert.equal(requests[0].options.body.expected_config_version, 4);
  assert.equal(requests[0].options.body.schedule.upload_interval_seconds, 21600);
  assert.equal(requests[0].options.body.calibration.measurement_points[0].measurement_point, 'temperature-1');
  assert.equal(requests[0].options.body.calibration.measurement_points[0].temperature_offset_c, -3.125);
  assert.equal(requests[0].options.body.calibration.measurement_points[1].temperature_offset_c, 0.001);
  assert.match(messages[0], /Neue Messungen nutzen den Abgleich/);
});

test('corrected temperature stays primary while raw value and offset remain visible', () => {
  const { view, focus, recent } = calibrationView();
  view.renderFocus();
  view.renderRecent();
  assert.match(focus.innerHTML, /<strong>-21,375 °C<\/strong>/);
  assert.match(focus.innerHTML, /Korrigiert · Rohwert -18,25 °C · Abgleich -3,125 °C/);
  assert.match(recent.innerHTML, /<strong>-21,375 °C<\/strong>/);
  assert.match(recent.innerHTML, /Korrigiert · Rohwert -18,25 °C · Abgleich -3,125 °C/);
});

test('rows without an applied offset do not claim to be corrected', () => {
  const { view, focus, recent } = calibrationView();
  view.state.data.kpis.latest_temperature_c = -18.25;
  view.state.data.kpis.latest_temperature_offset_c = 0;
  view.state.data.recent_measurements[0].temperature_c = -18.25;
  view.state.data.recent_measurements[0].temperature_offset_c = 0;
  view.renderFocus();
  view.renderRecent();
  assert.doesNotMatch(focus.innerHTML, /Korrigiert · Rohwert/);
  assert.doesNotMatch(recent.innerHTML, /Korrigiert · Rohwert/);
  assert.match(recent.innerHTML, /<strong>-18,25 °C<\/strong>/);
});

test('older settings without calibration offer zero offsets for each point', () => {
  const { view, getDialog } = calibrationView({ configured: false });
  view.settingsDialog();
  assert.match(getDialog().html, /name="calibration:temperature-1"[^>]*value="0"/);
  assert.match(getDialog().html, /name="calibration:temperature-2"[^>]*value="0"/);
});

test('the reference-thermometer note names an SHT45 device correctly', () => {
  const { view, getDialog } = calibrationView();
  view.state.data.selected_measurement_point.sensor_type = 'SHT45';
  view.settingsDialog();
  assert.match(getDialog().html, /Lufttemperatur direkt neben dem SHT45/);
  assert.doesNotMatch(getDialog().html, /neben dem DHT22/);
});
