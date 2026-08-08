#include "HaccpClient.h"

#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <time.h>

#include "FirmwareConfig.h"
#include "RootCertificate.h"

namespace {
bool validResponse(int status)
{
    return status >= 200 && status < 300;
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
    candidate.configVersion = document["config_version"].as<uint32_t>();
    candidate.measurementIntervalSeconds = document["measurement"]["interval_seconds"] | 300U;
    candidate.uploadIntervalSeconds = document["upload"]["interval_seconds"] | 21600U;
    const uint16_t serverBatchSize = document["upload"]["max_batch_size"] | 64U;
    candidate.maxBatchSize = min(serverBatchSize, static_cast<uint16_t>(DeviceState::QueueCapacity));
    candidate.alarmEnabled = document["alarm"]["enabled"] | false;
    candidate.temperatureMinC = document["alarm"]["temperature_min_c"].is<float>()
        ? document["alarm"]["temperature_min_c"].as<float>() : NAN;
    candidate.temperatureMaxC = document["alarm"]["temperature_max_c"].is<float>()
        ? document["alarm"]["temperature_max_c"].as<float>() : NAN;
    if (candidate.measurementIntervalSeconds < 1 || candidate.uploadIntervalSeconds < 1 || candidate.maxBatchSize < 1
        || (candidate.alarmEnabled && (isnan(candidate.temperatureMinC) || isnan(candidate.temperatureMaxC)
            || candidate.temperatureMinC >= candidate.temperatureMaxC))) {
        error = "Configuration values are invalid.";
        return false;
    }
    runtime = candidate;
    return true;
}

bool HaccpClient::sendHeartbeat(
    const ProvisioningConfig &provisioning,
    const RuntimeConfig &runtime,
    const DeviceDiagnostics &diagnostics,
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
    return deserializeJson(responseDocument, response) == DeserializationError::Ok
        && responseDocument["success"].as<bool>()
        && responseDocument["protocol_version"].as<int>() == 1
        && responseDocument["config_version"].as<uint32_t>() >= runtime.configVersion;
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
    return true;
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
