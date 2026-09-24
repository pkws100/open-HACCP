/** Calendar-day event counts. Days are UTC, matching the analysis API's DATE(opened_at). */
function utcDay(value) {
  if (value instanceof Date) return Number.isFinite(value.getTime()) ? value.toISOString().slice(0, 10) : null;
  const match = typeof value === 'string' && /^(\d{4}-\d{2}-\d{2})(?:$|[ T])/.exec(value);
  if (!match) return null;
  const timestamp = Date.parse(`${match[1]}T00:00:00Z`);
  return Number.isFinite(timestamp) && new Date(timestamp).toISOString().slice(0, 10) === match[1] ? match[1] : null;
}

function calendarDays(rows, startAt, endAt) {
  const rowDays = rows.map((row) => utcDay(row.day)).filter(Boolean).sort();
  const start = utcDay(startAt) || rowDays[0] || new Date().toISOString().slice(0, 10);
  const end = utcDay(endAt) || rowDays.at(-1) || start;
  if (end < start) return [];
  const counts = new Map();
  for (const row of rows) {
    const day = utcDay(row.day);
    const count = Number(row.event_count);
    if (!day || day < start || day > end || !['warning', 'critical'].includes(row.severity)
      || !Number.isSafeInteger(count) || count < 0) continue;
    const entry = counts.get(day) || { warning: 0, critical: 0 };
    entry[row.severity] += count;
    counts.set(day, entry);
  }
  const days = [];
  for (let time = Date.parse(`${start}T00:00:00Z`), last = Date.parse(`${end}T00:00:00Z`);
    time <= last; time += 86_400_000) {
    const day = new Date(time).toISOString().slice(0, 10);
    const { warning = 0, critical = 0 } = counts.get(day) || {};
    days.push({ day, warning, critical, total: warning + critical });
  }
  return days;
}

function color(name, fallback) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
}

function canvasSize(canvas) {
  const ratio = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  const width = Math.max(240, canvas.clientWidth || 600);
  const configured = Number(canvas.dataset.chartHeight || canvas.getAttribute('height') || 260);
  const height = Number.isFinite(configured) && configured > 0 ? configured : 260;
  canvas.dataset.chartHeight = String(height);
  canvas.style.height = `${height}px`;
  if (canvas.width !== Math.round(width * ratio)) canvas.width = Math.round(width * ratio);
  if (canvas.height !== Math.round(height * ratio)) canvas.height = Math.round(height * ratio);
  const context = canvas.getContext('2d');
  context.setTransform(ratio, 0, 0, ratio, 0, 0);
  context.clearRect(0, 0, width, height);
  return { context, width, height };
}

/**
 * Draw warning/critical counts as stacked UTC-day bars, including empty days.
 * Returns CSS-pixel geometry for pointer hit testing and keyboard selection.
 * options.selectedDay is a YYYY-MM-DD string; startAt/endAt may be UTC timestamps.
 */
export function eventStackChart(canvas, rows, options = {}) {
  const days = calendarDays(Array.isArray(rows) ? rows : [], options.startAt, options.endAt);
  const { context, width, height } = canvasSize(canvas);
  const left = 39;
  const right = width - 12;
  const top = 18;
  const bottom = height - 51;
  const plotHeight = Math.max(1, bottom - top);
  const plotWidth = Math.max(1, right - left);
  const colors = {
    warning: color('--warning', '#e2a64a'),
    critical: color('--danger', '#df7469'),
    accent: color('--accent', '#61d6c3'),
    selected: color('--accent-soft', 'rgba(97,214,195,.12)'),
    grid: color('--line', '#263b38'),
    text: color('--muted', '#91aaa6'),
  };
  const maximum = Math.max(1, ...days.map((entry) => entry.total));
  context.font = '10px Inter, system-ui';
  context.textAlign = 'right';
  context.fillStyle = colors.text;
  context.lineWidth = 1;
  for (const count of [...new Set([0, Math.floor(maximum / 2), maximum])]) {
    const y = bottom - count / maximum * plotHeight;
    context.strokeStyle = colors.grid;
    context.beginPath();
    context.moveTo(left, y);
    context.lineTo(right, y);
    context.stroke();
    context.fillText(String(count), left - 7, y + 3);
  }

  const slot = plotWidth / Math.max(1, days.length);
  const barWidth = Math.max(1, Math.min(18, slot * .74));
  const bars = days.map((entry, index) => {
    const x = left + slot * (index + .5);
    const warningHeight = entry.warning / maximum * plotHeight;
    const criticalHeight = entry.critical / maximum * plotHeight;
    if (entry.day === options.selectedDay) {
      context.fillStyle = colors.selected;
      context.fillRect(left + index * slot, top, Math.max(1, slot), plotHeight);
      context.strokeStyle = colors.accent;
      context.beginPath();
      context.moveTo(x, top);
      context.lineTo(x, bottom);
      context.stroke();
    }
    if (warningHeight > 0) {
      context.fillStyle = colors.warning;
      context.fillRect(x - barWidth / 2, bottom - warningHeight, barWidth, warningHeight);
    }
    if (criticalHeight > 0) {
      context.fillStyle = colors.critical;
      context.fillRect(x - barWidth / 2, bottom - warningHeight - criticalHeight, barWidth, criticalHeight);
    }
    return { ...entry, index, x, left: left + index * slot, right: left + (index + 1) * slot };
  });

  context.fillStyle = colors.text;
  context.textAlign = 'center';
  context.font = '9px Inter, system-ui';
  let lastLabelX = -Infinity;
  for (const [index, entry] of days.entries()) {
    const x = bars[index].x;
    const last = index === days.length - 1;
    if (index !== 0 && !last && (x - lastLabelX < 53 || right - x < 53)) continue;
    if (last && x - lastLabelX < 53 && index !== 0) continue;
    context.fillText(`${entry.day.slice(8, 10)}.${entry.day.slice(5, 7)}.`, x, height - 26);
    lastLabelX = x;
  }
  context.fillText('Tag (UTC)', (left + right) / 2, height - 7);
  if (!days.some((entry) => entry.total > 0)) {
    context.font = '11px Inter, system-ui';
    context.fillText('Keine Ereignisse im Zeitraum', (left + right) / 2, (top + bottom) / 2);
  }
  return {
    days,
    bars,
    positions: bars.map(({ day, index, x }) => ({ day, index, x })),
    left,
    right,
    width,
    height,
  };
}
