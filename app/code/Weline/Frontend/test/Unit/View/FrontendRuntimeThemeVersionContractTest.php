<?php
declare(strict_types=1);

namespace Weline\Frontend\test\Unit\View;

use Weline\Framework\Test\TestCore;

class FrontendRuntimeThemeVersionContractTest extends TestCore
{
    public function testHeaderRuntimeInjectsThemePublishedVersionFields(): void
    {
        $path = dirname(__DIR__, 3) . '/view/blocks/header/base.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('themePublishedVersionId', $src);
        self::assertStringContainsString('themePublishedVersion', $src);
        self::assertStringContainsString('deployVersion', $src);
        self::assertStringContainsString('ThemePublishedVersionRuntimeResolver', $src);
        self::assertStringContainsString('weline-api.js', $src);
        self::assertStringContainsString('account-session.js', $src);
        self::assertStringContainsString('Customer/view/statics/js/account-session.js', $src);
        self::assertStringContainsString('weline.js', $src);
        self::assertStringContainsString('welineJsMtime', $src);
        self::assertStringContainsString('welineJsSrc', $src);
        self::assertStringContainsString('mini-cart-icon.js', $src);
        self::assertStringContainsString('mini-cart-drawer.css', $src);
        self::assertStringContainsString('frontendJsMtime', $src);
        self::assertStringContainsString('frontendAssetVersion', $src);
        self::assertStringContainsString("'assetVersion' => \$frontendAssetVersion", $src);
    }

    /**
     * E1：PROD 下 deployVersion 不得粘滞字面 `dev`（与 current.json 对齐；缺/粘滞时文档化守卫 `prod`）。
     */
    public function testHeaderProdDeployVersionRejectsStickyDev(): void
    {
        $path = dirname(__DIR__, 3) . '/view/blocks/header/base.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("BP . 'var/deploy/current.json'", $src);
        self::assertStringContainsString("\$deployMeta['deploy_version']", $src);
        self::assertStringContainsString('PROD 禁止沿用粘滞的 deploy_version=dev', $src);
        self::assertStringContainsString("strcasecmp(\$deployVersion, 'dev') === 0", $src);
        self::assertStringContainsString("\$deployVersion = 'prod';", $src);
        self::assertStringContainsString("'/static/Weline/Frontend/base/weline.modules.js'", $src);
    }
}
