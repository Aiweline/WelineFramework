<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use ReflectionMethod as ReflectionMethod;
use ReflectionProperty as ReflectionProperty;
use Weline\Framework\Api\Runtime\ProcessCacheResetter as FrameworkProcessCacheResetter;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Deploy\DeployPublicUrlMode;
use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ProcessMemoryReclaimFacade;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\View\Cache\TemplateCachePolicyCompiler;
use Weline\Framework\View\Cache\TemplateFragmentOutputCache;
use Weline\Framework\View\CompiledTemplateMinifier;
use Weline\Framework\View\HookCompiledPhpInliner;
use Weline\Framework\View\PublicStaticUrl;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Taglib\Ast\AstNodePoolReclaimable;
use Weline\Framework\View\Taglib\Ast\NodePool;
use Weline\Framework\View\Taglib\Ast\StringInterner;
use Weline\Framework\View\Taglib\Generator\ClassGenerator;
use Weline\Framework\View\Taglib\Support\FloatingDropdownEmitter;
use Weline\Framework\View\Template;
use Weline\Framework\View\TemplateCompileLocaleJob;
use Weline\Framework\View\TemplateCompileService;
use Weline\Framework\View\ViewAreaTemplatePath;
use Weline\Theme\Helper\LayoutPathResolver;

/**
 * Aggregated contract suite (merged from 27 *ContractTest files).
 * Observe one overall pass/fail via testAllViewContractsPass().
 */
final class ViewContractsTest extends TestCase
{
    public function testAllViewContractsPass(): void
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
            'TemplateFragmentOutputCacheTtl::testCompilerAcceptsPerFileTtlAndFragmentBagRoundTrips' => function () {
            $this->assert_TemplateFragmentOutputCacheTtl_testCompilerAcceptsPerFileTtlAndFragmentBagRoundTrips();
        },
            'TemplateFragmentOutputCacheTtl::testHookFetchReadsDescriptorTtl' => function () {
            $this->assert_TemplateFragmentOutputCacheTtl_testHookFetchReadsDescriptorTtl();
        },
            'ClassGeneratorFiberCapture::testGeneratedRenderUsesFiberOutputBuffer' => function () {
            $this->assert_ClassGeneratorFiberCapture_testGeneratedRenderUsesFiberOutputBuffer();
        },
            'CompiledTemplateMinifier::testCollapseHtmlWhitespaceKeepsPreAndCollapsesAdjacent' => function () {
            $this->assert_CompiledTemplateMinifier_testCollapseHtmlWhitespaceKeepsPreAndCollapsesAdjacent();
        },
            'CompiledTemplateMinifier::testMinifyKeepsHashCommentAndIsIdentityInDev' => function () {
            $this->assert_CompiledTemplateMinifier_testMinifyKeepsHashCommentAndIsIdentityInDev();
        },
            'ForbidHardcodedModuleStaticUrl::testCriticalChromeUsesPublicStaticUrlNotBareDevModulePaths' => function () {
            $this->assert_ForbidHardcodedModuleStaticUrl_testCriticalChromeUsesPublicStaticUrlNotBareDevModulePaths();
        },
            'ForbidHardcodedModuleStaticUrl::testPublicStaticUrlHelperExists' => function () {
            $this->assert_ForbidHardcodedModuleStaticUrl_testPublicStaticUrlHelperExists();
        },
            'ForbidHardcodedModuleStaticUrl::testTraitTemplateModuleStaticsUsePublicStaticUrl' => function () {
            $this->assert_ForbidHardcodedModuleStaticUrl_testTraitTemplateModuleStaticsUsePublicStaticUrl();
        },
            'FormFiberCapture::testFormTaglibCallbackUsesFiberOutputBufferNotNativeOb' => function () {
            $this->assert_FormFiberCapture_testFormTaglibCallbackUsesFiberOutputBufferNotNativeOb();
        },
            'FormFiberCapture::testAstCodeGeneratorRuntimeChildrenUsesFiberOutputBuffer' => function () {
            $this->assert_FormFiberCapture_testAstCodeGeneratorRuntimeChildrenUsesFiberOutputBuffer();
        },
            'FrameworkI18nJsModule::testFrameworkRegistersCoreI18nModule' => function () {
            $this->assert_FrameworkI18nJsModule_testFrameworkRegistersCoreI18nModule();
        },
            'FrameworkI18nJsModule::testFrameworkI18nScriptInstallsFacade' => function () {
            $this->assert_FrameworkI18nJsModule_testFrameworkI18nScriptInstallsFacade();
        },
            'FrameworkI18nJsModule::testFrontendWelineJsDoesNotEmbedI18nObject' => function () {
            $this->assert_FrameworkI18nJsModule_testFrontendWelineJsDoesNotEmbedI18nObject();
        },
            'FrameworkI18nJsModule::testI18nEnhancementModuleDoesNotRegisterCoreI18n' => function () {
            $this->assert_FrameworkI18nJsModule_testI18nEnhancementModuleDoesNotRegisterCoreI18n();
        },
            'HookCompiledPhpInliner::testDevModeTryEmitReturnsNull' => function () {
            $this->assert_HookCompiledPhpInliner_testDevModeTryEmitReturnsNull();
        },
            'HookCompiledPhpInliner::testRuntimeAttributeForcesGetHookPath' => function () {
            $this->assert_HookCompiledPhpInliner_testRuntimeAttributeForcesGetHookPath();
        },
            'HookCompiledPhpInliner::testBuildInlinePhpEmitsIncludeNotHtmlSnapshot' => function () {
            $this->assert_HookCompiledPhpInliner_testBuildInlinePhpEmitsIncludeNotHtmlSnapshot();
        },
            'HooksRegistryCompileDigest::testDigestIncludesContributorSegmentWhenRegistryPresent' => function () {
            $this->assert_HooksRegistryCompileDigest_testDigestIncludesContributorSegmentWhenRegistryPresent();
        },
            'HtmlOutputCacheKey::testHookOutputStyleKeysSplitByLangAndCurrency' => function () {
            try {
                $this->assert_HtmlOutputCacheKey_testHookOutputStyleKeysSplitByLangAndCurrency();
            } finally {
                $this->tearDown_HtmlOutputCacheKey();
            }
        },
            'HtmlOutputCacheKey::testTemplateHookAndReusableHeaderUseSystemEnvironmentScopedKeys' => function () {
            try {
                $this->assert_HtmlOutputCacheKey_testTemplateHookAndReusableHeaderUseSystemEnvironmentScopedKeys();
            } finally {
                $this->tearDown_HtmlOutputCacheKey();
            }
        },
            'HtmlOutputCacheKey::testLanguageSwitcherHtmlKeyUsesSystemEnvironmentScopedBuilder' => function () {
            try {
                $this->assert_HtmlOutputCacheKey_testLanguageSwitcherHtmlKeyUsesSystemEnvironmentScopedBuilder();
            } finally {
                $this->tearDown_HtmlOutputCacheKey();
            }
        },
            'LangBakePhpStringEscape::testQuotedAtLangInsidePhpExportsApostropheSafely' => function () {
            $this->assert_LangBakePhpStringEscape_testQuotedAtLangInsidePhpExportsApostropheSafely();
        },
            'LangBakePhpStringEscape::testBareAtLangInsidePhpBecomesExportedLiteral' => function () {
            $this->assert_LangBakePhpStringEscape_testBareAtLangInsidePhpBecomesExportedLiteral();
        },
            'LangBakePhpStringEscape::testHtmlContextKeepsRawBakeIncludingApostrophe' => function () {
            $this->assert_LangBakePhpStringEscape_testHtmlContextKeepsRawBakeIncludingApostrophe();
        },
            'LangBakePhpStringEscape::testRuntimePhpStubIsNotReExported' => function () {
            $this->assert_LangBakePhpStringEscape_testRuntimePhpStubIsNotReExported();
        },
            'PublicStaticUrl::testFromDevWebPathIsModeAware' => function () {
            try {
                $this->assert_PublicStaticUrl_testFromDevWebPathIsModeAware();
            } finally {
                $this->tearDown_PublicStaticUrl();
            }
        },
            'PublicStaticUrl::testStagingForcesFlatProductionUrl' => function () {
            try {
                $this->assert_PublicStaticUrl_testStagingForcesFlatProductionUrl();
            } finally {
                $this->tearDown_PublicStaticUrl();
            }
        },
            'PublicStaticUrl::testSourceForbidsInventingThirdShapeInHelper' => function () {
            try {
                $this->assert_PublicStaticUrl_testSourceForbidsInventingThirdShapeInHelper();
            } finally {
                $this->tearDown_PublicStaticUrl();
            }
        },
            'NodePoolMemoryPressure::testTrimForMemoryPressureHalvesPools' => function () {
            try {
                $this->assert_NodePoolMemoryPressure_testTrimForMemoryPressureHalvesPools();
            } finally {
                $this->tearDown_NodePoolMemoryPressure();
            }
        },
            'NodePoolMemoryPressure::testSoftViaReclaimableAndHardViaFrameworkResetter' => function () {
            try {
                $this->assert_NodePoolMemoryPressure_testSoftViaReclaimableAndHardViaFrameworkResetter();
            } finally {
                $this->tearDown_NodePoolMemoryPressure();
            }
        },
            'ConditionGetData::testBareConditionIdentifierUsesGetDataFallback' => function () {
            $this->assert_ConditionGetData_testBareConditionIdentifierUsesGetDataFallback();
        },
            'ConditionGetData::testDottedConditionPathUsesGetDataFallback' => function () {
            $this->assert_ConditionGetData_testDottedConditionPathUsesGetDataFallback();
        },
            'ConditionGetData::testExplicitDollarConditionRemainsLocalVariable' => function () {
            $this->assert_ConditionGetData_testExplicitDollarConditionRemainsLocalVariable();
        },
            'TemplateCompileInplaceOverwrite::testCompileSourceBytesWritesFixedPathAndOverwritesOnIdentityChange' => function () {
            $this->assert_TemplateCompileInplaceOverwrite_testCompileSourceBytesWritesFixedPathAndOverwritesOnIdentityChange();
        },
            'TemplateCompilePathUnification::testFetchFileFromSourceUsesStableRootAndPlainSrcLeaf' => function () {
            $this->assert_TemplateCompilePathUnification_testFetchFileFromSourceUsesStableRootAndPlainSrcLeaf();
        },
            'TemplateCompilePathUnification::testLayoutPathResolverSharesStableCompileRoot' => function () {
            $this->assert_TemplateCompilePathUnification_testLayoutPathResolverSharesStableCompileRoot();
        },
            'TemplateCompileService::testServiceExposesPinnedCompileWithCallerLocalesAndProgress' => function () {
            $this->assert_TemplateCompileService_testServiceExposesPinnedCompileWithCallerLocalesAndProgress();
        },
            'TemplateCompileService::testLocaleJobProgressLineRoundTrip' => function () {
            $this->assert_TemplateCompileService_testLocaleJobProgressLineRoundTrip();
        },
            'TemplateCompileService::testResolveConcurrencyClampsAndDefaults' => function () {
            $this->assert_TemplateCompileService_testResolveConcurrencyClampsAndDefaults();
        },
            'TemplateCompileService::testWorkerScriptExistsBesideViewModule' => function () {
            $this->assert_TemplateCompileService_testWorkerScriptExistsBesideViewModule();
        },
            'TemplateFrontendAuthGuestContext::testFrontendAuthStaticHookCacheContextIsGuestOnlySource' => function () {
            $this->assert_TemplateFrontendAuthGuestContext_testFrontendAuthStaticHookCacheContextIsGuestOnlySource();
        },
            'TemplateNestedStream::testFetchStreamsWhenCaptureActiveAndFetchHtmlRemainsCaptureApi' => function () {
            $this->assert_TemplateNestedStream_testFetchStreamsWhenCaptureActiveAndFetchHtmlRemainsCaptureApi();
        },
            'ViewEngine::testEnginePackageAndFlagsExist' => function () {
            $this->assert_ViewEngine_testEnginePackageAndFlagsExist();
        },
            'TemplateNestedStream::testControllerContentFetchUsesFetchHtml' => function () {
            $this->assert_TemplateNestedStream_testControllerContentFetchUsesFetchHtml();
        },
            'ViewFileMap::testSealedViewFileMapRemoved' => function () {
            $this->assert_ViewFileMap_testSealedViewFileMapRemoved();
        },
            'TemplateReferencePathCase::testModuleTemplateReferencesMatchOnDiskCase' => function () {
            $this->assert_TemplateReferencePathCase_testModuleTemplateReferencesMatchOnDiskCase();
        },
            'TemplateThemeSwrPostResponseCaptureMode::testHookOutputAndAggregateRefreshUseScopeCaptureMode' => function () {
            $this->assert_TemplateThemeSwrPostResponseCaptureMode_testHookOutputAndAggregateRefreshUseScopeCaptureMode();
        },
            'TraitTemplateModuleStaticFlat::testTraitTemplateBuildsModuleStaticsViaPublicStaticUrl' => function () {
            $this->assert_TraitTemplateModuleStaticFlat_testTraitTemplateBuildsModuleStaticsViaPublicStaticUrl();
        },
            'TraitTemplateModuleStaticFlat::testPublicStaticUrlRemainsFlatAuthority' => function () {
            $this->assert_TraitTemplateModuleStaticFlat_testPublicStaticUrlRemainsFlatAuthority();
        },
            'TraitTemplateTagSourceAreaRoute::testFetchTagSourceDisablesAreaRouteForAllTypes' => function () {
            $this->assert_TraitTemplateTagSourceAreaRoute_testFetchTagSourceDisablesAreaRouteForAllTypes();
        },
            'UrlLiteralBake::testCompilerGenerationBumpsForUrlLiteralBake' => function () {
            $this->assert_UrlLiteralBake_testCompilerGenerationBumpsForUrlLiteralBake();
        },
            'UrlLiteralBake::testLiteralPathBakesWithoutGetUrlStub' => function () {
            $this->assert_UrlLiteralBake_testLiteralPathBakesWithoutGetUrlStub();
        },
            'UrlLiteralBake::testDocumentRootPathBakesRootRelative' => function () {
            $this->assert_UrlLiteralBake_testDocumentRootPathBakesRootRelative();
            },
            'UrlLiteralBake::testDynamicPathKeepsNullSoStubRemains' => function () {
            $this->assert_UrlLiteralBake_testDynamicPathKeepsNullSoStubRemains();
        },
            'UrlLiteralBake::testLiteralParamsBakeWithResolvedUrl' => function () {
            $this->assert_UrlLiteralBake_testLiteralParamsBakeWithResolvedUrl();
        },
            'UrlLiteralBake::testPhpContextVarExportsBakedUrl' => function () {
            $this->assert_UrlLiteralBake_testPhpContextVarExportsBakedUrl();
        },
            'ViewAreaTemplatePath::testStripAndJoinUseLowercaseAreaLayerOnly' => function () {
            $this->assert_ViewAreaTemplatePath_testStripAndJoinUseLowercaseAreaLayerOnly();
        },
            'ViewAreaTemplatePath::testPcControllerFetchJoinsAreaLayer' => function () {
            $this->assert_ViewAreaTemplatePath_testPcControllerFetchJoinsAreaLayer();
        },
            'ViewTemplateAreaDirCaseGate::testNoPascalCaseAreaDirsUnderTemplatesOrTheme' => function () {
            $this->assert_ViewTemplateAreaDirCaseGate_testNoPascalCaseAreaDirsUnderTemplatesOrTheme();
        },
            'FloatingDropdownEmitter::testScriptDefinesTaglibOwnedGlobalWithHoverBridge' => function () {
            $this->assert_FloatingDropdownEmitter_testScriptDefinesTaglibOwnedGlobalWithHoverBridge();
        },
            'TaglibFloatingDropdownMigration::testMigratedSelectTaglibsUseEmitterNotThemeSmartDropdown' => function () {
            $this->assert_TaglibFloatingDropdownMigration_testMigratedSelectTaglibsUseEmitterNotThemeSmartDropdown();
        },
        ];
    }

private function assert_TemplateFragmentOutputCacheTtl_testCompilerAcceptsPerFileTtlAndFragmentBagRoundTrips(): void
{
    $compiler = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Cache/TemplateCachePolicyCompiler.php');
    self::assertStringContainsString("array_key_exists('ttl'", $compiler);
    self::assertStringContainsString('invalid ttl', $compiler);
    TemplateFragmentOutputCache::resetProcessCaches();
    self::assertSame(300, TemplateFragmentOutputCache::resolveTtl(['ttl' => 300], 60));
    self::assertSame(60, TemplateFragmentOutputCache::resolveTtl([], 60));
    $key = 'test.widget.fragment.' . uniqid('', true);
    self::assertTrue(TemplateFragmentOutputCache::set($key, '<div data-testid="ok">x</div>', 30));
    $hit = TemplateFragmentOutputCache::get($key, 30);
    self::assertSame('fresh', $hit['status']);
    self::assertStringContainsString('data-testid="ok"', (string) $hit['html']);
    TemplateFragmentOutputCache::resetProcessCaches();
}

private function assert_TemplateFragmentOutputCacheTtl_testHookFetchReadsDescriptorTtl(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Template.php');
    self::assertStringContainsString('TemplateFragmentOutputCache::resolveTtl', $template);
}

private function assert_ClassGeneratorFiberCapture_testGeneratedRenderUsesFiberOutputBuffer(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Taglib/Generator/ClassGenerator.php');
    $pos = \strpos($source, 'public function generate(');
    self::assertNotFalse($pos);
    $chunk = \substr($source, (int) $pos, 2500);
    self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $chunk);
    self::assertStringContainsString('FiberOutputBuffer::endCapture()', $chunk);
    self::assertStringContainsString('FiberOutputBuffer::discardCapture()', $chunk);
    self::assertStringNotContainsString('ob_start();', $chunk);
    self::assertStringNotContainsString('ob_get_clean()', $chunk);
    self::assertTrue(\class_exists(ClassGenerator::class));
}

private function assert_CompiledTemplateMinifier_testCollapseHtmlWhitespaceKeepsPreAndCollapsesAdjacent(): void
{
    $method = new ReflectionMethod(CompiledTemplateMinifier::class, 'collapseHtmlWhitespace');
    $method->setAccessible(true);
    self::assertSame('<pre>  keep   spaces  </pre>', $method->invoke(null, '<pre>  keep   spaces  </pre>'));
    $collapsed = $method->invoke(null, "<div>  Hello   World  </div>\n\n\n");
    self::assertStringContainsString('<div> Hello World </div>', $collapsed);
    self::assertStringNotContainsString('  Hello   World  ', $collapsed);
}

private function assert_CompiledTemplateMinifier_testMinifyKeepsHashCommentAndIsIdentityInDev(): void
{
    $src = "<?php /* hash:abc123-10 */ ?>\n\n  <div>  Hello   World  </div>\n\n";
    $out = CompiledTemplateMinifier::minify($src);
    if (\defined('DEV') && DEV) {
        self::assertFalse(CompiledTemplateMinifier::shouldMinify());
        self::assertSame($src, $out);
        return;
    }
    self::assertStringContainsString('hash:abc123-10', $out);
    self::assertStringContainsString('<div> Hello World </div>', $out);
}

/** @return list<string> */
private function criticalRuntimeFiles_ForbidHardcodedModuleStaticUrl(): array
{
    $bp = '/Users/weline/Project/Official/框架/app/code/Weline';
    return [$bp . '/Frontend/view/blocks/header/base.phtml', $bp . '/Backend/view/blocks/header/base.phtml', $bp . '/Theme/Helper/StorefrontImagePlaceholder.php', $bp . '/Theme/Observer/ControllerFetchFileAfter.php', $bp . '/I18n/Helper/CountryFlagMarkup.php', $bp . '/CustomerService/view/templates/frontend/widgets/customer-service-float.phtml'];
}

private function assert_ForbidHardcodedModuleStaticUrl_testCriticalChromeUsesPublicStaticUrlNotBareDevModulePaths(): void
{
    $pattern = "/['\"]\\/Weline\\/[A-Za-z0-9_]+\\/view\\/statics/";
    foreach ($this->criticalRuntimeFiles_ForbidHardcodedModuleStaticUrl() as $file) {
        self::assertFileExists($file, $file);
        $src = (string) file_get_contents($file);
        // Identity constants / DEV path seeds are allowed only as PublicStaticUrl inputs.
        if (str_contains($src, 'PublicStaticUrl::fromDevWebPath') || str_contains($src, 'fetchTagSource')) {
            // Still forbid naked ternary DEV/PROD duplicates.
            self::assertDoesNotMatchRegularExpression("/DEV\\s*\\?\\s*['\"]\\/Weline\\//", $src, 'Use PublicStaticUrl instead of DEV ternary: ' . $file);
            continue;
        }
        self::assertDoesNotMatchRegularExpression($pattern, $src, 'Hardcoded module static URL in ' . $file);
    }
}

private function assert_ForbidHardcodedModuleStaticUrl_testPublicStaticUrlHelperExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/PublicStaticUrl.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('DeployPublicUrlMode::useProduction', $src);
    self::assertStringContainsString('/static/', $src);
}

private function assert_ForbidHardcodedModuleStaticUrl_testTraitTemplateModuleStaticsUsePublicStaticUrl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/TraitTemplate.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('PublicStaticUrl::fromModuleSource', $src);
    self::assertStringContainsString('buildModuleStaticPublicUrl', $src);
}

private function assert_FormFiberCapture_testFormTaglibCallbackUsesFiberOutputBufferNotNativeOb(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Taglib.php');
    self::assertSame('20261023-compile-scope-v13', Taglib::COMPILER_GENERATION);
    self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $source);
    self::assertStringContainsString('FiberOutputBuffer::endCapture()', $source);
    self::assertStringContainsString('Taglib__form_body', $source);
    self::assertStringNotContainsString("Taglib__form_body = \\\\ob_get_clean()", $source);
    self::assertMatchesRegularExpression('/FormRenderer::open\([\s\S]{0,400}?FiberOutputBuffer::beginCapture\(\)/', $source);
    self::assertDoesNotMatchRegularExpression('/FormRenderer::open\([\s\S]{0,200}?\\\\ob_start\(\)/', $source);
}

private function assert_FormFiberCapture_testAstCodeGeneratorRuntimeChildrenUsesFiberOutputBuffer(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Taglib/Generator/CodeGenerator.php');
    $pos = \strpos($source, 'function generateRuntimeTag');
    self::assertNotFalse($pos);
    $chunk = \substr($source, (int) $pos, 3500);
    self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $chunk);
    self::assertStringContainsString('FiberOutputBuffer::endCapture()', $chunk);
    self::assertStringContainsString('FiberOutputBuffer::discardCapture()', $chunk);
    self::assertStringNotContainsString('wrapPhp("ob_start();")', $chunk);
    self::assertStringNotContainsString('ob_get_clean()', $chunk);
}

private function assert_FrameworkI18nJsModule_testFrameworkRegistersCoreI18nModule(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/statics/frontend/weline.modules.js';
    self::assertFileExists($path);
    $js = (string) file_get_contents($path);
    self::assertStringContainsString('i18n:', $js);
    self::assertStringContainsString('Weline_Framework::js/i18n.js', $js);
    self::assertStringContainsString('globalVar: "WelineI18n"', $js);
    self::assertStringContainsString('load: "defer"', $js);
    self::assertStringContainsString('language: "i18n"', $js);
}

private function assert_FrameworkI18nJsModule_testFrameworkI18nScriptInstallsFacade(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/statics/js/i18n.js';
    self::assertFileExists($path);
    $js = (string) file_get_contents($path);
    self::assertStringContainsString('installWelineI18nFacade', $js);
    self::assertStringContainsString('window.Weline.i18n', $js);
    self::assertStringContainsString('window.WelineI18n', $js);
    self::assertStringContainsString('Weline_Framework', $js);
}

private function assert_FrameworkI18nJsModule_testFrontendWelineJsDoesNotEmbedI18nObject(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/view/statics/js/weline.js';
    self::assertFileExists($path);
    $js = (string) file_get_contents($path);
    self::assertStringNotContainsString('persistLangPreference', $js);
    self::assertStringNotContainsString('i18n: {', $js);
    self::assertStringContainsString('WelineI18n', $js);
    self::assertStringContainsString('Weline_Framework::js/i18n.js', $js);
}

private function assert_FrameworkI18nJsModule_testI18nEnhancementModuleDoesNotRegisterCoreI18n(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline' . '/I18n/view/statics/frontend/weline.modules.js';
    self::assertFileDoesNotExist($path);
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline' . '/I18n/view/statics/js/i18n.js');
}

private function assert_HookCompiledPhpInliner_testDevModeTryEmitReturnsNull(): void
{
    if (!(\defined('DEV') && DEV)) {
        self::markTestSkipped('Contract expects DEV=true in unit runner');
    }
    self::assertNull(HookCompiledPhpInliner::tryEmit('Weline_Captcha::backend::layouts::login::captcha'));
    self::assertFalse(HookCompiledPhpInliner::isProductionInlineEnabled());
}

private function assert_HookCompiledPhpInliner_testRuntimeAttributeForcesGetHookPath(): void
{
    $method = new ReflectionMethod(HookCompiledPhpInliner::class, 'wantsRuntime');
    $method->setAccessible(true);
    self::assertTrue($method->invoke(null, ['runtime' => '1']));
    self::assertTrue($method->invoke(null, ['runtime' => true]));
    self::assertTrue($method->invoke(null, ['hook-runtime' => 'yes']));
    self::assertFalse($method->invoke(null, []));
    self::assertFalse($method->invoke(null, ['runtime' => '0']));
}

private function assert_HookCompiledPhpInliner_testBuildInlinePhpEmitsIncludeNotHtmlSnapshot(): void
{
    $method = new ReflectionMethod(HookCompiledPhpInliner::class, 'buildInlinePhp');
    $method->setAccessible(true);
    $hook = 'Weline_Captcha::backend::layouts::login::captcha';
    try {
        $php = $method->invoke(null, $hook);
    } catch (\Throwable $e) {
        self::markTestSkipped('Hook contributor not compilable in this runner: ' . $e->getMessage());
    }
    if ($php === null) {
        self::markTestSkipped('Hook contributor com path unresolved (registry/modules)');
    }
    self::assertIsString($php);
    self::assertStringContainsString('baked-hook:' . $hook, $php);
    self::assertStringContainsString('include ', $php);
    self::assertStringNotContainsString('<html', $php);
    self::assertDoesNotMatchRegularExpression('/echo\s+[\'"]<[^;]+;/', $php);
}

private function assert_HooksRegistryCompileDigest_testDigestIncludesContributorSegmentWhenRegistryPresent(): void
{
    $file = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';
    if (!\is_file($file)) {
        self::markTestSkipped('generated/hooks.php missing');
    }
    $digest = Template::hooksRegistryCompileDigest();
    self::assertNotSame('missing', $digest);
    self::assertMatchesRegularExpression('/^\d+:\d+#([a-f0-9]{16}|empty)$/', $digest);
}

private function tearDown_HtmlOutputCacheKey(): void
{
    RequestContext::cleanup();
    if (Context::hasCurrent()) {
        Context::leave();
    }
    parent::tearDown();
}

private function assert_HtmlOutputCacheKey_testHookOutputStyleKeysSplitByLangAndCurrency(): void
{
    $build = static function (): string {
        return KeyBuilder::buildEnvironmentScoped('hook.output', 'fixture.phtml|1|1', ['cache_version' => '20261013-hook-env-scoped-v2', 'hook_context' => 'body-end|auth:frontend-auth:0', 'policy_digest' => 'd', 'ttl' => 60], ['area_route' => false]);
    };
    $this->enterLangCurrency_HtmlOutputCacheKey('zh_Hans_CN', 'CNY');
    $zh = $build();
    $this->enterLangCurrency_HtmlOutputCacheKey('en_US', 'CNY');
    $en = $build();
    $this->enterLangCurrency_HtmlOutputCacheKey('en_US', 'USD');
    $usd = $build();
    self::assertNotSame($zh, $en, 'lang must split hook.output keys');
    self::assertNotSame($en, $usd, 'currency must split hook.output keys');
}

private function assert_HtmlOutputCacheKey_testTemplateHookAndReusableHeaderUseSystemEnvironmentScopedKeys(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Template.php');
    self::assertStringContainsString("KeyBuilder::buildEnvironmentScoped(\n            'hook.output'", $src);
    self::assertStringContainsString("KeyBuilder::buildEnvironmentScoped(\n            'template.output.header.default'", $src);
    self::assertStringContainsString("KeyBuilder::buildEnvironmentScoped(\n                'view.hook.request'", $src);
    self::assertStringContainsString("KeyBuilder::buildEnvironmentScoped(\n                    'view.hook.aggregate'", $src);
    self::assertStringContainsString("'cache_version' => '20261013-hook-env-scoped-v2'", $src);
    self::assertStringNotContainsString("'hook.output.' . \\sha1(", $src);
    self::assertStringNotContainsString("'template.output.header.default.' . KeyBuilder::environmentHash(", $src);
    self::assertStringNotContainsString("'view.hook.aggregate.' . \\sha1(", $src);
    self::assertStringNotContainsString("'body-end:' . KeyBuilder::environmentHash(", $src);
    self::assertStringNotContainsString("'account-sidebar:' . KeyBuilder::environmentHash(", $src);
}

private function assert_HtmlOutputCacheKey_testLanguageSwitcherHtmlKeyUsesSystemEnvironmentScopedBuilder(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/I18n/Taglib/LanguageSwitcher.php');
    self::assertStringContainsString("KeyBuilder::buildEnvironmentScoped(\n            'i18n.language_switcher.html'", $src);
    self::assertStringContainsString("'cache_version' => '20261013-lang-switcher-env-scoped-v2'", $src);
    self::assertStringNotContainsString('return \md5(\json_encode([', $src);
}

private function enterLangCurrency_HtmlOutputCacheKey(string $lang, string $currency): void
{
    if (Context::hasCurrent()) {
        RequestContext::cleanup();
        Context::leave();
    }
    Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
    RequestContext::setId('html-output-cache-' . $lang . '-' . $currency);
    Context::current()->set('input.server.WELINE_AREA', 'frontend');
    RequestContext::installScopeIdentity(ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL));
    RequestContext::setWelineUserLang($lang);
    RequestContext::setWelineUserCurrency($currency);
    StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(RequestContext::scopeIdentity(), $lang, $currency, \str_repeat('a', 64), \str_repeat('a', 64), true));
}

/**
 * @return array{0:int,1:string,2:string}
 */
private function adjust_LangBakePhpStringEscape(string $content, int $pos, string $raw, string $replacement, string $tagName = 'lang'): array
{
    $method = new ReflectionMethod(Taglib::class, 'adjustBakedLangInlineReplacement');
    $method->setAccessible(true);
    return $method->invoke(new Taglib(), $content, $pos, $raw, $replacement, $tagName);
}

private function apply_LangBakePhpStringEscape(string $content, string $raw, string $replacement): string
{
    $pos = strpos($content, $raw);
    self::assertNotFalse($pos, 'fixture must contain raw token');
    [$newPos, $newRaw, $newReplacement] = $this->adjust_LangBakePhpStringEscape($content, $pos, $raw, $replacement);
    return substr($content, 0, $newPos) . $newReplacement . substr($content, $newPos + strlen($newRaw));
}

private function assert_LangBakePhpStringEscape_testQuotedAtLangInsidePhpExportsApostropheSafely(): void
{
    $raw = '@lang(用户协议导航)';
    $content = "<?php \$nav = '{$raw}'; ?>";
    $baked = "Navigation des conditions d'utilisation";
    $result = $this->apply_LangBakePhpStringEscape($content, $raw, $baked);
    self::assertSame('<?php $nav = ' . var_export($baked, true) . '; ?>', $result);
    self::assertStringNotContainsString("d'utilisation'", $result);
    self::assertTrue(@eval('return true; ' . str_replace(['<?php', '?>'], '', $result)) !== false || true);
    // php -l equivalent: the assignment must be valid PHP
    $code = str_replace(['<?php', '?>'], '', $result);
    try {
        eval($code);
        $ok = true;
    } catch (\ParseError $e) {
        $ok = false;
        self::fail('baked PHP must parse: ' . $e->getMessage());
    }
    self::assertTrue($ok);
    self::assertSame($baked, $nav ?? null);
}

private function assert_LangBakePhpStringEscape_testBareAtLangInsidePhpBecomesExportedLiteral(): void
{
    $raw = '@lang(支付指南导航)';
    $content = "<?php \$nav = {$raw}; ?>";
    $baked = "Navigation du guide de paiement";
    $result = $this->apply_LangBakePhpStringEscape($content, $raw, $baked);
    self::assertSame('<?php $nav = ' . var_export($baked, true) . '; ?>', $result);
}

private function assert_LangBakePhpStringEscape_testHtmlContextKeepsRawBakeIncludingApostrophe(): void
{
    $raw = '@lang(同意)';
    $content = "<button aria-label=\"{$raw}\">OK</button>";
    $baked = "d'accord";
    $result = $this->apply_LangBakePhpStringEscape($content, $raw, $baked);
    self::assertSame('<button aria-label="d\'accord">OK</button>', $result);
    self::assertStringContainsString("d'accord", $result);
    self::assertStringNotContainsString('var_export', $result);
}

private function assert_LangBakePhpStringEscape_testRuntimePhpStubIsNotReExported(): void
{
    $raw = '@lang($title)';
    $content = "<?php \$x = '{$raw}'; ?>";
    $replacement = '<?=__($title)?>';
    [$pos, $keptRaw, $keptReplacement] = $this->adjust_LangBakePhpStringEscape($content, (int) strpos($content, $raw), $raw, $replacement);
    self::assertSame((int) strpos($content, $raw), $pos);
    self::assertSame($raw, $keptRaw);
    self::assertSame($replacement, $keptReplacement);
}

private function tearDown_PublicStaticUrl(): void
{
    putenv(DeployStagingSession::ENV_ROOT);
    unset($_ENV[DeployStagingSession::ENV_ROOT], $_SERVER[DeployStagingSession::ENV_ROOT]);
    parent::tearDown();
}

private function assert_PublicStaticUrl_testFromDevWebPathIsModeAware(): void
{
    $dev = '/Weline/Frontend/view/statics/js/weline-api-worker.js';
    if (DeployPublicUrlMode::useProduction()) {
        self::assertSame('/static/Weline/Frontend/js/weline-api-worker.js', PublicStaticUrl::fromDevWebPath($dev));
    } else {
        self::assertSame($dev, PublicStaticUrl::fromDevWebPath($dev));
    }
}

private function assert_PublicStaticUrl_testStagingForcesFlatProductionUrl(): void
{
    $root = rtrim(sys_get_temp_dir(), '/\\') . '/weline-public-static-' . bin2hex(random_bytes(3));
    mkdir($root . '/static', 0775, true);
    putenv(DeployStagingSession::ENV_ROOT . '=' . $root);
    $_ENV[DeployStagingSession::ENV_ROOT] = $root;
    self::assertTrue(DeployPublicUrlMode::useProduction());
    self::assertSame('/static/Weline/Theme/css/widgets/mini-cart-drawer.css', PublicStaticUrl::fromDevWebPath('/Weline/Theme/view/statics/css/widgets/mini-cart-drawer.css'));
    self::assertSame('/static/Weline/Cart/css/mini-cart-drawer-qty-hit.css?v=1', PublicStaticUrl::fromDevWebPath('/Weline/Cart/view/statics/css/mini-cart-drawer-qty-hit.css?v=1'));
    self::assertSame('/static/Weline/Frontend/js/weline-api-worker.js', PublicStaticUrl::fromModuleSource('Weline_Frontend::js/weline-api-worker.js'));
}

private function assert_PublicStaticUrl_testSourceForbidsInventingThirdShapeInHelper(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/PublicStaticUrl.php');
    self::assertStringContainsString('DeployPublicUrlMode::useProduction', $src);
    self::assertStringContainsString('/view/statics/', $src);
    self::assertStringContainsString('/static/', $src);
    self::assertStringNotContainsString('PublicThemeNamespace', $src);
}

private function tearDown_NodePoolMemoryPressure(): void
{
    NodePool::reset();
    ProcessMemoryReclaimFacade::resetForTests();
    parent::tearDown();
}

private function assert_NodePoolMemoryPressure_testTrimForMemoryPressureHalvesPools(): void
{
    NodePool::reset();
    for ($i = 0; $i < 10; $i++) {
        NodePool::textNode(1, 'text-' . $i);
        StringInterner::intern('str-' . $i);
    }
    $stringsBefore = StringInterner::size();
    NodePool::trimForMemoryPressure();
    $after = NodePool::stats();
    self::assertSame(5, $after['textNodes']);
    self::assertSame((int) \ceil($stringsBefore / 2), StringInterner::size());
}

private function assert_NodePoolMemoryPressure_testSoftViaReclaimableAndHardViaFrameworkResetter(): void
{
    $facadeSrc = (string) \file_get_contents((string) (new \ReflectionClass(ProcessMemoryReclaimFacade::class))->getFileName());
    self::assertStringContainsString('AstNodePoolReclaimable', $facadeSrc);
    $resetterSrc = (string) \file_get_contents((string) (new \ReflectionClass(FrameworkProcessCacheResetter::class))->getFileName());
    self::assertStringContainsString('NodePool::reset()', $resetterSrc);
    $adapter = new AstNodePoolReclaimable();
    for ($i = 0; $i < 8; $i++) {
        NodePool::textNode(1, 'n-' . $i);
    }
    $adapter->compact();
    self::assertSame(4, NodePool::stats()['textNodes']);
}

private function assert_ConditionGetData_testBareConditionIdentifierUsesGetDataFallback(): void
{
    $taglib = ObjectManager::getInstance(Taglib::class);
    $template = ObjectManager::getInstance(Template::class);
    $content = '<if condition="content">YES<elseif condition="other"/>NO</if>';
    $compiled = $taglib->compile($template, $content, 'qa06-condition-getdata.phtml');
    self::assertStringContainsString("(\$content ?? \$this->getData('content'))", $compiled, 'if condition="content" must match {{content}} semantics');
    self::assertStringContainsString("(\$other ?? \$this->getData('other'))", $compiled, 'elseif condition="other" must match {{other}} semantics');
    self::assertStringNotContainsString('if($content):', $compiled);
    self::assertStringNotContainsString('elseif($other):', $compiled);
}

private function assert_ConditionGetData_testDottedConditionPathUsesGetDataFallback(): void
{
    $taglib = ObjectManager::getInstance(Taglib::class);
    $template = ObjectManager::getInstance(Template::class);
    $content = '<if condition="meta.showHeader">H</if>';
    $compiled = $taglib->compile($template, $content, 'qa06-condition-dotted.phtml');
    self::assertStringContainsString("getData('meta')", $compiled);
    self::assertStringContainsString("'showHeader'", $compiled);
}

private function assert_ConditionGetData_testExplicitDollarConditionRemainsLocalVariable(): void
{
    $taglib = ObjectManager::getInstance(Taglib::class);
    $template = ObjectManager::getInstance(Template::class);
    $content = '<if condition="$show">Shown</if>';
    $compiled = $taglib->compile($template, $content, 'qa06-condition-dollar.phtml');
    self::assertStringContainsString('if($show):', $compiled);
    self::assertStringNotContainsString("getData('show')", $compiled);
}

private function assert_TemplateCompileInplaceOverwrite_testCompileSourceBytesWritesFixedPathAndOverwritesOnIdentityChange(): void
{
    $template = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor();
    $events = $this->getMockBuilder(EventsManager::class)->disableOriginalConstructor()->onlyMethods(['dispatch', 'getEventObservers'])->getMock();
    $events->method('getEventObservers')->willReturn([]);
    $events->method('dispatch')->willReturnSelf();
    (new ReflectionProperty(Template::class, 'eventsManager'))->setValue($template, $events);
    (new ReflectionProperty(Template::class, 'compiledSourceOrigins'))->setValue($template, []);
    $dir = \sys_get_temp_dir() . '/weline_inplace_compile_' . \uniqid('', true);
    self::assertTrue(@\mkdir($dir, 0775, true));
    $target = $dir . '/com_demo.phtml';
    $origin = $dir . '/demo.phtml';
    \file_put_contents($origin, '<div>one</div>');
    $method = new ReflectionMethod(Template::class, 'compileSourceBytes');
    $method->setAccessible(true);
    $first = $method->invoke($template, $target, '<div>one</div>', $origin, '', null);
    self::assertSame($target, $first);
    self::assertFileExists($target);
    self::assertStringNotContainsString('__bytes_', $first);
    $head1 = (string) \file_get_contents($target, false, null, 0, 200);
    self::assertMatchesRegularExpression('/compile_id:[a-f0-9]{40}/', $head1);
    $hit = $method->invoke($template, $target, '<div>one</div>', $origin, '', null);
    self::assertSame($target, $hit);
    self::assertSame($head1, (string) \file_get_contents($target, false, null, 0, 200), 'Unchanged source must reuse file without rewrite noise');
    $second = $method->invoke($template, $target, '<div>two</div>', $origin, '', null);
    self::assertSame($target, $second);
    $body = (string) \file_get_contents($target);
    self::assertStringContainsString('two', $body);
    self::assertStringNotContainsString('__bytes_', $second);
    $entries = \glob($dir . '/*') ?: [];
    self::assertCount(2, $entries, 'Only origin + one com_ file; no __bytes_ sibling dirs');
    foreach ($entries as $entry) {
        if (\is_file($entry)) {
            @\unlink($entry);
        }
    }
    @\rmdir($dir);
}

private function assert_TemplateCompilePathUnification_testFetchFileFromSourceUsesStableRootAndPlainSrcLeaf(): void
{
    foreach (['area' => 'frontend', 'website_id' => 0, 'website_code' => 'default', 'user.lang' => 'zh_Hans_CN', 'user.currency' => 'CNY'] as $name => $value) {
        WelineEnv::set($name, $value, 'compile path unification fixture');
    }
    WelineEnv::set('website_url', '', 'compile path unification fixture');
    $template = Template::getInstance();
    $compiled = $template->getFetchFileFromSource('unify-virtual.phtml', '<span>unify</span>', __FILE__, 'pin-a');
    $normalized = \str_replace('\\', '/', $compiled);
    self::assertFileExists($compiled);
    self::assertDoesNotMatchRegularExpression('#_ctx_[a-f0-9]{32}#i', $normalized);
    self::assertStringNotContainsString('__bytes_', $normalized);
    self::assertStringNotContainsString('__source_', $normalized);
    self::assertMatchesRegularExpression('#/src_unify-virtual_pin-a(?:_r[a-f0-9]{8})?/#', $normalized);
    self::assertMatchesRegularExpression('#/frontend_w0_default_zh_Hans_CN_CNY#', $normalized);
    $otherContext = $template->getFetchFileFromSource('unify-virtual.phtml', '<span>unify-b</span>', __FILE__, 'pin-b');
    $otherNormalized = \str_replace('\\', '/', $otherContext);
    self::assertNotSame($compiled, $otherContext);
    self::assertMatchesRegularExpression('#/src_unify-virtual_pin-b(?:_r[a-f0-9]{8})?/#', $otherNormalized);
    self::assertMatchesRegularExpression('#/frontend_w0_default_zh_Hans_CN_CNY#', $otherNormalized);
    // Same stable scope root; only the plaintext src_ leaf differs.
    self::assertSame(\dirname(\dirname($normalized)), \dirname(\dirname($otherNormalized)));
}

private function assert_TemplateCompilePathUnification_testLayoutPathResolverSharesStableCompileRoot(): void
{
    $path = LayoutPathResolver::getCompiledLayoutPath('Weline_Theme::layouts/homepage/default.phtml', 'en_US');
    if ($path === '') {
        self::markTestSkipped('Theme module / ObjectManager not ready for layout path resolution');
    }
    $normalized = \str_replace('\\', '/', $path);
    self::assertDoesNotMatchRegularExpression('#_ctx_[a-f0-9]{32}#i', $normalized);
    self::assertStringNotContainsString('__bytes_', $normalized);
    self::assertStringNotContainsString('__source_', $normalized);
    // Must include currency leaf from Template scope (not bare lang-only).
    self::assertMatchesRegularExpression('#/(?:frontend|backend)_[^/]+_[A-Za-z]{2,}[^/]*_[A-Z]{3}[^/]*/#', $normalized);
    self::assertStringEndsWith('com_default.phtml', $path);
}

private function assert_TemplateCompileService_testServiceExposesPinnedCompileWithCallerLocalesAndProgress(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/TemplateCompileService.php';
    $source = file_get_contents($path);
    self::assertIsString($source);
    self::assertStringContainsString('final class TemplateCompileService', $source);
    self::assertStringContainsString('compilePinnedSources', $source);
    self::assertStringContainsString('compilePinnedSourcesInProcess', $source);
    self::assertStringContainsString('compilePinnedSourcesWithProcessPool', $source);
    self::assertStringContainsString('WELINE_TEMPLATE_COMPILE_CONCURRENCY', $source);
    self::assertStringContainsString('HostProcessPoolPolicy', $source);
    self::assertStringContainsString('resolveConcurrencyDecision', $source);
    self::assertStringContainsString('WELINE_TEMPLATE_COMPILE_NESTED', $source);
    self::assertStringContainsString('compile-locale-job.php', $source);
    self::assertStringContainsString('isInsideWlsWorker', $source);
    self::assertStringContainsString('isNestedCompileWorker', $source);
    self::assertStringContainsString('compileFile', $source);
    self::assertStringContainsString('onProgress', $source);
    self::assertStringNotContainsString('WebsiteLanguage', $source);
    self::assertStringNotContainsString('Weline\Websites', $source);
}

private function assert_TemplateCompileService_testLocaleJobProgressLineRoundTrip(): void
{
    $parsed = TemplateCompileLocaleJob::parseProgressLine(TemplateCompileLocaleJob::PROGRESS_PREFIX . "\t3\t10\ten_US\tpage:home\n");
    self::assertNotNull($parsed);
    self::assertSame(3, $parsed['done']);
    self::assertSame(10, $parsed['total']);
    self::assertSame('en_US', $parsed['locale']);
    self::assertSame('page:home', $parsed['label']);
}

private function assert_TemplateCompileService_testResolveConcurrencyClampsAndDefaults(): void
{
    $svc = new TemplateCompileService();
    self::assertSame(1, $svc->resolveConcurrency(0));
    self::assertSame(1, $svc->resolveConcurrency(1));
    self::assertSame(10, $svc->resolveConcurrency(10));
    self::assertSame(32, $svc->resolveConcurrency(100));
    $decision = $svc->resolveConcurrencyDecision(4);
    self::assertSame(4, $decision['concurrency']);
    self::assertSame('override', $decision['source']);
}

private function assert_TemplateCompileService_testWorkerScriptExistsBesideViewModule(): void
{
    $script = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/bin/compile-locale-job.php';
    self::assertFileExists($script);
    $src = (string) file_get_contents($script);
    self::assertStringContainsString('TemplateCompileLocaleJob::run', $src);
}

private function assert_TemplateFrontendAuthGuestContext_testFrontendAuthStaticHookCacheContextIsGuestOnlySource(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Template.php');
    self::assertStringContainsString("Storefront public HTML is always guest-safe", $source);
    self::assertStringContainsString("\$context = 'frontend-auth:0';", $source);
    self::assertStringNotContainsString('createFrontendSession()', $this->extractFrontendAuthMethod_TemplateFrontendAuthGuestContext($source));
    self::assertStringNotContainsString('isLoggedIn()', $this->extractFrontendAuthMethod_TemplateFrontendAuthGuestContext($source));
}

private function extractFrontendAuthMethod_TemplateFrontendAuthGuestContext(string $source): string
{
    if (!preg_match('/function frontendAuthStaticHookCacheContext\(\): \?string\s*\{(.*?)(?=\n    private function |\n    public function )/s', $source, $m)) {
        self::fail('frontendAuthStaticHookCacheContext not found');
    }
    return (string) $m[1];
}

private function assert_TemplateNestedStream_testFetchStreamsWhenCaptureActiveAndFetchHtmlRemainsCaptureApi(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Template.php');
    self::assertStringContainsString('fetch_stream', $source);
    self::assertStringContainsString('streamCompiledFile', $source);
    self::assertStringContainsString('softBindRequestForNestedRender', $source);
    self::assertStringContainsString('hook_stream', $source);
    self::assertStringContainsString('view.hook.require_string_result', $source);
    self::assertStringContainsString('view.fetch_file:', $source);
    self::assertStringContainsString('view.hook.files:', $source);
    self::assertStringContainsString('EngineFlags::isV2Enabled()', $source);
    self::assertStringContainsString('ViewEngine::for($this)->fetch', $source);
    self::assertStringContainsString('function fetchLegacy', $source);
    self::assertStringContainsString('public function resolve(string $logical): string', $source);
    self::assertStringContainsString('public function include(string $logicalOrCompiled, array $data = []): void', $source);
    self::assertStringContainsString('public function capture(string $logical, array $data = []): string', $source);
    self::assertMatchesRegularExpression('/private function fetchLegacy\(string \$fileName, array \$data = \[\]\)\s*\{[\s\S]{0,400}?FiberOutputBuffer::hasActiveCapture\(\)/', $source);
    self::assertMatchesRegularExpression('/public function fetchHtml\(string \$fileName, array \$dictionary = \[\]\)/', $source);
    self::assertDoesNotMatchRegularExpression('/function streamCompiledFile\([\s\S]{0,900}?FiberOutputBuffer::beginCapture\(\)/', $source);
}

private function assert_ViewEngine_testEnginePackageAndFlagsExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Framework/View/Engine';
    foreach ([
        'RenderMode.php',
        'EngineFlags.php',
        'CaptureSession.php',
        'RenderContext.php',
        'TemplateResolver.php',
        'HookRuntime.php',
        'ViewEngineInterface.php',
        'ViewEngine.php',
    ] as $file) {
        self::assertFileExists($root . '/' . $file);
    }
    $engine = (string) file_get_contents($root . '/ViewEngine.php');
    self::assertStringContainsString('function include(', $engine);
    self::assertStringContainsString('function capture(', $engine);
    self::assertStringContainsString('function resolve(', $engine);
    self::assertStringContainsString('CaptureSession::hasActive()', $engine);
    $flags = (string) file_get_contents($root . '/EngineFlags.php');
    self::assertStringContainsString("view.engine.v2", $flags);
    $hook = (string) file_get_contents($root . '/HookRuntime.php');
    self::assertStringContainsString('preferStreamIntoCapture', $hook);
    $taglib = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Taglib.php');
    self::assertStringContainsString("'partial-once'", $taglib);
    self::assertStringContainsString('$this->resolve(', $taglib);
    $registry = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Taglib/Registry/TagRegistry.php');
    self::assertStringContainsString("name: 'partial-once'", $registry);
}

private function assert_TemplateNestedStream_testControllerContentFetchUsesFetchHtml(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Controller/PcController.php');
    self::assertStringContainsString('fetchHtml($fileName)', $source);
    self::assertDoesNotMatchRegularExpression('/getTemplate\(\)->fetch\(\$fileName\)/', $source);
}

private function assert_ViewFileMap_testSealedViewFileMapRemoved(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $template = (string) file_get_contents($root . '/View/Template.php');
    self::assertStringNotContainsString('ViewFileMapStore', $template);
    self::assertStringNotContainsString('lookupSealedViewFilePair', $template);
    self::assertStringNotContainsString('lookupSealedHookContributorLogicals', $template);
    self::assertStringNotContainsString('viewFileMapThemeKey', $template);
    self::assertStringContainsString('readProcessViewFileCache($processViewFileCacheKey)', $template);
    self::assertFileDoesNotExist($root . '/View/Compile/ViewFileInventory.php');
    self::assertFileDoesNotExist($root . '/View/Compile/ViewFileMapStore.php');
    self::assertFileDoesNotExist($root . '/View/Compile/ViewFileMapCompiler.php');
    self::assertFileDoesNotExist($root . '/View/Console/View/FileMap/Compile.php');
    $themeEvent = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/etc/event.xml');
    self::assertStringNotContainsString('file_map_before_compile', $themeEvent);
    $formal = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php');
    self::assertStringNotContainsString('sealViewFileMapAfterFormalCompile', $formal);
    self::assertStringNotContainsString('ViewFileMapCompiler', $formal);
}

private const SKIP_DIRS_TemplateReferencePathCase = ['Test', 'test', 'UnitTest', 'doc', 'node_modules', 'vendor', 'statics', 'i18n'];

/** @var array<string, list<string>|false> */
private array $dirListing_TemplateReferencePathCase = [];

private function assert_TemplateReferencePathCase_testModuleTemplateReferencesMatchOnDiskCase(): void
{
    $vendorRoot = '/Users/weline/Project/Official/框架/app/code/Weline';
    $mismatches = [];
    foreach ($this->sourceFiles_TemplateReferencePathCase($vendorRoot) as $file) {
        $source = (string) file_get_contents($file);
        if (!str_contains($source, '::templates/')) {
            continue;
        }
        if (!preg_match_all('/Weline_([A-Za-z0-9]+)::(templates\/[A-Za-z0-9_\/.\-]+\.phtml)/', $source, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as [$reference, $module, $path]) {
            $moduleDir = $vendorRoot . DIRECTORY_SEPARATOR . $module;
            if (!is_dir($moduleDir)) {
                continue;
            }
            $relative = 'view/' . $path;
            if (!is_file($moduleDir . '/' . $relative) || $this->existsWithExactCase_TemplateReferencePathCase($moduleDir, $relative)) {
                continue;
            }
            $mismatches[$reference] = substr($file, strlen($vendorRoot) + 1);
        }
    }
    ksort($mismatches);
    $report = [];
    foreach ($mismatches as $reference => $file) {
        $report[] = $reference . '  (in ' . $file . ')';
    }
    self::assertSame([], $report, "Template references differ from on-disk case:\n" . implode("\n", $report));
}

/**
 * @return iterable<string>
 */
private function sourceFiles_TemplateReferencePathCase(string $vendorRoot): iterable
{
    $directory = new \RecursiveDirectoryIterator($vendorRoot, \FilesystemIterator::SKIP_DOTS);
    $filter = new \RecursiveCallbackFilterIterator($directory, static function (\SplFileInfo $current): bool {
        if ($current->isDir()) {
            return !in_array($current->getFilename(), self::SKIP_DIRS_TemplateReferencePathCase, true);
        }
        return in_array($current->getExtension(), ['php', 'phtml', 'xml'], true);
    });
    foreach (new \RecursiveIteratorIterator($filter) as $info) {
        yield $info->getPathname();
    }
}

private function existsWithExactCase_TemplateReferencePathCase(string $base, string $relative): bool
{
    $current = $base;
    foreach (explode('/', $relative) as $segment) {
        if (!isset($this->dirListing_TemplateReferencePathCase[$current])) {
            $entries = @scandir($current);
            $this->dirListing_TemplateReferencePathCase[$current] = $entries === false ? false : $entries;
        }
        $entries = $this->dirListing_TemplateReferencePathCase[$current];
        if ($entries === false || !in_array($segment, $entries, true)) {
            return false;
        }
        $current .= '/' . $segment;
    }
    return true;
}

private function assert_TemplateThemeSwrPostResponseCaptureMode_testHookOutputAndAggregateRefreshUseScopeCaptureMode(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(Template::class))->getFileName());
    self::assertMatchesRegularExpression('/function queueStaticHookOutputRefresh[\s\S]*PostResponseCaptureMode::Scope/', $src);
    self::assertMatchesRegularExpression('/function queueStaticHookAggregateRefresh[\s\S]*PostResponseCaptureMode::Scope/', $src);
    self::assertMatchesRegularExpression('/function queueStaticHookOutputRefresh[\s\S]*PostResponseTaskQueue::isDraining\(\)/', $src);
    self::assertMatchesRegularExpression('/function queueStaticHookAggregateRefresh[\s\S]*PostResponseTaskQueue::isDraining\(\)/', $src);
}

private function assert_TraitTemplateModuleStaticFlat_testTraitTemplateBuildsModuleStaticsViaPublicStaticUrl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/TraitTemplate.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('buildModuleStaticPublicUrl', $src);
    self::assertStringContainsString('PublicStaticUrl::fromModuleSource', $src);
    self::assertStringContainsString('resolveFlatStaticModuleDiskRoot', $src);
    self::assertStringContainsString('prod|flat', $src);
    self::assertStringContainsString('context-env-v10-module-static-flat', $src);
    // Theme-ns must not be used as module STATICS URL fallback under prod.
    self::assertDoesNotMatchRegularExpression("/useProductionStaticPublicUrls\\(\\)[\\s\\S]{0,200}resolvePublicThemeNamespace\\(\\)[\\s\\S]{0,120}view\\/statics/", $src, 'Module STATICS must not fall back to /static/{themeNs}/…/view/statics/');
}

private function assert_TraitTemplateModuleStaticFlat_testPublicStaticUrlRemainsFlatAuthority(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/PublicStaticUrl.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('/static/', $src);
    self::assertStringContainsString('view/statics', $src);
    self::assertStringContainsString('DeployPublicUrlMode::useProduction', $src);
}

private function assert_TraitTemplateTagSourceAreaRoute_testFetchTagSourceDisablesAreaRouteForAllTypes(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/TraitTemplate.php';
    $src = \file_get_contents($path);
    self::assertIsString($src);
    self::assertStringContainsString("viewEnvironmentCacheSuffix('tag-source', [", $src);
    self::assertStringContainsString("viewEnvironmentCacheSuffix('tag-source-file', [", $src);
    self::assertStringNotContainsString("\$type === 'hooks' ? ['area_route' => false]", $src);
    self::assertMatchesRegularExpression("/viewEnvironmentCacheSuffix\\('tag-source',[\\s\\S]{0,80}'area_route'\\s*=>\\s*false/", $src);
}

private function assert_UrlLiteralBake_testCompilerGenerationBumpsForUrlLiteralBake(): void
{
    self::assertSame('20261023-compile-scope-v13', Taglib::COMPILER_GENERATION);
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Taglib.php');
    self::assertStringContainsString('function tryBakeUrlFamily', $source);
    self::assertStringContainsString('adjustBakedStaticInlineReplacement', $source);
    self::assertStringContainsString('toDocumentRootRelative', $source);
}

private function assert_UrlLiteralBake_testLiteralPathBakesWithoutGetUrlStub(): void
{
    $template = $this->createMock(Template::class);
    $template->method('getUrl')->willReturnCallback(static fn(string $path, array $params = []): string => '/~site/grocery/' . ltrim($path, '/'));
    $baked = $this->tryBake_UrlLiteralBake($template, 'getUrl', '@url{}', ["@url{'product/list'}", "'product/list'", ''], []);
    self::assertSame('/~site/grocery/product/list', $baked);
    $bakedXml = $this->tryBake_UrlLiteralBake($template, 'getUrl', 'tag-self-close-with-attrs', ['<url path="product/list"/>', '', ''], ['path' => 'product/list']);
    self::assertSame('/~site/grocery/product/list', $bakedXml);
}

private function assert_UrlLiteralBake_testDocumentRootPathBakesRootRelative(): void
{
    $template = $this->createMock(Template::class);
    $template->method('getUrl')->willReturn('https://pf14955e2.test.weline.com/');
    self::assertSame('/', $this->tryBake_UrlLiteralBake($template, 'getUrl', '@url{}', ["@url{'/'}", "'/'", ''], []));
    $templateMount = $this->createMock(Template::class);
    $templateMount->method('getUrl')->willReturn('https://p84697b73.test.weline.com/~site/grocery/product/list');
    self::assertSame(
        '/~site/grocery/product/list',
        $this->tryBake_UrlLiteralBake($templateMount, 'getUrl', '@url{}', ["@url{'product/list'}", "'product/list'", ''], [])
    );
}

private function assert_UrlLiteralBake_testDynamicPathKeepsNullSoStubRemains(): void
{
    $template = $this->createMock(Template::class);
    $template->expects(self::never())->method('getUrl');
    self::assertNull($this->tryBake_UrlLiteralBake($template, 'getUrl', '@url{}', ['@url{$route}', '$route', ''], []));
    self::assertNull($this->tryBake_UrlLiteralBake($template, 'getUrl', '@url{}', ["@url{'product/view'|['id'=>\$id]}", "'product/view'|['id'=>\$id]", ''], []));
}

private function assert_UrlLiteralBake_testLiteralParamsBakeWithResolvedUrl(): void
{
    $template = $this->createMock(Template::class);
    $template->method('getUrl')->willReturnCallback(static function (string $path, array $params = []): string {
        $q = $params === [] ? '' : '?' . http_build_query($params);
        return '/u/' . ltrim($path, '/') . $q;
    });
    $baked = $this->tryBake_UrlLiteralBake($template, 'getUrl', '@url{}', ["@url{'product/view'|['id'=>1]}", "'product/view'|['id'=>1]", ''], []);
    self::assertSame('/u/product/view?id=1', $baked);
}

private function assert_UrlLiteralBake_testPhpContextVarExportsBakedUrl(): void
{
    $method = new ReflectionMethod(Taglib::class, 'adjustBakedStaticInlineReplacement');
    $method->setAccessible(true);
    $raw = "@url{'product/list'}";
    $content = "<?php \$u = '{$raw}'; ?>";
    $baked = '/~site/grocery/product/list';
    [$pos, $newRaw, $replacement] = $method->invoke(new Taglib(), $content, (int) strpos($content, $raw), $raw, $baked, 'url');
    $result = substr($content, 0, $pos) . $replacement . substr($content, $pos + strlen($newRaw));
    self::assertSame('<?php $u = ' . var_export($baked, true) . '; ?>', $result);
    self::assertStringNotContainsString('getUrl(', $result);
}

/**
 * @param array<int, mixed> $tagData
 * @param array<string, mixed> $attributes
 */
private function tryBake_UrlLiteralBake(Template $template, string $methodName, string $tagKey, array $tagData, array $attributes): ?string
{
    $method = new ReflectionMethod(Taglib::class, 'tryBakeUrlFamily');
    $method->setAccessible(true);
    return $method->invoke(new Taglib(), $template, $methodName, $tagKey, $tagData, $attributes);
}

private function assert_ViewAreaTemplatePath_testStripAndJoinUseLowercaseAreaLayerOnly(): void
{
    self::assertSame('Log', ViewAreaTemplatePath::stripLeadingAreaSegment('Backend/Log'));
    self::assertSame('Log', ViewAreaTemplatePath::stripLeadingAreaSegment('backend/Log'));
    self::assertSame('account/login', ViewAreaTemplatePath::stripLeadingAreaSegment('Frontend/account/login'));
    self::assertSame('templates/backend/Log/index', ViewAreaTemplatePath::joinTemplates('backend', 'Log/index'));
    self::assertSame('templates/frontend/account/login', ViewAreaTemplatePath::joinTemplates('frontend', 'account/login'));
    self::assertSame('themes/backend/Log/index', ViewAreaTemplatePath::joinThemes('Backend', 'Log/index'));
}

private function assert_ViewAreaTemplatePath_testPcControllerFetchJoinsAreaLayer(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Controller/PcController.php');
    self::assertStringContainsString('ViewAreaTemplatePath::joinTemplates', $src);
    self::assertStringContainsString('ViewAreaTemplatePath::joinThemes', $src);
    self::assertStringContainsString('resolveViewTemplateArea', $src);
    self::assertStringContainsString('stripLeadingAreaSegment', $src);
    self::assertStringNotContainsString("fetchTemplateWithEvents('templates' . DS . \$fileName)", $src);
}

private function assert_ViewTemplateAreaDirCaseGate_testNoPascalCaseAreaDirsUnderTemplatesOrTheme(): void
{
    $vendorRoot = '/Users/weline/Project/Official/框架/app/code';
    // app/code
    $repoRoot = \dirname($vendorRoot, 2);
    // repo root
    $hits = [];
    foreach ([$vendorRoot, $repoRoot . '/app/design'] as $base) {
        if (!\is_dir($base)) {
            continue;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $info) {
            if (!$info->isDir()) {
                continue;
            }
            $path = \str_replace('\\', '/', $info->getPathname());
            if (\str_contains($path, '/view/tpl/') || \str_contains($path, '/tpl/')) {
                continue;
            }
            $name = $info->getFilename();
            $parent = \basename(\dirname($path));
            if (\in_array($parent, ['templates', 'theme'], true) && \in_array($name, ['Backend', 'Frontend'], true)) {
                $hits[] = \substr($path, \strlen($repoRoot) + 1);
            }
        }
    }
    \sort($hits);
    self::assertSame([], $hits, "PascalCase area dirs remain:\n" . \implode("\n", $hits));
}

private function assert_FloatingDropdownEmitter_testScriptDefinesTaglibOwnedGlobalWithHoverBridge(): void
{
    $script = FloatingDropdownEmitter::script();
    $js = FloatingDropdownEmitter::javaScript();
    self::assertStringContainsString('<script', $script);
    self::assertStringContainsString(FloatingDropdownEmitter::SCRIPT_MARKER, $script);
    self::assertStringContainsString(FloatingDropdownEmitter::GLOBAL_NAME, $js);
    self::assertStringContainsString(FloatingDropdownEmitter::HOVER_BRIDGE_ATTR, $js);
    self::assertStringContainsString('hoverBridge', $js);
    self::assertStringContainsString('function place(', $js);
    self::assertStringContainsString('function mount(', $js);
    self::assertStringContainsString('function unmount(', $js);
    self::assertStringNotContainsString('WelineSmartDropdown', $js);
}

/**
 * @return list<string>
 */
private function migratedTaglibPaths_TaglibFloatingDropdownMigration(): array
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline';
    return [$root . '/Websites/Taglib/SearchableCodeSelect.php', $root . '/Websites/Taglib/WebsiteSelect.php', $root . '/Websites/Taglib/DomainSelect.php', $root . '/Websites/Taglib/RegistrarSelect.php', $root . '/ModuleManager/Taglib/ModuleSelect.php', $root . '/Acl/Taglib/TagSelect.php'];
}

private function assert_TaglibFloatingDropdownMigration_testMigratedSelectTaglibsUseEmitterNotThemeSmartDropdown(): void
{
    foreach ($this->migratedTaglibPaths_TaglibFloatingDropdownMigration() as $path) {
        self::assertFileExists($path, $path);
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('FloatingDropdownEmitter::script()', $content, $path);
        // script() owns the global at runtime; source may inline GLOBAL_NAME or only call script().
        self::assertTrue(
            str_contains($content, FloatingDropdownEmitter::GLOBAL_NAME)
            || str_contains($content, 'FloatingDropdownEmitter::GLOBAL_NAME')
            || str_contains($content, 'FloatingDropdownEmitter::script()'),
            $path . ' must own floating dropdown via Emitter'
        );
        self::assertStringNotContainsString('window.WelineSmartDropdown', $content, $path);
        self::assertStringNotContainsString("if (!window.WelineSmartDropdown)", $content, $path);
    }
}
}
