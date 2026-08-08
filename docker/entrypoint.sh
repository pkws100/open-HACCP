#!/bin/sh
set -eu

if [ -z "${DB_PASSWORD:-}" ] && [ -n "${DB_PASSWORD_FILE:-}" ]; then
    DB_PASSWORD=$(tr -d '\r\n' < "$DB_PASSWORD_FILE")
    export DB_PASSWORD
fi
if [ -z "${DEVICE_API_KEY_PEPPER:-}" ] && [ -n "${DEVICE_API_KEY_PEPPER_FILE:-}" ]; then
    DEVICE_API_KEY_PEPPER=$(tr -d '\r\n' < "$DEVICE_API_KEY_PEPPER_FILE")
    export DEVICE_API_KEY_PEPPER
fi
if [ -z "${AUDIT_LOG_KEY:-}" ] && [ -n "${AUDIT_LOG_KEY_FILE:-}" ]; then
    AUDIT_LOG_KEY=$(tr -d '\r\n' < "$AUDIT_LOG_KEY_FILE")
    export AUDIT_LOG_KEY
fi
if [ -z "${DASHBOARD_PASSWORD:-}" ] && [ -n "${DASHBOARD_PASSWORD_FILE:-}" ]; then
    DASHBOARD_PASSWORD=$(tr -d '\r\n' < "$DASHBOARD_PASSWORD_FILE")
    export DASHBOARD_PASSWORD
fi

if [ "${MIGRATE_ON_START:-true}" = "true" ]; then
    attempts=0
    until php -r '
        $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE"));
        new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 2]);
    ' >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "Database did not become ready in time." >&2
            exit 1
        fi
        sleep 2
    done

    vendor/bin/phinx migrate -e production
fi

exec "$@"
