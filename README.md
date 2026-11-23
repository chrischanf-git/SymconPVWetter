# DWD Weather PV – IP-Symcon Modul

**GitHub-installierbare Modulbibliothek für IP-Symcon ≥ 8.1**. Überführt das bisherige DWD-MOSMIX/PV-Skript V3.5 in eine Instanz mit konfigurierbaren Eigenschaften, eigenem WebHook, Timersteuerung und von anderen Instanzen getrennten Daten.

## Funktionen

| Kachel | Inhalt |
| --- | --- |
| 01 · Wettervorhersage (7 Tage) | MOSMIX, Tageswerte, anklickbarer DWD-Warnstatus |
| 02 · Wetterdiagramme (24 Stunden) | Temperatur, Regen, Sonnenschein, Strahlung, 2-Stunden-Tabelle, Warn-Link |
| 03 · Wetterwarnungen | Amtliche Warnungen für konfigurierbares Warngebiet, Warnfarben und Aktualitätsstatus |
| 04 · PV-Ertragsprognose | 24 h, morgen, 7 Tage; Standort-/Anlagenwerte aus der Instanz |
| 05 · Wettermodelle im Vergleich | MOSMIX, ICON-D2, ECMWF; Button für manuellen Abruf |
| 06 · PV-Prognose und Ist-Vergleich | Tages-/Gesamtertrag, PV-Leistung, Historie und Lernfaktor |
| 07 · PV-Leistungsprognose (24 Stunden) | Stündliche Wattwerte, 3/6/24-h-kWh, Modellqualität |

Alle Kacheln verwenden Schwarz/Dunkelgrau und kleinere Eckradien; Wetterwarnungen behalten ihre Warnfarben. Der Ortsname kommt aus `DW_ORT` im Konfigurationsformular.

## GitHub-Installation (einmalig)

1. Ein **neues GitHub-Repository** erstellen, beispielsweise `symcon-dwd-weather-pv`. Dieses Repository sollte bei der erstmaligen Veröffentlichung **nicht mit einer README-Datei vorgefüllt** werden, um Merge-Konflikte zu vermeiden.
2. **Alle Dateien und Ordner aus dem entpackten Paket** ins **Wurzelverzeichnis** des Repositories hochladen, sodass sich `library.json` direkt im Repository-Hauptverzeichnis befindet, daneben `DWDWeather/module.php`, `DWDWeather/module.json`, `DWDWeather/form.json` und `libs/ForecastEngine.php`.
3. Änderungen auf dem Branch `main` committen. Eine ZIP-Datei **als einzelnes Repository-Asset** einzustellen genügt *nicht*; IP-Symcon braucht die Verzeichnisstruktur im Repository.
4. In IP-Symcon die **Konsole → Kern-Instanzen → Modules (Module Control)** öffnen. Mit **+** die URL des Repositories hinzufügen, etwa `https://github.com/DEIN-NAME/symcon-dwd-weather-pv`.
5. Über **Instanz hinzufügen** eine neue Geräteinstanz **`DWDWeather`** (Hersteller `DWD Wetter PV`) erstellen.
6. Die neue Instanz öffnen. Die Einstellungen für Standort, PV-Anlage und Messvariablen kontrollieren und **Übernehmen**. Es werden sieben Statusvariablen für Kacheln, Mess-/Prognosewerte und eine Unterkategorie mit 24 Stundenwerten angelegt.
7. Die neuen HTML-Variablen `DWD_KACHEL_*` in der Kachelvisualisierung einbinden. Im Formular oder in Kachel 05 auf „Wetterdaten jetzt abrufen“ klicken. Der erste Abruf kann wegen der DWD-KMZ-Downloads etwas dauern.

### Voreinstellungen im Paket

- Wetterstandort **Niederdorf / Erzgebirge (09366)**; Koordinaten 50.7242 / 12.7834, Höhe 400 m
- DWD-Warngebiet **Erzgebirgskreis** (WarnCell-ID `114521000`)
- PV **22,5 kWp**, 25° Dachneigung, Azimut **+20° ab Süd**, PR 0,82
- Quellvariablen: Tagesertrag **16425** (Wh), Gesamtertrag **49528** (Wh), PV-Momentanleistung **23727** (W)

Diese Werte stammen aus der zuletzt verwendeten Skriptkonfiguration und sind **in der Modulinstanz frei änderbar**. Für andere Anlagen bitte vollständig anpassen; die Variablen-IDs sind für jede Symcon-Installation verschieden.

**Standortwechsel:** `DW_ORT` ändert ausschließlich die angezeigte Bezeichnung. Für einen echten Standortwechsel `DW_LAT`, `DW_LON`, `DW_HOEHE_M` und ggf. `DW_WARNCELL_ID`/`DW_WARNGEBIET_NAME` anpassen. Die Eingabe einer PLZ allein löst derzeit **keine** Geocodierung aus.

**PV-Ausrichtung:** +90° = West, 0° = Süd, −90° = Ost (Open-Meteo-Orientierung). Installierte Modulleistung in **kWp**, nicht die Wechselrichtergröße.

## Vorheriges Skript V3.5 migrieren

Beim Wechsel werden **neue Variablen unter der Modulinstanz** angelegt. Das ist beabsichtigt: Die bisherige Kategorie/Variablen-IDs werden nicht überschrieben. Um die bisherige selbstgelernte PV-Historie und die zwischengespeicherten Wetterdaten zu übernehmen:

1. Sicherung der Symcon-Konfiguration erstellen.
2. In der alten Skript-Kategorie die drei Ereignisse **`DWD_EVENT_FORECAST`**, **`DWD_EVENT_WARN`** und **`DWD_EVENT_PV_IST`** deaktivieren. Das frühere Skript `49498` nicht gleichzeitig mit dem Modul laufen lassen.
3. Neue Instanz öffnen, PV-/Standortparameter einstellen und speichern.
4. Im Formular **„Erweitert / Migration“** bei „Alte DWD-Kategorie“ die bisherige Kategorie auswählen. Laut vorheriger Skriptausgabe war das **37851**.
5. **„Alte Wetter- und PV-Lerndaten importieren“** einmal betätigen. Übertragen werden Cache-JSON und Zeitstempel, MOSMIX-Stationswahl und vor allem `DWD_PV_IST_STATE_JSON` (Lernhistorie). Der Import verändert keine alte Variable.
6. Datenabruf starten und die neuen Statuswerte prüfen; bestehende Kachelvisualisierungen, KNX-Verknüpfungen und Skripte auf die **neuen Variablen-IDs unter der Modulinstanz** umstellen.
7. Danach den alten WebHook `/hook/dwd-mosmix-49498` bei Bedarf aus **WebHook Control** entfernen. Den alten Skriptstand bis zur Prüfung als Backup aufheben.

**Wichtig:** Die Übernahme ist ein Kopieren des bestehenden Lernspeichers, keine Garantie auf identische alte Objekt-IDs. Eine Verknüpfung über den bisherigen Variablen-ID-Wert wird durch die Modulinstallation nicht automatisch umgestellt. Wenn du die PV-Anlagenparameter nach dem Import änderst, wird die Lernstatistik aus Sicherheitsgründen zurückgesetzt.

## Zeitsteuerung

Das Modul erstellt eigene Timer: MOSMIX/ICON ca. alle 3 Stunden, DWD-Warnungen alle 15 Minuten, PV-Istvergleich alle 5 Minuten (einstellbar). ECMWF wird zusätzlich etwa alle 6 Stunden abgefragt. Im Modul gibt es einen manuellen Abruf mit 90-Sekunden-Sperrfrist. Beim ersten Start erfolgt ein Abruf nach ca. fünf Sekunden.

Jede Instanz hat einen eigenen WebHook `/hook/dwd-weather-<InstanzID>`. Ein POST zum Aktualisieren benötigt den zufälligen Instanz-Token, der **nur intern als Symcon-Attribut** gespeichert wird. Warn-Links sind HMAC-geschützte schreibgeschützte GET-Ansichten. **Niemals ungefiltert die Symcon-WebHook-Control ins öffentliche Internet freigeben.**

## PV-Variablen für Automationen

- `DWD_PV_LEISTUNG_24H_VALID` → **true** nur bei vollständiger, noch gültiger 24-h-Prognose
- `DWD_PV_24H_QUALITAET` → **0** ungültig / **1** normal / **2** gültiger Cache nach Fehler oder Ersatzmodell
- `DWD_PV_LEISTUNG_H01_W` … `DWD_PV_LEISTUNG_H24_W` → Watt pro voller Stunde in der Unterkategorie
- `DWD_PV_LEISTUNG_NAECHSTE_STUNDE_W`, `DWD_PV_ERTRAG_3H_KWH`, `DWD_PV_ERTRAG_6H_KWH`, `DWD_PV_ERTRAG_24H_KWH_EXAKT` → weitere Automationswerte

Bei nicht nutzbaren Prognosen werden der Gültigkeitsstatus auf **false** und die Watt-Automationswerte auf **0** gesetzt. **Eine 0 ohne Gültigkeitsprüfung ist kein prognostizierter Nullertrag.** Niemals Verbraucher ausschließlich nach dieser Prognose schalten, sondern Liveleistung/Hausverbrauch und ggf. Ladezustand mit berücksichtigen.

## Aktualisierungen per GitHub

Änderungen an `DWDWeather/module.php`, `libs/ForecastEngine.php` oder dem Formular im Repository committen/pushen. In IP-Symcon unter **Kern-Instanzen → Modules** **„Auf Aktualisierung prüfen“** und anschließend **Aktualisieren**. Einstellungen werden in der Modulinstanz gespeichert; bei reinen Code-Updates bleiben diese erhalten. Im GitHub-Repository kann ein `dev`-Branch für Tests und `main` für die stabile Symcon-Version verwendet werden. Für veröffentlichte Releases `version`/`build` in `library.json` erhöhen.

Die GitHub-Actions-Konfiguration prüft PHP-Syntax, JSON-Struktur und einen simulierten Symcon-Testlauf ohne externe HTTP-Requests.

## Datenquellen, Lizenzen und Einschränkungen

- **DWD MOSMIX_L** (KML/KMZ): `opendata.dwd.de` und der Stationskatalog vom DWD
- **DWD Warnungen**: WarnWetter JSON vom DWD; das Warngebiet gilt meist für den gesamten Landkreis
- **ICON-D2 und ECMWF**: derzeit über die API von **Open-Meteo**; die frei nutzbare API richtet sich an **nichtkommerzielle Nutzung**. Für gewerblichen Einsatz einen gebuchten API-Key in der Instanz hinterlegen und die private Nutzung deaktivieren.
- Ein Modellvergleich ist keine amtlich kalibrierte Vorhersagewahrscheinlichkeit. PV-Prognosen sind Näherungen aus Einstrahlung, Dachparametern, PR und Lernfaktor.
- Der GitHub-Paketcode wurde mit PHP 8.4 syntaxgeprüft und mit simulierten Symcon-Funktionen getestet. **Ein Live-Test auf deinem IP-Symcon-Server und echte DWD-/Open-Meteo-Abrufe sind noch nicht durchgeführt.**

Dieser Quellcode steht unter der MIT-Lizenz. Rechte und Lizenzbedingungen der externen Wetterdaten/Datenanbieter bleiben davon unberührt.
