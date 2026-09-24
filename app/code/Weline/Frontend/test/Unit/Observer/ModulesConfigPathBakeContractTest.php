<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

/**
 * C-RESOLVE / E4 守护：modules 产物保留 Module::；禁止本机 DEV 绝对 URL 烤死；
 * resolveStaticPath PROD → /static/{V}/{M}/{rel}，历史 /V/M/view/statics/ 纠偏仍有效。
 * 禁为 modules 另造 HotCache 袋（产物链 = compile + Deploy\Upgrade / FlatStatic Provider）。
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
        // E4：非 Module::=0 / 纯 origin_paths 烤死态
        self::assertMatchesRegularExpression(
            '#paths:\s*\[[^\]]*Weline_[A-Za-z0-9_]+::#',
            $source,
            'compiled modules must keep Module:: logical paths (E4)'
        );

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

    public function testResolveStaticPathProdFlatShapeAndBakedDevRewrite(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/weline.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        // C-RESOLVE：Module:: → /static/{V}/{M}/{rel}（本 feature 不改契约形状）
        self::assertStringContainsString(
            'return `/static/${normalizedModuleName}/${filePath}${querySuffix}`;',
            $source,
            'PROD Module:: must resolve to /static/{V}/{M}/{rel}'
        );
        self::assertStringContainsString(
            'return `/${normalizedModuleName}/view/statics/${filePath}${querySuffix}`;',
            $source,
            'DEV Module:: shape must remain /{V}/{M}/view/statics/'
        );

        // 历史烤死绝对路径纠偏仍有效
        self::assertStringContainsString('bakedDev', $source);
        self::assertStringContainsString('纠偏：历史 compile', $source);
        self::assertStringContainsString('/static/${vendorName}/${moduleNamePart}/', $source);
    }

    public function testCompilerDocumentsNoDevAbsoluteBake(): void
    {
        $path = dirname(__DIR__, 3) . '/Observer/Compiler.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('paths 必须保留 Module:: 逻辑名', $source);
        self::assertStringContainsString('禁止按本机 DEV 烤死绝对 URL', $source);
        self::assertStringNotContainsString('HotCache', $source, 'Compiler must not introduce modules HotCache bag');
    }
}
