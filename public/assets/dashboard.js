(() => {
  'use strict';

  const state = {
    data: null,
    device: null,
    point: null,
    hours: 24,
    hoverIndex: null,
    loading: false,
  };

  const elements = {
    sync: document.querySelector('.sync-state'),
    syncLabel: document.querySelector('#sync-label'),
    refresh: document.querySelector('#refresh-button'),
    deviceCount: document.querySelector('#device-count'),
    deviceList: document.querySelector('#device-list'),
    deviceUid: document.querySelector('#device-uid'),
    title: document.querySelector('#workspace-title'),
    context: document.querySelector('#device-context'),
    pointSelect: document.querySelector('#point-select'),
    temperature: document.querySelector('#temperature-value'),
    humidity: document.querySelector('#humidity-value'),
    minimum: document.querySelector('#minimum-value'),
    maximum: document.querySelector('#maximum-value'),
    latestTime: document.querySelector('#latest-time'),
    measurementCount: document.querySelector('#measurement-count'),
    canvas: document.querySelector('#measurement-chart'),
    tooltip: document.querySelector('#chart-tooltip'),
    chartEmpty: document.querySelector('#chart-empty'),
    recentTable: document.querySelector('#recent-table'),
    deviceState: document.querySelector('#device-state'),
    diagnosticList: document.querySelector('#diagnostic-list'),
    fleetSummary: document.querySelector('#fleet-summary'),
  };

  const formatNumber = (value, digits = 1) => value == null
    ? '–'
    : new Intl.NumberFormat('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);

  const formatTime = (value, withDate = false) => {
    if (!value) return '–';
    const options = withDate
      ? { dateStyle: 'short', timeStyle: 'short' }
      : { hour: '2-digit', minute: '2-digit' };
    return new Intl.DateTimeFormat('de-DE', options).format(new Date(value));
  };

  const isOnline = (device, serverTime) => {
    if (!device || device.status !== 'active' || !device.last_seen_at) return false;
    return new Date(serverTime).getTime() - new Date(device.last_seen_at).getTime() < 12 * 60 * 60 * 1000;
  };

  async function loadData() {
    if (state.loading) return;
    state.loading = true;
    elements.sync.classList.remove('has-error');
    elements.syncLabel.textContent = 'Daten werden aktualisiert';
    elements.refresh.disabled = true;

    const query = new URLSearchParams({ hours: String(state.hours) });
    if (state.device) query.set('device', state.device);
    if (state.point) query.set('point', state.point);

    try {
      const response = await fetch(`/api/v1/dashboard/overview?${query}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      state.data = await response.json();
      state.device = state.data.selection?.device_uid ?? null;
      state.point = state.data.selection?.measurement_point ?? null;
      state.hoverIndex = null;
      render();
      elements.syncLabel.textContent = `Aktualisiert ${formatTime(state.data.server_time)}`;
    } catch (error) {
      elements.sync.classList.add('has-error');
      elements.syncLabel.textContent = 'Aktualisierung fehlgeschlagen';
      console.error('Dashboard refresh failed', error);
    } finally {
      state.loading = false;
      elements.refresh.disabled = false;
    }
  }

  function render() {
    const data = state.data;
    renderDevices(data);
    renderSelection(data);
    renderMetrics(data.kpis);
    renderChart(data.series ?? []);
    renderRecent(data.recent_measurements ?? []);
    renderDiagnostics(data);
    elements.fleetSummary.textContent = `${data.fleet.total_devices} Geräte · ${data.fleet.measurements_in_window} Messungen in ${data.window_hours} h`;
    document.querySelectorAll('[data-hours]').forEach((button) => {
      button.classList.toggle('is-active', Number(button.dataset.hours) === data.window_hours);
    });
  }

  function renderDevices(data) {
    elements.deviceList.replaceChildren();
    elements.deviceCount.textContent = String(data.devices.length);
    data.devices.forEach((device) => {
      const button = document.createElement('button');
      const pin = document.createElement('span');
      const copy = document.createElement('span');
      const name = document.createElement('strong');
      const uid = document.createElement('small');
      button.type = 'button';
      button.className = 'device-button';
      button.classList.toggle('is-active', device.device_uid === state.device);
      button.classList.toggle('is-online', isOnline(device, data.server_time));
      button.setAttribute('aria-pressed', device.device_uid === state.device ? 'true' : 'false');
      pin.className = 'status-pin';
      name.textContent = device.name;
      uid.textContent = device.device_uid;
      copy.append(name, uid);
      button.append(pin, copy);
      button.addEventListener('click', () => {
        if (state.device === device.device_uid) return;
        state.device = device.device_uid;
        state.point = null;
        loadData();
      });
      elements.deviceList.append(button);
    });
  }

  function renderSelection(data) {
    const device = data.selected_device;
    const point = data.selected_measurement_point;
    elements.deviceUid.textContent = device?.device_uid ?? 'Noch kein Gerät';
    elements.title.textContent = point?.name ?? device?.name ?? 'Messstellenübersicht';

    const context = [];
    if (point?.location) context.push(point.location);
    if (point?.sensor_type) context.push(point.sensor_type);
    if (device?.hardware_revision) context.push(device.hardware_revision);
    elements.context.textContent = context.join(' · ') || 'Warten auf Sensordaten';

    elements.pointSelect.replaceChildren();
    elements.pointSelect.disabled = data.measurement_points.length === 0;
    if (data.measurement_points.length === 0) {
      const option = document.createElement('option');
      option.textContent = 'Keine Messstelle';
      elements.pointSelect.append(option);
    } else {
      data.measurement_points.forEach((measurementPoint) => {
        const option = document.createElement('option');
        option.value = measurementPoint.code;
        option.textContent = measurementPoint.name;
        option.selected = measurementPoint.code === state.point;
        elements.pointSelect.append(option);
      });
    }
  }

  function renderMetrics(kpis) {
    elements.temperature.textContent = formatNumber(kpis?.latest_temperature_c);
    elements.humidity.textContent = formatNumber(kpis?.latest_humidity_rh);
    elements.minimum.textContent = formatNumber(kpis?.minimum_temperature_c);
    elements.maximum.textContent = formatNumber(kpis?.maximum_temperature_c);
    elements.latestTime.textContent = formatTime(kpis?.latest_measured_at, true);
    elements.measurementCount.textContent = `${kpis?.measurement_count ?? 0} Messwerte`;
  }

  function renderRecent(rows) {
    elements.recentTable.replaceChildren();
    if (rows.length === 0) {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = 5;
      cell.className = 'table-empty';
      cell.textContent = 'Noch keine Daten';
      row.append(cell);
      elements.recentTable.append(row);
      return;
    }

    rows.forEach((measurement) => {
      const row = document.createElement('tr');
      [
        formatTime(measurement.measured_at, true),
        `${formatNumber(measurement.temperature_c)} °C`,
        `${formatNumber(measurement.humidity_rh)} %`,
        `${measurement.battery_mv} mV`,
        String(measurement.sequence),
      ].forEach((value) => {
        const cell = document.createElement('td');
        cell.textContent = value;
        row.append(cell);
      });
      elements.recentTable.append(row);
    });
  }

  function renderDiagnostics(data) {
    const device = data.selected_device;
    const diagnostic = data.diagnostics;
    const online = isOnline(device, data.server_time);
    elements.deviceState.className = `state-badge ${online ? 'is-online' : 'is-stale'}`;
    elements.deviceState.textContent = online ? 'Online' : device ? 'Überfällig' : 'Unbekannt';

    const values = [
      diagnostic?.firmware_version ?? device?.firmware_version ?? '–',
      diagnostic?.hardware_revision ?? device?.hardware_revision ?? '–',
      diagnostic?.rssi_dbm == null ? '–' : `${diagnostic.rssi_dbm} dBm`,
      diagnostic?.wifi_connect_ms == null ? '–' : `${diagnostic.wifi_connect_ms} ms`,
      diagnostic?.battery_mv == null ? '–' : `${diagnostic.battery_mv} mV`,
      diagnostic?.boot_count == null ? '–' : String(diagnostic.boot_count),
    ];
    elements.diagnosticList.querySelectorAll('dd').forEach((element, index) => {
      element.textContent = values[index];
    });
  }

  function renderChart(series) {
    const canvas = elements.canvas;
    const context = canvas.getContext('2d');
    const rect = canvas.getBoundingClientRect();
    const density = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(rect.width * density);
    canvas.height = Math.round(rect.height * density);
    context.setTransform(density, 0, 0, density, 0, 0);
    context.clearRect(0, 0, rect.width, rect.height);

    elements.chartEmpty.hidden = series.length !== 0;
    elements.tooltip.hidden = true;
    if (series.length === 0) return;

    const styles = getComputedStyle(document.documentElement);
    const colors = {
      line: styles.getPropertyValue('--line').trim(),
      muted: styles.getPropertyValue('--muted').trim(),
      quiet: styles.getPropertyValue('--quiet').trim(),
      accent: styles.getPropertyValue('--accent').trim(),
      background: styles.getPropertyValue('--bg').trim(),
    };
    const pad = { left: 48, right: 48, top: 22, bottom: 34 };
    const width = rect.width - pad.left - pad.right;
    const height = rect.height - pad.top - pad.bottom;
    const temperatures = series.map((row) => row.temperature_c);
    const humidities = series.map((row) => row.humidity_rh);
    const extent = (values, fallbackPadding) => {
      let min = Math.min(...values);
      let max = Math.max(...values);
      const range = max - min || fallbackPadding;
      min -= range * 0.14;
      max += range * 0.14;
      return [min, max];
    };
    const [temperatureMin, temperatureMax] = extent(temperatures, 2);
    const [humidityMin, humidityMax] = extent(humidities, 10);
    const x = (index) => pad.left + (series.length === 1 ? width / 2 : (index / (series.length - 1)) * width);
    const yTemperature = (value) => pad.top + height - ((value - temperatureMin) / (temperatureMax - temperatureMin)) * height;
    const yHumidity = (value) => pad.top + height - ((value - humidityMin) / (humidityMax - humidityMin)) * height;

    context.lineWidth = 1;
    context.font = '10px ui-monospace, SFMono-Regular, Menlo, monospace';
    for (let step = 0; step <= 4; step += 1) {
      const y = pad.top + (height / 4) * step;
      context.strokeStyle = colors.line;
      context.beginPath();
      context.moveTo(pad.left, y);
      context.lineTo(rect.width - pad.right, y);
      context.stroke();
      context.fillStyle = colors.quiet;
      context.textAlign = 'left';
      context.fillText(formatNumber(temperatureMax - ((temperatureMax - temperatureMin) / 4) * step), 2, y + 3);
      context.textAlign = 'right';
      context.fillText(`${formatNumber(humidityMax - ((humidityMax - humidityMin) / 4) * step, 0)} %`, rect.width - 2, y + 3);
    }

    const labelIndices = [...new Set([0, Math.floor((series.length - 1) / 2), series.length - 1])];
    context.fillStyle = colors.quiet;
    labelIndices.forEach((index, position) => {
      context.textAlign = position === 0 ? 'left' : position === labelIndices.length - 1 ? 'right' : 'center';
      context.fillText(formatTime(series[index].measured_at, state.hours > 24), x(index), rect.height - 7);
    });

    const drawLine = (values, y, color, dashed = false) => {
      context.save();
      context.strokeStyle = color;
      context.lineWidth = dashed ? 1.2 : 2;
      context.lineJoin = 'round';
      context.lineCap = 'round';
      context.setLineDash(dashed ? [4, 5] : []);
      context.beginPath();
      values.forEach((value, index) => {
        const pointX = x(index);
        const pointY = y(value);
        if (index === 0) context.moveTo(pointX, pointY);
        else context.lineTo(pointX, pointY);
      });
      context.stroke();
      context.restore();
    };
    drawLine(humidities, yHumidity, colors.muted, true);
    drawLine(temperatures, yTemperature, colors.accent);

    if (state.hoverIndex != null && series[state.hoverIndex]) {
      const index = state.hoverIndex;
      const hoverX = x(index);
      context.strokeStyle = colors.muted;
      context.lineWidth = 1;
      context.setLineDash([3, 4]);
      context.beginPath();
      context.moveTo(hoverX, pad.top);
      context.lineTo(hoverX, pad.top + height);
      context.stroke();
      context.setLineDash([]);
      [[yTemperature(series[index].temperature_c), colors.accent], [yHumidity(series[index].humidity_rh), colors.muted]].forEach(([pointY, color]) => {
        context.fillStyle = colors.background;
        context.strokeStyle = color;
        context.lineWidth = 2;
        context.beginPath();
        context.arc(hoverX, pointY, 4, 0, Math.PI * 2);
        context.fill();
        context.stroke();
      });
    }

    canvas._chartGeometry = { series, pad, width, x };
  }

  function showTooltip(index, pointerY) {
    const row = state.data?.series?.[index];
    const geometry = elements.canvas._chartGeometry;
    if (!row || !geometry) return;
    elements.tooltip.replaceChildren();
    const time = document.createElement('strong');
    time.textContent = formatTime(row.measured_at, true);
    const temperature = document.createTextNode(`${formatNumber(row.temperature_c)} °C · ${formatNumber(row.humidity_rh)} % RH`);
    const battery = document.createElement('div');
    battery.textContent = `${row.battery_mv} mV · Sequenz ${row.sequence}`;
    elements.tooltip.append(time, temperature, battery);
    elements.tooltip.hidden = false;
    const tooltipWidth = 165;
    const rawLeft = geometry.x(index);
    elements.tooltip.style.left = `${Math.min(rawLeft, elements.canvas.clientWidth - tooltipWidth - 18)}px`;
    elements.tooltip.style.top = `${Math.max(45, Math.min(pointerY, elements.canvas.clientHeight - 45))}px`;
  }

  elements.canvas.addEventListener('pointermove', (event) => {
    const geometry = elements.canvas._chartGeometry;
    if (!geometry || geometry.series.length === 0) return;
    const rect = elements.canvas.getBoundingClientRect();
    const pointerX = event.clientX - rect.left;
    const normalized = Math.max(0, Math.min(1, (pointerX - geometry.pad.left) / geometry.width));
    const index = Math.round(normalized * (geometry.series.length - 1));
    if (state.hoverIndex !== index) {
      state.hoverIndex = index;
      renderChart(geometry.series);
    }
    showTooltip(index, event.clientY - rect.top);
  });
  elements.canvas.addEventListener('pointerleave', () => {
    state.hoverIndex = null;
    elements.tooltip.hidden = true;
    renderChart(state.data?.series ?? []);
  });

  elements.pointSelect.addEventListener('change', () => {
    state.point = elements.pointSelect.value;
    loadData();
  });
  document.querySelectorAll('[data-hours]').forEach((button) => {
    button.addEventListener('click', () => {
      state.hours = Number(button.dataset.hours);
      loadData();
    });
  });
  elements.refresh.addEventListener('click', loadData);

  const resizeObserver = new ResizeObserver(() => {
    if (state.data) renderChart(state.data.series ?? []);
  });
  resizeObserver.observe(elements.canvas);

  loadData();
  window.setInterval(loadData, 60_000);
})();
