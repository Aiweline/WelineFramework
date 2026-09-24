<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

/**
 * 编译后的 modules paths 不得按本机 DEV 烤死 /Vendor/Module/view/statics/... 绝对 URL。
 */
class ModulesConfigPathBakeContractTest extends TestCase
{
    public function testCompiledFrontendModulesKeepLogicalModulePaths(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/base/weline.modules.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('Weline_Currency::js/currency.js', $source);
        self::assertStringContainsString('Weline_Framework::js/i18n.js', $source);
        self::assertStringContainsString('Weline_Wishlist::js/wishlist-header.js', $source);

        self::assertDoesNotMatchRegularExpression(
            '#paths:\s*\[[^\]]*"/Weline/Currency/view/statics/#',
            $source,
            'currency must not bake DEV absolute path'
        );
        self::assertDoesNotMatchRegularExpression(
            '#paths:\s*\[[^\]]*"/Weline/Framework/view/statics/js/i18n\.js"#',
            $source,
            'i18n must not bake DEV absolute path'
        );
    }

    public function testResolveStaticPathRewritesBakedDevAbsolutePaths(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/weline.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('bakedDev', $source);
        self::assertStringContainsString('纠偏：历史 compile', $source);
        self::assertStringContainsString('/static/${vendorName}/${moduleNamePart}/', $source);
    }
}
