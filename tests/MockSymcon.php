<?php
declare(strict_types=1);

// Runtime fuer lokale PHP-CLI-Tests: keine externen Wetter-HTTP-Aufrufe.
const VARIABLE_PRESENTATION_WEB_CONTENT = 'web';
$GLOBALS['mockObjects'] = [];
$GLOBALS['mockValues'] = [];
$GLOBALS['mockNextId'] = 10000;
$GLOBALS['mockLogs'] = [];

function fakeNewObject(int $type, int $parent = 0): int
{
    $id = ++$GLOBALS['mockNextId'];
    $GLOBALS['mockObjects'][$id] = ['ObjectType' => $type, 'ObjectID' => $id, 'ParentID' => $parent,
        'ObjectIdent' => '', 'ObjectName' => '', 'VariableType' => 3, 'VariableUpdated' => time()];
    $GLOBALS['mockValues'][$id] = null;
    return $id;
}
function IPS_ObjectExists(int $id): bool { return isset($GLOBALS['mockObjects'][$id]); }
function IPS_VariableExists(int $id): bool { return IPS_ObjectExists($id) && $GLOBALS['mockObjects'][$id]['ObjectType'] === 2; }
function IPS_CreateVariable(int $type): int {
    $id = fakeNewObject(2);
    $GLOBALS['mockObjects'][$id]['VariableType'] = $type;
    $GLOBALS['mockValues'][$id] = [false, 0, 0.0, ''][$type];
    return $id;
}
function IPS_CreateCategory(): int { return fakeNewObject(0); }
function IPS_SetParent(int $id, int $parent): void { $GLOBALS['mockObjects'][$id]['ParentID'] = $parent; }
function IPS_SetIdent(int $id, string $ident): void { $GLOBALS['mockObjects'][$id]['ObjectIdent'] = $ident; }
function IPS_SetName(int $id, string $name): void { $GLOBALS['mockObjects'][$id]['ObjectName'] = $name; }
function IPS_SetHidden(int $id, bool $hidden): void { $GLOBALS['mockObjects'][$id]['Hidden'] = $hidden; }
function IPS_GetObjectIDByIdent(string $ident, int $parent): int|false {
    foreach ($GLOBALS['mockObjects'] as $id => $object) {
        if ($object['ParentID'] === $parent && $object['ObjectIdent'] === $ident) return $id;
    }
    return false;
}
function IPS_GetParent(int $id): int { return $GLOBALS['mockObjects'][$id]['ParentID']; }
function IPS_GetObject(int $id): array { return $GLOBALS['mockObjects'][$id]; }
function IPS_GetVariable(int $id): array { return $GLOBALS['mockObjects'][$id]; }
function GetValue(int $id): mixed { return $GLOBALS['mockValues'][$id] ?? null; }
function SetValue(int $id, mixed $value): void { $GLOBALS['mockValues'][$id] = $value; $GLOBALS['mockObjects'][$id]['VariableUpdated'] = time(); }
function IPS_LogMessage(string $who, string $msg): void { $GLOBALS['mockLogs'][] = [$who,$msg]; }
function IPS_SemaphoreEnter(string $key, int $timeout): bool { return true; }
function IPS_SemaphoreLeave(string $key): void {}

abstract class IPSModuleStrict
{
    public int $InstanceID;
    private array $properties=[];
    private array $attributes=[];
    public array $timers=[];
    public array $hooks=[];
    public int $status=0;
    public string $summary='';
    public function __construct(int $id) {
        $this->InstanceID=$id;
        $GLOBALS['mockObjects'][$id]=['ObjectType'=>1,'ObjectID'=>$id,'ParentID'=>0,'ObjectIdent'=>'', 'VariableType'=>0];
        $this->Create();
        $this->ApplyChanges();
    }
    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function RegisterPropertyString(string $key,string $val): void {$this->properties[$key]=$val;}
    public function RegisterPropertyInteger(string $key,int $val): void {$this->properties[$key]=$val;}
    public function RegisterPropertyFloat(string $key,float $val): void {$this->properties[$key]=$val;}
    public function RegisterPropertyBoolean(string $key,bool $val): void {$this->properties[$key]=$val;}
    public function ReadPropertyString(string $key): string {return (string)$this->properties[$key];}
    public function ReadPropertyInteger(string $key): int {return (int)$this->properties[$key];}
    public function ReadPropertyFloat(string $key): float {return (float)$this->properties[$key];}
    public function ReadPropertyBoolean(string $key): bool {return (bool)$this->properties[$key];}
    public function SetTestProperty(string $key,mixed $value): void {$this->properties[$key]=$value;}
    public function RegisterAttributeString(string $key,string $val): void {$this->attributes[$key]=$val;}
    public function RegisterAttributeInteger(string $key,int $val): void {$this->attributes[$key]=$val;}
    public function RegisterAttributeBoolean(string $key,bool $val): void {$this->attributes[$key]=$val;}
    public function ReadAttributeString(string $key): string {return $this->attributes[$key];}
    public function ReadAttributeInteger(string $key): int {return $this->attributes[$key];}
    public function ReadAttributeBoolean(string $key): bool {return $this->attributes[$key];}
    public function WriteAttributeString(string $key,string $val): void {$this->attributes[$key]=$val;}
    public function WriteAttributeInteger(string $key,int $val): void {$this->attributes[$key]=$val;}
    public function WriteAttributeBoolean(string $key,bool $val): void {$this->attributes[$key]=$val;}
    public function RegisterTimer(string $key,int $interval,string $code): void {$this->timers[$key]=['interval'=>$interval,'code'=>$code];}
    public function SetTimerInterval(string $key,int $interval): void {$this->timers[$key]['interval']=$interval;}
    public function RegisterHook(string $name): bool {$this->hooks[$name]=true;return true;}
    public function SetSummary(string $summary): void {$this->summary=$summary;}
    public function SetStatus(int $status): void {$this->status=$status;}
    public function SendDebug(string $key,mixed $value,int $format): void {}
    public function GetIDForIdent(string $ident): int {return IPS_GetObjectIDByIdent($ident,$this->InstanceID);}
    private function createV(string $key,string $name,int $type): bool {
        $id=IPS_GetObjectIDByIdent($key,$this->InstanceID);
        if ($id!==false) return false;
        $id=IPS_CreateVariable($type);
        IPS_SetParent($id,$this->InstanceID);
        IPS_SetIdent($id,$key);
        IPS_SetName($id,$name);
        return true;
    }
    protected function RegisterVariableBoolean(string $key,string $name,array $presentation=[]): bool {return $this->createV($key,$name,0);}
    protected function RegisterVariableInteger(string $key,string $name,array $presentation=[]): bool {return $this->createV($key,$name,1);}
    protected function RegisterVariableFloat(string $key,string $name,array $presentation=[]): bool {return $this->createV($key,$name,2);}
    protected function RegisterVariableString(string $key,string $name,array $presentation=[]): bool {return $this->createV($key,$name,3);}
    protected function GetValue(string $ident): mixed {return GetValue($this->GetIDForIdent($ident));}
    protected function SetValue(string $ident,mixed $val): void {SetValue($this->GetIDForIdent($ident),$val);}
}
