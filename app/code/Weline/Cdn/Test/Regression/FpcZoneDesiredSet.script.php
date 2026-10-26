<?php
declare(strict_types=1);
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\FpcPolicySyncService;
use Weline\Cdn\Service\FpcPolicyRulePlanner;
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Framework\Manager\ObjectManager;
try {
    $sync=ObjectManager::getInstance(FpcPolicySyncService::class);
    $method=new ReflectionMethod($sync,'desiredRules');
    $a=(new Domain())->setData(['domain_id'=>800001,'domain_name'=>'a.example.invalid','adapter'=>'cloudflare','zone_id'=>'fixture-zone','inherit_default'=>0]);
    $b=(new Domain())->setData(['domain_id'=>800002,'domain_name'=>'b.example.invalid','adapter'=>'cloudflare','zone_id'=>'fixture-zone','inherit_default'=>0]);
    $adapter=ObjectManager::getInstance(Cloudflare::class);
    $desired=$method->invoke($sync,[$a,$b],$adapter);
    // defaults(7) + 穿章 Bypass(有 secret 时 1 条)；两域同 zone 必须各合并成一条。
    $expected=7+(''!==\Weline\Framework\Http\Fpc\FpcPierceStamp::readSecret()?1:0);
    if(count($desired)!==$expected){throw new RuntimeException('两域相同规则没有合并为 '.$expected.' 条，实际 '.count($desired));}
    foreach($desired as $rule){if(!str_contains($rule['expression'],'a.example.invalid')||!str_contains($rule['expression'],'b.example.invalid')){throw new RuntimeException('同zone第二域从desired丢失');}}
    $native=$adapter->formatRulesForApi([['id'=>'id1','ref'=>'manual_ref','action'=>'set_cache_settings','expression'=>'true','action_parameters'=>['cache'=>false],'enabled'=>false]]);
    if($native[0]['id']!=='id1'||$native[0]['ref']!=='manual_ref'||$native[0]['enabled']!==false){throw new RuntimeException('完整规则往返丢id/ref/enabled');}
    $merged=FpcPolicyRulePlanner::merge([],$desired);
    $again=FpcPolicyRulePlanner::merge($merged,$desired);
    if(!FpcPolicyRulePlanner::equivalent($merged,$again)){throw new RuntimeException('重复desired不是无变化');}
    echo "PASS 两域Zone完整合并为{$expected}条、ID/ref原生格式保留、无变更幂等\n";
} catch(Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage()."\n");exit(1);}
