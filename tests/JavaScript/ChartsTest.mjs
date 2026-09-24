import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

globalThis.document = { documentElement: {} };
const colors = {
  '--accent': '#12aa99', '--chart-humidity': '#3456ab', '--warning': '#aa8800',
  '--danger': '#ab2233', '--line': '#455555', '--muted': '#667777', '--surface': '#101a19',
};
globalThis.getComputedStyle = () => ({ getPropertyValue: (name) => colors[name] || '#123456' });
globalThis.window = {
  cancelAnimationFrame() {},
  devicePixelRatio: 2,
  requestAnimationFrame(callback) { callback(); return 1; },
};

const source = await readFile(new URL('../../public/assets/charts.js', import.meta.url), 'utf8');
const charts = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

function fakeCanvas(configuredHeight = 340) {
  const attributes = new Map([['height', String(configuredHeight)]]);
  const strokes = [];
  const labels = [];
  const circles = [];
  let path = [];
  let dash = [];
  const context = {
    beginPath() { path = []; }, clearRect() {},
    fillText(value) { labels.push(String(value)); },
    lineTo(x, y) { path.push({ type: 'lineTo', x, y }); },
    moveTo(x, y) { path.push({ type: 'moveTo', x, y }); },
    arc(x, y, radius) { circles.push({ x, y, radius, color: this.strokeStyle }); },
    fill() {}, save() {}, restore() {}, setTransform() {},
    setLineDash(value) { dash = [...value]; },
    stroke() { strokes.push({ color: this.strokeStyle, width: this.lineWidth, dash: [...dash], path: [...path] }); },
  };
  let width = 300;
  let height = configuredHeight;

  return {
    attributes, strokes, labels, circles,
    canvas: {
      clientWidth: 600,
      dataset: {},
      get width() { return width; },
      set width(value) { width = value; attributes.set('width', String(value)); },
      get height() { return height; },
      set height(value) { height = value; attributes.set('height', String(value)); },
      getAttribute(name) { return attributes.get(name) ?? null; },
      getContext() { return context; },
      parentElement: { id: 'chart-stage' },
      style: {},
    },
  };
}

function sensorLines(strokes, color) {
  return strokes.filter((stroke) => stroke.color === color && stroke.width === 2 &&
    (stroke.path.length > 2 || stroke.path.filter(({ type }) => type === 'moveTo').length > 1));
}

test('canvas backing store keeps the configured CSS height across repeated renders', () => {
  const { canvas } = fakeCanvas();
  const series = [{ values: [
    { at: '2026-08-12T04:00:00Z', value: 4.2 },
    { at: '2026-08-12T05:00:00Z', value: 4.6 },
  ] }];

  charts.lineChart(canvas, series);
  charts.lineChart(canvas, series);

  assert.equal(canvas.dataset.chartHeight, '340');
  assert.equal(canvas.style.height, '340px');
  assert.equal(canvas.height, 680);

  canvas.clientWidth = 480;
  charts.lineChart(canvas, series);

  assert.equal(canvas.width, 960);
  assert.equal(canvas.height, 680);
  assert.equal(canvas.style.height, '340px');
});

test('resize observation targets the stable chart container', () => {
  const { canvas } = fakeCanvas();
  const observed = [];
  class ResizeObserverStub {
    constructor(callback) { this.callback = callback; }
    observe(element) { observed.push(element); }
  }
  globalThis.ResizeObserver = ResizeObserverStub;
  window.ResizeObserver = ResizeObserverStub;

  const observer = charts.observeChartResize([canvas], () => {});

  assert.ok(observer instanceof ResizeObserverStub);
  assert.deepEqual(observed, [canvas.parentElement]);
});

test('sensor trend has separate labelled scales and a shared clock axis', () => {
  const { canvas, labels, strokes } = fakeCanvas();
  const rows = [
    { measured_at: '2026-09-24T08:00:00Z', temperature_c: -20, humidity_rh: 44 },
    { measured_at: '2026-09-24T08:30:00Z', temperature_c: -19, humidity_rh: 45 },
    { measured_at: '2026-09-24T09:00:00Z', temperature_c: -18, humidity_rh: 46 },
  ];
  const geometry = charts.sensorTrendChart(canvas, rows);

  assert.ok(labels.includes('Temperatur · °C'));
  assert.ok(labels.includes('Luftfeuchtigkeit · % rF'));
  assert.ok(labels.filter((label) => /^\d{2}:\d{2}$/.test(label)).length >= 3);
  assert.deepEqual(geometry.positions.map(({ index }) => index), [0, 1, 2]);
  assert.ok(geometry.positions[0].x >= geometry.left);
  assert.ok(geometry.positions[2].x <= geometry.right);
  assert.ok(sensorLines(strokes, colors['--accent']).length > 0);
  assert.ok(sensorLines(strokes, colors['--chart-humidity']).some((line) => line.dash.length > 0));
});

test('sensor trend treats zero as a reading but never invents zero for missing or NaN values', () => {
  const { canvas, strokes } = fakeCanvas();
  const rows = [
    { measured_at: '2026-09-24T08:00:00Z', temperature_c: 0, humidity_rh: null },
    { measured_at: '2026-09-24T08:05:00Z', temperature_c: null, humidity_rh: 50 },
    { measured_at: '2026-09-24T08:10:00Z', temperature_c: NaN, humidity_rh: '' },
    { measured_at: '2026-09-24T08:15:00Z', temperature_c: 2, humidity_rh: 52 },
  ];
  const geometry = charts.sensorTrendChart(canvas, rows);

  assert.deepEqual(geometry.positions.map(({ index }) => index), [0, 1, 3]);
  const temperature = sensorLines(strokes, colors['--accent'])[0];
  const humidity = sensorLines(strokes, colors['--chart-humidity'])[0];
  assert.deepEqual(temperature?.path.map(({ type }) => type), ['moveTo', 'moveTo']);
  assert.deepEqual(humidity?.path.map(({ type }) => type), ['moveTo', 'moveTo']);
});

test('sensor trend breaks a long outage and marks the selected sample on both scales', () => {
  const { canvas, strokes, circles } = fakeCanvas();
  const rows = [
    { measured_at: '2026-09-24T08:00:00Z', temperature_c: 1, humidity_rh: 50 },
    { measured_at: '2026-09-24T08:05:00Z', temperature_c: 2, humidity_rh: 51 },
    { measured_at: '2026-09-24T08:10:00Z', temperature_c: 3, humidity_rh: 52 },
    { measured_at: '2026-09-24T08:30:00Z', temperature_c: 4, humidity_rh: 53 },
  ];
  charts.sensorTrendChart(canvas, rows, { selectedIndex: 1 });

  const temperature = sensorLines(strokes, colors['--accent'])[0];
  assert.deepEqual(temperature.path.map(({ type }) => type), ['moveTo', 'lineTo', 'lineTo', 'moveTo']);
  assert.equal(circles.filter(({ radius }) => radius === 5).length, 2);
});

test('sensor trend can hide either channel while preserving original row indices', () => {
  const { canvas, strokes } = fakeCanvas();
  const rows = [
    { measured_at: '2026-09-24T08:00:00Z', temperature_c: 1, humidity_rh: null },
    { measured_at: '2026-09-24T08:05:00Z', temperature_c: null, humidity_rh: 51 },
    { measured_at: '2026-09-24T08:10:00Z', temperature_c: 3, humidity_rh: 52 },
  ];
  const geometry = charts.sensorTrendChart(canvas, rows, { showTemperature: false });

  assert.deepEqual(geometry.positions.map(({ index }) => index), [1, 2]);
  assert.equal(sensorLines(strokes, colors['--accent']).length, 0);
  assert.ok(strokes.some((stroke) => stroke.color === colors['--chart-humidity'] && stroke.dash.length > 0));
});

test('the only visible channel fills the vertical plot instead of leaving an empty pane', () => {
  const rows = [
    { measured_at: '2026-09-24T08:00:00Z', temperature_c: 10, humidity_rh: 50 },
    { measured_at: '2026-09-24T08:05:00Z', temperature_c: 20, humidity_rh: 60 },
    { measured_at: '2026-09-24T08:10:00Z', temperature_c: 30, humidity_rh: 70 },
  ];
  const both = fakeCanvas();
  const onlyHumidity = fakeCanvas();
  const onlyTemperature = fakeCanvas();
  charts.sensorTrendChart(both.canvas, rows);
  charts.sensorTrendChart(onlyHumidity.canvas, rows, { showTemperature: false, selectedIndex: 1 });
  charts.sensorTrendChart(onlyTemperature.canvas, rows, { showHumidity: false, selectedIndex: 1 });

  const verticalSpan = (result, color) => {
    const y = sensorLines(result.strokes, color)[0].path.map((point) => point.y);
    return Math.max(...y) - Math.min(...y);
  };
  assert.ok(verticalSpan(onlyHumidity, colors['--chart-humidity']) >
    verticalSpan(both, colors['--chart-humidity']) * 1.7);
  assert.ok(verticalSpan(onlyTemperature, colors['--accent']) >
    verticalSpan(both, colors['--accent']) * 1.7);
  assert.deepEqual(onlyHumidity.labels.filter((label) => label.includes('·')), ['Luftfeuchtigkeit · % rF']);
  assert.deepEqual(onlyTemperature.labels.filter((label) => label.includes('·')), ['Temperatur · °C']);
  assert.equal(onlyHumidity.circles.filter(({ radius }) => radius === 5).length, 1);
  assert.equal(onlyTemperature.circles.filter(({ radius }) => radius === 5).length, 1);
});

test('a regular six-hour sampling cadence stays connected', () => {
  const { canvas, strokes } = fakeCanvas();
  const rows = [0, 6, 12].map((hour) => ({
    measured_at: `2026-09-24T${String(hour).padStart(2, '0')}:00:00Z`,
    temperature_c: hour,
    humidity_rh: 50 + hour,
  }));
  charts.sensorTrendChart(canvas, rows);

  assert.deepEqual(sensorLines(strokes, colors['--accent'])[0].path.map(({ type }) => type),
    ['moveTo', 'lineTo', 'lineTo']);
});

test('six-hour window positions are relative to now instead of stretching sparse data', () => {
  const originalNow = Date.now;
  Date.now = () => Date.parse('2026-09-24T12:00:00Z');
  try {
    const { canvas } = fakeCanvas();
    const geometry = charts.sensorTrendChart(canvas, [
      { measured_at: '2026-09-24T06:00:00Z', temperature_c: 1, humidity_rh: 50 },
      { measured_at: '2026-09-24T11:00:00Z', temperature_c: 2, humidity_rh: 51 },
    ], { hours: 6 });
    assert.equal(geometry.positions[0].x, geometry.left);
    assert.ok(geometry.positions[1].x < geometry.right);
    assert.ok(geometry.positions[1].x > geometry.left);
  } finally {
    Date.now = originalNow;
  }
});

test('24-hour ticks include dates so equal clock times on different days are distinguishable', () => {
  const originalNow = Date.now;
  Date.now = () => Date.parse('2026-09-24T12:00:00Z');
  try {
    const short = fakeCanvas();
    const day = fakeCanvas();
    charts.sensorTrendChart(short.canvas, [], { hours: 6 });
    charts.sensorTrendChart(day.canvas, [], { hours: 24 });

    const clock = /^\d{2}:\d{2}$/;
    const date = /^\d{2}\.\d{2}\.?$/;
    assert.equal(short.labels.filter((label) => clock.test(label)).length, 3);
    assert.equal(short.labels.filter((label) => date.test(label)).length, 0);
    assert.equal(day.labels.filter((label) => clock.test(label)).length, 3);
    assert.equal(day.labels.filter((label) => date.test(label)).length, 3);
    assert.notEqual(day.labels.filter((label) => date.test(label))[0],
      day.labels.filter((label) => date.test(label))[2]);
  } finally {
    Date.now = originalNow;
  }
});
