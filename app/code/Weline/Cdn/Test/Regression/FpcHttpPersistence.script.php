<?php
declare(strict_types=1);
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Cdn\Service\{FpcPolicyHttpVerificationService,FpcPolicySyncService,FpcPolicyStateService,FpcPolicyPublishService,FpcPolicyTargetService,RuleManager,AdapterResolver,AccountManager};
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Model\Domain;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\Catalog\{WebsiteCatalogInterface,StoreCatalogInterface,SalesChannelCatalogInterface};
// 正式持久化 + 正式观察器 + 已配置 Domain 公共 URL 的匿名真实 HTTP。
// 只有云端规则传输使用替身；不写供应商，不使用任意 URL，临时测试 job 在 finally 移除。
$key=null;
try {
    $om=ObjectManager::getInstance();$states=$om->getInstance(FpcPolicyStateService::class);$targets=$om->getInstance(FpcPolicyTargetService::class);
    $domains=$targets->domains();$domain=$domains[0]??throw new RuntimeException('没有已启用的真实 Domain');
    $adapter=new class extends Cloudflare {
        public function getRules(string $zoneId,array $credentials):array{return [];}
        public function putRules(string $zoneId,array $rules,array $credentials):array{return ['success'=>true,'fixture_transport'=>true];}
    };
    $resolver=new class($adapter) extends AdapterResolver {public function __construct(private AdapterInterface $fixture){}public function getAdapter(string $code):?AdapterInterface{return $this->fixture;}};
    $rules=new class($om,$resolver) extends RuleManager {public function getCredentials(Domain $domain):array{return ['fixture'=>'no-cloud-transport'];}};
    $http=new FpcPolicyHttpVerificationService($targets,$om->getInstance(WebsiteCatalogInterface::class),$om->getInstance(StoreCatalogInterface::class),$om->getInstance(SalesChannelCatalogInterface::class));
    $sync=new FpcPolicySyncService($states,$om->getInstance(FpcPolicyPublishService::class),$targets,$rules,$resolver,$om->getInstance(AccountManager::class),$http);
    $group=$sync->binding($domain);$key=hash('sha256','http-observation-fixture:'.bin2hex(random_bytes(8)));$group['job_key']=$key;$group['domain_ids']=[(int)$domain->getId()];
    $states->mutate(static function(array &$state)use($sync,$group,$key):void{$sync->stage($state,[$key=>$group],['trigger'=>'http_observation_fixture']);});
    $sync->run($key);
    $job=$states->read()['jobs'][$key];$receipt=$job['http_verification'];
    if(!is_array($receipt)||empty($receipt['samples'][0]['observations'])||$job['status']!=='success'||$job['cloud_version']!==$job['desired_version']||$job['purge_version']!==$job['desired_version']){throw new RuntimeException('实际 HTTP 观察未持久回写或不应回滚的前三阶段发生回滚');}
    if(!$receipt['verified']&&$job['verified_version']!==0){throw new RuntimeException('未满足抽样不应晋级');}
    echo json_encode(['evidence'=>'actual configured public HTTP + PostgreSQL receipt; cloud transport is fixture','status'=>$job['status'],'verified_version'=>$job['verified_version'],'http_verification'=>$receipt],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage()."\n");$failed=true;
} finally {
    if($key!==null&&isset($states)){$states->mutate(static function(array &$state)use($key):void{unset($state['jobs'][$key]);});}
}
if($failed??false){exit(1);}
