<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * 统一配置中心地址栏必须跟随当前 Scope，避免复制链接改错层。
 */
final class ConfigCenterShareUrlContractTest extends TestCase
{
    public function testIndexCanonicalizesShareQueryWithTargetScope(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/Controller/Backend/Config.php');
        self::assertStringContainsString('buildConfigCenterShareQuery', $src);
        self::assertStringContainsString('configCenterShareQueryNeedsSync', $src);
        self::assertStringContainsString('configCenterScopeInputFromGet', $src);
        self::assertStringContainsString("'target_scope' => \$storageScope", $src);
        self::assertStringContainsString('weline_systemconfig/backend/config', $src);

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/backend/config/index.phtml'
        );
        self::assertStringContainsString('id="wsc-filter-target-scope"', $tpl);
        self::assertStringContainsString('name="target_scope"', $tpl);
        self::assertStringContainsString('w-system-config__adapter-callback', $tpl);
        self::assertStringContainsString('system-config-adapter-callback-url', $tpl);
        self::assertStringContainsString('adapterCallbackUrl', $tpl);
    }
}
