#include "HaccpClient.h"

#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <esp_system.h>
#include <time.h>

#include "FirmwareConfig.h"
#include "RootCertificate.h"

namespace {
bool validResponse(int status)
{
    return status >= 200 && status < 300;
}

const char *configStatusName(ConfigApplyStatus status)
{
    switch (status) {
        case ConfigApplyStatus::Applied: return "applied";
        case ConfigApplyStatus::Rejected: return "rejected";
        default: return "default";
    }
}
}

int HaccpClient::request(
    const ProvisioningConfig &provisioning,
    const String &method,
    const String &path,
    const String &body,
    String &response,
    String &error
)
{
    if (!provisioning.apiBaseUrl.startsWith("https://")) {
        error = "Server URL must use HTTPS.";
        return -1;
    }

    WiFiClientSecure transport;
    transport.setCACert(OPEN_HACCP_ROOT_CA);
    HTTPClient http;
    http.setConnectTimeout(12000);
    http.setTimeout(15000);
    if (!http.begin(transport, provisioning.apiBaseUrl + path)) {
        error = "HTTPS client could not be initialized.";
        return -1;
    }
    http.addHeader("Accept", "application/json");
    http.addHeader("X-Device-ID", provisioning.deviceUid);
    http.addHeader("X-Device-Key", provisioning.deviceKey);
    int status = -1;
    if (method == "GET") {
        status = http.GET();
    } else {
        http.addHeader("Content-Type", "application/json");
        status = http.POST(reinterpret_cast<uint8_t *>(const_cast<char *>(body.c_str())), body.length());
    }
    if (status > 0) {
        response = http.getString();
    } else {
        error = "HTTPS connection or certificate verification failed.";
    }
    http.end();
    return status;
}

bool HaccpClient::fetchConfig(const ProvisioningConfig &provisioning, RuntimeConfig &runtime, String &error)
{
    String response;
    const int status = request(provisioning, "GET", "/api/v1/device/config", "", response, error);
    if (!validResponse(status)) {
        if (status == 401) {
            error = "Device UID or device key was rejected.";
        } else if (status > 0) {
            error = "Configuration endpoint returned HTTP " + String(status) + ".";
        }
        return false;
    }

    JsonDocument document;
    if (deserializeJson(document, response) != DeserializationError::Ok
        || document["protocol_version"].as<int>() != 1
        || !document["config_version"].is<uint32_t>()) {
        error = "Configuration response is invalid.";
        return false;
    }

    RuntimeConfig candidate = runtime;
    if (!parseConfiguration(document.as<JsonVariantConst>(), provisioning, runtime, candidate, error)) {
        return false;
    }
    runtime = candidate;
    return true;
}

bool HaccpClient::sendHeartbeat(
    const ProvisioningConfig &provisioning,
    const RuntimeConfig &runtime,
    const DeviceDiagnostics &diagnostics,
    RuntimeConfig &receivedConfig,
    bool &configurationValid,
    String &configurationError,
    String &error
)
{
    JsonDocument document;
    document["protocol_version"] = 1;
    document["firmware_version"] = OPEN_HACCP_FIRMWARE_VERSION;
    document["hardware_revision"] = OPEN_HACCP_HARDWARE_REVISION;
    document["battery_mv"] = diagnostics.batteryMv;
    document["rssi_dbm"] = diagnostics.rssiDbm;
    document["wifi_connect_ms"] = diagnostics.wifiConnectMs;
    document["boot_count"] = diagnostics.bootCount;
    addDeviceStatus(document, diagnostics);
    String body;
    serializeJson(document, body);

    String response;
    const int status = request(provisioning, "POST", "/api/v1/device/heartbeat", body, response, error);
    if (!validResponse(status)) {
        if (status > 0) {
            error = "Heartbeat returned HTTP " + String(status) + ".";
        }
        return false;
    }
    JsonDocument responseDocument;
    if (deserializeJson(responseDocument, response) != DeserializationError::Ok
        || !responseDocument["success"].as<bool>()
        || responseDocument["protocol_version"].as<int>() != 1
        || !responseDocument["config_version"].is<uint32_t>()) {
        error = "Heartbeat response is invalid.";
        return false;
    }
    configurationValid = false;
    configurationError = "Configuration is missing from heartbeat response.";
    if (!responseDocument["configuration"].isNull()) {
        RuntimeConfig candidate = runtime;
        configurationValid = parseConfiguration(
            responseDocument["configuration"].as<JsonVariantConst>(),
            provisioning,
            runtime,
            candidate,
            configurationError
        );
        if (configurationValid) {
            receivedConfig = candidate;
        }
    }
    return true;
}

bool HaccpClient::uploadMeasurements(
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
)
{
    acknowledgedCount = 0;
    reportedConfigVersion = runtime.configVersion;
    itemCount = min(itemCount, static_cast<size_t>(runtime.maxBatchSize));
    if (itemCount == 0) {
        error = "No measurements to upload.";
        return false;
    }

    JsonDocument document;
    document["protocol_version"] = 1;
    char batchId[48];
    snprintf(batchId, sizeof(batchId), "%lu-%llu", static_cast<unsigned long>(diagnostics.bootCount),
        static_cast<unsigned long long>(items[0].sequence));
    document["batch_id"] = batchId;
    document["firmware_version"] = OPEN_HACCP_FIRMWARE_VERSION;
    document["hardware_revision"] = OPEN_HACCP_HARDWARE_REVISION;
    document["sent_at"] = utcTimestamp(time(nullptr));
    JsonObject diagnosticObject = document["diagnostics"].to<JsonObject>();
    diagnosticObject["battery_mv"] = diagnostics.batteryMv;
    diagnosticObject["rssi_dbm"] = diagnostics.rssiDbm;
    diagnosticObject["wifi_connect_ms"] = diagnostics.wifiConnectMs;
    diagnosticObject["boot_count"] = diagnostics.bootCount;
    addDeviceStatus(document, diagnostics);
    JsonArray measurements = document["measurements"].to<JsonArray>();
    for (size_t index = 0; index < itemCount; ++index) {
        JsonObject measurement = measurements.add<JsonObject>();
        measurement["measurement_point"] = provisioning.measurementPoint;
        measurement["sequence"] = items[index].sequence;
        measurement["measured_at"] = utcTimestamp(items[index].measuredAt);
        measurement["temperature_c"] = roundf(items[index].temperatureC * 1000.0F) / 1000.0F;
        measurement["humidity_rh"] = roundf(items[index].humidityRh * 1000.0F) / 1000.0F;
        measurement["battery_mv"] = items[index].batteryMv;
    }
    String body;
    serializeJson(document, body);

    String response;
    const int status = request(provisioning, "POST", "/api/v1/device/measurements", body, response, error);
    if (!validResponse(status)) {
        if (status > 0) {
            error = "Measurement upload returned HTTP " + String(status) + ".";
        }
        return false;
    }
    JsonDocument responseDocument;
    if (deserializeJson(responseDocument, response) != DeserializationError::Ok
        || !responseDocument["success"].as<bool>()
        || responseDocument["protocol_version"].as<int>() != 1
        || strcmp(responseDocument["batch_id"] | "", batchId) != 0) {
        error = "Measurement response is invalid.";
        return false;
    }
    reportedConfigVersion = responseDocument["config_version"] | runtime.configVersion;
    for (JsonObject acknowledgement : responseDocument["acknowledgements"].as<JsonArray>()) {
        const char *statusValue = acknowledgement["status"] | "";
        const uint64_t sequence = acknowledgement["sequence"] | 0ULL;
        const char *point = acknowledgement["measurement_point"] | "";
        const size_t index = acknowledgement["index"] | itemCount;
        if (index < itemCount && sequence == items[index].sequence && provisioning.measurementPoint == point
            && (strcmp(statusValue, "accepted") == 0 || strcmp(statusValue, "duplicate") == 0)) {
            acknowledgedSequences[acknowledgedCount++] = sequence;
        }
    }
    configurationValid = false;
    configurationError = "Configuration is missing from measurement response.";
    if (!responseDocument["configuration"].isNull()) {
        RuntimeConfig candidate = runtime;
        configurationValid = parseConfiguration(
            responseDocument["configuration"].as<JsonVariantConst>(),
            provisioning,
            runtime,
            candidate,
            configurationError
        );
        if (configurationValid) {
            receivedConfig = candidate;
        }
    }
    return true;
}

bool HaccpClient::parseConfiguration(
    JsonVariantConst document,
    const ProvisioningConfig &provisioning,
    const RuntimeConfig &current,
    RuntimeConfig &candidate,
    String &error
)
{
    if (document["protocol_version"].as<int>() != 1 || !document["config_version"].is<uint32_t>()
        || !document["measurement"].is<JsonObjectConst>() || !document["measurement_points"].is<JsonArrayConst>()
        || !document["upload"].is<JsonObjectConst>() || !document["alarm"].is<JsonObjectConst>()) {
        error = "Configuration structure is invalid.";
        return false;
    }

    candidate = current;
    candidate.configVersion = document["config_version"].as<uint32_t>();
    candidate.defaultMeasurementIntervalSeconds = document["measurement"]["interval_seconds"] | 0U;
    candidate.measurementIntervalSeconds = candidate.defaultMeasurementIntervalSeconds;
    bool configuredPointFound = false;
    for (JsonObjectConst point : document["measurement_points"].as<JsonArrayConst>()) {
        const char *code = point["code"] | "";
        const uint32_t interval = point["interval_seconds"] | 0U;
        if (interval < 30 || interval > 86400) {
            error = "A measurement point interval is invalid.";
            return false;
        }
        if (provisioning.measurementPoint == code) {
            candidate.measurementIntervalSeconds = interval;
            configuredPointFound = true;
        }
    }
    candidate.uploadIntervalSeconds = document["upload"]["interval_seconds"] | 0U;
    const uint32_t serverBatchSize = document["upload"]["max_batch_size"] | 0U;
    candidate.maxBatchSize = static_cast<uint16_t>(min(
        serverBatchSize,
        static_cast<uint32_t>(DeviceState::QueueCapacity)
    ));
    candidate.alarmEnabled = document["alarm"]["enabled"] | false;
    candidate.temperatureMinC = document["alarm"]["temperature_min_c"].is<float>()
        ? document["alarm"]["temperature_min_c"].as<float>() : NAN;
    candidate.temperatureMaxC = document["alarm"]["temperature_max_c"].is<float>()
        ? document["alarm"]["temperature_max_c"].as<float>() : NAN;

    if (candidate.defaultMeasurementIntervalSeconds < 30 || candidate.defaultMeasurementIntervalSeconds > 86400
        || candidate.uploadIntervalSeconds < 60 || candidate.uploadIntervalSeconds > 604800
        || serverBatchSize < 1 || serverBatchSize > 500 || candidate.maxBatchSize < 1
        || !configuredPointFound
        || (candidate.alarmEnabled && (isnan(candidate.temperatureMinC) || isnan(candidate.temperatureMaxC)
            || candidate.temperatureMinC < -100 || candidate.temperatureMaxC > 150
            || candidate.temperatureMinC >= candidate.temperatureMaxC))) {
        error = configuredPointFound
            ? "Configuration values are invalid."
            : "The provisioned measurement point is not active in this configuration.";
        return false;
    }
    return true;
}

void HaccpClient::addDeviceStatus(JsonDocument &document, const DeviceDiagnostics &diagnostics)
{
    JsonObject info = document["device_info"].to<JsonObject>();
    info["board_model"] = OPEN_HACCP_BOARD_MODEL;
    info["chip_model"] = ESP.getChipModel();
    info["chip_revision"] = ESP.getChipRevision();
    info["cpu_cores"] = ESP.getChipCores();
    info["flash_bytes"] = ESP.getFlashChipSize();
    info["psram_bytes"] = ESP.getPsramSize();
    info["heap_free_bytes"] = ESP.getFreeHeap();
    info["sensor_model"] = OPEN_HACCP_SENSOR_MODEL;
    info["sensor_status"] = diagnostics.sensorReady ? "ready" : "unavailable";
    info["queue_capacity"] = DeviceState::QueueCapacity;
    JsonArray capabilities = info["capabilities"].to<JsonArray>();
    for (const char *capability : {"temperature", "humidity", "battery", "wifi_rssi", "deep_sleep", "remote_config", "provisioning_ap"}) {
        capabilities.add(capability);
    }

    JsonObject status = document["operational_status"].to<JsonObject>();
    status["provisioned"] = true;
    status["queue_depth"] = diagnostics.queueDepth;
    status["awake_ms"] = diagnostics.awakeMs;
    status["wake_reason"] = diagnostics.wakeReason;
    status["reset_reason"] = diagnostics.resetReason;
    status["requested_sleep_mode"] = diagnostics.requestedSleepMode;
    status["wifi_failures_since_report"] = diagnostics.operational.wifiFailuresSinceReport;
    status["upload_failures_since_report"] = diagnostics.operational.uploadFailuresSinceReport;
    status["max_consecutive_wifi_failures"] = diagnostics.operational.maxConsecutiveWifiFailures;
    status["sleep_fallbacks_since_report"] = diagnostics.operational.sleepFallbacksSinceReport;

    JsonObject configAck = document["config_ack"].to<JsonObject>();
    configAck["applied_version"] = diagnostics.operational.appliedConfigVersion;
    configAck["status"] = configStatusName(diagnostics.operational.configStatus);

    const bool batch = document["diagnostics"].is<JsonObject>();
    JsonArray errors = batch
        ? document["diagnostics"]["errors"].to<JsonArray>()
        : document["errors"].to<JsonArray>();
    for (size_t index = 0; index < diagnostics.errorCount; ++index) {
        errors.add(diagnostics.errors[index]);
    }
    if (diagnostics.errorCount == 0) {
        if (batch) {
            document["diagnostics"].remove("errors");
        } else {
            document.remove("errors");
        }
    }
}

String HaccpClient::utcTimestamp(int64_t epoch)
{
    struct tm value{};
    const time_t seconds = static_cast<time_t>(epoch);
    gmtime_r(&seconds, &value);
    char buffer[21];
    strftime(buffer, sizeof(buffer), "%Y-%m-%dT%H:%M:%SZ", &value);
    return String(buffer);
}
