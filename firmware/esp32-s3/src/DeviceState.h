#pragma once

#include <Arduino.h>
#include <Preferences.h>

struct ProvisioningConfig {
    String wifiSsid;
    String wifiPassword;
    String apiBaseUrl;
    String deviceUid;
    String deviceKey;
    String deviceLabel;
    String measurementPoint;

    bool isValid() const;
};
struct RuntimeConfig {
    uint32_t configVersion = 0;
    uint32_t measurementIntervalSeconds = 300;
    uint32_t uploadIntervalSeconds = 21600;
    uint16_t maxBatchSize = 64;
    bool alarmEnabled = false;
    float temperatureMinC = NAN;
    float temperatureMaxC = NAN;
};

struct PendingMeasurement {
    uint64_t sequence = 0;
    int64_t measuredAt = 0;
    float temperatureC = 0;
    float humidityRh = 0;
    uint16_t batteryMv = 0;
};

class DeviceState {
public:
    static constexpr size_t QueueCapacity = 64;

    bool loadProvisioning(ProvisioningConfig &config);
    bool saveProvisioning(const ProvisioningConfig &config);
    bool loadRuntime(RuntimeConfig &config);
    bool saveRuntime(const RuntimeConfig &config);
    bool enqueue(int64_t measuredAt, float temperatureC, float humidityRh, uint16_t batteryMv);
    size_t pendingCount() const;
    const PendingMeasurement *pendingItems() const;
    void acknowledge(const uint64_t *sequences, size_t sequenceCount);
    uint32_t incrementBootCount();
    void factoryReset();

private:
    struct QueueState {
        uint32_t magic = 0x48414351;
        uint16_t version = 1;
        uint16_t count = 0;
        uint64_t nextSequence = 1;
        PendingMeasurement items[QueueCapacity]{};
    } queue_;

    bool queueLoaded_ = false;
    void loadQueue();
    bool saveQueue();
};
