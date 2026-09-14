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
        self::assertStringContainsString("return \$this->redirect('weline_systemconfig/backend/config'", $src);
        self::assertStringNotContainsString(
            "return \$this->redirect(\n                \$this->request->getUrlBuilder()->getBackendUrl(",
            $src,
        );

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/backend/config/index.phtml'
        );
        self::assertStringContainsString('id="wsc-filter-target-scope"', $tpl);
        self::assertStringContainsString('name="target_scope"', $tpl);
        self::assertStringContainsString('wscOnWebsiteFilterChange', $tpl);
        self::assertStringContainsString('wsc-website-code_value', $tpl);
        self::assertStringContainsString('default.default.default', $tpl);
        self::assertStringContainsString('w-system-config__adapter-callback', $tpl);
        self::assertStringContainsString('system-config-adapter-callback-url', $tpl);
        self::assertStringContainsString('adapterCallbackUrl', $tpl);
        self::assertStringContainsString('withoutStorefrontLocalizationPrefix', $tpl);
        self::assertStringContainsString('callback-append-scope', $tpl);
        self::assertStringContainsString('$appendScope', $tpl);
        self::assertStringContainsString('callback-as-origin', $tpl);
        self::assertStringContainsString('callback-as-host', $tpl);
        self::assertStringContainsString('$asOrigin', $tpl);

        self::assertStringContainsString('SCOPE_GLOBAL', $src);
        self::assertStringContainsString('显式 Global storage scope', $src);
    }
}
