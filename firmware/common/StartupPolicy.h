#pragma once

#include <stdint.h>

namespace StartupPolicy {
// A deliberate timer wake follows the configured cadence. A real power-on,
// manual reset, or provisioning restart reports promptly once it has UTC.
constexpr bool bootContactDue(bool deepSleepWake, bool plannedFallbackRestart)
{
    return !deepSleepWake && !plannedFallbackRestart;
}

constexpr bool sampleDue(bool trustworthyUtc, bool startupContact, int64_t lastSampleAt,
    uint32_t intervalSeconds, int64_t now)
{
    return trustworthyUtc && (startupContact || lastSampleAt <= 0
        || now >= lastSampleAt + intervalSeconds);
}

constexpr bool contactDue(bool startupContact, bool clockNeeded, bool uploadDue,
    bool configDue, bool retryDue, bool queuePressure, bool backoffPending)
{
    return !backoffPending && (startupContact || clockNeeded || uploadDue
        || configDue || retryDue || queuePressure);
}
}
