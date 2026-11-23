<?php
declare(strict_types=1);
require __DIR__.'/MockSymcon.php';
require __DIR__.'/../DWDWeather/module.php';
ob_start();

function must(bool $condition,string $desc): void {
    if (!$condition) { throw new RuntimeException('Test fehlgeschlagen: '.$desc); }
    echo "OK: $desc\n";
}
// Alte Messwerte nur als Simulation. HTTP wird im 'measure'-Modus nicht aufgerufen.
foreach ([16425 => [2, 16500.0],49528 => [2, 58000.0],23727 => [1, 3400]] as $id => [$type,$value]) {
    $GLOBALS['mockObjects'][$id] = ['ObjectType'=>2, 'ObjectID'=>$id, 'ParentID'=>10,
        'ObjectIdent'=>'measured'.$id, 'VariableType'=>$type, 'VariableUpdated'=>time()];
    $GLOBALS['mockValues'][$id]=$value;
}
$mod = new DWDWeather(9001);
// Neue Instanzen duerfen keinerlei personen- oder anlagenspezifische Werte haben.
$emptyFields = [
    'DW_ORT'=>'', 'DW_LAT'=>0.0, 'DW_LON'=>0.0, 'DW_HOEHE_M'=>0,
    'DW_WARNCELL_ID'=>'', 'DW_WARNGEBIET_NAME'=>'', 'DW_FALLBACK_STATION'=>'',
    'DW_FALLBACK_NAME'=>'', 'DW_PV_KWP'=>0.0, 'DW_PV_TILT'=>0,
    'DW_PV_AZIMUTH'=>0, 'DW_PV_PR'=>0.0,
    'DW_PV_ID_TAGESERTRAG'=>0, 'DW_PV_ID_GESAMTERTRAG'=>0,
    'DW_PV_ID_LEISTUNG'=>0, 'DW_PV_UNIT_TAGESERTRAG'=>'',
    'DW_PV_UNIT_GESAMTERTRAG'=>'', 'DW_PV_UNIT_LEISTUNG'=>'',
    'DW_OM_ENABLE'=>false, 'DW_OM_PRIVATE_USE_ONLY'=>false,
    'DW_HTTP_TIMEOUT_S'=>0, 'DW_PV_LERN_MIN_TAGE'=>0,
];
foreach ($emptyFields as $key=>$default) {
    must((new ReflectionProperty(IPSModuleStrict::class, 'properties'))->getValue($mod)[$key] === $default, 'Neue Instanz ohne Vorgabe: '.$key);
}
must($mod->status===201,'Frische Instanz ohne Pflichtdaten inaktiv');
foreach ($mod->timers as $name=>$timer) {
    must($timer['interval']===0,'Unvollstaendig: Timer deaktiviert: '.$name);
}
must(!$mod->RequestRefresh(),'Kein manueller Abruf ohne Konfiguration');
must(IPS_GetObjectIDByIdent('DWD_KACHEL_7T', 9001)===false,'Ohne Konfiguration keine Kacheln');
$required = [
    'DW_ORT'=>'Niederdorf / Erzgebirge (09366)', 'DW_LAT'=>50.7242, 'DW_LON'=>12.7834,
    'DW_HOEHE_M'=>400, 'DW_WARNCELL_ID'=>'114521000',
    'DW_WARNGEBIET_NAME'=>'Erzgebirgskreis', 'DW_FALLBACK_STATION'=>'10577',
    'DW_FALLBACK_NAME'=>'Chemnitz', 'DW_PV_KWP'=>22.5, 'DW_PV_TILT'=>25,
    'DW_PV_AZIMUTH'=>20, 'DW_PV_PR'=>0.82,
    'DW_PV_ID_TAGESERTRAG'=>16425, 'DW_PV_ID_GESAMTERTRAG'=>49528,
    'DW_PV_ID_LEISTUNG'=>23727, 'DW_PV_UNIT_TAGESERTRAG'=>'Wh',
    'DW_PV_UNIT_GESAMTERTRAG'=>'Wh', 'DW_PV_UNIT_LEISTUNG'=>'W',
    'DW_OM_ENABLE'=>true, 'DW_OM_PRIVATE_USE_ONLY'=>true,
];
foreach ($required as $key=>$value) $mod->SetTestProperty($key,$value);
$mod->ApplyChanges();
must($mod->status===102,'Modul nach vollstaendiger Eingabe aktiv');
// 0 in erweiterten Eingaben wird erst intern zu sicheren Betriebswerten.
$setting = new ReflectionMethod($mod, 'setting');
must($setting->invoke($mod,'DW_HTTP_TIMEOUT_S')===20,'Ohne Technikvorgabe: HTTP-Timeout 20s intern');
must($setting->invoke($mod,'DW_TAGE')===7,'Ohne Technikvorgabe: Sieben Tage intern');
must($setting->invoke($mod,'DW_PV_MESSINTERVALL_MIN')===5,'Ohne Technikvorgabe: PV-Intervall intern');

must(count($mod->hooks)===1,'Eindeutiger Hook');
must(count($mod->timers)===5,'Fuenf Module-Timer');
must($mod->GetIDForIdent('DWD_KACHEL_7T')>0,'7-Tage-HTML-Variable erstellt');
must($mod->GetIDForIdent('DWD_KACHEL_WARN')>0,'Warnungskachel erstellt');
must($mod->GetIDForIdent('DWD_KACHEL_PV_LEISTUNG_24H')>0,'PV-Stundenkachel erstellt');
must($mod->GetIDForIdent('DWD_PV_LEISTUNG_24H_VALID')>0,'Gueltigkeitsvariable erstellt');
$mod->MeasureTick();
must(!empty(GetValue($mod->GetIDForIdent('DWD_KACHEL_24H'))),'HTML 24-Stunden-Kachel gerendert');
must(!empty(GetValue($mod->GetIDForIdent('DWD_KACHEL_WARN'))),'Warnstatus gerendert');
must(str_contains((string)GetValue($mod->GetIDForIdent('DWD_KACHEL_24H')),'2-Stunden'),'24h-Kachel enthaelt 2h-Vorschau');
must(str_contains((string)GetValue($mod->GetIDForIdent('DWD_KACHEL_MODELLE')),'Wetterdaten jetzt abrufen'),'Button im Modellvergleich');
must(str_contains((string)GetValue($mod->GetIDForIdent('DWD_KACHEL_PV_IST')),'22,5 kWp'),'PV-Daten dynamisch');
must((int)GetValue($mod->GetIDForIdent('DWD_PV_24H_QUALITAET'))===0,'Fehlende Wetterdaten -> Gueltigkeit 0');
must(GetValue($mod->GetIDForIdent('DWD_PV_LEISTUNG_24H_VALID')) === false,'Fail closed fuer Automation');
must($mod->RequestRefresh(),'Manuelle Aktualisierung kann angefordert werden');
must(!$mod->RequestRefresh(),'Manueller Abruf Sperrfrist');
// Vollstaendigen simulierten Modellcache fuer 24h/7d-Verlauf verarbeiten.
$base=(int)ceil(time()/3600)*3600;
$mosRows=[]; $solarRows=[];
for ($h=0; $h<200; $h++) {
    $ts=$base+$h*3600;
    $hour=(int)date('G',$ts);
    $sun=$hour>=7 && $hour<=18;
    $mosRows[]=['ts'=>$ts,'temp'=>12.5 + ($sun?4:0),'rain'=>0.1,'sun'=>$sun?1800.0:0.0,
        'wind'=>12.0,'cloud'=>50.0,'ww'=>2.0,'ghi'=>$sun?410.0:0.0,'prob'=>20.0];
    $solarRows[]=['ts'=>$ts,'temp'=>12.0,'rain'=>0.08,'sun'=>$sun?1900.0:0.0,
        'wind'=>12.0,'cloud'=>40.0,'ww'=>2.0,'ghi'=>$sun?450.0:0.0,'gti'=>$sun?360.0:0.0];
}
foreach (['DWD_CACHE_FORECAST'=>$mosRows,'DWD_CACHE_ICON'=>$solarRows,'DWD_CACHE_ECMWF'=>$solarRows] as $ident=>$value) {
    SetValue($mod->GetIDForIdent($ident),json_encode($value));
}
foreach (['DWD_FORECAST_TIME','DWD_ICON_TIME','DWD_ECMWF_TIME','DWD_WARNING_TIME'] as $ident) {
    SetValue($mod->GetIDForIdent($ident),time());
}
SetValue($mod->GetIDForIdent('DWD_WARN_VALID'),true);
SetValue($mod->GetIDForIdent('DWD_CACHE_WARNINGS'),'[]');
$mod->MeasureTick();
must((bool)GetValue($mod->GetIDForIdent('DWD_PV_LEISTUNG_24H_VALID')),'Simulierte 24h PV-Daten freigegeben');
must((int)GetValue($mod->GetIDForIdent('DWD_PV_24H_QUALITAET'))===1,'Simulierte Prognose mit Qualitaet 1');
$hourly=json_decode(GetValue($mod->GetIDForIdent('DWD_PV_LEISTUNG_24H_JSON')),true);
must(count($hourly['hours'])===24,'24 gueltige Stundenwerte erzeugt');
must(str_contains((string)GetValue($mod->GetIDForIdent('DWD_KACHEL_7T')),'Keine Warnungen'),'Nur Vorhersage zeigt Warnfreigabe');
must(!str_contains((string)GetValue($mod->GetIDForIdent('DWD_KACHEL_MODELLE')),'Keine Warnungen'),'Im Modellvergleich keine redundante Entwarnung');
// Manuelle POST-Aktion muss mit Token geschuetzt sein.
$_SERVER['REQUEST_METHOD']='GET'; $_GET=['dwd_view'=>'warnings','dwd_key'=>hash_hmac('sha256','dwd-warnings-only-v1',$mod->ReadAttributeString('ControlToken'))];
ob_start();
$hook=new ReflectionMethod($mod,'ProcessHookData');
$hook->invoke($mod);
$warningPage=ob_get_clean();
must(str_contains($warningPage,'warnungsfrei'),'Klick-Link oeffnet separate Warnansicht');
$_SERVER['REQUEST_METHOD']='POST'; $_POST=['dwd_action'=>'refresh','dwd_token'=>'falsch'];
ob_start(); $hook->invoke($mod); $unauthorized=ob_get_clean();
must(str_contains($unauthorized,'nicht autorisiert'),'Unberechtigter POST abgewiesen');
// Neue Instanz darf eigenstaendige Werte haben.
$second = new DWDWeather(9002);
foreach ($required as $key=>$value) $second->SetTestProperty($key,$value);
$second->SetTestProperty('DW_ORT','Chemnitz Test');
$second->SetTestProperty('DW_PV_KWP',5.5);
$second->ApplyChanges();
$second->MeasureTick();
must(str_contains((string)GetValue($second->GetIDForIdent('DWD_KACHEL_7T')),'Chemnitz Test'),'Neue Instanz eigene Ortseinstellung');
must(str_contains((string)GetValue($second->GetIDForIdent('DWD_KACHEL_PV')),'5,5 kWp'),'Neue Instanz eigene PV-Einstellung');
must(!str_contains((string)GetValue($mod->GetIDForIdent('DWD_KACHEL_PV')),'5,5 kWp'),'Erste Instanz unveraendert');
$mod->SetTestProperty('DW_ORT', '');
$mod->ApplyChanges();
must($mod->status===201, 'Ein bereits aktives Modul wird bei entfernten Pflichtfeldern inaktiv');
must(GetValue($mod->GetIDForIdent('DWD_PV_LEISTUNG_24H_VALID'))===false, 'Nach Loeschen Pflichtfeld keine Freigabe alter PV-Daten');
must(GetValue($mod->GetIDForIdent('DWD_PV_24H_QUALITAET'))===0, 'Nach Loeschen Pflichtfeld Qualitaet 0');
$mod->SetTestProperty('DW_ORT', $required['DW_ORT']);
$mod->ApplyChanges();
must($mod->status===102,'Bestehende Instanz nach Rueckkehr gueltiger Konfiguration aktiv');

// Einmaliger Cache-/Lerndatenimport ohne Mutation der alten Objekte.
$oldCat=IPS_CreateCategory();
foreach (['DWD_PV_IST_STATE_JSON' => '{"days":{"2026-11-20":{"actual":12}}}', 'DWD_STATION_ID'=>'O013'] as $ident=>$value) {
    $id=IPS_CreateVariable(3); IPS_SetParent($id,$oldCat);IPS_SetIdent($id,$ident); SetValue($id,$value);
}
$second->SetTestProperty('LegacyCategoryID',$oldCat);
$second->ApplyChanges();
must($second->ImportLegacy()===2,'Altdaten einmalig importiert');
must(str_contains((string)GetValue($second->GetIDForIdent('DWD_PV_IST_STATE_JSON')),'2026-11-20'),'PV-Historie erhalten');
try {$second->ImportLegacy(); throw new RuntimeException('Zweiter Import haette scheitern muessen');}
catch (RuntimeException $e) {must(str_contains($e->getMessage(),'bereits importiert'),'Import nur einmal');}
echo "Alle Mock-Tests bestanden.\n";

ob_end_flush();
