# DHT22 an ESP32-WROOM-32 und ESP8266 D1 mini in Betrieb nehmen

Diese Anleitung gilt für die dreipoligen DHT22-/AM2302-Module mit den **auf der Sensorplatine** beschrifteten Anschlüssen `+`, `OUT` und `−`. Die beiden USB-versorgten Ziele verwenden dasselbe HACCP-Protokoll, aber unterschiedliche Firmwaredateien. Für das vorhandene ESP32-S3/SHT45-Profil gilt weiterhin dessen eigene Pinbelegung.

Voraussetzung am Rechner: [PlatformIO Core](https://docs.platformio.org/en/latest/core/installation/methods/installer-script.html) mit dem Befehl `pio`, [Espressif esptool](https://docs.espressif.com/projects/esptool/en/latest/esp32/installation.html) mit dem Befehl `esptool`, ein datenfähiges USB-Kabel und für die elektrische Prüfung ein Multimeter. Auf macOS mit Homebrew lässt sich esptool bei Bedarf mit `brew install esptool` installieren.

## 1. Board und Sensor bei abgeschalteter Versorgung verdrahten

| Sensorplatine | ESP8266 D1 mini (ESP8266MOD) | ESP32-Board (ESP-WROOM-32, USB-C) |
|---|---|---|
| `+` | `3V3` | `3V3` |
| `OUT` | `D2` = `GPIO4` | `GPIO21` (Vorschlag, am konkreten Board prüfen) |
| `−` | `G` / `GND` | `GND` |

Beide Module ausschließlich an **3,3 V** anschließen. Nicht nach den Farben des mitgelieferten Kabels verdrahten: An der tatsächlichen Platine die Markierungen `+`, `OUT`, `−` ablesen und jeden Draht zu seinem Zielpin durchklingeln. Falls Stiftleisten fehlen, diese zuerst bei abgezogenem USB-Kabel einlöten. Vor dem Einstecken von USB `+` gegen `−` auf Kurzschluss und die `OUT`-Verbindung auf den richtigen GPIO prüfen. Nach dem Einschalten an `+` gegen `−` ungefähr 3,3 V messen.

Der Datenpin ist je Build konfigurierbar: ESP32 `OPEN_HACCP_DHT_DATA_PIN=21`, D1 mini `OPEN_HACCP_DHT_PIN=4`. Diese GPIO-Nummern stehen als Vorgabe im jeweiligen `FirmwareConfig.h` und können mit einem PlatformIO-`build_flags`-Override für eine abweichend geprüfte Verdrahtung geändert werden. `D2` ist die D1-mini-Boardbeschriftung für `GPIO4`, keine GPIO-Nummer 2.

Der DHT-Datenleiter benötigt einen Pull-up nach 3,3 V. Einige dreipolige Modulplatinen haben ihn bereits; das Shopfoto beweist das nicht. Platine/Schaltplan oder den Widerstand zwischen `OUT` und `+` bei abgezogener Versorgung prüfen. Fehlt er, einen externen **4,7–10 kΩ** Widerstand zwischen `OUT` und `3V3` ergänzen. Nur einen sauberen Pull-up vorsehen. Der DHT22 darf nicht schneller als etwa alle zwei Sekunden abgefragt werden; die Firmware wartet nach dem Einschalten/Neustart auf den Sensor.

Für den ersten USB-, Portal- und Sensortest **keine** zusätzliche Sleep-Brücke setzen. Erst wenn automatisches zeitgesteuertes Aufwachen des **D1 mini** geprüft werden soll, `D0`/`GPIO16` mit `RST` verbinden und das Sleep-Profil flashen. Ohne diese Verbindung wacht ein ESP8266 aus Deep Sleep nicht selbstständig per Timer auf. Das ESP32-Board braucht diese Brücke nicht. Die bewusste Factory-Reset-Geste löscht auch noch nicht hochgeladene Messungen: beim D1 mini `D5`/`GPIO14`, beim ESP32-WROOM `GPIO27` während des Boots mindestens fünf Sekunden an GND halten. Diese Reset-Brücke nur bei Bedarf stecken. `GPIO0`/`BOOT` am ESP32-WROOM dafür nicht verwenden: LOW bei Reset startet den seriellen Bootloader statt der Firmware. Ein normaler Reset-Tasterdruck ist keine Factory-Reset-Geste.

## 2. USB-Identität und seriellen Port prüfen

Die Fotos zeigen ESP-WROOM-32-Module auf USB-C-Entwicklungsboards; Hersteller, exakte Devkit-Variante, USB-Seriell-Wandler und Flash-Größe sind nicht sicher lesbar. `esp32dev` ist daher ein **vorläufiges kompatibles PlatformIO-Buildprofil**, keine bestätigte Boardbezeichnung. Beim D1 mini ist `d1_mini` das vorgesehene PlatformIO-Boardprofil. Vor dem ESP32-Flashen USB-Erkennung, gemeldeten Chip und Flash-Größe mit der Boardbeschriftung und dem gewählten Flash-Layout abgleichen. Ein USB-C-Anschluss allein identifiziert weder den ESP32-Typ noch den Flash-Ausbau.

Nacheinander nur **ein** Board mit einem datenfähigen USB-Kabel anschließen. Den neu auftauchenden Port vergleichen:

```bash
pio device list
# macOS ergänzend:
ls /dev/cu.*
PORT=/dev/cu.usbserial-0001  # durch den tatsächlich erkannten Port ersetzen
esptool --port "$PORT" flash-id
```

Auf macOS den Port `/dev/cu.*` statt `/dev/tty.*` für Upload und Monitor verwenden; unter Linux gewöhnlich `/dev/ttyUSB*` oder `/dev/ttyACM*`. `esptool` meldet Chip und Flash-Ausbau. Stimmen diese Angaben nicht mit dem gewählten Ziel überein, das Boardprofil **vor dem Upload** korrigieren. Ohne neu erkannten Port zuerst Kabel, USB-Hub und nötigen USB-Seriell-Treiber prüfen.

## 3. Individuelles Setup-Passwort erzeugen, bauen und flashen

Für **jedes physische Gerät** ein eigenes WPA2-Setup-Passwort erzeugen und zusammen mit der Boardkennung auf einem physischen Etikett oder in einem geschützten Passwortspeicher verwahren:

```bash
openssl rand -base64 24
cp firmware/esp32-s3/include/BuildSecrets.example.h firmware/esp32-s3/include/BuildSecrets.h
cp firmware/esp8266-d1-mini/include/BuildSecrets.example.h firmware/esp8266-d1-mini/include/BuildSecrets.h
```

Nur die Kopie für das jeweils zu flashende Board braucht bearbeitet zu werden. In einem lokalen Texteditor den leeren Wert von `OPEN_HACCP_SETUP_AP_PASSWORD` in `BuildSecrets.h` durch das erzeugte Passwort ersetzen; `BuildSecrets.example.h` bleibt unverändert. Ein leerer Wert stoppt den Build absichtlich. `BuildSecrets.h`, WLAN-Zugangsdaten und Backend-Geräteschlüssel gehören nicht in Git, Tickets, Shell-Befehle, öffentliche Build-Artefaktpakete oder serielle Mitschnitte. WLAN-Zugang und der einmalige Geräteschlüssel werden später ausschließlich im geschützten Einrichtungsportal eingegeben. Die Binärdatei enthält das Setup-Passwort technisch notwendigerweise; sie deshalb nur kontrolliert an das zugehörige Gerät weitergeben.

Im Repository-Stammverzeichnis jeweils das angeschlossene Board wählen:

```bash
# ESP32-WROOM-32 + DHT22 (USB-Ersttest ohne Deep Sleep)
pio run -d firmware/esp32-s3 -e esp32-wroom-dht22
pio run -d firmware/esp32-s3 -e esp32-wroom-dht22 -t upload --upload-port "$PORT"
pio device monitor -p "$PORT" -b 115200
```

```bash
# ESP8266 D1 mini + DHT22 (USB-Ersttest ohne Deep Sleep)
# Nur beim allerersten Flashen eines noch leeren D1 mini: LittleFS initialisieren.
pio run -d firmware/esp8266-d1-mini -e d1-mini-dht22 -t uploadfs --upload-port "$PORT"
pio run -d firmware/esp8266-d1-mini -e d1-mini-dht22
pio run -d firmware/esp8266-d1-mini -e d1-mini-dht22 -t upload --upload-port "$PORT"
pio device monitor -p "$PORT" -b 115200
```

Die Anwendungsdateien liegen danach unter `firmware/esp32-s3/.pio/build/esp32-wroom-dht22/firmware.bin` beziehungsweise `firmware/esp8266-d1-mini/.pio/build/d1-mini-dht22/firmware.bin`. Für das ESP32-Board sind außerdem Bootloader und Partitionstabelle nötig; `pio run … -t upload` schreibt die vom Profil vorgesehenen Adressen zusammen. Die `.pio`-Ausgaben bleiben lokal und werden nicht versioniert. Während Upload und seriellem Monitor darf kein anderes Programm denselben Port geöffnet halten.
`uploadfs` schreibt beim D1 mini das Dateisystem neu. **Nach der Ersteinrichtung nie erneut ausführen**, solange Zugangsdaten oder noch nicht bestätigte Messungen auf dem Gerät liegen; das würde sie löschen. Spätere Firmware-Updates verwenden nur `-t upload`.

## 4. Gerät mit der HACCP-App verbinden

Im Dashboard unter **Geräte** ein Gerät und eine Messstelle mit Sensortyp `DHT22` anlegen. Den nur einmal angezeigten Geräteschlüssel geschützt übernehmen. Nach dem Flashen beziehungsweise Neustart startet ein unprovisioniertes Board das WPA2-Netz `OpenHACCP-…`. Mit dem individuellen Passwort verbinden und `http://192.168.4.1` öffnen. Dort das 2,4-GHz-WLAN, `https://haccp.pow24.org`, die Geräte-UID, den Geräteschlüssel und die Messstellenkennung eingeben. Das Gerät prüft WLAN, UTC-Zeit, HTTPS-Zertifikatskette und Hostnamen sowie den Gerätezugang über `GET /api/v1/device/config`, bevor es die Daten speichert und neu startet. Bei einer Fehlermeldung im Portal bleiben die Eingaben zu korrigieren; kein ungesicherter HTTP-Server als Ersatz verwenden.

Bei noch offenen Messungen darf die Geräte-UID oder Messstellenkennung nicht gewechselt werden. Schlägt die erneute Einrichtung mit einer anderen Kennung fehl, zuerst die bestehende Warteschlange unter der bisherigen Identität übertragen. Ein Factory Reset löscht die ausstehenden Messungen bewusst und ist nur nach entsprechender Datensicherung beziehungsweise Entscheidung zum Verwerfen zulässig.

Diese USB-Profile haben keinen Batterie-Messpfad. Sie melden die Fähigkeit `mains_power` und einen nicht verfügbaren Batteriewert (`battery_mv: null`); das Dashboard zeigt **Netzbetrieb/Batteriewert nicht verfügbar**, ohne einen Leere-Batterie-Alarm auszulösen. Bei Geräten mit echter Batteriemessung bleibt die Batterieanzeige erhalten.

## 5. Messung und Übertragung beobachten

Im seriellen Monitor nach dem Start auf Sensorinitialisierung, gültige Temperatur und relative Luftfeuchtigkeit sowie den Warteschlangen-/Uploadstatus achten. Bei `NaN`, ungültigem Wertebereich oder wiederholtem DHT-Lesefehler Verkabelung, 3,3-V-Versorgung, Pull-up und Mindestabstand der Abfragen prüfen. Ein solcher Wert darf weder als gültige Messung in der Warteschlange noch im Dashboard auftauchen. Die erste DHT-Abfrage benötigt nach der Versorgung eine Anlaufzeit.

Im Dashboard anschließend kontrollieren, dass `DHT22`, die Boardkennung, der Messstellen-Code und die neue Messung mit plausibler Temperatur/Feuchte erscheinen. Das D1-mini-Profil meldet `ESP8266 D1 mini ESP8266MOD`; das ESP32-Profil meldet zunächst `ESP-WROOM-32 USB-C DevKit variant unverified`, bis die konkrete Devkit-Variante am angeschlossenen Board eindeutig geprüft und die Build-Kennung entsprechend angepasst wurde. Der Original-Zeitpunkt der Messung muss auch dann bestehen bleiben, wenn erst später hochgeladen wird. Eine Änderung des Messintervalls in den Geräteeinstellungen soll nach dem nächsten erfolgreichen Kontakt als vom Gerät angewendet bestätigt werden.

## 6. Offline-Warteschlange und ACK testen

Nach einer ersten erfolgreichen Messung den Zugang zum Standort-WLAN kurz unterbrechen, mindestens eine weitere Messung abwarten und das Gerät neu starten. Der Monitor muss die ausstehende Messung weiterhin als offen zeigen. WLAN wiederherstellen und Upload beobachten. Nur eine **exakte** `accepted`- oder identische `duplicate`-Bestätigung der jeweiligen Messung darf ihren lokalen Datensatz löschen; HTTP 200 allein reicht nicht. Im Dashboard sollten keine doppelten Messungen entstehen. Eine absichtlich volle Warteschlange darf ältere unbestätigte Messungen nicht überschreiben.

Der D1 mini speichert maximal **32** offene Datensätze. Das entspricht bei fünf Minuten Messintervall höchstens **2 h 40 min**, beim serverseitig zulässigen Minimum von 30 Sekunden nur **16 min** ohne erfolgreiche Übertragung. Das ESP32-Ziel hält **64** Datensätze vor, entsprechend **5 h 20 min** bei fünf Minuten oder **32 min** bei 30 Sekunden. Danach fallen neue Abtastungen aus und erzeugen eine Diagnose, bis bestätigt hochgeladen werden kann. Diese Zahlen beschreiben nur die Speicherkapazität, keine garantierte Offline-Betriebsdauer.

Der D1 mini schätzt den ursprünglichen UTC-Zeitpunkt während Deep Sleep aus dem letzten NTP-Zeitanker und begrenzt diese Schätzung auf 24 Stunden beziehungsweise 288 Schlafzyklen. Vor jeder HTTPS-Verbindung fordert er eine frische NTP-Antwort an. Die Genauigkeit des Offline-Zeitankers und TLS-Speicherreserve auf der konkreten Platine müssen im Hardwaretest bestätigt werden. Meldet ein Gerät `STORAGE_FAILED`, keine neue Geräteidentität provisionieren oder das Dateisystem erneut hochladen; zuerst die gespeicherten Daten sichern und den Fehler untersuchen.

## 7. Deep Sleep erst nach dem USB-Test einschalten

Am ESP32 die Sensor- und Aufwachzyklen zunächst mit `esp32-wroom-dht22` prüfen. Dieses USB-Testprofil verwendet Light Sleep beziehungsweise einen begrenzten Neustart als Rückfallpfad; der serielle Port kann dabei kurz verschwinden und wieder auftauchen. Das gesonderte Profil `esp32-wroom-dht22-sleep` aktiviert danach den Timer Deep Sleep; eine zusätzliche Drahtbrücke ist nicht erforderlich:

```bash
pio run -d firmware/esp32-s3 -e esp32-wroom-dht22-sleep -t upload --upload-port "$PORT"
```

Am D1 mini **zuerst** die Verbindung `D0`/`GPIO16` → `RST` bei ausgeschalteter Versorgung herstellen, auf Kurzschluss prüfen und dann das gesonderte Sleep-Profil flashen. Im seriellen Monitor mindestens zwei Timer-Aufwachzyklen und die erneute Sensor-/Netzfunktion prüfen. Die Brücke bei jedem späteren Flash- oder Resetproblem als mögliche Ursache berücksichtigen; das Testprofil ohne Deep Sleep bleibt für USB-Diagnose verfügbar. Der ESP8266 wacht aus Deep Sleep per Reset auf, nicht durch den ESP32-Wake-Pfad.

```bash
pio run -d firmware/esp8266-d1-mini -e d1-mini-dht22-sleep -t upload --upload-port "$PORT"
```

## Referenzen

- [PlatformIO: `esp32dev`](https://docs.platformio.org/en/stable/boards/espressif32/esp32dev.html) und [`d1_mini`](https://docs.platformio.org/en/stable/boards/espressif8266/d1_mini.html): Board-IDs und nominelle Speicherausstattung.
- [PlatformIO: serielle Ports anzeigen](https://docs.platformio.org/en/stable/core/userguide/device/cmd_list.html) und [Espressif esptool: Flash-ID](https://docs.espressif.com/projects/esptool/en/latest/esp32/esptool/basic-commands.html): USB-/Flash-Prüfung.
- [ESP8266 Arduino Core: Deep Sleep](https://arduino-esp8266.readthedocs.io/en/3.0.0/libraries.html): `GPIO16` muss zum Timer-Aufwachen mit `RST` verbunden sein.
- [Adafruit: DHT22/AM2302](https://learn.adafruit.com/dht): Pull-up und Mindestabstand der Abfragen. Für die konkret gekaufte dreipolige Platine sind deren aufgedruckte Pin-Namen maßgeblich.
