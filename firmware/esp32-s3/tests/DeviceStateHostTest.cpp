#include <cassert>
#include <cmath>
#include <cstdint>
#include <cstring>
#include <iostream>

#include "Preferences.h"
#include "AckCorrelation.h"
#include "DeviceState.h"
#include "SensorValidation.h"

int main()
{
    assert(validSensorReading(4.2F, 78.0F));
    assert(!validSensorReading(NAN, 78.0F));
    assert(!validSensorReading(4.2F, NAN));
    assert(!validSensorReading(INFINITY, 78.0F));
    assert(!validSensorReading(4.2F, 101.0F));
    assert(!validSensorReading(-101.0F, 78.0F));

    PendingMeasurement sent[3]{};
    sent[0].sequence = 11;
    sent[1].sequence = 12;
    sent[2].sequence = 13;
    bool seen[3]{};
    bool rejected[3]{};
    rejected[1] = true;
    assert(!correlateAcknowledgement(3, 11, "fridge-1", "accepted", sent, 3, "fridge-1", seen, rejected));
    assert(!correlateAcknowledgement(0, 99, "fridge-1", "accepted", sent, 3, "fridge-1", seen, rejected));
    assert(!correlateAcknowledgement(0, 11, "wrong-point", "accepted", sent, 3, "fridge-1", seen, rejected));
    assert(!correlateAcknowledgement(0, 11, "fridge-1", "rejected", sent, 3, "fridge-1", seen, rejected));
    assert(!correlateAcknowledgement(1, 12, "fridge-1", "accepted", sent, 3, "fridge-1", seen, rejected));
    assert(correlateAcknowledgement(0, 11, "fridge-1", "accepted", sent, 3, "fridge-1", seen, rejected));
    assert(!correlateAcknowledgement(0, 11, "fridge-1", "accepted", sent, 3, "fridge-1", seen, rejected));
    assert(correlateAcknowledgement(2, 13, "fridge-1", "duplicate", sent, 3, "fridge-1", seen, rejected));
    assert(seen[0] && !seen[1] && seen[2]); // Partial ACK leaves sequence 12 pending.

    Preferences::reset();
    DeviceState firstBoot;
    for (uint64_t sequence = 1; sequence <= DeviceState::QueueCapacity; ++sequence) {
        assert(firstBoot.enqueue(1704067200 + sequence, 4.2F, 78.0F, UINT16_MAX));
        assert(firstBoot.pendingItems()[sequence - 1].sequence == sequence);
    }
    assert(!firstBoot.enqueue(1704068000, 5.0F, 80.0F, UINT16_MAX));
    assert(firstBoot.pendingCount() == DeviceState::QueueCapacity);
    assert(firstBoot.pendingItems()[0].sequence == 1);

    // A new object represents a hard restart before the server has ACKed.
    DeviceState restarted;
    assert(restarted.pendingCount() == DeviceState::QueueCapacity);
    assert(restarted.pendingItems()[0].measuredAt == 1704067201);
    assert(restarted.pendingItems()[DeviceState::QueueCapacity - 1].sequence == DeviceState::QueueCapacity);

    const uint64_t partialAck[] = {2, 4};
    Preferences::failNextQueueWrite();
    assert(!restarted.acknowledge(partialAck, 2));
    assert(restarted.pendingCount() == DeviceState::QueueCapacity);
    assert(restarted.acknowledge(partialAck, 2));
    assert(restarted.pendingCount() == DeviceState::QueueCapacity - 2);
    assert(restarted.pendingItems()[0].sequence == 1);
    assert(restarted.pendingItems()[1].sequence == 3);
    assert(restarted.acknowledge(partialAck, 2)); // Duplicate ACK cannot remove more.
    assert(restarted.pendingCount() == DeviceState::QueueCapacity - 2);

    DeviceState nextBoot;
    assert(nextBoot.pendingCount() == DeviceState::QueueCapacity - 2);
    assert(nextBoot.enqueue(1704069000, 6.0F, 81.0F, UINT16_MAX));
    assert(nextBoot.pendingItems()[nextBoot.pendingCount() - 1].sequence == DeviceState::QueueCapacity + 1);
    Preferences::failNextQueueWrite();
    assert(!nextBoot.enqueue(1704069001, 6.0F, 81.0F, UINT16_MAX));
    assert(nextBoot.pendingCount() == DeviceState::QueueCapacity - 1);
    assert(nextBoot.enqueue(1704069002, 6.0F, 81.0F, UINT16_MAX));
    assert(nextBoot.pendingItems()[nextBoot.pendingCount() - 1].sequence == DeviceState::QueueCapacity + 2);

    ProvisioningConfig replacement;
    replacement.wifiSsid = "test-wifi";
    replacement.wifiPassword = "test-password";
    replacement.apiBaseUrl = "https://example.org";
    replacement.deviceUid = "new-device";
    replacement.deviceKey = String(std::string(64, 'a'));
    replacement.deviceLabel = "Replacement";
    replacement.measurementPoint = "fridge-1";
    assert(!nextBoot.saveProvisioning(replacement));

    // A provisioned device must not recreate an absent queue at sequence 1.
    Preferences::reset();
    Preferences ready;
    assert(ready.begin("haccp-prov", false));
    assert(ready.putBool("ready", true));
    ready.end();
    DeviceState missingQueue;
    assert(!missingQueue.queueHealthy());
    assert(!missingQueue.enqueue(1704069003, 7.0F, 82.0F, UINT16_MAX));
    const uint64_t missingAck[] = {1};
    assert(!missingQueue.acknowledge(missingAck, 1));
    const char *storageCodes[10]{};
    bool foundStorage = false;
    for (size_t i = 0; i < missingQueue.diagnosticCodes(storageCodes, 10); ++i) {
        foundStorage |= std::strcmp(storageCodes[i], "STORAGE_FAILED") == 0;
    }
    assert(foundStorage);
    missingQueue.markTelemetryDelivered();
    foundStorage = false;
    for (size_t i = 0; i < missingQueue.diagnosticCodes(storageCodes, 10); ++i) {
        foundStorage |= std::strcmp(storageCodes[i], "STORAGE_FAILED") == 0;
    }
    assert(foundStorage);
    missingQueue.factoryReset();
    assert(missingQueue.queueHealthy());

    // A malformed queue blob must also remain intact and block new records.
    Preferences::reset();
    Preferences malformed;
    assert(malformed.begin("haccp-queue", false));
    const uint8_t damaged[] = {0xde, 0xad, 0xbe, 0xef};
    assert(malformed.putBytes("queue", damaged, sizeof(damaged)) == sizeof(damaged));
    malformed.end();
    DeviceState damagedQueue;
    assert(!damagedQueue.queueHealthy());
    assert(!damagedQueue.enqueue(1704069004, 8.0F, 83.0F, UINT16_MAX));
    assert(malformed.begin("haccp-queue", true));
    assert(malformed.getBytesLength("queue") == sizeof(damaged));
    malformed.end();

    Preferences::reset();

    // Three DHT failures, even across reboots, create the repeated-read code.
    DeviceState failureOne;
    failureOne.recordDhtReadFailure();
    DeviceState failureTwo;
    failureTwo.recordDhtReadFailure();
    DeviceState failureThree;
    failureThree.recordDhtReadFailure();
    const char *codes[8]{};
    const size_t codeCount = failureThree.diagnosticCodes(codes, 8);
    bool foundRepeated = false;
    for (size_t i = 0; i < codeCount; ++i) {
        foundRepeated |= std::strcmp(codes[i], "DHT_READ_FAILED_REPEATED") == 0;
    }
    assert(foundRepeated);
    failureThree.recordDhtReadSuccess();
    failureThree.markTelemetryDelivered();
    failureThree.recordDhtReadFailure();
    const char *laterCodes[8]{};
    const size_t laterCount = failureThree.diagnosticCodes(laterCodes, 8);
    for (size_t i = 0; i < laterCount; ++i) {
        assert(std::strcmp(laterCodes[i], "DHT_READ_FAILED_REPEATED") != 0);
    }

    std::cout << "DeviceState host tests passed\n";
}
