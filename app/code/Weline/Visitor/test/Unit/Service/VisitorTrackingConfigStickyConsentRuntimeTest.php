<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\VisitorTrackingConfig;

/**
 * A08a：runtimeConfig 注入 consent/sticky 开关（默认值可读，不改 linker）。
 */
class VisitorTrackingConfigStickyConsentRuntimeTest extends TestCore
{
    public function testRuntimeConfigExposesConsentAndStickySwitches(): void
    {
        /** @var VisitorTrackingConfig $config */
        $config = ObjectManager::getInstance(VisitorTrackingConfig::class);
        $runtime = $config->getRuntimeConfig();

        self::assertArrayHasKey('consent', $runtime);
        self::assertArrayHasKey('enabled', $runtime['consent']);
        self::assertArrayHasKey('marketingStorageKey', $runtime['consent']);
        self::assertSame('ad_storage', $runtime['consent']['marketingStorageKey']);

        self::assertArrayHasKey('sticky', $runtime);
        self::assertArrayHasKey('ttlHours', $runtime['sticky']);
        self::assertArrayHasKey('linkerEnabled', $runtime['sticky']);
        self::assertArrayHasKey('formMergeEnabled', $runtime['sticky']);
        self::assertSame(24, (int)$runtime['sticky']['ttlHours']);
        self::assertTrue((bool)$runtime['sticky']['linkerEnabled']);
        self::assertFalse((bool)$runtime['sticky']['formMergeEnabled']);
    }

    public function testBodyEndFallbackDefaultsIncludeSticky(): void
    {
        $source = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );
        self::assertStringContainsString("'sticky' => [", $source);
        self::assertStringContainsString("'ttlHours' => 24", $source);
        self::assertStringContainsString("'linkerEnabled' => true", $source);
        self::assertStringContainsString("'formMergeEnabled' => false", $source);
        self::assertStringContainsString("'marketingStorageKey' => 'ad_storage'", $source);
        self::assertStringContainsString('__WelineVisitorTrackingConfig', $source);
    }

    public function testBootstrapServiceInjectsFullRuntimeConfig(): void
    {
        $source = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelBootstrapHtmlService.php'
        );
        self::assertStringContainsString('getRuntimeConfig()', $source);
        self::assertStringContainsString('__WelineVisitorTrackingConfig', $source);
    }

    public function testJsAlreadyReadsStickyAndConsentSections(): void
    {
        $js = (string)\file_get_contents(BP . '/app/code/Weline/Visitor/view/statics/js/pixel.js');
        self::assertStringContainsString("__visitorConfigSection('sticky')", $js);
        self::assertStringContainsString("__visitorConfigSection('consent')", $js);
        // A08a 时不改 linker；A09 起 linker 合法存在且须受营销同意门闩约束
        self::assertStringContainsString('__stickyLinkerEnabled', $js);
    }
}
