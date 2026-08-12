# ESP32-S3 firmware implementation handoff

This document is now the implementation and hardware-release handoff for `firmware/esp32-s3`. Firmware `0.3.0-power-managed` implements the first bounded Wake–Measure–Persist–Transmit–Sleep cycle without weakening the provisioning, TLS, idempotency or exact-acknowledgement rules in [`FIRMWARE_CONTRACT.md`](FIRMWARE_CONTRACT.md). It compiles both the normal Deep-Sleep profile and a forced Light-Sleep fallback profile.

Implemented in software:

- persisted sample, successful-transmission, config-check and retry deadlines;
- timer Deep Sleep with Light-Sleep and bounded restart fallback;
- two bounded WLAN attempts per wake plus persisted 1/5/15/30/60-minute jittered backoff;
- optional board/chip/sensor/capacity, queue, wake/reset and accumulated failure telemetry;
- exact batch ACK deletion followed by independent piggyback-config validation;
- explicit config GET fallback, durable `config_ack`, and a same-wake confirmation heartbeat for the version actually activated;
- dashboard controls for the default/per-provisioned-point sampling interval and one, three, five or other supported transmissions per day.

Not yet evidenced as a hardware production release: measured current and wake duration, calibrated battery divider/SHT45, destructive power-loss recovery, larger queue/offline-horizon sizing, encrypted/atomic storage, Secure Boot/Flash Encryption, OTA, final PCB/enclosure/reset design and manufacturing secret injection.

## Target and non-goals

Target the checked-in ESP32-S3-DevKitC-1/SHT45 profile first, while keeping pins, battery-divider calibration, point code, firmware version, and hardware revision compile-time configurable. The deliverable is firmware, native unit tests where practical, a PlatformIO build, and a bench-test procedure. OTA, a final PCB, enclosure certification, alarm delivery, and manufacturing PKI remain separate release gates.

Do not change Sensor Protocol V1 merely to simplify the device. The normative artifacts are:

- [`protocol-v1.schema.json`](protocol-v1.schema.json) for measurement and heartbeat requests;
- [`openapi.yaml`](openapi.yaml) for responses and operator scheduling;
- [`FIRMWARE_CONTRACT.md`](FIRMWARE_CONTRACT.md) for device behavior;
- [`DEVICE_PROVISIONING.md`](DEVICE_PROVISIONING.md) for SoftAP onboarding and recovery.

## Required wake-cycle order

```text
RTC/cold wake
  -> restore and validate provisioning + runtime state
  -> identify every due physical measurement point
  -> power sensor, measure temperature/humidity/battery
  -> allocate the point's next monotonic sequence
  -> atomically persist the complete pending record
  -> decide whether upload, retry, config refresh, or capacity pressure is due
     -> no: persist next deadlines, power down, deep sleep
     -> yes: connect WLAN, synchronize/validate UTC, establish verified HTTPS
          -> pending data: upload oldest bounded batch
          -> no pending data: heartbeat
          -> correlate and durably commit explicit ACKs only
          -> independently validate/apply piggyback configuration
          -> explicit GET config only as fallback/periodic verification
          -> persist state, disconnect/power down, deep sleep
```

Sampling and persistence happen before a normal network attempt. A cold boot that cannot reconstruct trustworthy UTC is the exception: synchronize time before assigning `measured_at`, or retain a separately marked local sample until a defensible UTC timestamp can be derived. Never fabricate a timestamp or replace measurement time with upload time.

## Durable state model

The reference uses versioned NVS records separated by provisioning, runtime config, operational scheduler/counters and queue namespaces. A production storage revision must add CRC plus an atomic A/B or journaled commit strategy and prove by destructive testing that a reset at any individual write recovers either the previous complete state or the next complete state, never a mixture.

Persist these domains separately so runtime configuration cannot overwrite provisioning credentials:

| Domain | Minimum durable content |
|---|---|
| Provisioning | ready flag, WLAN SSID/password, HTTPS base URL, device UID/key, label, supported point codes |
| Runtime config | schema version, server `config_version`, default and per-point measurement intervals, upload interval, batch cap, alarm fields |
| Scheduler | next due UTC/RTC deadline per point, upload deadline, config-refresh deadline, retry deadline/backoff attempt |
| Identity | boot counter, upload counter, next sequence per measurement point |
| Queue | point, sequence, measured UTC, temperature, humidity, measurement battery, pending/ACK state |
| Time anchor | last trusted server/NTP UTC, corresponding monotonic/RTC value, uncertainty/valid flag |

Normal reset and deep sleep preserve every domain. The five-second physical factory reset is the only flow allowed to erase provisioning, sequence, and pending data, and the portal must clearly call it destructive.

The queue is append-only until ACK state is durably committed. Never overwrite the oldest pending record. When capacity is low, force an upload attempt earlier than the normal upload deadline. If still full, surface a durable diagnostic and skip the new sample rather than corrupting or silently replacing old data. Size the product queue from the promised offline horizon and fastest allowed point interval; the existing 64-record reference queue is only a bench implementation.

## Scheduling and Deep Sleep

The server supplies a device default measurement interval plus an effective interval for every active measurement point. Track independent point deadlines. The next timer wake is the earliest due deadline among:

- all supported measurement points;
- normal upload;
- network retry/backoff;
- periodic explicit-config verification.

When a new config is atomically accepted, recompute deadlines without dropping a currently due action. A shorter interval may make a point immediately due; a longer interval must not duplicate a sample. Use absolute deadlines where trustworthy time exists so processing duration does not accumulate drift. Add bounded random jitter only to network retries, never to required sampling timestamps.

Before `esp_deep_sleep_start()`, stop HTTP/TLS, disconnect and power down Wi-Fi, release I²C/sensor power if hardware permits, commit queue/config/scheduler state, configure the RTC timer and approved wake pins, and log no secrets. On wake, distinguish timer, reset button, and unexpected reset causes. Boot count increases on every full wake cycle as defined by the firmware and remains within the protocol's uint32 range.

## Telemetry mapping

Each pending measurement contains and uploads:

- SHT45 temperature as `temperature_c`;
- SHT45 relative humidity as `humidity_rh`;
- calibrated battery voltage at sampling as `battery_mv`;
- the logical measurement point and its independent sequence;
- the original UTC `measured_at`.

Connection diagnostics contain the latest battery voltage, Wi-Fi `rssi_dbm`, connection duration, and boot count. RSSI belongs to the batch/heartbeat diagnostics because it only exists while WLAN is active; the backend stores it with the transmission and exposes it on the dashboard. Firmware/hardware versions are sent on every connection. Numeric range checks happen before queue insertion and again before serialization. Firmware persists stable, secret-free diagnostic flags and sends them as batch `diagnostics.errors` or heartbeat `errors`; successful delivery clears only codes included in that accepted request.

The optional `device_info` block reports actual `ESP` runtime values for board/chip revision, cores, flash, PSRAM and free heap together with the SHT45 status, queue capacity and capabilities. `operational_status` reports provisioning, queue occupancy, awake time, wake/reset reason, requested sleep mode and failures since the previous successful report. `config_ack` distinguishes the server's latest version from the version durably applied by the device. These blocks never contain MAC, SSID, credentials or free-form failure text.

## Configuration uptake on upload

Every successful measurement and heartbeat response includes `configuration`, identical in shape to `GET /api/v1/device/config`. Process in this order:

1. validate HTTP status, JSON, protocol version, and response identity;
2. for a batch, correlate each ACK by request index + point + sequence and commit only `accepted`/`duplicate` records;
3. validate the entire `configuration` independently, including version, interval ranges, active point entries, batch cap, and alarm range;
4. if its version is newer, atomically persist and activate it;
5. if absent, malformed, unsupported, or not newer, retain the last known-good config; use explicit GET config at the next allowed opportunity.

Configuration is operational and non-secret. It can change cadence and alarms, but it must never change WLAN credentials, API host, device UID/key, setup password, sequence history, or pending measurements. Re-provisioning those values requires the controlled local setup/factory-reset flow, not a telemetry response.

## Retry and ACK invariants

- Retain the exact sent item list until the response is fully processed.
- HTTP 200 alone acknowledges nothing.
- `accepted` and identical `duplicate` are the only deletion authorities.
- A missing/ambiguous ACK remains pending.
- `SEQUENCE_CONFLICT` remains quarantined for diagnosis and is never rewritten.
- `UNKNOWN_MEASUREMENT_POINT` remains pending/quarantined until server/operator correction.
- Reuse an exact pending record on retry; never reuse its sequence for changed data.
- Back off 1, 5, 15, 30, then 60 minutes with jitter for network/5xx/429 failures and persist the retry state across Deep Sleep.
- Treat 401 as a slow credential/provisioning fault, not as permission to erase data or reopen an unprotected setup AP.

## Security gates

- WPA2 SoftAP setup password is unique per unit or controlled batch and never committed/logged.
- Normal transport is HTTPS only with hostname and CA-chain validation, TLS 1.2+, maintained trust anchors, and trusted time.
- No insecure TLS fallback, leaf-certificate pin as the sole trust strategy, credential in URL, or secret serial output.
- Production release requires Secure Boot, Flash Encryption, encrypted NVS, protected factory-reset behavior, and a documented trust-anchor/OTA rotation process.

## Remaining production-hardware tests

1. Repeat clean and incremental PlatformIO builds in CI with no checked-in secret header; both normal and forced-fallback profiles currently compile locally.
2. First boot SoftAP, captive portal, invalid input, failed WLAN/TLS/auth verification, successful verify-before-save, reboot, and destructive reset.
3. Timer wake samples temperature/humidity/battery before network, persists it, and returns to Deep Sleep.
4. If the final PCB supports multiple physical sensors, add independent point schedulers/sequences and verify that two points with different intervals wake on the earliest due deadline. The current SHT45 profile supports its one provisioned point and rejects a config that removes it.
5. Power loss at each queue/config/ACK commit boundary recovers without sequence reuse or record loss.
6. Offline sampling, queue-pressure upload, retry persistence, and full-queue no-overwrite behavior.
7. Accepted, duplicate, partial rejection, missing ACK, conflict, invalid response, 401, 413, 429, 5xx, DNS, TLS, and timeout handling.
8. Piggyback config applies a newer interval after ACK processing; malformed/older config leaves the prior config intact; explicit GET fallback succeeds.
9. Temperature, humidity, battery, RSSI, Wi-Fi duration, boot count, firmware version, and hardware revision arrive at the backend with correct units.
10. Current public `https://haccp.pow24.org` certificate/hostname validation and a negative wrong-host/untrusted-CA test.
11. Measured current and wake duration for sleep, sample-only, successful upload, and failed-connect cycles; record the hardware/test conditions.
12. Secret scan of source, firmware binary strings, serial logs, HTTP logs, and captured error output.

## Definition of done

- The device can remain offline across repeated Deep Sleep cycles without losing or mutating queued measurements.
- Backend-controlled default/per-point sample intervals and upload cadence are applied atomically from either piggyback or explicit config.
- Temperature, humidity, measurement battery, connection battery/RSSI, and diagnostics are visible in the existing backend/dashboard path.
- Stable firmware diagnostic codes can become backend deviation events without a protocol-version change.
- Exact ACK correlation and sequence idempotency tests pass against a disposable local MariaDB stack and the VPS test deployment.
- Deep Sleep timing and power measurements are documented for the selected board, sensor wiring, and calibrated battery divider.
- Documentation clearly separates software-implemented behavior from hardware-measured and production-certified evidence.

Firmware handoff ready: **YES**. The first power-managed software implementation, backend and machine-readable contract agree. Hardware release ready remains **NO** until the remaining bench, calibration, power-loss, storage-encryption and security gates above have recorded evidence.
