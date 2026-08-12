#include <Adafruit_SHT4x.h>
#include <Arduino.h>
#include <WiFi.h>
#include <Wire.h>
#include <esp_sleep.h>
#include <esp_system.h>
#include <time.h>

#include "DeviceState.h"
#include "FirmwareConfig.h"
#include "HaccpClient.h"
#include "ProvisioningPortal.h"

namespace {
DeviceState deviceState;
HaccpClient haccpClient;
ProvisioningPortal provisioningPortal(deviceState, haccpClient);
ProvisioningConfig provisioning;
RuntimeConfig runtimeConfig;
Adafruit_SHT4x sensor;

bool portalMode = false;
bool sensorReady = false;
uint32_t bootCount = 0;
uint32_t wifiConnectMs = 0;
String currentWakeReason;
String currentResetReason;

bool elapsed(uint32_t since, uint32_t interval)
{
    return static_cast<uint32_t>(millis() - since) >= interval;
}

bool validClock()
{
    return time(nullptr) >= 1704067200;
}

String wakeReason()
{
    switch (esp_sleep_get_wakeup_cause()) {
        case ESP_SLEEP_WAKEUP_TIMER: return "timer";
        case ESP_SLEEP_WAKEUP_EXT0: return "external_pin_0";
        case ESP_SLEEP_WAKEUP_EXT1: return "external_pin_1";
        case ESP_SLEEP_WAKEUP_GPIO: return "gpio";
        case ESP_SLEEP_WAKEUP_TOUCHPAD: return "touch";
        case ESP_SLEEP_WAKEUP_ULP: return "ulp";
        case ESP_SLEEP_WAKEUP_UNDEFINED: return "cold_boot";
        default: return "other";
    }
}

String resetReason()
{
    switch (esp_reset_reason()) {
        case ESP_RST_POWERON: return "power_on";
        case ESP_RST_SW: return "software";
        case ESP_RST_PANIC: return "panic";
        case ESP_RST_INT_WDT: return "interrupt_watchdog";
        case ESP_RST_TASK_WDT: return "task_watchdog";
        case ESP_RST_WDT: return "watchdog";
        case ESP_RST_DEEPSLEEP: return "deep_sleep";
        case ESP_RST_BROWNOUT: return "brownout";
        default: return "other";
    }
}

bool synchronizeClock()
{
    if (validClock()) {
        return true;
    }
    configTime(0, 0, "pool.ntp.org", "time.cloudflare.com");
    const uint32_t startedAt = millis();
    while (!validClock() && !elapsed(startedAt, OPEN_HACCP_CLOCK_SYNC_TIMEOUT_MS)) {
        delay(200);
    }
    if (!validClock()) {
        deviceState.recordClockSyncFailure();
        return false;
    }
    return true;
}

bool connectWifi()
{
    if (WiFi.status() == WL_CONNECTED) {
        return true;
    }
    const uint32_t overallStartedAt = millis();
    for (uint8_t attempt = 0; attempt < OPEN_HACCP_WIFI_CONNECT_ATTEMPTS; ++attempt) {
        WiFi.disconnect(false, false);
        WiFi.begin(provisioning.wifiSsid.c_str(), provisioning.wifiPassword.c_str());
        const uint32_t startedAt = millis();
        while (WiFi.status() != WL_CONNECTED && !elapsed(startedAt, OPEN_HACCP_WIFI_CONNECT_TIMEOUT_MS)) {
            delay(200);
        }
        if (WiFi.status() == WL_CONNECTED) {
            wifiConnectMs = min(millis() - overallStartedAt, 120000UL);
            deviceState.recordWifiSuccess();
            return true;
        }
        deviceState.recordWifiFailure();
    }
    wifiConnectMs = min(millis() - overallStartedAt, 120000UL);
    return false;
}

uint16_t batteryMillivolts()
{
#if OPEN_HACCP_BATTERY_ADC_PIN >= 0
    const uint32_t pinMillivolts = analogReadMilliVolts(OPEN_HACCP_BATTERY_ADC_PIN);
    return static_cast<uint16_t>(constrain(
        static_cast<uint32_t>(lroundf(pinMillivolts * OPEN_HACCP_BATTERY_DIVIDER)),
        0UL,
        10000UL
    ));
#else
    return OPEN_HACCP_BATTERY_FALLBACK_MV;
#endif
}

DeviceDiagnostics diagnostics()
{
    const int rssi = WiFi.status() == WL_CONNECTED ? WiFi.RSSI() : -120;
    DeviceDiagnostics value;
    value.batteryMv = batteryMillivolts();
    value.rssiDbm = static_cast<int16_t>(constrain(rssi, -120, 0));
    value.wifiConnectMs = wifiConnectMs;
    value.bootCount = bootCount;
    value.awakeMs = millis();
    value.queueDepth = deviceState.pendingCount();
    value.sensorReady = sensorReady;
    value.wakeReason = currentWakeReason;
    value.resetReason = currentResetReason;
    value.requestedSleepMode = OPEN_HACCP_DISABLE_DEEP_SLEEP ? "light_sleep_fallback" : "deep_sleep";
    value.operational = deviceState.operational();
    value.errorCount = deviceState.diagnosticCodes(value.errors, 8);
    return value;
}

bool applyConfiguration(const RuntimeConfig &candidate)
{
    if (candidate.configVersion < runtimeConfig.configVersion) {
        return true;
    }
    if (candidate.configVersion == runtimeConfig.configVersion
        && deviceState.operational().appliedConfigVersion == candidate.configVersion
        && deviceState.operational().configStatus == ConfigApplyStatus::Applied) {
        return true;
    }
    if (!deviceState.saveRuntime(candidate)) {
        deviceState.recordConfigRejected();
        Serial.println("Configuration could not be persisted; last known-good version remains active.");
        return false;
    }
    runtimeConfig = candidate;
    deviceState.recordConfigApplied(candidate.configVersion);
    Serial.printf("Applied configuration version %lu (sample=%lus, upload=%lus).\n",
        static_cast<unsigned long>(runtimeConfig.configVersion),
        static_cast<unsigned long>(runtimeConfig.measurementIntervalSeconds),
        static_cast<unsigned long>(runtimeConfig.uploadIntervalSeconds));
    return true;
}

bool fetchAndApplyConfig(int64_t now)
{
    RuntimeConfig candidate = runtimeConfig;
    String error;
    if (!haccpClient.fetchConfig(provisioning, candidate, error)) {
        deviceState.recordConfigRejected();
        Serial.println("Configuration refresh failed: " + error);
        return false;
    }
    deviceState.recordConfigCheck(now);
    return applyConfiguration(candidate);
}

void sampleSensor(int64_t now)
{
    deviceState.recordSample(now);
    if (!sensorReady) {
        deviceState.recordSensorUnavailable();
        return;
    }
    sensors_event_t humidity;
    sensors_event_t temperature;
    sensor.getEvent(&humidity, &temperature);
    if (!isfinite(temperature.temperature) || !isfinite(humidity.relative_humidity)
        || temperature.temperature < -100 || temperature.temperature > 150
        || humidity.relative_humidity < 0 || humidity.relative_humidity > 100) {
        deviceState.recordSensorUnavailable();
        Serial.println("SHT45 sample rejected locally.");
        return;
    }
    if (!deviceState.enqueue(
        now,
        temperature.temperature,
        humidity.relative_humidity,
        batteryMillivolts()
    )) {
        deviceState.recordQueueFull();
        Serial.println("Offline queue is full or could not be persisted; no record was overwritten.");
        return;
    }
    Serial.printf("Measurement queued (pending=%u).\n", static_cast<unsigned>(deviceState.pendingCount()));
}

uint32_t retryDelaySeconds()
{
    constexpr uint32_t RetrySeconds[] = {60, 300, 900, 1800, 3600};
    const uint8_t step = min(deviceState.operational().retryStep, static_cast<uint8_t>(4));
    const uint32_t base = RetrySeconds[step];
    const int32_t jitterRange = static_cast<int32_t>(base / 10U > 0 ? base / 10U : 1U);
    const int32_t jitter = static_cast<int32_t>(esp_random() % (jitterRange * 2U + 1U)) - jitterRange;
    return static_cast<uint32_t>(static_cast<int32_t>(base) + jitter);
}

void scheduleRetry(int64_t now)
{
    deviceState.scheduleNetworkRetry(now, retryDelaySeconds());
}

bool handleReceivedConfiguration(
    const RuntimeConfig &candidate,
    bool configurationValid,
    const String &configurationError,
    int64_t now
)
{
    if (configurationValid) {
        deviceState.recordConfigCheck(now);
        return applyConfiguration(candidate);
    }

    deviceState.recordConfigRejected();
    Serial.println("Piggyback configuration rejected: " + configurationError);
    return fetchAndApplyConfig(now);
}

bool uploadOrHeartbeat(int64_t now)
{
    const size_t pendingBefore = deviceState.pendingCount();
    const uint32_t appliedVersionBeforeRequest = deviceState.operational().appliedConfigVersion;
    RuntimeConfig receivedConfig = runtimeConfig;
    bool configurationValid = false;
    String configurationError;
    String error;
    bool requestSucceeded = false;

    if (pendingBefore == 0) {
        requestSucceeded = haccpClient.sendHeartbeat(
            provisioning,
            runtimeConfig,
            diagnostics(),
            receivedConfig,
            configurationValid,
            configurationError,
            error
        );
    } else {
        uint64_t acknowledged[DeviceState::QueueCapacity]{};
        size_t acknowledgedCount = 0;
        uint32_t reportedConfigVersion = runtimeConfig.configVersion;
        requestSucceeded = haccpClient.uploadMeasurements(
            provisioning,
            runtimeConfig,
            diagnostics(),
            deviceState.pendingItems(),
            pendingBefore,
            acknowledged,
            acknowledgedCount,
            reportedConfigVersion,
            receivedConfig,
            configurationValid,
            configurationError,
            error
        );
        if (requestSucceeded) {
            deviceState.acknowledge(acknowledged, acknowledgedCount);
            Serial.printf("Batch processed; %u records acknowledged, %u remain.\n",
                static_cast<unsigned>(acknowledgedCount),
                static_cast<unsigned>(deviceState.pendingCount()));
        }
    }

    if (!requestSucceeded) {
        deviceState.recordTransportFailure();
        scheduleRetry(now);
        Serial.println("HTTPS request failed: " + error);
        return false;
    }

    // All counters and error flags present in this accepted envelope are now reported.
    deviceState.markTelemetryDelivered();
    deviceState.recordTransmissionSuccess(now);
    const bool configurationHandled = handleReceivedConfiguration(
        receivedConfig,
        configurationValid,
        configurationError,
        now
    );
    if (!configurationHandled) {
        deviceState.recordTransportFailure();
        scheduleRetry(now);
    } else if (deviceState.operational().appliedConfigVersion > appliedVersionBeforeRequest) {
        // Confirm durable activation in the same wake cycle. A failed confirmation remains
        // visible through the persisted applied version and is retried on the next contact.
        RuntimeConfig confirmationConfig = runtimeConfig;
        bool confirmationConfigValid = false;
        String confirmationConfigError;
        String confirmationError;
        if (haccpClient.sendHeartbeat(
            provisioning,
            runtimeConfig,
            diagnostics(),
            confirmationConfig,
            confirmationConfigValid,
            confirmationConfigError,
            confirmationError
        )) {
            deviceState.markTelemetryDelivered();
            deviceState.recordTransmissionSuccess(now);
            if (confirmationConfigValid && confirmationConfig.configVersion > runtimeConfig.configVersion) {
                applyConfiguration(confirmationConfig);
            }
            Serial.println("Applied configuration version confirmed to backend.");
        } else {
            deviceState.recordTransportFailure();
            scheduleRetry(now);
            Serial.println("Configuration confirmation failed: " + confirmationError);
        }
    }

    if (pendingBefore > 0 && deviceState.pendingCount() > 0) {
        deviceState.recordAckIncomplete();
        scheduleRetry(now);
    }
    return true;
}

int64_t dueAt(int64_t last, uint32_t interval, int64_t now)
{
    return last <= 0 ? now : last + interval;
}

uint32_t nextSleepSeconds(int64_t now)
{
    const OperationalState &state = deviceState.operational();
    if (!validClock()) {
        if (state.nextNetworkAttemptAt > now) {
            return static_cast<uint32_t>(max(
                static_cast<int64_t>(OPEN_HACCP_MINIMUM_SLEEP_SECONDS),
                state.nextNetworkAttemptAt - now
            ));
        }
        return 60;
    }
    int64_t next = dueAt(state.lastSampleAt, runtimeConfig.measurementIntervalSeconds, now);
    next = min(next, dueAt(state.lastSuccessfulTransmissionAt, runtimeConfig.uploadIntervalSeconds, now));
    next = min(next, dueAt(state.lastConfigCheckAt, OPEN_HACCP_CONFIG_REFRESH_SECONDS, now));
    if (state.nextNetworkAttemptAt > 0) {
        next = min(next, state.nextNetworkAttemptAt);
    }
    if (next <= now) {
        return OPEN_HACCP_MINIMUM_SLEEP_SECONDS;
    }
    const int64_t difference = next - now;
    return static_cast<uint32_t>(constrain(
        difference,
        static_cast<int64_t>(OPEN_HACCP_MINIMUM_SLEEP_SECONDS),
        static_cast<int64_t>(604800)
    ));
}

void powerDownAndSleep(uint32_t seconds)
{
    if (seconds < static_cast<uint32_t>(OPEN_HACCP_MINIMUM_SLEEP_SECONDS)) {
        seconds = static_cast<uint32_t>(OPEN_HACCP_MINIMUM_SLEEP_SECONDS);
    }
    WiFi.disconnect(true, false);
    WiFi.mode(WIFI_OFF);
    Wire.end();
    delay(20);

    const uint64_t sleepUs = static_cast<uint64_t>(seconds) * 1000000ULL;
    deviceState.recordSleepMode(SleepMode::DeepSleep);
    esp_err_t timerResult = esp_sleep_enable_timer_wakeup(sleepUs);
#if !OPEN_HACCP_DISABLE_DEEP_SLEEP
    if (timerResult == ESP_OK) {
        Serial.printf("Deep sleep for %lu seconds.\n", static_cast<unsigned long>(seconds));
        Serial.flush();
        esp_deep_sleep_start();
    }
#endif

    deviceState.recordSleepFallback(SleepMode::LightSleepFallback);
    if (timerResult != ESP_OK) {
        timerResult = esp_sleep_enable_timer_wakeup(sleepUs);
    }
    if (timerResult == ESP_OK) {
        Serial.printf("Deep sleep unavailable; light-sleep fallback for %lu seconds.\n",
            static_cast<unsigned long>(seconds));
        Serial.flush();
        if (esp_light_sleep_start() == ESP_OK) {
            ESP.restart();
        }
    }

    deviceState.recordSleepFallback(SleepMode::AwakeRestartFallback);
    Serial.println("Sleep timer unavailable; bounded awake restart fallback active.");
    Serial.flush();
    const uint32_t startedAt = millis();
    const uint32_t fallbackMs = (seconds < 3600U ? seconds : 3600U) * 1000U;
    while (!elapsed(startedAt, fallbackMs)) {
        delay(250);
    }
    ESP.restart();
}

void startPortal()
{
    portalMode = true;
    provisioningPortal.begin();
}

bool factoryResetRequested()
{
    pinMode(OPEN_HACCP_FACTORY_RESET_PIN, INPUT_PULLUP);
    if (digitalRead(OPEN_HACCP_FACTORY_RESET_PIN) != LOW) {
        return false;
    }
    const uint32_t pressedAt = millis();
    while (digitalRead(OPEN_HACCP_FACTORY_RESET_PIN) == LOW && !elapsed(pressedAt, 5000)) {
        delay(20);
    }
    return elapsed(pressedAt, 5000);
}

void runWakeCycle()
{
    int64_t now = validClock() ? static_cast<int64_t>(time(nullptr)) : 0;
    const OperationalState initialState = deviceState.operational();

    if (validClock() && now >= dueAt(initialState.lastSampleAt, runtimeConfig.measurementIntervalSeconds, now)) {
        sampleSensor(now);
    }

    bool needsClock = !validClock();
    bool uploadDue = validClock()
        && now >= dueAt(initialState.lastSuccessfulTransmissionAt, runtimeConfig.uploadIntervalSeconds, now);
    bool configDue = validClock()
        && now >= dueAt(initialState.lastConfigCheckAt, OPEN_HACCP_CONFIG_REFRESH_SECONDS, now);
    const bool retryDue = validClock() && initialState.nextNetworkAttemptAt > 0
        && now >= initialState.nextNetworkAttemptAt;
    const bool queuePressure = deviceState.pendingCount() >= runtimeConfig.maxBatchSize;
    bool networkDue = needsClock || uploadDue || configDue || retryDue || queuePressure;
    if (networkDue && validClock() && initialState.nextNetworkAttemptAt > now && !queuePressure) {
        networkDue = false;
    }

    if (networkDue) {
        if (!connectWifi()) {
            const int64_t retryBase = validClock() ? static_cast<int64_t>(time(nullptr)) : 1704067200;
            scheduleRetry(retryBase);
        } else if (!synchronizeClock()) {
            scheduleRetry(validClock() ? static_cast<int64_t>(time(nullptr)) : 1704067200);
        } else {
            now = static_cast<int64_t>(time(nullptr));
            const int64_t lastSampleAt = deviceState.operational().lastSampleAt;
            if (lastSampleAt <= 0
                || now >= dueAt(lastSampleAt, runtimeConfig.measurementIntervalSeconds, now)) {
                sampleSensor(now);
            }
            uploadDue = now >= dueAt(
                deviceState.operational().lastSuccessfulTransmissionAt,
                runtimeConfig.uploadIntervalSeconds,
                now
            );
            configDue = now >= dueAt(deviceState.operational().lastConfigCheckAt, OPEN_HACCP_CONFIG_REFRESH_SECONDS, now);
            if (uploadDue || configDue || deviceState.pendingCount() >= runtimeConfig.maxBatchSize) {
                uploadOrHeartbeat(now);
            }
        }
    }

    now = validClock() ? static_cast<int64_t>(time(nullptr)) : 1704067200;
    powerDownAndSleep(nextSleepSeconds(now));
}
}

void setup()
{
    Serial.begin(115200);
    delay(250);
    Serial.println("Open HACCP power-managed firmware starting.");

    currentWakeReason = wakeReason();
    currentResetReason = resetReason();
    if (factoryResetRequested()) {
        deviceState.factoryReset();
        Serial.println("Factory reset completed; entering provisioning mode.");
    }
    if (!deviceState.loadProvisioning(provisioning)) {
        startPortal();
        return;
    }

    bootCount = deviceState.incrementBootCount();
    deviceState.loadRuntime(runtimeConfig);
    if (runtimeConfig.configVersion > 0 && deviceState.operational().appliedConfigVersion == 0) {
        deviceState.recordConfigApplied(runtimeConfig.configVersion);
    }

    WiFi.mode(WIFI_STA);
    WiFi.setAutoReconnect(false);
    WiFi.persistent(false);
    Wire.begin(OPEN_HACCP_I2C_SDA, OPEN_HACCP_I2C_SCL);
    sensorReady = sensor.begin();
    if (sensorReady) {
        sensor.setPrecision(SHT4X_HIGH_PRECISION);
        sensor.setHeater(SHT4X_NO_HEATER);
    } else {
        deviceState.recordSensorUnavailable();
        Serial.println("SHT45 not detected; diagnostic heartbeat remains available.");
    }
#if OPEN_HACCP_BATTERY_ADC_PIN >= 0
    analogSetPinAttenuation(OPEN_HACCP_BATTERY_ADC_PIN, ADC_11db);
#endif

    runWakeCycle();
}

void loop()
{
    if (portalMode) {
        provisioningPortal.loop();
        return;
    }
    delay(1000);
}
