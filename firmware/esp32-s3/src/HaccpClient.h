#pragma once

#include <Arduino.h>
#include <ArduinoJson.h>

#include "DeviceState.h"

struct DeviceDiagnostics {
    uint16_t batteryMv;
    int16_t rssiDbm;
    uint32_t wifiConnectMs;
    uint32_t bootCount;
    uint32_t awakeMs;
    size_t queueDepth;
    bool sensorReady;
    String wakeReason;
    String resetReason;
    String requestedSleepMode;
    OperationalState operational;
    const char *errors[8]{};
    size_t errorCount = 0;
};
class HaccpClient {
public:
    bool fetchConfig(const ProvisioningConfig &provisioning, RuntimeConfig &runtime, String &error);
    bool sendHeartbeat(
        const ProvisioningConfig &provisioning,
        const RuntimeConfig &runtime,
        const DeviceDiagnostics &diagnostics,
        RuntimeConfig &receivedConfig,
        bool &configurationValid,
        String &configurationError,
        String &error
    );
    bool uploadMeasurements(
        const ProvisioningConfig &provisioning,
        const RuntimeConfig &runtime,
        const DeviceDiagnostics &diagnostics,
        const PendingMeasurement *items,
        size_t itemCount,
        uint64_t *acknowledgedSequences,
        size_t &acknowledgedCount,
        uint32_t &reportedConfigVersion,
        RuntimeConfig &receivedConfig,
        bool &configurationValid,
        String &configurationError,
        String &error
    );

private:
    int request(
        const ProvisioningConfig &provisioning,
        const String &method,
        const String &path,
        const String &body,
        String &response,
        String &error
    );
    bool parseConfiguration(
        JsonVariantConst document,
        const ProvisioningConfig &provisioning,
        const RuntimeConfig &current,
        RuntimeConfig &candidate,
        String &error
    );
    static void addDeviceStatus(JsonDocument &document, const DeviceDiagnostics &diagnostics);
    static String utcTimestamp(int64_t epoch);
};
