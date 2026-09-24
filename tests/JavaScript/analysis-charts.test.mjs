import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const palette = {
  '--accent': '#12aa99', '--chart-humidity': '#3456ab', '--line': '#455555',
  '--muted': '#667777', '--surface': '#101a19', '--warning': '#aa8800', '--danger': '#ab2233',
};
globalThis.document = { documentElement: {} };
globalThis.getComputedStyle = () => ({ getPropertyValue: (name) => palette[name] || '#123456' });
globalThis.window = { devicePixelRatio: 2 };
const source = await readFile(new URL('../../public/assets/charts.js', import.meta.url), 'utf8');
const { metricTrendChart } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

function canvasFixture(clientWidth = 380, height = 300) {
  const strokes = [];
  const labels = [];
  const circles = [];
  let path = [];
  let dash = [];
  const context = {
    beginPath() { path = []; },
    moveTo(x, y) { path.push({ kind: 'move', x, y }); },
    lineTo(x, y) { path.push({ kind: 'line', x, y }); },
    stroke() { strokes.push({ color: this.strokeStyle, dash: [...dash], width: this.lineWidth, path: [...path] }); },
    fillText(value) { labels.push(String(value)); },
    arc(x, y, radius) { circles.push({ x, y, radius }); },
    setLineDash(value) { dash = [...value]; },
    setTransform() {}, clearRect() {}, fill() {}, save() {}, restore() {},
  };
  const canvas = {
    clientWidth,
    dataset: {},
    style: {},
    width: 0,
    height,
    getAttribute(name) { return name === 'height' ? String(height) : null; },
    getContext() { return context; },
  };
  return { canvas, strokes, labels, circles };
}

function plottedStroke(result, color) {
  return result.strokes.find((stroke) => stroke.color === color && stroke.width === 2 &&
    (stroke.path.length > 2 || stroke.path.filter(({ kind }) => kind === 'move').length > 1));
}

test('analysis trend gives RSSI and transmission counts separate labelled scales', () => {
  const fixture = canvasFixture();
  const rows = [
    { at: '2026-09-24T08:00:00Z', rssi: -65, transmissions: 0 },
    { at: '2026-09-24T08:05:00Z', rssi: -62, transmissions: 1 },
    { at: '2026-09-24T08:10:00Z', rssi: -59, transmissions: 2 },
  ];
  const geometry = metricTrendChart(fixture.canvas, rows, {
    series: [
      { key: 'rssi', label: 'Signal', unit: 'dBm', color: '#12aa99' },
      { key: 'transmissions', label: 'Übertragungen', unit: 'Anzahl', color: '#3456ab', dashed: true },
    ],
    selectedIndex: 1,
  });

  assert.ok(fixture.labels.includes('Signal · dBm'));
  assert.ok(fixture.labels.includes('Übertragungen · Anzahl'));
  assert.deepEqual(geometry.positions.map(({ index }) => index), [0, 1, 2]);
  assert.ok(plottedStroke(fixture, '#12aa99'));
  assert.ok(plottedStroke(fixture, '#3456ab').dash.length > 0);
  assert.equal(fixture.circles.filter(({ radius }) => radius === 5).length, 2);
});

test('null and NaN cannot become a false zero-dBm sample or connect across missing readings', () => {
  const fixture = canvasFixture();
  const rows = [
    { at: '2026-09-24T08:00:00Z', rssi: -65 },
    { at: '2026-09-24T08:05:00Z', rssi: null },
    { at: '2026-09-24T08:10:00Z', rssi: NaN },
    { at: '2026-09-24T08:15:00Z', rssi: -59 },
  ];
  const geometry = metricTrendChart(fixture.canvas, rows, {
    series: [{ key: 'rssi', label: 'Signal', unit: 'dBm', color: '#12aa99' }],
  });

  assert.deepEqual(geometry.positions.map(({ index }) => index), [0, 3]);
  assert.deepEqual(plottedStroke(fixture, '#12aa99').path.map(({ kind }) => kind), ['move', 'move']);
  assert.equal(fixture.labels.includes('Signal · dBm'), true);
});

test('SQL timestamps without an offset are UTC for the explicit window and row positions', () => {
  const fixture = canvasFixture(420, 260);
  const rows = [
    { measured_at: '2026-09-24T08:00:00Z', temp: 1 },
    { measured_at: '2026-09-24 08:30:00', temp: 2 },
    { measured_at: '2026-09-24T09:00:00Z', temp: 3 },
    { measured_at: '2026-09-24T10:00:00Z', temp: 4 },
  ];
  const geometry = metricTrendChart(fixture.canvas, rows, {
    timeKey: 'measured_at',
    series: [{ key: 'temp', label: 'Temperatur', unit: '°C' }],
    startAt: '2026-09-24 08:00:00',
    endAt: '2026-09-24 09:00:00',
  });

  assert.deepEqual(geometry.positions.map(({ index }) => index), [0, 1, 2]);
  assert.equal(geometry.positions[0].x, geometry.left);
  assert.equal(geometry.positions[1].x, (geometry.left + geometry.right) / 2);
  assert.equal(geometry.positions[2].x, geometry.right);
  assert.equal(geometry.width, 420);
  assert.equal(fixture.canvas.width, 840);
  assert.equal(fixture.canvas.height, 520);
});

test('SQL timestamps with an explicit offset retain that offset', () => {
  const fixture = canvasFixture();
  const geometry = metricTrendChart(fixture.canvas, [
    { at: '2026-09-24 10:30:00+02:00', value: 7 },
  ], {
    series: [{ key: 'value', label: 'Wert', unit: '°C' }],
    startAt: '2026-09-24 08:00:00Z',
    endAt: '2026-09-24 09:00:00Z',
  });

  assert.equal(geometry.positions.length, 1);
  assert.equal(geometry.positions[0].x, (geometry.left + geometry.right) / 2);
});

test('six-digit SQL fractions are normalized to ISO milliseconds', () => {
  const fixture = canvasFixture();
  const geometry = metricTrendChart(fixture.canvas, [
    { at: '2026-09-24 08:00:00.500000', value: 7 },
  ], {
    series: [{ key: 'value', label: 'Wert', unit: '°C' }],
    startAt: '2026-09-24 08:00:00.000000',
    endAt: '2026-09-24 08:00:01.000000',
  });

  assert.equal(geometry.positions.length, 1);
  assert.equal(geometry.positions[0].x, (geometry.left + geometry.right) / 2);
});

test('a battery reference is dashed and included in the voltage scale', () => {
  const fixture = canvasFixture();
  metricTrendChart(fixture.canvas, [
    { at: '2026-09-24T08:00:00Z', mv: 3800 },
    { at: '2026-09-24T08:05:00Z', mv: 3900 },
  ], {
    series: [{
      key: 'mv', label: 'Spannung', unit: 'mV', color: '#aa8800',
      reference: { value: 3400, color: '#ab2233', dashed: true },
    }],
  });

  const reference = fixture.strokes.find((stroke) => stroke.color === '#ab2233');
  const gridY = fixture.strokes.filter((stroke) => stroke.color === '#455555')
    .map((stroke) => stroke.path[0]?.y).filter(Number.isFinite);
  assert.ok(reference);
  assert.deepEqual(reference.dash, [4, 4]);
  assert.equal(reference.path.length, 2);
  assert.equal(reference.path[0].y, reference.path[1].y);
  assert.ok(reference.path[0].y >= Math.min(...gridY));
  assert.ok(reference.path[0].y <= Math.max(...gridY));
  assert.ok(fixture.strokes.some((stroke) => stroke.color === '#aa8800' && stroke.width === 2 &&
    stroke.path.length === 2 && stroke.path[0].y !== stroke.path[1].y));
});

test('long outages break the path and day-scale time ticks include dates', () => {
  const fixture = canvasFixture();
  const rows = [
    { at: '2026-09-23T08:00:00Z', temperature: 1 },
    { at: '2026-09-23T08:05:00Z', temperature: 2 },
    { at: '2026-09-23T08:10:00Z', temperature: 3 },
    { at: '2026-09-23T08:30:00Z', temperature: 4 },
  ];
  metricTrendChart(fixture.canvas, rows, {
    series: [{ key: 'temperature', label: 'Temperatur', unit: '°C', color: '#12aa99' }],
    startAt: '2026-09-23T08:00:00Z',
    endAt: '2026-09-24T08:00:00Z',
  });

  assert.deepEqual(plottedStroke(fixture, '#12aa99').path.map(({ kind }) => kind),
    ['move', 'line', 'line', 'move']);
  assert.equal(fixture.labels.filter((label) => /^\d{2}\.\d{2}\.?$/.test(label)).length, 3);
  assert.equal(fixture.labels.filter((label) => /^\d{2}:\d{2}$/.test(label)).length, 3);
});
