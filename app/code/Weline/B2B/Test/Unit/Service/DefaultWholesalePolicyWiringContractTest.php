<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Default wholesale policy save guard + FPC observer wiring.
 */
final class DefaultWholesalePolicyWiringContractTest extends TestCase
{
    public function testPluginAndObserverWired(): void
    {
        $root = dirname(__DIR__, 3);
        $pluginXml = (string) file_get_contents($root . '/etc/plugin.xml');
        $eventXml = (string) file_get_contents($root . '/etc/event.xml');
        $pluginSrc = (string) file_get_contents($root . '/Plugin/SystemConfigCenterSaveGuard.php');
        $observerSrc = (string) file_get_contents($root . '/Observer/DefaultWholesalePolicyConfigResourceChanged.php');

        self::assertStringContainsString('SystemConfigCenterService', $pluginXml);
        self::assertStringContainsString('SystemConfigCenterSaveGuard', $pluginXml);
        self::assertStringContainsString('beforeSaveTemplateConfig', $pluginSrc);
        self::assertStringContainsString('assertAndNormalizeForSave', $pluginSrc);

        self::assertStringContainsString('Weline_Framework::resource_changed', $eventXml);
        self::assertStringContainsString('DefaultWholesalePolicyConfigResourceChanged', $eventXml);
        self::assertStringContainsString('ProductStorefrontCacheInvalidator', $observerSrc);
        self::assertStringContainsString('b2b_default_wholesale_policy', $observerSrc);
    }
}
