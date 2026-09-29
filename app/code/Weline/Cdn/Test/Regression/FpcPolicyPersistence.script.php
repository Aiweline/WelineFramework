<?php
declare(strict_types=1);
namespace Weline\Cdn\Service {
    // 只隔离外部Queue副作用；被测管理服务、ORM、事务和独立读回均为真实实现。
    function w_query(string $provider,string $operation,array $params=[]): mixed {
        if ($provider==='queue' && $operation==='createIfAbsent') { return ['success'=>true,'created'=>true,'queue_id'=>0,'status'=>'pending']; }
        throw new \RuntimeException('集成测试禁止外部队列操作');
    }
}
namespace {
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Framework\Manager\ObjectManager;
use Weline\Cdn\Service\FpcPolicyManagementService;
use Weline\Cdn\Service\FpcPolicyStateService;
if (!class_exists(FpcPolicyManagementService::class)) { fwrite(STDERR,"FAIL 策略管理服务尚未实现\n"); exit(1); }
try {
$manager=ObjectManager::getInstance(FpcPolicyManagementService::class);
$collected=$manager->collectDeclarations();
if (!($collected['success']??false)) { throw new RuntimeException(json_encode($collected)); }
$list=$manager->listPolicies(['page_size'=>100]);
$rows=$list['data']['items']??[];
$row=current(array_filter($rows,static fn($r)=>$r['code_enabled']&&$r['code_ttl']>0));
if (!$row) { throw new RuntimeException('没有可回归的真实FPC声明'); }
$id=$row['declaration_id'];
$before=['enabled'=>$row['override_enabled'],'ttl'=>$row['override_ttl']];
try {
    foreach ([67,43,29] as $ttl) {
        $result=$manager->saveOverride(['declaration_id'=>$id,'ttl'=>$ttl]);
        if (!($result['success']??false)) { throw new RuntimeException(json_encode($result)); }
    }
    $fresh=ObjectManager::getInstance(FpcPolicyStateService::class)->read();
    $global=\Weline\Framework\Runtime\ScopeIdentity::global()->canonicalKey();
    if ($fresh['overrides']['normal'][$global][$id]['ttl']!==29) { throw new RuntimeException('最终TTL未持久化'); }
    $targets=0; foreach($fresh['jobs'] as $job){$targets+=count($job['pending_targets']);}
    if ($targets===0) { throw new RuntimeException('连续保存丢失待清理集合'); }
    $version=$fresh['desired_version'];
    $manager->collectDeclarations();
    $fresh=ObjectManager::getInstance(FpcPolicyStateService::class)->read();
    if ($fresh['overrides']['normal'][$global][$id]['ttl']!==29) { throw new RuntimeException('重收集覆盖人工TTL'); }
    $same=$manager->saveOverride(['declaration_id'=>$id,'ttl'=>29]);
    if ($same['data']['changed'] || $same['data']['desired_version']!==$version) { throw new RuntimeException('相同值新增了版本'); }
    echo "PASS 实际PostgreSQL连续保存、清理累积、收集保留覆盖、相同值幂等\n";
} finally {
    $manager->saveOverride(['declaration_id'=>$id]+$before);
}
} catch(Throwable $error) { fwrite(STDERR,"FAIL ".$error->getMessage()."\n"); exit(1); }
}
