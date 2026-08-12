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
    uint32_t defaultMeasurementIntervalSeconds = 300;
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

enum class ConfigApplyStatus : uint8_t {
    Default = 0,
    Applied = 1,
    Rejected = 2,
};

enum class SleepMode : uint8_t {
    None = 0,
    DeepSleep = 1,
    LightSleepFallback = 2,
    AwakeRestartFallback = 3,
};

enum DiagnosticFlag : uint32_t {
    DiagnosticWifiConnectFailed = 1U << 0,
    DiagnosticTransportFailed = 1U << 1,
    DiagnosticClockSyncFailed = 1U << 2,
    DiagnosticSensorUnavailable = 1U << 3,
    DiagnosticQueueFull = 1U << 4,
    DiagnosticConfigRejected = 1U << 5,
    DiagnosticAckIncomplete = 1U << 6,
    DiagnosticSleepFallback = 1U << 7,
};

struct OperationalState {
    uint32_t magic = 0x4841434F;
    uint16_t version = 1;
    uint16_t reserved = 0;
    int64_t lastSampleAt = 0;
    int64_t lastSuccessfulTransmissionAt = 0;
    int64_t lastConfigCheckAt = 0;
    int64_t nextNetworkAttemptAt = 0;
    uint32_t wifiFailuresSinceReport = 0;
    uint32_t uploadFailuresSinceReport = 0;
    uint32_t maxConsecutiveWifiFailures = 0;
    uint32_t consecutiveWifiFailures = 0;
    uint32_t sleepFallbacksSinceReport = 0;
    uint32_t diagnosticFlags = 0;
    uint8_t retryStep = 0;
    ConfigApplyStatus configStatus = ConfigApplyStatus::Default;
    SleepMode lastSleepMode = SleepMode::None;
    uint8_t reserved2 = 0;
    uint32_t appliedConfigVersion = 0;
};

class DeviceState {
public:
    static constexpr size_t QueueCapacity = 64;

    bool loadProvisioning(ProvisioningConfig &config);
    bool saveProvisioning(const ProvisioningConfig &config);
    bool loadRuntime(RuntimeConfig &config);
    bool saveRuntime(const RuntimeConfig &config);
    bool loadOperational(OperationalState &state);
    const OperationalState &operational();
    void recordSample(int64_t epoch);
    void recordTransmissionSuccess(int64_t epoch);
    void recordConfigCheck(int64_t epoch);
    void recordWifiSuccess();
    void recordWifiFailure();
    void recordTransportFailure();
    void recordClockSyncFailure();
    void recordSensorUnavailable();
    void recordQueueFull();
    void recordAckIncomplete();
    void recordConfigApplied(uint32_t version);
    void recordConfigRejected();
    void recordSleepMode(SleepMode mode);
    void recordSleepFallback(SleepMode fallbackMode);
    void scheduleNetworkRetry(int64_t now, uint32_t delaySeconds);
    void clearNetworkRetry();
    void markTelemetryDelivered();
    size_t diagnosticCodes(const char **codes, size_t capacity) const;
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
    OperationalState operational_{};
    bool operationalLoaded_ = false;
    void loadQueue();
    bool saveQueue();
    void ensureOperationalLoaded();
    bool saveOperational();
};
