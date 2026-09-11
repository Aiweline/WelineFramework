<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Source contract: shared-pool delete moved to Framework cache_ops Observer. */
final class ConfigCacheInvalidationEmitOnlyContractTest extends TestCase
{
    public function testInvalidationServiceBuildsCacheOpsAndDoesNotDeleteSharedPoolsInline(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ConfigCacheInvalidationService.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('function buildCacheOps', $source);
        self::assertStringContainsString("'pool' => 'system_config'", $source);
        self::assertStringContainsString("'pool' => 'database'", $source);
        self::assertStringContainsString('Shared-pool deletes moved to impact.cache_ops', $source);
        self::assertStringNotContainsString("\$cache->delete(\$singleKey)", $source);
        self::assertStringNotContainsString("\$cache->delete(\$moduleKey)", $source);
    }

    public function testPublisherAcceptsCacheOpsAndFieldNamespaces(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/SystemConfigResourceChangePublisher.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('array $cacheOps = []', $source);
        self::assertStringContainsString('cache-namespaces', $source);
        self::assertStringContainsString('function resolveNamespaces', $source);
        self::assertStringContainsString("\$impact['cache_ops'] = \$cacheOps", $source);
    }

    public function testBuildCacheOpsDecoratesPreBumpFingerprintedKeys(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ConfigCacheInvalidationService.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('withPreBumpFingerprintedKeys', $source);
        self::assertStringContainsString('NamespaceKeyDecorator', $source);
        self::assertStringContainsString('array $namespacePaths = []', $source);
    }

    public function testConfigCenterFormCollectsControlCacheNamespaces(): void
    {
        $js = dirname(__DIR__, 3) . '/view/statics/js/system-config-filter.js';
        $source = (string)file_get_contents($js);
        self::assertStringContainsString("name = 'cache_namespaces[]'", $source);
        self::assertStringContainsString('data-cache-namespaces', $source);
    }
}
