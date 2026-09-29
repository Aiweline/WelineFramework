<?php
declare(strict_types=1);
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Cdn\Service\{FpcPolicySyncService,FpcPolicyStateService,FpcPolicyPublishService,FpcPolicyTargetService,RuleManager,AdapterResolver,AccountManager};
use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Framework\Manager\ObjectManager;
try {
    $om=ObjectManager::getInstance();$states=$om->getInstance(FpcPolicyStateService::class);
    $jobs=$states->read()['jobs'];$job=current(array_filter($jobs,static fn($j)=>$j['job_key']!=='origin'));
    if(!$job){throw new RuntimeException('没有可用于受控失败回归的真实持久job');}
    // 仅云端传输替身：真实同步器必须把边界失败写为失败，而不能晋级回执。
    $adapter=new class extends Cloudflare {
        public function getRules(string $zoneId,array $credentials):array { throw new RuntimeException('fixture_transport_unavailable'); }
    };
    $resolver=new class($adapter) extends AdapterResolver {
        public function __construct(private readonly AdapterInterface $fixture) {}
        public function getAdapter(string $adapterCode):?AdapterInterface{return $this->fixture;}
    };
    $rules=new class($om,$resolver) extends RuleManager {
        public function getCredentials(\Weline\Cdn\Model\Domain $domain):array{return ['api_token'=>'isolated-fixture-not-a-credential'];}
    };
    $sync=new FpcPolicySyncService($states,$om->getInstance(FpcPolicyPublishService::class),$om->getInstance(FpcPolicyTargetService::class),$rules,$resolver,$om->getInstance(AccountManager::class),$om->getInstance(\Weline\Cdn\Service\FpcPolicyHttpVerificationService::class));
    try{
        try{$sync->run($job['job_key']);throw new RuntimeException('云端失败未抛出');}
        catch(RuntimeException $error){if($error->getMessage()!=='fixture_transport_unavailable'){throw $error;}}
        $after=$states->read()['jobs'][$job['job_key']];
        if($after['status']!=='error'||!str_starts_with($after['last_error'],'cloud:')||$after['cloud_version']!==$job['cloud_version']||$after['purge_version']!==$job['purge_version']||$after['pending_targets']!==$job['pending_targets']||$after['http_verification']!==null){throw new RuntimeException('失败晋级回执或清理集合丢失');}
        echo "PASS 真实同步Service边界失败保留待清理集合/版本，未晋级cloud/purge/http\n";
    }finally{
        $states->mutate(static function(array &$state)use($job):void{
            $current=$state['jobs'][$job['job_key']];
            if($current['desired_version']===$job['desired_version']&&$current['pending_targets']===$job['pending_targets']){$state['jobs'][$job['job_key']]=$job;}
        });
    }
}catch(Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage()."\n");exit(1);}
