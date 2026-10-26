<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\FpcPolicyRulePlanner;
use Weline\Cdn\Service\FpcPolicySyncService;

/**
 * 云端规则去重与容量闸门：
 * - 旧版本推的规则没有 weline_cdn_ 前缀，但内容相同（仅 host 守卫差异）时必须被认领，不能再追加一份；
 * - 合并结果超过套餐容量（CF Free=10）时必须截断，不能 PUT 出 14 条再吃 HTTP 400/50001。
 */
final class FpcPolicyRulePlannerLegacyAdoptTest extends TestCase
{
    public function testLegacyUnprefixedRuleIsAdoptedInsteadOfDuplicated(): void
    {
        $legacy = [
            'id' => 'cf-rule-1',
            'ref' => 'cf-rule-1',
            'description' => '静态/媒体',
            'expression' => 'starts_with(http.request.uri.path, "/static/")',
            'action' => 'set_cache_settings',
            'action_parameters' => ['cache' => true],
            'enabled' => true,
        ];
        $desiredRule = [
            'ref' => 'weline_cdn_' . str_repeat('a', 40),
            'description' => '静态/媒体',
            'expression' => '(http.host in {"example.com"}) and (starts_with(http.request.uri.path, "/static/"))',
            'action' => 'set_cache_settings',
            'action_parameters' => ['cache' => true],
            'enabled' => true,
        ];
        $desiredRule['_legacy_rule'] = [
            'description' => '静态/媒体',
            'expression' => 'starts_with(http.request.uri.path, "/static/")',
            'action' => 'set_cache_settings',
            'action_parameters' => ['cache' => true],
            'enabled' => true,
        ];

        $plan = FpcPolicyRulePlanner::plan([$legacy], [$desiredRule]);

        self::assertCount(1, $plan['rules'], 'same content must replace, not append');
        self::assertSame('cf-rule-1', (string)($plan['rules'][0]['id'] ?? ''), 'legacy rule id should be kept');
        self::assertArrayHasKey($desiredRule['ref'], $plan['bindings'], 'legacy rule should be bound to desired logical ref');
    }

    public function testMergedRulesAreCappedToPlanCapacity(): void
    {
        $desired = [];
        for ($i = 0; $i < 10; $i++) {
            $desired[] = ['ref' => 'weline_cdn_' . str_pad((string)$i, 40, '0', STR_PAD_LEFT), 'expression' => 'true', 'action' => 'set_cache_settings', 'action_parameters' => ['cache' => false], 'enabled' => true];
        }
        $merged = $desired;
        for ($i = 0; $i < 4; $i++) {
            $merged[] = ['ref' => 'manual-' . $i, 'expression' => 'true', 'action' => 'set_cache_settings', 'action_parameters' => ['cache' => true], 'enabled' => true];
        }

        $service = (new \ReflectionClass(FpcPolicySyncService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(FpcPolicySyncService::class, 'capRules');
        $method->setAccessible(true);
        $capped = $method->invoke($service, $merged, $desired, 10);

        self::assertCount(10, $capped);
        $refs = array_column($capped, 'ref');
        foreach ($desired as $rule) {
            self::assertContains($rule['ref'], $refs, 'desired rules must survive capping');
        }
    }

    public function testCapRejectsDesiredOverCapacity(): void
    {
        $desired = [];
        for ($i = 0; $i < 3; $i++) {
            $desired[] = ['ref' => 'weline_cdn_' . $i, 'expression' => 'true', 'action' => 'set_cache_settings', 'action_parameters' => ['cache' => false], 'enabled' => true];
        }
        $service = (new \ReflectionClass(FpcPolicySyncService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(FpcPolicySyncService::class, 'capRules');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cdn_fpc_rules_capacity_exceeded');
        $method->invoke($service, $desired, $desired, 2);
    }
}
