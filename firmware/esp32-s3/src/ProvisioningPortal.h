#pragma once

#include <DNSServer.h>
#include <WebServer.h>

#include "DeviceState.h"
#include "HaccpClient.h"

class ProvisioningPortal {
public:
    ProvisioningPortal(DeviceState &state, HaccpClient &client);
    void begin();
    void loop();

private:
    DeviceState &state_;
    HaccpClient &client_;
    DNSServer dns_;
    WebServer server_{80};
    String accessPointName_;
    uint32_t restartAt_ = 0;

    void handleRoot();
    void handleScan();
    void handleConfigure();
    void redirectToPortal();
    void sendError(const String &message);
    bool validateCandidate(ProvisioningConfig &candidate, String &error);
    bool connectStation(const ProvisioningConfig &candidate, uint32_t &connectMs, String &error);
    bool synchronizeClock(String &error);
    static bool slug(const String &value, size_t minimum, size_t maximum);
    static bool hexKey(const String &value);
    static String escapeHtml(const String &value);
};
