<?php
declare(strict_types=1);
require __DIR__.'/MockSymcon.php';
require __DIR__.'/../DWDWeather/module.php';
function assertLoc(bool $yes,string $message): void {
    if (!$yes) throw new RuntimeException($message);
    echo "OK $message\n";
}
class DWDMapTest extends DWDWeather {
    public bool $offline = false;
    public ?array $geo = null;
    protected function dwReverseLocation(float $lat,float $lon): array {
        if ($this->offline) throw new RuntimeException('DNS offline');
        return $this->geo ?? ['address'=>[
            'village'=>'Niederdorf', 'postcode'=>'09366',
            'county'=>'Erzgebirgskreis', 'state'=>'Sachsen', 'country_code'=>'de'
        ]];
    }
    protected function dwNearestStationHeight(float $lat,float $lon): ?int {return 393;}
}
assertLoc(DWDWeather::decodeMapLocation('{"latitude":0,"longitude":0}') === null, 'keine vorgewaehlte Kartenposition');
assertLoc(DWDWeather::decodeMapLocation('not json') === null, 'ungueltige JSON-Position');
assertLoc(DWDWeather::decodeMapLocation('{"latitude":999,"longitude":14}') === null, 'ungueltige Breitengrad-Grenze');
assertLoc(DWDWeather::decodeMapLocation('{"latitude":50.7242,"longitude":12.7834}') === [50.7242,12.7834], 'gueltige Karte erkannt');
assertLoc(DWDWeather::dwNormalizeCounty('Stadt Meißen')==='stadt meissen', 'Umlaute ohne mbstring');
assertLoc(DWDWeather::dwWarnCellFromCsv("114521000;Erzgebirgskreis;Sachsen\n114511000;Stadt Chemnitz;Sachsen", 'Erzgebirgskreis') === ['id'=>'114521000','name'=>'Erzgebirgskreis'], 'DWD CSV eindeutig');
assertLoc(DWDWeather::dwWarnCellFromCsv("914524001;Kreis Zwickau;Sachsen\n914524002;Kreis Zwickau;Sachsen", 'Kreis Zwickau') === null,'Mehrdeutige Warnregion nie erraten');
$mod = new DWDMapTest(9222);
assertLoc($mod->status===201,'nicht konfiguriert nach Installation');
$noMap=$mod->ResolveLocation('{"latitude":0,"longitude":0}');
assertLoc(str_contains($noMap,'gueltigen Standort'),'kein Abruf bei fehlendem Kartenpunkt');
$validMap='{ "latitude": 50.7242, "longitude": 12.7834 }';
$result=$mod->ResolveLocation($validMap);
assertLoc(str_contains($result, 'Niederdorf (09366)'), 'Ortsname aus Rueckwaertssuche');
assertLoc($mod->ReadPropertyFloat('DW_LAT')===50.7242 && $mod->ReadPropertyFloat('DW_LON')===12.7834, 'GPS in Eigenschaften uebertragen');
assertLoc($mod->ReadPropertyString('DW_WARNCELL_ID')==='114521000', 'DWD Warnregion korrekt');
assertLoc($mod->ReadPropertyInteger('DW_HOEHE_M')===393, 'ungefaehre Hoehe aus DWD Station');
assertLoc($mod->status===201, 'PV-Pflichtdaten bleiben leer, kein Wetterabruf');
assertLoc($mod->ReadPropertyFloat('DW_PV_KWP')===0.0, 'PV-Leistung nicht erraten');
assertLoc(str_contains($mod->ResolveLocation($validMap), 'bereits ermittelt'), 'keine wiederholte Abfrage fuer gleichen Punkt');
// Geografie wechseln, fehlgeschlagene Rueckwaertssuche darf nicht altes Warngebiet liefern.
$mod->offline=true;
$next=$mod->ResolveLocation('{"latitude":51.1,"longitude":12.4}');
assertLoc(str_contains($next,'Ortsnamenssuche nicht erreichbar'), 'Offline-Ausfall klar beschrieben');
assertLoc($mod->ReadPropertyString('DW_WARNCELL_ID')==='', 'Warngebiet nach Ortswechsel ohne Zuordnung geleert');
assertLoc($mod->ReadPropertyString('DW_ORT')==='', 'Alter Ortsname nach Wechsel geloescht');
assertLoc($mod->status===201, 'Instanz ohne bestaetigtes Warngebiet inaktiv');
assertLoc((bool)$mod->ReadPropertyBoolean('DW_OM_ENABLE')===false,'Keine Open-Meteo-Voreinstellung');
echo "Standorttests abgeschlossen\n";
