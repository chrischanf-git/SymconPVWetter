<?php
declare(strict_types=1);

/** Logik des bisherigen DWD-Skripts V3.5, nun pro Instanz gekapselt. */
trait DWDWeatherEngine
{
private function dwEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


private function dwFmt($value, int $digits = 1): string
{
    return is_numeric($value) ? number_format((float)$value, $digits, ',', '.') : '–';
}


private function dwLog(string $msg): void
{
    IPS_LogMessage('DWD MOSMIX '.$this->setting('DW_ORT'), $msg);
}


private function dwHttp(string $url, int $maxBytes = 3000000): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET', 'timeout' => $this->setting('DW_HTTP_TIMEOUT_S'), 'ignore_errors' => true,
            'header' => "User-Agent: IPSymcon-DWD-MOSMIX/3.5\r\nAccept: */*\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
    ]);
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $lastWarning = '';
        unset($http_response_header); // Never reuse response headers from the previous attempt.
        // Capture only this HTTP request's warning, not a previous PHP warning.
        set_error_handler(static function (int $level, string $message) use (&$lastWarning): bool {
            $lastWarning = $message;
            return true;
        });
        try {
            $result = file_get_contents($url, false, $context, 0, $maxBytes + 1);
            $headers = $http_response_header ?? [];
        } finally {
            restore_error_handler();
        }
        $code = 0;
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $header, $m)) {
                $code = (int)$m[1];
            }
        }
        if ($result !== false && strlen($result) > $maxBytes) {
            throw new RuntimeException('Antwort groesser als zulaessige Downloadgroesse');
        }
        if ($result !== false && ($code === 0 || $code === 200) && $result !== '') {
            return $result;
        }
        $reason = $result === false
            ? 'Verbindungsfehler: '.($lastWarning !== '' ? $lastWarning : 'keine Antwort')
            : ($result === '' ? 'Leere Serverantwort' : 'HTTP '.$code);
        // If the server actively rejected a request (e.g. 400 or 401), do not retry.
        $transient = $result === false || $result === '' ||
            in_array($code, [408, 425, 429, 500, 502, 503, 504], true);
        if (preg_match('~(certificate verify failed|SSL certificate|certificate has expired|hostname mismatch)~i', $reason)) {
            $transient = false;
        }
        // Prevent the secret from appearing in logs or UI if it was embedded in the URL.
        $reason = $this->dwSafeFetchError($reason);
        if ($transient && $attempt === 1) {
            $this->dwLog('Download kurzzeitig fehlgeschlagen; zweiter Versuch nach ' .
                $this->setting('DW_HTTP_RETRY_PAUSE_MS') . ' ms. Ursache: ' . $reason);
            usleep(max(0, $this->setting('DW_HTTP_RETRY_PAUSE_MS')) * 1000);
            continue;
        }
        throw new RuntimeException('Download nach '.$attempt.' Versuch'.($attempt===1?'':'en').' fehlgeschlagen: '.$reason);
    }
    throw new RuntimeException('Download fehlgeschlagen');
}


private function dwSafeFetchError(string $message): string
{
    // Avoid leaking customer API keys in Symcon logs, status variables or JSON.
    if ($this->setting('DW_OM_API_KEY') !== '') {
        $message = str_replace([$this->setting('DW_OM_API_KEY'), rawurlencode($this->setting('DW_OM_API_KEY'))], '[geschuetzt]', $message);
    }
    $message = preg_replace('~([?&]apikey=)[^&\s)]+~i', '$1[geschuetzt]', $message) ?? $message;
    if (preg_match('~(php_network_getaddresses|getaddrinfo|name resolution|could not resolve host)~i', $message)) {
        return 'DNS-Namensaufloesung fehlgeschlagen';
    }
    if (preg_match('~(connection timed out|operation timed out|timed out)~i', $message)) {
        return 'Zeitueberschreitung beim Serverkontakt';
    }
    return $this->dwSubstrSafe(trim($message), 420);
}


private function dwSubstrSafe(string $value, int $limit): string
{
    // mbstring is optional in the Symcon runtime.
    if (function_exists('mb_substr')) return mb_substr($value, 0, $limit, 'UTF-8');
    return substr($value, 0, $limit);
}

/** Liest den ersten .kml-Eintrag aus einer KMZ (ZIP), direkt im RAM. */


private function dwKmlFromKmz(string $zip): string
{
    // ZIP End of Central Directory (ohne ZipArchive und ohne temporaere Dateien)
    $end = strrpos($zip, "PK\x05\x06");
    if ($end === false || strlen($zip) < $end + 22) {
        throw new RuntimeException('Ungueltige KMZ-Datei (ZIP-Ende fehlt)');
    }
    $count = unpack('v', substr($zip, $end + 10, 2))[1];
    $offset = unpack('V', substr($zip, $end + 16, 4))[1];
    for ($entry = 0; $entry < min($count, 30); $entry++) {
        if (substr($zip, $offset, 4) !== "PK\x01\x02") {
            throw new RuntimeException('ZIP-Zentralverzeichnis ungueltig');
        }
        $method = unpack('v', substr($zip, $offset + 10, 2))[1];
        $packed = unpack('V', substr($zip, $offset + 20, 4))[1];
        $unpacked = unpack('V', substr($zip, $offset + 24, 4))[1];
        $nameLen = unpack('v', substr($zip, $offset + 28, 2))[1];
        $extraLen = unpack('v', substr($zip, $offset + 30, 2))[1];
        $commentLen = unpack('v', substr($zip, $offset + 32, 2))[1];
        $local = unpack('V', substr($zip, $offset + 42, 4))[1];
        $name = substr($zip, $offset + 46, $nameLen);
        $offset += 46 + $nameLen + $extraLen + $commentLen;
        if (!preg_match('/\.kml$/i', $name)) {
            continue;
        }
        if ($unpacked > 7000000 || $packed > 3000000) {
            throw new RuntimeException('KMZ-Inhalt zu gross');
        }
        if (substr($zip, $local, 4) !== "PK\x03\x04") {
            throw new RuntimeException('ZIP-Dateikopf ungueltig');
        }
        $localNameLen = unpack('v', substr($zip, $local + 26, 2))[1];
        $localExtraLen = unpack('v', substr($zip, $local + 28, 2))[1];
        $payloadOffset = $local + 30 + $localNameLen + $localExtraLen;
        $compressed = substr($zip, $payloadOffset, $packed);
        if (strlen($compressed) !== $packed) {
            throw new RuntimeException('KMZ-Daten sind unvollstaendig');
        }
        $xml = $method === 0 ? $compressed : ($method === 8 ? @gzinflate($compressed) : false);
        if (!is_string($xml) || strlen($xml) < 100 || strlen($xml) > 7000000) {
            throw new RuntimeException('KMZ-Kompressionsformat ungueltig/nicht unterstuetzt');
        }
        return $xml;
    }
    throw new RuntimeException('Keine KML-Datei in KMZ gefunden');
}

/** Die bei DWD veroeffentlichten Stationskoordinaten sind Grad.Minuten, nicht Dezimalgrad. */


private function dwDegreesMinutes(string $text): ?float
{
    if (!preg_match('/^(-?)(\d{1,3})\.(\d{1,2})$/', $text, $m)) {
        return null;
    }
    if ((int)$m[3] > 59) {
        return null;
    }
    $value = (int)$m[2] + (int)$m[3] / 60;
    return $m[1] === '-' ? -$value : $value;
}


private function dwKm(float $latA, float $lonA, float $latB, float $lonB): float
{
    $p1 = deg2rad($latA);
    $p2 = deg2rad($latB);
    $dl = deg2rad($lonB - $lonA);
    $dp = deg2rad($latB - $latA);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 12742.0 * asin(min(1.0, sqrt($a)));
}


private function dwFindLocalStation(): array
{
    if ($this->setting('DW_STATION_MANUELL') !== '') {
        return ['id' => $this->setting('DW_STATION_MANUELL'), 'name' => 'Manuell', 'km' => null];
    }
    $url = 'https://www.dwd.de/DE/leistungen/met_verfahren_mosmix/mosmix_stationskatalog.cfg?view=nasPublication&nn=16102';
    $catalog = $this->dwHttp($url, 800000);
    $candidates = [];
    $ortKurz = trim(explode('(', trim(explode('/', $this->setting('DW_ORT'), 2)[0]), 2)[0]);
    foreach (preg_split('/\R/', $catalog) as $line) {
        $cols = preg_split('/\s+/', trim($line));
        if (count($cols) < 6) {
            continue;
        }
        $n = count($cols);
        $id = $cols[0];
        if (!preg_match('/^[A-Z0-9]{4,7}$/i', $id)) {
            continue;
        }
        $latitude = $this->dwDegreesMinutes($cols[$n - 3]);
        $longitude = $this->dwDegreesMinutes($cols[$n - 2]);
        $height = is_numeric($cols[$n - 1]) ? (int)$cols[$n - 1] : null;
        if ($latitude === null || $longitude === null || $height === null) {
            continue;
        }
        $km = $this->dwKm($this->setting('DW_LAT'), $this->setting('DW_LON'), $latitude, $longitude);
        if ($km > 30) {
            continue;
        }
        $name = implode(' ', array_slice($cols, 2, -3));
        $score = $km + min(12, abs($height - $this->setting('DW_HOEHE_M')) / 75);
        if ($ortKurz !== '' && preg_match('~^'.preg_quote($ortKurz, '~').'(?:\W|$)~iu', $name)) {
            $score -= 50; // Ein lokaler Vorhersagepunkt ist besonders geeignet.
        }
        $candidates[] = ['id' => $id, 'name' => $name, 'km' => round($km, 1), 'score' => $score];
    }
    if (!$candidates) {
        throw new RuntimeException('DWD-Stationskatalog enthaelt keinen gueltigen Punkt um ' . $this->setting('DW_ORT'));
    }
    usort($candidates, static fn($a, $b) => $a['score'] <=> $b['score']);
    return ['id' => $candidates[0]['id'], 'name' => $candidates[0]['name'], 'km' => $candidates[0]['km']];
}



private function dwReadMosmix(string $xml): array
{
    // Durch Namensraum-Prefixe unabhaengiger, kompakter Parser des DWD-KML-Formats.
    preg_match_all('~<(?:[\w.-]+:)?TimeStep\b[^>]*>([^<]+)</(?:[\w.-]+:)?TimeStep>~i', $xml, $matches);
    $steps = [];
    foreach ($matches[1] as $datetime) {
        try {
            $steps[] = (new DateTimeImmutable(trim($datetime)))->getTimestamp();
        } catch (Throwable $e) {
            throw new RuntimeException('Ungueltige Zeitangabe in MOSMIX-KML');
        }
    }
    if (count($steps) < 24) {
        throw new RuntimeException('MOSMIX-KML: zu wenige Zeitschritte (' . count($steps) . ')');
    }
    preg_match_all('~<(?:[\w.-]+:)?Forecast\b[^>]*\b(?:[\w.-]+:)?elementName\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</(?:[\w.-]+:)?Forecast>~is', $xml, $forecasts, PREG_SET_ORDER);
    $wanted = ['TTT' => true, 'RR1c' => true, 'R101' => true, 'SunD1' => true,
               'FF' => true, 'N' => true, 'ww' => true, 'Rad1h' => true];
    $columns = [];
    foreach ($forecasts as $forecast) {
        $param = $forecast[1];
        if (!isset($wanted[$param])) {
            continue;
        }
        $values = preg_split('/\s+/', trim(strip_tags($forecast[2])));
        if (count($values) !== count($steps)) {
            $this->dwLog('MOSMIX-Feld ' . $param . ' hat ' . count($values) . ' statt ' . count($steps) . ' Werte.');
            continue;
        }
        $columns[$param] = array_map(static function ($value) {
            return is_numeric($value) ? (float)$value : null;
        }, $values);
    }
    if (!isset($columns['TTT'])) {
        throw new RuntimeException('MOSMIX-KML enthaelt keine passende Temperaturreihe (TTT)');
    }
    $rows = [];
    foreach ($steps as $i => $timestamp) {
        $at = static function (string $key) use ($columns, $i): ?float {
            return $columns[$key][$i] ?? null;
        };
        $temp = $at('TTT');
        $rain = $at('RR1c');
        $sun = $at('SunD1');
        $wind = $at('FF');
        $rad = $at('Rad1h'); // kJ/m2 je vorheriger Stunde
        $rows[] = [
            'ts'   => $timestamp,
            'temp' => $temp === null ? null : round($temp - 273.15, 2),
            'rain' => $rain === null ? null : max(0.0, $rain),
            'prob' => $at('R101'),
            'sun'  => $sun === null ? null : min(3600.0, max(0.0, $sun)),
            'wind' => $wind === null ? null : max(0.0, $wind * 3.6),
            'cloud'=> $at('N'),
            'ghi'  => $rad === null ? null : max(0.0, round($rad / 3.6, 2)), // W/m2 (Stundenmittel)
            'ww'   => $at('ww'),
        ];
    }
    return $rows;
}



private function dwWarningsFromFeed(string $raw): array
{
    $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw));
    if ($raw === '' || $raw[0] !== '{') {
        // DWD-Warnapp liefert teils JSONP: warnWetter.loadWarnings({...});
        if (!preg_match('/^\s*warnWetter\.loadWarnings\s*\(\s*(\{.*\})\s*\)\s*;?\s*$/s', $raw, $m)) {
            throw new RuntimeException('DWD-Warnungen: unerwartetes JSON/JSONP-Format');
        }
        $raw = $m[1];
    }
    $doc = json_decode($raw, true);
    if (!is_array($doc) || !isset($doc['warnings']) || !is_array($doc['warnings'])) {
        throw new RuntimeException('DWD-Warnungen: ungueltige Datenstruktur (' . json_last_error_msg() . ')');
    }
    $warn = [];
    foreach (['warnings', 'vorabInformation'] as $kind) {
        $groups = $doc[$kind] ?? [];
        if (!is_array($groups)) {
            continue;
        }
        foreach ($groups as $warnCellID => $entries) {
            if ((string)$warnCellID !== $this->setting('DW_WARNCELL_ID') || !is_array($entries)) {
                continue;
            }
            foreach ($entries as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $end = $this->dwTime($item['end'] ?? 0);
                if ($end > 0 && $end < time()) {
                    continue; // abgelaufene Warnungen nicht anzeigen
                }
                $warn[] = [
                    'type' => (int)($item['type'] ?? -1),
                    'level' => $kind === 'vorabInformation' ? 1 : (int)($item['level'] ?? 2),
                    'start' => $this->dwTime($item['start'] ?? 0),
                    'end' => $end,
                    'headline' => (string)($item['headline'] ?? ($item['event'] ?? 'Wetterwarnung')),
                    'event' => (string)($item['event'] ?? 'Wetterwarnung'),
                    'description' => (string)($item['description'] ?? ''),
                    'instruction' => (string)($item['instruction'] ?? ''),
                    'regionName' => (string)($item['regionName'] ?? 'Erzgebirgskreis'),
                ];
            }
        }
    }
    usort($warn, static function (array $a, array $b): int {
        return ($b['level'] <=> $a['level']) ?: ($a['start'] <=> $b['start']);
    });
    return $warn;
}


private function dwTime($time): int
{
    if (!is_numeric($time)) {
        return 0;
    }
    $t = (float)$time;
    return (int)floor($t > 100000000000 ? $t / 1000 : $t);
}


private function dwWeather(?float $code, ?float $cloud = null): array
{
    $w = $code === null ? -1 : (int)$code;
    if (in_array($w, [95, 96, 99], true)) return ['⛈️', 'Gewitter'];
    if (($w >= 50 && $w <= 67) || ($w >= 80 && $w <= 82)) return ['🌧️', 'Regen'];
    if (($w >= 68 && $w <= 79) || ($w >= 83 && $w <= 90)) return ['🌨️', 'Schnee / Schauer'];
    if ($w >= 40 && $w <= 49) return ['🌫️', 'Nebel'];
    if ($w >= 90) return ['🌦️', 'Schauer / Gewitter'];
    if ($cloud !== null) {
        if ($cloud < 18) return ['☀️', 'Heiter'];
        if ($cloud < 45) return ['🌤️', 'Leicht bewölkt'];
        if ($cloud < 78) return ['⛅', 'Bewölkt'];
        return ['☁️', 'Bedeckt'];
    }
    return ['🌤️', 'Wechselhaft'];
}


private function dwAggregateDay(array $rows, int $days = 0): array
{
    $days = $days > 0 ? $days : (int)$this->setting('DW_TAGE');
    $localDays = [];
    $now = time();
    foreach ($rows as $row) {
        if ($row['ts'] < $now - 3600) continue;
        $key = date('Y-m-d', $row['ts']);
        if (!isset($localDays[$key])) {
            $localDays[$key] = ['day' => $key, 'tmin' => null, 'tmax' => null,
                'rain' => 0.0, 'rainN' => 0, 'sun' => 0.0, 'sunN' => 0, 'ghi' => 0.0, 'ghiN' => 0,
                'prob' => null, 'wind' => null, 'noon' => null, 'noonDiff' => PHP_INT_MAX];
        }
        $d = &$localDays[$key];
        if ($row['temp'] !== null) {
            $d['tmin'] = $d['tmin'] === null ? $row['temp'] : min($d['tmin'], $row['temp']);
            $d['tmax'] = $d['tmax'] === null ? $row['temp'] : max($d['tmax'], $row['temp']);
        }
        if ($row['rain'] !== null) { $d['rain'] += $row['rain']; $d['rainN']++; }
        if ($row['sun'] !== null) { $d['sun'] += $row['sun']; $d['sunN']++; }
        if (isset($row['ghi']) && $row['ghi'] !== null) { $d['ghi'] += $row['ghi'] / 1000; $d['ghiN']++; }
        if ($row['prob'] !== null) $d['prob'] = $d['prob'] === null ? $row['prob'] : max($d['prob'], $row['prob']);
        if ($row['wind'] !== null) $d['wind'] = $d['wind'] === null ? $row['wind'] : max($d['wind'], $row['wind']);
        $noonDiff = abs((int)date('H', $row['ts']) - 13);
        if ($noonDiff < $d['noonDiff']) { $d['noonDiff'] = $noonDiff; $d['noon'] = $row; }
        unset($d);
    }
    return array_slice(array_values($localDays), 0, $days);
}


private function dw24hours(array $rows): array
{
    $now = time();
    $cutoff = (int)(ceil($now / 3600) * 3600);
    $out = [];
    foreach ($rows as $row) {
        if ($row['ts'] >= $cutoff) $out[] = $row;
        if (count($out) >= $this->setting('DW_STUNDEN')) break;
    }
    return $out;
}
/** Drehung: 0=Sued, 45=Suedwest, -45=Suedost, +/-180=Nord. */


private function dwPvDirection(float $azimuth): string
{
    $bearing = fmod(180 + $azimuth + 3600, 360);
    $names = ['Nord', 'Nordost', 'Ost', 'Suedost', 'Sued', 'Suedwest', 'West', 'Nordwest'];
    $index = ((int)round($bearing / 45)) % 8;
    return str_replace(['Suedwest','Suedost','Sued'], ['Südwest','Südost','Süd'], $names[$index]);
}


private function dwPvSpecs(bool $compact = false): string
{
    $base = $this->dwFmt($this->setting('DW_PV_KWP'), 1) . ' kWp · ' . $this->dwFmt($this->setting('DW_PV_TILT'), 0) . '° Dachneigung · ' .
        $this->dwEsc($this->dwPvDirection((float)$this->setting('DW_PV_AZIMUTH')));
    if ($compact) return $base;
    return $base . ' (Azimut ' . $this->dwFmt($this->setting('DW_PV_AZIMUTH'), 0) . '° ab Süd)' .
        ' · PR ' . $this->dwFmt($this->setting('DW_PV_PR') * 100, 0) . ' %' .
        ($this->setting('DW_PV_AC_LIMIT_W') > 0 ? ' · AC-Limit ' . $this->dwFmt($this->setting('DW_PV_AC_LIMIT_W'), 0) . ' W' : '');
}

/** Einfacher POST-Button, der auch in einer HTMLBox ohne eingebettetes JavaScript nutzbar ist. */


private function dwHookForm(string $action, string $caption, string $hook, string $token,
    string $frameName, string $buttonClass, bool $showFeedback): string
{
    $result = '<form class="dwx-action-form" method="post" action="'.$this->dwEsc($hook).'" target="'.$this->dwEsc($frameName).'">' .
        '<input type="hidden" name="dwd_action" value="'.$this->dwEsc($action).'">' .
        '<input type="hidden" name="dwd_token" value="'.$this->dwEsc($token).'">' .
        '<button type="submit" class="'.$this->dwEsc($buttonClass).'">'.$this->dwEsc($caption).'</button></form>';
    return $result . '<iframe class="dwx-action-frame'.($showFeedback ? '' : ' dwx-hidden-frame').'" name="'.$this->dwEsc($frameName).'" title="Symcon-Aktion" loading="lazy"></iframe>';
}


private function dwWarnBadgeLabel(array $warnings, bool $valid, int $fetched): array
{
    if (!$valid || $fetched < time() - 3600) return ['⚠️ Warnstatus ungeprüft', 'dwx-pill dwx-error'];
    if ($warnings) return ['⚠️ ' . count($warnings) . ' Warnung' . (count($warnings)===1?'':'en'), 'dwx-pill dwx-alert'];
    return ['✓ Keine Warnungen', 'dwx-pill'];
}
/** Nur-Lese-Zugriff auf Warnungen. Ein HMAC fuer die Ansicht kann keinen Abruf starten. */


private function dwWarningViewUrl(string $hook, string $token): string
{
    if ($hook === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) return '';
    return $hook . '?dwd_view=warnings&dwd_key=' .
        rawurlencode(hash_hmac('sha256', 'dwd-warnings-only-v1', $token));
}
/** Ein normaler Link funktioniert ohne Script, iFrame oder VISU_OpenObject. */


private function dwForecastWarningButton(array $warnings, bool $valid, int $fetched,
    string $hook, string $token, string $frameName, bool $hookReady): string
{
    [$caption, $class] = $this->dwWarnBadgeLabel($warnings, $valid, $fetched);
    $url = $hookReady ? $this->dwWarningViewUrl($hook, $token) : '';
    if ($url === '') {
        return '<span class="'.$class.'" title="Warnungsuebersicht derzeit nicht verlinkt">'.$this->dwEsc($caption).'</span>';
    }
    return '<a href="'.$this->dwEsc($url).'" target="_blank" rel="noopener noreferrer" ' .
        'class="'.$class.' dwx-clickable" title="Vollstaendige DWD-Warnungsuebersicht oeffnen">' .
        $this->dwEsc($caption).' &#8599;</a>';
}

/** Wird nur bei Ersteinrichtung/manuellem Konsolenstart geprüft, nicht alle 5 Minuten. */


private function dwCSS(): string
{
    return <<<'CSS'
<style>
.dwx{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;color:#eceef2;background:linear-gradient(145deg,#08090b,#15171b 78%);border:1px solid #35383d;border-radius:14px;padding:18px;box-sizing:border-box;overflow:hidden;color-scheme:dark}
.dwx *{box-sizing:border-box}.dwx-head{display:flex;justify-content:space-between;align-items:flex-start;gap:9px;flex-wrap:wrap}.dwx h2{font-size:21px;font-weight:750;letter-spacing:-.4px;line-height:1.25;margin:0}.dwx-sub{margin-top:5px;font-size:12px;line-height:1.55;color:#a4a9b4}.dwx-muted{font-size:12px;color:#a7aab3}.dwx-small{font-size:11px;color:#aab0bc;line-height:1.6}.dwx-strong{color:#f3f4f6}.dwx-muted b{color:#e5e7eb}
.dwx-pill{display:inline-flex;align-items:center;gap:5px;background:#18241f;color:#a9e4c1;border:1px solid #386149;border-radius:8px;padding:6px 9px;font-size:12px;font-weight:650;max-width:100%}.dwx-pill.dwx-alert{background:#624125;color:#ffdd9b;border-color:#c58a4e}.dwx-pill.dwx-error{background:#572c29;color:#ffd0ca;border-color:#bc5c52}.dwx-pill.dwx-quiet{background:#22262b;color:#c9cbd1;border-color:#42464e}
.dwx-days{display:grid;grid-template-columns:repeat(auto-fit,minmax(121px,1fr));gap:8px;margin-top:15px}.dwx-day{background:#1b1d22;border:1px solid #363a42;border-radius:8px;padding:12px 9px;text-align:center;min-height:176px}.dwx-date{font-size:12px;font-weight:750}.dwx-icon{font-size:27px;margin:6px 0 2px}.dwx-desc{min-height:29px;font-size:11px;color:#aeb4c0}.dwx-temp{font-weight:750;font-size:22px;letter-spacing:-.7px;margin:8px 0}.dwx-temp small{font-size:13px;font-weight:450;color:#aeb6c0}.dwx-meta{font-size:11px;line-height:1.9;color:#ccd2df}
.dwx-notice{font-size:12px;line-height:1.55;background:#393223;color:#ffe5b6;border:1px solid #866638;border-radius:8px;padding:10px 11px;margin-top:13px}.dwx-errorbox{border-radius:8px;margin-top:13px;padding:12px;font-size:12px;line-height:1.55;background:#492522;color:#ffe1db;border:1px solid #8f4940}.dwx-ok{background:#123329;border:1px solid #327868;border-radius:8px;padding:14px;margin-top:14px;color:#b7f4d9}
.dwx-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(143px,1fr));gap:8px;margin-top:15px}.dwx-stat{background:#1b1d22;border:1px solid #353940;border-radius:8px;padding:12px;min-width:0}.dwx-stat label{display:block;font-size:11px;color:#a3a9b6;margin-bottom:6px;line-height:1.4}.dwx-stat strong{font-size:22px;font-weight:760;letter-spacing:-.5px;white-space:nowrap}.dwx-stat strong small{font-size:12px;font-weight:450;color:#b5bac6}.dwx-stat .dwx-detail{margin-top:4px;font-size:11px;color:#a9afbb}
.dwx-chart-block{margin-top:16px}.dwx-chart-head{display:flex;gap:5px 12px;justify-content:space-between;flex-wrap:wrap;align-items:center;margin-bottom:7px}.dwx-chart-head h3{font-size:14px;font-weight:750;margin:0}.dwx-chart-head span{font-size:11px;color:#aeb5c1}.dwx-chart-scroll{width:100%;overflow-x:auto;background:#121418;border:1px solid #30343c;border-radius:8px;padding:8px 6px 2px}.dwx-chart-scroll svg{display:block;width:100%;min-width:630px;height:auto}.dwx-tiny{font-size:11px;line-height:1.6;color:#939aa7;margin-top:9px}
.dwx-key{display:flex;gap:9px 15px;flex-wrap:wrap;margin-top:11px;font-size:11px;color:#c5c9d1}.dwx-dot{display:inline-block;width:8px;height:8px;vertical-align:middle;border-radius:2px;margin-right:5px}.dwx-models{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px;margin-top:14px}.dwx-model{background:#1b1d22;border:1px solid #353940;border-radius:8px;padding:12px}.dwx-model h3{font-size:13px;font-weight:750;margin:0 0 6px}.dwx-model p{margin:5px 0;font-size:12px;line-height:1.5;color:#c9cfdb}.dwx-model .dwx-num{font-size:23px;letter-spacing:-.7px;font-weight:750;margin:4px 0}.dwx-model small{font-size:11px;color:#a6afbc}
.dwx-pvrow{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:7px;margin-top:12px}.dwx-pvday{background:#1b1d22;border:1px solid #393d44;border-radius:8px;padding:8px 5px;text-align:center}.dwx-pvday .dwx-dlabel{font-size:10px;color:#b9c0cc}.dwx-pvday .dwx-kwh{font-size:15px;font-weight:760;margin:7px 0}.dwx-pvday .dwx-subline{font-size:10px;color:#a7afba}.dwx-footer{font-size:11px;color:#9199a4;line-height:1.7;margin-top:16px}.dwx-footer a{color:#b4c8e3;text-decoration:none}
.dwx-warning{margin-top:12px;padding:13px;border-radius:8px;border:1px solid #b48c4d;background:#4a3828}.dwx-warning.dwx-l4{background:#612d2c;border-color:#e2655f}.dwx-warning.dwx-l5{background:#5b263e;border-color:#ed6196}.dwx-warning.dwx-l3{background:#644425;border-color:#d99a4e}.dwx-warning.dwx-l2{background:#39465a;border-color:#6fa6da}.dwx-warning.dwx-l1{background:#3a4b5e;border-color:#7195b6}.dwx-hourtable{margin-top:12px;overflow-x:auto;background:#131519;border:1px solid #30343c;border-radius:7px}
.dwx-hourtable table{width:100%;border-collapse:collapse;min-width:560px;font-size:12px}
.dwx-hourtable th{background:#1b1e23;color:#c1c7d1;text-align:right;white-space:nowrap;font-weight:700;padding:10px 10px;border-bottom:1px solid #393e48}
.dwx-hourtable th:first-child,.dwx-hourtable td:first-child{text-align:left}
.dwx-hourtable td{padding:8px 10px;text-align:right;font-variant-numeric:tabular-nums;border-bottom:1px solid #292d34;color:#dce0e7;white-space:nowrap}
.dwx-hourtable tr:nth-child(2n) td{background:#191c20}.dwx-hourtable tr:last-child td{border-bottom:0}
.dwx-hourtable .dwx-source{font-size:10px;color:#aeb7c4}.dwx-solar{color:#efc46c;font-weight:700}.dwx-legend{display:flex;flex-wrap:wrap;gap:8px 13px;margin-top:9px;font-size:11px;color:#b9c1cc}
.dwx-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:middle}
.dwx-action-form{margin:0;display:inline-flex;max-width:100%}.dwx-action-form button{cursor:pointer;font-family:inherit}.dwx-action-form button:hover{filter:brightness(1.15)}.dwx-action-form button:focus-visible{outline:2px solid #f0c75c;outline-offset:2px}.dwx-clickable{text-decoration:underline;text-decoration-style:dotted;text-underline-offset:3px}.dwx-tools{display:flex;flex-direction:column;align-items:flex-end;gap:4px}.dwx-refresh{border-radius:7px;border:1px solid #7a683c;background:#2d291e;color:#f2d891;padding:9px 13px;font-size:12px;font-weight:720}.dwx-action-frame{display:block;width:280px;max-width:100%;height:30px;border:0;background:transparent;color:#b7dac1;overflow:hidden}.dwx-hidden-frame{display:none;width:0;height:0;visibility:hidden}
.dwx-pill.dwx-clickable{cursor:pointer;text-decoration:none;transition:filter .12s}.dwx-pill.dwx-clickable:hover{filter:brightness(1.25)}
.dwx-twohour-grid table{min-width:880px}.dwx-twohour-grid th:nth-child(2),.dwx-twohour-grid td:nth-child(2){text-align:left}.dwx-twohour-grid td:nth-child(2){color:#d3d7df}.dwx-twohour-grid td:nth-child(3){font-weight:700}
.dwx-warn-state{margin-top:15px;border:1px solid #454a54;border-radius:8px;padding:16px;display:flex;flex-direction:column;gap:7px;background:#22262c}.dwx-warn-state strong{font-size:19px}.dwx-warn-state span{font-size:12px;line-height:1.6}.dwx-warn-state-clear{background:#142c22;border-color:#387353;color:#b4edc9}.dwx-warn-state-error{background:#552e2c;border-color:#bc5c52;color:#ffd2ce}.dwx-warn-state-active{background:#513b29;border-color:#b88a4b;color:#ffe4b9}.dwx-warn-state-active.dwx-l2{background:#39465a;border-color:#6fa6da}.dwx-warn-state-active.dwx-l3{background:#644425;border-color:#d99a4e}.dwx-warn-state-active.dwx-l4{background:#612d2c;border-color:#e2655f}.dwx-warn-state-active.dwx-l5{background:#5b263e;border-color:#ed6196}
.dwx-warning h3{font-size:15px;margin:0 0 8px;line-height:1.35}.dwx-warning p{font-size:12px;line-height:1.6;color:#f1f3f6;margin:8px 0 0}.dwx-warning .dwx-time{font-size:11px;color:#d3e0ed}
@media(max-width:680px){.dwx{padding:12px}.dwx h2{font-size:18px}.dwx-days{grid-template-columns:repeat(2,minmax(0,1fr))}.dwx-pvrow{grid-template-columns:repeat(4,minmax(0,1fr))}.dwx-stat strong{font-size:20px}}
</style>
CSS;
}


private function dwOpenMeteoCredit(): string
{
    return 'ICON-D2 / ECMWF bereitgestellt durch <a href="https://open-meteo.com/" target="_blank" rel="noopener">Open-Meteo</a> (CC BY 4.0).';
}


private function dwWarnBadge(array $warnings, bool $valid, int $fetched): string
{
    // Ohne aktive Warnungen in PV-, Modell- und Warnkacheln keine Entwarnungs-Pille anzeigen.
    if (!$valid || $fetched < time() - 3600) return '<span class="dwx-pill dwx-error">⚠️ Warnstatus ungeprüft</span>';
    if ($warnings) return '<span class="dwx-pill dwx-alert">⚠️ ' . count($warnings) . ' Warnung' . (count($warnings) === 1 ? '' : 'en') . '</span>';
    return '';
}


private function dwFooter(string $stationName, string $stationId, int $forecastStamp, int $warnStamp, bool $warnOK, bool $modelsUsed = false): string
{
    $f = $forecastStamp ? date('d.m. H:i', $forecastStamp) : 'unbekannt';
    $w = $warnStamp ? date('d.m. H:i', $warnStamp) : 'noch nie';
    return '<div class="dwx-footer">DWD MOSMIX_L · Punkt: ' . $this->dwEsc($stationName) . ' (' . $this->dwEsc($stationId) . ') · Wetter: ' . $f .
        ' Uhr · Warnungen: ' . $w . ' Uhr' . ($warnOK ? '' : ' (Abruf derzeit nicht verifiziert)') .
        '<br>© <a href="https://www.dwd.de/" target="_blank" rel="noopener">Deutscher Wetterdienst</a>' .
        ($modelsUsed ? ' · ' . $this->dwOpenMeteoCredit() : '') . '</div>';
}


private function dwDatedNotice(string $error, int $stamp, string $source): string
{
    if ($error !== '') return '<div class="dwx-notice">⚠️ ' . $this->dwEsc($source) . ': letzter Abruf fehlgeschlagen (' . $this->dwEsc($error) . '). Anzeige kann veraltet sein.</div>';
    if ($stamp <= 0) return '<div class="dwx-errorbox">Noch keine Daten von ' . $this->dwEsc($source) . ' vorhanden.</div>';
    return '';
}


private function dwDailyHtml(array $days, array $warnings, bool $warnOK, int $warnStamp,
    string $stationName, string $stationId, int $forecastStamp, string $forecastError = '',
    string $warnHook = '', string $warnToken = '', bool $hookReady = false, int $widgetId = 0): string
{
    $html = $this->dwCSS() . '<div class="dwx"><div class="dwx-head"><div><h2>🌤️ Wettervorhersage · '.$this->dwEsc($this->setting('DW_ORT')).'</h2>' .
        '<div class="dwx-sub">7 Tage · Originaldaten DWD MOSMIX_L</div></div>' . $this->dwForecastWarningButton($warnings, $warnOK, $warnStamp, $warnHook, $warnToken, 'dwx_warn7_' . $widgetId, $hookReady) . '</div>' .
        $this->dwDatedNotice($forecastError, $forecastStamp, 'MOSMIX');
    if (!$days) {
        $html .= '<div class="dwx-errorbox">Noch keine Vorhersage verfügbar. MOSMIX-Verbindung prüfen.</div>';
    } else {
        $html .= '<div class="dwx-days">';
        $today = date('Y-m-d'); $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $weekday = ['So','Mo','Di','Mi','Do','Fr','Sa'];
        foreach ($days as $day) {
            $ts = strtotime($day['day'] . ' 12:00:00');
            $label = $day['day'] === $today ? 'Heute*' : ($day['day'] === $tomorrow ? 'Morgen' : $weekday[(int)date('w', $ts)]);
            [$icon, $description] = $this->dwWeather($day['noon']['ww'] ?? null, $day['noon']['cloud'] ?? null);
            $html .= '<div class="dwx-day"><div class="dwx-date">' . $this->dwEsc($label) . ' · ' . date('d.m.', $ts) . '</div><div class="dwx-icon">' . $icon . '</div>' .
                '<div class="dwx-desc">' . $this->dwEsc($description) . '</div><div class="dwx-temp">' . $this->dwFmt($day['tmax'],0) . '° <small>/ ' . $this->dwFmt($day['tmin'],0) . '°</small></div>' .
                '<div class="dwx-meta">🌧️ ' . ($day['rainN'] ? $this->dwFmt($day['rain'],1) : '–') . ' mm · ' . $this->dwFmt($day['prob'],0) . ' %/h' .
                '<br>☀️ ' . ($day['sunN'] ? $this->dwFmt($day['sun']/3600,1) : '–') . ' h · 💨 ' . $this->dwFmt($day['wind'],0) . ' km/h' .
                '<br>⚡ Strahlung ' . (($day['ghiN'] ?? 0) ? $this->dwFmt($day['ghi'],2) : '–') . ' kWh/m²</div></div>';
        }
        $html .= '</div><div class="dwx-tiny">* Heute nur verbleibende Prognosestunden. Strahlung ist horizontal (GHI), kein direkter PV-Anlagenertrag. Regenrisiko: maximaler Stundenwert, keine Tageswahrscheinlichkeit.</div>';
    }
    return $html . $this->dwFooter($stationName, $stationId, $forecastStamp, $warnStamp, $warnOK) . '</div>';
}
/** For each model, parse hourly JSON with UNIX timestamps to avoid DST errors. */


private function dwParseApi(array $result, string $model): array
{
    if (!empty($result['error'])) throw new RuntimeException(substr((string)($result['reason'] ?? 'API-Fehler'), 0, 160));
    $hourly = $result['hourly'] ?? [];
    if (!is_array($hourly) || !isset($hourly['time']) || !is_array($hourly['time'])) {
        throw new RuntimeException('API liefert keine Stunden-Zeitreihe');
    }
    $cols = [
        'temp' => 'temperature_2m', 'rain' => 'precipitation', 'sun' => 'sunshine_duration',
        'ghi' => 'shortwave_radiation', 'gti' => 'global_tilted_irradiance',
        'wind' => 'wind_speed_10m', 'cloud' => 'cloud_cover', 'ww' => 'weather_code'
    ];
    $rows = []; $solarFound = false;
    foreach ($hourly['time'] as $i => $timestamp) {
        if (!is_numeric($timestamp)) throw new RuntimeException('Zeiten nicht als UNIX-Sekunden geliefert');
        $row = ['ts' => (int)$timestamp];
        foreach ($cols as $key => $name) {
            $value = $hourly[$name][$i] ?? null;
            $row[$key] = is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
            if (in_array($key, ['rain', 'sun', 'ghi', 'gti'], true) && $row[$key] !== null) $row[$key] = max(0.0, $row[$key]);
        }
        if ($row['gti'] !== null) $solarFound = true;
        $rows[] = $row;
    }
    if (count($rows) < 24 || !$solarFound) throw new RuntimeException($model . ': Daten unvollständig (Solarstrahlung fehlt)');
    return $rows;
}


private function dwFetchApi(string $model): array
{
    if (!$this->setting('DW_OM_ENABLE')) throw new RuntimeException('Externe Modelle in den Einstellungen ausgeschaltet');
    if ($this->setting('DW_OM_API_KEY') === '' && !$this->setting('DW_OM_PRIVATE_USE_ONLY')) {
        throw new RuntimeException('Open-Meteo: für gewerbliche Nutzung API-Key erforderlich');
    }
    $api = $this->setting('DW_OM_API_KEY') === '' ? 'https://api.open-meteo.com' : 'https://customer-api.open-meteo.com';
    $isIcon = $model === 'icon_d2';
    $params = [
        'latitude' => $this->setting('DW_LAT'), 'longitude' => $this->setting('DW_LON'),
        'models' => $model,
        'hourly' => 'temperature_2m,precipitation,sunshine_duration,shortwave_radiation,global_tilted_irradiance,wind_speed_10m,cloud_cover,weather_code',
        'tilt' => $this->setting('DW_PV_TILT'), 'azimuth' => $this->setting('DW_PV_AZIMUTH'),
        'timezone' => 'Europe/Berlin', 'timeformat' => 'unixtime',
        'forecast_days' => $isIcon ? 3 : 8,
        'elevation' => $this->setting('DW_HOEHE_M')
    ];
    if ($this->setting('DW_OM_API_KEY') !== '') $params['apikey'] = $this->setting('DW_OM_API_KEY');
    $url = $api . ($isIcon ? '/v1/dwd-icon' : '/v1/ecmwf') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $content = $this->dwHttp($url, 900000);
    $json = json_decode($content, true);
    if (!is_array($json)) throw new RuntimeException('Open-Meteo: Antwort ist kein gültiges JSON');
    return $this->dwParseApi($json, $model);
}


private function dwRowsMap(array $rows): array
{
    $map = [];
    foreach ($rows as $row) {
        if (!isset($row['ts']) || !is_numeric($row['ts'])) continue;
        $ts = (int)$row['ts'];
        if ($ts % 3600 === 0) $map[$ts] = $row;
    }
    return $map;
}


private function dwFutureJoin(array $mos, array $icon, array $ecmwf, int $hours = 168): array
{
    $maps = ['mos' => $this->dwRowsMap($mos), 'icon' => $this->dwRowsMap($icon), 'ecmwf' => $this->dwRowsMap($ecmwf)];
    $start = (int)(floor(time() / 3600) * 3600 + 3600);
    $rows = [];
    for ($i = 0; $i < $hours; $i++) {
        $ts = $start + 3600*$i;
        $rows[] = ['ts' => $ts, 'mos' => $maps['mos'][$ts] ?? null,
            'icon' => $maps['icon'][$ts] ?? null, 'ecmwf' => $maps['ecmwf'][$ts] ?? null];
    }
    return $rows;
}


private function dwIndexValue(?array $row, string $key): ?float
{
    $value = $row[$key] ?? null;
    return is_numeric($value) ? (float)$value : null;
}


private function dwPV(?float $gti): ?float
{
    if ($gti === null) return null;
    // GTI: mittlere Einstrahlung in W/m2 der vorigen Stunde; bei 1h Intervall
    // liefert (GTI/1000) * kWp * PR direkt kWh je Stunde.
    return round(max(0.0, $gti / 1000 * $this->setting('DW_PV_KWP') * $this->setting('DW_PV_PR')), 4);
}


private function dwFuturePV(array $joined): array
{
    $out = [];
    foreach ($joined as $row) {
        $ip = $this->dwPV($this->dwIndexValue($row['icon'], 'gti'));
        $ep = $this->dwPV($this->dwIndexValue($row['ecmwf'], 'gti'));
        $chosen = $ip !== null ? 'ICON-D2' : ($ep !== null ? 'ECMWF IFS' : null);
        $out[] = [
            'ts' => $row['ts'], 'pv_kwh' => $ip ?? $ep, 'basis' => $chosen,
            'pv_icon_kwh' => $ip, 'pv_ecmwf_kwh' => $ep,
            'gti_wm2' => $this->dwIndexValue($row[$ip !== null ? 'icon' : 'ecmwf'], 'gti'),
            'ghi_mos_wm2' => $this->dwIndexValue($row['mos'], 'ghi'),
            'ghi_icon_wm2' => $this->dwIndexValue($row['icon'], 'ghi'),
            'ghi_ecmwf_wm2' => $this->dwIndexValue($row['ecmwf'], 'ghi'),
            'temp_mos_c' => $this->dwIndexValue($row['mos'], 'temp'),
            'temp_icon_c' => $this->dwIndexValue($row['icon'], 'temp'),
            'temp_ecmwf_c' => $this->dwIndexValue($row['ecmwf'], 'temp'),
            'rain_mos_mm' => $this->dwIndexValue($row['mos'], 'rain'),
            'rain_icon_mm' => $this->dwIndexValue($row['icon'], 'rain'),
            'rain_ecmwf_mm' => $this->dwIndexValue($row['ecmwf'], 'rain')
        ];
    }
    return $out;
}
/** Nur vollstaendig abgedeckte Zeitfenster als Prognosesumme ausgeben.
 * Fehlende Modellstunden duerfen NICHT als Null-Ertrag behandelt werden.
 */


private function dwSumFuture(array $rows, string $key, int $from, int $before): ?float
{
    if ($from >= $before) return 0.0; // z. B. heute nach 24 Uhr
    $expected = (int)ceil(($before - $from) / 3600);
    $value = 0.0; $seen = 0;
    foreach ($rows as $row) {
        if ($row['ts'] < $from || $row['ts'] >= $before) continue;
        if (!isset($row[$key]) || !is_numeric($row[$key])) continue;
        $value += (float)$row[$key]; $seen++;
    }
    // Kleine Modell-Luecken tolerieren (maximal 10 % des Fensters).
    return ($seen >= max(1,(int)ceil($expected*0.9))) ? round($value,2) : null;
}


private function dwModelFresh(int $stamp, int $maxAge): bool
{
    return $stamp > 0 && (time() - $stamp) < $maxAge;
}
/** Store only timestamps and short error text; never discard valid model cache on a failed refresh. */


private function dwRecordFetch(array &$history, string $source, bool $success, string $error = ''): void
{
    $history[$source] = [
        'attempt_ts' => time(),
        'result' => $success ? 'ok' : 'error',
        'error' => $success ? '' : $this->dwSafeFetchError($error)
    ];
}
/** "fresh" means the forecast is young enough AND has data, not "newly downloaded". */


private function dwSourceState(array $rows, int $stamp, int $maxAge, bool $enabled,
    array $lastAttempt, bool $loadedThisRun = false): array
{
    $hasData = $stamp > 0 && count($rows) > 0;
    $fresh = $enabled && $hasData && $this->dwModelFresh($stamp, $maxAge);
    $lastFailed = ($lastAttempt['result'] ?? '') === 'error' &&
        (int)($lastAttempt['attempt_ts'] ?? 0) >= $stamp;
    $err = $lastFailed ? (string)($lastAttempt['error'] ?? '') : '';
    if (!$enabled) {
        $state='disabled'; $label='Deaktiviert';
    } elseif ($fresh && $lastFailed) {
        $state='cached_after_failure'; $label='Gueltiger Cache (letzter Abruf fehlgeschlagen)';
    } elseif ($fresh && $loadedThisRun) {
        $state='downloaded'; $label='Jetzt erfolgreich abgerufen';
    } elseif ($fresh) {
        $state='cached'; $label='Gueltiger Cache (kein erneuter Abruf noetig)';
    } elseif ($hasData) {
        $state='expired'; $label='Veraltet - nicht fuer Automationen verwenden';
    } else {
        $state='unavailable'; $label='Keine gueltigen Daten verfuegbar';
    }
    return [
        'fresh'=>$fresh, 'state'=>$state, 'label'=>$label,
        'cached_after_error'=>$fresh && $lastFailed,
        'downloaded'=>$fresh && $loadedThisRun,
        'ts'=>$stamp, 'age_min'=>$hasData ? max(0,(int)floor((time()-$stamp)/60)) : null,
        'last_attempt_ts'=>(int)($lastAttempt['attempt_ts']??0),
        'last_attempt_result'=>(string)($lastAttempt['result']??''),
        'error'=>$err ?: ($fresh || !$enabled ? '' : ($hasData ? 'Prognose zu alt' : 'Kein erfolgreicher Abruf'))
    ];
}
/** Three-state automation quality: 0=invalid, 1=valid, 2=valid but impaired. */


private function dwPvDataQuality(bool $valid, array $series, array $info): array
{
    if (!$valid) return ['code'=>0,'label'=>'Ungueltig: 24h-Datensatz unvollstaendig oder zu alt',
        'cache_fallback'=>false, 'models'=>[], 'details'=>'Automationen gesperrt'];
    $names=[]; $degraded=false; $cache=false; $notes=[];
    foreach (array_slice($series, 1, 24) as $row) {
        $model=$row['source'] ?? '';
        if ($model === 'ICON-D2') $names['icon']=true;
        if ($model === 'ECMWF IFS') $names['ecmwf']=true;
    }
    foreach (array_keys($names) as $name) {
        if (!empty($info[$name]['cached_after_error'])) {
            $cache=true; $degraded=true;
            $notes[] = ($name === 'icon' ? 'ICON-D2' : 'ECMWF') . ': letzter Abruf gescheitert, gueltiger Cache genutzt';
        }
    }
    if (!isset($names['icon'])) {
        $degraded=true;
        $notes[]='ICON-D2 derzeit nicht nutzbar, ECMWF als Ersatzmodell';
    }
    return [
        'code'=>$degraded ? 2 : 1,
        'label'=>$degraded ? 'Gueltig mit Einschraenkung' : 'Gueltig (Modelldaten aktuell)',
        'cache_fallback'=>$cache,
        'models'=>array_keys($names),
        'details'=>$notes ? implode('; ',$notes) : 'Aktueller Modellcache, keine bekannte Abrufstoerung'
    ];
}



private function dwMiniSummary(array $joined, string $model): array
{
    $temp = []; $rain = 0.0; $rainN = 0; $sun = 0.0; $sunN = 0; $solar = 0.0; $solarN = 0;
    foreach (array_slice($joined, 0, $this->setting('DW_STUNDEN')) as $j) {
        $r = $j[$model] ?? null;
        $v = $this->dwIndexValue($r, 'temp'); if ($v !== null) $temp[] = $v;
        $v = $this->dwIndexValue($r, 'rain'); if ($v !== null) { $rain += $v; $rainN++; }
        $v = $this->dwIndexValue($r, 'sun'); if ($v !== null) { $sun += $v; $sunN++; }
        $v = $this->dwIndexValue($r, 'ghi'); if ($v !== null) { $solar += $v / 1000; $solarN++; }
    }
    return [
        'tempAvg' => $temp ? array_sum($temp)/count($temp) : null,
        'rain' => $rainN ? $rain : null, 'sun' => $sunN ? $sun/3600 : null,
        'ghi_kwhm2' => $solarN ? $solar : null,
        'hours' => max(count($temp), $rainN, $solarN)
    ];
}


private function dwComparison(array $joined): array
{
    $diffTemp = []; $pvI = 0.0; $pvE = 0.0; $pvIn = 0; $pvEn = 0;
    foreach (array_slice($joined, 0, $this->setting('DW_STUNDEN')) as $j) {
        $it = $this->dwIndexValue($j['icon'], 'temp'); $et = $this->dwIndexValue($j['ecmwf'], 'temp');
        if ($it !== null && $et !== null) $diffTemp[] = abs($it - $et);
        $ip = $this->dwPV($this->dwIndexValue($j['icon'], 'gti')); if ($ip !== null) { $pvI += $ip; $pvIn++; }
        $ep = $this->dwPV($this->dwIndexValue($j['ecmwf'], 'gti')); if ($ep !== null) { $pvE += $ep; $pvEn++; }
    }
    $i = $this->dwMiniSummary($joined, 'icon');
    $e = $this->dwMiniSummary($joined, 'ecmwf');
    $t = count($diffTemp) >= 22 ? array_sum($diffTemp)/count($diffTemp) : null;
    $r = ($i['rain'] !== null && $e['rain'] !== null && $i['hours'] >= 22 && $e['hours'] >= 22) ? abs($i['rain']-$e['rain']) : null;
    $p = ($pvIn >= 22 && $pvEn >= 22) ? abs($pvI-$pvE) : null;
    $score = 0; $n = 0;
    if ($t !== null) { $score += ($t <= 1.5 ? 2 : ($t <= 3 ? 1 : 0)); $n++; }
    if ($r !== null) { $score += ($r <= 2 ? 2 : ($r <= 5 ? 1 : 0)); $n++; }
    if ($p !== null) {
        $ref = max(1.0, ($pvI+$pvE)/2);
        $part = $p/$ref;
        $score += ($part <= .20 ? 2 : ($part <= .40 ? 1 : 0)); $n++;
    }
    $label = $n < 2 ? 'Nicht bewertbar' : (($score/$n >= 1.6) ? 'Hohe Übereinstimmung' : (($score/$n >= .85) ? 'Mittlere Übereinstimmung' : 'Geringe Übereinstimmung'));
    return ['temp_mae_c'=>$t, 'rain_diff_mm'=>$r, 'pv_diff_kwh'=>$p,
        'icon_pv24_kwh'=>$pvIn ? round($pvI,2) : null,
        'ecmwf_pv24_kwh'=>$pvEn ? round($pvE,2) : null,
        'label'=>$label, 'measurements'=>$n];
}


private function dwChartCompareSvg(array $joined, string $metric): string
{
    $rows = array_slice($joined, 0, $this->setting('DW_STUNDEN'));
    if (!$rows) return '<div class="dwx-muted">Keine Stundenwerte vorhanden.</div>';
    $models = ['mos'=>['name'=>'DWD MOSMIX','color'=>'#e1e4e9'],
        'icon'=>['name'=>'ICON-D2','color'=>'#47d3b7'],
        'ecmwf'=>['name'=>'ECMWF','color'=>'#88aaff']];
    if ($metric === 'sun') $models = ['mos'=>$models['mos']];
    $vals = []; $valid = [];
    foreach ($models as $model => $_meta) {
        $vals[$model] = [];
        foreach ($rows as $i=>$j) {
            $v = $this->dwIndexValue($j[$model], $metric === 'sun' ? 'sun' : $metric);
            if ($metric === 'sun' && $v !== null) $v /= 60.0;
            $vals[$model][$i] = $v;
            if ($v !== null) $valid[] = $v;
        }
    }
    if (!$valid) return '<div class="dwx-muted">Für diese Grafik liegen momentan keine Prognosedaten vor.</div>';
    $min = $metric === 'temp' ? floor(min($valid)-1) : 0.0;
    $max = $metric === 'temp' ? ceil(max($valid)+1) : ($metric === 'sun' ? 60.0 : max($metric === 'rain' ? 0.5 : 100.0, ceil(max($valid)*1.1)));
    if ($max <= $min) $max=$min+1;
    $w=840; $h=172; $l=48; $r=11; $top=10; $bottom=31;
    $pw=$w-$l-$r; $ph=$h-$top-$bottom; $n=count($rows);
    $x=static fn(int $i): float=>$l+($i+.5)*$pw/$n;
    $y=static fn(float $v): float=>$top+$ph-($v-$min)/($max-$min)*$ph;
    $label = ['temp'=>'Temperatur in Grad Celsius', 'rain'=>'Regen in mm je Stunde',
        'sun'=>'Sonnenscheindauer in Minuten', 'ghi'=>'Globalstrahlung in Watt je Quadratmeter'][$metric] ?? $metric;
    $svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" aria-label="'.$this->dwEsc($label).'" role="img">';
    for ($k=0;$k<=4;$k++) {
        $v=$min+($max-$min)*$k/4; $yy=round($y($v),1);
        $svg.='<line x1="'.$l.'" y1="'.$yy.'" x2="'.($w-$r).'" y2="'.$yy.'" stroke="#3c414b" stroke-dasharray="3 6"/>';
        $svg.='<text x="'.($l-8).'" y="'.($yy+4).'" text-anchor="end" fill="#9da5b2" font-size="10">'.$this->dwEsc($this->dwFmt($v,$metric==='rain'?1:0)).'</text>';
    }
    foreach ($rows as $i=>$row) {
        if ($i % 4 !== 0 && $i !== $n-1) continue;
        $svg.='<text x="'.round($x($i),1).'" y="'.($h-7).'" text-anchor="middle" fill="#aab2bf" font-size="11">'.date('H:i',$row['ts']).'</text>';
    }
    if ($metric === 'rain' || $metric === 'sun') {
        $modelCount=count($models); $groupWidth=($pw/$n)*.74;
        foreach ($models as $model=>$meta) {
            $idx=array_search($model,array_keys($models),true);
            foreach ($vals[$model] as $i=>$v) {
                if ($v===null || $v<=0) continue;
                $bw=$groupWidth/$modelCount;
                $xx=$x($i)-$groupWidth/2+$idx*$bw;
                $yy=$y($v);
                $svg.='<rect x="'.round($xx,1).'" y="'.round($yy,1).'" width="'.round(max(1,$bw-1.2),1).'" height="'.round(max(1,$top+$ph-$yy),1).'" rx="1" fill="'.$meta['color'].'">' .
                    '<title>'.$this->dwEsc($meta['name'].' · '.date('d.m. H:i',$rows[$i]['ts']).' · '.$this->dwFmt($v,1).($metric==='rain'?' mm':' min')).'</title></rect>';
            }
        }
    } else {
        foreach ($models as $model=>$meta) {
            $segments=[]; $segment=[];
            foreach ($vals[$model] as $i=>$v) {
                if ($v===null) { if ($segment) $segments[]=$segment; $segment=[]; continue; }
                $segment[]=[round($x($i),1),round($y($v),1),$i];
            }
            if ($segment) $segments[]=$segment;
            foreach ($segments as $part) {
                $points=implode(' ',array_map(static fn($p)=>$p[0].','.$p[1],$part));
                $svg.='<polyline points="'.$points.'" fill="none" stroke="'.$meta['color'].'" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"/>';
                foreach ($part as $p) {
                    $svg.='<circle cx="'.$p[0].'" cy="'.$p[1].'" r="2.35" fill="'.$meta['color'].'"><title>' .
                        $this->dwEsc($meta['name'].' · '.date('d.m. H:i',$rows[$p[2]]['ts']).' · '.$this->dwFmt($vals[$model][$p[2]],1) .
                        ($metric==='temp'?' °C':' W/m²')).'</title></circle>';
                }
            }
        }
    }
    return $svg.'</svg>';
}


private function dwChartBlock(array $joined, string $metric, string $title, string $subtitle): string
{
    return '<section class="dwx-chart-block"><div class="dwx-chart-head"><h3>'.$this->dwEsc($title).'</h3><span>'.$this->dwEsc($subtitle).'</span></div>' .
        '<div class="dwx-chart-scroll">'.$this->dwChartCompareSvg($joined,$metric).'</div></section>';
}


private function dwLegend(): string
{
    return '<div class="dwx-key"><span><span class="dwx-dot" style="background:#e1e4e9"></span>DWD MOSMIX</span>' .
        '<span><span class="dwx-dot" style="background:#47d3b7"></span>ICON-D2</span>' .
        '<span><span class="dwx-dot" style="background:#88aaff"></span>ECMWF IFS</span></div>';
}
/** Nimmt ausschliesslich vollstaendige 2h-Intervalle, sonst wird ein Strich angezeigt. */


private function dw2HourMetric(array $pair, string $metric, string $aggregation): ?float
{
    if (count($pair) !== 2) return null;
    $values = [];
    foreach ($pair as $part) {
        $v = $this->dwIndexValue($part['mos'] ?? null, $metric);
        if ($v === null) return null;
        $values[] = $v;
    }
    if ($aggregation === 'sum') return $values[0] + $values[1];
    if ($aggregation === 'max') return max($values);
    return ($values[0] + $values[1]) / 2;
}
/** Genau 12 Zweistunden-Bloecke aus den naechsten 24 MOSMIX-Stunden. */


private function dwTwoHourlyPreview(array $joined): string
{
    // Erster Wert in joined endet an der naechsten vollen Stunde und gehoert
    // daher teilweise noch zur laufenden Stunde. Ab Index 1 sind es 24 volle
    // Stunden ab dem naechsten Stundenschlag.
    $hours = array_slice($joined, 1, 24);
    $html = '<section class="dwx-chart-block"><div class="dwx-chart-head"><h3>🕑 Wettervorschau im 2-Stunden-Raster</h3>' .
        '<span>12 Zeitfenster · ab nächster voller Stunde · DWD MOSMIX</span></div>' .
        '<div class="dwx-hourtable dwx-twohour-grid"><table><thead><tr>' .
        '<th>Zeitraum</th><th>Wetter</th><th>Temp. &Oslash;</th><th>Regen</th>' .
        '<th>Regenrisiko*</th><th>Sonne</th><th>Wind max.</th><th>Strahlung &Oslash;</th>' .
        '</tr></thead><tbody>';
    $hasData = false;
    for ($i = 0; $i < 24; $i += 2) {
        $pair = array_slice($hours, $i, 2);
        if (count($pair) !== 2) break;
        $start = (int)$pair[0]['ts'] - 3600;
        $end = (int)$pair[1]['ts'];
        $range = date('d.m. H:i', $start) . '–' .
            (date('Y-m-d', $start) === date('Y-m-d', $end) ? date('H:i', $end) : date('d.m. H:i', $end));
        $temp = $this->dw2HourMetric($pair, 'temp', 'avg');
        $rain = $this->dw2HourMetric($pair, 'rain', 'sum');
        $prob = $this->dw2HourMetric($pair, 'prob', 'max');
        $sun = $this->dw2HourMetric($pair, 'sun', 'sum'); // MOSMIX SunD1: Sekunden
        $wind = $this->dw2HourMetric($pair, 'wind', 'max');
        $ghi = $this->dw2HourMetric($pair, 'ghi', 'avg'); // Stundenmittel in W/m2
        $last = $pair[1]['mos'] ?? null;
        $icon = '–'; $caption = 'Keine Daten';
        if ($last !== null && (($last['ww'] ?? null) !== null || ($last['cloud'] ?? null) !== null)) {
            [$icon, $caption] = $this->dwWeather($last['ww'] ?? null, $last['cloud'] ?? null);
        }
        if ($temp !== null) $hasData = true;
        $html .= '<tr><td>'.$this->dwEsc($range).'</td><td title="'.$this->dwEsc($caption).'">'.$icon.' '.$this->dwEsc($caption).'</td>' .
            '<td>'.$this->dwFmt($temp, 1).' &deg;C</td>' .
            '<td>'.$this->dwFmt($rain, 1).' mm</td>' .
            '<td>'.$this->dwFmt($prob, 0).' %</td>' .
            '<td>'.$this->dwFmt($sun === null ? null : $sun/3600, 1).' h</td>' .
            '<td>'.$this->dwFmt($wind, 0).' km/h</td>' .
            '<td>'.$this->dwFmt($ghi, 0).' W/m&sup2;</td></tr>';
    }
    $html .= '</tbody></table></div>' .
        '<div class="dwx-tiny">Temperatur und Strahlung: 2-h-Mittel. Regen und Sonnenschein: 2-h-Summe. ' .
        '* Regenrisiko: höchste Stundenwahrscheinlichkeit im jeweiligen Zeitfenster, keine 2-h-Eintrittswahrscheinlichkeit. ' .
        'Die Werte gelten für den jeweiligen Zweistundenzeitraum und sind Vorhersagen, keine Messwerte.</div>';
    if (!$hasData) {
        $html .= '<div class="dwx-notice">Derzeit keine ausreichend vollständigen MOSMIX-Stundendaten für die Zweistundenansicht.</div>';
    }
    return $html . '</section>';
}



private function dwHourlyHtml(array $joined, array $warnings, bool $warnOK, int $warnStamp,
    string $stationName, string $stationId, int $forecastStamp, string $forecastError,
    array $sourceInfo, string $warnHook = '', string $warnToken = '', bool $hookReady = false, int $widgetId = 0): string
{
    $mos=$this->dwMiniSummary($joined,'mos'); $icon=$this->dwMiniSummary($joined,'icon');
    $html=$this->dwCSS().'<div class="dwx"><div class="dwx-head"><div><h2>📈 Wetter · 24 Stunden</h2>' .
        '<div class="dwx-sub">Temperatur, Niederschlag, Sonnenschein und Solarstrahlung · '.$this->dwEsc($this->setting('DW_ORT')).'</div></div>'.
        $this->dwForecastWarningButton($warnings,$warnOK,$warnStamp,$warnHook,$warnToken,'dwx_warn24_' . $widgetId, $hookReady).'</div>'.$this->dwDatedNotice($forecastError,$forecastStamp,'MOSMIX');
    $html.='<div class="dwx-stats">' .
        '<div class="dwx-stat"><label>🌧️ Niederschlag (MOSMIX)</label><strong>'.$this->dwFmt($mos['rain'],1).' <small>mm/24 h</small></strong></div>' .
        '<div class="dwx-stat"><label>☀️ Sonnenstunden (MOSMIX)</label><strong>'.$this->dwFmt($mos['sun'],1).' <small>h/24 h</small></strong></div>' .
        '<div class="dwx-stat"><label>⚡ Horizontalstrahlung (MOSMIX)</label><strong>'.$this->dwFmt($mos['ghi_kwhm2'],2).' <small>kWh/m²</small></strong></div>' .
        '<div class="dwx-stat"><label>🌧️ Niederschlag (ICON-D2)</label><strong>'.$this->dwFmt($icon['rain'],1).' <small>mm/24 h</small></strong></div>' .
        '</div>'.$this->dwLegend();
    $html.=$this->dwTwoHourlyPreview($joined);
    $html.=$this->dwChartBlock($joined,'temp','🌡️ Temperaturvergleich','2-m-Temperatur in °C');
    $html.=$this->dwChartBlock($joined,'rain','🌧️ Niederschlagsvergleich','stündlicher Niederschlag in mm');
    $html.=$this->dwChartBlock($joined,'sun','☀️ Sonnenscheindauer','DWD MOSMIX: Minuten je Stunde');
    $html.=$this->dwChartBlock($joined,'ghi','⚡ Globalstrahlung','horizontale Solarstrahlung in W/m²');
    $html.='<div class="dwx-tiny">Balkenwerte und Strahlung gehören jeweils zur vorhergehenden Stunde. ICON-D2 liefert höchstens rund 48 Stunden; ECMWF ergänzt den längeren Zeitraum. Unterschiede sind Modellstreuung, keine Fehlerquote.</div>';
    foreach ($sourceInfo as $name=>$s) {
        if (!$s['fresh']) $html.='<div class="dwx-notice">'.$name.' derzeit nicht aktuell: '.$this->dwEsc($s['error'] ?: 'letzte Daten zu alt oder noch nicht vorhanden').'.</div>';
elseif (!empty($s['cached_after_error'])) $html.='<div class="dwx-notice">'.$name.': Der letzte Abruf ist fehlgeschlagen. Es werden gueltige gespeicherte Prognosewerte verwendet.</div>';
    }
    return $html.$this->dwFooter($stationName,$stationId,$forecastStamp,$warnStamp,$warnOK,true).'</div>';
}


private function dwPvToday(int $time, int $offset=0): int
{
    return strtotime(date('Y-m-d 00:00:00',$time).' +'.$offset.' day');
}


private function dwPvHtml(array $pvRows, array $joined, array $warn, bool $warnOK, int $warnStamp,
    string $stationName, string $stationId, int $forecastStamp, array $info): string
{
    $start=$pvRows[0]['ts'] ?? (int)(ceil(time()/3600)*3600);
    $tomorrow=strtotime('tomorrow 00:00:00');
    $dayAfter=strtotime('tomorrow 00:00:00 +1 day');
    $energy24=$this->dwSumFuture($pvRows,'pv_kwh',$start,$start+86400);
    $todayRest=$this->dwSumFuture($pvRows,'pv_kwh',$start,$tomorrow);
    $tomorrowEnergy=$this->dwSumFuture($pvRows,'pv_kwh',$tomorrow,$dayAfter);
    $energy7=$this->dwSumFuture($pvRows,'pv_kwh',$start,$start+7*86400);
    $nextPV=$pvRows[0]['pv_kwh'] ?? null;
    $nextGTI=$pvRows[0]['gti_wm2'] ?? null;
    $html=$this->dwCSS().'<div class="dwx"><div class="dwx-head"><div><h2>☀️ PV-Ertragsprognose · '.$this->dwEsc($this->setting('DW_ORT')).'</h2>' .
        '<div class="dwx-sub">ICON-D2 (bis ca. 48 h) und ECMWF IFS (7 Tage) · geneigte Modulfläche (GTI)</div></div>'.
        ''.'</div>';
    if (!$info['icon']['fresh'] && !$info['ecmwf']['fresh']) {
        $html.='<div class="dwx-errorbox">Noch keine aktuellen ICON-/ECMWF-Solarwerte vorhanden. PV-Ertrag kann nicht seriös geschätzt werden. Prüfe den Modellabruf bzw. die Open-Meteo-Lizenz.</div>';
    }
    $html.='<div class="dwx-stats">' .
        '<div class="dwx-stat"><label>⚡ PV-Ertrag nächste 24 h</label><strong>'.$this->dwFmt($energy24,1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>☀️ Heute noch zu erwarten</label><strong>'.$this->dwFmt($todayRest,1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>🌤️ Morgen</label><strong>'.$this->dwFmt($tomorrowEnergy,1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>📆 Nächste 168 Stunden</label><strong>'.$this->dwFmt($energy7,1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>🔌 Leistung kommende Stunde*</label><strong>'.$this->dwFmt($nextPV,2).' <small>kW</small></strong></div>' .
        '<div class="dwx-stat"><label>🌞 Modul-Einstrahlung*</label><strong>'.$this->dwFmt($nextGTI,0).' <small>W/m²</small></strong></div></div>';
    if ($energy24!==null && $energy7===null) {
        $html.='<div class="dwx-notice">Für 7 Tage fehlen Modellstunden (z. B. ECMWF nicht verfügbar). Die 7-Tage-Summe wird bewusst nicht als 0 angezeigt.</div>';
    }
    $html.='<div class="dwx-chart-block"><div class="dwx-chart-head"><h3>📅 Ertrag nach Kalendertagen</h3><span>jeweils verbleibende Prognosestunden</span></div><div class="dwx-pvrow">';
    $week=['So','Mo','Di','Mi','Do','Fr','Sa'];
    for ($i=0;$i<7;$i++) {
        $t=$this->dwPvToday(time(),$i); $next=$this->dwPvToday(time(),$i+1);
        $from=max($start,$t);
        $v=$this->dwSumFuture($pvRows,'pv_kwh',$from,$next);
        $ic=$this->dwSumFuture($pvRows,'pv_icon_kwh',$from,$next);
        $ec=$this->dwSumFuture($pvRows,'pv_ecmwf_kwh',$from,$next);
        $sub=$ic!==null&&$ec!==null ? 'ICON '.$this->dwFmt($ic,1).' / EC '.$this->dwFmt($ec,1) :
            ($ic!==null ? 'ICON-D2' : ($ec!==null ? 'ECMWF' : 'keine Daten'));
        $html.='<div class="dwx-pvday"><div class="dwx-dlabel">'.($i===0?'Heute*':$week[(int)date('w',$t)]).' '.date('d.m.',$t).'</div>' .
            '<div class="dwx-kwh">'.$this->dwFmt($v,1).'</div><div class="dwx-subline">kWh · '.$sub.'</div></div>';
    }
    $html.='</div></div>';
    $html.=$this->dwChartBlock($joined,'ghi','☀️ Globalstrahlung – 24 h','W/m² horizontal · MOSMIX / ICON / ECMWF');
    $html.='<div class="dwx-notice"><strong>PV-Anlagenparameter:</strong> '.$this->dwPvSpecs().'.</div>';
    $html.='<div class="dwx-tiny">* Momentanleistung als Mittelwert der kommenden Prognosestunde aus einem 1-Stunden-Energieintervall angenähert. Modell: kWp × geneigte Strahlung / 1000 × PR. Keine Berücksichtigung von Verschattung, Schnee, Batterieladung oder individueller Wechselrichter-Begrenzung. Nicht als garantierter Ertrag oder Netz-Einspeiseprognose verwenden.</div>';
    return $html.$this->dwFooter($stationName,$stationId,$forecastStamp,$warnStamp,$warnOK,true).'</div>';
}


private function dwCompareHtml(array $joined, array $pvRows, array $warnings, bool $warnOK, int $warnStamp,
    string $stationName, string $stationId, int $forecastStamp, array $info, array $cmp,
    string $hook, string $token, bool $hookReady, int $scriptId): string
{
    $form = $hookReady
        ? $this->dwHookForm('refresh', '↻ Wetterdaten jetzt abrufen', $hook, $token,
            'dwx_manual_' . $scriptId, 'dwx-refresh', true)
        : '<span class="dwx-small">Manueller Abruf nicht verfügbar · WebHook Control prüfen</span>';
    $html=$this->dwCSS().'<div class="dwx"><div class="dwx-head"><div><h2>🧭 Wettermodelle im Vergleich · '.$this->dwEsc($this->setting('DW_ORT')).'</h2>' .
        '<div class="dwx-sub">DWD MOSMIX_L · DWD ICON-D2 · ECMWF IFS · nächste 24 Stunden</div></div>'.
        '<div class="dwx-tools">'.$form.'</div></div>';
    $html.='<div class="dwx-stats"><div class="dwx-stat"><label>Übereinstimmung ICON ↔ ECMWF</label><strong style="font-size:16px">'.$this->dwEsc($cmp['label']).'</strong></div>' .
        '<div class="dwx-stat"><label>Mittlere Temperaturdifferenz</label><strong>'.$this->dwFmt($cmp['temp_mae_c'],1).' <small>°C</small></strong></div>' .
        '<div class="dwx-stat"><label>Abweichung Niederschlag</label><strong>'.$this->dwFmt($cmp['rain_diff_mm'],1).' <small>mm/24 h</small></strong></div>' .
        '<div class="dwx-stat"><label>Abweichung PV-Ertrag</label><strong>'.$this->dwFmt($cmp['pv_diff_kwh'],1).' <small>kWh/24 h</small></strong></div></div>';
    $models=['mos'=>['name'=>'DWD MOSMIX_L','caption'=>'Statistisch optimierte DWD-Prognose'],
        'icon'=>['name'=>'DWD ICON-D2','caption'=>'Hochauflösend · ungefähr 2 km · bis 48 h'],
        'ecmwf'=>['name'=>'ECMWF IFS','caption'=>'Mittelfristig · ungefähr 9 km · bis 15 Tage']];
    $html.='<div class="dwx-models">';
    foreach ($models as $code=>$model) {
        $summary=$this->dwMiniSummary($joined,$code);
        $status=$info[$code];
        $stamp=$status['ts'] ? date('d.m. H:i',$status['ts']) : 'noch nie';
        $html.='<div class="dwx-model"><h3>'.$this->dwEsc($model['name']).' '.($status['fresh'] && !$status['cached_after_error']?'✓':'⚠️').'</h3>' .
            '<small>'.$this->dwEsc($model['caption']).'</small><div class="dwx-num">'.$this->dwFmt($summary['rain'],1).' <small>mm Regen</small></div>' .
            '<p>Ø Temperatur: <b>'.$this->dwFmt($summary['tempAvg'],1).' °C</b><br>' .
            'Globalstrahlung: <b>'.$this->dwFmt($summary['ghi_kwhm2'],2).' kWh/m²</b><br>' .
            'Sonne: <b>'.$this->dwFmt($summary['sun'],1).' h</b></p>' .
            '<small>'.$this->dwEsc($status['label']).'<br>Letzter erfolgreicher Abruf: '.$stamp.' Uhr'.
($status['age_min']!==null?' ('.(int)$status['age_min'].' min alt)':'').'</small>' .
            ($status['error']!==''?'<p>⚠️ '.$this->dwEsc($status['error']).'</p>':'') . '</div>';
    }
    $html.='</div>'.$this->dwLegend();
    $html.=$this->dwChartBlock($joined,'temp','🌡️ Temperatur – Modellvergleich','3 Vorhersagen für die nächsten 24 h');
    $html.=$this->dwChartBlock($joined,'rain','🌧️ Niederschlag – Modellvergleich','Stundenmengen');
    $html.='<div class="dwx-tiny">„Übereinstimmung“ ist nur eine heuristische Einordnung der Unterschiede zwischen ICON-D2 und ECMWF (Temperatur, Regen, PV). Sie ist <strong>keine gemessene Prognosegüte oder Eintrittswahrscheinlichkeit</strong>. MOSMIX verarbeitet u. a. ICON- und IFS-Ergebnisse und ist daher keine völlig unabhängige dritte Vorhersage.</div>';
    return $html.$this->dwFooter($stationName,$stationId,$forecastStamp,$warnStamp,$warnOK,true).'</div>';
}


private function dwWarningHtml(array $warnings, bool $warnOK, int $warnStamp): string
{
    $html=$this->dwCSS().'<div class="dwx"><div class="dwx-head"><div><h2>⚠️ DWD-Wetterwarnungen</h2>' .
        '<div class="dwx-sub">Standort '.$this->dwEsc($this->setting('DW_ORT')).' · Warngebiet '.$this->dwEsc($this->setting('DW_WARNGEBIET_NAME')).' · Prüfung alle 15 Minuten</div></div>' .
        '<span class="dwx-muted">DWD WarnWetter</span></div>';
    $warnFresh = $warnOK && $warnStamp > 0 && $warnStamp >= time()-3600;
    $stampText = $warnStamp > 0 ? date('d.m.Y H:i', $warnStamp) . ' Uhr' : 'noch kein erfolgreicher Abruf';
    if (!$warnFresh) {
        $html .= '<div class="dwx-warn-state dwx-warn-state-error"><strong>⚠️ Warnstatus nicht aktuell / ungeprüft</strong>' .
            '<span>Letzter erfolgreicher Abruf: '.$this->dwEsc($stampText).'. Die folgenden Daten können veraltet sein. ' .
            'Keine automatische Entwarnung.</span></div>';
    } elseif (count($warnings) === 0) {
        $html .= '<div class="dwx-warn-state dwx-warn-state-clear"><strong>✓ Aktuell warnungsfrei</strong>' .
            '<span>Fuer '.$this->dwEsc($this->setting('DW_WARNGEBIET_NAME')).' liegen zurzeit keine aktiven amtlichen DWD-Wetterwarnungen vor.' .
            ' Letzter Abruf: '.$this->dwEsc($stampText).'.</span></div>';
    } else {
        $highest = max(array_map(static function (array $warning): int {
            return max(1, min(5, (int)($warning['level'] ?? 2)));
        }, $warnings));
        $html .= '<div class="dwx-warn-state dwx-warn-state-active dwx-l'.$highest.'">' .
            '<strong>⚠️ '.count($warnings).' DWD-Warnmeldung'.(count($warnings)===1?'':'en').' vorhanden</strong>' .
            '<span>Höchste Warnstufe: '.(int)$highest.' · '.$this->dwEsc($this->setting('DW_WARNGEBIET_NAME')).' · ' .
            'Stand: '.$this->dwEsc($stampText).'</span></div>';
    }
    foreach ($warnings as $warning) {
        $level=max(1,min(5,(int)($warning['level'] ?? 2)));
        $levelText=[1=>'Vorabinformation',2=>'Wetterwarnung',3=>'Markantes Wetter',4=>'Unwetter',5=>'Extremes Unwetter'][$level];
        $from=$warning['start'] ? date('d.m. H:i',$warning['start']) : 'offen';
        $until=$warning['end'] ? date('d.m. H:i',$warning['end']) : 'offen';
        $html.='<article class="dwx-warning dwx-l'.$level.'"><h3>⚠️ '.$this->dwEsc($warning['headline']).'</h3>' .
            '<div class="dwx-time">'.$this->dwEsc($levelText).' · '.$this->dwEsc($from).' – '.$this->dwEsc($until).' Uhr</div>';
        if ($warning['description']!=='') $html.='<p>'.nl2br($this->dwEsc($warning['description'])).'</p>';
        if ($warning['instruction']!=='') $html.='<p><strong>Verhalten:</strong> '.nl2br($this->dwEsc($warning['instruction'])).'</p>';
        $html.='</article>';
    }
    return $html.'<div class="dwx-footer">Warnungen gelten für ein Kreisgebiet und müssen nicht genau auf '.$this->dwEsc($this->setting('DW_ORT')).' zutreffen.' .
        ' Bitte bei sicherheitskritischen Entscheidungen die <a href="https://www.dwd.de/DE/wetter/warnungen_gemeinden/warnWetter_node.html" target="_blank" rel="noopener">amtliche DWD-Warnkarte</a> nutzen.' .
        '<br>Letzter erfolgreicher Abruf: '.($warnStamp ? date('d.m.Y H:i',$warnStamp).' Uhr':'keiner').
        ' · © <a href="https://www.dwd.de/" target="_blank" rel="noopener">Deutscher Wetterdienst</a></div></div>';
}
// =================== PV-ISTWERTE UND SELBSTLERNEN =====================
/** Tageszaehler und Gesamtzaehler kWh, Momentanleistung kW. Keine Werte erfinden. */


private function dwPVGetReading(int $now): array
{
    $configs = [
        'day' => [$this->setting('DW_PV_ID_TAGESERTRAG'), $this->setting('DW_PV_UNIT_TAGESERTRAG'), 'energy', 'Tagesertrag'],
        'total' => [$this->setting('DW_PV_ID_GESAMTERTRAG'), $this->setting('DW_PV_UNIT_GESAMTERTRAG'), 'energy', 'Gesamtertrag'],
        'power' => [$this->setting('DW_PV_ID_LEISTUNG'), $this->setting('DW_PV_UNIT_LEISTUNG'), 'power', 'Momentanleistung']
    ];
    $ret = ['day'=>null, 'total'=>null, 'power'=>null, 'errors'=>[], 'configured'=>true, 'now'=>$now];
    foreach ($configs as $key => [$id,$unit,$type,$title]) {
        if ($id <= 0) {
            $ret['errors'][] = $title . ': Variablen-ID noch nicht eingetragen';
            $ret['configured'] = false;
            continue;
        }
        if (!IPS_VariableExists($id)) {
            $ret['errors'][] = $title . ': ID ' . $id . ' ist keine gueltige Variable';
            continue;
        }
        $info = IPS_GetVariable($id);
        if (!in_array((int)$info['VariableType'], [1,2], true)) {
            $ret['errors'][] = $title . ': Variable muss Integer oder Float sein';
            continue;
        }
        $lastUpdate = (int)($info['VariableUpdated'] ?? 0);
        $maxAge = ($key === 'power') ? 20*60 : 120*60;
        if ($lastUpdate <= 0 || $now-$lastUpdate > $maxAge) {
            $ret['errors'][] = $title . ': Daten nicht aktuell (zuletzt '.($lastUpdate?date('d.m. H:i',$lastUpdate):'unbekannt').')';
            continue;
        }
        $raw = GetValue($id);
        if (!is_numeric($raw) || !is_finite((float)$raw)) {
            $ret['errors'][] = $title . ': kein numerischer Messwert';
            continue;
        }
        if (($type === 'energy' && !in_array($unit,['Wh','kWh'],true)) ||
            ($type === 'power' && !in_array($unit,['W','kW'],true))) {
            $ret['errors'][] = $title . ': unbekannte Einheit ' . $unit;
            continue;
        }
        $converted = (float)$raw / (($unit==='Wh' || $unit==='W') ? 1000 : 1);
        if ($converted < -0.05 || ($key==='power' && $converted > $this->setting('DW_PV_KWP')*2.2) ||
            ($key==='day' && $converted > $this->setting('DW_PV_KWP')*24*1.2)) {
            $ret['errors'][] = $title . ': unplausibler Wert ' . $this->dwFmt($converted,2);
            continue;
        }
        $ret[$key] = max(0.0,$converted);
    }
    return $ret;
}


private function dwPVDayBounds(string $date): array
{
    $begin=(new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Europe/Berlin')));
    return [$begin->getTimestamp(),$begin->modify('+1 day')->getTimestamp()];
}
/** Zeitstempel von Open-Meteo sind das ENDE der vorhergehenden Prognosestunde. */


private function dwPVSnapshot(string $date,array $icon,array $ecmwf,array $info,int $now,float $factor,int $factorDays): ?array
{
    [$from,$until]=$this->dwPVDayBounds($date);
    $iMap=$this->dwRowsMap(($info['icon']['fresh']??false)?$icon:[]);
    $eMap=$this->dwRowsMap(($info['ecmwf']['fresh']??false)?$ecmwf:[]);
    $byHour=[]; $sum=0.0; $used=[];
    $needed=(int)(($until-$from)/3600);
    for($end=$from+3600;$end<=$until;$end+=3600) {
        $ip=$this->dwPV($this->dwIndexValue($iMap[$end]??null,'gti'));
        $ep=$this->dwPV($this->dwIndexValue($eMap[$end]??null,'gti'));
        $v=$ip??$ep;
        if($v===null) continue;
        $byHour[(string)$end]=round($v,4);
        $sum+=$v;
        $used[$ip!==null?'ICON-D2':'ECMWF']=true;
    }
    // Nicht mit unvollstaendigen Tagesprognosen trainieren.
    if(count($byHour)<$needed*0.95) return null;
    $early=($now <= $from+3*3600); // vor 03:00 Lokalzeit, nicht nachtraeglich "schoengerechnet"
    return [
        'forecast_at'=>$now,'forecast_raw_kwh'=>round($sum,3),
        'forecast_adjusted_kwh'=>round($sum*$factor,3),'forecast_factor'=>$factor,
        'learn_days_at_forecast'=>$factorDays,
        'forecast_early'=>$early,'forecast_by_hour'=>$byHour,
        'forecast_sources'=>array_keys($used),'expected_hours'=>$needed
    ];
}
/** Median abgeschlossener Vergleiche, robust gegen einzelne Ausreisser. */


private function dwPVLearning(array $days,string $today): array
{
    $ratios=[];
    foreach($days as $date=>$d) {
        if($date >= $today || empty($d['eligible'])) continue;
        $base=$d['forecast_raw_kwh']??null;
        $actual=$d['final_kwh']??null;
        if(!is_numeric($base) || !is_numeric($actual) || $base < 3.0) continue;
        $ratio=(float)$actual / (float)$base;
        if($ratio<0.3 || $ratio>2.5) continue; // Messfehler oder stoerende Ausnahmetage
        $ratios[$date]=$ratio;
    }
    ksort($ratios);
    $values=array_slice(array_values($ratios),-30);
    sort($values,SORT_NUMERIC);
    $n=count($values); $factor=1.0;
    if($n>=$this->setting('DW_PV_LERN_MIN_TAGE')) {
        $median=$n%2 ? $values[intdiv($n,2)] :
            ($values[intdiv($n,2)-1]+$values[intdiv($n,2)])/2;
        $factor=min(1.35,max(0.65,$median));
    }
    return ['factor'=>round($factor,4),'days'=>$n,'active'=>$n>=$this->setting('DW_PV_LERN_MIN_TAGE')];
}
/** Intervallanteile auf volle UNIX-Stunden aufteilen (auch bei Sommerzeitwechsel). */


private function dwPVAccumulateHours(array &$record,int $begin,int $end,float $kwh): void
{
    $seconds=$end-$begin;
    if($seconds<=0) return;
    for($x=$begin;$x<$end;) {
        $hourEnd=(int)(floor($x/3600)*3600+3600);
        $until=min($end,$hourEnd);
        $key=(string)$hourEnd;
        $record['actual_hours'][$key]=round((float)($record['actual_hours'][$key]??0)+$kwh*($until-$x)/$seconds,5);
        $record['covered_seconds'][$key]=(int)($record['covered_seconds'][$key]??0)+($until-$x);
        $x=$until;
    }
}
/** Eine dauerhaft gespeicherte Tagesprognose wird niemals nachtraeglich ueberschrieben. */


private function dwPVProcessHistory(array $state,array $reading,array $icon,array $ecmwf,array $info,int $now): array
{
    $state['days']=is_array($state['days']??null)?$state['days']:[];
    $today=date('Y-m-d',$now);
    // Fruehere Tage erst abschliessen, danach den Lernfaktor fuer HEUTE bestimmen.
    foreach($state['days'] as $date=>&$d) {
        if($date>=$today || !empty($d['finalized'])) continue;
        [, $end]=$this->dwPVDayBounds($date);
        $endSample=(int)($d['last_sample_at']??0);
        $complete=($endSample >= $end-3600 && $endSample < $end &&
                   isset($d['actual_kwh']) && empty($d['counter_error']));
        $d['finalized']=true;
        $d['final_kwh']=$complete?(float)$d['actual_kwh']:null;
        $d['eligible']=$complete && !empty($d['forecast_early']) &&
            isset($d['forecast_raw_kwh']) && $d['forecast_raw_kwh']>=3;
    }
    unset($d);
    $learning=$this->dwPVLearning($state['days'],$today);
    if (!isset($state['days'][$today])) {
        $state['days'][$today]=['actual_hours'=>[],'covered_seconds'=>[],
            'actual_kwh'=>null,'last_sample_at'=>0,'finalized'=>false,
            'created_at'=>$now];
    }
    $d=&$state['days'][$today];
    if(!isset($d['forecast_at'])) {
        $snapshot=$this->dwPVSnapshot($today,$icon,$ecmwf,$info,$now,$learning['factor'],$learning['days']);
        if($snapshot!==null) $d=array_merge($d,$snapshot);
    }
    if($reading['day']!==null && !empty($reading['configured'])) {
        $day=(float)$reading['day'];
        $last=$state['last']??null;
        // Manche Wechselrichter setzen den Tageszaehler erst Minuten nach
        // Mitternacht zurueck. Den gestrigen Wert nicht fuer heute zaehlen.
        if(is_array($last) && ($last['date']??'')!==$today &&
            $now-$this->dwPVDayBounds($today)[0]<3*3600 &&
            ($last['day']??0)>0.5 && $day>=(float)$last['day']*0.8) {
            $d['reset_waiting']=true;
            $day=0.0;
            // Kein Vergleich/kein Messwert solange der Zaehler nicht plausibel neu gestartet ist.
            $reading['day']=null;
        }
        if($reading['day']!==null) {
        if(is_array($last) && ($last['date']??'')===$today &&
            isset($last['day']) && is_numeric($last['day'])) {
            $old=(float)$last['day'];
            $duration=$now-(int)$last['ts'];
            if($day < $old-0.1) {
                $d['counter_error']='Tageszaehler wurde tagsueber zurueckgesetzt';
            } elseif($duration>0 && $duration<=20*60 && empty($d['counter_error'])) {
                $delta=max(0.0,$day-$old);
                // Unplausible Messwertspruenge werden nicht als Stundenwerte integriert.
                if($delta <= $this->setting('DW_PV_KWP')*($duration/3600)*1.5+0.04) {
                    $this->dwPVAccumulateHours($d,(int)$last['ts'],$now,$delta);
                } else {
                    $d['integration_warning']='Unplausibler Ertragssprung; einzelne Stundenwerte ausgelassen';
                }
            }
        }
        // Nur wirklich erhaltene Werte speichern. Ein 0 am Tageswechsel wird nicht
        // versehentlich auf den Vortag geschrieben.
        if(empty($d['counter_error'])) $d['actual_kwh']=round($day,4);
        $d['last_sample_at']=$now;
        if($reading['total']!==null) {
            if(!isset($d['total_at_start'])) {
                $d['total_at_start']=round((float)$reading['total'],4);
                [$midnight, ]=$this->dwPVDayBounds($today);
                $d['total_start_valid']=($now-$midnight<45*60 && $day<=0.2);
            }
            $d['total_last_kwh']=round((float)$reading['total'],4);
            $deltaTotal=(float)$reading['total']-(float)$d['total_at_start'];
            if(!empty($d['total_start_valid']) && $deltaTotal >= 0 && $day >= 1.0 &&
                abs($deltaTotal-$day)>max(1.0,0.20*$day) &&
                ($now-$this->dwPVDayBounds($today)[0])>3*3600) {
                $d['total_warning']='Tages- und Gesamtzaehler weichen deutlich voneinander ab (Messpunkt/Einheit pruefen)';
            } else {unset($d['total_warning']);}
        }
        $state['last']=['date'=>$today,'day'=>$day,'ts'=>$now];
        unset($d['reset_waiting']);
        }
    }
    unset($d);
    ksort($state['days']);
    if(count($state['days'])>$this->setting('DW_PV_HISTORIE_TAGE')) {
        $state['days']=array_slice($state['days'],-$this->setting('DW_PV_HISTORIE_TAGE'),null,true);
    }
    $state['version']=3;
    return ['state'=>$state,'learning'=>$learning, 'today'=>$state['days'][$today]];
}


private function dwPVForecastUntil(array $byHour,int $dayStart,int $now): ?float
{
    if(!$byHour) return null;
    $sum=0.0;
    foreach($byHour as $hourEnd=>$energy) {
        $end=(int)$hourEnd; $begin=$end-3600;
        if($end<=$dayStart || $begin>=$now) continue;
        $fraction=max(0,min(3600,min($end,$now)-max($begin,$dayStart)))/3600;
        $sum+=(float)$energy*$fraction;
    }
    return round($sum,3);
}
/** Kein Mitzaehlen bereits vergangener Minuten der aktuell begonnenen Stunde. */


private function dwPVRemainingUntil(array $pvRows,int $now,int $until): ?float
{
    if($until<=$now) return 0.0;
    $required=$until-$now; $seen=0; $sum=0.0;
    foreach($pvRows as $hour) {
        $end=(int)$hour['ts']; $begin=$end-3600;
        $a=max($begin,$now); $b=min($end,$until);
        if($b<=$a) continue;
        if(!isset($hour['pv_kwh']) || !is_numeric($hour['pv_kwh'])) continue;
        $dt=$b-$a; $seen+=$dt;
        $sum+=(float)$hour['pv_kwh']*$dt/3600;
    }
    if($seen < $required*0.95) return null;
    return round($sum,3);
}
/** Kombinierte PV-Prognose: bereits gemessener Ertrag + angepasster Rest. */


private function dwPVMakeReport(array $history,array $reading,array $pvRows,int $now): array
{
    $dayDate=date('Y-m-d',$now);
    [$begin,$end]=$this->dwPVDayBounds($dayDate);
    $d=$history['today']; $learning=$history['learning'];
    $raw=(isset($d['forecast_raw_kwh'])?(float)$d['forecast_raw_kwh']:null);
    $capturedFactor=(float)($d['forecast_factor']??1.0);
    $forecastSoFar=$this->dwPVForecastUntil($d['forecast_by_hour']??[],$begin,$now);
    $actual=!empty($d['reset_waiting'])?null:$reading['day'];
    $rawRest=$this->dwPVRemainingUntil($pvRows,$now,$end);
    $factor=(float)$learning['factor'];
    $expected=$actual!==null && $rawRest!==null ? round($actual+$rawRest*$factor,2) : null;
    $drift=$actual!==null && $forecastSoFar!==null && $forecastSoFar>=0.5 ?
        round($actual-$forecastSoFar*$capturedFactor,2) : null;
    $percent=$drift!==null && $forecastSoFar*$capturedFactor>=1.0 ?
        round(100*$drift/($forecastSoFar*$capturedFactor),1) : null;
    return [
        'configured'=>$reading['configured'],'day_kwh'=>$actual,'total_kwh'=>$reading['total'],
        'power_kw'=>$reading['power'],'errors'=>$reading['errors'],
        'today'=>$d,'days'=>$history['state']['days'], 'learning'=>$learning,
        'forecast_fixed_kwh'=>$raw,'forecast_fixed_adjusted_kwh'=>$raw===null?null:round($raw*$capturedFactor,2),
        'forecast_sofar_adjusted_kwh'=>$forecastSoFar===null?null:round($forecastSoFar*$capturedFactor,2),
        'difference_sofar_kwh'=>$drift,'difference_sofar_pct'=>$percent,
        'rest_adjusted_kwh'=>$rawRest===null?null:round($rawRest*$factor,2),
        'today_expected_kwh'=>$expected,
        'baseline_early'=>(bool)($d['forecast_early']??false),
        'baseline_at'=>(int)($d['forecast_at']??0),
        'today_counter_error'=>($d['counter_error']??'').(!empty($d['reset_waiting']) ? ' Tageszaehler hat nach Mitternacht noch nicht zurueckgesetzt.' : ''),
        'today_total_warning'=>$d['total_warning']??'',
        'today_integration_warning'=>$d['integration_warning']??''
    ];
}
/** Fehlerkennzahlen ueber die letzten 14 tatsaechlich auswertbaren Tage. */


private function dwPVMetrics(array $days): array
{
    $recent=[];
    foreach($days as $d) {
        if(empty($d['eligible']) || !isset($d['forecast_adjusted_kwh'],$d['final_kwh'])) continue;
        $recent[]=$d;
    }
    $recent=array_slice($recent,-14);
    $absSum=0.0; $actualSum=0.0; $biasSum=0.0;
    foreach($recent as $d) {
        $err=(float)$d['final_kwh']-(float)$d['forecast_adjusted_kwh'];
        $absSum+=abs($err); $biasSum+=$err; $actualSum+=(float)$d['final_kwh'];
    }
    $n=count($recent);
    return ['days'=>$n,'mae_kwh'=>$n?round($absSum/$n,2):null,
        'wape_pct'=>$n && $actualSum>0?round(100*$absSum/$actualSum,1):null,
        'bias_kwh'=>$n?round($biasSum/$n,2):null];
}


private function dwPVHistoryChart(array $days): string
{
    $complete=[];
    foreach($days as $date=>$d) {
        if(empty($d['eligible']) || !isset($d['forecast_adjusted_kwh'],$d['final_kwh'])) continue;
        $complete[$date]=$d;
    }
    $complete=array_slice($complete,-12,12,true);
    if(!$complete) return '<div class="dwx-muted">Noch keine abgeschlossenen Vergleichstage. Messung und Prognose werden ab Einrichtung gespeichert.</div>';
    $n=count($complete); $w=840; $h=206; $l=46; $r=10; $top=18; $bottom=39;
    $pw=$w-$l-$r; $ph=$h-$top-$bottom;
    $hi=max(2,max(array_map(static fn($d)=>max((float)$d['forecast_adjusted_kwh'],(float)$d['final_kwh']),$complete))*1.16);
    $y=static fn(float $v):float=>$top+$ph-$v/$hi*$ph;
    $svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" role="img" aria-label="PV-Vorhersage und gemessene Tagesertraege">';
    for($i=0;$i<=4;$i++) {
        $v=$hi*$i/4; $yy=round($y($v),1);
        $svg.='<line x1="'.$l.'" y1="'.$yy.'" x2="'.($w-$r).'" y2="'.$yy.'" stroke="#394049" stroke-dasharray="3 5"/>';
        $svg.='<text x="'.($l-7).'" y="'.($yy+3).'" text-anchor="end" font-size="10" fill="#a1a9b6">'.$this->dwFmt($v,0).'</text>';
    }
    $i=0;
    foreach($complete as $date=>$d) {
        $x=$l+($i+0.5)*$pw/$n; $barWidth=min(17,$pw/$n*0.28);
        foreach([['v'=>$d['forecast_adjusted_kwh'],'color'=>'#8aa8ff','offset'=>-1],
                 ['v'=>$d['final_kwh'],'color'=>'#46d6b5','offset'=>1]] as $b) {
            $v=(float)$b['v']; $yy=$y($v); $left=$x+($b['offset']===-1?-1.07:0.07)*$barWidth;
            $svg.='<rect x="'.round($left,1).'" y="'.round($yy,1).'" width="'.round($barWidth,1).'" height="'.round(max(0.7,$top+$ph-$yy),1).'" rx="2" fill="'.$b['color'].'"><title>'.$this->dwEsc($date.' · '.($b['offset']===-1?'Prognose: ':'Ist: ').$this->dwFmt($v,2).' kWh').'</title></rect>';
        }
        $svg.='<text x="'.round($x,1).'" y="'.($h-11).'" text-anchor="middle" font-size="11" fill="#aeb6c2">'.date('d.m.',strtotime($date.' 12:00')).'</text>';
        $i++;
    }
    return $svg.'</svg>';
}


private function dwPVTodayChart(array $today,int $now): string
{
    $b=$today['forecast_by_hour']??[];
    if(!$b) return '<div class="dwx-muted">Fuer heute konnte noch keine vollstaendige Prognose gespeichert werden.</div>';
    $a=$today['actual_hours']??[];
    $coverage=$today['covered_seconds']??[];
    $factor=(float)($today['forecast_factor']??1);
    $rows=[];
    foreach($b as $end=>$v) {
        $ts=(int)$end; $completed=($ts<=$now-60);
        $sec=(int)($coverage[(string)$ts]??0);
        $measured=($completed && $sec>=2400 && isset($a[(string)$ts])) ? (float)$a[(string)$ts] : null;
        $rows[]=['ts'=>$ts,'forecast'=>(float)$v*$factor,'actual'=>$measured];
    }
    $w=840; $h=192; $l=46; $r=12; $top=14; $bottom=32;
    $pw=$w-$l-$r; $ph=$h-$top-$bottom;
    $max=0.3;
    foreach($rows as $row) $max=max($max,$row['forecast'],(float)($row['actual']??0));
    $max*=1.20; $n=count($rows);
    $x=static fn(int $i):float=>$l+($i+.5)*$pw/$n;
    $y=static fn(float $v):float=>$top+$ph-($v/$max)*$ph;
    $svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" role="img" aria-label="Stundenweise Prognose und Stromerzeugung">';
    for($i=0;$i<=4;$i++) {
        $v=$max*$i/4; $yy=round($y($v),1);
        $svg.='<line x1="'.$l.'" y1="'.$yy.'" x2="'.($w-$r).'" y2="'.$yy.'" stroke="#343b45" stroke-dasharray="3 5"/>';
        $svg.='<text x="'.($l-5).'" y="'.($yy+4).'" text-anchor="end" font-size="10" fill="#a3abb8">'.$this->dwFmt($v,1).'</text>';
    }
    $half=min(11,$pw/$n*0.28);
    foreach($rows as $i=>$row) {
        $x0=$x($i); $ts=$row['ts'];
        foreach([['v'=>$row['forecast'],'off'=>-$half-0.5,'color'=>'#89aaff'],
                 ['v'=>$row['actual'],'off'=>0.5,'color'=>'#47d3b7']] as $b) {
            if($b['v']===null) continue;
            $yy=$y((float)$b['v']);
            $svg.='<rect x="'.round($x0+$b['off'],1).'" y="'.round($yy,1).'" width="'.round($half,1).'" height="'.round(max(.8,$top+$ph-$yy),1).'" rx="1" fill="'.$b['color'].'"><title>'.$this->dwEsc(date('H:i',$ts).' · '.($b['off']<0?'Prognose ':'Ist ').$this->dwFmt($b['v'],2).' kWh').'</title></rect>';
        }
        if($i%4===0 || $i===$n-1) {
            $svg.='<text x="'.round($x0,1).'" y="'.($h-8).'" text-anchor="middle" font-size="11" fill="#abb1bc">'.date('H:i',$ts).'</text>';
        }
    }
    return $svg.'</svg>';
}


private function dwPVMeasuredInline(array $r): string
{
    if(!$r['configured']) return '<div class="dwx-notice">PV-Istvergleich noch nicht eingerichtet: bitte die drei PV-Variablen-IDs oben im Skript eintragen. Es werden keine fiktiven Messwerte angezeigt.</div>';
    return '<section class="dwx-chart-block"><div class="dwx-chart-head"><h3>🔋 Deine PV-Anlage · Live</h3><span>Messwerte aus IP-Symcon</span></div>' .
        '<div class="dwx-stats" style="margin-top:0">' .
        '<div class="dwx-stat"><label>Heute erzeugt</label><strong>'.$this->dwFmt($r['day_kwh'],1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>Momentanleistung</label><strong>'.$this->dwFmt($r['power_kw'],2).' <small>kW</small></strong></div>' .
        '<div class="dwx-stat"><label>Heute gesamt erwartet (Ist + Rest)</label><strong>'.$this->dwFmt($r['today_expected_kwh'],1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>Lernkorrektur</label><strong>'.$this->dwFmt($r['learning']['factor']*100,0).' <small>%</small></strong></div>' .
        '</div></section>';
}


private function dwPVRealityHtml(array $r,array $warnings,bool $warnOK,int $warnStamp): string
{
    $html=$this->dwCSS().'<div class="dwx"><div class="dwx-head"><div><h2>🔋 PV-Prognose ↔ Ist-Ertrag</h2>' .
        '<div class="dwx-sub">'.$this->dwEsc($this->setting('DW_ORT')).' · '.$this->dwPvSpecs(true).' · Tageszähler, Gesamtzähler und Momentanleistung</div></div>' .
        ''.'</div>';
    if(!$r['configured']) {
        return $html.'<div class="dwx-errorbox"><b>Noch nicht mit deiner PV-Anlage verbunden.</b><br>' .
            'In der Modulinstanz drei PV-Variablen auswählen: <b>DW_PV_ID_TAGESERTRAG</b>, <b>DW_PV_ID_GESAMTERTRAG</b> und <b>DW_PV_ID_LEISTUNG</b>.<br>' .
            'Einheiten (Wh/kWh bzw. W/kW) und Anlagenleistung pruefen. Bis dahin erscheinen absichtlich keine erfundenen Ist-Werte.</div></div>';
    }
    $html.='<div class="dwx-stats">' .
        '<div class="dwx-stat"><label>☀️ Heute erzeugt (Ist)</label><strong>'.$this->dwFmt($r['day_kwh'],2).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>⚡ Aktuelle PV-Leistung</label><strong>'.$this->dwFmt($r['power_kw'],2).' <small>kW</small></strong></div>' .
        '<div class="dwx-stat"><label>🔢 PV-Gesamtertrag</label><strong>'.$this->dwFmt($r['total_kwh'],0).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>📌 Heute prognostiziert (Tagesbeginn)</label><strong>'.$this->dwFmt($r['forecast_fixed_adjusted_kwh'],1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>📈 Bis jetzt laut Prognose</label><strong>'.$this->dwFmt($r['forecast_sofar_adjusted_kwh'],1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>⚖️ Abweichung bis jetzt</label><strong>'.($r['difference_sofar_kwh']!==null && $r['difference_sofar_kwh']>0?'+':'').$this->dwFmt($r['difference_sofar_kwh'],1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>🌤️ Heute erwartet (Ist + Prognoserest)</label><strong>'.$this->dwFmt($r['today_expected_kwh'],1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>🔭 Heute noch erwartet (korrigiert)</label><strong>'.$this->dwFmt($r['rest_adjusted_kwh'],1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>🧠 Automatischer Lernfaktor</label><strong>'.$this->dwFmt($r['learning']['factor'],3).' <small>× · '.$r['learning']['days'].' Tage</small></strong></div>' .
        '</div>';
    $metrics=$this->dwPVMetrics($r['days']);
    $html.='<div class="dwx-stats">' .
        '<div class="dwx-stat"><label>📊 Mittlerer absoluter Tagesfehler*</label><strong>'.$this->dwFmt($metrics['mae_kwh'],1).' <small>kWh</small></strong></div>' .
        '<div class="dwx-stat"><label>🎯 Gewichteter relativer Fehler*</label><strong>'.$this->dwFmt($metrics['wape_pct'],1).' <small>%</small></strong></div>' .
        '<div class="dwx-stat"><label>📅 Vergleichstage für Fehler*</label><strong>'.$this->dwFmt($metrics['days'],0).' <small>von max. 14</small></strong></div></div>';
    if($r['errors']) $html.='<div class="dwx-notice">⚠️ Messwerte teilweise veraltet/ungueltig: '.$this->dwEsc(implode(' · ',$r['errors'])).'</div>';
    if($r['today_counter_error']) $html.='<div class="dwx-errorbox">⚠️ '.$this->dwEsc($r['today_counter_error']).'. Tagesmessung wird zur Sicherheit nicht fuer das Lernen verwendet.</div>';
    if($r['today_total_warning']) $html.='<div class="dwx-notice">⚠️ '.$this->dwEsc($r['today_total_warning']).'</div>';
    if($r['today_integration_warning']) $html.='<div class="dwx-notice">⚠️ '.$this->dwEsc($r['today_integration_warning']).'</div>';
    if($r['baseline_at']) {
        $html.='<div class="dwx-small" style="margin-top:11px">Tagesprognose eingefroren: '.date('d.m.Y H:i',$r['baseline_at']).' Uhr · '.
            ($r['baseline_early']?'Vor Tagesanbruch gespeichert':'Zu spaet gespeichert – wird NICHT fuer das Lernen gewertet').'</div>';
    } else {
        $html.='<div class="dwx-notice">Heute ist noch keine vollstaendige ICON-/ECMWF-Prognose gespeichert. Lerndaten werden nicht erfunden.</div>';
    }
    if(!$r['learning']['active']) $html.='<div class="dwx-notice">🧠 Kalibrierung lernt noch: '. $r['learning']['days'] .' von mindestens '.$this->setting('DW_PV_LERN_MIN_TAGE').' geeigneten abgeschlossenen Tagen. Faktor bleibt bis dahin bei 1,000.</div>';
    $html.='<section class="dwx-chart-block"><div class="dwx-chart-head"><h3>📊 Heute: Prognose und Messung</h3><span>kWh je voller Stunde · Blau = Prognose, Gruen = gemessen</span></div>' .
        '<div class="dwx-chart-scroll">'.$this->dwPVTodayChart($r['today'],time()).'</div></section>';
    $html.='<section class="dwx-chart-block"><div class="dwx-chart-head"><h3>📆 Prognosegenauigkeit · vergangene Tage</h3><span>kWh pro Tag · Tagesbeginn-Prognose vs. gemessener Ertrag</span></div>' .
        '<div class="dwx-chart-scroll">'.$this->dwPVHistoryChart($r['days']).'</div></section>';
    $html.='<div class="dwx-key"><span><span class="dwx-dot" style="background:#89aaff"></span>Prognose</span>' .
        '<span><span class="dwx-dot" style="background:#47d3b7"></span>PV-Istwerte</span></div>';
    $html.='<div class="dwx-tiny">* Fehlerstatistik aus bis zu 14 gueltigen abgeschlossenen Tagen, ohne nachtraegliche Prognose-Aenderung.<br>Messung alle '.$this->setting('DW_PV_MESSINTERVALL_MIN').' Minuten. Stundenwerte aus Zaehlerdifferenzen (bei Datenluecken nicht dargestellt).' .
        ' Tagesprognosen werden beim ersten geeigneten Tagesabruf fixiert und im Nachhinein nicht geaendert.' .
        ' Der Lernfaktor ist der begrenzte Median der letzten bis zu 30 gueltigen Ist/Prognose-Verhaeltnisse (0,65–1,35).' .
        ' Das ist eine anlagenspezifische Anpassung, keine Garantie gegen Wetterfehler. Nettoeinspeisung ist NICHT mit PV-Erzeugung identisch.' .
        ' Bei ungeprueften Messungen oder veralteten Modellen Prognose nicht fuer kritische Steuerungen verwenden.</div>';
    return $html.'</div>';
}


// =================== PV-STUNDENLEISTUNG (VERSION 3.2) ===================
/**
 * Open-Meteo: GTI ist der MITTELWERT der Stunde VOR dem Zeitstempel.
 * 'end_ts' = Ende eines vollen Stundenintervalls, 'start_ts' = end_ts-3600.
 * Der erste Datensatz enthaelt die bereits laufende (teilweise vergangene) Stunde.
 * Index 1..24 enthaelt die 24 naechsten VOLLEN Kalenderstunden.
 * Die Laufzeit 0..24 deckt auch ein rollierendes 24h-Fenster ab jetzt ab.
 */


private function dwPVPowerSeries(array $pvRows, float $factor, int $now): array
{
    $factor = min(1.35, max(0.65, $factor));
    $byEnd = [];
    foreach ($pvRows as $r) {
        if (isset($r['ts']) && is_numeric($r['ts'])) $byEnd[(int)$r['ts']] = $r;
    }
    $firstEnd = (int)(floor($now / 3600)*3600 + 3600);
    $out = [];
    for ($i=0; $i<=24; $i++) {
        $end = $firstEnd+$i*3600;
        $r = $byEnd[$end] ?? [];
        $convert = function ($kwh) use ($factor): ?int {
            if (!is_numeric($kwh) || !is_finite((float)$kwh)) return null;
            $w = max(0, (float)$kwh * 1000 * $factor);
            if ($this->setting('DW_PV_AC_LIMIT_W') > 0) $w = min($w, $this->setting('DW_PV_AC_LIMIT_W'));
            return (int)round($w);
        };
        $w = $convert($r['pv_kwh'] ?? null);
        $iw = $convert($r['pv_icon_kwh'] ?? null);
        $ew = $convert($r['pv_ecmwf_kwh'] ?? null);
        $out[] = [
            'index' => $i,
            'start_ts' => $end-3600,
            'end_ts' => $end,
            'start_local' => date(DATE_ATOM, $end-3600),
            'end_local' => date(DATE_ATOM, $end),
            'forecast_power_w' => $w,
            'icon_power_w' => $iw,
            'ecmwf_power_w' => $ew,
            'forecast_energy_wh_full_hour' => $w,
            'source' => $w === null ? null : ($r['basis'] ?? null),
            'gti_wm2' => $w === null ? null : ($r['gti_wm2'] ?? null),
            'valid' => $w !== null
        ];
    }
    return $out;
}
/** Stundenmittel als Watt mal Zeitanteil; nur bei vollstaendiger Abdeckung. */


private function dwPVPowerEnergyWindow(array $series, int $from, int $until): ?float
{
    if ($until <= $from) return 0.0;
    $covered = 0; $energyWh = 0.0;
    foreach ($series as $r) {
        $a = max($from, (int)$r['start_ts']);
        $b = min($until, (int)$r['end_ts']);
        if ($b <= $a) continue;
        if ($r['forecast_power_w'] === null) continue;
        $sec = $b-$a;
        $covered += $sec;
        $energyWh += $r['forecast_power_w']*$sec/3600;
    }
    if ($covered < $until-$from-1) return null;
    return round($energyWh / 1000.0, 3);
}
/** Zahlen fuer Symcon-Automationen plus gesonderter Gueltigkeitsstatus. */


private function dwPVPowerSummary(array $series, int $now): array
{
    $full = array_slice($series, 1, 24);
    $validCount = count(array_filter($full, static fn($r) => $r['valid']));
    $complete = count($full)===24 && $validCount===24;
    $current = $series[0]['forecast_power_w'] ?? null;
    $next = $series[1]['forecast_power_w'] ?? null;
    $peak = $complete ? max(array_map(static fn($r)=>$r['forecast_power_w'], $full)) : null;
    $energy = [];
    foreach ([1,3,6,12,24] as $hours) {
        $energy[(string)$hours] = $this->dwPVPowerEnergyWindow($series, $now, $now+$hours*3600);
    }
    // Die 24-Stunden-Komplett-Gueltigkeit erfordert auch die noch laufende Stunde,
    // da Steuerungen u.U. die rollierenden Ertragsfenster verwenden.
    $validRolling = $complete && $current !== null && $energy['24'] !== null;
    return [
        'valid' => $validRolling,
        'full_hours_available' => $validCount,
        'current_hour_mean_w' => $current,
        'next_full_hour_mean_w' => $next,
        'peak_full_hours_w' => $peak,
        'rolling_1h_kwh' => $energy['1'],
        'rolling_3h_kwh' => $energy['3'],
        'rolling_6h_kwh' => $energy['6'],
        'rolling_12h_kwh' => $energy['12'],
        'rolling_24h_kwh' => $energy['24'],
        'full_hours_begin_ts' => $series[1]['start_ts'] ?? null,
        'full_hours_end_ts' => $series[24]['end_ts'] ?? null,
        'forecast_model_1h' => $series[1]['source'] ?? null
    ];
}
/** Darstellung mit prognostizierten Stundenmitteln und zwei Modellkurven. */


private function dwPVPowerSvg(array $full): string
{
    if (!array_filter($full,static fn($r)=>$r['forecast_power_w']!==null)) {
        return '<div class="dwx-muted">Keine verwertbaren PV-Leistungswerte von ICON-D2/ECMWF vorhanden.</div>';
    }
    $w=940; $h=248; $l=51; $r=14; $top=12; $bottom=39;
    $pw=$w-$l-$r; $ph=$h-$top-$bottom; $n=count($full);
    $maxPower=500;
    foreach($full as $s) {
        foreach(['forecast_power_w','icon_power_w','ecmwf_power_w'] as $key) {
            if ($s[$key]!==null) $maxPower=max($maxPower,$s[$key]);
        }
    }
    $maxPower=(int)ceil($maxPower*1.13/500)*500;
    $x=static fn(int $i):float=>$l+($i+.5)*$pw/$n;
    $y=static fn(float $v):float=>$top+$ph*(1-$v/$maxPower);
    $svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" role="img" aria-label="24-Stunden-PV-Leistungsprognose in Watt">';
    for($i=0;$i<=4;$i++) {
        $value=$maxPower*$i/4; $yy=round($y($value),1);
        $svg.='<line x1="'.$l.'" x2="'.($w-$r).'" y1="'.$yy.'" y2="'.$yy.'" stroke="#343942" stroke-dasharray="3 6"/>';
        $svg.='<text x="'.($l-6).'" y="'.($yy+4).'" text-anchor="end" fill="#b0b7c2" font-size="11">'.$this->dwFmt($value,0).'</text>';
    }
    foreach ($full as $i=>$row) {
        $pos=$x($i);
        $val=$row['forecast_power_w'];
        if ($val!==null && $val>0) {
            $yy=$y($val); $bw=min(24,$pw/$n*.64);
            $x0=$pos-$bw/2;
            $label=date('d.m. H:i',$row['start_ts']).'–'.date('H:i',$row['end_ts']);
            $svg.='<rect x="'.round($x0,1).'" y="'.round($yy,1).'" width="'.round($bw,1).'" height="'.round(max(1,$top+$ph-$yy),1).'" rx="2" fill="#eabb62" opacity="0.88"><title>'.$this->dwEsc($label.' · Prognose '.$this->dwFmt($val,0).' W · '.($row['source']??'unbekannt')).'</title></rect>';
        }
        if($i%3===0 || $i===$n-1) {
            $lab=date('H', $row['start_ts']).'–'.date('H',$row['end_ts']);
            $svg.='<text x="'.round($pos,1).'" y="'.($h-16).'" text-anchor="middle" fill="#bbc2cb" font-size="10">'.$lab.'</text>';
        }
        if($i>0 && date('Y-m-d',$row['start_ts'])!==date('Y-m-d',$full[$i-1]['start_ts'])) {
            $mark=$pos-$pw/$n/2;
            $svg.='<line x1="'.round($mark,1).'" x2="'.round($mark,1).'" y1="'.$top.'" y2="'.($top+$ph).'" stroke="#666d79" stroke-dasharray="3 3" />';
            $svg.='<text x="'.round($mark+6,1).'" y="'.($h-2).'" fill="#aeb7c3" font-size="10">'.date('d.m.', $row['start_ts']).'</text>';
        }
    }
    foreach ([['key'=>'icon_power_w','color'=>'#56d6b5','title'=>'ICON-D2'],
             ['key'=>'ecmwf_power_w','color'=>'#87abfa','title'=>'ECMWF IFS']] as $config) {
        $parts=[]; $part=[];
        foreach($full as $i=>$row) {
            $v=$row[$config['key']];
            if($v===null) {if($part) $parts[]=$part; $part=[];continue;}
            $part[]=[round($x($i),1),round($y($v),1),$i];
        }
        if($part) $parts[]=$part;
        foreach($parts as $segment) {
            $points=implode(' ',array_map(static fn($p)=>$p[0].','.$p[1],$segment));
            $svg.='<polyline points="'.$points.'" stroke="'.$config['color'].'" stroke-width="2" fill="none" stroke-dasharray="5 3" stroke-linejoin="round" stroke-linecap="round"/>';
            foreach($segment as $p) {
                $v=$full[$p[2]][$config['key']];
                $svg.='<circle cx="'.$p[0].'" cy="'.$p[1].'" r="2" fill="'.$config['color'].'"><title>'.$this->dwEsc($config['title'].' · '.date('d.m. H:i',$full[$p[2]]['start_ts']).' · '.$this->dwFmt($v,0).' W').'</title></circle>';
            }
        }
    }
    $svg.='<text x="'.($l+5).'" y="'.($top+13).'" font-size="10" fill="#b5bdc9">W · prognostizierte Stundenmittelwerte</text>';
    return $svg.'</svg>';
}


private function dwPVPowerHtml(array $series, array $summary, array $reading, float $factor,
    array $warnings, bool $warnOK, int $warnStamp, array $info, array $pvQuality): string
{
    $full=array_slice($series,1,24);
    $modelStamp=max((int)($info['icon']['ts']??0),(int)($info['ecmwf']['ts']??0));
    $html=$this->dwCSS().'<div class="dwx"><div class="dwx-head"><div><h2>⚡ PV-Leistungsprognose · 24 h</h2>'.
        '<div class="dwx-sub">'.$this->dwEsc($this->setting('DW_ORT')).' · '.$this->dwPvSpecs(true).' · Watt je volle Stunde · ICON-D2 / ECMWF</div></div>'.
        ''.'</div>';
    if($pvQuality['code']===1) $html.='<div class="dwx-tiny">Wettermodell-Daten innerhalb der Gueltigkeitsfrist; PV-Automationsqualitaet: 1 (normal).</div>';
    if($pvQuality['code']===2) $html.='<div class="dwx-notice"><b>Prognose aus Cache / Ersatzmodell nutzbar:</b> '.$this->dwEsc($pvQuality['details']).'. Automationen koennen den Qualitaetswert 2 gezielt beruecksichtigen.</div>';
    if($pvQuality['code']===0) $html.='<div class="dwx-errorbox">PV-Automationsdaten ungueltig (Qualitaet 0). Leistungswerte werden vorsorglich auf 0 gesetzt.</div>';
    if(!$summary['valid']) {
        $html.='<div class="dwx-errorbox"><strong>Prognose nicht vollständig – nicht für Automationen verwenden.</strong><br>'.
            'Vollständige Stunden: '.(int)$summary['full_hours_available'].'/24. ' .
            'Prüfe den ICON-D2-/ECMWF-Abruf und die Lizenz. Fehlende Werte werden nicht als 0 W dargestellt.</div>';
    }
    $actual = $reading['power']!==null ? round($reading['power']*1000) : null;
    $html.='<div class="dwx-stats">'.
        '<div class="dwx-stat"><label>⚡ PV-Leistung live (Messung)</label><strong>'.$this->dwFmt($actual,0).' <small>W</small></strong></div>'.
        '<div class="dwx-stat"><label>🌤️ Aktuelle Stunde (Ø Prognose)</label><strong>'.$this->dwFmt($summary['current_hour_mean_w'],0).' <small>W</small></strong></div>'.
        '<div class="dwx-stat"><label>⏭️ Nächste volle Stunde (Ø)</label><strong>'.$this->dwFmt($summary['next_full_hour_mean_w'],0).' <small>W</small></strong></div>'.
        '<div class="dwx-stat"><label>📈 Spitzen-Stundenmittel nächste 24 h</label><strong>'.$this->dwFmt($summary['peak_full_hours_w'],0).' <small>W</small></strong></div>'.
        '<div class="dwx-stat"><label>🔋 Ertrag nächste 3 h ab jetzt</label><strong>'.$this->dwFmt($summary['rolling_3h_kwh'],2).' <small>kWh</small></strong></div>'.
        '<div class="dwx-stat"><label>🔌 Ertrag nächste 6 h ab jetzt</label><strong>'.$this->dwFmt($summary['rolling_6h_kwh'],2).' <small>kWh</small></strong></div>'.
        '<div class="dwx-stat"><label>☀️ Ertrag nächste 24 h ab jetzt</label><strong>'.$this->dwFmt($summary['rolling_24h_kwh'],2).' <small>kWh</small></strong></div>'.
        '<div class="dwx-stat"><label>🧠 Kalibrierfaktor</label><strong>'.$this->dwFmt($factor,3).' <small>×</small></strong></div>'.
        '</div>';
    $html.='<section class="dwx-chart-block"><div class="dwx-chart-head"><h3>📊 Erwartete PV-Leistung</h3>'.
        '<span>nächste 24 vollständige Stunden · Mittelwert W</span></div>'.
        '<div class="dwx-chart-scroll">'.$this->dwPVPowerSvg($full).'</div></section>'.
        '<div class="dwx-legend"><span><i style="background:#eabb62"></i>PV-Prognose (gewähltes Modell)</span>'.
        '<span><i style="background:#56d6b5"></i>DWD ICON-D2</span>'.
        '<span><i style="background:#87abfa"></i>ECMWF IFS</span></div>';
    $html.='<section class="dwx-chart-block"><div class="dwx-chart-head"><h3>🕒 Stundenwerte für Planung und KNX</h3>'.
        '<span>Watt = Durchschnitt im angegebenen Zeitfenster</span></div>'.
        '<div class="dwx-hourtable"><table><thead><tr><th>Zeitraum</th><th>Prognose</th><th>ICON-D2</th><th>ECMWF</th><th>Quelle</th></tr></thead><tbody>';
    foreach($full as $row) {
        $label=date('D d.m. H:i',$row['start_ts']).'–'.date('H:i',$row['end_ts']);
        $label=str_replace(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],['Mo','Di','Mi','Do','Fr','Sa','So'],$label);
        $html.='<tr><td>'.$this->dwEsc($label).'</td><td class="dwx-solar">'.$this->dwFmt($row['forecast_power_w'],0).' W</td>'.
            '<td>'.$this->dwFmt($row['icon_power_w'],0).' W</td><td>'.$this->dwFmt($row['ecmwf_power_w'],0).' W</td>'.
            '<td class="dwx-source">'.$this->dwEsc($row['source']??'keine Daten').'</td></tr>';
    }
    $html.='</tbody></table></div></section>';
    $html.='<div class="dwx-tiny">Leistung in Watt ist die <b>durchschnittlich erwartete PV-Erzeugung einer vollen Stunde</b>, '.
        'keine Echtzeitprognose oder garantierte Überschussleistung. Die Live-Messung wird getrennt angezeigt. '.
        '24 Tabellenstunden beginnen mit der nächsten vollen Stunde; die laufende Stunde steht oben separat. '.
        'Für rollierende kWh-Werte ab jetzt werden die angefangene und die letzte Teilstunde anteilig berücksichtigt.<br>'.
        'Berechnung: geneigte Einstrahlung (GTI) × '.$this->dwFmt($this->setting('DW_PV_KWP'),1).' kWp × PR ('.$this->dwFmt($this->setting('DW_PV_PR')*100,0).' %) × Lernfaktor ('.$this->dwFmt($factor,3).'×)'.
        ($this->setting('DW_PV_AC_LIMIT_W')>0?' · auf '.$this->dwFmt($this->setting('DW_PV_AC_LIMIT_W'),0).' W AC begrenzt':'').'. '.
        'Keine direkte Schaltung von Verbrauchern nur nach dieser Vorhersage; für Wallbox/KNX auch Echtzeit-PV-Leistung, '.
        'Hausverbrauch, Speicherstand und Prognosegültigkeit berücksichtigen. ' .
        'Letzter erfolgreicher Modellabruf: '.($modelStamp>0?date('d.m. H:i',$modelStamp).' Uhr':'keiner').'.</div>';
    $html.='<div class="dwx-footer">'.$this->dwOpenMeteoCredit().
        ' · © <a href="https://www.dwd.de/" target="_blank" rel="noopener">Deutscher Wetterdienst</a>'.
        ' · MOSMIX bleibt Wetter-Grundlage; GTI stammt aus den Zusatzmodellen.</div>';
    return $html.'</div>';
}


}
