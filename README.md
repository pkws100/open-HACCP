# Open HACCP Monitor Backend

Developer prototype for ESP32 temperature and humidity monitoring. The backend defines Sensor Protocol V1 and provides device ingestion, diagnostics, versioned configuration, one-time device onboarding, an operator dashboard, an optional three-device demo fleet, and a buildable ESP32-S3/SHT45 reference firmware. It intentionally has no customer accounts, tenants, persistent alarm events, notifications, or HACCP reports.

## Stack and architecture

- PHP 8.3, Slim 4 and PSR-7
- PDO with MariaDB 10.11 and utf8mb4
- Phinx-only schema migrations
- Opis JSON Schema validation
- Monolog JSON logs on stderr
- PHPUnit unit and MariaDB integration tests
- Apache and MariaDB through Docker Compose

HTTP controllers delegate to protocol services, which use prepared-statement repositories. A physical device owns one or more independent measurement points. Measurement writes and device/transmission updates are transactional.

## Prerequisites

- Docker Engine with Docker Compose v2
- `openssl` for generating local secrets
- Free local TCP port 18082 (configurable with `APP_HTTP_PORT`)

PHP and Composer are not required on the host.

## Initial setup and start

```bash
cp .env.example .env
openssl rand -hex 32
```

Edit `.env` and replace all placeholder secrets. `DEVICE_API_KEY_PEPPER` must contain at least 32 characters; 64 random hexadecimal characters are recommended. Set a separate dashboard password with at least 12 characters.

```bash
docker compose up -d --build
docker compose ps
curl http://localhost:18082/health
```

The app waits for MariaDB and automatically runs all Phinx migrations. Set `MIGRATE_ON_START=false` when migrations should be a separate deployment step. A manual migration command is:

```bash
docker compose exec app vendor/bin/phinx migrate -e production
```

Expected health response:

```json
{"status":"ok","service":"haccp-monitor-backend","database":"ok"}
```

The local developer dashboard is available at [http://localhost:18082/dashboard](http://localhost:18082/dashboard) and uses the `DASHBOARD_USERNAME` and `DASHBOARD_PASSWORD` values from `.env`. The VPS test dashboard is available at [https://haccp.pow24.org/dashboard](https://haccp.pow24.org/dashboard). This is simple operator protection, not customer identity management. The same credentials authorize the settings and device-onboarding APIs.

The dashboard lists active devices only. Battery status uses configurable low/full millivolt thresholds; Wi-Fi quality uses the latest RSSI value. Versioned device settings define the inclusive normal temperature range. The current value and chart are evaluated immediately, but this prototype deliberately does not persist alarm events or send notifications.

## Learn a physical device

Use the plus button next to **Geräte** in the dashboard. Enter the device label, first measurement point, SHT45 type/location, and initial alarm/battery thresholds. The UID is optional; the backend generates one when it is blank. Creation is transactional and returns an HTTPS setup package whose 64-character device key is visible once only. The response is not cacheable, and only a peppered HMAC of the key is stored.

On first boot, the reference ESP32 firmware exposes a WPA2 SoftAP named `OpenHACCP-…`. Join it with the unique setup password printed for that unit and open `http://192.168.4.1`. Enter the site's 2.4-GHz WLAN plus the setup package. The device keeps all candidate values in memory until it has joined the WLAN, synchronized UTC, verified the server certificate and hostname, authenticated to `GET /api/v1/device/config`, and validated the returned config. It then persists the values, reboots, and disables the setup network.

Holding the BOOT/factory-reset pin for five seconds at boot returns the reference unit to onboarding. This deliberately erases credentials, config, pending readings, and sequence state. See [`docs/DEVICE_PROVISIONING.md`](docs/DEVICE_PROVISIONING.md) for the exact lifecycle, security boundary, recovery behavior, and production hardening gates.

The external URL suggested by the backend comes from `PUBLIC_API_BASE_URL` and defaults to `https://haccp.pow24.org`.

## Run the three-device demo fleet

The optional `demo` profile idempotently provisions a refrigerator, freezer, and milk-drink cooler. On first start it submits twelve historical readings for each device, then one reading every five minutes. Keys, counters, and exact pending retry batches live only in the `haccp-demo-state` Docker volume and never appear in Compose configuration or logs.

```bash
docker compose --profile demo up -d --build
docker compose --profile demo ps
docker compose logs demo
```

Use a single cycle for tests:

```bash
docker compose --profile demo run --rm demo php tools/demo_fleet.php --once --url=http://app
```

The reserved UIDs are `haccp-demo-fridge`, `haccp-demo-freezer`, and `haccp-demo-milk-cooler`. Only `haccp-demo-*` credentials may be automatically created or rotated. Provisioning is idempotent and preserves settings edited in the dashboard. The simulator never disables unrelated devices; deactivate obsolete test devices explicitly with `bin/device-disable` when an environment should display exactly the demo fleet.

## Provision a prototype

The dashboard flow above is preferred for a physical unit because it creates the device, its first measurement point, initial config, and one-time setup package together. The CLI remains useful for automation and recovery.

Create a device:

```bash
docker compose exec app php bin/device-create \
  --uid=haccp-p01-0001 \
  --name="Prototype A"
```

Copy the displayed Device Key immediately. It is shown only once and only its protected digest is stored.

Create its initial measurement point:

```bash
docker compose exec app php bin/measurement-point-create \
  --device=haccp-p01-0001 \
  --code=fridge-1 \
  --name="Prototype test fridge" \
  --sensor-type=SHT45 \
  --location="Test kitchen"
```

Other device commands:

```bash
docker compose exec app php bin/device-list
docker compose exec app php bin/device-regenerate-key --uid=haccp-p01-0001
docker compose exec app php bin/device-disable --uid=haccp-p01-0001
```

Key rotation invalidates the old key immediately. Disabled devices receive the same generic 401 response as invalid credentials.

## Run the simulator

The simulator runs inside the app image so no host PHP is needed. Replace the key below:

```bash
docker compose exec app php tools/sensor_simulator.php \
  --device=haccp-p01-0001 \
  --key=PASTE_DEVICE_KEY \
  --url=http://localhost \
  --measurement-point=fridge-1 \
  --count=3 \
  --resend
```

To exercise the same verified TLS route as production firmware, change the URL to `https://haccp.pow24.org`. The simulator uses normal CA and hostname verification and has no insecure TLS mode.

The first response reports three `accepted` acknowledgements. The immediate resend reports three `duplicate` acknowledgements and creates no additional measurement rows.

Send only a heartbeat:

```bash
docker compose exec app php tools/sensor_simulator.php \
  --device=haccp-p01-0001 \
  --key=PASTE_DEVICE_KEY \
  --url=http://localhost \
  --mode=heartbeat
```

For less key exposure in shell history, pass it through an environment variable:

```bash
docker compose exec -e DEVICE_KEY=PASTE_DEVICE_KEY app php tools/sensor_simulator.php \
  --device=haccp-p01-0001 --url=http://localhost --mode=both
```

Simulator sequence, boot, and upload counters are stored in the ignored `.runtime` directory inside the container image.

## API examples

Fetch config:

```bash
curl -sS http://localhost:18082/api/v1/device/config \
  -H 'X-Device-ID: haccp-p01-0001' \
  -H 'X-Device-Key: PASTE_DEVICE_KEY'
```

Send a heartbeat:

```bash
curl -sS -X POST http://localhost:18082/api/v1/device/heartbeat \
  -H 'Content-Type: application/json' \
  -H 'X-Device-ID: haccp-p01-0001' \
  -H 'X-Device-Key: PASTE_DEVICE_KEY' \
  --data '{
    "protocol_version":1,
    "firmware_version":"0.1.0",
    "hardware_revision":"prototype-a",
    "battery_mv":6127,
    "rssi_dbm":-58,
    "wifi_connect_ms":1834,
    "boot_count":42
  }'
```

The complete measurement request and response are documented in [`docs/SENSOR_PROTOCOL_V1.md`](docs/SENSOR_PROTOCOL_V1.md). Firmware implementers should use [`docs/FIRMWARE_CONTRACT.md`](docs/FIRMWARE_CONTRACT.md).

## Inspect stored data and logs

```bash
docker compose exec db sh -lc 'MYSQL_PWD="$(cat /run/secrets/database_password)" mariadb -u"$MARIADB_USER" "$MARIADB_DATABASE" -e "SELECT device_id, measurement_point_id, sequence, measured_at, temperature_c, humidity_rh FROM measurements ORDER BY id;"'

docker compose logs app
```

Logs contain request IDs, endpoints, status, durations and batch result counts. Device keys, request bodies and passwords are never logged. Compose injects database credentials, the device-key pepper, and the dashboard password as runtime secrets, so `docker compose config` contains only secret names rather than values.

## VPS and reverse proxy

The default binding is `127.0.0.1:18082`, avoiding collisions with public ports and preventing accidental direct Internet exposure. For the existing VPS Nginx Proxy Manager network, start with the override:

```bash
docker compose -f docker-compose.yml -f docker-compose.vps.yml up -d --build
```

The override attaches only the app container to the external Docker network named `proxy` under the stable alias `haccp-monitor`. The deployed Nginx Proxy Manager host `haccp.pow24.org` forwards to `haccp-monitor:80`, forces HTTPS, enables HTTP/2 and HSTS, and uses a publicly trusted Let's Encrypt certificate. The host port remains bound to loopback. Under the VPS override, the demo container deliberately sends to `https://haccp.pow24.org`, exercising the same DNS, certificate, reverse-proxy, and HTTPS path as firmware.

The external device base URL is:

```text
https://haccp.pow24.org
```

Sensors must establish Wi-Fi, synchronize UTC time, establish a certificate-verified HTTPS connection, and only then exchange Sensor Protocol V1 HTTP/JSON requests. Plain HTTP is limited to the Docker-internal proxy hop and local development; firmware must never use it in deployment.

## Run tests

The test profile starts a disposable MariaDB instance, migrates it from empty, and runs unit plus integration tests:

```bash
docker compose --profile test build
docker compose --profile test run --rm tests
docker compose --profile test down
```

The suite verifies health, authentication, ingestion, retries, partial rejection, unknown measurement points, ranges, config, heartbeat state, secret-free logs, sequence conflicts and gaps, migration tables, key rotation, disabled devices, request-size limits, transactional device onboarding and one-time key hashing, settings validation/version conflicts, status boundaries, and demo-state behavior.

## Build the ESP32-S3 reference firmware

Install PlatformIO Core, then create the ignored build-secret header:

```bash
cp firmware/esp32-s3/include/BuildSecrets.example.h \
  firmware/esp32-s3/include/BuildSecrets.h
```

Replace the example setup password with an 8–63 character value unique to that physical unit or controlled manufacturing batch. Never commit `BuildSecrets.h`.

```bash
cd firmware/esp32-s3
pio run
pio run --target upload
pio device monitor --baud 115200
```

The checked-in profile targets an ESP32-S3-DevKitC-1 with SHT45 on I²C SDA 8/SCL 9. Override the `OPEN_HACCP_*` build macros for the actual board and calibrated battery divider. The firmware is an awake bench prototype with a durable 64-record NVS queue; deep sleep, final PCB pinout, manufacturing keys, encrypted NVS/Flash Encryption, Secure Boot, OTA, and physical hardware qualification remain productization work.

## Stop and reset

```bash
docker compose down
```

Database data remains in the named volume. To intentionally delete all local database data:

```bash
docker compose down -v
```

## Protocol artifacts

- [`docs/protocol-v1.schema.json`](docs/protocol-v1.schema.json): normative batch JSON Schema
- [`docs/openapi.yaml`](docs/openapi.yaml): OpenAPI 3.1 API definition
- [`docs/SENSOR_PROTOCOL_V1.md`](docs/SENSOR_PROTOCOL_V1.md): backend protocol behavior
- [`docs/FIRMWARE_CONTRACT.md`](docs/FIRMWARE_CONTRACT.md): standalone firmware handoff
- [`docs/DEVICE_PROVISIONING.md`](docs/DEVICE_PROVISIONING.md): local setup portal, verification, persistence and recovery
- [`firmware/esp32-s3`](firmware/esp32-s3): buildable ESP32-S3/SHT45 onboarding reference

## Prototype limitations

There is no customer user or tenant model, rate limiter, persistent alarm-event model, alert delivery, export, calibration workflow, firmware registry, OTA channel, or cloud-provider dependency. Device onboarding exists, but production manufacturing secrets, encrypted-at-rest device storage, physical reset protection, captive-portal qualification across phone platforms, and hardware certification are not complete. The operator dashboard is protected by environment-configured HTTP Basic credentials and can create devices plus new versioned temperature/battery settings. Device configuration remains read-only from the firmware perspective through Sensor Protocol V1. TLS is terminated by Nginx Proxy Manager in deployment, while firmware and the VPS demo use HTTPS exclusively.
