#include "DeviceState.h"

#include <LittleFS.h>
#include <cstring>

namespace {
constexpr uint32_t Magic = 0x48414331;
constexpr uint16_t Version = 1;

template <typename T> struct Record {
    uint32_t magic = Magic;
    uint16_t version = Version;
    uint16_t reserved = 0;
    uint32_t generation = 0;
    T payload{};
    uint32_t checksum = 0;
};

struct StoredProvisioning {
    char ssid[33]{};
    char wifiPassword[64]{};
    char apiUrl[181]{};
    char uid[65]{};
    char key[65]{};
    char label[161]{};
    char point[65]{};
};

uint32_t crc32(const uint8_t *bytes, size_t count)
{
    uint32_t value = 0xFFFFFFFFU;
    for (size_t index = 0; index < count; ++index) {
        value ^= bytes[index];
        for (uint8_t bit = 0; bit < 8; ++bit) {
            value = (value >> 1) ^ ((value & 1U) ? 0xEDB88320U : 0U);
        }
    }
    return ~value;
}

template <typename T> bool readSlot(const char *name, Record<T> &record)
{
    File file = LittleFS.open(name, "r");
    if (!file) return false;
    const bool okay = file.size() == sizeof(record)
        && file.readBytes(reinterpret_cast<char *>(&record), sizeof(record)) == sizeof(record);
    file.close();
    if (!okay || record.magic != Magic || record.version != Version) return false;
    return record.checksum == crc32(reinterpret_cast<const uint8_t *>(&record), offsetof(Record<T>, checksum));
}

template <typename T> Record<T> *scratchRecords()
{
    // ESP8266 has a small task stack. Share two fixed buffers per record type.
    static Record<T> records[2];
    return records;
}

template <typename T> bool loadRecord(const char *a, const char *b, T &result)
{
    Record<T> &first = scratchRecords<T>()[0];
    Record<T> &second = scratchRecords<T>()[1];
    const bool firstGood = readSlot(a, first);
    const bool secondGood = readSlot(b, second);
    if (!firstGood && !secondGood) return false;
    result = (secondGood && (!firstGood || static_cast<int32_t>(second.generation - first.generation) > 0))
        ? second.payload : first.payload;
    return true;
}

template <typename T> bool saveRecord(const char *a, const char *b, const T &value)
{
    Record<T> &first = scratchRecords<T>()[0];
    Record<T> &second = scratchRecords<T>()[1];
    const bool firstGood = readSlot(a, first);
    const bool secondGood = readSlot(b, second);
    const bool firstNewest = firstGood && (!secondGood
        || static_cast<int32_t>(first.generation - second.generation) > 0);
    const char *target = firstNewest ? b : a;
    Record<T> &next = firstNewest ? second : first;
    next = Record<T>{};
    next.generation = (firstNewest ? first.generation : secondGood ? second.generation : 0) + 1;
    next.payload = value;
    next.checksum = crc32(reinterpret_cast<const uint8_t *>(&next), offsetof(Record<T>, checksum));
    File file = LittleFS.open(target, "w");
    if (!file) return false;
    const bool written = file.write(reinterpret_cast<const uint8_t *>(&next), sizeof(next)) == sizeof(next);
    file.close();
    if (!written) return false;
    const uint32_t expectedGeneration = next.generation;
    return readSlot(target, next) && next.generation == expectedGeneration;
}

bool hasAny(const char *a, const char *b)
{
    return LittleFS.exists(a) || LittleFS.exists(b);
}

template <size_t N> void copyField(char (&to)[N], const String &from)
{
    snprintf(to, N, "%s", from.c_str());
}
}

bool ProvisioningConfig::isValid() const
{
    return wifiSsid.length() >= 1 && wifiSsid.length() <= 32
        && wifiPassword.length() <= 63 && apiBaseUrl.startsWith("https://") && apiBaseUrl.length() <= 180
        && deviceUid.length() >= 3 && deviceUid.length() <= 64 && deviceKey.length() == 64
        && deviceLabel.length() >= 1 && deviceLabel.length() <= 160
        && measurementPoint.length() >= 1 && measurementPoint.length() <= 64;
}

bool DeviceState::begin()
{
    // The ESP8266 core otherwise auto-formats on mount failure, which would erase
    // pending readings and reset the monotone sequence after a damaged filesystem.
    LittleFS.setConfig(LittleFSConfig(false));
    fsReady_ = LittleFS.begin();
    storageHealthy_ = fsReady_;
    return fsReady_;
}

bool DeviceState::loadProvisioning(ProvisioningConfig &config)
{
    if (!fsReady_) return false;
    StoredProvisioning value{};
    if (!loadRecord("/prov0", "/prov1", value)) return false;
    config.wifiSsid = value.ssid;
    config.wifiPassword = value.wifiPassword;
    config.apiBaseUrl = value.apiUrl;
    config.deviceUid = value.uid;
    config.deviceKey = value.key;
    config.deviceLabel = value.label;
    config.measurementPoint = value.point;
    return config.isValid();
}

bool DeviceState::saveProvisioning(const ProvisioningConfig &config)
{
    if (!fsReady_ || !config.isValid()) return false;
    loadQueue();
    if (!storageHealthy_ || (!hasAny("/queue0", "/queue1") && !saveQueue(queue_))) return false;
    if (queue_.count > 0) {
        ProvisioningConfig previous;
        if (!loadProvisioning(previous) || previous.deviceUid != config.deviceUid
            || previous.measurementPoint != config.measurementPoint) {
            // Stored samples contain no independent device identity or point.
            return false;
        }
    }
    StoredProvisioning value{};
    copyField(value.ssid, config.wifiSsid);
    copyField(value.wifiPassword, config.wifiPassword);
    copyField(value.apiUrl, config.apiBaseUrl);
    copyField(value.uid, config.deviceUid);
    copyField(value.key, config.deviceKey);
    copyField(value.label, config.deviceLabel);
    copyField(value.point, config.measurementPoint);
    return saveRecord("/prov0", "/prov1", value);
}

bool DeviceState::loadRuntime(RuntimeConfig &config)
{
    if (!fsReady_ || !loadRecord("/run0", "/run1", config)) return false;
    config.maxBatchSize = constrain(config.maxBatchSize, static_cast<uint16_t>(1), static_cast<uint16_t>(BatchCapacity));
    return config.configVersion > 0;
}

bool DeviceState::saveRuntime(const RuntimeConfig &config)
{
    return fsReady_ && saveRecord("/run0", "/run1", config);
}

void DeviceState::ensureOperationalLoaded()
{
    if (operationalLoaded_) return;
    if (fsReady_ && !loadRecord("/oper0", "/oper1", operational_)
        && hasAny("/oper0", "/oper1")) {
        operational_.diagnosticFlags |= DiagnosticStorageFailed;
    }
    operationalLoaded_ = true;
}

bool DeviceState::saveOperational()
{
    ensureOperationalLoaded();
    if (fsReady_ && saveRecord("/oper0", "/oper1", operational_)) return true;
    operational_.diagnosticFlags |= DiagnosticStorageFailed;
    return false;
}

bool DeviceState::loadOperational(OperationalState &state)
{
    ensureOperationalLoaded();
    state = operational_;
    return fsReady_;
}

const OperationalState &DeviceState::operational()
{
    ensureOperationalLoaded();
    return operational_;
}

void DeviceState::recordSample(int64_t epoch) { ensureOperationalLoaded(); operational_.lastSampleAt = epoch; saveOperational(); }
void DeviceState::recordTransmissionSuccess(int64_t epoch)
{
    ensureOperationalLoaded(); operational_.lastSuccessfulTransmissionAt = epoch;
    operational_.nextNetworkAttemptAt = 0; operational_.retryStep = 0;
    operational_.noClockRetryDelaySeconds = 0; saveOperational();
}
void DeviceState::recordConfigCheck(int64_t epoch) { ensureOperationalLoaded(); operational_.lastConfigCheckAt = epoch; saveOperational(); }
void DeviceState::recordWifiSuccess() { ensureOperationalLoaded(); operational_.consecutiveWifiFailures = 0; saveOperational(); }
void DeviceState::recordWifiFailure()
{
    ensureOperationalLoaded(); ++operational_.wifiFailuresSinceReport;
    ++operational_.consecutiveWifiFailures;
    operational_.maxConsecutiveWifiFailures = max(operational_.maxConsecutiveWifiFailures, operational_.consecutiveWifiFailures);
    operational_.diagnosticFlags |= DiagnosticWifiConnectFailed; saveOperational();
}
void DeviceState::recordTransportFailure()
{
    ensureOperationalLoaded(); ++operational_.uploadFailuresSinceReport;
    operational_.diagnosticFlags |= DiagnosticTransportFailed; saveOperational();
}
void DeviceState::recordClockSyncFailure()
{
    ensureOperationalLoaded(); operational_.diagnosticFlags |= DiagnosticClockSyncFailed; saveOperational();
}
void DeviceState::recordSensorFailure()
{
    ensureOperationalLoaded();
    if (operational_.consecutiveSensorFailures < 255) ++operational_.consecutiveSensorFailures;
    operational_.diagnosticFlags |= DiagnosticSensorReadFailed;
    if (operational_.consecutiveSensorFailures >= 3) {
        operational_.diagnosticFlags |= DiagnosticSensorUnavailable | DiagnosticSensorReadRepeated;
    }
    saveOperational();
}
void DeviceState::recordSensorSuccess()
{
    ensureOperationalLoaded(); operational_.consecutiveSensorFailures = 0; saveOperational();
}
void DeviceState::recordQueueFull() { ensureOperationalLoaded(); operational_.diagnosticFlags |= DiagnosticQueueFull; saveOperational(); }
void DeviceState::recordAckIncomplete() { ensureOperationalLoaded(); operational_.diagnosticFlags |= DiagnosticAckIncomplete; saveOperational(); }
void DeviceState::recordConfigApplied(uint32_t version)
{
    ensureOperationalLoaded(); operational_.appliedConfigVersion = version;
    operational_.configStatus = ConfigApplyStatus::Applied;
    operational_.diagnosticFlags &= ~DiagnosticConfigRejected; saveOperational();
}
void DeviceState::recordConfigRejected()
{
    ensureOperationalLoaded(); operational_.configStatus = ConfigApplyStatus::Rejected;
    operational_.diagnosticFlags |= DiagnosticConfigRejected; saveOperational();
}
void DeviceState::recordSleepMode(SleepMode mode) { ensureOperationalLoaded(); operational_.lastSleepMode = mode; saveOperational(); }
void DeviceState::recordTimeAnchor(int64_t epoch)
{
    ensureOperationalLoaded(); operational_.timeAnchorAt = epoch; operational_.anchorCycles = 0; saveOperational();
}
void DeviceState::recordPlannedWake(int64_t epoch)
{
    ensureOperationalLoaded(); operational_.plannedWakeAt = epoch;
    if (operational_.anchorCycles < UINT16_MAX) ++operational_.anchorCycles;
    saveOperational();
}
void DeviceState::scheduleNetworkRetry(int64_t now, uint32_t seconds)
{
    ensureOperationalLoaded(); operational_.nextNetworkAttemptAt = now + seconds;
    operational_.noClockRetryDelaySeconds = seconds;
    if (operational_.retryStep < 4) ++operational_.retryStep;
    saveOperational();
}
void DeviceState::clearNetworkRetry()
{
    ensureOperationalLoaded(); operational_.nextNetworkAttemptAt = 0; operational_.retryStep = 0;
    operational_.noClockRetryDelaySeconds = 0; saveOperational();
}
void DeviceState::markTelemetryDelivered()
{
    ensureOperationalLoaded();
    operational_.wifiFailuresSinceReport = 0; operational_.uploadFailuresSinceReport = 0;
    operational_.maxConsecutiveWifiFailures = 0;
    operational_.diagnosticFlags = storageHealthy_ ? 0 : DiagnosticStorageFailed;
    saveOperational();
}

size_t DeviceState::diagnosticCodes(const char **codes, size_t capacity) const
{
    const_cast<DeviceState *>(this)->ensureOperationalLoaded();
    const struct { uint32_t flag; const char *code; } entries[] = {
        {DiagnosticWifiConnectFailed, "WIFI_CONNECT_FAILED"},
        {DiagnosticTransportFailed, "HTTPS_TRANSPORT_FAILED"},
        {DiagnosticClockSyncFailed, "CLOCK_SYNC_FAILED"},
        {DiagnosticSensorUnavailable, "SENSOR_UNAVAILABLE"},
        {DiagnosticQueueFull, "OFFLINE_QUEUE_FULL"},
        {DiagnosticConfigRejected, "CONFIG_REJECTED"},
        {DiagnosticAckIncomplete, "ACK_INCOMPLETE"},
        {DiagnosticStorageFailed, "STORAGE_FAILED"},
        {DiagnosticSensorReadFailed, "DHT_READ_FAILED"},
        {DiagnosticSensorReadRepeated, "DHT_READ_FAILED_REPEATED"},
    };
    size_t count = 0;
    for (const auto &entry : entries) {
        if ((operational_.diagnosticFlags & entry.flag) != 0 && count < capacity) codes[count++] = entry.code;
    }
    return count;
}

void DeviceState::loadQueue()
{
    if (queueLoaded_) return;
    if (!fsReady_ || !loadRecord("/queue0", "/queue1", queue_)) {
        if (!fsReady_ || hasAny("/queue0", "/queue1") || hasAny("/prov0", "/prov1")) {
            storageHealthy_ = false;
            ensureOperationalLoaded();
            operational_.diagnosticFlags |= DiagnosticStorageFailed;
            saveOperational();
        }
    }
    if (queue_.count > QueueCapacity || queue_.nextSequence == 0) storageHealthy_ = false;
    queueLoaded_ = true;
}

bool DeviceState::saveQueue(const QueueState &next)
{
    if (!storageHealthy_ || !fsReady_ || !saveRecord("/queue0", "/queue1", next)) {
        storageHealthy_ = false;
        ensureOperationalLoaded(); operational_.diagnosticFlags |= DiagnosticStorageFailed; saveOperational();
        return false;
    }
    queue_ = next;
    return true;
}

bool DeviceState::enqueue(int64_t measuredAt, float temperatureC, float humidityRh)
{
    loadQueue();
    if (!storageHealthy_ || queue_.count >= QueueCapacity || queue_.nextSequence == UINT64_MAX) return false;
    if (!isfinite(temperatureC) || !isfinite(humidityRh) || temperatureC < -100 || temperatureC > 150
        || humidityRh < 0 || humidityRh > 100 || measuredAt < 1704067200) return false;
    QueueState next = queue_;
    PendingMeasurement &item = next.items[next.count++];
    item.sequence = next.nextSequence++;
    item.measuredAt = measuredAt;
    item.temperatureC = temperatureC;
    item.humidityRh = humidityRh;
    return saveQueue(next);
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

bool DeviceState::acknowledge(const uint64_t *sequences, size_t count)
{
    loadQueue();
    if (!storageHealthy_) return false;
    QueueState next = queue_;
    uint16_t target = 0;
    for (uint16_t source = 0; source < queue_.count; ++source) {
        bool acknowledged = false;
        for (size_t index = 0; index < count; ++index) {
            if (queue_.items[source].sequence == sequences[index]) { acknowledged = true; break; }
        }
        if (!acknowledged) next.items[target++] = queue_.items[source];
    }
    if (target == queue_.count) return true;
    next.count = target;
    return saveQueue(next);
}

uint32_t DeviceState::incrementBootCount()
{
    ensureOperationalLoaded();
    if (operational_.bootCount < UINT32_MAX) ++operational_.bootCount;
    saveOperational();
    return operational_.bootCount;
}

void DeviceState::factoryReset()
{
    if (!fsReady_) return;
    for (const char *name : {"/prov0", "/prov1", "/run0", "/run1", "/oper0", "/oper1", "/queue0", "/queue1"}) {
        LittleFS.remove(name);
    }
    queue_ = QueueState{}; operational_ = OperationalState{};
    queueLoaded_ = false; operationalLoaded_ = false; storageHealthy_ = true;
}
