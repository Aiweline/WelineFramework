<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Context;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\ConfigCacheInvalidationFeedback;
use Weline\SystemConfig\Service\ConfigFieldCacheNamespaceResolver;
use Weline\SystemConfig\Service\ScopeConfigCacheInvalidator;
use Weline\SystemConfig\Service\SystemConfigCenterService;

/**
 * Aggregated contract suite (merged from 8 *ContractTest files).
 * Observe one overall pass/fail via testAllSystemConfigServiceContractsPass().
 */
final class SystemConfigServiceContractsTest extends TestCase
{
    public function testAllSystemConfigServiceContractsPass(): void
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
            'ConfigCacheInvalidationEmitOnly::testInvalidationServiceBuildsCacheOpsAndDoesNotDeleteSharedPoolsInline' => function () {
            $this->assert_ConfigCacheInvalidationEmitOnly_testInvalidationServiceBuildsCacheOpsAndDoesNotDeleteSharedPoolsInline();
        },
            'ConfigCacheInvalidationEmitOnly::testPublisherAcceptsCacheOpsAndFieldNamespaces' => function () {
            $this->assert_ConfigCacheInvalidationEmitOnly_testPublisherAcceptsCacheOpsAndFieldNamespaces();
        },
            'ConfigCacheInvalidationEmitOnly::testBuildCacheOpsDecoratesPreBumpFingerprintedKeys' => function () {
            $this->assert_ConfigCacheInvalidationEmitOnly_testBuildCacheOpsDecoratesPreBumpFingerprintedKeys();
        },
            'ConfigCacheInvalidationEmitOnly::testConfigCenterFormCollectsControlCacheNamespaces' => function () {
            $this->assert_ConfigCacheInvalidationEmitOnly_testConfigCenterFormCollectsControlCacheNamespaces();
        },
            'ConfigCacheInvalidationFeedback::testBuildSummaryUsesFriendlyLayeredCopy' => function () {
            $this->assert_ConfigCacheInvalidationFeedback_testBuildSummaryUsesFriendlyLayeredCopy();
        },
            'ConfigCacheInvalidationFeedback::testFormatSavePartsSeparatesTitleAndBody' => function () {
            $this->assert_ConfigCacheInvalidationFeedback_testFormatSavePartsSeparatesTitleAndBody();
        },
            'ConfigCacheInvalidationFeedback::testControllerAndEmbedUseServerMessage' => function () {
            $this->assert_ConfigCacheInvalidationFeedback_testControllerAndEmbedUseServerMessage();
        },
            'ConfigFieldCacheNamespaceResolver::testDeclarationNormalizesLeafPrefixAndStarSugar' => function () {
            $this->setUp_ConfigFieldCacheNamespaceResolver();
            $this->assert_ConfigFieldCacheNamespaceResolver_testDeclarationNormalizesLeafPrefixAndStarSugar();
        },
            'ConfigFieldCacheNamespaceResolver::testRequestWithoutDeclarationRejectsArbitraryGlobal' => function () {
            $this->setUp_ConfigFieldCacheNamespaceResolver();
            $this->assert_ConfigFieldCacheNamespaceResolver_testRequestWithoutDeclarationRejectsArbitraryGlobal();
        },
            'ConfigFieldCacheNamespaceResolver::testDeclarationWinsOverIncompatibleRequest' => function () {
            $this->setUp_ConfigFieldCacheNamespaceResolver();
            $this->assert_ConfigFieldCacheNamespaceResolver_testDeclarationWinsOverIncompatibleRequest();
        },
            'ConfigFieldCacheNamespaceResolver::testKeyBindingMapsCaptchaKeys' => function () {
            $this->setUp_ConfigFieldCacheNamespaceResolver();
            $this->assert_ConfigFieldCacheNamespaceResolver_testKeyBindingMapsCaptchaKeys();
        },
            'ConfigFieldCacheNamespaceResolver::testControlReinforcesDeclaredParentChild' => function () {
            $this->setUp_ConfigFieldCacheNamespaceResolver();
            $this->assert_ConfigFieldCacheNamespaceResolver_testControlReinforcesDeclaredParentChild();
        },
            'ConfigFieldCacheNamespaceResolver::testBindKeyPrefixesAttributeActivatesRule' => function () {
            $this->setUp_ConfigFieldCacheNamespaceResolver();
            $this->assert_ConfigFieldCacheNamespaceResolver_testBindKeyPrefixesAttributeActivatesRule();
        },
            'ScopeConfigVersionVectorContext::testBumpRefreshesMemoizedDescendantsButKeepsOtherWebsites' => function () {
            $this->setUp_ScopeConfigVersionVectorContext();
            try {
                $this->assert_ScopeConfigVersionVectorContext_testBumpRefreshesMemoizedDescendantsButKeepsOtherWebsites();
            } finally {
                $this->tearDown_ScopeConfigVersionVectorContext();
            }
        },
            'ScopeConfigVersionVectorContext::testGlobalBumpRefreshesDefaultWebsiteSentinelAndStore' => function () {
            $this->setUp_ScopeConfigVersionVectorContext();
            try {
                $this->assert_ScopeConfigVersionVectorContext_testGlobalBumpRefreshesDefaultWebsiteSentinelAndStore();
            } finally {
                $this->tearDown_ScopeConfigVersionVectorContext();
            }
        },
            'SecurityHeaderProcessCache::testOverrideProviderOwnsProcessBag' => function () {
            $this->assert_SecurityHeaderProcessCache_testOverrideProviderOwnsProcessBag();
        },
            'SecurityHeaderProcessCache::testProcessCacheResetterClearsSecurityOverrideBag' => function () {
            $this->assert_SecurityHeaderProcessCache_testProcessCacheResetterClearsSecurityOverrideBag();
        },
            'SystemConfigMultiselectInOptions::testInOptionsAcceptsMultiselectArrayWithinOptions' => function () {
            $this->assert_SystemConfigMultiselectInOptions_testInOptionsAcceptsMultiselectArrayWithinOptions();
        },
            'SystemConfigMultiselectInOptions::testInOptionsRejectsUnknownMultiselectItem' => function () {
            $this->assert_SystemConfigMultiselectInOptions_testInOptionsRejectsUnknownMultiselectItem();
        },
            'SystemConfigMultiselectInOptions::testInOptionsNoLongerFailsOnArrayCastToStringArray' => function () {
            $this->assert_SystemConfigMultiselectInOptions_testInOptionsNoLongerFailsOnArrayCastToStringArray();
        },
            'SystemConfigMultiselectInOptions::testRequiredRejectsEmptyMultiselectArray' => function () {
            $this->assert_SystemConfigMultiselectInOptions_testRequiredRejectsEmptyMultiselectArray();
        },
            'SystemConfigNavSearchIndexService::testServiceBuildsSearchableConfigFieldHitsWithDeeplinkShape' => function () {
            $this->assert_SystemConfigNavSearchIndexService_testServiceBuildsSearchableConfigFieldHitsWithDeeplinkShape();
        },
            'SystemConfigNavSearchIndexService::testBackendSearchProviderUsesIndexServiceAndBackendArea' => function () {
            $this->assert_SystemConfigNavSearchIndexService_testBackendSearchProviderUsesIndexServiceAndBackendArea();
        },
            'SystemConfigSensitivePlaceholder::testSensitiveUnchangedPlaceholderRecognizesMasks' => function () {
            $this->assert_SystemConfigSensitivePlaceholder_testSensitiveUnchangedPlaceholderRecognizesMasks();
        },
            'SystemConfigSensitivePlaceholder::testImportFileFieldDetected' => function () {
            $this->assert_SystemConfigSensitivePlaceholder_testImportFileFieldDetected();
        },
        ];
    }

private function assert_ConfigCacheInvalidationEmitOnly_testInvalidationServiceBuildsCacheOpsAndDoesNotDeleteSharedPoolsInline(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Service/ConfigCacheInvalidationService.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function buildCacheOps', $source);
    self::assertStringContainsString("'pool' => 'system_config'", $source);
    self::assertStringContainsString("'pool' => 'database'", $source);
    self::assertStringContainsString('Shared-pool deletes moved to impact.cache_ops', $source);
    self::assertStringNotContainsString("\$cache->delete(\$singleKey)", $source);
    self::assertStringNotContainsString("\$cache->delete(\$moduleKey)", $source);
}

private function assert_ConfigCacheInvalidationEmitOnly_testPublisherAcceptsCacheOpsAndFieldNamespaces(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Service/SystemConfigResourceChangePublisher.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('array $cacheOps = []', $source);
    self::assertStringContainsString('cache-namespaces', $source);
    self::assertStringContainsString('function resolveNamespaces', $source);
    self::assertStringContainsString("\$impact['cache_ops'] = \$cacheOps", $source);
}

private function assert_ConfigCacheInvalidationEmitOnly_testBuildCacheOpsDecoratesPreBumpFingerprintedKeys(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Service/ConfigCacheInvalidationService.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('withPreBumpFingerprintedKeys', $source);
    self::assertStringContainsString('NamespaceKeyDecorator', $source);
    self::assertStringContainsString('array $namespacePaths = []', $source);
}

private function assert_ConfigCacheInvalidationEmitOnly_testConfigCenterFormCollectsControlCacheNamespaces(): void
{
    $js = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/statics/js/system-config-filter.js';
    $source = (string) file_get_contents($js);
    self::assertStringContainsString("name = 'cache_namespaces[]'", $source);
    self::assertStringContainsString('data-cache-namespaces', $source);
}

private function assert_ConfigCacheInvalidationFeedback_testBuildSummaryUsesFriendlyLayeredCopy(): void
{
    $feedback = new ConfigCacheInvalidationFeedback();
    $built = $feedback->build(['global/storefront/captcha', 'global/storefront/config'], [['pool' => 'system_config', 'keys' => ['a', 'b', 'c']], ['pool' => 'database', 'keys' => ['a', 'b', 'c']]], 2);
    self::assertContains('人机验证', $built['friendly_labels']);
    self::assertContains('店面配置', $built['friendly_labels']);
    self::assertSame(3, $built['cache_key_count']);
    self::assertStringContainsString('已刷新缓存', $built['summary']);
    self::assertStringNotContainsString('bump', $built['summary']);
    self::assertStringNotContainsString('池删除', $built['summary']);
    self::assertGreaterThanOrEqual(2, count($built['detail_lines']));
}

private function assert_ConfigCacheInvalidationFeedback_testFormatSavePartsSeparatesTitleAndBody(): void
{
    $feedback = new ConfigCacheInvalidationFeedback();
    $invalidation = $feedback->build(['global/storefront/captcha'], [['pool' => 'system_config', 'keys' => ['k1']]], 1);
    $parts = $feedback->formatSaveParts(42, $invalidation);
    self::assertSame('配置已保存', $parts['title']);
    self::assertStringContainsString('版本批次 42', $parts['body']);
    self::assertStringContainsString("\n", $parts['message']);
    self::assertStringNotContainsString('bump', $parts['message']);
}

private function assert_ConfigCacheInvalidationFeedback_testControllerAndEmbedUseServerMessage(): void
{
    $controller = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Controller/Backend/Config.php';
    $js = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/view/statics/js/config-embed.js';
    $controllerSrc = (string) file_get_contents($controller);
    $jsSrc = (string) file_get_contents($js);
    self::assertStringContainsString('formatSaveParts', $controllerSrc);
    self::assertStringContainsString('addSuccess($body, $title)', $controllerSrc);
    self::assertStringContainsString('message_title', $jsSrc);
    self::assertStringContainsString('title:', $jsSrc);
}

private ConfigFieldCacheNamespaceResolver $resolver_ConfigFieldCacheNamespaceResolver;

private function setUp_ConfigFieldCacheNamespaceResolver(): void
{
    $this->resolver_ConfigFieldCacheNamespaceResolver = new ConfigFieldCacheNamespaceResolver(new NamespacePath());
}

private function assert_ConfigFieldCacheNamespaceResolver_testDeclarationNormalizesLeafPrefixAndStarSugar(): void
{
    $paths = $this->resolver_ConfigFieldCacheNamespaceResolver->resolve(['storefront/captcha', 'storefront', 'storefront/*', 'captcha']);
    self::assertContains('global/storefront/captcha', $paths);
    self::assertContains('global/storefront', $paths);
    self::assertSame($paths, array_values(array_unique($paths)));
}

private function assert_ConfigFieldCacheNamespaceResolver_testRequestWithoutDeclarationRejectsArbitraryGlobal(): void
{
    $paths = $this->resolver_ConfigFieldCacheNamespaceResolver->resolve([], ['global/storefront/evil-custom', 'storefront/captcha']);
    self::assertSame(['global/storefront/captcha'], $paths);
}

private function assert_ConfigFieldCacheNamespaceResolver_testDeclarationWinsOverIncompatibleRequest(): void
{
    $paths = $this->resolver_ConfigFieldCacheNamespaceResolver->resolve(['storefront/captcha'], ['storefront/auth']);
    self::assertSame(['global/storefront/captcha'], $paths);
}

private function assert_ConfigFieldCacheNamespaceResolver_testKeyBindingMapsCaptchaKeys(): void
{
    $paths = $this->resolver_ConfigFieldCacheNamespaceResolver->resolve([], [], true, ['captcha/google/api_key']);
    self::assertSame(['global/storefront/captcha'], $paths);
}

private function assert_ConfigFieldCacheNamespaceResolver_testControlReinforcesDeclaredParentChild(): void
{
    $paths = $this->resolver_ConfigFieldCacheNamespaceResolver->resolve(['storefront/captcha'], ['storefront/captcha', 'captcha'], true);
    self::assertSame(['global/storefront/captcha'], $paths);
}

private function assert_ConfigFieldCacheNamespaceResolver_testBindKeyPrefixesAttributeActivatesRule(): void
{
    $paths = $this->resolver_ConfigFieldCacheNamespaceResolver->fromConfigKeys([], ['captcha/']);
    self::assertSame(['global/storefront/captcha'], $paths);
}

private function setUp_ScopeConfigVersionVectorContext(): void
{
    Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
    \w_cache('system_config')->clear();
}

private function tearDown_ScopeConfigVersionVectorContext(): void
{
    Context::leave();
}

private function assert_ScopeConfigVersionVectorContext_testBumpRefreshesMemoizedDescendantsButKeepsOtherWebsites(): void
{
    $invalidator = new ScopeConfigCacheInvalidator();
    $scopes = ['shop.default.default', 'shop.main.default', 'shop.main.app', 'other.main.app'];
    $before = [];
    foreach ($scopes as $scope) {
        $before[$scope] = $invalidator->versionVectorFor($scope);
    }
    $invalidator->bumpGeneration('shop.default.default');
    foreach (array_slice($scopes, 0, 3) as $scope) {
        self::assertNotSame($before[$scope], $invalidator->versionVectorFor($scope), $scope);
    }
    self::assertSame($before[$scopes[3]], $invalidator->versionVectorFor($scopes[3]));
}

private function assert_ScopeConfigVersionVectorContext_testGlobalBumpRefreshesDefaultWebsiteSentinelAndStore(): void
{
    $invalidator = new ScopeConfigCacheInvalidator();
    $scope = 'default.__store__.__channel__';
    $before = $invalidator->versionVectorFor($scope);
    $invalidator->bumpGeneration(SystemConfig::SCOPE_GLOBAL);
    self::assertNotSame($before, $invalidator->versionVectorFor($scope));
}

private function assert_SecurityHeaderProcessCache_testOverrideProviderOwnsProcessBag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Service/SystemConfigSecurityHeaderPolicyOverrideProvider.php');
    self::assertStringContainsString('$processOverridesByScope', $src);
    self::assertStringContainsString('function clearProcessCache', $src);
}

private function assert_SecurityHeaderProcessCache_testProcessCacheResetterClearsSecurityOverrideBag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Api/Runtime/ProcessCacheResetter.php');
    self::assertStringContainsString('SystemConfigSecurityHeaderPolicyOverrideProvider::clearProcessCache()', $src);
}

private function assert_SystemConfigMultiselectInOptions_testInOptionsAcceptsMultiselectArrayWithinOptions(): void
{
    $method = $this->validateMethod_SystemConfigMultiselectInOptions();
    $service = $this->service_SystemConfigMultiselectInOptions();
    $error = $method->invoke($service, ['USD', 'EUR'], ['type' => 'multiselect', 'value-type' => 'json', 'options' => 'USD:USD,EUR:EUR,GBP:GBP', 'validation' => 'required|in_options']);
    self::assertSame('', $error);
}

private function assert_SystemConfigMultiselectInOptions_testInOptionsRejectsUnknownMultiselectItem(): void
{
    $method = $this->validateMethod_SystemConfigMultiselectInOptions();
    $service = $this->service_SystemConfigMultiselectInOptions();
    $error = $method->invoke($service, ['USD', 'CNY'], ['type' => 'multiselect', 'value-type' => 'json', 'options' => 'USD:USD,EUR:EUR', 'validation' => 'in_options']);
    self::assertNotSame('', $error);
}

private function assert_SystemConfigMultiselectInOptions_testInOptionsNoLongerFailsOnArrayCastToStringArray(): void
{
    $method = $this->validateMethod_SystemConfigMultiselectInOptions();
    $service = $this->service_SystemConfigMultiselectInOptions();
    // Regression: casting list to string became "Array" and always failed in_options.
    $error = $method->invoke($service, ['USD'], ['type' => 'multiselect', 'options' => 'USD:USD,EUR:EUR', 'validation' => 'in_options']);
    self::assertSame('', $error);
    self::assertStringContainsString('validationCandidateValues', (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Service/SystemConfigCenterService.php'));
}

private function assert_SystemConfigMultiselectInOptions_testRequiredRejectsEmptyMultiselectArray(): void
{
    $method = $this->validateMethod_SystemConfigMultiselectInOptions();
    $service = $this->service_SystemConfigMultiselectInOptions();
    $error = $method->invoke($service, [], ['type' => 'multiselect', 'options' => 'USD:USD', 'validation' => 'required|in_options']);
    self::assertNotSame('', $error);
}

private function service_SystemConfigMultiselectInOptions(): SystemConfigCenterService
{
    return (new \ReflectionClass(SystemConfigCenterService::class))->newInstanceWithoutConstructor();
}

private function validateMethod_SystemConfigMultiselectInOptions(): \ReflectionMethod
{
    $method = new \ReflectionMethod(SystemConfigCenterService::class, 'validateFieldValue');
    $method->setAccessible(true);
    return $method;
}

private function assert_SystemConfigNavSearchIndexService_testServiceBuildsSearchableConfigFieldHitsWithDeeplinkShape(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/Service/SystemConfigNavSearchIndexService.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('class SystemConfigNavSearchIndexService', $src);
    self::assertStringContainsString('function getItems', $src);
    self::assertStringContainsString('guide_key', $src);
    self::assertStringContainsString('guide_locate', $src);
    self::assertStringContainsString('weline_systemconfig/backend/config', $src);
    self::assertStringContainsString('search_text', $src);
}

private function assert_SystemConfigNavSearchIndexService_testBackendSearchProviderUsesIndexServiceAndBackendArea(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SystemConfig' . '/extends/module/Weline_Search/Searcher/SystemConfigSearchProvider.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('class SystemConfigSearchProvider', $src);
    self::assertStringContainsString("return 'system_config'", $src);
    self::assertStringContainsString("return ['backend']", $src);
    self::assertStringContainsString('SystemConfigNavSearchIndexService', $src);
    self::assertStringContainsString('entityType: \'system_config_field\'', $src);
    self::assertStringContainsString("'breadcrumb'", $src);
    self::assertStringContainsString("'template_title'", $src);
    self::assertStringContainsString("'group'", $src);
}

private function assert_SystemConfigSensitivePlaceholder_testSensitiveUnchangedPlaceholderRecognizesMasks(): void
{
    $service = (new \ReflectionClass(SystemConfigCenterService::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(SystemConfigCenterService::class, 'isSensitiveUnchangedPlaceholder');
    $method->setAccessible(true);
    self::assertTrue($method->invoke($service, ''));
    self::assertTrue($method->invoke($service, '***'));
    self::assertTrue($method->invoke($service, '********'));
    self::assertTrue($method->invoke($service, '*****'));
    self::assertFalse($method->invoke($service, 'GOCSPX-real-secret'));
}

private function assert_SystemConfigSensitivePlaceholder_testImportFileFieldDetected(): void
{
    $service = (new \ReflectionClass(SystemConfigCenterService::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(SystemConfigCenterService::class, 'isImportFileField');
    $method->setAccessible(true);
    self::assertTrue($method->invoke($service, ['type' => 'import_file']));
    self::assertFalse($method->invoke($service, ['type' => 'textarea']));
}
}
