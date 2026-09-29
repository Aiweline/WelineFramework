<?php
declare(strict_types=1);
// Reuse the isolated IO fixtures; real Sync, Reducer and Planner remain loaded.
require __DIR__.'/FpcDomainGroupPurge.script.php';
$states->state=$fresh;
$identityAdapter=new class($states){
    public array $remote=[];public int $puts=0;public bool $reject=true;public bool $sawPersisted=false;
    public function __construct(private $states){}
    public function getRules(string $zone,array $credentials):array {
        // Model a concurrent desired update between initial capture and binding write.
        $this->states->state['desired_version']=3;
        $this->states->state['jobs']['concurrent']=['pending_targets'=>['keep'=>'target'],'purge_receipt'=>['keep'=>'receipt']];
        return $this->remote;
    }
    public function putRules(string $zone,array $rules,array $credentials):array {
        ++$this->puts;
        $this->sawPersisted=count(current($this->states->state['cloud_rule_bindings']??[])?:[])===1;
        if(!$this->sawPersisted){throw new RuntimeException('legacy binding missing before cloud write');}
        if($this->reject){return ['success'=>false,'message'=>'fixture PUT failed after binding commit'];}
        $this->remote=$rules;return ['success'=>true];
    }
};
$service=new \Weline\Cdn\Service\FpcPolicySyncService($states,new \Weline\Cdn\Service\FpcPolicyPublishService(),$targets,new \Weline\Cdn\Service\RuleManager(),new \Weline\Cdn\Service\AdapterResolver($identityAdapter),new \Weline\Cdn\Service\AccountManager(),new \Weline\Cdn\Service\FpcPolicyHttpVerificationService());
$desired=(new ReflectionMethod($service,'desiredRules'))->invoke($service,[$a,$b],$identityAdapter);
$identityAdapter->remote=[$desired[0]['_legacy_rule']+['id'=>'legacy-public-id','ref'=>'legacy-public-id']];
$service->stage($states->state,$service->groups(),[]);
try{$service->run($key);throw new RuntimeException('expected failed first PUT');}catch(RuntimeException $e){if($e->getMessage()!=='fixture PUT failed after binding commit'){throw $e;}}
$map=$states->state['cloud_rule_bindings'];$zoneKey=hash('sha256',"cloudflare\nsame-zone");
if(!$identityAdapter->sawPersisted||($map[$zoneKey][$desired[0]['ref']]['id']??null)!=='legacy-public-id'){throw new RuntimeException('Binding not durably retained after failed PUT');}
if($states->state['desired_version']!==3||$states->state['jobs']['concurrent']!==['pending_targets'=>['keep'=>'target'],'purge_receipt'=>['keep'=>'receipt']]){throw new RuntimeException('Binding mutation overwrote concurrent desired/targets/receipts');}
$identityAdapter->reject=false;$service->run($key);
if(count($identityAdapter->remote)!==1||$identityAdapter->remote[0]['id']!=='legacy-public-id'||$states->state['cloud_rule_bindings']!==$map){throw new RuntimeException('Retry changed ownership/duplicated rule');}
$puts=$identityAdapter->puts;$service->run($key);if($identityAdapter->puts!==$puts){throw new RuntimeException('No-op repeated PUT');}
if(isset($map[hash('sha256',"cloudflare\nother-zone")])||isset($map[hash('sha256',"other-adapter\nsame-zone")])){throw new RuntimeException('Ownership leaked to another adapter/zone');}
echo "PASS actual Sync: binding committed before failed PUT, retry/no-op, concurrent desired/targets/receipts retained, adapter/zone isolation.\n";
