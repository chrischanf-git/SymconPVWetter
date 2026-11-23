<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/ForecastEngine.php';

/**
 * Instanzfähiges DWD MOSMIX / ICON-D2 / ECMWF Wetter- und PV-Modul.
 * Basiert auf dem bisherigen Standalone-Skript V3.5.
 * Minimum: IP-Symcon 8.1 wegen IPSModuleStrict + nativen WebHooks.
 */
class DWDWeather extends IPSModuleStrict
{
    use DWDWeatherEngine;

    private const PREFIX = 'DWDW';

    private const PROPERTY_TYPES = ['DW_MAP_LOCATION'=>'String', 'DW_ORT'=>'String', 'DW_LAT'=>'Float', 'DW_LON'=>'Float', 'DW_HOEHE_M'=>'Integer', 'DW_WARNCELL_ID'=>'String', 'DW_WARNGEBIET_NAME'=>'String', 'DW_STATION_MANUELL'=>'String', 'DW_FALLBACK_STATION'=>'String', 'DW_FALLBACK_NAME'=>'String', 'DW_TAGE'=>'Integer', 'DW_STUNDEN'=>'Integer', 'DW_PV_KWP'=>'Float', 'DW_PV_TILT'=>'Integer', 'DW_PV_AZIMUTH'=>'Integer', 'DW_PV_PR'=>'Float', 'DW_PV_AC_LIMIT_W'=>'Integer', 'DW_VISU_ID'=>'Integer', 'DW_MANUELL_MIN_ABSTAND_S'=>'Integer', 'DW_HTTP_TIMEOUT_S'=>'Integer', 'DW_HTTP_RETRY_PAUSE_MS'=>'Integer', 'DW_OM_ENABLE'=>'Boolean', 'DW_OM_API_KEY'=>'String', 'DW_OM_PRIVATE_USE_ONLY'=>'Boolean', 'DW_PV_ID_TAGESERTRAG'=>'Integer', 'DW_PV_ID_GESAMTERTRAG'=>'Integer', 'DW_PV_ID_LEISTUNG'=>'Integer', 'DW_PV_UNIT_TAGESERTRAG'=>'String', 'DW_PV_UNIT_GESAMTERTRAG'=>'String', 'DW_PV_UNIT_LEISTUNG'=>'String', 'DW_PV_LERN_MIN_TAGE'=>'Integer', 'DW_PV_HISTORIE_TAGE'=>'Integer', 'DW_PV_MESSINTERVALL_MIN'=>'Integer'];
    // 0 in den erweiterten Feldern bedeutet: keine individuelle Vorgabe.
    // Die Betriebswerte gelten intern, ohne die Instanzkonfiguration vorzubelegen.
    private const INTERNAL_DEFAULTS = [
        'DW_TAGE' => 7,
        'DW_STUNDEN' => 24,
        'DW_MANUELL_MIN_ABSTAND_S' => 90,
        'DW_HTTP_TIMEOUT_S' => 20,
        'DW_HTTP_RETRY_PAUSE_MS' => 1500,
        'DW_PV_LERN_MIN_TAGE' => 5,
        'DW_PV_HISTORIE_TAGE' => 120,
        'DW_PV_MESSINTERVALL_MIN' => 5,
    ];
    private array $ids = [];

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('DW_MAP_LOCATION', '{"latitude":0,"longitude":0}');
        $this->RegisterPropertyString('DW_ORT', '');
        $this->RegisterPropertyFloat('DW_LAT', 0.0);
        $this->RegisterPropertyFloat('DW_LON', 0.0);
        $this->RegisterPropertyInteger('DW_HOEHE_M', 0);
        $this->RegisterPropertyString('DW_WARNCELL_ID', '');
        $this->RegisterPropertyString('DW_WARNGEBIET_NAME', '');
        $this->RegisterPropertyString('DW_STATION_MANUELL', '');
        $this->RegisterPropertyString('DW_FALLBACK_STATION', '');
        $this->RegisterPropertyString('DW_FALLBACK_NAME', '');
        $this->RegisterPropertyInteger('DW_TAGE', 0);
        $this->RegisterPropertyInteger('DW_STUNDEN', 0);
        $this->RegisterPropertyFloat('DW_PV_KWP', 0.0);
        $this->RegisterPropertyInteger('DW_PV_TILT', 0);
        $this->RegisterPropertyInteger('DW_PV_AZIMUTH', 0);
        $this->RegisterPropertyFloat('DW_PV_PR', 0.0);
        $this->RegisterPropertyInteger('DW_PV_AC_LIMIT_W', 0);
        $this->RegisterPropertyInteger('DW_VISU_ID', 0);
        $this->RegisterPropertyInteger('DW_MANUELL_MIN_ABSTAND_S', 0);
        $this->RegisterPropertyInteger('DW_HTTP_TIMEOUT_S', 0);
        $this->RegisterPropertyInteger('DW_HTTP_RETRY_PAUSE_MS', 0);
        $this->RegisterPropertyBoolean('DW_OM_ENABLE', false);
        $this->RegisterPropertyString('DW_OM_API_KEY', '');
        $this->RegisterPropertyBoolean('DW_OM_PRIVATE_USE_ONLY', false);
        $this->RegisterPropertyInteger('DW_PV_ID_TAGESERTRAG', 0);
        $this->RegisterPropertyInteger('DW_PV_ID_GESAMTERTRAG', 0);
        $this->RegisterPropertyInteger('DW_PV_ID_LEISTUNG', 0);
        $this->RegisterPropertyString('DW_PV_UNIT_TAGESERTRAG', '');
        $this->RegisterPropertyString('DW_PV_UNIT_GESAMTERTRAG', '');
        $this->RegisterPropertyString('DW_PV_UNIT_LEISTUNG', '');
        $this->RegisterPropertyInteger('DW_PV_LERN_MIN_TAGE', 0);
        $this->RegisterPropertyInteger('DW_PV_HISTORIE_TAGE', 0);
        $this->RegisterPropertyInteger('DW_PV_MESSINTERVALL_MIN', 0);
        $this->RegisterPropertyInteger('LegacyCategoryID', 0);
        $this->RegisterAttributeString('ControlToken', '');
        $this->RegisterAttributeInteger('LastManual', 0);
        $this->RegisterAttributeString('ConfigFingerprint', '');
        $this->RegisterAttributeBoolean('LegacyImported', false);
        $this->RegisterAttributeString('ResolvedMapLocation', '');
        $this->RegisterAttributeString('ResolvedLocationSummary', '');

        $this->RegisterTimer('ForecastTimer', 0, 'DWDW_ForecastTick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('WarningTimer', 0, 'DWDW_WarningTick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MeasureTimer', 0, 'DWDW_MeasureTick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('BootstrapTimer', 0, 'DWDW_BootTick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ManualTimer', 0, 'DWDW_ManualTick($_IPS[\'TARGET\']);');
        // Der eigene WebHook ist pro Instanz eindeutig und wird beim Loeschen freigegeben.
        $this->RegisterHook($this->hookName());
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (!$this->configurationValid()) {
            foreach (['ForecastTimer', 'WarningTimer', 'MeasureTimer', 'BootstrapTimer', 'ManualTimer'] as $timer) {
                $this->SetTimerInterval($timer, 0);
            }
            $this->invalidateAutomationOutputs();
            $this->SetStatus(201);
            return;
        }
        if (!preg_match('~^[a-f0-9]{64}$~', $this->ReadAttributeString('ControlToken'))) {
            $this->WriteAttributeString('ControlToken', bin2hex(random_bytes(32)));
        }
        $this->ids = $this->ensureVariables();
        $this->RegisterHook($this->hookName());
        $fingerprint = hash('sha256', json_encode([
            $this->setting('DW_LAT'), $this->setting('DW_LON'), $this->setting('DW_HOEHE_M'),
            $this->setting('DW_STATION_MANUELL'), $this->setting('DW_WARNCELL_ID'),
            $this->setting('DW_PV_KWP'), $this->setting('DW_PV_TILT'),
            $this->setting('DW_PV_AZIMUTH'), $this->setting('DW_PV_PR'),
            $this->setting('DW_PV_AC_LIMIT_W'), $this->setting('DW_PV_ID_TAGESERTRAG'),
            $this->setting('DW_PV_ID_GESAMTERTRAG'), $this->setting('DW_PV_ID_LEISTUNG'),
            $this->setting('DW_PV_UNIT_TAGESERTRAG'), $this->setting('DW_PV_UNIT_GESAMTERTRAG'),
            $this->setting('DW_PV_UNIT_LEISTUNG'),
        ], JSON_THROW_ON_ERROR));
        $previous = $this->ReadAttributeString('ConfigFingerprint');
        if ($previous !== '' && $previous !== $fingerprint) {
            $this->clearLocationAndPvState();
            $this->dwLog('Standort/PV-Konfiguration geaendert: Prognosecache und Lernstatistik zurueckgesetzt.');
        }
        $this->WriteAttributeString('ConfigFingerprint', $fingerprint);
        $this->SetSummary($this->setting('DW_ORT') . ' | ' . $this->setting('DW_PV_KWP') . ' kWp');
        $this->SetTimerInterval('ForecastTimer', 3 * 3600 * 1000);
        $this->SetTimerInterval('WarningTimer', 15 * 60 * 1000);
        $this->SetTimerInterval('MeasureTimer', max(1, (int)$this->setting('DW_PV_MESSINTERVALL_MIN')) * 60 * 1000);
        $this->SetTimerInterval('BootstrapTimer', 5 * 1000);
        $this->SetStatus(102);
    }

    /**
     * Kartenauswahl in der Konsole. Die Position kommt direkt aus dem
     * SelectLocation-Feld (auch wenn das Formular noch nicht gespeichert wurde).
     * Nur auf ausdruecklichen Klick: keine Geo-Abfragen im Hintergrundbetrieb.
     *
     * Rueckgabe ist eine Kurzmeldung fuer das Konfigurationsformular.
     */
    public function ResolveLocation(string $locationJson): string
    {
        $coords = self::decodeMapLocation($locationJson);
        if ($coords === null) {
            return 'Bitte zuerst auf der Karte einen gueltigen Standort auswaehlen.';
        }
        [$lat, $lon] = $coords;
        $key = sprintf('%.5f,%.5f', $lat, $lon);
        if ($this->ReadAttributeString('ResolvedMapLocation') === $key) {
            return 'Standort bereits ermittelt: ' . $this->ReadAttributeString('ResolvedLocationSummary');
        }
        if (!IPS_SemaphoreEnter('DWDW_LOCATION_' . $this->InstanceID, 1000)) {
            return 'Standortsuche laeuft bereits. Bitte spaeter erneut versuchen.';
        }
        try {
            // Kein altes Warngebiet von einem anderen Standort uebernehmen.
            $place = '';
            $district = '';
            $warning = null;
            $height = null;
            $notes = [];
            try {
                $reverse = $this->dwReverseLocation($lat, $lon);
                $address = $reverse['address'] ?? [];
                if (!is_array($address)) $address = [];
                if (!in_array(strtolower((string)($address['country_code'] ?? '')), ['de'], true)) {
                    $notes[] = 'DWD-Warnungen nur fuer Deutschland verfuegbar';
                } else {
                    $district = trim((string)($address['county'] ?? $address['state_district'] ?? ''));
                    if ($district === '') {
                        $district = trim((string)($address['city'] ?? ''));
                        if ($district !== '') $district = 'Stadt ' . $district;
                    }
                    try {
                        $warning = $this->dwFindWarnCellForDistrict($district);
                    } catch (Throwable $e) {
                        $notes[] = 'Warngebiet konnte nicht online ermittelt werden';
                        $this->SendDebug('Standort/Warngebiet', $e->getMessage(), 0);
                    }
                }
                $place = trim((string)($address['city'] ?? $address['town'] ?? $address['village']
                    ?? $address['municipality'] ?? $address['hamlet'] ?? $address['suburb'] ?? ''));
                $postal = trim((string)($address['postcode'] ?? ''));
                if ($place !== '' && preg_match('/^\d{5}$/', $postal)) $place .= ' (' . $postal . ')';
                if ($place === '') $notes[] = 'Ortsname muss noch kontrolliert werden';
            } catch (Throwable $e) {
                $notes[] = 'Ortsnamenssuche nicht erreichbar: bitte Ort manuell ergaenzen';
                $this->SendDebug('Standort/Rueckwaertssuche', $e->getMessage(), 0);
            }
            // Die DWD-Stationshoehe ist eine NAeHERUNG; keine Punkt-Hoehenmessung.
            try {
                $height = $this->dwNearestStationHeight($lat, $lon);
            } catch (Throwable $e) {
                $notes[] = 'Standorthoehe nicht ermittelt (optional)';
                $this->SendDebug('Standort/Hoehe', $e->getMessage(), 0);
            }
            // Zuerst Karte/Koordinaten sichern, dann ermittelte Standortdaten.
            // Wenn Namens-/Warngebietserkennung scheitert, NICHT mit den
            // Angaben der vorherigen Position weiterarbeiten.
            IPS_SetProperty($this->InstanceID, 'DW_MAP_LOCATION', json_encode([
                'latitude' => $lat, 'longitude' => $lon
            ], JSON_THROW_ON_ERROR));
            IPS_SetProperty($this->InstanceID, 'DW_LAT', $lat);
            IPS_SetProperty($this->InstanceID, 'DW_LON', $lon);
            IPS_SetProperty($this->InstanceID, 'DW_ORT', $place);
            IPS_SetProperty($this->InstanceID, 'DW_WARNCELL_ID', $warning['id'] ?? '');
            IPS_SetProperty($this->InstanceID, 'DW_WARNGEBIET_NAME', $warning['name'] ?? '');
            IPS_SetProperty($this->InstanceID, 'DW_HOEHE_M', $height ?? 0);
            // Ueberreste manueller Stationen koennen den neuen Standort stoeren.
            IPS_SetProperty($this->InstanceID, 'DW_STATION_MANUELL', '');
            IPS_SetProperty($this->InstanceID, 'DW_FALLBACK_STATION', '');
            IPS_SetProperty($this->InstanceID, 'DW_FALLBACK_NAME', '');
            $this->WriteAttributeString('ResolvedMapLocation', $key);
            $summary = 'Karte: ' . $key . ' | Ort: ' . ($place ?: 'offen') .
                ' | Warngebiet: ' . ($warning['name'] ?? 'manuell pruefen') .
                ' | Hoehe: ' . ($height === null ? 'nicht ermittelt' : '~' . $height . ' m (Stationswert)');
            if ($notes) $summary .= ' | Hinweise: ' . implode('; ', $notes);
            $this->WriteAttributeString('ResolvedLocationSummary', $summary);
            IPS_ApplyChanges($this->InstanceID);
            return $summary . '. Formular bitte neu oeffnen und pruefen.';
        } finally {
            IPS_SemaphoreLeave('DWDW_LOCATION_' . $this->InstanceID);
        }
    }

    /** Strikt validierte Symcon-SelectLocation-Koordinaten. */
    public static function decodeMapLocation(string $json): ?array
    {
        $value = json_decode($json, true);
        if (!is_array($value) || !isset($value['latitude'], $value['longitude']) ||
            !is_numeric($value['latitude']) || !is_numeric($value['longitude'])) return null;
        $lat = (float)$value['latitude'];
        $lon = (float)$value['longitude'];
        if (!is_finite($lat) || !is_finite($lon) || $lat <= -90 || $lat >= 90 ||
            $lon <= -180 || $lon >= 180 || $lat == 0.0 || $lon == 0.0) return null;
        return [round($lat, 6), round($lon, 6)];
    }

    /**
     * Pro bewusstem Klick hoechstens EINE Reverse-Anfrage an Nominatim.
     * Anbieterregel: max. 1 Anfrage/s pro Installation, eigener User-Agent,
     * kein Autocomplete, keine periodischen Anfragen. OSM/ODbL-Attribution im Formular.
     */
    protected function dwReverseLocation(float $lat, float $lon): array
    {
        $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
            'format' => 'jsonv2', 'lat' => $lat, 'lon' => $lon,
            'zoom' => 10, 'addressdetails' => 1, 'accept-language' => 'de'
        ], '', '&', PHP_QUERY_RFC3986);
        // Installationweit synchronisieren (auch ueber mehrere Modulinstanzen).
        $path = sys_get_temp_dir() . '/dwdweather_nominatim_geocode.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) throw new RuntimeException('Nominatim-Sperrdatei nicht anlegbar');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Nominatim-Sperre nicht moeglich');
            $content = stream_get_contents($handle);
            $last = is_string($content) ? (float)trim($content) : 0.0;
            $wait = 1.1 - (microtime(true) - $last);
            if ($wait > 0) usleep((int)($wait * 1000000));
            rewind($handle); ftruncate($handle, 0);
            fwrite($handle, sprintf('%.6f', microtime(true)));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN); fclose($handle);
        }
        $raw = $this->dwLocationHttp($url, 300000, true);
        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['address']) || !is_array($json['address'])) {
            throw new RuntimeException('Ortsdaten nicht lesbar');
        }
        return $json;
    }

    protected function dwLocationHttp(string $url, int $maxBytes, bool $nominatim = false): string
    {
        $agent = $nominatim
            ? 'DWDWeather-IPSymcon/1.0.3 (IP-Symcon Standortauswahl; https://www.symcon.de)'
            : 'DWDWeather-IPSymcon/1.0.3';
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 8, 'ignore_errors' => false,
            'header' => "User-Agent: {$agent}\r\nAccept: application/json,text/plain,*/*\r\n"
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $raw = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
        if (!is_string($raw) || $raw === '' || strlen($raw) > $maxBytes) {
            throw new RuntimeException('Standort-Webdienst nicht erreichbar oder Antwort ungueltig');
        }
        return $raw;
    }

    /** Warncell-ID nur setzen, wenn sie mit dem DWD-Gebiet eindeutig uebereinstimmt. */
    protected function dwFindWarnCellForDistrict(string $district): ?array
    {
        if ($district === '') return null;
        // OFFLINE-Fallback fuer eindeutig benannte, amtliche sächsische Warnkreise.
        $known = [
            'erzgebirgskreis' => ['id' => '114521000', 'name' => 'Erzgebirgskreis'],
            'stadt chemnitz' => ['id' => '114511000', 'name' => 'Stadt Chemnitz'],
            'stadt dresden' => ['id' => '114612000', 'name' => 'Stadt Dresden'],
            'stadt leipzig' => ['id' => '114713000', 'name' => 'Stadt Leipzig'],
            'kreis leipzig' => ['id' => '114729000', 'name' => 'Kreis Leipzig'],
            'kreis meissen' => ['id' => '114627000', 'name' => 'Kreis Meißen'],
        ];
        $key = self::dwNormalizeCounty($district);
        if (isset($known[$key])) return $known[$key];
        // DWD-Verzeichnis enthielt alle WarncellIDs; CSV-Spalten variieren ueber Versionen.
        $url = 'https://www.dwd.de/DE/leistungen/opendata/help/warnungen/cap_warncellids_csv.csv';
        $csv = $this->dwLocationHttp($url, 4000000);
        return self::dwWarnCellFromCsv($csv, $district);
    }

    public static function dwNormalizeCounty(string $name): string
    {
        // Kein mbstring erforderlich; Umlaute vor ASCII-Kleinschreibung umsetzen.
        $name = trim(strtr($name, [
            'Ä'=>'Ae', 'Ö'=>'Oe', 'Ü'=>'Ue', 'ẞ'=>'Ss',
            'ä'=>'ae', 'ö'=>'oe', 'ü'=>'ue', 'ß'=>'ss'
        ]));
        $name = strtolower($name);
        $name = preg_replace('/\s*\([^)]*\)/u', '', $name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
        return trim((string)$name);
    }

    /** Offizielle DWD-Liste: bei mehrdeutigen Kreisteilen KEINE automatische Entwarnung. */
    public static function dwWarnCellFromCsv(string $csv, string $district): ?array
    {
        $target = self::dwNormalizeCounty($district);
        if ($target === '') return null;
        $found = [];
        foreach (preg_split('/\R/', $csv) as $line) {
            if (strlen($line) > 1000) continue;
            $separator = substr_count($line, ';') >= 1 ? ';' :
                (substr_count($line, "\t") >= 1 ? "\t" : ',');
            $fields = str_getcsv($line, $separator);
            $id = '';
            foreach ($fields as $field) {
                if (preg_match('/^(?:1|9)\d{8}$/', trim($field))) {
                    $id = trim($field);
                    break;
                }
            }
            if ($id === '') continue;
            foreach ($fields as $field) {
                $name = trim($field);
                if ($name === '' || preg_match('/^\d+$/', $name)) continue;
                if (self::dwNormalizeCounty($name) === $target) {
                    $found[$id] = ['id' => $id, 'name' => $name];
                    break;
                }
            }
        }
        // Landkreis-Unterteilungen oder Namensgleichheiten: manuell pruefen.
        return count($found) === 1 ? reset($found) : null;
    }

    /** Naechste MOSMIX-Kataloghoehe (nur grobe Naeherung fuer die Kartenposition). */
    protected function dwNearestStationHeight(float $lat, float $lon): ?int
    {
        $catalog = $this->dwLocationHttp(
            'https://www.dwd.de/DE/leistungen/met_verfahren_mosmix/mosmix_stationskatalog.cfg?view=nasPublication&nn=16102',
            1200000
        );
        $nearestKm = INF;
        $height = null;
        foreach (preg_split('/\R/', $catalog) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 6 || !preg_match('/^[A-Za-z0-9]{4,7}$/', $cols[0])) continue;
            $n = count($cols);
            $y = $this->dwDegreesMinutes($cols[$n - 3]);
            $x = $this->dwDegreesMinutes($cols[$n - 2]);
            $h = is_numeric($cols[$n - 1]) ? (int)$cols[$n - 1] : null;
            if ($x === null || $y === null || $h === null) continue;
            $distance = $this->dwKm($lat, $lon, $y, $x);
            if ($distance < $nearestKm) {
                $nearestKm = $distance;
                $height = $h;
            }
        }
        return $nearestKm <= 50.0 ? $height : null;
    }

    private function configurationValid(): bool
    {
        $lat = (float)$this->setting('DW_LAT');
        $lon = (float)$this->setting('DW_LON');
        $kwp = (float)$this->setting('DW_PV_KWP');
        $pr = (float)$this->setting('DW_PV_PR');
        return trim((string)$this->setting('DW_ORT')) !== ''
            && is_finite($lat) && $lat >= -90 && $lat <= 90 && $lat != 0.0
            && is_finite($lon) && $lon >= -180 && $lon <= 180 && $lon != 0.0
            && trim((string)$this->setting('DW_WARNCELL_ID')) !== ''
            && trim((string)$this->setting('DW_WARNGEBIET_NAME')) !== ''
            && is_finite($kwp) && $kwp > 0
            && $this->setting('DW_PV_TILT') >= 0 && $this->setting('DW_PV_TILT') <= 90
            && $this->setting('DW_PV_AZIMUTH') >= -180 && $this->setting('DW_PV_AZIMUTH') <= 180
            && is_finite($pr) && $pr > 0 && $pr <= 1
            && (int)$this->setting('DW_PV_ID_TAGESERTRAG') > 0
            && (int)$this->setting('DW_PV_ID_GESAMTERTRAG') > 0
            && (int)$this->setting('DW_PV_ID_LEISTUNG') > 0
            && in_array($this->setting('DW_PV_UNIT_TAGESERTRAG'), ['Wh','kWh'], true)
            && in_array($this->setting('DW_PV_UNIT_GESAMTERTRAG'), ['Wh','kWh'], true)
            && in_array($this->setting('DW_PV_UNIT_LEISTUNG'), ['W','kW'], true);
    }

    private function invalidateAutomationOutputs(): void
    {
        // Bestehende Instanz: bei unvollstaendiger Konfiguration ALTE Freigaben sperren.
        // Keine Caches und insbesondere keine Lerndaten loeschen.
        $invalid = [
            'DWD_PV_LEISTUNG_24H_VALID' => false,
            'DWD_PV_VALID' => false,
            'DWD_PV_7D_VALID' => false,
            'DWD_PV_MORGEN_VALID' => false,
            'DWD_PV_HEUTE_ERWARTET_VALID' => false,
            'DWD_WARN_VALID' => false,
            'DWD_PV_24H_QUALITAET' => 0,
            'DWD_PV_24H_QUALITAET_TEXT' => 'Instanz noch nicht vollstaendig konfiguriert',
        ];
        foreach ($invalid as $ident => $value) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($id !== false) $this->dwSetIfDifferent((int)$id, $value);
        }
        $hourCat = @IPS_GetObjectIDByIdent('DWD_PV_24_STUNDENWERTE', $this->InstanceID);
        if ($hourCat !== false) {
            for ($i = 1; $i <= 24; $i++) {
                $hourID = @IPS_GetObjectIDByIdent(sprintf('DWD_PV_LEISTUNG_H%02d_W', $i), $hourCat);
                if ($hourID !== false) $this->dwSetIfDifferent((int)$hourID, 0);
            }
        }
    }

    /** Alle Wetterparameter sind Instanz-Eigenschaften, keine globalen PHP-Konstanten. */
    private function setting(string $name)
    {
        if (!isset(self::PROPERTY_TYPES[$name])) {
            throw new InvalidArgumentException('Unbekannte Moduleigenschaft: ' . $name);
        }
        $type = self::PROPERTY_TYPES[$name];
        $method = 'ReadProperty' . $type;
        $value = $this->{$method}($name);
        if (isset(self::INTERNAL_DEFAULTS[$name]) && $value === 0) {
            return self::INTERNAL_DEFAULTS[$name];
        }
        return $value;
    }

    private function dwVar(int $parent, string $ident, string $name, int $type,
        bool $html = false, bool $hidden = false): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($parent === $this->InstanceID) {
            $methods = [0 => 'RegisterVariableBoolean', 1 => 'RegisterVariableInteger',
                2 => 'RegisterVariableFloat', 3 => 'RegisterVariableString'];
            if (!isset($methods[$type])) {
                throw new InvalidArgumentException('Ungueltiger Variablentyp: ' . $type);
            }
            $presentation = $html
                ? ['PRESENTATION' => VARIABLE_PRESENTATION_WEB_CONTENT, 'HTML_TYPE' => 0]
                : [];
            $method = $methods[$type];
            $this->{$method}($ident, $name, $presentation);
            $id = $this->GetIDForIdent($ident);
        } elseif ($id === false) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $parent);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
        }
        if ($hidden && $id !== false) {
            IPS_SetHidden($id, true);
        }
        return (int)$id;
    }

    /** Auch ReadOnly-Variablen der Strict-Klasse koennen ueber die Modul-API aktualisiert werden. */
    private function dwSetIfDifferent(int $id, $value): void
    {
        if ($id <= 0 || GetValue($id) === $value) {
            return;
        }
        if (IPS_GetParent($id) === $this->InstanceID) {
            $ident = IPS_GetObject($id)['ObjectIdent'];
            $this->SetValue($ident, $value);
        } else {
            SetValue($id, $value);
        }
    }

    private function ensureVariables(): array
    {
        $categoryID = $this->InstanceID;
        // Die Anzeigen werden als echte Instanz-Statusvariablen angelegt.
        $vDaily     = $this->dwVar($categoryID, 'DWD_KACHEL_7T', '01 · Wettervorhersage (7 Tage)', 3, true);
        $vHourly    = $this->dwVar($categoryID, 'DWD_KACHEL_24H', '02 · Wetterdiagramme (24 Stunden)', 3, true);
        $vWarning   = $this->dwVar($categoryID, 'DWD_KACHEL_WARN', '03 · Wetterwarnungen', 3, true);
        $vPvTile    = $this->dwVar($categoryID, 'DWD_KACHEL_PV', '04 · PV-Ertragsprognose', 3, true);
        $vCompare   = $this->dwVar($categoryID, 'DWD_KACHEL_MODELLE', '05 · Wettermodelle im Vergleich', 3, true);
        $vReality   = $this->dwVar($categoryID, 'DWD_KACHEL_PV_IST', '06 · PV-Prognose und Ist-Vergleich', 3, true);
        $vPvPowerTile=$this->dwVar($categoryID,'DWD_KACHEL_PV_LEISTUNG_24H','07 · PV-Leistungsprognose (24 Stunden)',3,true);

        $vNowTemp   = $this->dwVar($categoryID, 'DWD_TEMP_1H', 'Temperatur nächste Stunde (°C)', 2);
        $vRain24    = $this->dwVar($categoryID, 'DWD_REGEN_24H', 'MOSMIX Niederschlag nächste 24 h (mm)', 2);
        $vSun24     = $this->dwVar($categoryID, 'DWD_SONNE_24H', 'MOSMIX Sonnenstunden nächste 24 h (h)', 2);
        $vProb24    = $this->dwVar($categoryID, 'DWD_PROB_MAX_24H', 'Maximaler Stunden-Regenwert 24 h (%)', 1);
        $vGHINext   = $this->dwVar($categoryID, 'DWD_GHI_NEXT_WM2', 'Globalstrahlung nächste Stunde (W/m²)', 2);
        $vGHI24     = $this->dwVar($categoryID, 'DWD_GHI_24_KWHM2', 'MOSMIX Globalstrahlung 24 h (kWh/m²)', 2);
        $vPVNext    = $this->dwVar($categoryID, 'DWD_PV_NEXT_KW', 'PV prognostizierte Leistung nächste Stunde (kW)', 2);
        $vPV24      = $this->dwVar($categoryID, 'DWD_PV_24_KWH', 'PV prognostizierter Ertrag 24 h (kWh)', 2);
        $vPVRest    = $this->dwVar($categoryID, 'DWD_PV_REST_HEUTE_KWH', 'PV Rest heute (kWh)', 2);
        $vPVMorgen  = $this->dwVar($categoryID, 'DWD_PV_MORGEN_KWH', 'PV morgen (kWh)', 2);
        $vPV7       = $this->dwVar($categoryID, 'DWD_PV_7D_KWH', 'PV nächste 168 Stunden (kWh)', 2);
        $vPVFresh   = $this->dwVar($categoryID, 'DWD_PV_VALID', 'PV 24-h-Prognose aktuell / nutzbar', 0);
        $vPV7Fresh  = $this->dwVar($categoryID, 'DWD_PV_7D_VALID', 'PV 7-Tage-Prognose vollstaendig', 0);
        $vPVMorrowValid = $this->dwVar($categoryID, 'DWD_PV_MORGEN_VALID', 'PV Morgen vollstaendig', 0);
        $vMosFresh  = $this->dwVar($categoryID, 'DWD_MOS_VALID', 'DWD MOSMIX aktuell / nutzbar', 0);
        $vIconFresh = $this->dwVar($categoryID, 'DWD_ICON_VALID', 'ICON-D2 aktuell / nutzbar', 0);
        $vECFresh   = $this->dwVar($categoryID, 'DWD_ECMWF_VALID', 'ECMWF aktuell / nutzbar', 0);
        $vAgree     = $this->dwVar($categoryID, 'DWD_MODELL_STREUUNG', 'Modellübereinstimmung 24 Stunden', 3);
        $vWarnCount = $this->dwVar($categoryID, 'DWD_WARN_COUNT', 'Anzahl DWD-Warnungen', 1);
        $vWarnLevel = $this->dwVar($categoryID, 'DWD_WARN_MAXLEVEL', 'Höchste DWD-Warnstufe', 1);
        $vWarnValid = $this->dwVar($categoryID, 'DWD_WARN_VALID', 'DWD-Warnstatus zuverlässig', 0);
        $vStatus    = $this->dwVar($categoryID, 'DWD_STATUS', 'Abrufstatus aller Wetterquellen', 3);
        $vFetchState=$this->dwVar($categoryID,'DWD_FETCH_STATE_JSON','Letzter Abrufversuch je Quelle (JSON)',3,false,true);
        $vModelStatus=$this->dwVar($categoryID,'DWD_QUELLENSTATUS_JSON','Wettermodelle / Cache Status (JSON)',3,false,true);

        $vStation   = $this->dwVar($categoryID, 'DWD_STATION_ID', 'MOSMIX-Punkt ID', 3, false, true);
        $vStationName = $this->dwVar($categoryID, 'DWD_STATION_NAME', 'MOSMIX-Punkt Name', 3, false, true);
        $vLastSearch = $this->dwVar($categoryID, 'DWD_LAST_STATION_SEARCH', 'Stationssuche (Unix)', 1, false, true);
        $vMosCache  = $this->dwVar($categoryID, 'DWD_CACHE_FORECAST', 'MOSMIX-Puffer JSON', 3, false, true);
        $vWarningCache = $this->dwVar($categoryID, 'DWD_CACHE_WARNINGS', 'Warnpuffer JSON', 3, false, true);
        $vMosStamp  = $this->dwVar($categoryID, 'DWD_FORECAST_TIME', 'MOSMIX erfolgreicher Abruf (Unix)', 1, false, true);
        $vWarnStamp = $this->dwVar($categoryID, 'DWD_WARNING_TIME', 'Warnungen erfolgreicher Abruf (Unix)', 1, false, true);
        $vIconCache = $this->dwVar($categoryID, 'DWD_CACHE_ICON', 'ICON-D2-Puffer JSON', 3, false, true);
        $vECCache   = $this->dwVar($categoryID, 'DWD_CACHE_ECMWF', 'ECMWF-Puffer JSON', 3, false, true);
        $vIconStamp = $this->dwVar($categoryID, 'DWD_ICON_TIME', 'ICON-D2 letzter Abruf (Unix)', 1, false, true);
        $vECStamp   = $this->dwVar($categoryID, 'DWD_ECMWF_TIME', 'ECMWF letzter Abruf (Unix)', 1, false, true);
        $vECName    = $this->dwVar($categoryID, 'DWD_ECMWF_MODELNAME', 'ECMWF-Modellkennung', 3, false, true);
        $vPVHourly  = $this->dwVar($categoryID, 'DWD_PV_JSON_HOURLY', 'PV Prognose stündlich JSON', 3, false, true);
        $vPVSummary = $this->dwVar($categoryID, 'DWD_PV_JSON_DAILY', 'PV Prognose täglich JSON', 3, false, true);
        $vModelsJson = $this->dwVar($categoryID, 'DWD_MODELL_JSON', 'Modellvergleich JSON', 3, false, true);
        // Messwerte/Prognosekorrektur fuer weitere Automationen.
        $vRealityState = $this->dwVar($categoryID,'DWD_PV_IST_STATE_JSON','PV Vergleich Lernspeicher JSON',3,false,true);
        $vRealityJson = $this->dwVar($categoryID,'DWD_PV_IST_VERGLEICH_JSON','PV Auswertung JSON',3,false,true);
        $vRealityPower = $this->dwVar($categoryID,'DWD_PV_IST_LEISTUNG_KW','PV gemessen aktuelle Leistung (kW)',2);
        $vRealityDay = $this->dwVar($categoryID,'DWD_PV_IST_HEUTE_KWH','PV gemessen heute (kWh)',2);
        $vRealityTotal = $this->dwVar($categoryID,'DWD_PV_IST_GESAMT_KWH','PV gemessener Gesamtertrag (kWh)',2);
        $vRealityDiff = $this->dwVar($categoryID,'DWD_PV_ABWEICHUNG_BISHER_KWH','PV Abweichung bis jetzt (kWh)',2);
        $vRealityDayExpected = $this->dwVar($categoryID,'DWD_PV_HEUTE_ERWARTET_KWH','PV heute gesamt erwartet (Ist plus Rest) (kWh)',2);
        $vRealityRest = $this->dwVar($categoryID,'DWD_PV_REST_HEUTE_KORR_KWH','PV Rest heute selbstkorrigiert (kWh)',2);
        $vRealityTomorrow = $this->dwVar($categoryID,'DWD_PV_MORGEN_KORR_KWH','PV morgen selbstkorrigiert (kWh)',2);
        $vRealityFactor = $this->dwVar($categoryID,'DWD_PV_LERNFAKTOR','PV Lernfaktor gemessen/Prognose',2);
        $vRealityLearningDays = $this->dwVar($categoryID,'DWD_PV_LERNTAGE','Anzahl geeigneter abgeschlossener Tage',1);
        $vRealityMeasValid = $this->dwVar($categoryID,'DWD_PV_IST_GUELTIG','PV Ist-Tageswert aktuell/gueltig',0);
        $vRealityExpectedValid = $this->dwVar($categoryID,'DWD_PV_HEUTE_ERWARTET_VALID','PV heute Ist plus Rest gueltig',0);

        // PV-Leistung fuer Automationen (W, nicht kW). Alle Werte nur bei gueltigem
        // 24h-Datensatz verwenden; bei unvollstaendiger Prognose = 0 und Flag FALSE.
        $vPowerValid=$this->dwVar($categoryID,'DWD_PV_LEISTUNG_24H_VALID','PV 24 Stunden Leistungswerte gueltig',0);
        $vPowerQuality=$this->dwVar($categoryID,'DWD_PV_24H_QUALITAET','PV Prognosequalitaet (0 ungueltig, 1 normal, 2 eingeschraenkt)',1);
        $vPowerQualityText=$this->dwVar($categoryID,'DWD_PV_24H_QUALITAET_TEXT','PV Prognose Status Text',3);
        $vPowerCachedError=$this->dwVar($categoryID,'DWD_PV_24H_CACHE_NACH_FEHLER','PV gueltiger Cache nach Downloadfehler',0);
        $vPowerCurrent=$this->dwVar($categoryID,'DWD_PV_LEISTUNG_AKT_STUNDE_W','PV aktuelle Stunde Ø Prognose (W)',1);
        $vPowerNext=$this->dwVar($categoryID,'DWD_PV_LEISTUNG_NAECHSTE_STUNDE_W','PV naechste volle Stunde Ø Prognose (W)',1);
        $vPowerPeak=$this->dwVar($categoryID,'DWD_PV_LEISTUNG_MAX_24H_W','PV Maximum Stundenmittel naechste 24 h (W)',1);
        $vPowerRolling3=$this->dwVar($categoryID,'DWD_PV_ERTRAG_3H_KWH','PV Ertrag naechste 3 h ab jetzt (kWh)',2);
        $vPowerRolling6=$this->dwVar($categoryID,'DWD_PV_ERTRAG_6H_KWH','PV Ertrag naechste 6 h ab jetzt (kWh)',2);
        $vPowerRolling24=$this->dwVar($categoryID,'DWD_PV_ERTRAG_24H_KWH_EXAKT','PV Ertrag naechste 24 h ab jetzt (kWh)',2);
        $vPowerHourlyJson=$this->dwVar($categoryID,'DWD_PV_LEISTUNG_24H_JSON','PV Leistungsprognose 24 Stunden JSON',3,false,true);
        $vPowerForecastStamp=$this->dwVar($categoryID,'DWD_PV_LEISTUNG_DATENSTAND_UNIX','PV Wettermodelle Datenstand (Unix)',1);
        $vPowerHourCat=@IPS_GetObjectIDByIdent('DWD_PV_24_STUNDENWERTE',$categoryID);
        if($vPowerHourCat===false) {
            $vPowerHourCat=IPS_CreateCategory();
            IPS_SetParent($vPowerHourCat,$categoryID);
            IPS_SetIdent($vPowerHourCat,'DWD_PV_24_STUNDENWERTE');
            IPS_SetName($vPowerHourCat,'⚡ PV · 24 Stundenwerte für KNX (W)');
        }
        $vPowerHourVars=[];
        for($i=1;$i<=24;$i++) {
            $ident=sprintf('DWD_PV_LEISTUNG_H%02d_W',$i);
            $vPowerHourVars[]=$this->dwVar($vPowerHourCat,$ident,sprintf('%02d · Nächste volle Stunde +%02d (W)',$i,$i-1),1);
        }
        return compact('vDaily', 'vHourly', 'vWarning', 'vPvTile', 'vCompare', 'vReality', 'vPvPowerTile', 'vNowTemp', 'vRain24', 'vSun24', 'vProb24', 'vGHINext', 'vGHI24', 'vPVNext', 'vPV24', 'vPVRest', 'vPVMorgen', 'vPV7', 'vPVFresh', 'vPV7Fresh', 'vPVMorrowValid', 'vMosFresh', 'vIconFresh', 'vECFresh', 'vAgree', 'vWarnCount', 'vWarnLevel', 'vWarnValid', 'vStatus', 'vFetchState', 'vModelStatus', 'vStation', 'vStationName', 'vLastSearch', 'vMosCache', 'vWarningCache', 'vMosStamp', 'vWarnStamp', 'vIconCache', 'vECCache', 'vIconStamp', 'vECStamp', 'vECName', 'vPVHourly', 'vPVSummary', 'vModelsJson', 'vRealityState', 'vRealityJson', 'vRealityPower', 'vRealityDay', 'vRealityTotal', 'vRealityDiff', 'vRealityDayExpected', 'vRealityRest', 'vRealityTomorrow', 'vRealityFactor', 'vRealityLearningDays', 'vRealityMeasValid', 'vRealityExpectedValid', 'vPowerValid', 'vPowerQuality', 'vPowerQualityText', 'vPowerCachedError', 'vPowerCurrent', 'vPowerNext', 'vPowerPeak', 'vPowerRolling3', 'vPowerRolling6', 'vPowerRolling24', 'vPowerHourlyJson', 'vPowerForecastStamp', 'vPowerHourCat', 'vPowerHourVars');
    }

    private function hookName(): string
    {
        return 'dwd-weather-' . $this->InstanceID;
    }

    private function clearLocationAndPvState(): void
    {
        $reset = [
            'DWD_CACHE_FORECAST' => '', 'DWD_CACHE_WARNINGS' => '',
            'DWD_CACHE_ICON' => '', 'DWD_CACHE_ECMWF' => '',
            'DWD_FETCH_STATE_JSON' => '', 'DWD_QUELLENSTATUS_JSON' => '',
            'DWD_STATION_ID' => '', 'DWD_STATION_NAME' => '',
            'DWD_PV_IST_STATE_JSON' => '', 'DWD_PV_IST_VERGLEICH_JSON' => '',
            'DWD_LAST_STATION_SEARCH' => 0, 'DWD_FORECAST_TIME' => 0,
            'DWD_WARNING_TIME' => 0, 'DWD_ICON_TIME' => 0, 'DWD_ECMWF_TIME' => 0,
            'DWD_WARN_VALID' => false, 'DWD_PV_LEISTUNG_24H_VALID' => false,
            'DWD_PV_24H_QUALITAET' => 0, 'DWD_PV_VALID' => false,
            'DWD_PV_7D_VALID' => false, 'DWD_PV_MORGEN_VALID' => false,
            'DWD_PV_LEISTUNG_AKT_STUNDE_W' => 0,
            'DWD_PV_LEISTUNG_NAECHSTE_STUNDE_W' => 0,
            'DWD_PV_LEISTUNG_MAX_24H_W' => 0,
            'DWD_PV_ERTRAG_3H_KWH' => 0.0,
            'DWD_PV_ERTRAG_6H_KWH' => 0.0,
            'DWD_PV_ERTRAG_24H_KWH_EXAKT' => 0.0,
            'DWD_PV_24H_QUALITAET_TEXT' => 'Konfiguration geändert; neue Prognose ausstehend',
        ];
        foreach ($reset as $ident => $value) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($id !== false) {
                $this->dwSetIfDifferent($id, $value);
            }
        }
        $hoursCategory = @IPS_GetObjectIDByIdent('DWD_PV_24_STUNDENWERTE', $this->InstanceID);
        if ($hoursCategory !== false) {
            for ($i = 1; $i <= 24; $i++) {
                $hourId = @IPS_GetObjectIDByIdent(sprintf('DWD_PV_LEISTUNG_H%02d_W', $i), $hoursCategory);
                if ($hourId !== false) {
                    $this->dwSetIfDifferent($hourId, 0);
                }
            }
        }
    }

    /** Von der Konsole/aus PHP aufrufbar: DWDW_RequestRefresh($InstanzID). */
    public function RequestRefresh(): bool
    {
        if (!$this->configurationValid()) return false;
        if (!IPS_SemaphoreEnter('DWDW_MANUAL_' . $this->InstanceID, 1000)) {
            return false;
        }
        try {
            $last = $this->ReadAttributeInteger('LastManual');
            if ((time() - $last) < max(0, (int)$this->setting('DW_MANUELL_MIN_ABSTAND_S'))) {
                return false;
            }
            $this->WriteAttributeInteger('LastManual', time());
            $this->SetTimerInterval('ManualTimer', 1000);
            return true;
        } finally {
            IPS_SemaphoreLeave('DWDW_MANUAL_' . $this->InstanceID);
        }
    }

    /** Die naechsten Methoden dienen nur als Timer-Callbacks. */
    public function ForecastTick(): void { $this->runCycle('forecast'); }
    public function WarningTick(): void { $this->runCycle('warn'); }
    public function MeasureTick(): void { $this->runCycle('measure'); }
    public function BootTick(): void
    {
        $this->SetTimerInterval('BootstrapTimer', 0);
        $this->runCycle('all');
    }
    public function ManualTick(): void
    {
        $this->SetTimerInterval('ManualTimer', 0);
        $this->runCycle('manual');
    }

    /** Manuelle Vollaktualisierung ohne Ratenbegrenzung, z.B. Konsole. */
    public function Refresh(): void
    {
        $this->runCycle('all');
    }

    /** Mehrere Instanzen duerfen parallel laufen, jedoch nicht dieselbe Instanz mehrfach. */
    private function runCycle(string $mode): void
    {
        if (!$this->configurationValid()) {
            $this->invalidateAutomationOutputs();
            $this->SetStatus(201);
            return;
        }
        if (!in_array($mode, ['all','forecast','warn','measure','manual'], true)) {
            return;
        }
        $lock = 'DWDW_UPDATE_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 10000)) {
            $this->dwLog('Wetterabruf laeuft bereits. Erneuter Aufruf uebersprungen.');
            return;
        }
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $categoryID = $this->InstanceID;
            if ($this->ids === []) {
                $this->ids = $this->ensureVariables();
            }
            $vDaily = $this->ids['vDaily'];
            $vHourly = $this->ids['vHourly'];
            $vWarning = $this->ids['vWarning'];
            $vPvTile = $this->ids['vPvTile'];
            $vCompare = $this->ids['vCompare'];
            $vReality = $this->ids['vReality'];
            $vPvPowerTile = $this->ids['vPvPowerTile'];
            $vNowTemp = $this->ids['vNowTemp'];
            $vRain24 = $this->ids['vRain24'];
            $vSun24 = $this->ids['vSun24'];
            $vProb24 = $this->ids['vProb24'];
            $vGHINext = $this->ids['vGHINext'];
            $vGHI24 = $this->ids['vGHI24'];
            $vPVNext = $this->ids['vPVNext'];
            $vPV24 = $this->ids['vPV24'];
            $vPVRest = $this->ids['vPVRest'];
            $vPVMorgen = $this->ids['vPVMorgen'];
            $vPV7 = $this->ids['vPV7'];
            $vPVFresh = $this->ids['vPVFresh'];
            $vPV7Fresh = $this->ids['vPV7Fresh'];
            $vPVMorrowValid = $this->ids['vPVMorrowValid'];
            $vMosFresh = $this->ids['vMosFresh'];
            $vIconFresh = $this->ids['vIconFresh'];
            $vECFresh = $this->ids['vECFresh'];
            $vAgree = $this->ids['vAgree'];
            $vWarnCount = $this->ids['vWarnCount'];
            $vWarnLevel = $this->ids['vWarnLevel'];
            $vWarnValid = $this->ids['vWarnValid'];
            $vStatus = $this->ids['vStatus'];
            $vFetchState = $this->ids['vFetchState'];
            $vModelStatus = $this->ids['vModelStatus'];
            $vStation = $this->ids['vStation'];
            $vStationName = $this->ids['vStationName'];
            $vLastSearch = $this->ids['vLastSearch'];
            $vMosCache = $this->ids['vMosCache'];
            $vWarningCache = $this->ids['vWarningCache'];
            $vMosStamp = $this->ids['vMosStamp'];
            $vWarnStamp = $this->ids['vWarnStamp'];
            $vIconCache = $this->ids['vIconCache'];
            $vECCache = $this->ids['vECCache'];
            $vIconStamp = $this->ids['vIconStamp'];
            $vECStamp = $this->ids['vECStamp'];
            $vECName = $this->ids['vECName'];
            $vPVHourly = $this->ids['vPVHourly'];
            $vPVSummary = $this->ids['vPVSummary'];
            $vModelsJson = $this->ids['vModelsJson'];
            $vRealityState = $this->ids['vRealityState'];
            $vRealityJson = $this->ids['vRealityJson'];
            $vRealityPower = $this->ids['vRealityPower'];
            $vRealityDay = $this->ids['vRealityDay'];
            $vRealityTotal = $this->ids['vRealityTotal'];
            $vRealityDiff = $this->ids['vRealityDiff'];
            $vRealityDayExpected = $this->ids['vRealityDayExpected'];
            $vRealityRest = $this->ids['vRealityRest'];
            $vRealityTomorrow = $this->ids['vRealityTomorrow'];
            $vRealityFactor = $this->ids['vRealityFactor'];
            $vRealityLearningDays = $this->ids['vRealityLearningDays'];
            $vRealityMeasValid = $this->ids['vRealityMeasValid'];
            $vRealityExpectedValid = $this->ids['vRealityExpectedValid'];
            $vPowerValid = $this->ids['vPowerValid'];
            $vPowerQuality = $this->ids['vPowerQuality'];
            $vPowerQualityText = $this->ids['vPowerQualityText'];
            $vPowerCachedError = $this->ids['vPowerCachedError'];
            $vPowerCurrent = $this->ids['vPowerCurrent'];
            $vPowerNext = $this->ids['vPowerNext'];
            $vPowerPeak = $this->ids['vPowerPeak'];
            $vPowerRolling3 = $this->ids['vPowerRolling3'];
            $vPowerRolling6 = $this->ids['vPowerRolling6'];
            $vPowerRolling24 = $this->ids['vPowerRolling24'];
            $vPowerHourlyJson = $this->ids['vPowerHourlyJson'];
            $vPowerForecastStamp = $this->ids['vPowerForecastStamp'];
            $vPowerHourCat = $this->ids['vPowerHourCat'];
            $vPowerHourVars = $this->ids['vPowerHourVars'];
            $scriptID = $this->InstanceID; // nur als Kachel-Frame-ID verwendet
            $hookPath = '/hook/' . $this->hookName();
            $controlToken = $this->ReadAttributeString('ControlToken');
            $hookReady = $controlToken !== '';
            $mos = json_decode((string)GetValue($vMosCache), true);
            $icon = json_decode((string)GetValue($vIconCache), true);
            $ecmwf = json_decode((string)GetValue($vECCache), true);
            $warnings = json_decode((string)GetValue($vWarningCache), true);
            if (!is_array($mos)) $mos=[];
            if (!is_array($icon)) $icon=[];
            if (!is_array($ecmwf)) $ecmwf=[];
            if (!is_array($warnings)) $warnings=[];
            $mosStamp = (int)GetValue($vMosStamp);
            $iconStamp = (int)GetValue($vIconStamp);
            $ecStamp = (int)GetValue($vECStamp);
            $warnStamp = (int)GetValue($vWarnStamp);
            $stationId = (string)GetValue($vStation);
            $stationName = (string)GetValue($vStationName);
            $ecLabel = (string)GetValue($vECName);
            $errors = ['mos'=>'','icon'=>'','ecmwf'=>'','warnings'=>''];
            $fetchHistory=json_decode((string)GetValue($vFetchState),true);
            if(!is_array($fetchHistory)) $fetchHistory=[];
            $justDownloaded=['mos'=>false,'icon'=>false,'ecmwf'=>false];

            if ($mode === 'all' || $mode === 'forecast' || $mode === 'manual') {
                if ($this->setting('DW_STATION_MANUELL') !== '' || $stationId === '' || time()-(int)GetValue($vLastSearch) > 7*86400) {
                    try {
                        $best=$this->dwFindLocalStation();
                        $stationId=$best['id'];
                        $stationName=$best['name'].($best['km']!==null?' · '.$this->dwFmt($best['km']).' km':'');
                        $this->dwSetIfDifferent($vStation,$stationId); $this->dwSetIfDifferent($vStationName,$stationName);
                    } catch (Throwable $e) {
                        $this->dwLog('Stationssuche: '.$e->getMessage());
                        if ($stationId==='') {
                            $stationId=$this->setting('DW_FALLBACK_STATION');
                            $stationName=$this->setting('DW_FALLBACK_NAME').' (Ersatzstation)';
                            $this->dwSetIfDifferent($vStation,$stationId); $this->dwSetIfDifferent($vStationName,$stationName);
                        }
                    }
                    $this->dwSetIfDifferent($vLastSearch,time());
                }
                if ($stationId==='') { $stationId=$this->setting('DW_FALLBACK_STATION'); $stationName=$this->setting('DW_FALLBACK_NAME').' (Ersatzstation)'; }
                try {
                    if (!preg_match('/^[A-Za-z0-9]{4,7}$/',$stationId)) throw new RuntimeException('Ungültige Stationskennung');
                    $download=function(string $id): array {
                        $url='https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/' .
                            rawurlencode($id).'/kml/MOSMIX_L_LATEST_'.rawurlencode($id).'.kmz';
                        return $this->dwReadMosmix($this->dwKmlFromKmz($this->dwHttp($url,300000)));
                    };
                    try { $fresh=$download($stationId); }
                    catch (Throwable $e) {
                        if ($stationId===$this->setting('DW_FALLBACK_STATION')) throw $e;
                        $this->dwLog('Station '.$stationId.' nicht abrufbar; Ersatzpunkt Chemnitz: '.$e->getMessage());
                        $stationId=$this->setting('DW_FALLBACK_STATION');
                        $stationName=$this->setting('DW_FALLBACK_NAME').' (Ersatzstation)';
                        $fresh=$download($stationId);
                        $this->dwSetIfDifferent($vStation,$stationId); $this->dwSetIfDifferent($vStationName,$stationName);
                    }
                    $mos=$fresh; $mosStamp=time();
                    $this->dwRecordFetch($fetchHistory,'mos',true); $justDownloaded['mos']=true;
                    $this->dwSetIfDifferent($vMosCache,json_encode($mos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                    $this->dwSetIfDifferent($vMosStamp,$mosStamp);
                } catch (Throwable $e) {
                    $errors['mos']=$this->dwSafeFetchError($e->getMessage()); $this->dwRecordFetch($fetchHistory,'mos',false,$errors['mos']); $this->dwLog('MOSMIX: '.$errors['mos']);
                }

                if ($this->setting('DW_OM_ENABLE')) {
                    try {
                        $icon=$this->dwFetchApi('icon_d2'); $iconStamp=time();
                        $this->dwRecordFetch($fetchHistory,'icon',true); $justDownloaded['icon']=true;
                        $this->dwSetIfDifferent($vIconCache,json_encode($icon, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                        $this->dwSetIfDifferent($vIconStamp,$iconStamp);
                    } catch (Throwable $e) {
                        $errors['icon']=$this->dwSafeFetchError($e->getMessage()); $this->dwRecordFetch($fetchHistory,'icon',false,$errors['icon']); $this->dwLog('ICON-D2: '.$errors['icon']);
                    }
                    // ECMWF-Modell 6-stuendlich aktualisieren, damit API-Anfragen sparsam bleiben.
                    if ($mode==='manual' || $ecStamp===0 || time()-$ecStamp >= 6*3600) {
                        try {
                            $ecLabel='ECMWF IFS HRES (9 km)';
                            try { $fresh=$this->dwFetchApi('ecmwf_ifs'); }
                            catch (Throwable $inner) {
                                $this->dwLog('ECMWF 9km nicht verfügbar; versuche 0.25°: '.$inner->getMessage());
                                $fresh=$this->dwFetchApi('ecmwf_ifs025');
                                $ecLabel='ECMWF IFS Open-Data (0.25°)';
                            }
                            $ecmwf=$fresh; $ecStamp=time();
                            $this->dwRecordFetch($fetchHistory,'ecmwf',true); $justDownloaded['ecmwf']=true;
                            $this->dwSetIfDifferent($vECCache,json_encode($ecmwf,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                            $this->dwSetIfDifferent($vECStamp,$ecStamp);
                            $this->dwSetIfDifferent($vECName,$ecLabel);
                        } catch (Throwable $e) {
                            $errors['ecmwf']=$this->dwSafeFetchError($e->getMessage()); $this->dwRecordFetch($fetchHistory,'ecmwf',false,$errors['ecmwf']); $this->dwLog('ECMWF: '.$errors['ecmwf']);
                        }
                    }
                } else {
                    $errors['icon']='Externe Modell-API deaktiviert';
                    $errors['ecmwf']='Externe Modell-API deaktiviert';
                }
            }

            // Warnungen bei jedem Wetter-/Warn-Lauf, NICHT beim 5-Min-PV-Messtimer.
            if ($mode !== 'measure') try {
                $warnings=$this->dwWarningsFromFeed($this->dwHttp('https://www.dwd.de/DWD/warnungen/warnapp/json/warnings.json',6000000));
                $warnStamp=time();
                $this->dwSetIfDifferent($vWarningCache,json_encode($warnings,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                $this->dwSetIfDifferent($vWarnStamp,$warnStamp);
                $this->dwSetIfDifferent($vWarnValid,true);
                $this->dwSetIfDifferent($vWarnCount,count($warnings));
                $this->dwSetIfDifferent($vWarnLevel,$warnings ? max(array_column($warnings,'level')) : 0);
            } catch (Throwable $e) {
                $errors['warnings']=$e->getMessage();
                $this->dwSetIfDifferent($vWarnValid,false);
                $this->dwLog('Warnungen: '.$errors['warnings']);
            }
            $warnOK = (bool)GetValue($vWarnValid) && $errors['warnings']==='' && $this->dwModelFresh($warnStamp,3600);
            // Persist the outcome of the last REQUEST; a 5-minute PV poll must not clear a network failure.
            $this->dwSetIfDifferent($vFetchState,json_encode($fetchHistory,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
            $info = [
                'mos' => $this->dwSourceState($mos,$mosStamp,14*3600,true,$fetchHistory['mos']??[],$justDownloaded['mos']),
                'icon' => $this->dwSourceState($icon,$iconStamp,9*3600,$this->setting('DW_OM_ENABLE'),$fetchHistory['icon']??[],$justDownloaded['icon']),
                'ecmwf' => $this->dwSourceState($ecmwf,$ecStamp,15*3600,$this->setting('DW_OM_ENABLE'),$fetchHistory['ecmwf']??[],$justDownloaded['ecmwf']),
            ];
            $solarSourcesFresh = $info['icon']['fresh'] || $info['ecmwf']['fresh'];
            // This JSON distinguishes newly fetched values from cached values and expired data.
            $this->dwSetIfDifferent($vModelStatus,json_encode([
                'checked_at'=>date(DATE_ATOM),
                'retry_after_ms'=>$this->setting('DW_HTTP_RETRY_PAUSE_MS'),
                'max_attempts_per_request'=>2,
                'sources'=>$info
            ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES));
            $this->dwSetIfDifferent($vMosFresh,$info['mos']['fresh']);
            $this->dwSetIfDifferent($vIconFresh,$info['icon']['fresh']);
            $this->dwSetIfDifferent($vECFresh,$info['ecmwf']['fresh']);
            // PV-Gueltigkeit erst nach Pruefung der Stundenabdeckung setzen (siehe unten).

            // Alte Prognosedaten bleiben im Cache, fließen aber bei Überalterung NICHT
            // in die neuen Diagramme und Steuerungswerte ein.
            $joined=$this->dwFutureJoin(
                $info['mos']['fresh'] ? $mos : [],
                $info['icon']['fresh'] ? $icon : [],
                $info['ecmwf']['fresh'] ? $ecmwf : [],168);
            $pvRows=$this->dwFuturePV($joined);
            $mosHours=$this->dw24hours($info['mos']['fresh'] ? $mos : []);
            $rain24=0.0;$rainSeen=false;$sun24=0.0;$sunSeen=false;$probMax=null;$gh24=0.0;$ghiSeen=false;
            foreach ($mosHours as $h) {
                if ($h['rain']!==null) { $rain24+=$h['rain']; $rainSeen=true; }
                if ($h['sun']!==null) { $sun24+=$h['sun']; $sunSeen=true; }
                if (($h['ghi'] ?? null)!==null) { $gh24+=$h['ghi']/1000; $ghiSeen=true; }
                if ($h['prob']!==null) $probMax=$probMax===null?$h['prob']:max($probMax,$h['prob']);
            }
            if (($mosHours[0]['temp'] ?? null)!==null) $this->dwSetIfDifferent($vNowTemp,round($mosHours[0]['temp'],2));
            if ($rainSeen) $this->dwSetIfDifferent($vRain24,round($rain24,2));
            if ($sunSeen) $this->dwSetIfDifferent($vSun24,round($sun24/3600,2));
            if ($ghiSeen) $this->dwSetIfDifferent($vGHI24,round($gh24,3));
            if ($probMax!==null) $this->dwSetIfDifferent($vProb24,(int)round($probMax));
            if (($mosHours[0]['ghi'] ?? null)!==null) $this->dwSetIfDifferent($vGHINext,round($mosHours[0]['ghi'],2));

            $start = $pvRows[0]['ts'] ?? (int)(ceil(time()/3600)*3600);
            $tomorrow = strtotime('tomorrow 00:00:00');
            $dayAfter = strtotime('tomorrow 00:00:00 +1 day');
            $pv24 = $this->dwSumFuture($pvRows,'pv_kwh',$start,$start+86400);
            $pvRest = $this->dwSumFuture($pvRows,'pv_kwh',$start,$tomorrow);
            $pvTomorrow = $this->dwSumFuture($pvRows,'pv_kwh',$tomorrow,$dayAfter);
            $pv7 = $this->dwSumFuture($pvRows,'pv_kwh',$start,$start+168*3600);
            $readyPV = $solarSourcesFresh && $pv24 !== null;
            $this->dwSetIfDifferent($vPVFresh,$readyPV);
            $this->dwSetIfDifferent($vPV7Fresh,$solarSourcesFresh && $pv7 !== null);
            $this->dwSetIfDifferent($vPVMorrowValid,$solarSourcesFresh && $pvTomorrow !== null);
            // Automationswerte niemals mit einem frueheren (inzwischen ungueltigen) Ertrag belassen.
            $this->dwSetIfDifferent($vPV24,$readyPV ? $pv24 : 0.0);
            $this->dwSetIfDifferent($vPVRest,$readyPV && $pvRest!==null ? $pvRest : 0.0);
            $this->dwSetIfDifferent($vPVMorgen,$solarSourcesFresh && $pvTomorrow!==null ? $pvTomorrow : 0.0);
            $this->dwSetIfDifferent($vPV7,$solarSourcesFresh && $pv7!==null ? $pv7 : 0.0);
            $this->dwSetIfDifferent($vPVNext,$readyPV && ($pvRows[0]['pv_kwh'] ?? null)!==null
                ? round((float)$pvRows[0]['pv_kwh'],3) : 0.0);
            // Erzeuger-Messwerte aus dem PV-Zaehlersystem (ohne zusaetzliche Wetterdownloads).
            $obsNow=time();
            $obsReading=$this->dwPVGetReading($obsNow);
            $oldState=json_decode((string)GetValue($vRealityState),true);
            if(!is_array($oldState)) $oldState=[];
            // Niemals eine unbelegte Messung als gueltige Ist-Prognose darstellen.
            if($obsReading['configured']) {
                $history=$this->dwPVProcessHistory($oldState,$obsReading,$icon,$ecmwf,$info,$obsNow);
                $reality=$this->dwPVMakeReport($history,$obsReading,$pvRows,$obsNow);
                $this->dwSetIfDifferent($vRealityState,json_encode($history['state'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
            } else {
                $history=['state'=>$oldState,'learning'=>$this->dwPVLearning($oldState['days']??[],date('Y-m-d',$obsNow))];
                $reality=['configured'=>false,'errors'=>$obsReading['errors'],'learning'=>$history['learning']];
            }
            $validIst=$obsReading['configured'] && $obsReading['day']!==null && empty($reality['today_counter_error']);
            $this->dwSetIfDifferent($vRealityMeasValid,$validIst);
            $this->dwSetIfDifferent($vRealityExpectedValid,$validIst && $reality['today_expected_kwh']!==null);
            if($obsReading['day']!==null && $validIst) $this->dwSetIfDifferent($vRealityDay,round($obsReading['day'],3));
            if($obsReading['total']!==null) $this->dwSetIfDifferent($vRealityTotal,round($obsReading['total'],3));
            if($obsReading['power']!==null) $this->dwSetIfDifferent($vRealityPower,round($obsReading['power'],3));
            if($obsReading['configured']) {
                if($reality['difference_sofar_kwh']!==null) $this->dwSetIfDifferent($vRealityDiff,$reality['difference_sofar_kwh']);
                if($validIst && $reality['today_expected_kwh']!==null) $this->dwSetIfDifferent($vRealityDayExpected,$reality['today_expected_kwh']);
                if($reality['rest_adjusted_kwh']!==null) $this->dwSetIfDifferent($vRealityRest,$reality['rest_adjusted_kwh']);
                if($pvTomorrow!==null) $this->dwSetIfDifferent($vRealityTomorrow,round($pvTomorrow*$reality['learning']['factor'],2));
                $this->dwSetIfDifferent($vRealityFactor,$reality['learning']['factor']);
                $this->dwSetIfDifferent($vRealityLearningDays,(int)$reality['learning']['days']);
            }
            $this->dwSetIfDifferent($vRealityJson,json_encode([
                'updated'=>date(DATE_ATOM),'configured'=>$obsReading['configured'],
                'sources'=>['day_id'=>$this->setting('DW_PV_ID_TAGESERTRAG'),'total_id'=>$this->setting('DW_PV_ID_GESAMTERTRAG'),'power_id'=>$this->setting('DW_PV_ID_LEISTUNG')],
                'units'=>['energy'=>'kWh','power'=>'kW'], 'status'=>$obsReading['errors'],
                'learning'=>$history['learning'], 'values'=>$obsReading['configured'] ? [
                    'actual_today_kwh'=>$reality['day_kwh'],
                    'actual_current_kw'=>$reality['power_kw'],
                    'actual_total_kwh'=>$reality['total_kwh'],
                    'forecast_today_frozen_kwh'=>$reality['forecast_fixed_adjusted_kwh'],
                    'forecast_sofar_kwh'=>$reality['forecast_sofar_adjusted_kwh'],
                    'difference_sofar_kwh'=>$reality['difference_sofar_kwh'],
                    'forecast_rest_adjusted_kwh'=>$reality['rest_adjusted_kwh'],
                    'forecast_today_expected_kwh'=>$validIst ? $reality['today_expected_kwh'] : null,
                    'forecast_tomorrow_adjusted_kwh'=>$pvTomorrow!==null?round($pvTomorrow*$history['learning']['factor'],2):null
                ] : null
            ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES));

            // PV-Watt-Prognose aus den bereits gecachten Wettermodellen. Wird durch den
            // bestehenden 5-Minuten-Messtimer laufend neu auf die 24 vollen Stunden ausgerichtet.
            $powerFactor=(float)($history['learning']['factor']??1.0);
            $powerSeries=$this->dwPVPowerSeries($pvRows,$powerFactor,$obsNow);
            $powerSummary=$this->dwPVPowerSummary($powerSeries,$obsNow);
            $fullPowerHours=array_slice($powerSeries,1,24);
            $powerOK=$solarSourcesFresh && $powerSummary['valid'];
            $pvQuality=$this->dwPvDataQuality($powerOK,$powerSeries,$info);
            $this->dwSetIfDifferent($vPowerQuality,$pvQuality['code']);
            $this->dwSetIfDifferent($vPowerQualityText,$pvQuality['label'].' - '.$pvQuality['details']);
            $this->dwSetIfDifferent($vPowerCachedError,$pvQuality['cache_fallback']);
            // Bei Datenverlust sofort sperren; bei neuen gueltigen Daten erst nach
            // dem Schreiben aller Stundenwerte freigeben (Schutz fuer Automationen).
            if(!$powerOK) $this->dwSetIfDifferent($vPowerValid,false);
            // Unvollstaendige Daten 'fail-closed': keine alten Wattwerte fuer Automationen.
            $this->dwSetIfDifferent($vPowerCurrent,$powerOK?(int)$powerSummary['current_hour_mean_w']:0);
            $this->dwSetIfDifferent($vPowerNext,$powerOK?(int)$powerSummary['next_full_hour_mean_w']:0);
            $this->dwSetIfDifferent($vPowerPeak,$powerOK?(int)$powerSummary['peak_full_hours_w']:0);
            $this->dwSetIfDifferent($vPowerRolling3,$powerOK?round((float)$powerSummary['rolling_3h_kwh'],3):0.0);
            $this->dwSetIfDifferent($vPowerRolling6,$powerOK?round((float)$powerSummary['rolling_6h_kwh'],3):0.0);
            $this->dwSetIfDifferent($vPowerRolling24,$powerOK?round((float)$powerSummary['rolling_24h_kwh'],3):0.0);
            for($i=0;$i<24;$i++) {
                $this->dwSetIfDifferent($vPowerHourVars[$i],$powerOK?(int)$fullPowerHours[$i]['forecast_power_w']:0);
            }
            $modelSolarStamp=max($info['icon']['fresh']?$iconStamp:0,$info['ecmwf']['fresh']?$ecStamp:0);
            $this->dwSetIfDifferent($vPowerForecastStamp,$powerOK?$modelSolarStamp:0);
            $jsonPowerOpts=JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES;
            $this->dwSetIfDifferent($vPowerHourlyJson,json_encode([
                'location'=>$this->setting('DW_ORT'),'generated_at'=>date(DATE_ATOM,$obsNow),
                'valid_for_automation'=>$powerOK,'available_full_hours'=>$powerSummary['full_hours_available'],
                'forecast_quality'=>$pvQuality,
                'quality_normal_no_known_download_failure'=>($pvQuality['code']===1),
                'source_last_fetch_unix'=>$modelSolarStamp,
                'source_freshness'=>['icon_d2'=>$info['icon']['fresh'],'ecmwf'=>$info['ecmwf']['fresh']],
                'model'=>'prefer ICON-D2, fallback ECMWF IFS',
                'source_status'=>$info,
                'pv_config'=>['peak_kwp'=>$this->setting('DW_PV_KWP'),'tilt_deg'=>$this->setting('DW_PV_TILT'),
                              'azimuth_from_south_deg'=>$this->setting('DW_PV_AZIMUTH'),'performance_ratio'=>$this->setting('DW_PV_PR'),
                              'learning_factor'=>$powerFactor,'ac_power_limit_w'=>$this->setting('DW_PV_AC_LIMIT_W')],
                'unit'=>'W als voraussichtlicher Durchschnitt der jeweils vollen Stunde',
                'current_hour'=>$powerSeries[0],
                'summary'=>$powerSummary,
                'hours'=>$fullPowerHours,
            ],$jsonPowerOpts));
            if($powerOK) $this->dwSetIfDifferent($vPowerValid,true);

            $cmp=$this->dwComparison($joined);
            $this->dwSetIfDifferent($vAgree,$cmp['label']);
            $dailyPV=[];
            for ($i=0;$i<7;$i++) {
                $dayTs=$this->dwPvToday(time(),$i);
                $nextDay=$this->dwPvToday(time(),$i+1);
                $from=max($dayTs,$start);
                $dailyPV[]=[
                    'date'=>date('Y-m-d',$dayTs),
                    'energy_kwh'=>$this->dwSumFuture($pvRows,'pv_kwh',$from,$nextDay),
                    'icon_kwh'=>$this->dwSumFuture($pvRows,'pv_icon_kwh',$from,$nextDay),
                    'ecmwf_kwh'=>$this->dwSumFuture($pvRows,'pv_ecmwf_kwh',$from,$nextDay),
                    'today_remaining'=>$i===0
                ];
            }
            $jsonOptions=JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES;
            $this->dwSetIfDifferent($vPVHourly,json_encode([
                'location'=>$this->setting('DW_ORT'),'generated'=>date(DATE_ATOM),'valid'=>$readyPV,
                'pv_config'=>['kwp'=>$this->setting('DW_PV_KWP'),'tilt_deg'=>$this->setting('DW_PV_TILT'),'azimuth_from_south_deg'=>$this->setting('DW_PV_AZIMUTH'),'performance_ratio'=>$this->setting('DW_PV_PR')],
                'units'=>['pv_kwh'=>'kWh in der vorangehenden Stunde','gti_wm2'=>'W/m2 Mittelwert der vorangehenden Stunde'],
                'hours'=>$pvRows
            ],$jsonOptions));
            $this->dwSetIfDifferent($vPVSummary,json_encode([
                'location'=>$this->setting('DW_ORT'),'valid'=>$readyPV,'today_rest_kwh'=>$pvRest,
                'next_24h_kwh'=>$pv24,'tomorrow_kwh'=>$pvTomorrow,'next_168h_kwh'=>$pv7,
                'valid_tomorrow'=>$solarSourcesFresh && $pvTomorrow!==null,
                'valid_next_168h'=>$solarSourcesFresh && $pv7!==null,'days'=>$dailyPV
            ],$jsonOptions));
            $this->dwSetIfDifferent($vModelsJson,json_encode([
                'location'=>$this->setting('DW_ORT'),'generated'=>date(DATE_ATOM),'sources'=>$info,
                'forecast_quality'=>$pvQuality,
                'ecmwf_model'=>$ecLabel,'comparison'=>$cmp,'summary_24h'=>[
                    'mos'=>$this->dwMiniSummary($joined,'mos'),
                    'icon'=>$this->dwMiniSummary($joined,'icon'),
                    'ecmwf'=>$this->dwMiniSummary($joined,'ecmwf')
                ]
            ],$jsonOptions));

            $days=$this->dwAggregateDay($mos);
            $this->dwSetIfDifferent($vDaily,$this->dwDailyHtml($days,$warnings,$warnOK,$warnStamp,$stationName,$stationId,$mosStamp,$errors['mos'], $hookPath,$controlToken,$hookReady,$vDaily));
            $this->dwSetIfDifferent($vHourly,$this->dwHourlyHtml($joined,$warnings,$warnOK,$warnStamp,$stationName,$stationId,$mosStamp,$errors['mos'],
                ['ICON-D2'=>$info['icon'],'ECMWF'=>$info['ecmwf']], $hookPath,$controlToken,$hookReady,$vHourly));
            $this->dwSetIfDifferent($vWarning,$this->dwWarningHtml($warnings,$warnOK,$warnStamp));
            $pvRendered=$this->dwPvHtml($pvRows,$joined,$warnings,$warnOK,$warnStamp,$stationName,$stationId,$mosStamp,$info);
            if($pvQuality['code']===2) {
                $insert='<div class="dwx-notice"><strong>Wetterdaten eingeschraenkt:</strong> '.$this->dwEsc($pvQuality['details']).'. Die 24h-Leistungsprognose bleibt verfuegbar (Qualitaet 2).</div>';
                $needle='<div class="dwx-footer">'; $place=strrpos($pvRendered,$needle);
                if($place!==false) $pvRendered=substr_replace($pvRendered,$insert,$place,0);
            }
            // Den vorhandenen PV-Kachel-Inhalt unveraendert lassen und einen Ist-Block anhaengen.
            // Der Block steht vor dem letzten schliessenden DIV der Kachel.
            $pos=strrpos($pvRendered,'<div class="dwx-footer">');
            if($pos!==false) $pvRendered=substr_replace($pvRendered,$this->dwPVMeasuredInline($reality),$pos,0);
            $this->dwSetIfDifferent($vPvTile,$pvRendered);
            $this->dwSetIfDifferent($vReality,$this->dwPVRealityHtml($reality,$warnings,$warnOK,$warnStamp));
            $this->dwSetIfDifferent($vCompare,$this->dwCompareHtml($joined,$pvRows,$warnings,$warnOK,$warnStamp,$stationName,$stationId,$mosStamp,$info,$cmp, $hookPath,$controlToken,$hookReady,$scriptID));
            $this->dwSetIfDifferent($vPvPowerTile,$this->dwPVPowerHtml($powerSeries,$powerSummary,$obsReading,$powerFactor,$warnings,$warnOK,$warnStamp,$info,$pvQuality));
            $sourceLabels=['mos'=>'MOSMIX','icon'=>'ICON-D2','ecmwf'=>'ECMWF'];
            $statusParts=[];
            foreach($sourceLabels as $source=>$title) {
                $entry=$info[$source];
                $description=$entry['label'];
                if($entry['age_min']!==null) $description.=' ('.(int)$entry['age_min'].' min)';
                if($entry['cached_after_error'] && $entry['error']!=='') {
                    $description.='; Ursache: '.$entry['error'];
                } elseif(!$entry['fresh'] && $entry['error']!=='') {
                    $description.='; '.$entry['error'];
                }
                $statusParts[]=$title.': '.$description;
            }
            $statusParts[]='DWD-Warnungen: '.($warnOK?'gueltig':'NICHT geprueft / nicht aktuell');
            if($errors['warnings']!=='') $statusParts[]='Warnungen: '.$errors['warnings'];
            $statusParts[]='PV-Automationen: '.$pvQuality['label'].' (Qualitaet '.$pvQuality['code'].')';
            $status=implode(' | ',$statusParts);
            $this->dwSetIfDifferent($vStatus,$status);
            $this->SendDebug('Wetterstatus', $status, 0);
        } catch (Throwable $e) {
            $this->dwLog('Modul-Laufzeitfehler: ' . $this->dwSafeFetchError($e->getMessage()));
            $this->SendDebug('Fehler', $this->dwSafeFetchError($e->getMessage()), 0);
        } finally {
            date_default_timezone_set($previousTimezone);
            IPS_SemaphoreLeave($lock);
        }
    }

    /** GET nur Leseseite, POST nur mit geheimem Instanz-Token. */
    protected function ProcessHookData(): void
    {
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        $token = $this->ReadAttributeString('ControlToken');
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
        if ($method === 'GET' && (string)($_GET['dwd_view'] ?? '') === 'warnings') {
            $expected = hash_hmac('sha256', 'dwd-warnings-only-v1', $token);
            if ($token === '' || !hash_equals($expected, (string)($_GET['dwd_key'] ?? ''))) {
                $this->hookResponse(403, 'Warnungsansicht nicht autorisiert.', false);
                return;
            }
            $warnings = json_decode((string)$this->GetValue('DWD_CACHE_WARNINGS'), true);
            $warnings = is_array($warnings) ? $warnings : [];
            $stamp = (int)$this->GetValue('DWD_WARNING_TIME');
            $valid = (bool)$this->GetValue('DWD_WARN_VALID') && $stamp > 0 && $stamp >= time() - 3600;
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
                '<title>DWD Warnungen ' . $this->dwEsc($this->setting('DW_ORT')) . '</title>' .
                '<style>html,body{margin:0;background:#0b0d10}body{padding:12px;max-width:1080px;margin:auto}</style>' .
                '</head><body>' . $this->dwWarningHtml($warnings, $valid, $stamp) . '</body></html>';
            return;
        }
        if ($method !== 'POST' || $token === '' ||
            !hash_equals($token, (string)($_POST['dwd_token'] ?? ''))) {
            $this->hookResponse(403, 'Aktion nicht autorisiert.', false);
            return;
        }
        if ((string)($_POST['dwd_action'] ?? '') !== 'refresh') {
            $this->hookResponse(400, 'Unbekannte Aktion.', false);
            return;
        }
        $success = $this->RequestRefresh();
        $this->hookResponse($success ? 202 : 429,
            $success ? 'Datenabruf angefordert. Kacheln aktualisieren sich danach.' : 'Bitte kurz warten: Abruf bereits angefordert oder Sperrfrist aktiv.',
            $success);
    }

    private function hookResponse(int $code, string $message, bool $success): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Frame-Options: SAMEORIGIN');
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><style>' .
            'html,body{background:#15171b;color:' . ($success ? '#b8e3bd' : '#ffc5ad') . ';font:12px system-ui,sans-serif;margin:0;padding:4px}' .
            '</style></head><body>' . $this->dwEsc($message) . '</body></html>';
    }

    /** Importiert ALTE Daten unter der bisherigen Kategorie, nie Variablen-IDs selbst verschieben. */
    public function ImportLegacy(): int
    {
        $oldCategory = (int)$this->ReadPropertyInteger('LegacyCategoryID');
        if ($oldCategory <= 0 || !IPS_ObjectExists($oldCategory) || $oldCategory === $this->InstanceID) {
            throw new RuntimeException('Bitte die alte DWD-Kategorie-ID als LegacyCategoryID einstellen.');
        }
        if ($this->ReadAttributeBoolean('LegacyImported')) {
            throw new RuntimeException('Altdaten wurden fuer diese Instanz bereits importiert.');
        }
        $idMap = $this->ensureVariables();
        $copy = [
            'DWD_CACHE_FORECAST', 'DWD_CACHE_WARNINGS', 'DWD_CACHE_ICON', 'DWD_CACHE_ECMWF',
            'DWD_FORECAST_TIME', 'DWD_WARNING_TIME', 'DWD_ICON_TIME', 'DWD_ECMWF_TIME',
            'DWD_STATION_ID', 'DWD_STATION_NAME', 'DWD_LAST_STATION_SEARCH',
            'DWD_ECMWF_MODELNAME', 'DWD_FETCH_STATE_JSON', 'DWD_PV_IST_STATE_JSON'
        ];
        $count = 0;
        foreach ($copy as $ident) {
            $src = @IPS_GetObjectIDByIdent($ident, $oldCategory);
            $dst = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($src === false || $dst === false) {
                continue;
            }
            $a = IPS_GetVariable($src)['VariableType'];
            $b = IPS_GetVariable($dst)['VariableType'];
            if ($a !== $b) {
                continue;
            }
            $this->dwSetIfDifferent($dst, GetValue($src));
            $count++;
        }
        if ($count === 0) {
            throw new RuntimeException('Keine kompatiblen Variablen in der bisherigen Kategorie gefunden.');
        }
        $this->WriteAttributeBoolean('LegacyImported', true);
        $this->dwLog('Alte Wetter-/PV-Lerndaten importiert: ' . $count . ' Werte.');
        return $count;
    }
}
