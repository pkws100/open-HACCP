#pragma once

#include <cmath>

inline bool validSensorReading(float temperatureC, float humidityRh)
{
    return std::isfinite(temperatureC) && std::isfinite(humidityRh)
        && temperatureC >= -100.0F && temperatureC <= 150.0F
        && humidityRh >= 0.0F && humidityRh <= 100.0F;
}
