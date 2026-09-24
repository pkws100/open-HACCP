import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const colors = {
  '--warning': '#bb8800', '--danger': '#cc3344', '--accent': '#11aa99',
  '--accent-soft': 'rgba(17,170,153,.1)', '--line': '#445555', '--muted': '#889999',
};
globalThis.document = { documentElement: {} };
globalThis.getComputedStyle = () => ({ getPropertyValue: (name) => colors[name] || '' });
globalThis.window = { devicePixelRatio: 2 };

const source = await readFile(new URL('../../public/assets/analysis-events-chart.js', import.meta.url), 'utf8');
const { eventStackChart } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

function fakeCanvas(clientWidth = 380, chartHeight = 260) {
  const fills = [];
  const labels = [];
  const strokes = [];
  const context = {
    clearRect() {}, setTransform() {}, beginPath() {}, moveTo() {}, lineTo() {},
    stroke() { strokes.push(this.strokeStyle); },
    fillRect(x, y, width, height) { fills.push({ x, y, width, height, color: this.fillStyle }); },
    fillText(text, x, y) { labels.push({ text: String(text), x, y }); },
  };
  const canvas = {
    clientWidth,
    width: 0,
    height: 0,
    dataset: {},
    style: {},
    getAttribute(name) { return name === 'height' ? String(chartHeight) : null; },
    getContext() { return context; },
  };
  return { canvas, fills, labels, strokes };
}

test('stacked bars aggregate repeated severities and include UTC days without events', () => {
  const drawn = fakeCanvas();
  const geometry = eventStackChart(drawn.canvas, [
    { day: '2026-09-22', event_type: 'device_offline', severity: 'critical', event_count: '2' },
    { day: '2026-09-22', event_type: 'temperature_above_max', severity: 'critical', event_count: '1' },
    { day: '2026-09-22', event_type: 'signal_weak', severity: 'warning', event_count: '3' },
    { day: '2026-09-24', event_type: 'firmware_diagnostic', severity: 'warning', event_count: '1' },
  ], { startAt: '2026-09-22 06:00:00', endAt: '2026-09-24 06:00:00' });

  assert.deepEqual(geometry.days, [
    { day: '2026-09-22', warning: 3, critical: 3, total: 6 },
    { day: '2026-09-23', warning: 0, critical: 0, total: 0 },
    { day: '2026-09-24', warning: 1, critical: 0, total: 1 },
  ]);
  assert.deepEqual(geometry.positions.map(({ day }) => day), geometry.days.map(({ day }) => day));
  assert.equal(drawn.fills.filter((fill) => fill.color === colors['--warning']).length, 2);
  assert.equal(drawn.fills.filter((fill) => fill.color === colors['--danger']).length, 1);
  const warning = drawn.fills.find((fill) => fill.color === colors['--warning']);
  const critical = drawn.fills.find((fill) => fill.color === colors['--danger']);
  assert.equal(critical.y + critical.height, warning.y);
  assert.ok(drawn.labels.some(({ text }) => text === 'Tag (UTC)'));
  assert.ok(drawn.labels.some(({ text }) => text === '22.09.'));
  assert.ok(drawn.labels.some(({ text }) => text === '24.09.'));
});

test('a selected zero-event day has a visible highlight and touch geometry', () => {
  const drawn = fakeCanvas(280);
  const geometry = eventStackChart(drawn.canvas, [], {
    startAt: '2026-09-22T00:00:00Z', endAt: '2026-09-24T23:59:59Z', selectedDay: '2026-09-23',
  });
  assert.equal(geometry.bars.length, 3);
  assert.ok(geometry.bars.every((bar) => bar.left <= bar.x && bar.x <= bar.right));
  assert.ok(geometry.bars[1].right <= geometry.right);
  assert.ok(drawn.fills.some((fill) => fill.color === colors['--accent-soft']));
  assert.ok(drawn.strokes.includes(colors['--accent']));
  assert.ok(drawn.labels.some(({ text }) => text === 'Keine Ereignisse im Zeitraum'));
});

test('90-day mobile chart limits date labels and preserves the first and last day', () => {
  const drawn = fakeCanvas(255);
  const geometry = eventStackChart(drawn.canvas, [], {
    startAt: '2026-06-26 00:00:00', endAt: '2026-09-24 23:59:59',
  });
  const dates = drawn.labels.filter(({ text }) => /^\d{2}\.\d{2}\.$/.test(text));
  assert.equal(geometry.days.length, 91);
  assert.equal(geometry.days[0].day, '2026-06-26');
  assert.equal(geometry.days.at(-1).day, '2026-09-24');
  assert.ok(dates.length >= 2 && dates.length <= 5);
  assert.equal(dates[0].text, '26.06.');
  assert.equal(dates.at(-1).text, '24.09.');
  assert.ok(dates.every(({ x }) => x >= geometry.left && x <= geometry.right));
});

test('invalid counts, dates, and unknown severity do not become event bars', () => {
  const drawn = fakeCanvas();
  const geometry = eventStackChart(drawn.canvas, [
    { day: '2026-09-24', severity: 'warning', event_count: -1 },
    { day: '2026-09-24', severity: 'warning', event_count: 'NaN' },
    { day: '2026-09-24', severity: 'notice', event_count: 4 },
    { day: '2026-02-30', severity: 'critical', event_count: 5 },
  ], { startAt: '2026-09-24', endAt: '2026-09-24' });
  assert.deepEqual(geometry.days, [{ day: '2026-09-24', warning: 0, critical: 0, total: 0 }]);
  assert.equal(drawn.fills.filter(({ color }) => color === colors['--warning'] || color === colors['--danger']).length, 0);
});

test('repeated renders and resize preserve CSS height and adjust backing pixels', () => {
  const drawn = fakeCanvas(380, 250);
  eventStackChart(drawn.canvas, [], { startAt: '2026-09-24', endAt: '2026-09-24' });
  assert.equal(drawn.canvas.style.height, '250px');
  assert.equal(drawn.canvas.width, 760);
  assert.equal(drawn.canvas.height, 500);
  drawn.canvas.clientWidth = 300;
  drawn.canvas.dataset.chartHeight = '300';
  const geometry = eventStackChart(drawn.canvas, [], { startAt: '2026-09-24', endAt: '2026-09-24' });
  assert.equal(geometry.width, 300);
  assert.equal(geometry.height, 300);
  assert.equal(drawn.canvas.width, 600);
  assert.equal(drawn.canvas.height, 600);
  assert.equal(drawn.canvas.style.height, '300px');
});
