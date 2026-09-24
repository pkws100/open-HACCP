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

function sensorValue(value) {
  if (value == null || value === '') return null;
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : null;
}

function medianInterval(points) {
  const intervals = [];
  for (let index = 1; index < points.length; index += 1) {
    const interval = points[index].time - points[index - 1].time;
    if (interval > 0) intervals.push(interval);
  }
  if (!intervals.length) return 0;
  intervals.sort((left, right) => left - right);
  const middle = Math.floor(intervals.length / 2);
  return intervals.length % 2 ? intervals[middle] : (intervals[middle - 1] + intervals[middle]) / 2;
}

function sensorPane(context, colors, pane, left, right, values, label, unit, color, dashed,
  fallback = unit === '°C' ? [0, 1] : [0, 100]) {
  const [minimum, maximum] = domain(values, fallback);
  context.save();
  context.font = '600 11px Inter, system-ui';
  context.fillStyle = color;
  context.textAlign = 'left';
  context.setLineDash(dashed ? [5, 4] : []);
  context.strokeStyle = color;
  context.lineWidth = 2;
  context.beginPath();
  context.moveTo(left, pane.heading - 4);
  context.lineTo(left + 16, pane.heading - 4);
  context.stroke();
  context.setLineDash([]);
  context.fillText(unit ? `${label} · ${unit}` : label, left + 23, pane.heading);
  context.font = '10px Inter, system-ui';
  context.fillStyle = colors.text;
  context.textAlign = 'right';
  context.strokeStyle = colors.grid;
  context.lineWidth = 1;
  for (let tick = 0; tick <= 2; tick += 1) {
    const y = pane.top + (pane.bottom - pane.top) * tick / 2;
    context.beginPath();
    context.moveTo(left, y);
    context.lineTo(right, y);
    context.stroke();
    const value = maximum - (maximum - minimum) * tick / 2;
    context.fillText(new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 }).format(value), left - 8, y + 3);
  }
  context.restore();
  return { minimum, maximum };
}

/** Aligned sensor plots. Coordinates are CSS pixels, suitable for pointer hit testing. */
export function sensorTrendChart(canvas, rows, options = {}) {
  const colors = palette();
  const { context, width, height } = setup(canvas);
  const left = 56;
  const right = width - 16;
  const showTemperature = options.showTemperature !== false;
  const showHumidity = options.showHumidity !== false;
  const points = rows.map((row, index) => ({
    index,
    time: row.measured_at == null ? NaN : new Date(row.measured_at).getTime(),
    temperature: sensorValue(row.temperature_c),
    humidity: sensorValue(row.humidity_rh),
  })).filter((point) => Number.isFinite(point.time))
    .sort((leftPoint, rightPoint) => leftPoint.time - rightPoint.time || leftPoint.index - rightPoint.index);
  const readings = points.filter((point) => point.temperature !== null || point.humidity !== null);
  const hours = Number(options.hours);
  let minTime; let maxTime;
  if (Number.isFinite(hours) && hours > 0) {
    maxTime = Math.max(Date.now(), readings.at(-1)?.time ?? -Infinity);
    minTime = maxTime - hours * 60 * 60 * 1000;
  } else {
    minTime = readings[0]?.time ?? Date.now() - 60 * 60 * 1000;
    maxTime = readings.at(-1)?.time ?? minTime + 60 * 60 * 1000;
    if (maxTime === minTime) { minTime -= 30 * 60 * 1000; maxTime += 30 * 60 * 1000; }
  }
  const showDates = maxTime - minTime >= 24 * 60 * 60 * 1000;
  const heading = 22;
  const top = 12;
  const bottom = showDates ? 42 : 28;
  const gap = 18;
  const paneCount = Number(showTemperature) + Number(showHumidity);
  const paneHeight = Math.max(24, (height - top - bottom - gap * Math.max(0, paneCount - 1) - heading * paneCount) /
    Math.max(1, paneCount));
  let paneStart = top;
  const nextPane = () => {
    const pane = { heading: paneStart + 10, top: paneStart + heading, bottom: paneStart + heading + paneHeight };
    paneStart = pane.bottom + gap;
    return pane;
  };
  const temperaturePane = showTemperature ? nextPane() : null;
  const humidityPane = showHumidity ? nextPane() : null;
  const xAt = (time) => left + (time - minTime) / Math.max(1, maxTime - minTime) * (right - left);
  const visible = points.filter((point) => point.time >= minTime && point.time <= maxTime);
  const positions = visible.filter((point) =>
    (showTemperature && point.temperature !== null) || (showHumidity && point.humidity !== null))
    .map((point) => ({ index: point.index, x: xAt(point.time) }));
  const temperatureDomain = temperaturePane && sensorPane(context, colors, temperaturePane, left, right,
    visible.map((point) => point.temperature).filter((value) => value !== null),
    'Temperatur', '°C', colors.accent, false);
  const humidityDomain = humidityPane && sensorPane(context, colors, humidityPane, left, right,
    visible.map((point) => point.humidity).filter((value) => value !== null),
    'Luftfeuchtigkeit', '% rF', colors.humidity, true);
  const yAt = (value, pane, limits) => pane.bottom - (value - limits.minimum) /
    (limits.maximum - limits.minimum) * (pane.bottom - pane.top);
  const usualInterval = medianInterval(visible);
  const gapThreshold = usualInterval > 30 * 60 * 1000
    ? 3 * usualInterval
    : Math.min(30 * 60 * 1000, 3 * usualInterval || 30 * 60 * 1000);

  function drawSeries(key, pane, limits, color, dashed) {
    if (!pane) return;
    context.save();
    context.strokeStyle = color;
    context.fillStyle = color;
    context.lineWidth = 2;
    context.lineJoin = 'round';
    context.lineCap = 'round';
    context.setLineDash(dashed ? [5, 4] : []);
    context.beginPath();
    let previous = null;
    let last = null;
    for (const point of visible) {
      const value = point[key];
      if (value === null) { previous = null; continue; }
      const x = xAt(point.time);
      const y = yAt(value, pane, limits);
      if (previous && point.time - previous.time <= gapThreshold) context.lineTo(x, y);
      else context.moveTo(x, y);
      previous = point;
      last = { x, y };
    }
    context.stroke();
    context.setLineDash([]);
    if (last) {
      context.beginPath();
      context.arc(last.x, last.y, 2.5, 0, Math.PI * 2);
      context.fill();
    }
    context.restore();
  }

  drawSeries('temperature', temperaturePane, temperatureDomain, colors.accent, false);
  drawSeries('humidity', humidityPane, humidityDomain, colors.humidity, true);
  context.save();
  context.font = '10px Inter, system-ui';
  context.fillStyle = colors.text;
  const timeLabel = new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit' });
  const dateLabel = showDates && new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit' });
  for (const [time, x, align] of [
    [minTime, left, 'left'],
    [(minTime + maxTime) / 2, (left + right) / 2, 'center'],
    [maxTime, right, 'right'],
  ]) {
    context.textAlign = align;
    if (dateLabel) context.fillText(dateLabel.format(new Date(time)), x, height - 19);
    context.fillText(timeLabel.format(new Date(time)), x, height - 7);
  }
  const selected = visible.find((point) => point.index === options.selectedIndex &&
    ((showTemperature && point.temperature !== null) || (showHumidity && point.humidity !== null)));
  if (selected) {
    const x = xAt(selected.time);
    context.setLineDash([3, 4]);
    context.strokeStyle = colors.text;
    context.lineWidth = 1;
    context.beginPath();
    context.moveTo(x, (temperaturePane || humidityPane).top);
    context.lineTo(x, (humidityPane || temperaturePane).bottom);
    context.stroke();
    context.setLineDash([]);
    for (const [value, pane, limits, color, enabled] of [
      [selected.temperature, temperaturePane, temperatureDomain, colors.accent, showTemperature],
      [selected.humidity, humidityPane, humidityDomain, colors.humidity, showHumidity],
    ]) {
      if (!enabled || value === null) continue;
      context.beginPath();
      context.fillStyle = colors.surface;
      context.strokeStyle = color;
      context.lineWidth = 2;
      context.arc(x, yAt(value, pane, limits), 5, 0, Math.PI * 2);
      context.fill();
      context.stroke();
    }
  }
  context.restore();
  return { positions, left, right, width, height };
}

function metricTime(value) {
  if (value instanceof Date) return value.getTime();
  if (typeof value === 'number') return value;
  if (typeof value !== 'string' || !value.trim()) return NaN;
  const trimmed = value.trim();
  if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(trimmed)) {
    const iso = trimmed.replace(' ', 'T');
    return new Date(/(?:Z|[+-]\d{2}:?\d{2})$/i.test(iso) ? iso : `${iso}Z`).getTime();
  }
  return new Date(trimmed).getTime();
}

/** One or two numeric series with separate scales; selection indices refer to input rows. */
export function metricTrendChart(canvas, rows, options = {}) {
  const definitions = options.series || [];
  if (definitions.length < 1 || definitions.length > 2) {
    throw new RangeError('metricTrendChart requires one or two series');
  }
  const colors = palette();
  const { context, width, height } = setup(canvas);
  const left = 56;
  const right = width - 16;
  const timeKey = options.timeKey || 'at';
  const points = rows.map((row, index) => ({
    index,
    time: metricTime(row[timeKey]),
    values: definitions.map((definition) => sensorValue(row[definition.key])),
  })).filter((point) => Number.isFinite(point.time))
    .sort((first, second) => first.time - second.time || first.index - second.index);
  const readings = points.filter((point) => point.values.some((value) => value !== null));
  const explicitStart = metricTime(options.startAt);
  const explicitEnd = metricTime(options.endAt);
  let minTime = Number.isFinite(explicitStart) ? explicitStart : readings[0]?.time ?? Date.now() - 60 * 60 * 1000;
  let maxTime = Number.isFinite(explicitEnd) ? explicitEnd : readings.at(-1)?.time ?? minTime + 60 * 60 * 1000;
  if (minTime > maxTime) [minTime, maxTime] = [maxTime, minTime];
  if (minTime === maxTime) { minTime -= 30 * 60 * 1000; maxTime += 30 * 60 * 1000; }
  const showDates = maxTime - minTime >= 24 * 60 * 60 * 1000;
  const top = 12;
  const bottom = showDates ? 42 : 28;
  const heading = 22;
  const gap = 18;
  const paneHeight = Math.max(24, (height - top - bottom - heading * definitions.length - gap * (definitions.length - 1)) /
    definitions.length);
  const panes = definitions.map((_, index) => {
    const paneTop = top + index * (heading + paneHeight + gap);
    return { heading: paneTop + 10, top: paneTop + heading, bottom: paneTop + heading + paneHeight };
  });
  const visible = points.filter((point) => point.time >= minTime && point.time <= maxTime);
  const xAt = (time) => left + (time - minTime) / (maxTime - minTime) * (right - left);
  const positions = visible.filter((point) => point.values.some((value) => value !== null))
    .map((point) => ({ index: point.index, x: xAt(point.time) }));
  const limits = definitions.map((definition, seriesIndex) => {
    const values = visible.map((point) => point.values[seriesIndex]).filter((value) => value !== null);
    const reference = sensorValue(definition.reference?.value);
    if (reference !== null) values.push(reference);
    return sensorPane(context, colors, panes[seriesIndex], left, right, values,
      definition.label || definition.key, definition.unit || '',
      definition.color || (seriesIndex ? colors.humidity : colors.accent), Boolean(definition.dashed), [0, 1]);
  });
  const yAt = (value, pane, range) => pane.bottom - (value - range.minimum) /
    (range.maximum - range.minimum) * (pane.bottom - pane.top);

  definitions.forEach((definition, seriesIndex) => {
    const value = sensorValue(definition.reference?.value);
    if (value === null) return;
    const y = yAt(value, panes[seriesIndex], limits[seriesIndex]);
    context.save();
    context.strokeStyle = definition.reference.color || colors.danger;
    context.lineWidth = 1.5;
    context.setLineDash(definition.reference.dashed === false ? [] : [4, 4]);
    context.beginPath();
    context.moveTo(left, y);
    context.lineTo(right, y);
    context.stroke();
    context.restore();
  });

  definitions.forEach((definition, seriesIndex) => {
    const color = definition.color || (seriesIndex ? colors.humidity : colors.accent);
    const dashed = Boolean(definition.dashed);
    const validPoints = visible.filter((point) => point.values[seriesIndex] !== null);
    const typicalInterval = medianInterval(validPoints);
    const gapThreshold = typicalInterval > 30 * 60 * 1000
      ? 3 * typicalInterval
      : Math.min(30 * 60 * 1000, 3 * typicalInterval || 30 * 60 * 1000);
    context.save();
    context.strokeStyle = color;
    context.fillStyle = color;
    context.lineWidth = 2;
    context.lineJoin = 'round';
    context.lineCap = 'round';
    context.setLineDash(dashed ? [5, 4] : []);
    context.beginPath();
    let previous = null;
    let last = null;
    for (const point of visible) {
      const value = point.values[seriesIndex];
      if (value === null) { previous = null; continue; }
      const x = xAt(point.time);
      const y = yAt(value, panes[seriesIndex], limits[seriesIndex]);
      if (previous && point.time - previous.time <= gapThreshold) context.lineTo(x, y);
      else context.moveTo(x, y);
      previous = point;
      last = { x, y };
    }
    context.stroke();
    context.setLineDash([]);
    if (last) {
      context.beginPath();
      context.arc(last.x, last.y, 2.5, 0, Math.PI * 2);
      context.fill();
    }
    context.restore();
  });

  context.save();
  context.font = '10px Inter, system-ui';
  context.fillStyle = colors.text;
  const clock = new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit' });
  const calendar = showDates && new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit' });
  for (const [time, x, align] of [
    [minTime, left, 'left'],
    [(minTime + maxTime) / 2, (left + right) / 2, 'center'],
    [maxTime, right, 'right'],
  ]) {
    context.textAlign = align;
    if (calendar) context.fillText(calendar.format(new Date(time)), x, height - 19);
    context.fillText(clock.format(new Date(time)), x, height - 7);
  }
  const selected = visible.find((point) => point.index === options.selectedIndex &&
    point.values.some((value) => value !== null));
  if (selected) {
    const x = xAt(selected.time);
    context.strokeStyle = colors.text;
    context.lineWidth = 1;
    context.setLineDash([3, 4]);
    context.beginPath();
    context.moveTo(x, panes[0].top);
    context.lineTo(x, panes.at(-1).bottom);
    context.stroke();
    context.setLineDash([]);
    selected.values.forEach((value, seriesIndex) => {
      if (value === null) return;
      context.beginPath();
      context.fillStyle = colors.surface;
      context.strokeStyle = definitions[seriesIndex].color ||
        (seriesIndex ? colors.humidity : colors.accent);
      context.lineWidth = 2;
      context.arc(x, yAt(value, panes[seriesIndex], limits[seriesIndex]), 5, 0, Math.PI * 2);
      context.fill();
      context.stroke();
    });
  }
  context.restore();
  return { positions, left, right, width, height };
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
