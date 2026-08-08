#include "DeviceState.h"

#include <cstring>

namespace {
constexpr char ProvisioningNamespace[] = "haccp-prov";
constexpr char RuntimeNamespace[] = "haccp-run";
constexpr char QueueNamespace[] = "haccp-queue";

struct PersistedRuntime {
    uint32_t magic = 0x48414352;
    uint16_t version = 1;
    RuntimeConfig config{};
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
    const bool loaded = preferences.getBytesLength("config") == sizeof(stored)
        && preferences.getBytes("config", &stored, sizeof(stored)) == sizeof(stored)
        && stored.magic == 0x48414352 && stored.version == 1;
    preferences.end();
    if (!loaded) {
        return false;
    }
    config = stored.config;
    config.maxBatchSize = constrain(config.maxBatchSize, static_cast<uint16_t>(1), static_cast<uint16_t>(QueueCapacity));
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
    for (const char *name : {ProvisioningNamespace, RuntimeNamespace, QueueNamespace}) {
        Preferences preferences;
        if (preferences.begin(name, false)) {
            preferences.clear();
            preferences.end();
        }
    }
    queue_ = QueueState{};
    queueLoaded_ = true;
}
