# Open HACCP Monitor Backend

Developer prototype for ESP32 temperature and humidity monitoring. The backend defines Sensor Protocol V1 and currently provides device ingestion, diagnostics, configuration, CLI provisioning, and a sensor simulator. It intentionally has no customer UI, user accounts, tenants, alarms, or HACCP reports.

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

The local developer dashboard is available at [http://localhost:18082/dashboard](http://localhost:18082/dashboard) and uses the `DASHBOARD_USERNAME` and `DASHBOARD_PASSWORD` values from `.env`. The VPS test dashboard is available at [https://haccp.pow24.org/dashboard](https://haccp.pow24.org/dashboard). This is simple operator protection, not customer identity management.

## Provision a prototype

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
docker compose exec db sh -lc 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT device_id, measurement_point_id, sequence, measured_at, temperature_c, humidity_rh FROM measurements ORDER BY id;"'

docker compose logs app
```

Logs contain request IDs, endpoints, status, durations and batch result counts. Device keys, request bodies and database passwords are never logged.

## VPS and reverse proxy

The default binding is `127.0.0.1:18082`, avoiding collisions with public ports and preventing accidental direct Internet exposure. For the existing VPS Nginx Proxy Manager network, start with the override:

```bash
docker compose -f docker-compose.yml -f docker-compose.vps.yml up -d --build
```

The override attaches only the app container to the external Docker network named `proxy` under the stable alias `haccp-monitor`. The deployed Nginx Proxy Manager host `haccp.pow24.org` forwards to `haccp-monitor:80`, forces HTTPS, enables HTTP/2 and HSTS, and uses a publicly trusted Let's Encrypt certificate. The host port remains bound to loopback.

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

The integration suite verifies health, authentication, ingestion, retries, partial rejection, unknown measurement points, ranges, config, heartbeat state, secret-free logs, sequence conflicts and gaps, migration tables, key rotation, disabled devices, and the request-size limit.

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

## Prototype limitations

There is no customer user or tenant model, rate limiter, alert delivery, export, calibration workflow, Wi-Fi provisioning, firmware registry, OTA channel, or cloud-provider dependency. The included dashboard is a read-only developer monitor protected by environment-configured HTTP Basic credentials. Device configuration is read-only over Sensor Protocol V1 and currently created with the device through CLI. TLS is terminated by Nginx Proxy Manager in deployment, while firmware uses HTTPS exclusively.
