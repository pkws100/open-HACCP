#pragma once

#include <Arduino.h>

#include "DeviceState.h"

struct DeviceDiagnostics {
    uint16_t batteryMv;
    int16_t rssiDbm;
    uint32_t wifiConnectMs;
    uint32_t bootCount;
};
class HaccpClient {
public:
    bool fetchConfig(const ProvisioningConfig &provisioning, RuntimeConfig &runtime, String &error);
    bool sendHeartbeat(
        const ProvisioningConfig &provisioning,
        const RuntimeConfig &runtime,
        const DeviceDiagnostics &diagnostics,
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
    static String utcTimestamp(int64_t epoch);
};
