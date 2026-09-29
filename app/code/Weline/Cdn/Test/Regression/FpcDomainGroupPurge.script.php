<?php
declare(strict_types=1);
// Real Sync/Reducer/Planner/Scope code; isolate only database, Queue and cloud transport boundaries.
namespace Weline\Cdn\Model {
    class Domain {
        public function __construct(private array $data){}
        public function getId():int{return $this->data['domain_id'];}
        public function getData(string $key):mixed{return $this->data[$key]??null;}
    }
}
namespace Weline\Framework\Compilation {
    class AtomicCompiledFilePublisher {
        public function __construct(int $timeout){}public function acquireDirectoryLock(string $path):bool{return true;}public static function releaseDirectoryLock(string $path):void{}
    }
}
namespace Weline\Framework\Runtime {
    class RuntimeProviderResolution {public const NOT_CONFIGURED='not_configured';public string $status='not_configured';}
    class RuntimeProviderResolver {public function resolveDetailed(string $contract):RuntimeProviderResolution{return new RuntimeProviderResolution();}}
}
namespace Weline\Framework\Manager {class ObjectManager {public static function getInstance(string $class):object{return new $class();}}}
namespace Weline\Cdn\Service {
    class FpcPolicyStateService {public array $state=[];public function read():array{return $this->state;}public function mutate(callable $fn):mixed{return $fn($this->state);}}
    class FpcPolicyPublishService {public function publishLatest():array{return ['source_version'=>2];}}
    class FpcPolicyTargetService {public function __construct(public array $fixtures){}public function domains(bool $enabled=true):array{return $this->fixtures;}public function publicEndpoints(\Weline\Cdn\Model\Domain $d):array{$h=$d->getData('domain_name');return [['host'=>$h,'base_url'=>'https://'.$h]];}}
    class RuleManager {
        public function resolveCredentialSource(\Weline\Cdn\Model\Domain $d):array{return ['account_id'=>7,'credential_identity'=>'account:7','credentials'=>[]];}
        public function getCredentials(\Weline\Cdn\Model\Domain $d):array{return ['fixture'=>'transport-isolated'];}
        public function planRulesForEdgePush(\Weline\Cdn\Model\Domain $d,mixed $unused=null,bool $all=true):array{return [['_weline_identity'=>'default:0','expression'=>'true','action'=>'set_cache_settings','action_parameters'=>['cache'=>true]]];}
    }
    class AdapterResolver {public function __construct(public object $adapter){}public function getAdapter(string $code):object{return $this->adapter;}}
    class AccountManager {}
    class FpcPolicyHttpVerificationService {public function verify(array $job,array $domains,array $snapshot):array{return ['coverage'=>'sample','snapshot_revision'=>'fixture','verified'=>false,'status'=>'unmet'];}}
    class CompiledFpcPolicySnapshotProvider {public function snapshot():array{return ['revision'=>'fixture'];}}
    function w_query(string $provider,string $operation,array $params):array {
        if($provider!=='queue'||$operation!=='createIfAbsent'){throw new \RuntimeException('Unexpected external operation');}
        $GLOBALS['admitted'][]=$params;return ['success'=>true,'created'=>true,'queue_id'=>count($GLOBALS['admitted']),'status'=>'pending'];
    }
}
namespace {
    use Weline\Cdn\Model\Domain;
    use Weline\Cdn\Service\{FpcPolicySyncService,FpcPolicyStateService,FpcPolicyPublishService,FpcPolicyTargetService,RuleManager,AdapterResolver,AccountManager,FpcPolicyHttpVerificationService};
    define('BP',dirname(__DIR__,6));
    foreach(['ScopeIdentity','ScopeEnvelope'] as $c){require BP.'/app/code/Weline/Framework/Runtime/'.$c.'.php';}
    foreach(['FpcPolicyStateReducer','FpcPolicyRulePlanner','FpcPolicySyncService'] as $c){require BP.'/app/code/Weline/Cdn/Service/'.$c.'.php';}
    $fail=[];$count=0;$assert=static function(bool $ok,string $why)use(&$fail,&$count):void{++$count;if(!$ok){$fail[]=$why;}};
    $a=new Domain(['domain_id'=>1,'domain_name'=>'a.example.invalid','adapter'=>'cloudflare','zone_id'=>'same-zone','enabled'=>1]);
    $b=new Domain(['domain_id'=>2,'domain_name'=>'b.example.invalid','adapter'=>'cloudflare','zone_id'=>'same-zone','enabled'=>1]);
    $adapter=new class {
        public array $purged=[];
        public function getRules(string $z,array $c):array{return [];}
        public function putRules(string $z,array $r,array $c):array{return ['success'=>true];}
        public function purgeUrls(string $z,array $urls,array $c):array{$this->purged=array_merge($this->purged,$urls);return ['success'=>true,'purge_ids'=>['fixture-purge']];}
    };
    $states=new FpcPolicyStateService();$targets=new FpcPolicyTargetService([$b,$a,$b]);
    $sync=new FpcPolicySyncService($states,new FpcPolicyPublishService(),$targets,new RuleManager(),new AdapterResolver($adapter),new AccountManager(),new FpcPolicyHttpVerificationService());
    $key=$sync->binding($a)['job_key'];$fresh=['next_sync_id'=>1,'desired_version'=>2,'origin_revision'=>'fixture','jobs'=>[]];
    $scopeTarget=['domain_id'=>1,'kind'=>'url','value'=>'https://a.example.invalid/shop-a/blog'];
    $outside=['domain_id'=>99,'kind'=>'url','value'=>'https://foreign.example.invalid/blog'];
    $groups=$sync->groups();
    $assert($groups[$key]['domain_ids']===[1,2],'groups must retain sorted unique [1,2] domain membership');
    $states->state=$fresh;$sync->stage($states->state,$groups,['purge_targets'=>[$scopeTarget,$outside]]);
    $pending=array_values($states->state['jobs'][$key]['pending_targets']);
    $assert(count($pending)===1&&($pending[0]['target']??null)===$scopeTarget,'stage must retain the one Scope target and exclude foreign domain');
    $assert($states->state['jobs']['origin']['pending_targets']===[],'origin job must not gain CDN purge targets');
    $sync->run($key);
    $assert($adapter->purged===[$scopeTarget['value']],'run must call actual purge boundary with retained Scope URL');
    $assert($states->state['jobs'][$key]['pending_targets']===[],'ack must consume accepted target');
    $assert(count($states->state['jobs'][$key]['purge_receipt']['targets']??[])===1,'accepted purge needs a target receipt');
    $states->state=$fresh;
    $admission=$sync->requestSync(['purge_targets'=>[$scopeTarget,$outside]]);
    $assert($states->state['jobs'][$key]['domain_ids']===[1,2],'requestSync must persist sorted unique domain membership');
    $assert(count($states->state['jobs'][$key]['pending_targets'])===1,'requestSync must persist the Scope purge target');
    $assert(count($admission['queue_ids'])===2&&$admission['admission_errors']===[],'Queue admission must wake zone plus origin');
    $states->state=$fresh;$sync->requestSync(['domain_ids'=>[1],'purge_targets'=>[$scopeTarget,$outside]]);
    $assert($states->state['jobs'][$key]['domain_ids']===[1],'explicit domain selection must not gain another domain');
    $assert(count($states->state['jobs'][$key]['pending_targets'])===1,'filtered request must keep its Scope purge target');
    if($fail!==[]){fwrite(STDERR,"FAIL ".implode("\nFAIL ",$fail)."\n");exit(1);}
    echo "PASS {$count} real Sync group→state→Queue→run purge checks; no network/database writes.\n";
}
