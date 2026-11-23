# Migration vom Skript V3.5 zum Symcon-Modul

- Vorher ein Backup von Symcon erstellen.
- Altes Skript `49498` und seine drei zyklischen Ereignisse deaktivieren.
- Neue Modulinstanz anlegen und die Einstellungen (insbesondere 22,5 kWp / 25° / +20° sowie Messwert-IDs) prüfen.
- Im Konfigurationsformular `LegacyCategoryID` mit der bisherigen Wetterkategorie **37851** belegen und übernehmen.
- Einmalig `DWDW_ImportLegacy(<neue Instanz-ID>);` über die Modulaktion ausführen.
- Das Modul kopiert `DWD_PV_IST_STATE_JSON`, Prognose-Zeitstempel und Wetter-Caches. Es verschiebt **keine** alten Objekte oder Variablen-IDs.
- Anschließend `DWDW_RequestRefresh(<neue Instanz-ID>);` aufrufen.
- In der Kachelvisualisierung die neuen `DWD_KACHEL_*`-Variablen auswählen. KNX- oder Wallbox-Automationen auf neue Modulvariablen umstellen und Freigabe-/Qualitätswerte berücksichtigen.
- Alten WebHook `/hook/dwd-mosmix-49498` optional im WebHook Control entfernen; neuen `/hook/dwd-weather-<InstanzID>` verwenden.
- Das alte Skript und die Kategorie nicht löschen, bevor die Kacheln, Werte und PV-Historie geprüft wurden.

Bei geänderten Standort-/PV-Parametern wird der Cache bzw. Lernspeicher zurückgesetzt. Deshalb **zuerst die Modulkonfiguration abschließend festlegen und dann importieren**.
