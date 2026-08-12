const variables = { accent: '--accent', humidity: '--chart-humidity', warning: '--warning', danger: '--danger', grid: '--line', text: '--muted', surface: '--surface' };

export function chartColor(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(variables[name] || name).trim();
}

function palette() {
  return Object.fromEntries(Object.keys(variables).map((name) => [name, chartColor(name)]));
}

function setup(canvas) {
  const ratio = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  const width = Math.max(240, canvas.clientWidth || 600);
  const configuredHeight = Number(canvas.dataset.chartHeight || canvas.getAttribute('height') || 260);
  const height = Number.isFinite(configuredHeight) && configuredHeight > 0 ? configuredHeight : 260;
  canvas.dataset.chartHeight = String(height);
  canvas.style.height = `${height}px`;
  const pixelWidth = Math.round(width * ratio);
  const pixelHeight = Math.round(height * ratio);
  if (canvas.width !== pixelWidth) canvas.width = pixelWidth;
  if (canvas.height !== pixelHeight) canvas.height = pixelHeight;
  const context = canvas.getContext('2d');
  context.setTransform(ratio, 0, 0, ratio, 0, 0);
  context.clearRect(0, 0, width, height);
  return { context, width, height };
}

function domain(values, fallback = [0, 1]) {
  const finite = values.filter(Number.isFinite);
  if (!finite.length) return fallback;
  let min = Math.min(...finite); let max = Math.max(...finite);
  if (min === max) { min -= 1; max += 1; }
  const pad = (max - min) * .12;
  return [min - pad, max + pad];
}

function grid(context, width, height, pad, min, max, unit = '') {
  const colors = palette();
  context.font = '10px Inter, system-ui';
  context.textAlign = 'right';
  context.fillStyle = colors.text;
  context.strokeStyle = colors.grid;
  context.lineWidth = 1;
  for (let index = 0; index <= 4; index += 1) {
    const y = pad.top + ((height - pad.top - pad.bottom) / 4) * index;
    context.beginPath(); context.moveTo(pad.left, y); context.lineTo(width - pad.right, y); context.stroke();
    const value = max - ((max - min) / 4) * index;
    context.fillText(`${value.toFixed(1)}${unit}`, pad.left - 8, y + 3);
  }
}

export function lineChart(canvas, series, options = {}) {
  const colors = palette();
  const { context, width, height } = setup(canvas);
  const pad = { left: 48, right: 18, top: 18, bottom: 30 };
  const all = series.flatMap((item) => item.values.map((point) => Number(point.value)));
  const [min, max] = options.domain || domain(all);
  grid(context, width, height, pad, min, max, options.unit || '');
  const times = series.flatMap((item) => item.values.map((point) => new Date(point.at).getTime())).filter(Number.isFinite);
  const minTime = Math.min(...times); const maxTime = Math.max(...times);
  series.forEach((item, seriesIndex) => {
    context.strokeStyle = item.color || (seriesIndex ? colors.humidity : colors.accent);
    context.lineWidth = item.width || 2;
    context.beginPath();
    let started = false;
    item.values.forEach((point) => {
      const value = Number(point.value); const time = new Date(point.at).getTime();
      if (!Number.isFinite(value) || !Number.isFinite(time)) return;
      const x = pad.left + ((time - minTime) / Math.max(1, maxTime - minTime)) * (width - pad.left - pad.right);
      const y = pad.top + (1 - ((value - min) / (max - min))) * (height - pad.top - pad.bottom);
      if (!started) { context.moveTo(x, y); started = true; } else context.lineTo(x, y);
    });
    context.stroke();
  });
  context.textAlign = 'left'; context.fillStyle = colors.text; context.font = '10px Inter, system-ui';
  if (Number.isFinite(minTime)) context.fillText(new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit' }).format(new Date(minTime)), pad.left, height - 8);
  if (Number.isFinite(maxTime)) { context.textAlign = 'right'; context.fillText(new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit' }).format(new Date(maxTime)), width - pad.right, height - 8); }
}

export function barChart(canvas, rows, { labelKey = 'label', valueKey = 'value', color = null } = {}) {
  const colors = palette();
  color ||= colors.warning;
  const { context, width, height } = setup(canvas);
  const pad = { left: 40, right: 12, top: 15, bottom: 42 };
  const max = Math.max(1, ...rows.map((row) => Number(row[valueKey]) || 0));
  grid(context, width, height, pad, 0, max, '');
  const slot = (width - pad.left - pad.right) / Math.max(1, rows.length);
  rows.forEach((row, index) => {
    const value = Number(row[valueKey]) || 0;
    const barHeight = (value / max) * (height - pad.top - pad.bottom);
    context.fillStyle = row.color || color;
    context.fillRect(pad.left + index * slot + slot * .18, height - pad.bottom - barHeight, slot * .64, barHeight);
    if (rows.length <= 15 || index % Math.ceil(rows.length / 12) === 0) {
      context.save(); context.translate(pad.left + index * slot + slot / 2, height - pad.bottom + 8); context.rotate(-.55);
      context.fillStyle = colors.text; context.font = '9px Inter, system-ui'; context.textAlign = 'right'; context.fillText(String(row[labelKey]).slice(0, 10), 0, 0); context.restore();
    }
  });
}

export function observeChartResize(canvases, render) {
  if (!('ResizeObserver' in window)) return null;
  let frame = 0;
  const observer = new ResizeObserver(() => {
    window.cancelAnimationFrame(frame);
    frame = window.requestAnimationFrame(render);
  });
  new Set(canvases.map((canvas) => canvas.parentElement || canvas)).forEach((element) => observer.observe(element));
  return observer;
}

export function accessibleTable(container, caption, headers, rows) {
  container.innerHTML = `<table><caption>${caption}</caption><thead><tr>${headers.map((header) => `<th>${header}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${row.map((value) => `<td>${value ?? '–'}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
}
