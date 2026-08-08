#pragma once

#include "BuildSecrets.h"

#define OPEN_HACCP_FIRMWARE_VERSION "0.2.0-provisioning"
#define OPEN_HACCP_HARDWARE_REVISION "esp32-s3-sht45-prototype"

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
