<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use Weline\Framework\Service\Query\BinQueryCachePolicy;
use Weline\Framework\Service\Query\BinQueryDescriptorAttributeResolver;
use Weline\I18n\Extends\Module\Weline_Framework\Query\I18nQueryProvider;
use Weline\I18n\Extends\Module\Weline_Framework\Query\I18nRemoteTranslationQueryProvider;
use Weline\I18n\Service\RemoteDictionaryAssistService;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nQueryContractsPass().
 */
final class I18nQueryContractsTest extends TestCase
{
    public function testAllI18nQueryContractsPass(): void
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
            'I18nAdminQueryProviderPayload::testProviderNormalizesBracketPayloadBeforeControllerInvoke' => function () {
            $this->assert_I18nAdminQueryProviderPayload_testProviderNormalizesBracketPayloadBeforeControllerInvoke();
        },
            'I18nAdminQueryProviderPayload::testBracketPayloadParsesIntoNestedDescriptionArray' => function () {
            $this->assert_I18nAdminQueryProviderPayload_testBracketPayloadParsesIntoNestedDescriptionArray();
        },
            'I18nAdminQueryProviderPayload::testDuplicateScalarPayloadKeysCoerceToLastScalar' => function () {
            $this->assert_I18nAdminQueryProviderPayload_testDuplicateScalarPayloadKeysCoerceToLastScalar();
        },
            'I18nQueryProviderCacheDescriptor::testPublicLocaleCatalogOpsAreCdnCacheable' => function () {
            $this->assert_I18nQueryProviderCacheDescriptor_testPublicLocaleCatalogOpsAreCdnCacheable();
        },
            'I18nRemoteTranslationQueryProvider::testProviderNameOpsAndAcl' => function () {
            $this->assert_I18nRemoteTranslationQueryProvider_testProviderNameOpsAndAcl();
        },
            'I18nRemoteTranslationQueryProvider::testCollectServiceSupportsEnqueueFlag' => function () {
            $this->assert_I18nRemoteTranslationQueryProvider_testCollectServiceSupportsEnqueueFlag();
        },
            'I18nRemoteTranslationQueryProvider::testRemoteCollectUsesEnqueueFalse' => function () {
            $this->assert_I18nRemoteTranslationQueryProvider_testRemoteCollectUsesEnqueueFalse();
        },
            'I18nRemoteTranslationQueryProvider::testRestThinShellAndAclSources' => function () {
            $this->assert_I18nRemoteTranslationQueryProvider_testRestThinShellAndAclSources();
        },
            'I18nRemoteTranslationQueryProvider::testAssistLimitsMatchContracts' => function () {
            $this->assert_I18nRemoteTranslationQueryProvider_testAssistLimitsMatchContracts();
        },
            'I18nRemoteTranslationQueryProvider::testNormalizeTypeDefaultsAndRejectsUnknown' => function () {
            $this->assert_I18nRemoteTranslationQueryProvider_testNormalizeTypeDefaultsAndRejectsUnknown();
        },
            'I18nRemoteTranslationQueryProvider::testQueryProviderRoutesLocalModelAndCollectGate' => function () {
            $this->assert_I18nRemoteTranslationQueryProvider_testQueryProviderRoutesLocalModelAndCollectGate();
        },
            'I18nRemoteTranslationQueryProvider::testLocalModelRemoteAssistSurface' => function () {
            $this->assert_I18nRemoteTranslationQueryProvider_testLocalModelRemoteAssistSurface();
        },
            'I18nRemoteTranslationQueryProvider::testDemoScriptsDocumentRemoteType' => function () {
            $this->assert_I18nRemoteTranslationQueryProvider_testDemoScriptsDocumentRemoteType();
        },
        ];
    }

private function assert_I18nAdminQueryProviderPayload_testProviderNormalizesBracketPayloadBeforeControllerInvoke(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php');
    self::assertStringContainsString('normalizeActionPayload', $source);
    self::assertStringContainsString('normalizeScalarPayloadKeys', $source);
    self::assertStringContainsString('AdminControllerBridge::invoke', $source);
    self::assertStringNotContainsString('assertBackendSession', $source);
    self::assertStringNotContainsString("'taglib-local-save',", $source);
    self::assertStringContainsString('parse_str', $source);
    self::assertStringContainsString('http_build_query', $source);
}

private function assert_I18nAdminQueryProviderPayload_testBracketPayloadParsesIntoNestedDescriptionArray(): void
{
    $payload = ['model' => 'Weline\Eav\Model\EavAttribute\Group\LocalDescription', 'description[zh_Hans_CN][local_code]' => 'zh_Hans_CN', 'description[zh_Hans_CN][group_id]' => '1', 'description[zh_Hans_CN][name]' => '默认属性组'];
    $normalized = [];
    parse_str(http_build_query($payload, '', '&', PHP_QUERY_RFC3986), $normalized);
    self::assertIsArray($normalized['description'] ?? null);
    self::assertSame('zh_Hans_CN', $normalized['description']['zh_Hans_CN']['local_code'] ?? null);
    self::assertSame('1', $normalized['description']['zh_Hans_CN']['group_id'] ?? null);
    self::assertSame('默认属性组', $normalized['description']['zh_Hans_CN']['name'] ?? null);
}

private function assert_I18nAdminQueryProviderPayload_testDuplicateScalarPayloadKeysCoerceToLastScalar(): void
{
    $payload = ['model' => ['Weline\Eav\Model\EavAttribute\Set\LocalDescription', 'Weline\Eav\Model\EavAttribute\Set\LocalDescription'], 'id' => ['4', '4'], 'field' => ['name', 'name']];
    $normalized = $payload;
    foreach (['model', 'field', 'id', 'value', 'isIframe', 'action'] as $key) {
        if (!array_key_exists($key, $normalized) || !is_array($normalized[$key])) {
            continue;
        }
        $resolved = '';
        foreach ($normalized[$key] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                $resolved = trim((string) $candidate);
            }
        }
        $normalized[$key] = $resolved;
    }
    self::assertSame('Weline\Eav\Model\EavAttribute\Set\LocalDescription', $normalized['model']);
    self::assertSame('4', $normalized['id']);
    self::assertSame('name', $normalized['field']);
}

private function assert_I18nQueryProviderCacheDescriptor_testPublicLocaleCatalogOpsAreCdnCacheable(): void
{
    $provider = (new ReflectionClass(I18nQueryProvider::class))->newInstanceWithoutConstructor();
    $merged = (new BinQueryDescriptorAttributeResolver())->merge($provider, $provider->getDescriptor());
    $byName = [];
    foreach ($merged['operations'] ?? [] as $operation) {
        if (\is_array($operation) && isset($operation['name'])) {
            $byName[(string) $operation['name']] = $operation;
        }
    }
    $policy = new BinQueryCachePolicy();
    foreach (['getCountryFlags' => '7d', 'getInstalledLocales' => '1h', 'getLocaleByCode' => '1h', 'getLocaleName' => '1h'] as $name => $ttl) {
        self::assertArrayHasKey($name, $byName, $name);
        self::assertTrue(($byName[$name]['external'] ?? false) === true, $name);
        self::assertTrue($policy->isCacheableOperation($byName[$name]), $name);
        self::assertSame($ttl, $byName[$name]['cache']['ttl'] ?? null, $name);
    }
    self::assertFalse($policy->isCacheableOperation($byName['getBackendCountryFlags'] ?? ['external' => false, 'mode' => 'read']));
    self::assertArrayHasKey('getLanguageSwitcherCatalog', $byName);
    self::assertTrue(($byName['getLanguageSwitcherCatalog']['external'] ?? false) === true);
    self::assertTrue($policy->isCacheableOperation($byName['getLanguageSwitcherCatalog']));
    self::assertSame('1h', $byName['getLanguageSwitcherCatalog']['cache']['ttl'] ?? null);
    self::assertTrue(($byName['getLanguageSwitcherCatalog']['frontend'] ?? false) === true);
    self::assertSame(['binquery-i18n-getLanguageSwitcherCatalog'], $byName['getLanguageSwitcherCatalog']['cache']['tags'] ?? null);
}

private function assert_I18nRemoteTranslationQueryProvider_testProviderNameOpsAndAcl(): void
{
    $provider = (new ReflectionClass(I18nRemoteTranslationQueryProvider::class))->newInstanceWithoutConstructor();
    self::assertSame('i18n_remote_translation', $provider->getProviderName());
    $descriptor = $provider->getDescriptor();
    self::assertTrue((bool) ($descriptor['demo'] ?? false));
    $ops = array_column(is_array($descriptor['operations'] ?? null) ? $descriptor['operations'] : [], 'name');
    self::assertSame(['remoteTranslationPending', 'remoteTranslationIngest', 'remoteTranslationCollectStart', 'remoteTranslationCollectStatus'], $ops);
    $expectedAcl = ['remoteTranslationPending' => 'Weline_I18n::rest_v1_remote_translation_pending', 'remoteTranslationIngest' => 'Weline_I18n::rest_v1_remote_translation_ingest', 'remoteTranslationCollectStart' => 'Weline_I18n::rest_v1_remote_translation_collect_start', 'remoteTranslationCollectStatus' => 'Weline_I18n::rest_v1_remote_translation_collect_status'];
    foreach ($descriptor['operations'] as $operation) {
        $name = (string) ($operation['name'] ?? '');
        self::assertTrue((bool) ($operation['frontend'] ?? false));
        self::assertFalse((bool) ($operation['external'] ?? true));
        self::assertSame('backend', $operation['auth'] ?? null);
        self::assertSame('source', $operation['backend_acl']['kind'] ?? null);
        self::assertSame($expectedAcl[$name] ?? '', (string) ($operation['backend_acl']['source_id'] ?? ''));
        $paramNames = array_column(is_array($operation['params'] ?? null) ? $operation['params'] : [], 'name');
        if (in_array($name, ['remoteTranslationPending', 'remoteTranslationIngest', 'remoteTranslationCollectStart'], true)) {
            self::assertContains('type', $paramNames, $name . ' must document type');
        }
    }
}

private function assert_I18nRemoteTranslationQueryProvider_testCollectServiceSupportsEnqueueFlag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryCollectService.php');
    self::assertStringContainsString('bool $enqueueAiTranslation = true', $src);
    self::assertStringContainsString('if ($enqueueAiTranslation && $collectedCount > 0)', $src);
}

private function assert_I18nRemoteTranslationQueryProvider_testRemoteCollectUsesEnqueueFalse(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/RemoteCollectTaskService.php');
    self::assertStringContainsString("            false,\n        );", $src);
    self::assertStringContainsString('bin2hex(random_bytes(16))', $src);
    self::assertStringContainsString("STATUS_EXPIRED = 'expired'", $src);
    self::assertStringNotContainsString('enqueueAiTranslation: true', $src);
}

private function assert_I18nRemoteTranslationQueryProvider_testRestThinShellAndAclSources(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Api/Rest/V1/RemoteTranslation.php');
    self::assertStringContainsString("w_query('i18n_remote_translation'", $src);
    self::assertStringContainsString('Weline_I18n::rest_v1_remote_translation_pending', $src);
    self::assertStringContainsString('Weline_I18n::rest_v1_remote_translation_ingest', $src);
    self::assertStringContainsString('Weline_I18n::rest_v1_remote_translation_collect_start', $src);
    self::assertStringContainsString('Weline_I18n::rest_v1_remote_translation_collect_status', $src);
    self::assertStringContainsString("'type' => (string)(\$body['type'] ?? '')", $src);
    self::assertStringNotContainsString('DictionaryCollectService', $src);
}

private function assert_I18nRemoteTranslationQueryProvider_testAssistLimitsMatchContracts(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/RemoteDictionaryAssistService.php');
    self::assertStringContainsString('LIMIT_MAX = 200', $src);
    self::assertStringContainsString('ITEMS_MAX = 100', $src);
    self::assertStringContainsString('FIELD_MAX_LEN = 8000', $src);
    self::assertStringContainsString('publishLocale', $src);
    self::assertStringContainsString('locale_not_allowed', $src);
    self::assertStringContainsString('duplicate_in_batch', $src);
    self::assertStringContainsString('TYPE_PHRASE', $src);
    self::assertStringContainsString('TYPE_META', $src);
    self::assertStringContainsString('TYPE_LOCAL_MODEL', $src);
    self::assertStringContainsString('wrong_shape_for_type', $src);
    self::assertStringContainsString('queryMetaPendingUnion', $src);
    self::assertStringContainsString('queryPhrasePendingUnion', $src);
    self::assertStringContainsString('MetaTranslation::setTranslatedValueWithScope', $src);
    self::assertStringContainsString('MetaLocalWordParser', $src);
    self::assertStringContainsString("NOT LIKE ", $src);
    self::assertStringNotContainsString("d.word LIKE " . "'@meta::%'", $src);
}

private function assert_I18nRemoteTranslationQueryProvider_testNormalizeTypeDefaultsAndRejectsUnknown(): void
{
    self::assertSame(RemoteDictionaryAssistService::TYPE_PHRASE, RemoteDictionaryAssistService::normalizeType(null));
    self::assertSame(RemoteDictionaryAssistService::TYPE_META, RemoteDictionaryAssistService::normalizeType('META'));
    self::assertSame(RemoteDictionaryAssistService::TYPE_LOCAL_MODEL, RemoteDictionaryAssistService::normalizeType('local_model'));
    $this->expectException(\InvalidArgumentException::class);
    RemoteDictionaryAssistService::normalizeType('widgets');
}

private function assert_I18nRemoteTranslationQueryProvider_testQueryProviderRoutesLocalModelAndCollectGate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/extends/module/Weline_Framework/Query/I18nRemoteTranslationQueryProvider.php');
    self::assertStringContainsString('TYPE_LOCAL_MODEL', $src);
    self::assertStringContainsString('remotePending', $src);
    self::assertStringContainsString('remoteIngest', $src);
    self::assertStringContainsString('local_model 不支持词典 collect', $src);
}

private function assert_I18nRemoteTranslationQueryProvider_testLocalModelRemoteAssistSurface(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationService.php');
    self::assertStringContainsString('function remotePending', $src);
    self::assertStringContainsString('function remoteIngest', $src);
    self::assertStringContainsString('locale_not_allowed', $src);
    self::assertStringContainsString('TYPE_LOCAL_MODEL', $src);
    self::assertStringContainsString('upsertLocalValue', $src);
}

private function assert_I18nRemoteTranslationQueryProvider_testDemoScriptsDocumentRemoteType(): void
{
    $readme = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/source/api-demo/i18n_remote_translation/README.md');
    $php = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/source/api-demo/i18n_remote_translation/php/run.php');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/source/api-demo/i18n_remote_translation/js/index.js');
    self::assertStringContainsString('WELINE_REMOTE_TYPE', $readme);
    self::assertStringContainsString('local_model', $readme);
    self::assertStringContainsString('WidgetI18n', $readme);
    self::assertStringContainsString('WELINE_REMOTE_TYPE', $php);
    self::assertStringContainsString("'type' => \$type", $php);
    self::assertStringContainsString('WELINE_REMOTE_TYPE', $js);
    self::assertStringContainsString('type,', $js);
}
}
