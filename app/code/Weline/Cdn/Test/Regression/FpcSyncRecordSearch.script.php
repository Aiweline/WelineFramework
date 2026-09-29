<?php
declare(strict_types=1);
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Framework\Manager\ObjectManager;
use Weline\Cdn\Service\FpcPolicyManagementService;
try {
    $service=ObjectManager::getInstance(FpcPolicyManagementService::class);
    $all=$service->listSyncRecords(['page_size'=>100]);
    if (!($all['success']??false)) { throw new RuntimeException('同步记录无法读取'); }
    $expected=array_values(array_filter($all['data']['items'],static fn($row)=>strtolower((string)$row['adapter'])==='cloudflare'));
    if ($expected===[]) { throw new RuntimeException('受控环境没有Cloudflare同步记录'); }
    $search=$service->listSyncRecords(['keyword'=>'CLOUDFLARE','page_size'=>1,'page'=>1]);
    if ($search['data']['total']!==count($expected)||count($search['data']['items'])!==1||$search['data']['items'][0]['adapter']!=='cloudflare') {
        throw new RuntimeException('FAIL 关键词应先匹配adapter再计算total和分页');
    }
    $empty=$service->listSyncRecords(['keyword'=>'__not_a_real_sync_record__']);
    if ($empty['data']['total']!==0||$empty['data']['items']!==[]) { throw new RuntimeException('FAIL 无匹配关键词未返回空集合'); }
    $last=$service->listSyncRecords(['keyword'=>'cloudflare','page_size'=>1,'page'=>count($expected)+1]);
    if ($last['data']['items']!==[]||$last['data']['total']!==count($expected)) { throw new RuntimeException('FAIL 筛选后分页不正确'); }
    $domainName=$expected[0]['domain_names'][0]??'';
    if ($domainName!=='') {
        $byDomain=$service->listSyncRecords(['keyword'=>$domainName]);
        if (!in_array($expected[0]['sync_id'],array_column($byDomain['data']['items'],'sync_id'),true)) { throw new RuntimeException('FAIL 域名关键词未命中所属记录'); }
    }
    $snapshot=(new \Weline\Cdn\Service\CompiledFpcPolicySnapshotProvider())->snapshot();
    $policies=$service->listPolicies(['page_size'=>1]);
    foreach ([$policies['data'],$search['data'],$policies['data']['items'][0],$search['data']['items'][0]] as $data) {
        if ($data['snapshot_version']!==$snapshot['source_version']||$data['snapshot_revision']!==$snapshot['revision']) { throw new RuntimeException('FAIL API快照版本并非实际已发布文件证据'); }
    }
    echo "PASS 同步记录关键词大小写/域名匹配、先筛选后分页、无匹配空集合、实际快照版本证据\n";
} catch(Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");exit(1); }
