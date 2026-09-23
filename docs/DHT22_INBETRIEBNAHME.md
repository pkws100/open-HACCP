# DHT22 an ESP32-WROOM-32 und ESP8266 D1 mini in Betrieb nehmen

Diese Anleitung gilt für die dreipoligen DHT22-/AM2302-Module mit den **auf der Sensorplatine** beschrifteten Anschlüssen `+`, `OUT` und `−`. Die beiden USB-versorgten Ziele verwenden dasselbe HACCP-Protokoll, aber unterschiedliche Firmwaredateien. Für das vorhandene ESP32-S3/SHT45-Profil gilt weiterhin dessen eigene Pinbelegung.

Voraussetzung am Rechner: [PlatformIO Core](https://docs.platformio.org/en/latest/core/installation/methods/installer-script.html) mit dem Befehl `pio`, [Espressif esptool](https://docs.espressif.com/projects/esptool/en/latest/esp32/installation.html) mit dem Befehl `esptool`, ein datenfähiges USB-Kabel und für die elektrische Prüfung ein Multimeter. Auf macOS mit Homebrew lässt sich esptool bei Bedarf mit `brew install esptool` installieren.

## 1. Board und Sensor bei abgeschalteter Versorgung verdrahten

| Sensorplatine | ESP8266 D1 mini (ESP8266MOD) | ESP32-Board (ESP-WROOM-32, USB-C) |
|---|---|---|
| `+` | `3V3` | `3V3` |
| `OUT` | `D2` = `GPIO4` | `GPIO21` (am ersten angeschlossenen Board mit realen Messungen geprüft) |
| `−` | `G` / `GND` | `GND` |

Beide Module ausschließlich an **3,3 V** anschließen. Nicht nach den Farben des mitgelieferten Kabels verdrahten: An der tatsächlichen Platine die Markierungen `+`, `OUT`, `−` ablesen und jeden Draht zu seinem Zielpin durchklingeln. Falls Stiftleisten fehlen, diese zuerst bei abgezogenem USB-Kabel einlöten. Vor dem Einstecken von USB `+` gegen `−` auf Kurzschluss und die `OUT`-Verbindung auf den richtigen GPIO prüfen. Nach dem Einschalten an `+` gegen `−` ungefähr 3,3 V messen.

Der Datenpin ist je Build konfigurierbar: ESP32 `OPEN_HACCP_DHT_DATA_PIN=21`, D1 mini `OPEN_HACCP_DHT_PIN=4`. Diese GPIO-Nummern stehen als Vorgabe im jeweiligen `FirmwareConfig.h` und können mit einem PlatformIO-`build_flags`-Override für eine abweichend geprüfte Verdrahtung geändert werden. `D2` ist die D1-mini-Boardbeschriftung für `GPIO4`, keine GPIO-Nummer 2.

Der DHT-Datenleiter benötigt einen Pull-up nach 3,3 V. Einige dreipolige Modulplatinen haben ihn bereits; das Shopfoto beweist das nicht. Platine/Schaltplan oder den Widerstand zwischen `OUT` und `+` bei abgezogener Versorgung prüfen. Fehlt er, einen externen **4,7–10 kΩ** Widerstand zwischen `OUT` und `3V3` ergänzen. Nur einen sauberen Pull-up vorsehen. Der DHT22 darf nicht schneller als etwa alle zwei Sekunden abgefragt werden; die Firmware wartet nach dem Einschalten/Neustart auf den Sensor. Für eine aktuelle AM2302-Messung verwirft sie den ersten Busabruf und nutzt nach weiteren 2,1 Sekunden den zweiten; diese zusätzliche Wachzeit ist bei der Batterielaufzeit zu berücksichtigen.

### Einsatz im Gefrierschrank

Das [Aosong-AM2302-Datenblatt](https://www.aosong.com/uploadfiles/2025/04/20250417105409216.pdf) nennt **−40 bis +80 °C** als Temperaturbereich. Für relative Feuchte unter **0 °C** weist es keine gesicherte Genauigkeit aus; seine Genauigkeitsangaben setzen zudem **kondensationsfreie** Bedingungen voraus. Bei **3,3 V** darf die Sensorleitung laut Hersteller höchstens **1 m** lang sein. Nach dem Einschalten **mehr als 2 s** bis zur ersten Abfrage warten und zwischen zwei Abfragen **mindestens 2 s** lassen.

Die beiden neuen Firmwareprofile setzen die Herstellerempfehlung für zwei Busabfragen um: Nach der Anlaufzeit verwerfen sie die erste Abfrage, warten **2,1 s** und verwenden erst die zweite für eine HACCP-Messung. So wird ein vom Sensor zwischengespeicherter Vorwert nicht als neue Messung ausgegeben.

Für einen Gefrierschrank ist es daher eine praktische Schlussfolgerung, ESP-Entwicklungsboard und Akku außerhalb zu lassen und nur den Sensor mit kurzer Leitung am Messpunkt zu platzieren. Die Kabeldurchführung sorgfältig abdichten, ohne die Sensoröffnung zu verschließen; die Temperatur vor dem HACCP-Einsatz mit einem geeigneten Referenzthermometer vergleichen. Ein solcher Gefrierschrankaufbau wurde hier noch nicht getestet.

Für den ersten USB-, Portal- und Sensortest **keine** zusätzliche Sleep-Brücke setzen. Erst wenn automatisches zeitgesteuertes Aufwachen des **D1 mini** geprüft werden soll, `D0`/`GPIO16` mit `RST` verbinden und das Sleep-Profil flashen. Ohne diese Verbindung wacht ein ESP8266 aus Deep Sleep nicht selbstständig per Timer auf. Das ESP32-Board braucht diese Brücke nicht. Die bewusste Factory-Reset-Geste löscht auch noch nicht hochgeladene Messungen: beim D1 mini `D5`/`GPIO14`, beim ESP32-WROOM `GPIO27` während des Boots mindestens fünf Sekunden an GND halten. Diese Reset-Brücke nur bei Bedarf stecken. `GPIO0`/`BOOT` am ESP32-WROOM dafür nicht verwenden: LOW bei Reset startet den seriellen Bootloader statt der Firmware. Ein normaler Reset-Tasterdruck ist keine Factory-Reset-Geste.

## 2. USB-Identität und seriellen Port prüfen

Die Fotos zeigen ESP-WROOM-32-Module auf USB-C-Entwicklungsboards; Hersteller und exakte Devkit-Variante sind daraus nicht sicher lesbar. Das erste tatsächlich angeschlossene Board meldete einen **ESP32-D0WD-V3, Revision 3.1**, mit **40-MHz-Quarz** und **4 MB Flash**. Der USB-Seriell-Wandler ist ein **Silicon Labs CP2102** mit USB-ID `10c4:ea60`; auf dem geprüften Mac erschien `/dev/cu.SLAB_USBtoUART` (auch `/dev/cu.usbserial-0001`). Das generische PlatformIO-Profil `esp32dev` wurde auf diesem Board erfolgreich geflasht und ist dafür kompatibel. Es ist weiterhin **keine exakte Hersteller-/Devkit-Bezeichnung**. Diese Prüfung gilt nur für das angeschlossene Exemplar; jedes weitere Board vor dem Flashen ebenso identifizieren. Beim D1 mini ist `d1_mini` das vorgesehene PlatformIO-Boardprofil. Ein USB-C-Anschluss allein identifiziert weder den ESP32-Typ noch den Flash-Ausbau.

Nacheinander nur **ein** Board mit einem datenfähigen USB-Kabel anschließen. Den neu auftauchenden Port vergleichen:

```bash
pio device list
# macOS ergänzend:
ls /dev/cu.*
PORT=/dev/cu.SLAB_USBtoUART  # durch den tatsächlich erkannten Port ersetzen
esptool --port "$PORT" flash-id
```

Auf macOS den Port `/dev/cu.*` statt `/dev/tty.*` für Upload und Monitor verwenden; unter Linux gewöhnlich `/dev/ttyUSB*` oder `/dev/ttyACM*`. `esptool` meldet Chip und Flash-Ausbau. Stimmen diese Angaben nicht mit dem gewählten Ziel überein, das Boardprofil **vor dem Upload** korrigieren. Ohne neu erkannten Port zuerst Kabel, USB-Hub und nötigen USB-Seriell-Treiber prüfen. Beim geprüften Mac erschien der CP2102-Port erst nach Installation und Freigabe des offiziellen Silicon-Labs-CP210x-VCP-Treibers, einem Neustart von macOS und erneutem Anschließen des Boards. Falls macOS nach dem USB-Zubehör fragt, dessen Verbindung erlauben. Einen Treiber nur installieren, wenn der eigene Mac ihn benötigt.

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

**Hardwaretest am ersten ESP32 (23.09.2026):** `+ → 3V3`, `OUT → GPIO21` und `− → GND` wurden verdrahtet. Ein temporärer DHT22-Sensortest las mit 2,5 Sekunden Abstand sechs gültige Werte von **24,4–24,5 °C** und **33,5–35,1 % rF**. Ohne zusätzlich gesteckten Pull-up funktionierte diese konkrete Verdrahtung; ob und welchen Pull-up die gekaufte Modulplatine bestückt hat, wurde nicht elektrisch verifiziert. Danach wurde die Produktionsfirmware wieder aufgespielt. Der Start des geschützten Einrichtungs-WLANs und des Portals bei `http://192.168.4.1` wurde seriell beobachtet. Nach der Einrichtung bestätigte das Portal „Gerät ist bereit“. Die Firmware `0.4.0-esp32-dht22` übertrug auf dem Testserver drei echte Messungen; der serielle Monitor meldete **drei bestätigte Datensätze und null offene**. Das Dashboard zeigte unter anderem **24,0 °C**, **36,8 % rF**, `DHT22`, das ESP-WROOM-32-Boardprofil, Konfigurationsversion 1 und **Netzbetrieb · Batteriewert nicht verfügbar**. Die genaue DevKit-Variante ist weiterhin ungeklärt.

Vor der Aktualisierung des Testservers beantwortete dessen ältere Version die Übertragung ohne Batteriewert mit HTTP 422. Die Firmware behielt die Messungen dabei in der Warteschlange. Nach Sicherung, Migration und Deployment des aktuellen Repository-Stands auf `haccp.pow24.org` nahm der Server die Datensätze an; die Firmware löschte sie erst nach den zugehörigen ACKs. Damit sind die lokale Einrichtung, die HTTPS-Verbindung zu diesem Server, eine echte DHT22-Messung und ein erfolgreicher ACK-Pfad am angeschlossenen ESP32 nachgewiesen. Anschließend wurde die ESP32-Sleep-Variante geflasht: Der ESP32 ging für 139 Sekunden in Deep Sleep, wachte selbstständig wieder auf und las **23,9 °C / 37,4 % rF** am DHT22. Diese neue Messung blieb gemäß dem Serverintervall zunächst in der Warteschlange; für sie wurde noch kein ACK beobachtet. Ein längerer Netzausfall mit Neustart und Wiederverbindung sowie eine volle Warteschlange wurden an dieser Hardware noch nicht nachgewiesen. Der D1 mini wurde noch nicht angeschlossen.

Am zweiten ESP32 wurden ebenfalls CP2102, ESP32-D0WD-V3 Revision 3.1 und 4 MB Flash erkannt. Mit abgetrennter Batterie und DHT22 an 3V3/GPIO21/GND wurde das individuelle USB-Testprofil `0.4.1-esp32-dht22` geflasht; esptool bestätigte die Hashes aller vier Segmente. Das geschützte Portal startete, die Einrichtung über das Standort-WLAN gelang, und der Live-Server nahm eine echte erste Messung von **23,5 °C / 46,6 % rF** an. Das Dashboard zeigte für **„Tiefkühlschrank“** diese Messung, `DHT22 · ready`, Konfiguration **übernommen · v1** sowie die vom Nutzer gesetzten Alarmgrenzen **−25 bis −18 °C**. Wegen der Messung bei Raumtemperatur ist „Über Maximum“ während des Banktests erwartbar. Danach wurde bei weiterhin abgetrennter Batterie per USB nur die Anwendung des **Batterieprofils ohne Spannungsmessung** (`esp32-wroom-dht22-battery-unmonitored`) geflasht: Eine neue gültige Messung von **23,50 °C / 46,70 % rF** wurde lokal eingereiht, für sie liegt noch kein Upload-ACK vor. Da die tatsächlich vorgesehene Versorgung noch ungeeignet ist, wurde anschließend wieder das individuelle **USB-Profil** als Anwendung geschrieben. Der Hash wurde verifiziert; der serielle Start und der DHT22-Datenpin wurden erneut bestätigt, ohne die Provisionierung zu löschen. Damit meldet das Gerät bis zur sicheren Umrüstung weiterhin Netzbetrieb. Tatsächlicher Batteriebetrieb, Deep Sleep mit diesem Profil und der Gefrierschrankaufbau sind an diesem Board noch nicht nachgewiesen; ein serieller ACK-Mitschnitt der ersten Messung liegt nicht vor.

Der Testserver enthält seit der Bereinigung am 24.09.2026 nur diese zwei echten ESP32-Geräte. Der Demo-Generator wurde gestoppt; drei Demo-Geräte und zwei ältere VPS-Testgeräte wurden nach privater Datenbanksicherung samt **40.068 synthetischen Messungen**, **80.068 Testübertragungen** und zwei daraus entstandenen Ereignissen entfernt. Ein alter abgelaufener Testexport wurde ebenfalls entfernt. Die Audit-Kette blieb erhalten und wurde nach der Bereinigung erfolgreich geprüft. Im Live-Dashboard sind nur **„ESP32 DHT22 Test“** und **„Tiefkühlschrank“** sichtbar. Neue Deployments auf diesen Testserver starten das optionale Demo-Profil nicht.

Im seriellen Monitor nach dem Start auf Sensorinitialisierung, gültige Temperatur und relative Luftfeuchtigkeit sowie den Warteschlangen-/Uploadstatus achten. Bei `NaN`, ungültigem Wertebereich oder wiederholtem DHT-Lesefehler Verkabelung, 3,3-V-Versorgung, Pull-up und Mindestabstand der Abfragen prüfen. Ein solcher Wert darf weder als gültige Messung in der Warteschlange noch im Dashboard auftauchen. Die erste DHT-Abfrage benötigt nach der Versorgung eine Anlaufzeit.

Im Dashboard anschließend kontrollieren, dass `DHT22`, die Boardkennung, der Messstellen-Code und die neue Messung mit plausibler Temperatur/Feuchte erscheinen. Das D1-mini-Profil meldet `ESP8266 D1 mini ESP8266MOD`; das ESP32-Profil meldet zunächst `ESP-WROOM-32 USB-C DevKit variant unverified`, bis die konkrete Devkit-Variante am angeschlossenen Board eindeutig geprüft und die Build-Kennung entsprechend angepasst wurde. Der Original-Zeitpunkt der Messung muss auch dann bestehen bleiben, wenn erst später hochgeladen wird.

Unter **Geräteeinstellungen → Messung und Übertragung** bietet das Dashboard Uploads alle **1, 5, 15, 30 oder 60 Minuten** sowie **12, 8, 6, 5, 4, 3, 2 oder 1 Mal täglich**. Das Messintervall wird getrennt eingestellt. Eine gespeicherte Änderung bleibt bis zur Bestätigung als ausstehend sichtbar: Die Firmware übernimmt sie beim nächsten erfolgreichen HTTPS-Kontakt, der ohne vorgezogenen Upload bis zum eingestellten Übertragungsintervall dauern kann; ein Netzausfall verlängert dies. Danach muss die neue Konfigurationsversion im Dashboard als vom Gerät angewendet erscheinen.

## 6. Offline-Warteschlange und ACK testen

Nach einer ersten erfolgreichen Messung den Zugang zum Standort-WLAN kurz unterbrechen, mindestens eine weitere Messung abwarten und das Gerät neu starten. Der Monitor muss die ausstehende Messung weiterhin als offen zeigen. WLAN wiederherstellen und Upload beobachten. Nur eine **exakte** `accepted`- oder identische `duplicate`-Bestätigung der jeweiligen Messung darf ihren lokalen Datensatz löschen; HTTP 200 allein reicht nicht. Im Dashboard sollten keine doppelten Messungen entstehen. Eine absichtlich volle Warteschlange darf ältere unbestätigte Messungen nicht überschreiben.

Der D1 mini speichert maximal **32** offene Datensätze. Das entspricht bei fünf Minuten Messintervall höchstens **2 h 40 min**, beim serverseitig zulässigen Minimum von 30 Sekunden nur **16 min** ohne erfolgreiche Übertragung. Das ESP32-Ziel hält **64** Datensätze vor, entsprechend **5 h 20 min** bei fünf Minuten oder **32 min** bei 30 Sekunden. Auf dem ESP32 wird schon bei **60 offenen Datensätzen** oder beim niedrigeren konfigurierten `max_batch_size` ein vorgezogener Uploadversuch ausgelöst; vier Plätze bleiben zunächst als Reserve. Bei weiterem Netzausfall fallen nach Erreichen der vollen Kapazität neue Abtastungen aus und erzeugen eine Diagnose, bis bestätigt hochgeladen werden kann. Diese Zahlen beschreiben nur die Speicherkapazität, keine garantierte Offline-Betriebsdauer.

Der D1 mini schätzt den ursprünglichen UTC-Zeitpunkt während Deep Sleep aus dem letzten NTP-Zeitanker und begrenzt diese Schätzung auf 24 Stunden beziehungsweise 288 Schlafzyklen. Vor jeder HTTPS-Verbindung fordert er eine frische NTP-Antwort an. Die Genauigkeit des Offline-Zeitankers und TLS-Speicherreserve auf der konkreten Platine müssen im Hardwaretest bestätigt werden. Meldet ein Gerät `STORAGE_FAILED`, keine neue Geräteidentität provisionieren oder das Dateisystem erneut hochladen; zuerst die gespeicherten Daten sichern und den Fehler untersuchen.

**Speicherprüfung der USB-Builds `0.4.1`:** Auf dem tatsächlich angeschlossenen ESP32 wurden 4 MiB Flash und 0 MiB PSRAM erkannt. `min_spiffs.csv` teilt den Flash unter anderem in 20 KiB NVS, zwei Anwendungsslots zu je 1.966.080 Byte und 128 KiB SPIFFS auf; die ESP32-Anwendung belegt 1.032.000 Byte eines Slots (rund 52,5 %). Beim ersten übertragenen Datensatz des zweiten Boards meldete die Laufzeitdiagnose **235.632 Byte freien Heap**. Das `d1_mini`-Profil setzt 4 MiB Flash voraus, davon rund 1.044.464 Byte für den Sketch und 1.024.000 Byte LittleFS; die Anwendung belegt 512.960 Byte des Sketchbereichs (rund 49,1 %). Statische Daten des D1-Builds belegen rund 42,4 KiB von 80 KiB RAM. Sein **freier Heap unter WLAN/BearSSL-Last** und der tatsächliche Flash-Ausbau sind noch nicht an einem D1 mini gemessen; der erfolgreiche Build allein belegt diese Laufzeitreserve nicht. Beide Firmwares melden den freien Heap bei einer Verbindung als `device_info.heap_free_bytes`.

## 7. Deep Sleep erst nach dem USB-Test einschalten

Am ESP32 die Sensor- und Aufwachzyklen zunächst mit `esp32-wroom-dht22` prüfen. Dieses USB-Testprofil verwendet Light Sleep beziehungsweise einen begrenzten Neustart als Rückfallpfad; der serielle Port kann dabei kurz verschwinden und wieder auftauchen. Das gesonderte Profil `esp32-wroom-dht22-sleep` aktiviert danach den Timer Deep Sleep; eine zusätzliche Drahtbrücke ist nicht erforderlich:

```bash
pio run -d firmware/esp32-s3 -e esp32-wroom-dht22-sleep -t upload --upload-port "$PORT"
```

Am D1 mini **zuerst** die Verbindung `D0`/`GPIO16` → `RST` bei ausgeschalteter Versorgung herstellen, auf Kurzschluss prüfen und dann das gesonderte Sleep-Profil flashen. Im seriellen Monitor mindestens zwei Timer-Aufwachzyklen und die erneute Sensor-/Netzfunktion prüfen. Die Brücke bei jedem späteren Flash- oder Resetproblem als mögliche Ursache berücksichtigen; das Testprofil ohne Deep Sleep bleibt für USB-Diagnose verfügbar. Der ESP8266 wacht aus Deep Sleep per Reset auf, nicht durch den ESP32-Wake-Pfad.

```bash
pio run -d firmware/esp8266-d1-mini -e d1-mini-dht22-sleep -t upload --upload-port "$PORT"
```

## Zweites Gerät: Batterieprofil ohne Spannungsmessung

Für ein extern versorgtes DHT22-Gerät gibt es **eigene** Buildprofile. Das ESP32-Normalprofil wurde am zweiten Board bislang nur über USB mit abgetrennter Batterie getestet und danach wieder durch das USB-Profil ersetzt; echter Batteriebetrieb und das zugehörige Deep-Sleep-Profil sind noch ungeprüft. Auch das D1-mini-Batterieprofil wurde noch nicht an Hardware getestet. Die Profile melden `battery_mv: null` und die Fähigkeit `battery_power_unmonitored`. Das Dashboard zeigt **Batteriebetrieb · Batteriewert nicht verfügbar** und löst keinen Alarm wegen niedriger Batteriespannung aus. Daraus lässt sich weder Ladezustand noch Restlaufzeit ableiten; hierfür wäre eine gesondert verdrahtete und kalibrierte Spannungsmessung nötig.

**Sperrhinweis für den zweiten ESP32:** Der vorhandene Dreizellenpack liefert ungefähr **3,75 V** und ist für einen direkten Anschluss an `5V/VIN` vorgesehen. **So nicht anschließen oder einschalten.** Eine bereits an `5V/VIN` liegende Batterieleitung vor jedem USB-/Powerbank-Betrieb abklemmen und isolieren. Erst ein geeigneter, geregelter **5-V-Aufwärtswandler (Booster)** zwischen Pack und dem für das konkrete Board verifizierten 5-V-/VIN-Eingang oder eine geeignete **5-V-USB-Powerbank** schafft einen möglichen Versorgungsweg. Vorher Pinbeschriftung, Polarität, Wandlerausgang unter Last und Board-Schaltplan prüfen; der Regler und die Stromversorgung des generischen USB-C-Devkits sind nicht eindeutig identifiziert. Den DHT22 weiter an **3V3** betreiben. Während USB-Flash und seriellem Test den Batteriepfad vollständig trennen; **USB und externe 5 V am Header niemals gleichzeitig anschließen**. [Espressif nennt diese Versorgungswege bei seinem DevKitC ausdrücklich gegenseitig ausschließend](https://docs.espressif.com/projects/esp-dev-kits/en/latest/esp32/esp32-devkitc/user_guide.html); das fotografierte USB-C-Board ist damit nicht als DevKitC identifiziert. Die Laufzeit eines generischen ESP32- oder D1-mini-Devkits muss mit dem tatsächlichen Akku, Wandler und Mess-/Uploadtakt gemessen werden; aus der Firmware lässt sie sich nicht verlässlich versprechen.

`PORT` ist der zuvor per USB ermittelte serielle Port. Zuerst die Normalprofile über USB prüfen. Auf einem fabrikneuen D1 mini das einmalige `uploadfs` aus Schritt 3 vor dem ersten Firmware-Upload ausführen, danach beim Profilwechsel nicht wiederholen.

```bash
# ESP32: Batterie ohne Spannungsmessung, USB-Test ohne Deep Sleep
pio run -d firmware/esp32-s3 -e esp32-wroom-dht22-battery-unmonitored
pio run -d firmware/esp32-s3 -e esp32-wroom-dht22-battery-unmonitored -t upload --upload-port "$PORT"

# D1 mini: Batterie ohne Spannungsmessung, USB-Test ohne Deep Sleep
pio run -d firmware/esp8266-d1-mini -e d1-mini-dht22-battery-unmonitored
pio run -d firmware/esp8266-d1-mini -e d1-mini-dht22-battery-unmonitored -t upload --upload-port "$PORT"
```

Die Sleep-Varianten erst nach dem Aufwachtest aus Schritt 7 flashen. Beim D1 mini muss dafür **D0/GPIO16 → RST** verbunden sein.

```bash
# ESP32: Deep Sleep, keine D0-RST-Brücke
pio run -d firmware/esp32-s3 -e esp32-wroom-dht22-battery-unmonitored-sleep
pio run -d firmware/esp32-s3 -e esp32-wroom-dht22-battery-unmonitored-sleep -t upload --upload-port "$PORT"

# D1 mini: Deep Sleep, erst mit D0/GPIO16 → RST
pio run -d firmware/esp8266-d1-mini -e d1-mini-dht22-battery-unmonitored-sleep
pio run -d firmware/esp8266-d1-mini -e d1-mini-dht22-battery-unmonitored-sleep -t upload --upload-port "$PORT"
```

## Referenzen

- [PlatformIO: `esp32dev`](https://docs.platformio.org/en/stable/boards/espressif32/esp32dev.html) und [`d1_mini`](https://docs.platformio.org/en/stable/boards/espressif8266/d1_mini.html): Board-IDs und nominelle Speicherausstattung.
- [PlatformIO: serielle Ports anzeigen](https://docs.platformio.org/en/stable/core/userguide/device/cmd_list.html) und [Espressif esptool: Flash-ID](https://docs.espressif.com/projects/esptool/en/latest/esp32/esptool/basic-commands.html): USB-/Flash-Prüfung.
- [ESP8266 Arduino Core: Deep Sleep](https://arduino-esp8266.readthedocs.io/en/3.0.0/libraries.html): `GPIO16` muss zum Timer-Aufwachen mit `RST` verbunden sein.
- [Adafruit: DHT22/AM2302](https://learn.adafruit.com/dht): Pull-up und Mindestabstand der Abfragen. Für die konkret gekaufte dreipolige Platine sind deren aufgedruckte Pin-Namen maßgeblich.
