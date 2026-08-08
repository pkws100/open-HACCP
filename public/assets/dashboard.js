(() => {
  'use strict';

  const state = {
    data: null,
    device: null,
    point: null,
    hours: 24,
    hoverIndex: null,
    loading: false,
    saving: false,
    inspectorOpen: false,
    enrollmentOpen: false,
    provisioning: false,
    setupPackage: null,
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
    temperatureStatus: document.querySelector('#temperature-status'),
    temperatureRange: document.querySelector('#temperature-range'),
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
    diagnosticSignal: document.querySelector('#diagnostic-signal'),
    diagnosticBattery: document.querySelector('#diagnostic-battery'),
    fleetSummary: document.querySelector('#fleet-summary'),
    settingsButton: document.querySelector('#settings-button'),
    settingsBackdrop: document.querySelector('#settings-backdrop'),
    settingsInspector: document.querySelector('#settings-inspector'),
    settingsForm: document.querySelector('#settings-form'),
    settingsClose: document.querySelector('#settings-close'),
    settingsCancel: document.querySelector('#settings-cancel'),
    settingsSave: document.querySelector('#settings-save'),
    settingsDevice: document.querySelector('#settings-device'),
    settingsMessage: document.querySelector('#settings-message'),
    alarmEnabled: document.querySelector('#alarm-enabled'),
    temperatureMin: document.querySelector('#temperature-min'),
    temperatureMax: document.querySelector('#temperature-max'),
    batteryLow: document.querySelector('#battery-low'),
    batteryFull: document.querySelector('#battery-full'),
    addDeviceButton: document.querySelector('#add-device-button'),
    enrollmentBackdrop: document.querySelector('#enrollment-backdrop'),
    enrollmentInspector: document.querySelector('#enrollment-inspector'),
    enrollmentForm: document.querySelector('#enrollment-form'),
    enrollmentClose: document.querySelector('#enrollment-close'),
    enrollmentCancel: document.querySelector('#enrollment-cancel'),
    enrollmentSubmit: document.querySelector('#enrollment-submit'),
    enrollmentDone: document.querySelector('#enrollment-done'),
    enrollmentMessage: document.querySelector('#enrollment-message'),
    enrollmentFields: document.querySelector('#enrollment-fields'),
    setupResult: document.querySelector('#setup-result'),
    enrollmentName: document.querySelector('#enrollment-name'),
    enrollmentUid: document.querySelector('#enrollment-uid'),
    enrollmentPointName: document.querySelector('#enrollment-point-name'),
    enrollmentPointCode: document.querySelector('#enrollment-point-code'),
    enrollmentLocation: document.querySelector('#enrollment-location'),
    enrollmentSensor: document.querySelector('#enrollment-sensor'),
    enrollmentAlarmEnabled: document.querySelector('#enrollment-alarm-enabled'),
    enrollmentTemperatureMin: document.querySelector('#enrollment-temperature-min'),
    enrollmentTemperatureMax: document.querySelector('#enrollment-temperature-max'),
    enrollmentBatteryLow: document.querySelector('#enrollment-battery-low'),
    enrollmentBatteryFull: document.querySelector('#enrollment-battery-full'),
    setupApiUrl: document.querySelector('#setup-api-url'),
    setupDeviceUid: document.querySelector('#setup-device-uid'),
    setupPointCode: document.querySelector('#setup-point-code'),
    setupDeviceKey: document.querySelector('#setup-device-key'),
    copySetupPackage: document.querySelector('#copy-setup-package'),
  };

  const alarmLabels = {
    normal: 'Im Bereich',
    below_min: 'Zu kalt',
    above_max: 'Zu warm',
    disabled: 'Deaktiviert',
    no_data: 'Keine Daten',
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

  const batteryIcon = (battery, includeValue = false) => {
    const wrapper = document.createElement('span');
    const stateName = battery?.state ?? 'unknown';
    const levels = { low: 1, medium: 2, full: 3, unknown: 0 };
    const widths = [0, 4, 8, 12];
    wrapper.className = `status-icon is-${stateName}`;
    wrapper.title = battery?.millivolts == null ? 'Batterie unbekannt' : `Batterie ${stateName}: ${battery.millivolts} mV`;
    wrapper.setAttribute('aria-label', wrapper.title);
    wrapper.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="16" height="10" rx="2"/><path d="M21 10v4"/><rect class="battery-fill" x="5" y="9" width="${widths[levels[stateName]]}" height="6" rx="1"/></svg>`;
    if (includeValue) wrapper.append(document.createTextNode(battery?.millivolts == null ? '–' : `${battery.millivolts} mV`));
    return wrapper;
  };

  const wifiIcon = (wifi, includeValue = false) => {
    const wrapper = document.createElement('span');
    const bars = wifi?.bars ?? 0;
    wrapper.className = `status-icon ${bars >= 4 ? 'is-strong' : ''}`;
    wrapper.title = wifi?.rssi_dbm == null ? 'WLAN-Signal unbekannt' : `WLAN ${bars} von 4 Balken: ${wifi.rssi_dbm} dBm`;
    wrapper.setAttribute('aria-label', wrapper.title);
    wrapper.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">
      <rect class="wifi-bar ${bars >= 1 ? 'is-on' : ''}" x="3" y="16" width="3" height="4" rx="1"/>
      <rect class="wifi-bar ${bars >= 2 ? 'is-on' : ''}" x="8" y="12" width="3" height="8" rx="1"/>
      <rect class="wifi-bar ${bars >= 3 ? 'is-on' : ''}" x="13" y="8" width="3" height="12" rx="1"/>
      <rect class="wifi-bar ${bars >= 4 ? 'is-on' : ''}" x="18" y="4" width="3" height="16" rx="1"/>
    </svg>`;
    if (includeValue) wrapper.append(document.createTextNode(wifi?.rssi_dbm == null ? '–' : `${wifi.rssi_dbm} dBm`));
    return wrapper;
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
    renderMetrics(data);
    renderChart(data.series ?? [], data.settings);
    renderRecent(data.recent_measurements ?? []);
    renderDiagnostics(data);
    elements.settingsButton.disabled = !data.selected_device;
    elements.fleetSummary.textContent = `${data.fleet.total_devices} Geräte · ${data.fleet.measurements_in_window} Messungen in ${data.window_hours} h`;
    document.querySelectorAll('[data-hours]').forEach((button) => {
      button.classList.toggle('is-active', Number(button.dataset.hours) === data.window_hours);
    });
    if (state.inspectorOpen) fillSettingsForm();
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
      const signals = document.createElement('span');
      button.type = 'button';
      button.className = 'device-button';
      button.classList.toggle('is-active', device.device_uid === state.device);
      button.classList.toggle('is-online', isOnline(device, data.server_time));
      button.setAttribute('aria-pressed', device.device_uid === state.device ? 'true' : 'false');
      pin.className = 'status-pin';
      name.textContent = device.name;
      uid.textContent = device.device_uid;
      copy.append(name, uid);
      signals.className = 'device-signals';
      signals.append(wifiIcon(device.wifi), batteryIcon(device.battery));
      button.append(pin, copy, signals);
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

  function renderMetrics(data) {
    const kpis = data.kpis;
    const alarm = data.settings?.alarm;
    const alarmState = kpis?.alarm_status ?? 'no_data';
    elements.temperature.textContent = formatNumber(kpis?.latest_temperature_c);
    elements.humidity.textContent = formatNumber(kpis?.latest_humidity_rh);
    elements.minimum.textContent = formatNumber(kpis?.minimum_temperature_c);
    elements.maximum.textContent = formatNumber(kpis?.maximum_temperature_c);
    elements.latestTime.textContent = formatTime(kpis?.latest_measured_at, true);
    elements.measurementCount.textContent = `${kpis?.measurement_count ?? 0} Messwerte`;
    elements.temperatureStatus.textContent = alarmLabels[alarmState] ?? 'Unbekannt';
    elements.temperatureStatus.className = `alarm-state ${alarmState === 'normal' ? 'is-normal' : ['below_min', 'above_max'].includes(alarmState) ? 'is-alarm' : ''}`;
    elements.temperatureRange.textContent = alarm?.enabled
      ? `Normal ${formatNumber(alarm.temperature_min_c)} bis ${formatNumber(alarm.temperature_max_c)} °C`
      : 'Grenzbereich nicht aktiv';
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
      null,
      diagnostic?.wifi_connect_ms == null ? '–' : `${diagnostic.wifi_connect_ms} ms`,
      null,
      diagnostic?.boot_count == null ? '–' : String(diagnostic.boot_count),
    ];
    elements.diagnosticList.querySelectorAll('dd').forEach((element, index) => {
      if (values[index] !== null) element.textContent = values[index];
    });
    elements.diagnosticSignal.replaceChildren(wifiIcon(device?.wifi, true));
    elements.diagnosticBattery.replaceChildren(batteryIcon(device?.battery, true));
  }

  function renderChart(series, settings) {
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
      accentSoft: styles.getPropertyValue('--accent-soft').trim(),
      background: styles.getPropertyValue('--bg').trim(),
    };
    const pad = { left: 48, right: 48, top: 22, bottom: 34 };
    const width = rect.width - pad.left - pad.right;
    const height = rect.height - pad.top - pad.bottom;
    const temperatures = series.map((row) => row.temperature_c);
    const humidities = series.map((row) => row.humidity_rh);
    const alarm = settings?.alarm;
    const temperatureScaleValues = [...temperatures];
    if (alarm?.enabled && alarm.temperature_min_c != null && alarm.temperature_max_c != null) {
      temperatureScaleValues.push(alarm.temperature_min_c, alarm.temperature_max_c);
    }
    const extent = (values, fallbackPadding) => {
      let min = Math.min(...values);
      let max = Math.max(...values);
      const range = max - min || fallbackPadding;
      min -= range * 0.14;
      max += range * 0.14;
      return [min, max];
    };
    const [temperatureMin, temperatureMax] = extent(temperatureScaleValues, 2);
    const [humidityMin, humidityMax] = extent(humidities, 10);
    const x = (index) => pad.left + (series.length === 1 ? width / 2 : (index / (series.length - 1)) * width);
    const yTemperature = (value) => pad.top + height - ((value - temperatureMin) / (temperatureMax - temperatureMin)) * height;
    const yHumidity = (value) => pad.top + height - ((value - humidityMin) / (humidityMax - humidityMin)) * height;

    if (alarm?.enabled && alarm.temperature_min_c != null && alarm.temperature_max_c != null) {
      const top = yTemperature(alarm.temperature_max_c);
      const bottom = yTemperature(alarm.temperature_min_c);
      context.fillStyle = colors.accentSoft;
      context.fillRect(pad.left, top, width, bottom - top);
      context.strokeStyle = colors.accent;
      context.globalAlpha = .28;
      context.setLineDash([3, 5]);
      [top, bottom].forEach((y) => {
        context.beginPath();
        context.moveTo(pad.left, y);
        context.lineTo(rect.width - pad.right, y);
        context.stroke();
      });
      context.globalAlpha = 1;
      context.setLineDash([]);
    }

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

  function openSettings() {
    if (!state.data?.settings) return;
    state.inspectorOpen = true;
    elements.settingsBackdrop.hidden = false;
    elements.settingsInspector.hidden = false;
    document.body.style.overflow = 'hidden';
    clearFormMessages();
    fillSettingsForm();
    window.requestAnimationFrame(() => elements.alarmEnabled.focus());
  }

  function openEnrollment() {
    state.enrollmentOpen = true;
    state.setupPackage = null;
    elements.enrollmentForm.reset();
    elements.enrollmentAlarmEnabled.checked = true;
    elements.enrollmentTemperatureMin.value = '2';
    elements.enrollmentTemperatureMax.value = '7';
    elements.enrollmentBatteryLow.value = '5600';
    elements.enrollmentBatteryFull.value = '6000';
    elements.enrollmentSensor.value = 'SHT45';
    elements.enrollmentFields.hidden = false;
    elements.setupResult.hidden = true;
    elements.enrollmentCancel.hidden = false;
    elements.enrollmentSubmit.hidden = false;
    elements.enrollmentDone.hidden = true;
    elements.enrollmentBackdrop.hidden = false;
    elements.enrollmentInspector.hidden = false;
    document.body.style.overflow = 'hidden';
    clearEnrollmentMessages();
    window.requestAnimationFrame(() => elements.enrollmentName.focus());
  }

  function closeEnrollment(force = false) {
    if (state.provisioning) return;
    if (state.setupPackage && !force && !window.confirm('Der einmalige Geräteschlüssel wird nach dem Schließen nicht erneut angezeigt. Wurden die Daten gesichert?')) return;
    state.enrollmentOpen = false;
    state.setupPackage = null;
    elements.setupDeviceKey.textContent = '–';
    elements.enrollmentBackdrop.hidden = true;
    elements.enrollmentInspector.hidden = true;
    document.body.style.overflow = '';
    elements.addDeviceButton.focus();
  }

  function clearEnrollmentMessages() {
    elements.enrollmentMessage.hidden = true;
    elements.enrollmentMessage.textContent = '';
    elements.enrollmentForm.querySelectorAll('[data-enrollment-error]').forEach((element) => {
      element.hidden = true;
      element.textContent = '';
    });
    elements.enrollmentForm.querySelectorAll('[aria-invalid]').forEach((element) => element.removeAttribute('aria-invalid'));
  }

  function showEnrollmentError(group, message) {
    const output = elements.enrollmentForm.querySelector(`[data-enrollment-error="${group}"]`);
    if (output) {
      output.textContent = message;
      output.hidden = false;
    }
  }

  function enrollmentPayload() {
    const uid = elements.enrollmentUid.value.trim();
    return {
      ...(uid ? { device_uid: uid } : {}),
      name: elements.enrollmentName.value.trim(),
      measurement_point: {
        code: elements.enrollmentPointCode.value.trim(),
        name: elements.enrollmentPointName.value.trim(),
        sensor_type: elements.enrollmentSensor.value.trim(),
        location: elements.enrollmentLocation.value.trim() || null,
      },
      alarm: {
        enabled: elements.enrollmentAlarmEnabled.checked,
        temperature_min_c: elements.enrollmentTemperatureMin.value === '' ? null : Number(elements.enrollmentTemperatureMin.value),
        temperature_max_c: elements.enrollmentTemperatureMax.value === '' ? null : Number(elements.enrollmentTemperatureMax.value),
      },
      battery: {
        low_threshold_mv: Number(elements.enrollmentBatteryLow.value),
        full_threshold_mv: Number(elements.enrollmentBatteryFull.value),
      },
    };
  }

  function validateEnrollment(payload) {
    let valid = true;
    if (!payload.name || payload.name.length > 160 || (payload.device_uid && !/^[a-z0-9][a-z0-9-]{2,63}$/.test(payload.device_uid))) {
      showEnrollmentError('identity', 'Bitte eine Bezeichnung und optional eine gültige UID aus Kleinbuchstaben, Ziffern und Bindestrichen eingeben.');
      valid = false;
    }
    if (!/^[a-z0-9][a-z0-9-]{0,63}$/.test(payload.measurement_point.code)
      || !payload.measurement_point.name || !payload.measurement_point.sensor_type) {
      showEnrollmentError('measurement_point', 'Name, Sensortyp und eine kleingeschriebene Kennung aus Buchstaben, Ziffern und Bindestrichen werden benötigt.');
      valid = false;
    }
    const min = payload.alarm.temperature_min_c;
    const max = payload.alarm.temperature_max_c;
    const low = payload.battery.low_threshold_mv;
    const full = payload.battery.full_threshold_mv;
    if ((payload.alarm.enabled && (min == null || max == null))
      || (min != null && (!Number.isFinite(min) || min < -100 || min > 150))
      || (max != null && (!Number.isFinite(max) || max < -100 || max > 150))
      || (min != null && max != null && min >= max)
      || !Number.isInteger(low) || !Number.isInteger(full) || low < 0 || full > 10000 || low >= full) {
      showEnrollmentError('settings', 'Temperaturbereich und Batterieschwellen sind nicht plausibel. Minimum beziehungsweise „Niedrig“ muss kleiner als der obere Wert sein.');
      valid = false;
    }
    return valid;
  }

  function renderSetupPackage(setupPackage) {
    elements.setupApiUrl.textContent = setupPackage.api_base_url;
    elements.setupDeviceUid.textContent = setupPackage.device_uid;
    elements.setupPointCode.textContent = setupPackage.measurement_point;
    elements.setupDeviceKey.textContent = setupPackage.device_key;
    elements.enrollmentFields.hidden = true;
    elements.setupResult.hidden = false;
    elements.enrollmentCancel.hidden = true;
    elements.enrollmentSubmit.hidden = true;
    elements.enrollmentDone.hidden = false;
    elements.enrollmentMessage.hidden = true;
    elements.enrollmentDone.focus();
  }

  async function provisionDevice(event) {
    event.preventDefault();
    if (state.provisioning || state.setupPackage) return;
    clearEnrollmentMessages();
    const payload = enrollmentPayload();
    if (!validateEnrollment(payload)) return;

    state.provisioning = true;
    elements.enrollmentSubmit.disabled = true;
    elements.enrollmentCancel.disabled = true;
    elements.enrollmentSubmit.textContent = 'Wird vorbereitet …';
    try {
      const response = await fetch('/api/v1/dashboard/devices', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const json = await response.json();
      if (!response.ok) {
        if (response.status === 422) {
          const fields = json.error?.details?.fields ?? {};
          const identity = Object.entries(fields).filter(([field]) => ['name', 'device_uid'].includes(field)).map(([, message]) => message);
          const point = Object.entries(fields).filter(([field]) => field.startsWith('measurement_point')).map(([, message]) => message);
          const settings = Object.entries(fields).filter(([field]) => field.startsWith('alarm') || field.startsWith('battery')).map(([, message]) => message);
          if (identity.length) showEnrollmentError('identity', identity.join(' '));
          if (point.length) showEnrollmentError('measurement_point', point.join(' '));
          if (settings.length) showEnrollmentError('settings', settings.join(' '));
          elements.enrollmentMessage.textContent = 'Bitte korrigieren Sie die markierten Angaben.';
        } else if (response.status === 409) {
          elements.enrollmentMessage.textContent = 'Diese Geräte-UID ist bereits vergeben. Bitte eine andere UID verwenden oder das Feld automatisch erzeugen lassen.';
        } else {
          elements.enrollmentMessage.textContent = json.error?.message ?? `Geräteanlage fehlgeschlagen (HTTP ${response.status}).`;
        }
        elements.enrollmentMessage.hidden = false;
        return;
      }

      state.setupPackage = json.setup_package;
      renderSetupPackage(state.setupPackage);
      state.device = json.device.device_uid;
      state.point = json.setup_package.measurement_point;
      await loadData();
    } catch (error) {
      elements.enrollmentMessage.textContent = 'Gerät konnte nicht vorbereitet werden. Bitte Verbindung prüfen und erneut versuchen.';
      elements.enrollmentMessage.hidden = false;
    } finally {
      state.provisioning = false;
      elements.enrollmentSubmit.disabled = false;
      elements.enrollmentCancel.disabled = false;
      elements.enrollmentSubmit.textContent = 'Gerät vorbereiten';
    }
  }

  async function copyText(value, button) {
    try {
      await navigator.clipboard.writeText(value);
    } catch (_) {
      const textarea = document.createElement('textarea');
      textarea.value = value;
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.append(textarea);
      textarea.select();
      document.execCommand('copy');
      textarea.remove();
    }
    const original = button.textContent;
    button.textContent = 'Kopiert';
    window.setTimeout(() => { button.textContent = original; }, 1200);
  }

  function closeSettings() {
    if (state.saving) return;
    state.inspectorOpen = false;
    elements.settingsBackdrop.hidden = true;
    elements.settingsInspector.hidden = true;
    document.body.style.overflow = '';
    elements.settingsButton.focus();
  }

  function fillSettingsForm() {
    const settings = state.data?.settings;
    if (!settings) return;
    elements.settingsDevice.textContent = `${state.data.selected_device.name} · Version ${settings.config_version}`;
    elements.alarmEnabled.checked = settings.alarm.enabled;
    elements.temperatureMin.value = settings.alarm.temperature_min_c ?? '';
    elements.temperatureMax.value = settings.alarm.temperature_max_c ?? '';
    elements.batteryLow.value = settings.battery.low_threshold_mv;
    elements.batteryFull.value = settings.battery.full_threshold_mv;
  }

  function clearFormMessages() {
    elements.settingsMessage.hidden = true;
    elements.settingsMessage.className = 'form-message';
    elements.settingsForm.querySelectorAll('.field-error').forEach((element) => {
      element.hidden = true;
      element.textContent = '';
    });
    elements.settingsForm.querySelectorAll('[aria-invalid]').forEach((element) => element.removeAttribute('aria-invalid'));
  }

  function showFieldError(group, message) {
    const output = elements.settingsForm.querySelector(`[data-error-for="${group}"]`);
    if (output) {
      output.textContent = message;
      output.hidden = false;
    }
    const inputs = group === 'alarm'
      ? [elements.temperatureMin, elements.temperatureMax]
      : [elements.batteryLow, elements.batteryFull];
    inputs.forEach((input) => input.setAttribute('aria-invalid', 'true'));
  }

  function settingsPayload() {
    const min = elements.temperatureMin.value === '' ? null : Number(elements.temperatureMin.value);
    const max = elements.temperatureMax.value === '' ? null : Number(elements.temperatureMax.value);
    const low = Number(elements.batteryLow.value);
    const full = Number(elements.batteryFull.value);
    return {
      expected_config_version: state.data.settings.config_version,
      alarm: { enabled: elements.alarmEnabled.checked, temperature_min_c: min, temperature_max_c: max },
      battery: { low_threshold_mv: low, full_threshold_mv: full },
    };
  }

  function validateSettings(payload) {
    let valid = true;
    if (payload.alarm.enabled && (payload.alarm.temperature_min_c == null || payload.alarm.temperature_max_c == null)) {
      showFieldError('alarm', 'Bei aktivem Alarm werden beide Temperaturgrenzen benötigt.');
      valid = false;
    } else if (payload.alarm.temperature_min_c != null && payload.alarm.temperature_max_c != null
      && (payload.alarm.temperature_min_c < -100 || payload.alarm.temperature_max_c > 150
        || payload.alarm.temperature_min_c >= payload.alarm.temperature_max_c)) {
      showFieldError('alarm', 'Minimum und Maximum müssen zwischen −100 und 150 °C liegen; Minimum muss kleiner sein.');
      valid = false;
    }
    if (!Number.isInteger(payload.battery.low_threshold_mv) || !Number.isInteger(payload.battery.full_threshold_mv)
      || payload.battery.low_threshold_mv < 0 || payload.battery.full_threshold_mv > 10000
      || payload.battery.low_threshold_mv >= payload.battery.full_threshold_mv) {
      showFieldError('battery', 'Beide Werte müssen ganze Millivoltwerte von 0 bis 10000 sein; „Niedrig“ muss kleiner sein.');
      valid = false;
    }
    return valid;
  }

  async function saveSettings(event) {
    event.preventDefault();
    if (state.saving || !state.data?.settings) return;
    clearFormMessages();
    const payload = settingsPayload();
    if (!validateSettings(payload)) return;

    state.saving = true;
    elements.settingsSave.disabled = true;
    elements.settingsCancel.disabled = true;
    elements.settingsSave.textContent = 'Wird gespeichert …';
    try {
      const response = await fetch(`/api/v1/dashboard/devices/${encodeURIComponent(state.device)}/settings`, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const json = await response.json();
      if (!response.ok) {
        if (response.status === 409) {
          await loadData();
          elements.settingsMessage.textContent = 'Die Konfiguration wurde zwischenzeitlich geändert. Die aktuellen Werte wurden neu geladen; bitte prüfen und erneut speichern.';
        } else if (response.status === 422) {
          const fields = json.error?.details?.fields ?? {};
          const alarmErrors = Object.entries(fields).filter(([field]) => field.startsWith('alarm')).map(([, message]) => message);
          const batteryErrors = Object.entries(fields).filter(([field]) => field.startsWith('battery')).map(([, message]) => message);
          if (alarmErrors.length) showFieldError('alarm', alarmErrors.join(' '));
          if (batteryErrors.length) showFieldError('battery', batteryErrors.join(' '));
          elements.settingsMessage.textContent = 'Bitte korrigieren Sie die markierten Werte.';
        } else {
          elements.settingsMessage.textContent = json.error?.message ?? `Speichern fehlgeschlagen (HTTP ${response.status}).`;
        }
        elements.settingsMessage.hidden = false;
        return;
      }

      await loadData();
      elements.settingsMessage.textContent = `Gespeichert als Konfigurationsversion ${json.config_version}.`;
      elements.settingsMessage.classList.add('is-success');
      elements.settingsMessage.hidden = false;
      window.setTimeout(closeSettings, 850);
    } catch (error) {
      elements.settingsMessage.textContent = 'Speichern nicht möglich. Bitte Verbindung prüfen und erneut versuchen.';
      elements.settingsMessage.hidden = false;
      console.error('Dashboard settings update failed', error);
    } finally {
      state.saving = false;
      elements.settingsSave.disabled = false;
      elements.settingsCancel.disabled = false;
      elements.settingsSave.textContent = 'Änderungen speichern';
    }
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
      renderChart(geometry.series, state.data?.settings);
    }
    showTooltip(index, event.clientY - rect.top);
  });
  elements.canvas.addEventListener('pointerleave', () => {
    state.hoverIndex = null;
    elements.tooltip.hidden = true;
    renderChart(state.data?.series ?? [], state.data?.settings);
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
  elements.addDeviceButton.addEventListener('click', openEnrollment);
  elements.settingsButton.addEventListener('click', openSettings);
  elements.settingsClose.addEventListener('click', closeSettings);
  elements.settingsCancel.addEventListener('click', closeSettings);
  elements.settingsBackdrop.addEventListener('click', closeSettings);
  elements.settingsForm.addEventListener('submit', saveSettings);
  elements.enrollmentClose.addEventListener('click', () => closeEnrollment());
  elements.enrollmentCancel.addEventListener('click', () => closeEnrollment());
  elements.enrollmentDone.addEventListener('click', () => closeEnrollment(true));
  elements.enrollmentBackdrop.addEventListener('click', () => closeEnrollment());
  elements.enrollmentForm.addEventListener('submit', provisionDevice);
  elements.enrollmentForm.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!state.setupPackage) return;
      copyText(String(state.setupPackage[button.dataset.copy] ?? ''), button);
    });
  });
  elements.copySetupPackage.addEventListener('click', () => {
    if (!state.setupPackage) return;
    copyText(JSON.stringify(state.setupPackage, null, 2), elements.copySetupPackage);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && state.inspectorOpen) closeSettings();
    else if (event.key === 'Escape' && state.enrollmentOpen) closeEnrollment();
  });

  const resizeObserver = new ResizeObserver(() => {
    if (state.data) renderChart(state.data.series ?? [], state.data.settings);
  });
  resizeObserver.observe(elements.canvas);

  loadData();
  window.setInterval(loadData, 60_000);
})();
