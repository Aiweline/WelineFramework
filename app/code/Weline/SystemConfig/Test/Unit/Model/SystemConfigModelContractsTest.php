<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Model;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllSystemConfigModelContractsPass().
 */
final class SystemConfigModelContractsTest extends TestCase
{
    public function testAllSystemConfigModelContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'SystemConfigCachePoolBinding::testInitAlwaysRebindsDedicatedSystemConfigPool' => function () {
            $this->assert_SystemConfigCachePoolBinding_testInitAlwaysRebindsDedicatedSystemConfigPool();
        },
            'SystemConfigCachePoolBinding::testInvalidationBuildsDualPoolCacheOpsForFrameworkObserver' => function () {
            $this->assert_SystemConfigCachePoolBinding_testInvalidationBuildsDualPoolCacheOpsForFrameworkObserver();
        },
            'SystemConfigNoopSave::testSaveScopeConfigSkipsUnchangedWrites' => function () {
            $this->assert_SystemConfigNoopSave_testSaveScopeConfigSkipsUnchangedWrites();
        },
            'SystemConfigNoopSave::testControllerWarnsOnNoop' => function () {
            $this->assert_SystemConfigNoopSave_testControllerWarnsOnNoop();
        },
            'SystemConfigNoopSave::testEmbedHandlesNoopStatus' => function () {
            $this->assert_SystemConfigNoopSave_testEmbedHandlesNoopStatus();
        },
            'SystemConfigProcessCache::testModelExposesClearProcessCacheAndKeyedProcessBucket' => function () {
            $this->assert_SystemConfigProcessCache_testModelExposesClearProcessCacheAndKeyedProcessBucket();
        },
            'SystemConfigProcessCache::testInvalidationClearsProcessCacheHelper' => function () {
            $this->assert_SystemConfigProcessCache_testInvalidationClearsProcessCacheHelper();
        },
            'SystemConfigProcessCache::testWarmupModuleMapsApiExistsForCronProcessEntry' => function () {
            $this->assert_SystemConfigProcessCache_testWarmupModuleMapsApiExistsForCronProcessEntry();
        },
        ];
    }

private function assert_SystemConfigCachePoolBinding_testInitAlwaysRebindsDedicatedSystemConfigPool(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Model/SystemConfig.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString("\$this->_cache = w_cache('system_config');", $source);
    self::assertStringNotContainsString('if (!isset($this->_cache))', $source);
    self::assertStringContainsString('parent::__init() sets AbstractModel::_cache', $source);
}

private function assert_SystemConfigCachePoolBinding_testInvalidationBuildsDualPoolCacheOpsForFrameworkObserver(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Service/ConfigCacheInvalidationService.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('function buildCacheOps', $source);
    self::assertStringContainsString("'pool' => 'system_config'", $source);
    self::assertStringContainsString("'pool' => 'database'", $source);
    self::assertStringContainsString('Shared-pool deletes moved to impact.cache_ops', $source);
}

private function assert_SystemConfigNoopSave_testSaveScopeConfigSkipsUnchangedWrites(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Model/SystemConfig.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('isUnchangedConfigWrite', $source);
    self::assertStringContainsString("'status' => 'noop'", $source);
    self::assertStringContainsString('没有检测到配置变更，未创建新版本。', $source);
    self::assertStringContainsString('Already inheriting (no override row)', $source);
}

private function assert_SystemConfigNoopSave_testControllerWarnsOnNoop(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Controller/Backend/Config.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("=== 'noop'", $source);
    self::assertStringContainsString('addWarning', $source);
}

private function assert_SystemConfigNoopSave_testEmbedHandlesNoopStatus(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/statics/js/config-embed.js';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("data.status === 'noop'", $source);
}

private function assert_SystemConfigProcessCache_testModelExposesClearProcessCacheAndKeyedProcessBucket(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Model/SystemConfig.php');
    self::assertStringContainsString('function clearProcessCache', $src);
    self::assertStringContainsString('self::$configs[$area][$module][$processKey]', $src);
}

private function assert_SystemConfigProcessCache_testInvalidationClearsProcessCacheHelper(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Service/ConfigCacheInvalidationService.php');
    self::assertStringContainsString('SystemConfig::clearProcessCache($area, $module)', $src);
}

private function assert_SystemConfigProcessCache_testWarmupModuleMapsApiExistsForCronProcessEntry(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Model/SystemConfig.php');
    self::assertStringContainsString('function warmupModuleMaps', $src);
    self::assertStringContainsString('getConfigMapByModule($module, $area, $scope, $locale)', $src);
    self::assertTrue(method_exists(\Weline\SystemConfig\Model\SystemConfig::class, 'warmupModuleMaps'));
}
}
