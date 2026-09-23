import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../public/assets/format.js', import.meta.url), 'utf8');
const { powerLabel } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

test('power label distinguishes an unmonitored battery from mains and measured voltage', () => {
  assert.equal(powerLabel({ power_source: 'battery_unmonitored', millivolts: null }),
    'Batteriebetrieb · Batteriewert nicht verfügbar');
  assert.equal(powerLabel({ power_source: 'mains', millivolts: null }),
    'Netzbetrieb · Batteriewert nicht verfügbar');
  assert.match(powerLabel({ power_source: 'battery', state: 'full', millivolts: 6100 }), /6.100 mV/);
});
