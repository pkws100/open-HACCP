# Firmware Contract: Open HACCP Sensor Protocol V1

This document is sufficient to implement a hardware-independent client for Sensor Protocol V1. It intentionally contains no ESP32 pin assignments, SHT45 driver choice, Wi-Fi provisioning, or OTA design.

## Required firmware constants

```text
PROTOCOL_VERSION = 1
DEVICE_ID
DEVICE_KEY
API_BASE_URL

MEASUREMENT_INTERVAL_SECONDS = 300       // replaced by server config
UPLOAD_INTERVAL_SECONDS = 21600          // replaced by server config
MAX_BATCH_SIZE = 500                     // replaced by server config, never exceed 500

FIRMWARE_VERSION
HARDWARE_REVISION                        // prototype-a or prototype-b initially
MEASUREMENT_POINT_CODE                   // fridge-1 initially
```

`DEVICE_KEY` is a 64-character hexadecimal secret. Store it in protected device configuration and never print it to serial diagnostics in production.

## HTTP contract

Use `API_BASE_URL` plus these paths:

- `GET /api/v1/device/config`
- `POST /api/v1/device/heartbeat`
- `POST /api/v1/device/measurements`

Every request sends:

```text
X-Device-ID: <DEVICE_ID>
X-Device-Key: <DEVICE_KEY>
```

POST requests additionally send `Content-Type: application/json`. Requests must stay below 256 KiB. JSON property names and primitive types are exact; do not encode numbers as strings.

All timestamps use UTC and this format:

```text
YYYY-MM-DDTHH:MM:SSZ
```

Fractional seconds up to six digits are accepted. Synchronize time before constructing upload timestamps. `measured_at` must be less than or equal to `sent_at`; sensor time more than 24 hours ahead of the server is rejected. Never replace a measurement timestamp with upload time.

## Measurement storage record

Persist every measurement before attempting Wi-Fi. A durable local record contains at least:

```text
measurement_point : string, 1..64, lowercase letters/digits/hyphen
sequence          : positive int64, monotonic for this measurement point
measured_at       : UTC timestamp
temperature_c     : number, -100..150
humidity_rh       : number, 0..100
battery_mv        : integer, 0..10000
upload_state      : pending | acknowledged
```

Sequence state must survive reset and deep sleep. Never reuse a sequence for changed data. A batch may contain more than one measurement point, but sequences are independent per point.

## Batch request

All fields below are required. Maximum `measurements` length is the smaller of the server config and 500.

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

`batch_id` is 1..64 characters matching `[A-Za-z0-9][A-Za-z0-9._:-]*`. It is diagnostic, not the idempotency key. A practical value is `<zero-padded boot_count>-<zero-padded upload_count>`.

Required diagnostic ranges:

| Value | Range | Unit |
|---|---:|---|
| battery | 0..10000 | mV |
| RSSI | -120..0 | dBm |
| Wi-Fi connect | 0..120000 | ms |
| boot count | 0..4294967295 | count |

## Processing the batch response

HTTP 200 means the envelope was processed, not that every measurement succeeded. Verify all of:

1. JSON parses successfully.
2. `success` is `true`.
3. `protocol_version` is 1.
4. `batch_id` exactly matches the request.
5. Each acknowledgement exactly matches an item from the current request by `index`, `measurement_point`, and `sequence`.
6. `status` is `accepted` or `duplicate`.

Only then mark that specific local record acknowledged. Both statuses are safe: `duplicate` means the identical record was already stored. Never delete based on HTTP completion, counts, `last_sequence`, or `sequence_gaps`.

Every sent item should have either an acknowledgement or rejection. If an item has neither, keep it pending and record a protocol diagnostic.

Rejections are not acknowledgements:

- Correct locally recoverable serialization/value errors before retrying.
- `UNKNOWN_MEASUREMENT_POINT` requires server provisioning or configuration correction.
- `SEQUENCE_CONFLICT` means this sequence is already bound to different data. Keep the local record for diagnosis; do not overwrite it or advance deletion past it.

`sequence_gaps` is server-side diagnostic information only. It does not authorize deletion or require an immediate retransmission.

## Config response

On boot, after key rotation, and periodically before upload, call the config endpoint:

```json
{
  "protocol_version": 1,
  "config_version": 1,
  "server_time": "2026-08-07T16:00:00Z",
  "measurement": {"interval_seconds": 300},
  "upload": {"interval_seconds": 21600, "max_batch_size": 500},
  "alarm": {
    "enabled": false,
    "temperature_min_c": null,
    "temperature_max_c": null
  }
}
```

Apply a config only after complete validation, then durably store its `config_version`. Never accept a `max_batch_size` above the compiled protocol maximum of 500. `server_time` may be used to diagnose or correct clock drift using a platform-appropriate secure time strategy.

## Heartbeat request

Send a heartbeat when an upload connection is made but no measurements are pending:

```json
{
  "protocol_version": 1,
  "firmware_version": "0.1.0",
  "hardware_revision": "prototype-a",
  "battery_mv": 6127,
  "rssi_dbm": -58,
  "wifi_connect_ms": 1834,
  "boot_count": 42
}
```

A valid response contains `success: true`, `protocol_version: 1`, `server_time`, and `config_version`. A heartbeat never acknowledges measurement data.

## Failure and retry policy

| Result | Required firmware action |
|---|---|
| HTTP 200 | Validate body and process explicit ACK entries only |
| 400, 404, 415, 422 | Keep affected data; record error; do not aggressively retry unchanged payload |
| 401 | Keep all data; stop normal uploads; flag provisioning/key failure; retry only slowly or after credential change |
| 413 | Keep all data; reduce batch/request size before retry |
| 429, 500, 502, 503 | Keep all data and retry with backoff |
| Network/DNS/TLS/timeout | Keep all data and retry with backoff |
| Invalid/truncated response | Keep all unacknowledged data and retry with backoff |

Recommended retry delays are 1, 5, 15, 30, then 60 minutes, with jitter. Cap subsequent retries at 60 minutes. A successful upload resets the backoff. Deep sleep and reset must not clear pending measurements or retry state.

## Reference state machine

```text
BOOT / RTC WAKE
    restore durable sequence, config, pending records, retry state
    read sensor and battery
    if values valid:
        sequence[point] += 1 and persist sequence
        persist new pending measurement

    if upload not due and pending storage not near capacity:
        deep sleep

    connect Wi-Fi
    if connection fails:
        keep all pending data
        schedule backoff
        deep sleep

    optionally GET config
    if config response is valid and version is newer:
        persist complete config atomically

    if no pending measurements:
        POST heartbeat
    else:
        select oldest pending records up to min(config.max_batch_size, 500)
        construct one batch and retain the exact sent-item list
        POST measurement batch

        if HTTP 200 and response identity/shape is valid:
            for each valid acknowledgement:
                match request index + point + sequence
                if status accepted or duplicate:
                    mark only that record acknowledged
            retain every rejected, missing, or ambiguous record
        else:
            retain every record

    compact acknowledged storage only after durable ACK state is committed
    disconnect Wi-Fi
    deep sleep
```

Power loss is permitted at every arrow. After restart the device must either resend a pending record or know from durable state that it received a valid explicit acknowledgement. Resending is safe and expected.

## Compatibility rule

Firmware compiled for Protocol V1 must send `protocol_version: 1` and refuse responses claiming another protocol. Metadata objects may gain fields, which V1 clients should ignore. Required fields, measurement strictness, ACK identity, types, units, and limits remain binding for V1.
