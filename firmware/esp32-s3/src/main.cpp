#include <Adafruit_SHT4x.h>
#include <Arduino.h>
#include <WiFi.h>
#include <Wire.h>
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
uint32_t lastWifiAttempt = 0;
uint32_t lastSensorAttempt = 0;
uint32_t lastSample = 0;
uint32_t lastUploadAttempt = 0;
uint32_t lastSuccessfulUpload = 0;
uint32_t lastConfigFetch = 0;

bool elapsed(uint32_t since, uint32_t interval)
{
    return static_cast<uint32_t>(millis() - since) >= interval;
}

bool validClock()
{
    return time(nullptr) >= 1704067200;
}

bool synchronizeClock()
{
    if (validClock()) {
        return true;
    }
    configTime(0, 0, "pool.ntp.org", "time.cloudflare.com");
    const uint32_t startedAt = millis();
    while (!validClock() && !elapsed(startedAt, 15000)) {
        delay(200);
    }
    return validClock();
}

bool connectWifi()
{
    if (WiFi.status() == WL_CONNECTED) {
        return true;
    }
    const uint32_t startedAt = millis();
    WiFi.begin(provisioning.wifiSsid.c_str(), provisioning.wifiPassword.c_str());
    while (WiFi.status() != WL_CONNECTED && !elapsed(startedAt, 15000)) {
        delay(200);
    }
    wifiConnectMs = millis() - startedAt;
    return WiFi.status() == WL_CONNECTED;
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
    return DeviceDiagnostics{
        batteryMillivolts(),
        static_cast<int16_t>(constrain(rssi, -120, 0)),
        wifiConnectMs,
        bootCount,
    };
}

void fetchAndApplyConfig()
{
    RuntimeConfig candidate = runtimeConfig;
    String error;
    if (haccpClient.fetchConfig(provisioning, candidate, error)) {
        if (candidate.configVersion >= runtimeConfig.configVersion && deviceState.saveRuntime(candidate)) {
            runtimeConfig = candidate;
            Serial.printf("Applied configuration version %lu.\n", static_cast<unsigned long>(runtimeConfig.configVersion));
        }
    } else {
        Serial.println("Configuration refresh failed: " + error);
    }
    lastConfigFetch = millis();
}

void sampleSensor()
{
    if (!sensorReady || !validClock()) {
        return;
    }
    sensors_event_t humidity;
    sensors_event_t temperature;
    sensor.getEvent(&humidity, &temperature);
    if (!isfinite(temperature.temperature) || !isfinite(humidity.relative_humidity)
        || temperature.temperature < -100 || temperature.temperature > 150
        || humidity.relative_humidity < 0 || humidity.relative_humidity > 100) {
        Serial.println("SHT45 sample rejected locally.");
        return;
    }
    if (!deviceState.enqueue(
        static_cast<int64_t>(time(nullptr)),
        temperature.temperature,
        humidity.relative_humidity,
        batteryMillivolts()
    )) {
        Serial.println("Offline queue is full or could not be persisted; no record was overwritten.");
        return;
    }
    const char *alarm = "normal";
    if (runtimeConfig.alarmEnabled) {
        if (temperature.temperature < runtimeConfig.temperatureMinC) alarm = "below_min";
        else if (temperature.temperature > runtimeConfig.temperatureMaxC) alarm = "above_max";
    } else {
        alarm = "disabled";
    }
    Serial.printf("Measurement queued (pending=%u, alarm=%s).\n", static_cast<unsigned>(deviceState.pendingCount()), alarm);
}

void uploadPending()
{
    const size_t pending = deviceState.pendingCount();
    String error;
    if (pending == 0) {
        if (elapsed(lastSuccessfulUpload, runtimeConfig.uploadIntervalSeconds * 1000UL)) {
            if (haccpClient.sendHeartbeat(provisioning, runtimeConfig, diagnostics(), error)) {
                lastSuccessfulUpload = millis();
                Serial.println("Heartbeat acknowledged.");
            } else {
                Serial.println("Heartbeat failed: " + error);
            }
        }
        return;
    }

    uint64_t acknowledged[DeviceState::QueueCapacity]{};
    size_t acknowledgedCount = 0;
    uint32_t reportedConfigVersion = runtimeConfig.configVersion;
    if (haccpClient.uploadMeasurements(
        provisioning,
        runtimeConfig,
        diagnostics(),
        deviceState.pendingItems(),
        pending,
        acknowledged,
        acknowledgedCount,
        reportedConfigVersion,
        error
    )) {
        deviceState.acknowledge(acknowledged, acknowledgedCount);
        lastSuccessfulUpload = millis();
        Serial.printf("Batch processed; %u records explicitly acknowledged, %u remain.\n",
            static_cast<unsigned>(acknowledgedCount), static_cast<unsigned>(deviceState.pendingCount()));
        if (reportedConfigVersion > runtimeConfig.configVersion) {
            fetchAndApplyConfig();
        }
    } else {
        Serial.println("Measurement upload failed: " + error);
    }
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
}

void setup()
{
    Serial.begin(115200);
    delay(250);
    Serial.println("Open HACCP firmware starting.");

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
    WiFi.mode(WIFI_STA);
    WiFi.setAutoReconnect(true);
    WiFi.persistent(false);
    Wire.begin(OPEN_HACCP_I2C_SDA, OPEN_HACCP_I2C_SCL);
    sensorReady = sensor.begin();
    if (sensorReady) {
        sensor.setPrecision(SHT4X_HIGH_PRECISION);
        sensor.setHeater(SHT4X_NO_HEATER);
    } else {
        Serial.println("SHT45 not detected; telemetry heartbeat remains active.");
    }
#if OPEN_HACCP_BATTERY_ADC_PIN >= 0
    analogSetPinAttenuation(OPEN_HACCP_BATTERY_ADC_PIN, ADC_11db);
#endif

    if (connectWifi() && synchronizeClock()) {
        fetchAndApplyConfig();
    } else {
        Serial.println("Initial WLAN/time connection failed; retrying without discarding queued data.");
    }
    const uint32_t sampleInterval = runtimeConfig.measurementIntervalSeconds * 1000UL;
    lastSample = millis() - sampleInterval;
    lastSuccessfulUpload = millis() - (runtimeConfig.uploadIntervalSeconds * 1000UL);
    lastUploadAttempt = millis() - 60000UL;
}

void loop()
{
    if (portalMode) {
        provisioningPortal.loop();
        return;
    }

    if (!sensorReady && elapsed(lastSensorAttempt, 60000UL)) {
        lastSensorAttempt = millis();
        sensorReady = sensor.begin();
    }
    if (WiFi.status() != WL_CONNECTED && elapsed(lastWifiAttempt, 30000UL)) {
        lastWifiAttempt = millis();
        connectWifi();
    }
    if (WiFi.status() == WL_CONNECTED && !validClock()) {
        synchronizeClock();
    }

    const uint32_t sampleInterval = runtimeConfig.measurementIntervalSeconds * 1000UL;
    if (elapsed(lastSample, sampleInterval)) {
        lastSample = millis();
        sampleSensor();
    }
    if (WiFi.status() == WL_CONNECTED && validClock() && elapsed(lastUploadAttempt, 60000UL)) {
        const size_t pending = deviceState.pendingCount();
        const bool due = elapsed(lastSuccessfulUpload, runtimeConfig.uploadIntervalSeconds * 1000UL)
            || pending >= runtimeConfig.maxBatchSize;
        if (due) {
            lastUploadAttempt = millis();
            uploadPending();
        }
    }
    if (WiFi.status() == WL_CONNECTED && validClock() && elapsed(lastConfigFetch, 900000UL)) {
        fetchAndApplyConfig();
    }
    delay(20);
}
