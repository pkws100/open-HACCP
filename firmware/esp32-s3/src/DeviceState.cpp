#include "DeviceState.h"

#include <cstring>

namespace {
constexpr char ProvisioningNamespace[] = "haccp-prov";
constexpr char RuntimeNamespace[] = "haccp-run";
constexpr char QueueNamespace[] = "haccp-queue";
constexpr char OperationalNamespace[] = "haccp-oper";

struct PersistedRuntime {
    uint32_t magic = 0x48414352;
    uint16_t version = 2;
    RuntimeConfig config{};
};

struct LegacyRuntimeConfigV1 {
    uint32_t configVersion = 0;
    uint32_t measurementIntervalSeconds = 300;
    uint32_t uploadIntervalSeconds = 21600;
    uint16_t maxBatchSize = 64;
    bool alarmEnabled = false;
    float temperatureMinC = NAN;
    float temperatureMaxC = NAN;
};

struct PersistedRuntimeV1 {
    uint32_t magic = 0x48414352;
    uint16_t version = 1;
    LegacyRuntimeConfigV1 config{};
};
}

bool ProvisioningConfig::isValid() const
{
    return wifiSsid.length() >= 1 && wifiSsid.length() <= 32
        && wifiPassword.length() <= 63
        && apiBaseUrl.startsWith("https://") && apiBaseUrl.length() <= 180
        && deviceUid.length() >= 3 && deviceUid.length() <= 64
        && deviceKey.length() == 64
        && deviceLabel.length() >= 1 && deviceLabel.length() <= 160
        && measurementPoint.length() >= 1 && measurementPoint.length() <= 64;
}

bool DeviceState::loadProvisioning(ProvisioningConfig &config)
{
    Preferences preferences;
    if (!preferences.begin(ProvisioningNamespace, true)) {
        return false;
    }
    const bool ready = preferences.getBool("ready", false);
    if (ready) {
        config.wifiSsid = preferences.getString("ssid");
        config.wifiPassword = preferences.getString("wifi_pass");
        config.apiBaseUrl = preferences.getString("api_url");
        config.deviceUid = preferences.getString("device_uid");
        config.deviceKey = preferences.getString("device_key");
        config.deviceLabel = preferences.getString("label");
        config.measurementPoint = preferences.getString("point");
    }
    preferences.end();
    return ready && config.isValid();
}

bool DeviceState::saveProvisioning(const ProvisioningConfig &config)
{
    if (!config.isValid()) {
        return false;
    }
    Preferences preferences;
    if (!preferences.begin(ProvisioningNamespace, false)) {
        return false;
    }
    preferences.putBool("ready", false);
    bool saved = preferences.putString("ssid", config.wifiSsid) > 0;
    saved = preferences.putString("wifi_pass", config.wifiPassword) > 0 && saved;
    saved = preferences.putString("api_url", config.apiBaseUrl) > 0 && saved;
    saved = preferences.putString("device_uid", config.deviceUid) > 0 && saved;
    saved = preferences.putString("device_key", config.deviceKey) > 0 && saved;
    saved = preferences.putString("label", config.deviceLabel) > 0 && saved;
    saved = preferences.putString("point", config.measurementPoint) > 0 && saved;
    if (saved) {
        saved = preferences.putBool("ready", true) == 1;
    }
    preferences.end();
    return saved;
}

bool DeviceState::loadRuntime(RuntimeConfig &config)
{
    Preferences preferences;
    if (!preferences.begin(RuntimeNamespace, true)) {
        return false;
    }
    PersistedRuntime stored;
    bool loaded = false;
    bool migrated = false;
    const size_t storedLength = preferences.getBytesLength("config");
    if (storedLength == sizeof(stored)
        && preferences.getBytes("config", &stored, sizeof(stored)) == sizeof(stored)
        && stored.magic == 0x48414352 && stored.version == 2) {
        config = stored.config;
        loaded = true;
    } else if (storedLength == sizeof(PersistedRuntimeV1)) {
        PersistedRuntimeV1 legacy;
        if (preferences.getBytes("config", &legacy, sizeof(legacy)) == sizeof(legacy)
            && legacy.magic == 0x48414352 && legacy.version == 1) {
            config.configVersion = legacy.config.configVersion;
            config.defaultMeasurementIntervalSeconds = legacy.config.measurementIntervalSeconds;
            config.measurementIntervalSeconds = legacy.config.measurementIntervalSeconds;
            config.uploadIntervalSeconds = legacy.config.uploadIntervalSeconds;
            config.maxBatchSize = legacy.config.maxBatchSize;
            config.alarmEnabled = legacy.config.alarmEnabled;
            config.temperatureMinC = legacy.config.temperatureMinC;
            config.temperatureMaxC = legacy.config.temperatureMaxC;
            loaded = true;
            migrated = true;
        }
    }
    preferences.end();
    if (!loaded) {
        return false;
    }
    config.maxBatchSize = constrain(config.maxBatchSize, static_cast<uint16_t>(1), static_cast<uint16_t>(QueueCapacity));
    if (migrated) {
        saveRuntime(config);
    }
    return config.configVersion > 0;
}

bool DeviceState::saveRuntime(const RuntimeConfig &config)
{
    Preferences preferences;
    if (!preferences.begin(RuntimeNamespace, false)) {
        return false;
    }
    PersistedRuntime stored;
    stored.config = config;
    const bool saved = preferences.putBytes("config", &stored, sizeof(stored)) == sizeof(stored);
    preferences.end();
    return saved;
}

bool DeviceState::loadOperational(OperationalState &state)
{
    ensureOperationalLoaded();
    state = operational_;
    return true;
}

const OperationalState &DeviceState::operational()
{
    ensureOperationalLoaded();
    return operational_;
}

void DeviceState::ensureOperationalLoaded()
{
    if (operationalLoaded_) {
        return;
    }
    Preferences preferences;
    if (preferences.begin(OperationalNamespace, true)) {
        OperationalState stored;
        if (preferences.getBytesLength("state") == sizeof(stored)
            && preferences.getBytes("state", &stored, sizeof(stored)) == sizeof(stored)
            && stored.magic == operational_.magic && stored.version == operational_.version) {
            operational_ = stored;
        }
        preferences.end();
    }
    operationalLoaded_ = true;
}

bool DeviceState::saveOperational()
{
    ensureOperationalLoaded();
    Preferences preferences;
    if (!preferences.begin(OperationalNamespace, false)) {
        return false;
    }
    const bool saved = preferences.putBytes("state", &operational_, sizeof(operational_)) == sizeof(operational_);
    preferences.end();
    return saved;
}

void DeviceState::recordSample(int64_t epoch)
{
    ensureOperationalLoaded();
    operational_.lastSampleAt = epoch;
    saveOperational();
}

void DeviceState::recordTransmissionSuccess(int64_t epoch)
{
    ensureOperationalLoaded();
    operational_.lastSuccessfulTransmissionAt = epoch;
    operational_.nextNetworkAttemptAt = 0;
    operational_.retryStep = 0;
    saveOperational();
}

void DeviceState::recordConfigCheck(int64_t epoch)
{
    ensureOperationalLoaded();
    operational_.lastConfigCheckAt = epoch;
    saveOperational();
}

void DeviceState::recordWifiSuccess()
{
    ensureOperationalLoaded();
    operational_.consecutiveWifiFailures = 0;
    saveOperational();
}

void DeviceState::recordWifiFailure()
{
    ensureOperationalLoaded();
    ++operational_.wifiFailuresSinceReport;
    ++operational_.consecutiveWifiFailures;
    operational_.maxConsecutiveWifiFailures = max(
        operational_.maxConsecutiveWifiFailures,
        operational_.consecutiveWifiFailures
    );
    operational_.diagnosticFlags |= DiagnosticWifiConnectFailed;
    saveOperational();
}

void DeviceState::recordTransportFailure()
{
    ensureOperationalLoaded();
    ++operational_.uploadFailuresSinceReport;
    operational_.diagnosticFlags |= DiagnosticTransportFailed;
    saveOperational();
}

void DeviceState::recordClockSyncFailure()
{
    ensureOperationalLoaded();
    operational_.diagnosticFlags |= DiagnosticClockSyncFailed;
    saveOperational();
}

void DeviceState::recordSensorUnavailable()
{
    ensureOperationalLoaded();
    operational_.diagnosticFlags |= DiagnosticSensorUnavailable;
    saveOperational();
}

void DeviceState::recordQueueFull()
{
    ensureOperationalLoaded();
    operational_.diagnosticFlags |= DiagnosticQueueFull;
    saveOperational();
}

void DeviceState::recordAckIncomplete()
{
    ensureOperationalLoaded();
    operational_.diagnosticFlags |= DiagnosticAckIncomplete;
    saveOperational();
}

void DeviceState::recordConfigApplied(uint32_t version)
{
    ensureOperationalLoaded();
    operational_.appliedConfigVersion = version;
    operational_.configStatus = ConfigApplyStatus::Applied;
    operational_.diagnosticFlags &= ~DiagnosticConfigRejected;
    saveOperational();
}

void DeviceState::recordConfigRejected()
{
    ensureOperationalLoaded();
    operational_.configStatus = ConfigApplyStatus::Rejected;
    operational_.diagnosticFlags |= DiagnosticConfigRejected;
    saveOperational();
}

void DeviceState::recordSleepMode(SleepMode mode)
{
    ensureOperationalLoaded();
    operational_.lastSleepMode = mode;
    saveOperational();
}

void DeviceState::recordSleepFallback(SleepMode fallbackMode)
{
    ensureOperationalLoaded();
    operational_.lastSleepMode = fallbackMode;
    ++operational_.sleepFallbacksSinceReport;
    operational_.diagnosticFlags |= DiagnosticSleepFallback;
    saveOperational();
}

void DeviceState::scheduleNetworkRetry(int64_t now, uint32_t delaySeconds)
{
    ensureOperationalLoaded();
    operational_.nextNetworkAttemptAt = now + delaySeconds;
    if (operational_.retryStep < 4) {
        ++operational_.retryStep;
    }
    saveOperational();
}

void DeviceState::clearNetworkRetry()
{
    ensureOperationalLoaded();
    operational_.nextNetworkAttemptAt = 0;
    operational_.retryStep = 0;
    saveOperational();
}

void DeviceState::markTelemetryDelivered()
{
    ensureOperationalLoaded();
    operational_.wifiFailuresSinceReport = 0;
    operational_.uploadFailuresSinceReport = 0;
    operational_.maxConsecutiveWifiFailures = 0;
    operational_.sleepFallbacksSinceReport = 0;
    operational_.diagnosticFlags = 0;
    saveOperational();
}

size_t DeviceState::diagnosticCodes(const char **codes, size_t capacity) const
{
    const_cast<DeviceState *>(this)->ensureOperationalLoaded();
    const struct { uint32_t flag; const char *code; } mappings[] = {
        {DiagnosticWifiConnectFailed, "WIFI_CONNECT_FAILED"},
        {DiagnosticTransportFailed, "HTTPS_TRANSPORT_FAILED"},
        {DiagnosticClockSyncFailed, "CLOCK_SYNC_FAILED"},
        {DiagnosticSensorUnavailable, "SENSOR_UNAVAILABLE"},
        {DiagnosticQueueFull, "OFFLINE_QUEUE_FULL"},
        {DiagnosticConfigRejected, "CONFIG_REJECTED"},
        {DiagnosticAckIncomplete, "ACK_INCOMPLETE"},
        {DiagnosticSleepFallback, "DEEP_SLEEP_FALLBACK"},
    };
    size_t count = 0;
    for (const auto &mapping : mappings) {
        if ((operational_.diagnosticFlags & mapping.flag) != 0 && count < capacity) {
            codes[count++] = mapping.code;
        }
    }
    return count;
}

void DeviceState::loadQueue()
{
    if (queueLoaded_) {
        return;
    }
    Preferences preferences;
    if (preferences.begin(QueueNamespace, true)) {
        if (preferences.getBytesLength("queue") == sizeof(queue_)) {
            QueueState stored;
            if (preferences.getBytes("queue", &stored, sizeof(stored)) == sizeof(stored)
                && stored.magic == queue_.magic && stored.version == queue_.version
                && stored.count <= QueueCapacity && stored.nextSequence > 0) {
                queue_ = stored;
            }
        }
        preferences.end();
    }
    queueLoaded_ = true;
}

bool DeviceState::saveQueue()
{
    Preferences preferences;
    if (!preferences.begin(QueueNamespace, false)) {
        return false;
    }
    const bool saved = preferences.putBytes("queue", &queue_, sizeof(queue_)) == sizeof(queue_);
    preferences.end();
    return saved;
}

bool DeviceState::enqueue(int64_t measuredAt, float temperatureC, float humidityRh, uint16_t batteryMv)
{
    loadQueue();
    if (queue_.count >= QueueCapacity) {
        return false;
    }
    PendingMeasurement &item = queue_.items[queue_.count++];
    item.sequence = queue_.nextSequence++;
    item.measuredAt = measuredAt;
    item.temperatureC = temperatureC;
    item.humidityRh = humidityRh;
    item.batteryMv = batteryMv;
    if (!saveQueue()) {
        queue_.count--;
        queue_.nextSequence--;
        return false;
    }
    return true;
}

size_t DeviceState::pendingCount() const
{
    const_cast<DeviceState *>(this)->loadQueue();
    return queue_.count;
}

const PendingMeasurement *DeviceState::pendingItems() const
{
    const_cast<DeviceState *>(this)->loadQueue();
    return queue_.items;
}

void DeviceState::acknowledge(const uint64_t *sequences, size_t sequenceCount)
{
    loadQueue();
    uint16_t target = 0;
    for (uint16_t source = 0; source < queue_.count; ++source) {
        bool acknowledged = false;
        for (size_t index = 0; index < sequenceCount; ++index) {
            if (queue_.items[source].sequence == sequences[index]) {
                acknowledged = true;
                break;
            }
        }
        if (!acknowledged) {
            if (target != source) {
                queue_.items[target] = queue_.items[source];
            }
            ++target;
        }
    }
    if (target != queue_.count) {
        queue_.count = target;
        saveQueue();
    }
}

uint32_t DeviceState::incrementBootCount()
{
    Preferences preferences;
    if (!preferences.begin(RuntimeNamespace, false)) {
        return 0;
    }
    const uint32_t count = preferences.getUInt("boot_count", 0) + 1;
    preferences.putUInt("boot_count", count);
    preferences.end();
    return count;
}

void DeviceState::factoryReset()
{
    for (const char *name : {ProvisioningNamespace, RuntimeNamespace, QueueNamespace, OperationalNamespace}) {
        Preferences preferences;
        if (preferences.begin(name, false)) {
            preferences.clear();
            preferences.end();
        }
    }
    queue_ = QueueState{};
    queueLoaded_ = true;
    operational_ = OperationalState{};
    operationalLoaded_ = true;
}
