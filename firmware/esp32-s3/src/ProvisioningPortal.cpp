#include "ProvisioningPortal.h"

#include <ArduinoJson.h>
#include <WiFi.h>
#include <esp_system.h>
#include <time.h>

#include "FirmwareConfig.h"

namespace {
constexpr char PortalHtml[] = R"HTML(<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark"><title>Open HACCP · Geräteeinrichtung</title>
<style>
:root{font-family:Inter,system-ui,sans-serif;color:#eef5f2;background:#091013;--a:#61e1cf;--m:#91a39d;--l:rgba(217,235,229,.16)}
*{box-sizing:border-box}body{margin:0;min-width:300px;background:radial-gradient(circle at 70% -10%,rgba(97,225,207,.10),transparent 38%),#091013}
main{width:min(620px,100%);margin:auto;padding:38px 22px 50px}.brand{display:flex;align-items:center;gap:12px;margin-bottom:48px;font-size:11px;letter-spacing:.16em}.mark{display:grid;width:35px;height:35px;place-items:center;border:1px solid var(--l);border-radius:50%;color:var(--a)}
.eyebrow{margin:0 0 9px;color:var(--a);font:10px ui-monospace,monospace;letter-spacing:.13em;text-transform:uppercase}h1{margin:0;font-size:clamp(30px,8vw,48px);font-weight:520;letter-spacing:-.045em}header>p:last-child{max-width:510px;margin:14px 0 0;color:var(--m);font-size:13px;line-height:1.65}
form{margin-top:36px;border-top:1px solid var(--l)}section{padding:27px 0;border-bottom:1px solid var(--l)}h2{margin:0 0 7px;font-size:14px;font-weight:560}section>p{margin:0 0 18px;color:#65756f;font-size:11px;line-height:1.5}.grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}.full{grid-column:1/-1}label{display:grid;gap:8px;color:var(--m);font-size:10px}input{width:100%;padding:11px 12px;color:#eef5f2;border:1px solid var(--l);border-radius:6px;background:#10181b;font:13px ui-monospace,monospace}input:focus{border-color:var(--a);outline:2px solid rgba(97,225,207,.12)}button{min-height:42px;padding:10px 15px;border:1px solid var(--a);border-radius:6px;background:var(--a);color:#07110f;font-weight:650;cursor:pointer}.actions{display:flex;align-items:center;justify-content:space-between;gap:20px;padding-top:28px}.actions small{color:#65756f;line-height:1.5}.message{margin:24px 0 0;padding:12px;color:#e3a284;border:1px solid rgba(227,162,132,.3);border-radius:6px;background:rgba(227,162,132,.07);font-size:12px}.scan{justify-self:start;min-height:30px;padding:5px 8px;color:var(--a);border-color:var(--l);background:transparent;font-size:9px}.hint{color:#65756f;font-size:9px}
@media(max-width:560px){main{padding-top:27px}.brand{margin-bottom:36px}.grid{grid-template-columns:1fr}.full{grid-column:auto}.actions{align-items:stretch;flex-direction:column-reverse}.actions button{width:100%}}
</style></head><body><main><div class="brand"><span class="mark">✣</span><strong>OPEN HACCP</strong> SETUP</div>
<header><p class="eyebrow">Sichere Ersteinrichtung</p><h1>Sensor anlernen</h1><p>Das Gerät prüft WLAN, HTTPS-Zertifikat und Gerätezugang, bevor es Werte dauerhaft speichert. Das Setup-WLAN wird anschließend abgeschaltet.</p></header>
<div id="message"></div><form method="post" action="/configure" autocomplete="off">
<section><h2>Lokales WLAN</h2><p>2,4-GHz-Netz auswählen oder den Namen direkt eingeben.</p><div class="grid"><label class="full">WLAN-Name<input id="ssid" name="ssid" maxlength="32" list="networks" required><datalist id="networks"></datalist></label><label class="full">WLAN-Passwort<input name="wifi_password" type="password" maxlength="63"></label><button class="scan" type="button" id="scan">Netze suchen</button></div></section>
<section><h2>HACCP-Server</h2><p>Nur HTTPS. Der Hostname und die Zertifikatskette werden vollständig geprüft.</p><div class="grid"><label class="full">API-Basis-URL<input name="api_url" type="url" maxlength="180" value="https://haccp.pow24.org" required></label></div></section>
<section><h2>Gerätezugang</h2><p>Diese einmaligen Werte stammen aus „Gerät anlernen“ im Dashboard.</p><div class="grid"><label>Geräte-UID<input name="device_uid" maxlength="64" autocapitalize="none" spellcheck="false" required></label><label>Messstellenkennung<input name="measurement_point" maxlength="64" autocapitalize="none" spellcheck="false" required></label><label class="full">Bezeichnung<input name="device_label" maxlength="160" required></label><label class="full">Geräteschlüssel<input name="device_key" type="password" minlength="64" maxlength="64" autocapitalize="none" spellcheck="false" required></label></div></section>
<div class="actions"><small>Die Prüfung kann bis zu 45 Sekunden dauern.<br>Das Gerätepasswort wird niemals angezeigt.</small><button type="submit">Verbindung prüfen &amp; speichern</button></div></form></main>
<script>const b=document.querySelector('#scan'),d=document.querySelector('#networks'),m=document.querySelector('#message');b.onclick=async()=>{b.disabled=true;b.textContent='Suche …';try{const r=await fetch('/scan'),j=await r.json();d.replaceChildren(...j.networks.map(n=>Object.assign(document.createElement('option'),{value:n.ssid})));m.className='';m.textContent=j.networks.length+' Netze gefunden';}catch(e){m.className='message';m.textContent='WLAN-Suche fehlgeschlagen.'}finally{b.disabled=false;b.textContent='Netze suchen'}};</script></body></html>)HTML";

String suffixFromMac()
{
    const uint64_t mac = ESP.getEfuseMac();
    char suffix[7];
    snprintf(suffix, sizeof(suffix), "%06llX", static_cast<unsigned long long>(mac & 0xFFFFFFULL));
    return String(suffix);
}
}

ProvisioningPortal::ProvisioningPortal(DeviceState &state, HaccpClient &client)
    : state_(state), client_(client)
{
}

void ProvisioningPortal::begin()
{
    accessPointName_ = "OpenHACCP-" + suffixFromMac();
    WiFi.mode(WIFI_AP_STA);
    WiFi.softAP(accessPointName_.c_str(), OPEN_HACCP_SETUP_AP_PASSWORD);
    dns_.start(53, "*", WiFi.softAPIP());
    server_.on("/", HTTP_GET, [this] { handleRoot(); });
    server_.on("/scan", HTTP_GET, [this] { handleScan(); });
    server_.on("/configure", HTTP_POST, [this] { handleConfigure(); });
    server_.on("/generate_204", HTTP_GET, [this] { redirectToPortal(); });
    server_.on("/hotspot-detect.html", HTTP_GET, [this] { redirectToPortal(); });
    server_.on("/connecttest.txt", HTTP_GET, [this] { redirectToPortal(); });
    server_.onNotFound([this] { redirectToPortal(); });
    server_.begin();
    Serial.println("Provisioning portal active at http://192.168.4.1");
    Serial.println("Setup SSID: " + accessPointName_);
}

void ProvisioningPortal::loop()
{
    dns_.processNextRequest();
    server_.handleClient();
    if (restartAt_ != 0 && static_cast<int32_t>(millis() - restartAt_) >= 0) {
        ESP.restart();
    }
    delay(2);
}

void ProvisioningPortal::handleRoot()
{
    server_.sendHeader("Cache-Control", "no-store");
    server_.send(200, "text/html; charset=utf-8", PortalHtml);
}

void ProvisioningPortal::handleScan()
{
    const int count = WiFi.scanNetworks(false, true);
    JsonDocument document;
    JsonArray networks = document["networks"].to<JsonArray>();
    for (int index = 0; index < count; ++index) {
        JsonObject network = networks.add<JsonObject>();
        network["ssid"] = WiFi.SSID(index);
        network["rssi_dbm"] = WiFi.RSSI(index);
        network["secured"] = WiFi.encryptionType(index) != WIFI_AUTH_OPEN;
    }
    WiFi.scanDelete();
    String response;
    serializeJson(document, response);
    server_.sendHeader("Cache-Control", "no-store");
    server_.send(200, "application/json", response);
}

void ProvisioningPortal::handleConfigure()
{
    ProvisioningConfig candidate;
    candidate.wifiSsid = server_.arg("ssid");
    candidate.wifiPassword = server_.arg("wifi_password");
    candidate.apiBaseUrl = server_.arg("api_url");
    candidate.deviceUid = server_.arg("device_uid");
    candidate.deviceKey = server_.arg("device_key");
    candidate.deviceLabel = server_.arg("device_label");
    candidate.measurementPoint = server_.arg("measurement_point");
    candidate.apiBaseUrl.trim();
    while (candidate.apiBaseUrl.endsWith("/")) {
        candidate.apiBaseUrl.remove(candidate.apiBaseUrl.length() - 1);
    }

    String error;
    if (!validateCandidate(candidate, error)) {
        sendError(error);
        return;
    }
    uint32_t connectMs = 0;
    if (!connectStation(candidate, connectMs, error) || !synchronizeClock(error)) {
        sendError(error);
        return;
    }
    RuntimeConfig runtime;
    if (!client_.fetchConfig(candidate, runtime, error)) {
        WiFi.disconnect(false, false);
        sendError(error);
        return;
    }
    if (!state_.saveRuntime(runtime) || !state_.saveProvisioning(candidate)) {
        sendError("Configuration could not be stored. Please retry.");
        return;
    }

    server_.sendHeader("Cache-Control", "no-store");
    server_.send(200, "text/html; charset=utf-8", R"HTML(<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="color-scheme" content="dark"><style>body{display:grid;min-height:100vh;margin:0;place-items:center;background:#091013;color:#eef5f2;font-family:system-ui}.box{max-width:460px;padding:30px}.mark{display:grid;width:44px;height:44px;place-items:center;border-radius:50%;background:#61e1cf;color:#07110f;font-size:22px}h1{font-size:34px;letter-spacing:-.04em}p{color:#91a39d;line-height:1.6}</style></head><body><main class="box"><div class="mark">✓</div><h1>Gerät ist bereit</h1><p>WLAN, HTTPS und Gerätezugang wurden geprüft. Die Konfiguration ist gespeichert; der Sensor startet neu und das Setup-WLAN wird abgeschaltet.</p></main></body></html>)HTML");
    restartAt_ = millis() + 2500;
}

void ProvisioningPortal::redirectToPortal()
{
    server_.sendHeader("Location", "http://192.168.4.1/", true);
    server_.send(302, "text/plain", "");
}

void ProvisioningPortal::sendError(const String &message)
{
    const String body = "{\"success\":false,\"message\":\"" + escapeHtml(message) + "\"}";
    server_.sendHeader("Cache-Control", "no-store");
    server_.send(422, "application/json", body);
}

bool ProvisioningPortal::validateCandidate(ProvisioningConfig &candidate, String &error)
{
    if (candidate.wifiSsid.isEmpty() || candidate.wifiSsid.length() > 32 || candidate.wifiPassword.length() > 63) {
        error = "WLAN name or password has an invalid length.";
        return false;
    }
    if (!candidate.apiBaseUrl.startsWith("https://") || candidate.apiBaseUrl.length() > 180
        || candidate.apiBaseUrl.indexOf('@') >= 0 || candidate.apiBaseUrl.indexOf('?') >= 0
        || candidate.apiBaseUrl.indexOf('#') >= 0 || candidate.apiBaseUrl.indexOf('/', 8) >= 0
        || candidate.apiBaseUrl.length() <= 8) {
        error = "Server URL must be a plain HTTPS base URL.";
        return false;
    }
    if (!slug(candidate.deviceUid, 3, 64) || !slug(candidate.measurementPoint, 1, 64)) {
        error = "Device UID or measurement point has an invalid format.";
        return false;
    }
    if (!hexKey(candidate.deviceKey)) {
        error = "Device key must contain exactly 64 hexadecimal characters.";
        return false;
    }
    if (candidate.deviceLabel.isEmpty() || candidate.deviceLabel.length() > 160) {
        error = "Device label has an invalid length.";
        return false;
    }
    return candidate.isValid();
}

bool ProvisioningPortal::connectStation(const ProvisioningConfig &candidate, uint32_t &connectMs, String &error)
{
    WiFi.disconnect(false, false);
    const uint32_t startedAt = millis();
    WiFi.begin(candidate.wifiSsid.c_str(), candidate.wifiPassword.c_str());
    while (WiFi.status() != WL_CONNECTED && millis() - startedAt < 20000) {
        delay(200);
    }
    connectMs = millis() - startedAt;
    if (WiFi.status() != WL_CONNECTED) {
        error = "Connection to the selected WLAN failed.";
        return false;
    }
    return true;
}

bool ProvisioningPortal::synchronizeClock(String &error)
{
    configTime(0, 0, "pool.ntp.org", "time.cloudflare.com");
    const uint32_t startedAt = millis();
    while (time(nullptr) < 1704067200 && millis() - startedAt < 20000) {
        delay(200);
    }
    if (time(nullptr) < 1704067200) {
        error = "UTC time synchronization failed; HTTPS cannot be verified safely.";
        return false;
    }
    return true;
}

bool ProvisioningPortal::slug(const String &value, size_t minimum, size_t maximum)
{
    if (value.length() < minimum || value.length() > maximum) {
        return false;
    }
    for (size_t index = 0; index < value.length(); ++index) {
        const char character = value[index];
        if (!((character >= 'a' && character <= 'z') || (character >= '0' && character <= '9') || character == '-')) {
            return false;
        }
    }
    return value[0] != '-' && value[value.length() - 1] != '-';
}

bool ProvisioningPortal::hexKey(const String &value)
{
    if (value.length() != 64) {
        return false;
    }
    for (size_t index = 0; index < value.length(); ++index) {
        if (!isxdigit(static_cast<unsigned char>(value[index]))) {
            return false;
        }
    }
    return true;
}

String ProvisioningPortal::escapeHtml(const String &value)
{
    String escaped = value;
    escaped.replace("\\", "\\\\");
    escaped.replace("\"", "\\\"");
    escaped.replace("\r", " ");
    escaped.replace("\n", " ");
    return escaped;
}
