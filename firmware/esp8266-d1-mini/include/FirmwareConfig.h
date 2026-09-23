#pragma once

#include "BuildSecrets.h"

#define OPEN_HACCP_FIRMWARE_VERSION "0.4.0-d1-mini-dht22"
#define OPEN_HACCP_HARDWARE_REVISION "esp8266-d1-mini-dht22-prototype"
#define OPEN_HACCP_BOARD_MODEL "ESP8266 D1 mini ESP8266MOD"
#define OPEN_HACCP_SENSOR_MODEL "DHT22"

#ifndef OPEN_HACCP_SETUP_AP_PASSWORD
#error "Set a unique WPA2 setup password in ignored include/BuildSecrets.h"
#endif
static_assert(sizeof(OPEN_HACCP_SETUP_AP_PASSWORD) - 1 >= 8, "Setup password needs at least 8 characters");
static_assert(sizeof(OPEN_HACCP_SETUP_AP_PASSWORD) - 1 <= 63, "Setup password cannot exceed 63 characters");

#ifndef OPEN_HACCP_DHT_PIN
#define OPEN_HACCP_DHT_PIN 4  // D2 / GPIO4
#endif

#ifndef OPEN_HACCP_FACTORY_RESET_PIN
#define OPEN_HACCP_FACTORY_RESET_PIN 14  // D5 / GPIO14; hold to GND for 5 s during boot
#endif

#ifndef OPEN_HACCP_ENABLE_DEEP_SLEEP
#define OPEN_HACCP_ENABLE_DEEP_SLEEP 0
#endif

#define OPEN_HACCP_WIFI_CONNECT_TIMEOUT_MS 12000UL
#define OPEN_HACCP_WIFI_CONNECT_ATTEMPTS 2
#define OPEN_HACCP_CLOCK_SYNC_TIMEOUT_MS 15000UL
#define OPEN_HACCP_CONFIG_REFRESH_SECONDS 86400UL
#define OPEN_HACCP_MINIMUM_SLEEP_SECONDS 10UL
