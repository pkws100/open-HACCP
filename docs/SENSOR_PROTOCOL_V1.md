# Open HACCP Sensor Protocol V1

Protocol Version: **1**

Sensor Protocol V1 is the stable HTTP/JSON contract between the Open HACCP backend and sensor firmware. It is independent of ESP32 pin assignments and treats a physical `device` separately from one or more logical `measurement_point` records.

## Transport and authentication

- Production base URL: `https://haccp.pow24.org`
- Base path: `/api/v1`
- Media type: `application/json`
- Maximum request body: 256 KiB
- Maximum batch size: 500 measurements
- All timestamps: RFC 3339 UTC ending in `Z`
- Authenticated requests require both `X-Device-ID` and `X-Device-Key`.

Production devices must use HTTPS with TLS 1.2 or newer, validate the complete certificate chain against a maintained CA trust store, verify the requested hostname, and reject expired, untrusted, or mismatched certificates. Plain HTTP is permitted only for local development and the isolated reverse-proxy-to-container hop. The payload remains HTTP/JSON inside the TLS connection.

The device key contains 32 random bytes encoded as 64 hexadecimal characters. The server stores only an HMAC-SHA-256 digest protected by `DEVICE_API_KEY_PEPPER`. Missing, invalid, rotated, and disabled credentials all return the same `DEVICE_AUTHENTICATION_FAILED` response.

## Technical value ranges

| Field | Type | Unit / range |
|---|---|---|
| `temperature_c` | number | degrees Celsius, -100 through 150 |
| `humidity_rh` | number | percent relative humidity, 0 through 100 |
| `battery_mv` | integer | millivolts, 0 through 10000 |
| `rssi_dbm` | integer | dBm, -120 through 0 |
| `wifi_connect_ms` | integer | milliseconds, 0 through 120000 |
| `boot_count` | integer | 0 through 4294967295 |
| `sequence` | integer | 1 through signed 64-bit maximum |

These limits detect technical errors and are not HACCP alarm thresholds. Batch metadata, diagnostics, and heartbeat objects may contain new metadata fields. Measurement objects are strict: unknown fields reject that individual measurement.

## Measurement batch

`POST /api/v1/device/measurements` accepts the request defined by [`protocol-v1.schema.json`](protocol-v1.schema.json). All shown fields are required.

```json
{
  "protocol_version": 1,
  "batch_id": "00000042-00000127",
  "firmware_version": "0.1.0",
  "hardware_revision": "prototype-a",
  "sent_at": "2026-08-07T16:00:00Z",
  "diagnostics": {
    "battery_mv": 6127,
    "rssi_dbm": -58,
    "wifi_connect_ms": 1834,
    "boot_count": 42
  },
  "measurements": [{
    "measurement_point": "fridge-1",
    "sequence": 1001,
    "measured_at": "2026-08-07T15:55:00Z",
    "temperature_c": 4.10,
    "humidity_rh": 69.80,
    "battery_mv": 6132
  }]
}
```

`measured_at` must not be later than `sent_at`. Neither may be more than 24 hours ahead of server time. Old buffered values remain valid.

### Acknowledgement and partial rejection

A syntactically and structurally valid batch receives HTTP 200 even if individual measurements are rejected. Every received array element is represented either by an `acknowledgements` entry or a `rejections` entry using the zero-based request `index`.

```json
{
  "success": true,
  "protocol_version": 1,
  "server_time": "2026-08-07T16:00:02Z",
  "batch_id": "00000042-00000127",
  "result": {
    "received": 3,
    "accepted": 2,
    "duplicates": 0,
    "rejected": 1,
    "last_sequence": 1003
  },
  "acknowledgements": [
    {"index": 0, "measurement_point": "fridge-1", "sequence": 1001, "status": "accepted"},
    {"index": 2, "measurement_point": "fridge-1", "sequence": 1003, "status": "accepted"}
  ],
  "rejections": [
    {"index": 1, "measurement_point": "fridge-1", "sequence": 1002, "code": "INVALID_HUMIDITY", "message": "humidity_rh must be between 0 and 100"}
  ],
  "sequence_gaps": [],
  "config_version": 1,
  "configuration": {
    "protocol_version": 1,
    "config_version": 1,
    "server_time": "2026-08-07T16:00:02Z",
    "measurement": {"interval_seconds": 300},
    "measurement_points": [
      {"code": "fridge-1", "interval_seconds": 300}
    ],
    "upload": {"interval_seconds": 21600, "max_batch_size": 500},
    "alarm": {
      "enabled": false,
      "temperature_min_c": null,
      "temperature_max_c": null
    }
  }
}
```

Only explicit `accepted` and `duplicate` acknowledgements authorize firmware to remove or mark local records as sent. `last_sequence` and `sequence_gaps` are informational and must never be used as deletion authorization.

The full current `configuration` is piggybacked on every successful authenticated batch response. Apply it only after ACK correlation and independent complete config validation; config processing never changes the meaning of acknowledgements. The duplicated top-level `config_version` remains for older V1 clients.

## Idempotency and sequence rules

Sequences increase monotonically per measurement point. The database identity is `(device, measurement_point, sequence)`.

- First receipt stores the measurement and returns `accepted`.
- A retry with the same normalized timestamp, temperature, humidity, and battery returns `duplicate` without inserting.
- The same identity with different data returns `SEQUENCE_CONFLICT`; the stored record is never changed.
- `batch_id` is diagnostic and need not be a UUID. Every HTTP retry is stored as a separate transmission attempt.
- `sequence_gaps` reports newly observed missing ranges. No alarm is generated.

## Config and heartbeat

`GET /api/v1/device/config` returns the current configuration. Version 1 defaults are 300 seconds measurement interval, 21600 seconds upload interval, and 500 measurements per batch. `measurement.interval_seconds` is the device default. Every active logical point also appears in `measurement_points` with its effective interval; a point-specific server override replaces the default for that point. Measurement intervals are 30 through 86400 seconds and upload intervals are 60 through 604800 seconds.

An authenticated operator may create a new version through the separate dashboard settings API. Firmware therefore treats a higher `config_version` as an atomic replacement and receives reporting intervals plus the updated `alarm.enabled`, `temperature_min_c`, and `temperature_max_c`. The normal range is inclusive. Dashboard battery display thresholds are intentionally not part of Sensor Protocol V1 and do not appear in the firmware config response.

`POST /api/v1/device/heartbeat` requires protocol version, firmware and hardware versions, battery, RSSI, Wi-Fi connection duration, and boot counter. It updates device status and creates a diagnostic transmission without measurements. Its successful response also contains the full current `configuration`, so a wake cycle can report diagnostics and update scheduling in one HTTPS exchange.

Only operational, non-secret configuration is returned. WLAN credentials, device keys, setup passwords, and other provisioning secrets are never included in config, heartbeat, or batch responses. `GET /config` remains the authoritative fallback and explicit configuration check.

## HTTP and error codes

All errors use `{ "success": false, "error": { "code": "...", "message": "..." } }`.

| HTTP | Meaning |
|---|---|
| 200 | Request processed; inspect per-measurement ACKs/rejections |
| 400 | Invalid JSON or non-object JSON |
| 401 | Device authentication failed |
| 404 | Unknown route |
| 405 | Method not allowed |
| 413 | Body exceeds 256 KiB |
| 415 | Content-Type is not JSON |
| 422 | Invalid batch envelope or heartbeat |
| 429 | Reserved for rate limiting |
| 500 | Internal error |

Stable envelope/heartbeat codes include `INVALID_JSON`, `UNSUPPORTED_PROTOCOL_VERSION`, `MISSING_REQUIRED_FIELD`, `INVALID_BATCH_ID`, `INVALID_FIRMWARE_VERSION`, `INVALID_HARDWARE_REVISION`, `INVALID_SENT_AT`, `INVALID_DIAGNOSTICS`, `EMPTY_BATCH`, `BATCH_SIZE_EXCEEDED`, and `INVALID_HEARTBEAT`.

Stable measurement rejection codes include `MISSING_MEASUREMENT_FIELD`, `UNKNOWN_MEASUREMENT_FIELD`, `INVALID_MEASUREMENT_POINT`, `UNKNOWN_MEASUREMENT_POINT`, `INVALID_SEQUENCE`, `INVALID_MEASURED_AT`, `INVALID_TEMPERATURE`, `INVALID_HUMIDITY`, `INVALID_BATTERY`, `INVALID_MEASUREMENT`, and `SEQUENCE_CONFLICT`.

The normative machine-readable descriptions are [`protocol-v1.schema.json`](protocol-v1.schema.json) and [`openapi.yaml`](openapi.yaml).

## Operator settings outside the firmware protocol

`POST /api/v1/dashboard/devices` is the Basic-authenticated operator onboarding endpoint. It atomically creates a device, its first measurement point, and config version 1. The optional UID is generated when absent. Its non-cacheable HTTP 201 response contains a 32-random-byte device key encoded as 64 hexadecimal characters exactly once; only the peppered HMAC is retained server-side. Invalid input returns `INVALID_DEVICE_PROVISIONING`, and a requested duplicate UID returns `DEVICE_UID_ALREADY_EXISTS`.

The resulting setup package is transferred through the physical sensor's WPA2-protected local SoftAP portal. That local portal, site WLAN credentials, captive-portal behavior, recovery button, and NVS storage are outside Sensor Protocol V1; see [`DEVICE_PROVISIONING.md`](DEVICE_PROVISIONING.md). Normal device traffic begins only after the firmware verifies HTTPS and authenticates the config request.

`PUT /api/v1/dashboard/devices/{device_uid}/settings` uses Dashboard Basic authentication and optimistic concurrency through `expected_config_version`. It is an operator API, not a sensor endpoint. A successful update creates a complete new `device_configs` row; an outdated version receives HTTP 409 with `DEVICE_CONFIG_VERSION_CONFLICT`. Invalid temperature, battery, or schedule ranges receive HTTP 422 with `INVALID_DEVICE_SETTINGS`, and an unknown device receives `DASHBOARD_DEVICE_NOT_FOUND`.

The optional `schedule` object changes the device default measurement interval, upload interval, and a complete list of point-specific overrides. Omitting it preserves the preceding schedule. Every listed point must currently be active for that device; duplicate or unknown point codes are rejected. An empty `measurement_points` list removes all overrides so every point inherits the default.

```json
{
  "expected_config_version": 1,
  "alarm": {"enabled": true, "temperature_min_c": 2.0, "temperature_max_c": 7.0},
  "battery": {"low_threshold_mv": 5600, "full_threshold_mv": 6000},
  "schedule": {
    "default_measurement_interval_seconds": 300,
    "upload_interval_seconds": 21600,
    "measurement_points": [
      {"measurement_point": "fridge-1", "interval_seconds": 120}
    ]
  }
}
```

The dashboard evaluates `normal`, `below_min`, `above_max`, `disabled`, and `no_data` directly from current measurements. These are display states only. Protocol V1 does not yet define alarm event records, hysteresis, escalation, push, or email delivery.
