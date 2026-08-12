import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

globalThis.document = { documentElement: {} };
globalThis.getComputedStyle = () => ({ getPropertyValue: () => '#123456' });
globalThis.window = {
  cancelAnimationFrame() {},
  devicePixelRatio: 2,
  requestAnimationFrame(callback) { callback(); return 1; },
};

const source = await readFile(new URL('../../public/assets/charts.js', import.meta.url), 'utf8');
const charts = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

function fakeCanvas(configuredHeight = 340) {
  const attributes = new Map([['height', String(configuredHeight)]]);
  const context = {
    beginPath() {}, clearRect() {}, fillText() {}, lineTo() {}, moveTo() {}, setTransform() {}, stroke() {},
  };
  let width = 300;
  let height = configuredHeight;

  return {
    attributes,
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
