# Firmware Contract: Open HACCP Sensor Protocol V1

This document is sufficient to implement a hardware-independent client for Sensor Protocol V1. The concrete ESP32-S3/SHT45 onboarding reference is documented in [`DEVICE_PROVISIONING.md`](DEVICE_PROVISIONING.md) and implemented in `firmware/esp32-s3`; OTA and production manufacturing remain separate release concerns.

## Required firmware constants

```text
PROTOCOL_VERSION = 1
DEVICE_ID                              // written by verified local onboarding
DEVICE_KEY                             // written by verified local onboarding
API_BASE_URL                           // written by verified local onboarding

MEASUREMENT_INTERVAL_SECONDS = 300       // replaced by server config
MEASUREMENT_POINT_INTERVALS              // effective interval per active point from server config
UPLOAD_INTERVAL_SECONDS = 21600          // replaced by server config
MAX_BATCH_SIZE = 500                     // replaced by server config, never exceed 500

FIRMWARE_VERSION
HARDWARE_REVISION                        // prototype-a or prototype-b initially
MEASUREMENT_POINT_CODE                 // written by verified local onboarding
```

`DEVICE_KEY` is a 64-character hexadecimal secret. Store it in protected device configuration and never print it to serial diagnostics in production.

For the VPS test and deployed firmware, set:

```text
API_BASE_URL = https://haccp.pow24.org
```

Do not append a trailing slash.

## First boot and local onboarding

An unprovisioned ESP32 must expose a WPA2-protected SoftAP plus local setup portal, not an IBSS network. Standard phones and laptops can join this direct link without existing infrastructure. The reference SSID is `OpenHACCP-<device suffix>` and the portal is `http://192.168.4.1`. The setup password must be unique per device or controlled batch, at least eight characters, delivered on a physical label, and absent from source control and logs.

The portal collects site WLAN credentials, the HTTPS API base URL, device ID/key, human-readable label, and measurement-point code. Keep candidate values only in memory until all of these succeed:

1. connect to the selected site WLAN in AP+STA mode;
2. synchronize UTC;
3. establish HTTPS with CA-chain and hostname validation;
4. authenticate through `GET /api/v1/device/config`;
5. fully validate the returned config.

Persist the runtime config first and mark the provisioning record ready last. Then reboot and disable the setup AP. A failed check leaves provisioning mode active and must not persist partial credentials as an operational configuration. Never send a device key in a URL or print it to serial output.

A five-second factory-reset gesture may erase the provisioning record and return to setup mode. If it also erases pending measurements and sequence state—as the reference implementation does—label it as a destructive physical recovery action. Normal restart and deep sleep must preserve all state.

## HTTPS transport and HTTP contract

Deployment transport is HTTPS only. The sensor must validate the server certificate chain against a maintained public CA trust store and verify that the certificate hostname matches `haccp.pow24.org`. Never ship firmware with an insecure TLS mode, disabled peer verification, a hard-coded leaf certificate, or an expired CA bundle. Support TLS 1.2 or newer and synchronize UTC time before certificate validation.

The application protocol inside the encrypted connection is HTTP/JSON. TLS terminates at the reverse proxy; the proxy-to-application Docker hop may use HTTP and is not visible to the sensor.

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

Sequence state must survive reset and deep sleep. Never reuse a sequence for changed data. A batch may contain more than one measurement point, but sequences are independent per point. The current ESP32-S3/SHT45 build represents one provisioned physical point, selects that point's effective server interval, compiles a 64-record durable queue, and caps a server-provided larger batch size at 64; another client may support more physical points or a different lower compiled maximum than the protocol limit.

Durably store the current config and applied-version acknowledgement, effective interval for every supported physical point, last sample/upload/config-check and retry deadlines, retry counters, boot counter, monotonic sequences, pending queue and stable failure flags. Wake/reset must reconstruct the same pending records and deadlines. The checked-in power-managed reference persists these domains in NVS. A production firmware must still size storage for the selected offline guarantee and complete destructive power-loss/A-B or journal recovery tests; 64 records are not sufficient for every interval, upload cadence and outage duration.

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
    "boot_count": 42,
    "errors": ["RTC_SYNC_RETRIED"]
  },
  "device_info": {
    "board_model": "ESP32-S3-DevKitC-1",
    "chip_model": "ESP32-S3",
    "chip_revision": 0,
    "cpu_cores": 2,
    "flash_bytes": 8388608,
    "psram_bytes": 0,
    "heap_free_bytes": 241920,
    "sensor_model": "SHT45",
    "sensor_status": "ready",
    "queue_capacity": 64,
    "capabilities": ["temperature", "humidity", "battery", "wifi_rssi", "deep_sleep", "remote_config", "provisioning_ap"]
  },
  "operational_status": {
    "provisioned": true,
    "queue_depth": 3,
    "awake_ms": 2450,
    "wake_reason": "timer",
    "reset_reason": "deep_sleep",
    "requested_sleep_mode": "deep_sleep",
    "wifi_failures_since_report": 5,
    "upload_failures_since_report": 1,
    "max_consecutive_wifi_failures": 5,
    "sleep_fallbacks_since_report": 0
  },
  "config_ack": {"applied_version": 3, "status": "applied"},
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

An optional `errors` array may contain at most 20 unique stable codes matching `[A-Z0-9_.-]{1,64}`. Use it only for durable machine-readable conditions such as queue pressure, sensor recovery or repeated UTC synchronization failure. Never include SSIDs, passwords, credential-bearing URLs, device keys, request bodies or personal data. Heartbeat accepts the same optional top-level `errors` array. Omitting it remains fully compatible with V1.

`device_info`, `operational_status`, and `config_ack` are optional additive V1 objects. They expose real hardware/sensor/capacity data, wake/sleep and accumulated connection-health counters, plus the configuration version actually committed by the unit. The backend stores each report and shows the latest state in the protected dashboard. Because failed WLAN or HTTPS attempts cannot be reported while the unit is offline, their counters are sent after recovery and cleared only after that authenticated envelope is accepted. `config_ack` is not a server command and contains no provisioning material.

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
  "measurement_points": [
    {"code": "fridge-1", "interval_seconds": 120}
  ],
  "upload": {"interval_seconds": 21600, "max_batch_size": 500},
  "alarm": {
    "enabled": false,
    "temperature_min_c": null,
    "temperature_max_c": null
  }
}
```

`measurement.interval_seconds` is the default. `measurement_points` contains every active logical point and its effective interval after server-side overrides. Accept measurement intervals only from 30 through 86400 seconds and upload intervals only from 60 through 604800 seconds. A firmware build may support only its provisioned physical points, but it must not silently reinterpret an unknown point as a known sensor.

Apply a config only after complete validation, then atomically and durably store all fields plus its `config_version`. Recompute future deadlines without discarding an already-due sample or upload. Never accept a `max_batch_size` above the compiled protocol maximum of 500. `server_time` may be used to diagnose or correct clock drift using a platform-appropriate secure time strategy.

Dashboard operators can change the inclusive temperature range, enable flag, device default measurement interval, upload interval, and measurement-point overrides. Each save creates a higher configuration version. Battery low/full thresholds belong only to the dashboard display and are never delivered to firmware in Sensor Protocol V1. Displayed alarm states currently create no persistent event and trigger no push or email action.

Successful batch and heartbeat responses contain the same complete object as `configuration`. Prefer this piggyback when its version is newer, validate it independently, and persist it atomically after response identity/ACK processing. If it is absent or invalid, retain the last known-good config and use `GET /api/v1/device/config` on the next eligible connection. Never expect WLAN credentials, device keys, setup passwords, or other provisioning secrets in an operational response; the backend deliberately never returns them.

After durable activation, send the applied version in `config_ack`. The ESP32 reference uses one confirmation heartbeat in the same wake cycle and repeats the acknowledgement on every later batch/heartbeat; a failed confirmation never rolls back the locally valid config. `status: applied` means that exact version controls the scheduler. `status: rejected` means the last known-good configuration remains active and an explicit config check is pending. The backend/dashboard must compare this acknowledgement with the newest stored server version instead of assuming that delivery equals application.

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
  "boot_count": 42,
  "operational_status": {
    "provisioned": true,
    "queue_depth": 0,
    "awake_ms": 2200,
    "wake_reason": "timer",
    "reset_reason": "deep_sleep",
    "requested_sleep_mode": "deep_sleep",
    "wifi_failures_since_report": 5,
    "upload_failures_since_report": 1,
    "max_consecutive_wifi_failures": 5,
    "sleep_fallbacks_since_report": 0
  },
  "config_ack": {"applied_version": 3, "status": "applied"}
}
```

A valid response contains `success: true`, `protocol_version: 1`, `server_time`, `config_version`, and the full current `configuration`. A heartbeat never acknowledges measurement data.

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
    determine which measurement-point deadlines are due
    for each due physical point:
        power and read sensor plus battery
        if values valid:
            sequence[point] += 1 and persist sequence
            persist new pending temperature/humidity/battery measurement
        advance that point deadline from its server-provided effective interval

    determine whether upload/config refresh/retry is due or storage is near capacity
    if no network work is due:
        flush durable state and deep sleep until the earliest due deadline

    connect Wi-Fi
    if connection fails:
        keep all pending data
        schedule backoff
        deep sleep

    synchronize UTC time using the platform's trusted time source
    establish HTTPS and verify CA chain + hostname
    if DNS, time synchronization, TLS, or certificate validation fails:
        keep all pending data
        schedule backoff
        deep sleep

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

    validate the response configuration independently
    if its config version is newer:
        persist complete config atomically and recompute deadlines
    if no valid response configuration was available and config refresh is due:
        GET config and apply the same validation rules

    compact acknowledged storage only after durable ACK state is committed
    disconnect Wi-Fi
    power down sensor/radio peripherals
    flush queue, schedule, counters, config, and retry state
    deep sleep until the earliest due deadline
```

The earliest due deadline is the minimum of all point measurement deadlines, upload deadline, retry deadline, and periodic explicit-config refresh deadline. Account for clock correction and timer limits, use bounded jitter for network retries, and never use a configuration update to erase pending measurements or reset sequences.

The exact next implementation scope and definition of done are in [`FIRMWARE_IMPLEMENTATION_HANDOFF.md`](FIRMWARE_IMPLEMENTATION_HANDOFF.md).

Power loss is permitted at every arrow. After restart the device must either resend a pending record or know from durable state that it received a valid explicit acknowledgement. Resending is safe and expected.

## Compatibility rule

Firmware compiled for Protocol V1 must send `protocol_version: 1` and refuse responses claiming another protocol. Metadata objects may gain fields, which V1 clients should ignore. Required fields, measurement strictness, ACK identity, types, units, and limits remain binding for V1.
