#pragma once

#include <cstddef>

namespace QueuePressure {
constexpr size_t Headroom = 4;

// The server may request a batch larger than the device's durable queue.
// Start uploading before the last four slots are needed for new samples.
constexpr size_t threshold(size_t maxBatchSize, size_t queueCapacity)
{
    return maxBatchSize < queueCapacity - Headroom
        ? maxBatchSize : queueCapacity - Headroom;
}

constexpr bool reached(size_t pendingCount, size_t maxBatchSize, size_t queueCapacity)
{
    return pendingCount >= threshold(maxBatchSize, queueCapacity);
}
}
