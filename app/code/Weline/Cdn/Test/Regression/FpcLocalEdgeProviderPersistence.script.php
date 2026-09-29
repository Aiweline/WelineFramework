<?php
declare(strict_types=1);
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Cdn\Service\{FpcPolicySyncService,FpcPolicyStateService,FpcPolicyPublishService,FpcPolicyTargetService,RuleManager,AdapterResolver,AccountManager,FpcPolicyHttpVerificationService};
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Model\Domain;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\{RuntimeProviderResolver,RuntimeProviderResolution,RuntimeEdgeCacheInvalidatorInterface};
// Real registered provider + persistent state + real Sync; isolate cloud/HTTP transport only.
// Existing local env must be not_managed. Never edits gateway settings or runs a reload.
$key=null;$failed=false;
try {
    if(realpath(BP)==='/www/wwwroot/changanhanfu.com'){throw new RuntimeException('Local-only test refuses production');}
    $om=ObjectManager::getInstance();$states=$om->getInstance(FpcPolicyStateService::class);$targets=$om->getInstance(FpcPolicyTargetService::class);
    $resolution=$om->getInstance(RuntimeProviderResolver::class)->resolveDetailed(RuntimeEdgeCacheInvalidatorInterface::class);
    if($resolution->status!==RuntimeProviderResolution::AVAILABLE){throw new RuntimeException('Real provider unavailable: '.$resolution->errorCode);}
    $env=\Weline\Framework\App\Env::getInstance()->getConfig();
    if(($env['wls']['edge']['nginx']['managed']??false)!==false){throw new RuntimeException('Fixture requires existing unmanaged local gateway');}
    $domain=$targets->domains()[0]??throw new RuntimeException('No actual enabled local Domain');
    $before=$states->read();$declarations=[];
    foreach($before['declarations'] as $entry){if(($entry['active']??false)&&($entry['declaration']['class']??'')==='Weline\\Currency\\Controller\\Frontend\\Index'){$declarations[]=$entry['declaration'];}}
    if($declarations===[]){throw new RuntimeException('Actual currency declaration unavailable');}
    $purgeTargets=$targets->targets($declarations,null,[$domain]);
    if($purgeTargets===[]){throw new RuntimeException('No trusted actual public targets');}
    $adapter=new class extends Cloudflare {
        public array $calls=[];
        public function getRules(string $z,array $c):array{$this->calls[]='get_rules';return [];}
        public function putRules(string $z,array $r,array $c):array{$this->calls[]='put_rules';return ['success'=>true];}
        public function purgeUrls(string $z,array $v,array $c):array{$this->calls[]=['purge_urls'=>count($v)];return ['success'=>true,'purge_ids'=>['isolated-no-cloud-write']];}
        public function purgeHosts(string $z,array $v,array $c):array{$this->calls[]=['purge_hosts'=>count($v)];return ['success'=>true,'purge_ids'=>['isolated-no-cloud-write']];}
        public function purgePrefixes(string $z,array $v,array $c):array{$this->calls[]=['purge_prefixes'=>count($v)];return ['success'=>true,'purge_ids'=>['isolated-no-cloud-write']];}
    };
    $resolver=new class($adapter) extends AdapterResolver {public function __construct(private AdapterInterface $fixture){}public function getAdapter(string $code):?AdapterInterface{return $this->fixture;}};
    $rules=new class($om,$resolver) extends RuleManager {public function getCredentials(Domain $d):array{return ['fixture'=>'no-network-credential'];}};
    $http=new class extends FpcPolicyHttpVerificationService {public function __construct(){}public function verify(array $job,array $domains,array $snapshot):array{return ['coverage'=>'sample','requested_version'=>$job['desired_version'],'snapshot_revision'=>$snapshot['revision'],'verified'=>false,'status'=>'no_public_sample','samples'=>[],'reason'=>'isolated_http_transport'];}};
    $sync=new FpcPolicySyncService($states,$om->getInstance(FpcPolicyPublishService::class),$targets,$rules,$resolver,$om->getInstance(AccountManager::class),$http);
    $group=$sync->binding($domain);$key=hash('sha256','real-edge-local-fixture:'.bin2hex(random_bytes(12)));$group['job_key']=$key;$group['domain_ids']=[(int)$domain->getId()];
    $states->mutate(static function(array &$s)use($sync,$key,$group,$purgeTargets):void{$sync->stage($s,[$key=>$group],['trigger'=>'local_edge_provider_fixture','purge_targets'=>$purgeTargets]);});
    $sync->run($key);$job=$states->read()['jobs'][$key];$receipt=$job['local_edge_receipt']??[];
    if(($receipt['success']??null)!==true||($receipt['completed']??null)!==true||($receipt['applicable']??null)!==false||($receipt['reason']??'')!=='not_managed'){throw new RuntimeException('Real not_managed receipt did not persist');}
    if($job['status']!=='success'||$job['pending_targets']!==[]||$job['purge_version']!==$job['desired_version']||$job['verified_version']!==0){throw new RuntimeException('Dual-edge ack/HTTP evidence semantics incorrect');}
    if(count($job['purge_receipt']['targets']??[])!==count($purgeTargets)){throw new RuntimeException('Some trusted targets not acknowledged');}
    $reflection=new ReflectionClass(FpcPolicySyncService::class);
    echo json_encode(['result'=>'PASS','evidence'=>'real local PostgreSQL + registered Server provider; cloud/HTTP transport isolated','provider_status'=>$resolution->status,'provider'=>$resolution->implementation,'sync_file'=>$reflection->getFileName(),'sync_sha256'=>hash_file('sha256',$reflection->getFileName()),'domain_id'=>(int)$domain->getId(),'target_count'=>count($purgeTargets),'local_edge_receipt'=>$receipt,'cloud_fixture_calls'=>$adapter->calls,'status'=>$job['status'],'purge_version'=>$job['purge_version'],'verified_version'=>$job['verified_version'],'does_not_prove_managed_reload'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
} catch(Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage().PHP_EOL);$failed=true;
} finally {
    if($key!==null&&isset($states)){$states->mutate(static function(array &$s)use($key):void{unset($s['jobs'][$key]);});echo "FIXTURE_REMOVED\n";}
}
if($failed){exit(1);}
