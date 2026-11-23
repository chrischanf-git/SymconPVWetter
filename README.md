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
6. Neue Instanz öffnen. Sie enthält **keine Standort-, PV- oder Messvariablen-Vorgaben**. Unter **Standort und Warnungen** auf **Standort auf Karte auswählen** klicken, den Ort markieren, dann **Standort aus Karte übernehmen und Daten ermitteln** anklicken. Die Suche läuft **nur nach dem Button-Klick**, nicht zyklisch. Anschließend das Formular neu öffnen, Ort, Koordinaten, Warngebiet und ungefähre Höhe kontrollieren. Wenn die Warnregion nicht eindeutig bestimmt werden konnte, die DWD-WarnCell-ID **manuell** ergänzen. Dann PV-Leistung, Neigung, Ausrichtung, PR, Messvariablen und Einheiten ausfüllen und **Übernehmen**. Erst dann werden sieben Statusvariablen für Kacheln, Mess-/Prognosewerte und eine Unterkategorie mit 24 Stundenwerten angelegt.
7. Die neuen HTML-Variablen `DWD_KACHEL_*` in der Kachelvisualisierung einbinden. Im Formular oder in Kachel 05 auf „Wetterdaten jetzt abrufen“ klicken. Der erste Abruf kann wegen der DWD-KMZ-Downloads etwas dauern.

### Ersteinrichtung ohne voreingetragene Werte (ab V1.0.3)

**Neue Instanzen** haben keine vorgegebenen Standortdaten, Koordinaten, DWD-Warngebiet-ID, PV-Anlagenparameter, Messvariablen-IDs, Einheiten oder Zustimmungen zur API-Nutzung. Eine neue Instanz befindet sich zunaechst im Status **Konfiguration unvollstaendig (201)**. Die Timer bleiben bis zur vollstaendigen Einrichtung ausgeschaltet, und es werden keine Prognosewerte freigegeben.

**Pflichtangaben:** Ortsbezeichnung; Breitengrad und Laengengrad; DWD-WarnCell-ID und Warngebietsname; PV-kWp und Performance Ratio; drei Messvariablen und deren Einheiten. Dachneigung und Azimut koennen legitim 0 sein (Flachdach/Suedausrichtung). Die Hoehe, MOSMIX-Ersatzstation und das AC-Limit sind optional. Eine MOSMIX-Station wird automatisch bestimmt, wenn das Stationsfeld leer bleibt.

**Technische Besonderheit von IP-Symcon:** `NumberSpinner`-Felder koennen keinen wirklich leeren numerischen Standardwert anzeigen. Dort steht deshalb **0 = noch keine individuelle Eingabe**. In den erweiterten Einstellungen gelten bei 0 *intern* die sicheren Betriebswerte (z.B. 7 Tage, 24 Stunden, 20 s HTTP-Timeout, 90 s manuelle Sperrfrist und 5 min Messintervall). Diese werden nicht als persoenliche Anlagenwerte in der Instanz hinterlegt. Checkboxen sind nicht markiert. Die Einheitenauswahl beginnt bei „Bitte Einheit waehlen“.

**Open-Meteo:** ICON-D2 und ECMWF sind bei einer neuen Instanz zunaechst deaktiviert. Erst nach eigener Wahl und Pruefung der erlaubten API-Nutzung aktivieren; fuer kommerzielle Nutzung ist ein passender API-Zugang noetig.

**Beim Update bereits bestehender Instanzen** werden gespeicherte Werte **nicht** automatisch entfernt. Das ist Absicht, damit Standort, PV-Parameter, Messvariablen und Prognosehistorie erhalten bleiben. Fuer eine vollstaendig leere Konfiguration bitte eine *neue* Instanz erstellen oder die bestehenden Felder bewusst zuruecksetzen; ein Modul-Update ist kein Werksreset.

**Standortwechsel per Karte (ab V1.0.3):** `SelectLocation` ist die in Symcon integrierte Karte (Internetverbindung für die Karte erforderlich). Nach Kartenwahl **„Standort aus Karte übernehmen und Daten ermitteln“** anklicken. Das Modul liest Koordinaten aus der Karte, fragt den Ortsnamen einmalig über OpenStreetMap/Nominatim ab, sucht eine eindeutige DWD-WarnCell-ID im DWD-Katalog (oder verwendet wenige fest verifizierte eindeutige Gebiete), und ermittelt eine **ungefähre** Höhe aus dem nächstgelegenen DWD-MOSMIX-Stationskatalog. MOSMIX wählt seinen Prognosepunkt beim ersten regulären Wetterabruf selbst. **Keine automatische Ermittlung von Dachneigung, Ausrichtung, kWp und Messvariablen** aus einer Karte: diese müssen weiterhin manuell angegeben werden. Bei nicht verfügbarem Online-Dienst oder mehrdeutiger Warnregion bleiben WarnCell-ID und ggf. Ortsname leer; es gibt dann ausdrücklich keine falsche Warnungsentwarnung. Bereits gespeicherte Konfigurationen bleiben beim Update erhalten; erst eine neue Kartenwahl + Button ändert sie. Manuelle Koordinatenkorrekturen sind weiterhin möglich.

**Nominatim-Hinweis:** Standortsuche ist ein **einmaliger nutzerinitiierter Aufruf** (keine Vorschlagslisten/Autocomplete, keine Hintergrundabfragen). Das Modul sendet einen identifizierenden User-Agent, drosselt Serveranfragen auf maximal einmal je Sekunde über eine Sperrdatei und vermeidet systematische Abfragen. Nutzungsbedingungen der öffentlichen Instanz beachten: https://operations.osmfoundation.org/policies/nominatim/ . © OpenStreetMap-Mitwirkende, ODbL. Bei Ausfällen Ortsdaten bitte manuell eintragen.

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
