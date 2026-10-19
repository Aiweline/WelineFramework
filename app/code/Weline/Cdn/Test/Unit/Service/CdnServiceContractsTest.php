<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\ApiRule;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\AnnotationPackFlagResolver;
use Weline\Cdn\Service\AnnotationRulePacker;
use Weline\Cdn\Service\BinQueryFidelityEdgeCompressor;
use Weline\Cdn\Service\CdnRuleLane;
use Weline\Cdn\Service\RuleManager;
use Weline\Cdn\Service\WarmupCollectService;
use Weline\Cdn\Service\WarmupLocaleUrlExpander;
use Weline\Cdn\Service\WarmupProviderScanner;
use Weline\Cdn\Service\WarmupRunner;
use Weline\Cdn\WarmupProvider\FpcExtraDeclaredUrls;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\BinQueryCachePolicy;
use Weline\I18n\Service\Seo\LocalizedUrlBuilder;

/**
 * Aggregated contract suite (merged from 12 *ContractTest files).
 * Observe one overall pass/fail via testAllCdnServiceContractsPass().
 */
final class CdnServiceContractsTest extends TestCase
{
    public function testAllCdnServiceContractsPass(): void
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
            'AnnotationRulePacker::testFlagResolverConfigKeyAndDefaultTruthy' => function () {
            $this->assert_AnnotationRulePacker_testFlagResolverConfigKeyAndDefaultTruthy();
        },
            'AnnotationRulePacker::testPackOrPathsMedianTtlAndIdentity' => function () {
            $this->assert_AnnotationRulePacker_testPackOrPathsMedianTtlAndIdentity();
        },
            'AnnotationRulePacker::testCacheableAdminPathRejectedFromPackExpression' => function () {
            $this->assert_AnnotationRulePacker_testCacheableAdminPathRejectedFromPackExpression();
        },
            'AnnotationRulePacker::testCacheFalseOnlyAdminSurfaceEmitsNoPack' => function () {
            $this->assert_AnnotationRulePacker_testCacheFalseOnlyAdminSurfaceEmitsNoPack();
        },
            'AnnotationRulePacker::testCacheableBackendPathRejectedFromPackExpression' => function () {
            $this->assert_AnnotationRulePacker_testCacheableBackendPathRejectedFromPackExpression();
        },
            'AnnotationRulePacker::testTtlRatioSplitProducesTwoApiPacks' => function () {
            $this->assert_AnnotationRulePacker_testTtlRatioSplitProducesTwoApiPacks();
        },
            'AnnotationRulePacker::testSearchCacheFalseEmitsForbidPackNotSpecialTtl' => function () {
            $this->assert_AnnotationRulePacker_testSearchCacheFalseEmitsForbidPackNotSpecialTtl();
        },
            'AnnotationRulePacker::testCacheableSearchAndCheckoutRejectedFromSpecialTtlPack' => function () {
            $this->assert_AnnotationRulePacker_testCacheableSearchAndCheckoutRejectedFromSpecialTtlPack();
        },
            'AnnotationRulePacker::testGuidePaymentNotRejectedAsNeverCacheEdge' => function () {
            $this->assert_AnnotationRulePacker_testGuidePaymentNotRejectedAsNeverCacheEdge();
        },
            'AnnotationRulePacker::testPackerSourceReadsApiRuleClassRouteNotOnlyCloudflareShape' => function () {
            $this->assert_AnnotationRulePacker_testPackerSourceReadsApiRuleClassRouteNotOnlyCloudflareShape();
        },
            'BinQueryFidelityEdgePlan::testPackerSkipsBinQueryFidelityRules' => function () {
            $this->setUp_BinQueryFidelityEdgePlan();
            $this->assert_BinQueryFidelityEdgePlan_testPackerSkipsBinQueryFidelityRules();
        },
            'BinQueryFidelityEdgePlan::testCompressorEmitsPostMarkerAndCacheKey' => function () {
            $this->setUp_BinQueryFidelityEdgePlan();
            $this->assert_BinQueryFidelityEdgePlan_testCompressorEmitsPostMarkerAndCacheKey();
        },
            'BinQueryFidelityEdgePlan::testPlanSeparatesFidelityFromDemoPack' => function () {
            $this->setUp_BinQueryFidelityEdgePlan();
            $this->assert_BinQueryFidelityEdgePlan_testPlanSeparatesFidelityFromDemoPack();
        },
            'CdnAdminQueryGlobalRules::testAdminQueryServiceUsesDefaultRulesMethods' => function () {
            $this->assert_CdnAdminQueryGlobalRules_testAdminQueryServiceUsesDefaultRulesMethods();
        },
            'CdnAdminQueryGlobalRules::testDefaultRulesFileIsNonEmptyArray' => function () {
            $this->assert_CdnAdminQueryGlobalRules_testDefaultRulesFileIsNonEmptyArray();
        },
            'CdnBinQueryDualPathRule::testCollectorEmitsDistinctIdentitiesForBinQueryAndQueryBin' => function () {
            $this->assert_CdnBinQueryDualPathRule_testCollectorEmitsDistinctIdentitiesForBinQueryAndQueryBin();
        },
            'CdnBinQueryDualPathRule::testCloudflareForwardsCacheKeyActionParameter' => function () {
            $this->assert_CdnBinQueryDualPathRule_testCloudflareForwardsCacheKeyActionParameter();
        },
            'CdnRuleCollectorClassLevelCdn::testCollectorInheritsClassLevelCdnWhenMethodLacksAnnotation' => function () {
            $this->assert_CdnRuleCollectorClassLevelCdn_testCollectorInheritsClassLevelCdnWhenMethodLacksAnnotation();
        },
            'CdnRuleCollectorClassLevelCdn::testSearchAndCompareKeepClassLevelCacheFalse' => function () {
            $this->assert_CdnRuleCollectorClassLevelCdn_testSearchAndCompareKeepClassLevelCacheFalse();
        },
            'CloudflareHttpClientI18nArgs::testHttpErrorUsesArrayI18nArgs' => function () {
            $this->assert_CloudflareHttpClientI18nArgs_testHttpErrorUsesArrayI18nArgs();
        },
            'CloudflareMailDnsGlobalKey::testHttpClientExposesApiWithCredentials' => function () {
            $this->assert_CloudflareMailDnsGlobalKey_testHttpClientExposesApiWithCredentials();
        },
            'CloudflareMailDnsGlobalKey::testMailDnsManagerUsesCredentialsNotBearerOnly' => function () {
            $this->assert_CloudflareMailDnsGlobalKey_testMailDnsManagerUsesCredentialsNotBearerOnly();
        },
            'CloudflareMailDnsGlobalKey::testPlannerAllowsAbsentDkimDesiredRecord' => function () {
            $this->assert_CloudflareMailDnsGlobalKey_testPlannerAllowsAbsentDkimDesiredRecord();
        },
            'CloudflareOAuthScopes::testRequiredScopesOmitProtocolOfflineAccess' => function () {
            $this->assert_CloudflareOAuthScopes_testRequiredScopesOmitProtocolOfflineAccess();
        },
            'CloudflareUnifiedAuthTransport::testAdapterMakeRequestDelegatesToHttpClient' => function () {
            $this->assert_CloudflareUnifiedAuthTransport_testAdapterMakeRequestDelegatesToHttpClient();
        },
            'CloudflareUnifiedAuthTransport::testHttpClientIsSoleAuthHeaderBuilderConsumerForApi' => function () {
            $this->assert_CloudflareUnifiedAuthTransport_testHttpClientIsSoleAuthHeaderBuilderConsumerForApi();
        },
            'CloudflareUnifiedAuthTransport::testRegistrarUsesUnifiedClientWhenCdnPresent' => function () {
            $this->assert_CloudflareUnifiedAuthTransport_testRegistrarUsesUnifiedClientWhenCdnPresent();
        },
            'WarmupLocaleUrlExpander::testExpanderUsesLocalizedUrlBuilderAcrossLocales' => function () {
            $this->assert_WarmupLocaleUrlExpander_testExpanderUsesLocalizedUrlBuilderAcrossLocales();
        },
            'WarmupLocaleUrlExpander::testLocalizedUrlBuilderOmitsDefaultLocaleSegment' => function () {
            $this->assert_WarmupLocaleUrlExpander_testLocalizedUrlBuilderOmitsDefaultLocaleSegment();
        },
            'WarmupLocaleUrlExpander::testProvidersExpandLocalesInSource' => function () {
            $this->assert_WarmupLocaleUrlExpander_testProvidersExpandLocalesInSource();
        },
            'WarmupOrchestration::testCollectServiceFiltersByDomainInOrchestrationLayer' => function () {
            $this->assert_WarmupOrchestration_testCollectServiceFiltersByDomainInOrchestrationLayer();
        },
            'WarmupOrchestration::testRunnerDiscardsResponseBodyWithoutReturnTransfer' => function () {
            $this->assert_WarmupOrchestration_testRunnerDiscardsResponseBodyWithoutReturnTransfer();
        },
            'WarmupOrchestration::testCollectDispatchMustNotPassEventObject' => function () {
            $this->assert_WarmupOrchestration_testCollectDispatchMustNotPassEventObject();
        },
            'WarmupOrchestration::testRunnerSkippedDoesNotCountAsSuccess' => function () {
            $this->assert_WarmupOrchestration_testRunnerSkippedDoesNotCountAsSuccess();
        },
            'WarmupOrchestration::testRunnerSqlFiltersIncompleteBeforeLimitWindow' => function () {
            $this->assert_WarmupOrchestration_testRunnerSqlFiltersIncompleteBeforeLimitWindow();
        },
            'WarmupOrchestration::testWarmupUrlHasEnabledDomainIndexAndProvider255' => function () {
            $this->assert_WarmupOrchestration_testWarmupUrlHasEnabledDomainIndexAndProvider255();
        },
            'WarmupOrchestration::testSourceModuleResolutionDocumentedInCollectService' => function () {
            $this->assert_WarmupOrchestration_testSourceModuleResolutionDocumentedInCollectService();
        },
            'WarmupProviderScannerBuiltin::testScanProvidersIncludesBuiltinFpcProvider' => function () {
            $this->assert_WarmupProviderScannerBuiltin_testScanProvidersIncludesBuiltinFpcProvider();
        },
        ];
    }

private function assert_AnnotationRulePacker_testFlagResolverConfigKeyAndDefaultTruthy(): void
{
    self::assertSame('cdn/rules/pack_annotations', AnnotationPackFlagResolver::CONFIG_KEY);
    $resolver = new AnnotationPackFlagResolver(null);
    self::assertTrue($resolver->isEnabled());
}

private function assert_AnnotationRulePacker_testPackOrPathsMedianTtlAndIdentity(): void
{
    $packer = new AnnotationRulePacker();
    $packs = $packer->pack([['enabled' => true, 'module' => 'Weline_Cdn', 'class' => 'Weline\Cdn\Controller\Api\DemoA', 'method' => 'index', 'route' => '/api/a', 'expression' => 'starts_with(http.request.uri.path, "/api/a")', 'action' => ['cache' => ['ttl' => 100, 'status_code' => [200], 'mode' => 'override_origin']]], ['enabled' => true, 'module' => 'Weline_Cdn', 'class' => 'Weline\Cdn\Controller\Api\DemoB', 'method' => 'index', 'route' => '/api/b', 'expression' => 'starts_with(http.request.uri.path, "/api/b")', 'action' => ['cache' => ['ttl' => 300, 'status_code' => [200], 'mode' => 'override_origin']]]], 5);
    self::assertCount(1, $packs);
    $pack = $packs[0];
    self::assertSame('pack:ttl:api:0', $pack['_weline_identity']);
    self::assertStringContainsString('/api/a', (string) $pack['expression']);
    self::assertStringContainsString('/api/b', (string) $pack['expression']);
    self::assertStringContainsString(' or ', (string) $pack['expression']);
    self::assertSame(200, (int) $pack['action']['cache']['ttl']);
    self::assertSame('override_origin', $pack['action']['cache']['mode']);
    self::assertStringStartsWith('Weline pack:ttl:api ttl=200 n=', (string) $pack['description']);
}

private function assert_AnnotationRulePacker_testCacheableAdminPathRejectedFromPackExpression(): void
{
    $packer = new AnnotationRulePacker();
    $packs = $packer->pack([['enabled' => true, 'module' => 'Weline_Backend', 'class' => 'Weline\Foo\Controller\Backend\Dash', 'method' => 'index', 'route' => '/admin/dash', 'expression' => 'starts_with(http.request.uri.path, "/admin/dash")', 'action' => ['cache' => ['ttl' => 600, 'status_code' => [200]]]], ['enabled' => true, 'module' => 'Weline_Store', 'class' => 'Weline\Store\Controller\Frontend\Home', 'method' => 'index', 'route' => '/home', 'expression' => 'starts_with(http.request.uri.path, "/home")', 'action' => ['cache' => ['ttl' => 120, 'status_code' => [200]]]]], 5);
    foreach ($packs as $pack) {
        $expr = \strtolower((string) ($pack['expression'] ?? ''));
        self::assertStringNotContainsString('/admin/', $expr);
        self::assertStringNotContainsString('/backend/', $expr);
    }
    self::assertNotEmpty($packs);
    self::assertStringContainsString('/home', (string) $packs[0]['expression']);
}

private function assert_AnnotationRulePacker_testCacheFalseOnlyAdminSurfaceEmitsNoPack(): void
{
    $packer = new AnnotationRulePacker();
    $packs = $packer->pack([['enabled' => true, 'module' => 'Weline_Backend', 'class' => 'Weline\Foo\Controller\Backend\Dash', 'method' => 'index', 'route' => '/admin/dash', 'expression' => 'starts_with(http.request.uri.path, "/admin/dash")', 'action' => ['cache' => false]]], 5);
    self::assertSame([], $packs);
}

private function assert_AnnotationRulePacker_testCacheableBackendPathRejectedFromPackExpression(): void
{
    $packer = new AnnotationRulePacker();
    $packs = $packer->pack([['enabled' => true, 'module' => 'Weline_Backend', 'class' => 'Weline\Foo\Controller\Backend\Panel', 'method' => 'index', 'route' => '/backend/panel', 'expression' => 'starts_with(http.request.uri.path, "/backend/panel")', 'action' => ['cache' => ['ttl' => 600, 'status_code' => [200]]]], ['enabled' => true, 'module' => 'Weline_Store', 'class' => 'Weline\Store\Controller\Frontend\Catalog', 'method' => 'index', 'route' => '/catalog', 'expression' => 'starts_with(http.request.uri.path, "/catalog")', 'action' => ['cache' => ['ttl' => 90, 'status_code' => [200]]]]], 5);
    foreach ($packs as $pack) {
        $expr = \strtolower((string) ($pack['expression'] ?? ''));
        self::assertStringNotContainsString('/admin/', $expr);
        self::assertStringNotContainsString('/backend/', $expr);
    }
    self::assertNotEmpty($packs);
    self::assertStringContainsString('/catalog', (string) $packs[0]['expression']);
}

private function assert_AnnotationRulePacker_testTtlRatioSplitProducesTwoApiPacks(): void
{
    $packer = new AnnotationRulePacker();
    $packs = $packer->pack([['enabled' => true, 'module' => 'Weline_Cdn', 'class' => 'Weline\Cdn\Controller\Api\Short', 'method' => 'index', 'route' => '/api/short', 'expression' => 'starts_with(http.request.uri.path, "/api/short")', 'action' => ['cache' => ['ttl' => 60, 'status_code' => [200]]]], ['enabled' => true, 'module' => 'Weline_Cdn', 'class' => 'Weline\Cdn\Controller\Api\Long', 'method' => 'index', 'route' => '/api/long', 'expression' => 'starts_with(http.request.uri.path, "/api/long")', 'action' => ['cache' => ['ttl' => 600, 'status_code' => [200]]]]], 5);
    self::assertCount(2, $packs);
    self::assertSame('pack:ttl:api:0', $packs[0]['_weline_identity']);
    self::assertSame('pack:ttl:api:1', $packs[1]['_weline_identity']);
}

private function assert_AnnotationRulePacker_testSearchCacheFalseEmitsForbidPackNotSpecialTtl(): void
{
    $packer = new AnnotationRulePacker();
    $packs = $packer->pack([['enabled' => true, 'module' => 'Weline_Search', 'class' => 'Weline\Search\Controller\Frontend\Index', 'method' => 'index', 'route' => '/search', 'expression' => 'starts_with(http.request.uri.path, "/search")', 'action' => ['cache' => false]], ['enabled' => true, 'module' => 'Weline_Cdn', 'class' => 'Weline\Cdn\Controller\Api\FixtureSpecialTtl', 'method' => 'getProbe', 'route' => '/__fixture_special_ttl_api', 'expression' => 'starts_with(http.request.uri.path, "/__fixture_special_ttl_api")', 'action' => ['cache' => ['ttl' => 900, 'status_code' => [200], 'mode' => 'override_origin']]]], 5);
    $forbid = null;
    $ttl = null;
    foreach ($packs as $pack) {
        $id = (string) ($pack['_weline_identity'] ?? '');
        if (\str_starts_with($id, 'pack:forbid:')) {
            $forbid = $pack;
        }
        if (\str_starts_with($id, 'pack:ttl:')) {
            $ttl = $pack;
        }
    }
    self::assertNotNull($forbid);
    self::assertSame(false, $forbid['action']['cache']);
    self::assertStringContainsString('/search', (string) $forbid['expression']);
    self::assertNotNull($ttl);
    self::assertSame(900, (int) $ttl['action']['cache']['ttl']);
    self::assertStringContainsString('/__fixture_special_ttl_api', (string) $ttl['expression']);
}

private function assert_AnnotationRulePacker_testCacheableSearchAndCheckoutRejectedFromSpecialTtlPack(): void
{
    $packer = new AnnotationRulePacker();
    $packs = $packer->pack([['enabled' => true, 'module' => 'Weline_Search', 'class' => 'Weline\Search\Controller\Frontend\Index', 'method' => 'index', 'route' => '/search', 'expression' => 'starts_with(http.request.uri.path, "/search")', 'action' => ['cache' => ['ttl' => 31536000, 'status_code' => [200]]]], ['enabled' => true, 'module' => 'Weline_Checkout', 'class' => 'Weline\Checkout\Controller\Frontend\Index', 'method' => 'index', 'route' => '/checkout', 'expression' => 'starts_with(http.request.uri.path, "/checkout")', 'action' => ['cache' => ['ttl' => 600, 'status_code' => [200]]]], ['enabled' => true, 'module' => 'Weline_Payment', 'class' => 'Weline\Payment\Controller\Frontend\Success', 'method' => 'index', 'route' => '/payment/success', 'expression' => 'starts_with(http.request.uri.path, "/payment/success")', 'action' => ['cache' => ['ttl' => 600, 'status_code' => [200]]]], ['enabled' => true, 'module' => 'Weline_Catalog', 'class' => 'Weline\Catalog\Controller\Frontend\Product', 'method' => 'view', 'route' => '/product', 'expression' => 'starts_with(http.request.uri.path, "/product")', 'action' => ['cache' => ['ttl' => 900, 'status_code' => [200]]]]], 5);
    self::assertNotEmpty($packs);
    foreach ($packs as $pack) {
        $expr = \strtolower((string) ($pack['expression'] ?? ''));
        $id = (string) ($pack['_weline_identity'] ?? '');
        if (\str_starts_with($id, 'pack:ttl:')) {
            self::assertStringNotContainsString('/search', $expr);
            self::assertStringNotContainsString('/checkout', $expr);
            self::assertStringNotContainsString('/payment', $expr);
        }
    }
    $ttlExpr = '';
    foreach ($packs as $pack) {
        if (\str_starts_with((string) ($pack['_weline_identity'] ?? ''), 'pack:ttl:')) {
            $ttlExpr = (string) $pack['expression'];
        }
    }
    self::assertStringContainsString('/product', $ttlExpr);
}

private function assert_AnnotationRulePacker_testGuidePaymentNotRejectedAsNeverCacheEdge(): void
{
    $packer = new AnnotationRulePacker();
    $packs = $packer->pack([['enabled' => true, 'module' => 'Weline_Cms', 'class' => 'Weline\Cms\Controller\Frontend\Guide', 'method' => 'payment', 'route' => '/guide/payment', 'expression' => 'starts_with(http.request.uri.path, "/guide/payment")', 'action' => ['cache' => ['ttl' => 86400, 'status_code' => [200]]]]], 5);
    self::assertCount(1, $packs);
    self::assertStringContainsString('/guide/payment', (string) $packs[0]['expression']);
}

/** A7：Packer 源码须直接读 ApiRule class/route，禁止仅依赖 toCloudflareRule 猜面。 */
private function assert_AnnotationRulePacker_testPackerSourceReadsApiRuleClassRouteNotOnlyCloudflareShape(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/AnnotationRulePacker.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('ApiRule::schema_fields_CLASS', $src);
    self::assertStringContainsString('ApiRule::schema_fields_ROUTE', $src);
    self::assertStringContainsString('instanceof ApiRule', $src);
    self::assertStringContainsString('禁止只吃 toCloudflareRule', $src);
    // 允许文档提及；禁止调用形态 ->toCloudflareRule(
    self::assertDoesNotMatchRegularExpression('/->\s*toCloudflareRule\s*\(/', $src);
    $rmPath = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/RuleManager.php';
    $rmSrc = (string) \file_get_contents($rmPath);
    self::assertStringContainsString('getEnabledApiRuleModels', $rmSrc);
    self::assertStringContainsString('禁止仅 toCloudflareRule', $rmSrc);
}

private function setUp_BinQueryFidelityEdgePlan(): void
{
    parent::setUp();
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架' . \DIRECTORY_SEPARATOR);
    }
}

private function assert_BinQueryFidelityEdgePlan_testPackerSkipsBinQueryFidelityRules(): void
{
    $packer = new AnnotationRulePacker();
    $packs = $packer->pack([['enabled' => true, 'module' => 'Weline_I18n', 'class' => 'Weline\Frontend\Controller\Api\BinQuery::i18n', 'method' => 'getLanguageSwitcherCatalog', 'route' => '/bin/query', 'lane' => CdnRuleLane::PREDICATE_FIDELITY, 'expression' => 'http.request.uri.path eq "/bin/query" and http.request.method eq "POST"' . ' and http.request.uri.query contains "' . BinQueryCachePolicy::MARKER_PARAM . '=wq1.frontend.i18n.op."', 'action' => ['cache' => ['ttl' => 3600, 'status_code' => [200], 'mode' => 'override_origin', 'cache_key' => ['custom_key' => ['query_string' => ['include' => [BinQueryCachePolicy::MARKER_PARAM]]]]]]], ['enabled' => true, 'module' => 'Weline_Cdn', 'class' => 'Weline\Cdn\Controller\Api\FixtureSpecialTtl', 'method' => 'index', 'route' => '/api/__fixture_special_ttl_api', 'lane' => CdnRuleLane::PATH_PACKABLE, 'expression' => 'starts_with(http.request.uri.path, "/api/__fixture_special_ttl_api")', 'action' => ['cache' => ['ttl' => 900, 'status_code' => [200], 'mode' => 'override_origin']]]], 5);
    self::assertCount(1, $packs);
    $expr = (string) ($packs[0]['expression'] ?? '');
    self::assertStringContainsString('__fixture_special_ttl_api', $expr);
    self::assertStringNotContainsString('/bin/query', $expr);
    self::assertStringNotContainsString('query-bin', $expr);
}

private function assert_BinQueryFidelityEdgePlan_testCompressorEmitsPostMarkerAndCacheKey(): void
{
    $compressor = new BinQueryFidelityEdgeCompressor();
    $edge = $compressor->compress([['enabled' => true, 'lane' => CdnRuleLane::PREDICATE_FIDELITY, 'expression' => 'http.request.uri.path eq "/bin/query" and http.request.method eq "POST"' . ' and http.request.uri.query contains "' . BinQueryCachePolicy::MARKER_PARAM . '=wq1.frontend.a.op."', 'action' => ['cache' => ['ttl' => 100, 'status_code' => [200], 'mode' => 'override_origin']]], ['enabled' => true, 'lane' => CdnRuleLane::PREDICATE_FIDELITY, 'expression' => 'http.request.uri.path eq "/api/framework/query-bin" and http.request.method eq "POST"' . ' and http.request.uri.query contains "' . BinQueryCachePolicy::MARKER_PARAM . '=wq1.frontend.a.op."', 'action' => ['cache' => ['ttl' => 300, 'status_code' => [200], 'mode' => 'override_origin']]]]);
    self::assertCount(1, $edge);
    $rule = $edge[0];
    self::assertSame('fidelity:binquery:0', $rule['_weline_identity']);
    $expr = (string) $rule['expression'];
    self::assertStringContainsString('http.request.method eq "POST"', $expr);
    self::assertStringContainsString('http.request.uri.query contains "' . BinQueryCachePolicy::MARKER_PARAM . '="', $expr);
    self::assertStringContainsString('http.request.uri.path eq "/bin/query"', $expr);
    self::assertStringContainsString('http.request.uri.path eq "/api/framework/query-bin"', $expr);
    self::assertStringNotContainsString('starts_with', $expr);
    self::assertSame(200, (int) $rule['action']['cache']['ttl']);
    // Free Cache Rules 拒收 custom cache_key；planned 边缘仅靠 expression marker。
    self::assertArrayNotHasKey('cache_key', $rule['action']['cache']);
}

private function assert_BinQueryFidelityEdgePlan_testPlanSeparatesFidelityFromDemoPack(): void
{
    $binQuery = $this->mockApiRule_BinQueryFidelityEdgePlan([ApiRule::schema_fields_ENABLED => 1, ApiRule::schema_fields_MODULE => 'Weline_I18n', ApiRule::schema_fields_CLASS => 'Weline\Frontend\Controller\Api\BinQuery::i18n', ApiRule::schema_fields_METHOD => 'getLanguageSwitcherCatalog', ApiRule::schema_fields_ROUTE => '/bin/query', ApiRule::schema_fields_LANE => CdnRuleLane::PREDICATE_FIDELITY, ApiRule::schema_fields_EXPRESSION => 'http.request.uri.path eq "/bin/query" and http.request.method eq "POST"' . ' and http.request.uri.query contains "' . BinQueryCachePolicy::MARKER_PARAM . '=wq1.frontend.i18n.op."', ApiRule::schema_fields_DESCRIPTION => 'BinQuery i18n [/bin/query]'], ['cache' => ['ttl' => 3600, 'status_code' => [200], 'mode' => 'override_origin', 'cache_key' => ['custom_key' => ['query_string' => ['include' => [BinQueryCachePolicy::MARKER_PARAM]]]]]]);
    $specialTtl = $this->mockApiRule_BinQueryFidelityEdgePlan([ApiRule::schema_fields_ENABLED => 1, ApiRule::schema_fields_MODULE => 'Weline_Cdn', ApiRule::schema_fields_CLASS => 'Weline\Cdn\Controller\Api\FixtureSpecialTtl', ApiRule::schema_fields_METHOD => 'index', ApiRule::schema_fields_ROUTE => '/api/__fixture_special_ttl_api', ApiRule::schema_fields_LANE => CdnRuleLane::PATH_PACKABLE, ApiRule::schema_fields_EXPRESSION => 'starts_with(http.request.uri.path, "/api/__fixture_special_ttl_api")', ApiRule::schema_fields_DESCRIPTION => 'FIXTURE_SPECIAL_TTL_API'], ['cache' => ['ttl' => 900, 'status_code' => [200], 'mode' => 'override_origin']]);
    $manager = $this->newManagerWithApiRules_BinQueryFidelityEdgePlan([$binQuery, $specialTtl]);
    $domain = $this->freeDomain_BinQueryFidelityEdgePlan();
    $planned = $manager->planRulesForEdgePush($domain, null, true);
    $fidelityExprs = [];
    $packExprs = [];
    foreach ($planned as $rule) {
        if (!\is_array($rule)) {
            continue;
        }
        $identity = (string) ($rule['_weline_identity'] ?? '');
        $expr = (string) ($rule['expression'] ?? '');
        if (\str_starts_with($identity, 'fidelity:')) {
            $fidelityExprs[] = $expr;
            self::assertStringContainsString('POST', $expr);
            self::assertStringContainsString(BinQueryCachePolicy::MARKER_PARAM . '=', $expr);
            self::assertStringNotContainsString('starts_with(http.request.uri.path, "/bin/query")', $expr);
        }
        if (\str_starts_with($identity, 'pack:')) {
            $packExprs[] = $expr;
            self::assertStringNotContainsString('/bin/query', $expr);
            self::assertStringNotContainsString('query-bin', $expr);
        }
        // No planned rule may path-OR bin/query with starts_with
        if (\str_contains($expr, 'starts_with(http.request.uri.path, "/bin/query")')) {
            self::fail('planned must not emit bare starts_with(/bin/query)');
        }
        if (\str_contains($expr, 'starts_with(http.request.uri.path, "/api/framework/query-bin")')) {
            self::fail('planned must not emit bare starts_with(/api/framework/query-bin)');
        }
    }
    self::assertNotEmpty($fidelityExprs, 'plan must include fidelity:binquery edge');
    self::assertNotEmpty($packExprs, 'plan must still pack special-TTL path annotation');
}

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed> $action
 */
private function mockApiRule_BinQueryFidelityEdgePlan(array $data, array $action): ApiRule
{
    $apiRule = $this->createMock(ApiRule::class);
    $apiRule->method('getData')->willReturnCallback(static fn(string $key) => $data[$key] ?? null);
    $apiRule->method('getActionArray')->willReturn($action);
    return $apiRule;
}

private function freeDomain_BinQueryFidelityEdgePlan(): Domain
{
    $domain = $this->createMock(Domain::class);
    $domain->method('getCredentialsArray')->willReturn([]);
    $domain->method('getRulesOverrideArray')->willReturn([]);
    $domain->method('getId')->willReturn(1);
    $domain->method('getData')->willReturnCallback(static fn(string $key) => match ($key) {
        Domain::schema_fields_ADAPTER => 'cloudflare',
        Domain::schema_fields_ZONE_ID => 'zone-1',
        Domain::schema_fields_ACCOUNT_ID => null,
        default => null,
    });
    $domain->method('isInheritDefault')->willReturn(false);
    return $domain;
}

/**
 * @param list<ApiRule> $apiRuleItems
 */
private function newManagerWithApiRules_BinQueryFidelityEdgePlan(array $apiRuleItems): RuleManager
{
    $om = $this->createMock(ObjectManager::class);
    $om->method('getInstance')->willReturn(null);
    $resolver = $this->createMock(AdapterResolver::class);
    $accounts = $this->createMock(AccountManager::class);
    $flag = new class extends AnnotationPackFlagResolver
    {
        public function __construct()
        {
            parent::__construct(null);
        }
        public function isEnabled(?\Weline\Framework\Runtime\ScopeIdentity $identity = null): bool
        {
            return true;
        }
    };
    return new class($om, $resolver, $accounts, $flag, $apiRuleItems) extends RuleManager
    {
        /** @param list<ApiRule> $injectedApiRules */
        public function __construct(ObjectManager $objectManager, AdapterResolver $adapterResolver, AccountManager $accountManager, AnnotationPackFlagResolver $annotationPackFlagResolver, private readonly array $injectedApiRules)
        {
            parent::__construct($objectManager, $adapterResolver, $accountManager, $annotationPackFlagResolver, new AnnotationRulePacker(), new BinQueryFidelityEdgeCompressor());
        }
        protected function getEnabledApiRuleModels(?string $triggerType = null): array
        {
            return $this->injectedApiRules;
        }
    };
}

private function assert_CdnAdminQueryGlobalRules_testAdminQueryServiceUsesDefaultRulesMethods(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/CdnAdminQueryService.php');
    self::assertStringContainsString('function getGlobalRules', $source);
    self::assertStringContainsString('function saveGlobalRules', $source);
    self::assertStringContainsString('$manager->getDefaultRules()', $source);
    self::assertStringContainsString('$manager->saveDefaultRules($rules)', $source);
    self::assertStringNotContainsString("method_exists(\$manager, 'getGlobalRules')", $source);
    self::assertStringNotContainsString("method_exists(\$manager, 'saveGlobalRules')", $source);
}

private function assert_CdnAdminQueryGlobalRules_testDefaultRulesFileIsNonEmptyArray(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/etc/default-rules.json';
    self::assertFileExists($path);
    $rules = \json_decode((string) \file_get_contents($path), true);
    self::assertIsArray($rules);
    self::assertGreaterThanOrEqual(1, \count($rules));
}

private function assert_CdnBinQueryDualPathRule_testCollectorEmitsDistinctIdentitiesForBinQueryAndQueryBin(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/CdnRuleCollector.php');
    self::assertStringContainsString('/bin/query', $source);
    self::assertStringContainsString('/api/framework/query-bin', $source);
    self::assertStringContainsString('pathBindings', $source);
    self::assertStringContainsString('BinQuery::', $source);
    self::assertStringContainsString('QueryBin::', $source);
    self::assertStringContainsString('BinQueryCachePolicy::MARKER_PARAM', $source);
    self::assertStringContainsString('cache_key', $source);
    self::assertStringContainsString('PREDICATE_FIDELITY', $source);
    self::assertStringContainsString('Weline\\\\Frontend\\\\Controller\\\\Api\\\\BinQuery::', $source);
    self::assertStringContainsString('Weline\\\\Frontend\\\\Controller\\\\Api\\\\QueryBin::', $source);
}

private function assert_CdnBinQueryDualPathRule_testCloudflareForwardsCacheKeyActionParameter(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Adapter/Cloudflare.php');
    self::assertStringContainsString("\$params['cache_key'] = \$cfg['cache_key']", $source);
}

private function assert_CdnRuleCollectorClassLevelCdn_testCollectorInheritsClassLevelCdnWhenMethodLacksAnnotation(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/CdnRuleCollector.php');
    self::assertStringContainsString('classHasCdn', $src);
    self::assertStringContainsString('getDeclaringClass()', $src);
    self::assertMatchesRegularExpression('/\$docComment\s*=\s*\$classDoc/', $src);
    self::assertStringContainsString('resolvePublicRoute', $src);
    self::assertStringContainsString('extractExtraPublicPathPatterns', $src);
}

private function assert_CdnRuleCollectorClassLevelCdn_testSearchAndCompareKeepClassLevelCacheFalse(): void
{
    $root = '/Users/weline/Project/Official/框架';
    $search = (string) \file_get_contents($root . '/app/code/Weline/Search/Controller/Frontend/Index.php');
    $compare = (string) \file_get_contents($root . '/app/code/Weline/Compare/Controller/Frontend/Index.php');
    self::assertMatchesRegularExpression('/@Cdn\s+cache=false/', $search);
    self::assertMatchesRegularExpression('/@Cdn\s+cache=false/', $compare);
}

private function assert_CloudflareHttpClientI18nArgs_testHttpErrorUsesArrayI18nArgs(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Cdn/Service/CloudflareHttpClient.php');
    self::assertStringContainsString("'Cloudflare 请求失败（HTTP %{1}，代码 %{2}）。'", $src);
    self::assertStringContainsString('[$status, $this->publicErrorCode($decoded)]', $src);
    self::assertStringNotContainsString("'Cloudflare 请求失败（HTTP %{1}，代码 %{2}）。',\n                    \$status,\n                    \$this->publicErrorCode(\$decoded),", $src);
}

private function assert_CloudflareMailDnsGlobalKey_testHttpClientExposesApiWithCredentials(): void
{
    $http = (string) file_get_contents(BP . 'app/code/Weline/Cdn/Service/CloudflareHttpClient.php');
    self::assertStringContainsString('function apiWithCredentials', $http);
    self::assertStringContainsString('Cloudflare::buildAuthHeaders', $http);
    self::assertStringContainsString('Cloudflare::hasUsableCredentials', $http);
}

private function assert_CloudflareMailDnsGlobalKey_testMailDnsManagerUsesCredentialsNotBearerOnly(): void
{
    $mgr = (string) file_get_contents(BP . 'app/code/Weline/Cdn/Service/CloudflareMailDnsManager.php');
    self::assertStringContainsString('apiWithCredentials', $mgr);
    self::assertStringContainsString('hasUsableCredentials', $mgr);
    self::assertStringNotContainsString("\$token = trim((string)(\$credentials['api_token'] ?? ''));", $mgr);
    self::assertStringContainsString('array_slice($labels, $i)', $mgr);
    self::assertStringContainsString('已尝试：%{1}', $mgr);
    self::assertStringContainsString('catch (\\Throwable $e)', $mgr);
}

private function assert_CloudflareMailDnsGlobalKey_testPlannerAllowsAbsentDkimDesiredRecord(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Cdn/Service/CloudflareMailDnsPlanner.php');
    self::assertStringContainsString("\$wanted === [] && (\$label === 'DKIM'", $src);
    self::assertStringContainsString("\$label === 'DKIM'", $src);
    self::assertStringContainsString('foreach ($wanted as $wantedRecord)', $src);
}

private function assert_CloudflareOAuthScopes_testRequiredScopesOmitProtocolOfflineAccess(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/CloudflareOAuthService.php');
    self::assertMatchesRegularExpression("/REQUIRED_SCOPES\\s*=\\s*\\[[^\\]]*zone\\.read[^\\]]*dns\\.write[^\\]]*cache\\.purge[^\\]]*cache-settings\\.write[^\\]]*\\]/s", $source);
    self::assertStringContainsString('PROTOCOL_SCOPES', $source);
    self::assertStringContainsString("'offline_access'", $source);
    // offline_access must live in PROTOCOL_SCOPES strip list, not REQUIRED_SCOPES.
    if (preg_match('/private const REQUIRED_SCOPES = \[(.*?)\];/s', $source, $m) !== 1) {
        self::fail('REQUIRED_SCOPES block missing');
    }
    self::assertStringNotContainsString('offline_access', $m[1]);
    self::assertStringContainsString('in_array($scope, self::PROTOCOL_SCOPES', $source);
}

private function assert_CloudflareUnifiedAuthTransport_testAdapterMakeRequestDelegatesToHttpClient(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Cdn/Adapter/Cloudflare.php');
    self::assertStringContainsString('CloudflareHttpClient::class', $src);
    self::assertStringContainsString('apiWithCredentials', $src);
    self::assertStringContainsString('统一走 CloudflareHttpClient', $src);
    self::assertStringNotContainsString('curl_init($url)', $src);
}

private function assert_CloudflareUnifiedAuthTransport_testHttpClientIsSoleAuthHeaderBuilderConsumerForApi(): void
{
    $http = (string) file_get_contents(BP . 'app/code/Weline/Cdn/Service/CloudflareHttpClient.php');
    self::assertStringContainsString('function apiWithCredentials', $http);
    self::assertStringContainsString('Cloudflare::buildAuthHeaders', $http);
    self::assertStringContainsString('Cloudflare::hasUsableCredentials', $http);
}

private function assert_CloudflareUnifiedAuthTransport_testRegistrarUsesUnifiedClientWhenCdnPresent(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Websites/Adapter/CloudflareRegistrar.php');
    self::assertStringContainsString('CloudflareHttpClient::class', $src);
    self::assertStringContainsString('hasUsableCredentials', $src);
    self::assertStringContainsString('apiWithCredentials', $src);
    self::assertStringContainsString('normalizeCloudflareCredentials', $src);
    self::assertStringContainsString('buildFallbackAuthHeaders', $src);
    self::assertStringContainsString('resolveFallbackAuthMode', $src);
    self::assertStringContainsString('X-Auth-Key:', $src);
}

private function assert_WarmupLocaleUrlExpander_testExpanderUsesLocalizedUrlBuilderAcrossLocales(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/WarmupLocaleUrlExpander.php';
    $src = (string) file_get_contents($path);
    $this->assertStringContainsString('LocalizedUrlBuilderInterface', $src);
    $this->assertStringContainsString('getWebsiteLanguageCodes', $src);
    $this->assertStringContainsString('WebsiteData::defaultLanguageForWebsite', $src);
    $this->assertStringContainsString('WebsiteData::defaultCurrencyForWebsite', $src);
    $this->assertStringNotContainsString('Website::schema_fields_ID', $src);
    $this->assertStringContainsString('expandRoute', $src);
    $this->assertStringContainsString('Visitor', $src);
    // documents deferred heat ranking
}

private function assert_WarmupLocaleUrlExpander_testLocalizedUrlBuilderOmitsDefaultLocaleSegment(): void
{
    $builder = new LocalizedUrlBuilder();
    $default = $builder->build('https://example.com', '/product/demo', 'zh_Hans_CN', 'zh_Hans_CN', null, 'CNY');
    $en = $builder->build('https://example.com', '/product/demo', 'en_US', 'zh_Hans_CN', null, 'CNY');
    $this->assertSame('https://example.com/product/demo', $default);
    $this->assertSame('https://example.com/en_US/product/demo', $en);
}

private function assert_WarmupLocaleUrlExpander_testProvidersExpandLocalesInSource(): void
{
    $fpc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/WarmupProvider/FpcExtraDeclaredUrls.php');
    $this->assertStringContainsString('WarmupLocaleUrlExpander', $fpc);
    $this->assertStringContainsString('覆盖站点已启用语种', $fpc);
    $product = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/extends/module/Weline_Cdn/ProductHeatUrls.php');
    $this->assertStringContainsString('WarmupLocaleUrlExpander', $product);
    $this->assertStringContainsString('站点已启用语种', $product);
}

private function assert_WarmupOrchestration_testCollectServiceFiltersByDomainInOrchestrationLayer(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/WarmupCollectService.php';
    $src = (string) file_get_contents($path);
    $this->assertStringContainsString('collectProvider(string $providerFqcn, ?int $domainId = null, ?int $siteIdFilter', $src);
    $this->assertStringContainsString('PER_PROVIDER_CAP', $src);
    $this->assertStringContainsString("'provider' => \$providerFqcn", $src);
    $this->assertStringContainsString('filtered_count', $src);
    $this->assertStringNotContainsString('provider=scanner', $src);
    $this->assertStringContainsString("dispatch('Weline_Cdn::send_warmup', \$eventData)", $src);
    $this->assertStringContainsString("\$eventData['result']", $src);
    $this->assertStringNotContainsString('new Event(', $src);
    $this->assertStringContainsString('DISPATCH_CHUNK', $src);
    $this->assertStringContainsString('array_chunk($accepted, self::DISPATCH_CHUNK)', $src);
}

private function assert_WarmupOrchestration_testRunnerDiscardsResponseBodyWithoutReturnTransfer(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/WarmupRunner.php';
    $src = (string) file_get_contents($path);
    $this->assertStringContainsString('CURLOPT_WRITEFUNCTION', $src);
    $this->assertStringContainsString('CURLOPT_RETURNTRANSFER => false', $src);
    $this->assertStringNotContainsString('CURLOPT_RETURNTRANSFER => true', $src);
    $this->assertStringContainsString('discard body', $src);
}

private function assert_WarmupOrchestration_testCollectDispatchMustNotPassEventObject(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/WarmupCollectService.php';
    $src = (string) file_get_contents($path);
    $this->assertStringNotContainsString('use Weline\Framework\Event\Event;', $src);
    $this->assertStringNotContainsString('$event->getData(', $src);
}

private function assert_WarmupOrchestration_testRunnerSkippedDoesNotCountAsSuccess(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/WarmupRunner.php';
    $src = (string) file_get_contents($path);
    $this->assertStringContainsString("\$outcome === 'skipped'", $src);
    $this->assertStringContainsString('$skipped++', $src);
    $this->assertStringContainsString("'skipped' => \$skipped", $src);
    $skippedPos = strpos($src, "\$outcome === 'skipped'");
    $successPos = strpos($src, "\$outcome === 'success'");
    $this->assertNotFalse($skippedPos);
    $this->assertNotFalse($successPos);
    $this->assertLessThan($successPos, (int) $skippedPos);
    $this->assertTrue(method_exists(WarmupRunner::class, 'run'));
    $ref = new \ReflectionMethod(WarmupRunner::class, 'run');
    $this->assertCount(4, $ref->getParameters());
}

private function assert_WarmupOrchestration_testRunnerSqlFiltersIncompleteBeforeLimitWindow(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/WarmupRunner.php';
    $src = (string) file_get_contents($path);
    $this->assertStringContainsString('schema_fields_PROCESSED_COUNT', $src);
    $this->assertStringContainsString('schema_fields_TARGET_COUNT', $src);
    $this->assertStringContainsString('->additional(', $src);
    $this->assertStringContainsString("['wls.host', 'server.host']", $src);
    $this->assertStringContainsString("['wls.port', 'server.port'", $src);
    $this->assertStringContainsString('resolveFetchUrl', $src);
    $this->assertStringContainsString('CURLOPT_SSL_VERIFYPEER', $src);
    $this->assertStringContainsString('localDevFetch', $src);
    $this->assertTrue(method_exists(WarmupRunner::class, 'resolveFetchUrl'));
}

private function assert_WarmupOrchestration_testWarmupUrlHasEnabledDomainIndexAndProvider255(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Model/WarmupUrl.php';
    $src = (string) file_get_contents($path);
    $this->assertStringContainsString('idx_enabled_domain', $src);
    $this->assertStringContainsString("['enabled', 'domain_id']", $src);
    $this->assertStringContainsString("#[Col('varchar', 255, nullable: false, comment: '提供者FQCN')]", $src);
}

private function assert_WarmupOrchestration_testSourceModuleResolutionDocumentedInCollectService(): void
{
    $svc = new \ReflectionClass(WarmupCollectService::class);
    $this->assertTrue($svc->hasMethod('sourceModuleForFqcn'));
    $this->assertTrue($svc->hasMethod('collectAllProviders'));
}

private function assert_WarmupProviderScannerBuiltin_testScanProvidersIncludesBuiltinFpcProvider(): void
{
    $scanner = \Weline\Framework\Manager\ObjectManager::getInstance(WarmupProviderScanner::class);
    $providers = $scanner->scanProviders(true);
    $this->assertContains(FpcExtraDeclaredUrls::class, $providers);
}
}
