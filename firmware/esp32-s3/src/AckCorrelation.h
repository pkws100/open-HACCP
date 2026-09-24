#pragma once

#include <cstddef>
#include <cstdint>
#include <cstring>

#include "DeviceState.h"

inline bool correlateAcknowledgement(
    size_t index,
    uint64_t sequence,
    const char *point,
    const char *status,
    const PendingMeasurement *items,
    size_t itemCount,
    const char *expectedPoint,
    bool *seen,
    const bool *rejected
)
{
    if (index >= itemCount || seen[index] || rejected[index] || sequence != items[index].sequence
        || std::strcmp(point, expectedPoint) != 0
        || (std::strcmp(status, "accepted") != 0 && std::strcmp(status, "duplicate") != 0)) {
        return false;
    }
    seen[index] = true;
    return true;
}
