#pragma once

#include "BuildSecrets.h"

#define OPEN_HACCP_FIRMWARE_VERSION "0.3.0-power-managed"
#define OPEN_HACCP_HARDWARE_REVISION "esp32-s3-sht45-prototype"

#ifndef OPEN_HACCP_BOARD_MODEL
#define OPEN_HACCP_BOARD_MODEL "ESP32-S3-DevKitC-1"
#endif

#ifndef OPEN_HACCP_SENSOR_MODEL
#define OPEN_HACCP_SENSOR_MODEL "SHT45"
#endif

#ifndef OPEN_HACCP_SETUP_AP_PASSWORD
#error "OPEN_HACCP_SETUP_AP_PASSWORD must be defined in include/BuildSecrets.h"
#endif
static_assert(sizeof(OPEN_HACCP_SETUP_AP_PASSWORD) - 1 >= 8, "Setup AP password must have at least 8 characters");
static_assert(sizeof(OPEN_HACCP_SETUP_AP_PASSWORD) - 1 <= 63, "Setup AP password must have at most 63 characters");

#ifndef OPEN_HACCP_FACTORY_RESET_PIN
#define OPEN_HACCP_FACTORY_RESET_PIN 0
#endif

#ifndef OPEN_HACCP_I2C_SDA
#define OPEN_HACCP_I2C_SDA 8
#endif

#ifndef OPEN_HACCP_I2C_SCL
#define OPEN_HACCP_I2C_SCL 9
#endif

#ifndef OPEN_HACCP_BATTERY_ADC_PIN
#define OPEN_HACCP_BATTERY_ADC_PIN -1
#endif

#ifndef OPEN_HACCP_BATTERY_DIVIDER
#define OPEN_HACCP_BATTERY_DIVIDER 2.0F
#endif

#ifndef OPEN_HACCP_BATTERY_FALLBACK_MV
#define OPEN_HACCP_BATTERY_FALLBACK_MV 6000
#endif

#ifndef OPEN_HACCP_WIFI_CONNECT_TIMEOUT_MS
#define OPEN_HACCP_WIFI_CONNECT_TIMEOUT_MS 12000UL
#endif

#ifndef OPEN_HACCP_WIFI_CONNECT_ATTEMPTS
#define OPEN_HACCP_WIFI_CONNECT_ATTEMPTS 2
#endif

#ifndef OPEN_HACCP_CLOCK_SYNC_TIMEOUT_MS
#define OPEN_HACCP_CLOCK_SYNC_TIMEOUT_MS 15000UL
#endif

#ifndef OPEN_HACCP_CONFIG_REFRESH_SECONDS
#define OPEN_HACCP_CONFIG_REFRESH_SECONDS 86400UL
#endif

#ifndef OPEN_HACCP_MINIMUM_SLEEP_SECONDS
#define OPEN_HACCP_MINIMUM_SLEEP_SECONDS 10UL
#endif

// Set to 1 only for a hardware bench test of the light-sleep/restart fallback.
#ifndef OPEN_HACCP_DISABLE_DEEP_SLEEP
#define OPEN_HACCP_DISABLE_DEEP_SLEEP 0
#endif
