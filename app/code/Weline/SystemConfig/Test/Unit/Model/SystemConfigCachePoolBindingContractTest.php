<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

final class SystemConfigCachePoolBindingContractTest extends TestCase
{
    public function testInitAlwaysRebindsDedicatedSystemConfigPool(): void
    {
        $path = \dirname(__DIR__, 3) . '/Model/SystemConfig.php';
        self::assertFileExists($path);
        $source = (string) \file_get_contents($path);
        self::assertStringContainsString("\$this->_cache = w_cache('system_config');", $source);
        self::assertStringNotContainsString('if (!isset($this->_cache))', $source);
        self::assertStringContainsString('parent::__init() sets AbstractModel::_cache', $source);
    }

    public function testInvalidationBuildsDualPoolCacheOpsForFrameworkObserver(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/ConfigCacheInvalidationService.php';
        self::assertFileExists($path);
        $source = (string) \file_get_contents($path);
        self::assertStringContainsString('function buildCacheOps', $source);
        self::assertStringContainsString("'pool' => 'system_config'", $source);
        self::assertStringContainsString("'pool' => 'database'", $source);
        self::assertStringContainsString('Shared-pool deletes moved to impact.cache_ops', $source);
    }
}