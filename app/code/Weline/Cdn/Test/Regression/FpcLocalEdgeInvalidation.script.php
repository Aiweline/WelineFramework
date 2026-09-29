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
namespace Weline\Cdn\Service {
    class FpcPolicyStateService {public array $state=[];public function read():array{return $this->state;}public function mutate(callable $fn):mixed{return $fn($this->state);}}
    class FpcPolicyPublishService {public function publishLatest():array{$GLOBALS['order'][]='origin';return ['source_version'=>2];}}
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
namespace Weline\Framework\Runtime {
    interface RuntimeEdgeCacheInvalidatorInterface {public function invalidateHosts(array $hosts,string $operationId):array;}
    class RuntimeProviderResolution {public const AVAILABLE='available',NOT_CONFIGURED='not_configured',CONFIGURED_UNAVAILABLE='configured_unavailable'; public function __construct(public string $status,public ?object $provider=null,public string $errorCode='',public string $error='') {}}
    class RuntimeProviderResolver {public function resolveDetailed(string $contract):RuntimeProviderResolution{return $GLOBALS['edgeResolution'];}}
}
namespace Weline\Framework\Manager {class ObjectManager {public static function getInstance(string $class):object{return new $class();}}}
namespace {
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\{FpcPolicySyncService,FpcPolicyStateService,FpcPolicyPublishService,FpcPolicyTargetService,RuleManager,AdapterResolver,AccountManager,FpcPolicyHttpVerificationService,FpcPolicyStateReducer};
use Weline\Framework\Runtime\{RuntimeProviderResolution,RuntimeEdgeCacheInvalidatorInterface};
define('BP',dirname(__DIR__,6));
foreach(['ScopeIdentity','ScopeEnvelope'] as $c){require BP.'/app/code/Weline/Framework/Runtime/'.$c.'.php';}
foreach(['FpcPolicyStateReducer','FpcPolicyRulePlanner','FpcPolicySyncService'] as $c){require BP.'/app/code/Weline/Cdn/Service/'.$c.'.php';}
$edge=new class implements RuntimeEdgeCacheInvalidatorInterface {
 public array $calls=[];public bool $fail=false;public bool $disabled=false;public bool $badIdentity=false;public bool $badHosts=false;
 public function invalidateHosts(array $hosts,string $id):array {
  $this->calls[]=['hosts'=>$hosts,'id'=>$id];$GLOBALS['order'][]='edge';
  return ['success'=>!$this->fail,'completed'=>!$this->fail,'applicable'=>!$this->disabled,'operation_id'=>$this->badIdentity?'wrong-id':$id,'backend'=>'managed_nginx','granularity'=>'host','hosts'=>$this->badHosts?['outside.example.invalid']:$hosts,'generation_by_host'=>array_fill_keys($hosts,2),'reason'=>$this->disabled?'cache_disabled':'invalidated','error_code'=>$this->fail?'config_publish_failed':'','message'=>$this->fail?'fixture publish refused':''];
 }
};
$GLOBALS['edgeResolution']=new RuntimeProviderResolution('available',$edge);
$adapter=new class {
 public bool $fail=false;public int $purgeCalls=0;public int $failAt=0;public mixed $onPurge=null;
 public function getRules(string $z,array $c):array{return [];}
 public function putRules(string $z,array $r,array $c):array{$GLOBALS['order'][]='rules';return ['success'=>true];}
 public function purgeUrls(string $z,array $v,array $c):array{return $this->purge();}
 public function purgePrefixes(string $z,array $v,array $c):array{return $this->purge();}
 public function purgeHosts(string $z,array $v,array $c):array{return $this->purge();}
 private function purge():array {++$this->purgeCalls;$GLOBALS['order'][]='cloud_purge';if($this->onPurge){($this->onPurge)();$this->onPurge=null;}return ['success'=>!$this->fail&&$this->purgeCalls!==$this->failAt,'message'=>'fixture cloud refused','purge_ids'=>['fixture']];}
};
$domain=new Domain(['domain_id'=>1,'domain_name'=>'a.example.invalid','adapter'=>'cloudflare','zone_id'=>'z','enabled'=>1]);
$states=new FpcPolicyStateService();$sync=new FpcPolicySyncService($states,new FpcPolicyPublishService(),new FpcPolicyTargetService([$domain]),new RuleManager(),new AdapterResolver($adapter),new AccountManager(),new FpcPolicyHttpVerificationService());
$key=$sync->binding($domain)['job_key'];$groups=$sync->groups();
$targets=[['domain_id'=>1,'kind'=>'url','value'=>'https://a.example.invalid/currency'],['domain_id'=>1,'kind'=>'prefix','value'=>'www.a.example.invalid/shop/'],['domain_id'=>1,'kind'=>'host','value'=>'a.example.invalid']];
$reset=function()use($states,$sync,$groups,$targets,$edge,$adapter):void{$states->state=['next_sync_id'=>1,'desired_version'=>2,'origin_revision'=>'fixture','jobs'=>[]];$sync->stage($states->state,$groups,['purge_targets'=>$targets]);$edge->calls=[];$edge->fail=false;$edge->disabled=false;$edge->badIdentity=false;$edge->badHosts=false;$adapter->fail=false;$adapter->failAt=0;$adapter->purgeCalls=0;$adapter->onPurge=null;$GLOBALS['order']=[];$GLOBALS['edgeResolution']=new RuntimeProviderResolution('available',$edge);};
$checks=0;$assert=function(bool $ok,string $why)use(&$checks):void{++$checks;if(!$ok){throw new RuntimeException($why);}};
$expectFail=function(string $contains)use($sync,$key,$assert):void{try{$sync->run($key);throw new RuntimeException('Expected sync failure');}catch(RuntimeException $e){$assert(str_contains($e->getMessage(),$contains),'wrong failure: '.$e->getMessage());}};
try {
 $reset();$sync->run($key);
 $assert(count($edge->calls)===1,'local edge invalidator must run before Cloudflare purge');
 $assert($edge->calls[0]['hosts']===['a.example.invalid','www.a.example.invalid'],'url/prefix/host must normalize to unique sorted Scope hosts');
 $assert(array_search('origin',$GLOBALS['order'])<array_search('rules',$GLOBALS['order'])&&array_search('rules',$GLOBALS['order'])<array_search('edge',$GLOBALS['order'])&&array_search('edge',$GLOBALS['order'])<array_search('cloud_purge',$GLOBALS['order']),'stage order must be rules→edge→cloud purge');
 $assert($states->state['jobs'][$key]['pending_targets']===[],'both success must acknowledge');
 $reset();$edge->fail=true;$expectFail('config_publish_failed');
 $assert($adapter->purgeCalls===0&&count($states->state['jobs'][$key]['pending_targets'])===3&&$states->state['jobs'][$key]['purge_version']===0,'local failure must retain all pending targets and version');
 $assert(($states->state['jobs'][$key]['local_edge_receipt']['error_code']??'')==='config_publish_failed','persist concrete local error receipt');
 $edge->fail=false;$sync->run($key);$assert(count($edge->calls)===2&&$edge->calls[0]['id']===$edge->calls[1]['id'],'local failure retry operation id must remain stable');
 $reset();$adapter->fail=true;$expectFail('fixture cloud refused');$id=$edge->calls[0]['id'];$adapter->fail=false;$sync->run($key);
 $assert(count($edge->calls)===1,'cloud failure must reuse completed local receipt without another generation');
 $reset();$large=[];for($i=0;$i<101;++$i){$large[]=['domain_id'=>1,'kind'=>'url','value'=>'https://'.($i===100?'www.':'').'a.example.invalid/currency/'.$i];}
 $states->state['jobs'][$key]['pending_targets']=FpcPolicyStateReducer::accumulateTargets([],$large,2);$adapter->failAt=2;$expectFail('fixture cloud refused');
 $assert(count($states->state['jobs'][$key]['pending_targets'])===1,'first successful batch may ack only itself');$adapter->failAt=0;$sync->run($key);
 $assert(count($edge->calls)===1&&$states->state['jobs'][$key]['pending_targets']===[],'remaining host subset must reuse original local receipt after partial cloud success');
 $reset();$edge->badIdentity=true;$expectFail('invalid_provider_receipt');$assert($adapter->purgeCalls===0,'mismatched local receipt must never admit cloud purge');
 $reset();$edge->badHosts=true;$expectFail('invalid_provider_receipt');$assert($adapter->purgeCalls===0,'mismatched local host identity must never admit cloud purge');
 $reset();$adapter->onPurge=function()use($states,$key,$targets){$states->state['desired_version']=3;$states->state['jobs'][$key]['desired_version']=3;$states->state['jobs'][$key]['pending_targets']=FpcPolicyStateReducer::accumulateTargets($states->state['jobs'][$key]['pending_targets'],[$targets[0]],3);};$sync->run($key);
 $assert(count($states->state['jobs'][$key]['pending_targets'])===1&&$states->state['jobs'][$key]['purge_version']===2,'in-flight later version cannot be acknowledged');
 $sync->run($key);$assert(count($edge->calls)===2&&$edge->calls[0]['id']!==$edge->calls[1]['id'],'new desired needs new local generation operation');
 $reset();$GLOBALS['edgeResolution']=new RuntimeProviderResolution('not_configured');$sync->run($key);
 $assert(($states->state['jobs'][$key]['local_edge_receipt']['reason']??'')==='not_configured'&&count($edge->calls)===0,'absent optional provider must be explicit completed skip');
 $reset();$edge->disabled=true;$sync->run($key);$assert(($states->state['jobs'][$key]['local_edge_receipt']['reason']??'')==='cache_disabled','disabled provider must preserve explicit skip');
 $reset();$GLOBALS['edgeResolution']=new RuntimeProviderResolution('configured_unavailable',null,'provider_construction_failed','fixture unavailable');$expectFail('provider_construction_failed');
 $assert($adapter->purgeCalls===0&&count($states->state['jobs'][$key]['pending_targets'])===3,'broken configured provider must not skip');
 echo "PASS {$checks} actual Sync local-edge ordering/failure/retry/concurrency/skip checks; no remote writes.\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage()."\n");exit(1);}
}
