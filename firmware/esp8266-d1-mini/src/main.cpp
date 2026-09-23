#include <Arduino.h>
#include <DHT.h>
#include <ESP8266WiFi.h>
#include <coredecls.h>
#include <time.h>
#include <sys/time.h>

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
DHT sensor(OPEN_HACCP_DHT_PIN, DHT22);

bool portalMode = false;
bool sensorReady = false;
uint32_t bootCount = 0;
uint32_t wifiConnectMs = 0;
uint32_t cycleStartedMillis = 0;
volatile bool freshNtpTime = false;
#if !OPEN_HACCP_ENABLE_DEEP_SLEEP
uint32_t nextCycleMillis = 0;
#endif
String currentWakeReason;
String currentResetReason;

bool validClock() { return time(nullptr) >= 1704067200; }
bool elapsed(uint32_t since, uint32_t interval) { return static_cast<uint32_t>(millis() - since) >= interval; }

bool deepSleepWake()
{
    return ESP.getResetReason().indexOf("Deep-Sleep") >= 0;
}

void restoreSleepClock()
{
    if (!deepSleepWake()) return;
    const OperationalState &state = deviceState.operational();
    if (state.plannedWakeAt < 1704067200 || state.timeAnchorAt < 1704067200
        || state.plannedWakeAt - state.timeAnchorAt > 86400 || state.anchorCycles > 288) return;
    timeval value{};
    value.tv_sec = static_cast<time_t>(state.plannedWakeAt);
    settimeofday(&value, nullptr);
    Serial.println("UTC restored from the last trusted time and deep-sleep timer.");
}

bool synchronizeClock()
{
    // A deep-sleep timer gives a bounded estimate for offline sample timestamps.
    // HTTPS waits for a fresh SNTP update rather than trusting that estimate.
    freshNtpTime = false;
    settimeofday_cb([](bool fromSntp) { if (fromSntp) freshNtpTime = true; });
    configTime(0, 0, "pool.ntp.org", "time.cloudflare.com");
    const uint32_t started = millis();
    while (!freshNtpTime && !elapsed(started, OPEN_HACCP_CLOCK_SYNC_TIMEOUT_MS)) delay(200);
    if (!freshNtpTime || !validClock()) {
        deviceState.recordClockSyncFailure();
        return false;
    }
    deviceState.recordTimeAnchor(time(nullptr));
    return true;
}

bool connectWifi()
{
    if (WiFi.status() == WL_CONNECTED) return true;
    WiFi.mode(WIFI_STA);
    const uint32_t overall = millis();
    for (uint8_t attempt = 0; attempt < OPEN_HACCP_WIFI_CONNECT_ATTEMPTS; ++attempt) {
        WiFi.disconnect(false);
        WiFi.begin(provisioning.wifiSsid.c_str(), provisioning.wifiPassword.c_str());
        const uint32_t started = millis();
        while (WiFi.status() != WL_CONNECTED && !elapsed(started, OPEN_HACCP_WIFI_CONNECT_TIMEOUT_MS)) delay(200);
        if (WiFi.status() == WL_CONNECTED) {
            wifiConnectMs = min(millis() - overall, 120000UL);
            deviceState.recordWifiSuccess();
            return true;
        }
        deviceState.recordWifiFailure();
    }
    wifiConnectMs = min(millis() - overall, 120000UL);
    return false;
}

DeviceDiagnostics diagnostics()
{
    DeviceDiagnostics value{};
    value.rssiDbm = static_cast<int16_t>(constrain(WiFi.status() == WL_CONNECTED ? WiFi.RSSI() : -120, -120, 0));
    value.wifiConnectMs = wifiConnectMs;
    value.bootCount = bootCount;
    value.awakeMs = min(static_cast<uint32_t>(millis() - cycleStartedMillis), static_cast<uint32_t>(3600000));
    value.queueDepth = deviceState.pendingCount();
    value.sensorReady = sensorReady;
    value.wakeReason = currentWakeReason;
    value.resetReason = currentResetReason;
    value.requestedSleepMode = OPEN_HACCP_ENABLE_DEEP_SLEEP ? "deep_sleep" : "none";
    value.operational = deviceState.operational();
    value.errorCount = deviceState.diagnosticCodes(value.errors, 10);
    return value;
}

bool applyConfiguration(const RuntimeConfig &candidate)
{
    if (candidate.configVersion < runtimeConfig.configVersion) return true;
    if (candidate.configVersion == runtimeConfig.configVersion
        && deviceState.operational().appliedConfigVersion == candidate.configVersion
        && deviceState.operational().configStatus == ConfigApplyStatus::Applied) return true;
    if (!deviceState.saveRuntime(candidate)) {
        deviceState.recordConfigRejected();
        Serial.println("Configuration storage failed; previous cadence retained.");
        return false;
    }
    runtimeConfig = candidate;
    deviceState.recordConfigApplied(candidate.configVersion);
    Serial.printf("Config version %lu applied: sample %lu s, upload %lu s.\n",
        static_cast<unsigned long>(candidate.configVersion),
        static_cast<unsigned long>(candidate.measurementIntervalSeconds),
        static_cast<unsigned long>(candidate.uploadIntervalSeconds));
    return true;
}

bool fetchAndApplyConfig(int64_t now)
{
    RuntimeConfig candidate = runtimeConfig;
    String error;
    if (!haccpClient.fetchConfig(provisioning, candidate, error)) {
        deviceState.recordConfigRejected();
        Serial.println("Config refresh failed: " + error);
        return false;
    }
    deviceState.recordConfigCheck(now);
    return applyConfiguration(candidate);
}

void sampleSensor(int64_t now)
{
    deviceState.recordSample(now);
    // DHT::begin() ran at least 2.5 seconds ago. The server's minimum interval is 30 s.
    const float humidity = sensor.readHumidity();
    const float temperature = sensor.readTemperature();
    if (!isfinite(temperature) || !isfinite(humidity)
        || temperature < -100 || temperature > 150 || humidity < 0 || humidity > 100) {
        sensorReady = false;
        deviceState.recordSensorFailure();
        Serial.printf("DHT22 reading rejected (%u consecutive failures).\n",
            static_cast<unsigned>(deviceState.operational().consecutiveSensorFailures));
        return;
    }
    sensorReady = true;
    deviceState.recordSensorSuccess();
    if (!deviceState.enqueue(now, temperature, humidity)) {
        deviceState.recordQueueFull();
        Serial.println("Queue full or storage unavailable; older pending readings retained.");
        return;
    }
    Serial.printf("DHT22: %.2f C, %.2f %% RH; %u pending.\n", temperature, humidity,
        static_cast<unsigned>(deviceState.pendingCount()));
}

void scheduleRetry(int64_t now)
{
    constexpr uint32_t delays[] = {60, 300, 900, 1800, 3600};
    const uint32_t base = delays[min(deviceState.operational().retryStep, static_cast<uint8_t>(4))];
    const int32_t span = static_cast<int32_t>(base / 10);
    const uint32_t delaySeconds = static_cast<uint32_t>(static_cast<int32_t>(base) + random(-span, span + 1));
    deviceState.scheduleNetworkRetry(now, delaySeconds);
}

bool handleReceivedConfiguration(const RuntimeConfig &candidate, bool valid, const String &error, int64_t now)
{
    if (valid) { deviceState.recordConfigCheck(now); return applyConfiguration(candidate); }
    deviceState.recordConfigRejected();
    Serial.println("Piggyback config rejected: " + error);
    return fetchAndApplyConfig(now);
}

bool uploadOne(int64_t now)
{
    const size_t pendingBefore = deviceState.pendingCount();
    const size_t sentCount = min(pendingBefore, static_cast<size_t>(runtimeConfig.maxBatchSize));
    const uint32_t previousAppliedVersion = deviceState.operational().appliedConfigVersion;
    RuntimeConfig receivedConfig = runtimeConfig;
    bool configValid = false;
    String configError, error;
    bool sent = false;
    size_t acknowledgedCount = 0;
    if (sentCount == 0) {
        sent = haccpClient.sendHeartbeat(provisioning, runtimeConfig, diagnostics(), receivedConfig,
            configValid, configError, error);
    } else {
        uint64_t acknowledged[DeviceState::BatchCapacity]{};
        uint32_t reportedVersion = runtimeConfig.configVersion;
        sent = haccpClient.uploadMeasurements(provisioning, runtimeConfig, diagnostics(),
            deviceState.pendingItems(), sentCount, acknowledged, acknowledgedCount, reportedVersion,
            receivedConfig, configValid, configError, error);
        if (sent && !deviceState.acknowledge(acknowledged, acknowledgedCount)) {
            deviceState.recordTransportFailure();
            scheduleRetry(now);
            Serial.println("ACK could not be committed; pending readings retained.");
            return false;
        }
    }
    if (!sent) {
        deviceState.recordTransportFailure();
        scheduleRetry(now);
        Serial.println("HTTPS request failed: " + error);
        return false;
    }
    deviceState.markTelemetryDelivered();
    deviceState.recordTransmissionSuccess(now);
    if (!handleReceivedConfiguration(receivedConfig, configValid, configError, now)) {
        deviceState.recordTransportFailure();
        scheduleRetry(now);
    } else if (deviceState.operational().appliedConfigVersion > previousAppliedVersion) {
        RuntimeConfig confirmation = runtimeConfig;
        bool confirmationValid = false;
        String confirmationConfigError, confirmationError;
        if (haccpClient.sendHeartbeat(provisioning, runtimeConfig, diagnostics(), confirmation,
            confirmationValid, confirmationConfigError, confirmationError)) {
            deviceState.markTelemetryDelivered();
            deviceState.recordTransmissionSuccess(now);
            Serial.println("Applied config version confirmed.");
        } else {
            deviceState.recordTransportFailure();
            scheduleRetry(now);
        }
    }
    if (sentCount > acknowledgedCount) {
        deviceState.recordAckIncomplete();
        scheduleRetry(now);
    }
    Serial.printf("Batch processed: %u ACK, %u pending.\n", static_cast<unsigned>(acknowledgedCount),
        static_cast<unsigned>(deviceState.pendingCount()));
    return sentCount == acknowledgedCount;
}

int64_t dueAt(int64_t last, uint32_t interval, int64_t now) { return last <= 0 ? now : last + interval; }

uint32_t nextSleepSeconds(int64_t now)
{
    if (!validClock()) {
        const uint32_t delaySeconds = deviceState.operational().noClockRetryDelaySeconds;
        return delaySeconds > 0 ? min(delaySeconds, static_cast<uint32_t>(4200)) : 60;
    }
    const OperationalState &state = deviceState.operational();
    const int64_t sampleDue = dueAt(state.lastSampleAt, runtimeConfig.measurementIntervalSeconds, now);
    int64_t networkDue = min(dueAt(state.lastSuccessfulTransmissionAt, runtimeConfig.uploadIntervalSeconds, now),
        dueAt(state.lastConfigCheckAt, OPEN_HACCP_CONFIG_REFRESH_SECONDS, now));
    if (deviceState.pendingCount() >= DeviceState::QueueCapacity - 4) networkDue = now;
    if (state.nextNetworkAttemptAt > now) networkDue = max(networkDue, state.nextNetworkAttemptAt);
    const int64_t next = min(sampleDue, networkDue);
    if (next <= now) return OPEN_HACCP_MINIMUM_SLEEP_SECONDS;
    return static_cast<uint32_t>(constrain(next - now, static_cast<int64_t>(OPEN_HACCP_MINIMUM_SLEEP_SECONDS),
        static_cast<int64_t>(4200)));
}

void finishCycle(uint32_t seconds)
{
    WiFi.disconnect(true);
    WiFi.mode(WIFI_OFF);
    if (seconds < OPEN_HACCP_MINIMUM_SLEEP_SECONDS) seconds = OPEN_HACCP_MINIMUM_SLEEP_SECONDS;
#if OPEN_HACCP_ENABLE_DEEP_SLEEP
    // GPIO16/D0 must be wired to RST or the D1 mini cannot wake itself.
    seconds = min(seconds, static_cast<uint32_t>(4200));
    if (validClock()) deviceState.recordPlannedWake(time(nullptr) + seconds);
    deviceState.recordSleepMode(SleepMode::DeepSleep);
    Serial.printf("Deep sleep %lu s; D0 to RST jumper required.\n", static_cast<unsigned long>(seconds));
    Serial.flush();
    ESP.deepSleep(static_cast<uint64_t>(seconds) * 1000000ULL, WAKE_RF_DEFAULT);
    // If deepSleep returns unexpectedly, restart after a bounded delay.
    delay(1000);
    ESP.restart();
#else
    deviceState.recordSleepMode(SleepMode::Awake);
    nextCycleMillis = millis() + min(seconds, static_cast<uint32_t>(4200)) * 1000UL;
    Serial.printf("Next USB-powered cycle in %lu s.\n", static_cast<unsigned long>(seconds));
#endif
}

bool factoryResetRequested()
{
    pinMode(OPEN_HACCP_FACTORY_RESET_PIN, INPUT_PULLUP);
    if (digitalRead(OPEN_HACCP_FACTORY_RESET_PIN) != LOW) return false;
    const uint32_t started = millis();
    while (digitalRead(OPEN_HACCP_FACTORY_RESET_PIN) == LOW && !elapsed(started, 5000)) delay(20);
    return elapsed(started, 5000);
}

void runCycle()
{
    cycleStartedMillis = millis();
    int64_t now = validClock() ? static_cast<int64_t>(time(nullptr)) : 0;
    const OperationalState initial = deviceState.operational();
    if (validClock() && now >= dueAt(initial.lastSampleAt, runtimeConfig.measurementIntervalSeconds, now)) sampleSensor(now);

    const bool uploadDue = validClock() && now >= dueAt(initial.lastSuccessfulTransmissionAt,
        runtimeConfig.uploadIntervalSeconds, now);
    const bool configDue = validClock() && now >= dueAt(initial.lastConfigCheckAt,
        OPEN_HACCP_CONFIG_REFRESH_SECONDS, now);
    const bool retryDue = validClock() && initial.nextNetworkAttemptAt > 0 && now >= initial.nextNetworkAttemptAt;
    const bool queuePressure = deviceState.pendingCount() >= DeviceState::QueueCapacity - 4;
    bool networkDue = !validClock() || uploadDue || configDue || retryDue || queuePressure;
    if (validClock() && initial.nextNetworkAttemptAt > now) networkDue = false;

    if (networkDue) {
        if (!connectWifi()) {
            scheduleRetry(validClock() ? time(nullptr) : 1704067200);
        } else if (!synchronizeClock()) {
            scheduleRetry(validClock() ? time(nullptr) : 1704067200);
        } else {
            now = time(nullptr);
            if (now >= dueAt(deviceState.operational().lastSampleAt, runtimeConfig.measurementIntervalSeconds, now))
                sampleSensor(now);
            const bool due = now >= dueAt(deviceState.operational().lastSuccessfulTransmissionAt,
                runtimeConfig.uploadIntervalSeconds, now)
                || now >= dueAt(deviceState.operational().lastConfigCheckAt,
                    OPEN_HACCP_CONFIG_REFRESH_SECONDS, now)
                || retryDue || queuePressure;
            if (due) {
                for (size_t request = 0; request < DeviceState::QueueCapacity; ++request) {
                    const size_t before = deviceState.pendingCount();
                    if (!uploadOne(now)) break;
                    const size_t after = deviceState.pendingCount();
                    if (after == 0 || after >= before) break;
                    yield();
                }
            }
        }
    }
    now = validClock() ? time(nullptr) : 0;
    finishCycle(nextSleepSeconds(now));
}
}

void setup()
{
    Serial.begin(115200);
    delay(250);
    Serial.println("Open HACCP ESP8266 D1 mini / DHT22 starting.");
    deviceState.begin();
    currentWakeReason = deepSleepWake() ? "timer" : "cold_boot";
    currentResetReason = deepSleepWake() ? "deep_sleep" : "other";
    if (factoryResetRequested()) {
        deviceState.factoryReset();
        Serial.println("Destructive factory reset completed; setup mode follows.");
    }
    if (!deviceState.loadProvisioning(provisioning)) {
        portalMode = true;
        provisioningPortal.begin();
        return;
    }
    restoreSleepClock();
    bootCount = deviceState.incrementBootCount();
    deviceState.loadRuntime(runtimeConfig);
    if (runtimeConfig.configVersion > 0 && deviceState.operational().appliedConfigVersion == 0)
        deviceState.recordConfigApplied(runtimeConfig.configVersion);
    WiFi.persistent(false);
    WiFi.setAutoReconnect(false);
    WiFi.mode(WIFI_STA);
    randomSeed(ESP.getCycleCount());
    sensor.begin();
    delay(2500); // AM2302 minimum settling time after power-up.
    if (!validClock() && !deepSleepWake() && deviceState.operational().noClockRetryDelaySeconds > 0) {
        // An unexpected cold reset cannot prove how much of the prior wait elapsed.
        // Wait the whole persisted delay once before another network attempt.
        finishCycle(deviceState.operational().noClockRetryDelaySeconds);
        return;
    }
    runCycle();
}

void loop()
{
    if (portalMode) { provisioningPortal.loop(); return; }
#if !OPEN_HACCP_ENABLE_DEEP_SLEEP
    if (static_cast<int32_t>(millis() - nextCycleMillis) >= 0) runCycle();
#endif
    delay(250);
}
