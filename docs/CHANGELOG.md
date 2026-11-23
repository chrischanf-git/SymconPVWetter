# Änderungen

## 1.0.3 – Kartenbasierte Standortwahl

- Symcon-Element `SelectLocation` in der Instanzkonfiguration eingebaut.
- Button für einmalige Ortsnamens- und DWD-Warngebietszuordnung (OpenStreetMap/Nominatim, DWD Warncell-Katalog).
- Koordinaten aus Kartenpunkt; Stationshöhe als ausdrücklich gekennzeichnete grobe Näherung.
- Keine veraltete Warnregion beim Standortwechsel übernehmen; unklare Warngebiete müssen bestätigt werden.
- MOSMIX-Stationsauswahl bleibt automatisch im bestehenden Wetterabruf.
- Keine Standort-, PV- oder Messvariablen-Vorgaben für neue Instanzen; Bestand bleibt erhalten.

# Version 1.0.2 (23.11.2026)

- Neue Instanzen enthalten keine individuellen Standardwerte fuer Ort, Koordinaten, Warngebiet, PV-Leistung, Dachparamater, Symcon-Variablen-IDs oder Messeinheiten.
- Auch erweiterte Zahlenfelder beginnen bei 0; interne, feste Betriebsparameter werden erst bei der Ausfuehrung als sichere Standardwerte verwendet.
- Die Einheit-Auswahl zeigt zunaechst "Bitte Einheit waehlen"; Modellnutzung und private API-Nutzungsbestätigung sind zunaechst deaktiviert.
- Solange Pflichtangaben fehlen, bleiben Instanzstatus 201, alle Timer deaktiviert und PV-Automationsfreigaben gesperrt.
- Loescht man Pflichtangaben einer bereits aktiven Instanz, werden alte Automationsfreigaben auf ungueltig gesetzt, ohne Cache und PV-Lernhistorie zu loeschen.
- Bereits gespeicherte Konfigurationen bestehender Instanzen werden beim Update nicht ueberschrieben.
- Symcon-NumberSpinner kann numerisch nicht wirklich leer sein; 0 kennzeichnet den nicht voreingestellten Zustand.

# Version 1.0.1 (23.11.2026)

- Der zusaetzliche Alias `DWD MOSMIX Wetter PV` wurde entfernt.
- Der Auswahlname `DWD Wetter und PV` bleibt erhalten.
- Interner Modulname, GUID, Klasse und Datenverarbeitung bleiben identisch.
- Ein bereits angelegtes Modul oder seine Konfiguration muss nicht neu erstellt werden.

Hinweis: `DWDWeather` bleibt der interne Name und kann von Symcon weiterhin in technischen Ansichten angezeigt werden.
