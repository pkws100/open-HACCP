import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = (await readFile(new URL('../../public/assets/views/overview.js', import.meta.url), 'utf8'))
  .replace(/^import .*;\n/gm, '')
  .replace('export const overviewView', 'const overviewView');

function photoView(role = 'administrator') {
  const inputs = [];
  const messages = [];
  const requests = [];
  const focus = {
    markup: '',
    set innerHTML(value) {
      this.markup = value;
      inputs.length = 0;
      for (const match of value.matchAll(/<input type="file"[^>]*data-photo-upload[^>]*>/g)) {
        inputs.push({
          markup: match[0], files: [], value: '', disabled: false, listeners: {},
          addEventListener(type, handler) { this.listeners[type] = handler; },
        });
      }
    },
  };
  const sandbox = {
    FormData,
    api: async (path, options) => { requests.push({ path, options }); throw new Error('Test: kein Netzwerk'); },
    alarmLabel: (value) => value || 'normal',
    escapeHtml: (value) => String(value),
    formatDate: (value) => value || '–',
    formatNumber: (value) => String(value ?? '–'),
    powerLabel: () => 'Netzbetrieb',
    signalIcon: () => '',
    document: {
      querySelector: (selector) => selector === '#device-focus' ? focus : null,
      querySelectorAll: (selector) => selector === '[data-photo-upload]' ? inputs : [],
    },
  };
  vm.runInNewContext(`${source}\nglobalThis.photoTest = { renderFocus, state, setContext(value) { context = value; } };`, sandbox);
  const view = sandbox.photoTest;
  view.setContext({ user: { role }, showMessage: (message) => messages.push(message) });
  view.state.data = {
    selected_device: { name: 'Tiefkühlschrank', device_uid: 'device-1', battery: {}, wifi: {}, configuration_delivery: {} },
    selected_measurement_point: { id: 42, name: 'Truhe', location: 'Lager', photo: null },
    settings: { alarm: { temperature_min_c: -25, temperature_max_c: -18 } },
    kpis: {},
  };
  return { view, focus, inputs, messages, requests };
}

test('empty photo area opens a native picker and submits the selected file', async () => {
  const { view, focus, inputs, messages, requests } = photoView();
  view.renderFocus();

  assert.match(focus.markup, /<label class="focus-photo-empty photo-upload-target">[\s\S]*<input type="file" data-photo-upload[^>]*>[\s\S]*<\/label>/);
  assert.equal(inputs.length, 3);
  assert.match(inputs[1].markup, /capture="environment"/);

  const file = new File(['photo'], 'truhe.jpg', { type: 'image/jpeg' });
  inputs[0].files = [file];
  inputs[0].value = 'truhe.jpg';
  inputs[0].listeners.change({ target: inputs[0] });
  await new Promise(setImmediate);

  assert.equal(requests.length, 1);
  assert.equal(requests[0].path, '/api/v1/dashboard/measurement-points/42/photos');
  assert.equal(requests[0].options.method, 'POST');
  assert.equal(requests[0].options.body.get('photo').name, 'truhe.jpg');
  assert.equal(inputs[0].value, '');
  assert.equal(inputs[0].disabled, false);
  assert.ok(messages.some((message) => message.includes('hochgeladen')));
});

test('auditors see the photo placeholder without an upload control', () => {
  const { view, focus, inputs } = photoView('auditor');
  view.renderFocus();
  assert.match(focus.markup, /<div class="focus-photo-empty">/);
  assert.equal(inputs.length, 0);
});
