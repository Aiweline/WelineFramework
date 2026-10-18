<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllFrontendObserverContractsPass().
 */
final class FrontendObserverContractsTest extends TestCase
{
    public function testAllFrontendObserverContractsPass(): void
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
            'ModulesConfigPathBake::testCompiledFrontendModulesKeepLogicalModulePaths' => function () {
            $this->assert_ModulesConfigPathBake_testCompiledFrontendModulesKeepLogicalModulePaths();
        },
            'ModulesConfigPathBake::testResolveStaticPathProdFlatShapeAndBakedDevRewrite' => function () {
            $this->assert_ModulesConfigPathBake_testResolveStaticPathProdFlatShapeAndBakedDevRewrite();
        },
            'ModulesConfigPathBake::testCompilerDocumentsNoDevAbsoluteBake' => function () {
            $this->assert_ModulesConfigPathBake_testCompilerDocumentsNoDevAbsoluteBake();
        },
        ];
    }

private function assert_ModulesConfigPathBake_testCompiledFrontendModulesKeepLogicalModulePaths(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/base/weline.modules.js';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('Weline_Currency::js/currency.js', $source);
    self::assertStringContainsString('Weline_Framework::js/i18n.js', $source);
    self::assertStringContainsString('Weline_Wishlist::js/wishlist-header.js', $source);
    // E4：非 Module::=0 / 纯 origin_paths 烤死态
    self::assertMatchesRegularExpression('#paths:\s*\[[^\]]*Weline_[A-Za-z0-9_]+::#', $source, 'compiled modules must keep Module:: logical paths (E4)');
    self::assertDoesNotMatchRegularExpression('#paths:\s*\[[^\]]*"/Weline/Currency/view/statics/#', $source, 'currency must not bake DEV absolute path');
    self::assertDoesNotMatchRegularExpression('#paths:\s*\[[^\]]*"/Weline/Framework/view/statics/js/i18n\.js"#', $source, 'i18n must not bake DEV absolute path');
}

private function assert_ModulesConfigPathBake_testResolveStaticPathProdFlatShapeAndBakedDevRewrite(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    // C-RESOLVE：Module:: → /static/{V}/{M}/{rel}（本 feature 不改契约形状）
    self::assertStringContainsString('return `/static/${normalizedModuleName}/${filePath}${querySuffix}`;', $source, 'PROD Module:: must resolve to /static/{V}/{M}/{rel}');
    self::assertStringContainsString('return `/${normalizedModuleName}/view/statics/${filePath}${querySuffix}`;', $source, 'DEV Module:: shape must remain /{V}/{M}/view/statics/');
    // 历史烤死绝对路径纠偏仍有效
    self::assertStringContainsString('bakedDev', $source);
    self::assertStringContainsString('纠偏：历史 compile', $source);
    self::assertStringContainsString('/static/${vendorName}/${moduleNamePart}/', $source);
}

private function assert_ModulesConfigPathBake_testCompilerDocumentsNoDevAbsoluteBake(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/Observer/Compiler.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('paths 必须保留 Module:: 逻辑名', $source);
    self::assertStringContainsString('禁止按本机 DEV 烤死绝对 URL', $source);
    self::assertStringContainsString("['globalVar', 'description', 'load', 'async', 'type']", $source, 'Compiler knownKeys must preserve type=module for ESM shells');
    self::assertStringNotContainsString('HotCache', $source, 'Compiler must not introduce modules HotCache bag');

    $loader = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js');
    self::assertStringContainsString("script.type = 'module'", $loader, 'ModuleLoader must honor modulesConfig type:module');
    self::assertStringContainsString('moduleConfig.type || moduleConfig.scriptType', $loader);
}
}
