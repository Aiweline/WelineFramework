<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Service\Localization\WebsiteLanguageAssignment;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesModelContractsPass().
 */
final class WebsitesModelContractsTest extends TestCase
{
    public function testAllWebsitesModelContractsPass(): void
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
            'StoreChannelSchema::testStoreAndChannelDeclareTheirCompleteCompositeUniqueKeys' => function () {
            $this->assert_StoreChannelSchema_testStoreAndChannelDeclareTheirCompleteCompositeUniqueKeys();
        },
            'StoreChannelSchema::testModuleAndRegistrationVersionsAreReleasedTogether' => function () {
            $this->assert_StoreChannelSchema_testModuleAndRegistrationVersionsAreReleasedTogether();
        },
            'StoreChannelSchema::testDynamicDocumentEntryReferencesScopeContract' => function () {
            $this->assert_StoreChannelSchema_testDynamicDocumentEntryReferencesScopeContract();
        },
            'WebsiteLanguageFontWarmupHook::testSetWebsiteLanguagesSourceCallsThemeFontWarmup' => function () {
            $this->assert_WebsiteLanguageFontWarmupHook_testSetWebsiteLanguagesSourceCallsThemeFontWarmup();
        },
            'WebsiteLanguageFontWarmupHook::testLanguageAssignmentSourceCallsThemeFontWarmup' => function () {
            $this->assert_WebsiteLanguageFontWarmupHook_testLanguageAssignmentSourceCallsThemeFontWarmup();
        },
            'WebsiteLoadContextCache::testLoadPrefersRequestContextSnapshot' => function () {
            $this->setUp_WebsiteLoadContextCache();
            try {
                $this->assert_WebsiteLoadContextCache_testLoadPrefersRequestContextSnapshot();
            } finally {
                $this->tearDown_WebsiteLoadContextCache();
            }
        },
            'WebsiteLoadContextCache::testForceReloadBypassesRequestContext' => function () {
            $this->setUp_WebsiteLoadContextCache();
            try {
                $this->assert_WebsiteLoadContextCache_testForceReloadBypassesRequestContext();
            } finally {
                $this->tearDown_WebsiteLoadContextCache();
            }
        },
            'WebsiteLoadContextCache::testLoadByCodePrefersRequestContext' => function () {
            $this->setUp_WebsiteLoadContextCache();
            try {
                $this->assert_WebsiteLoadContextCache_testLoadByCodePrefersRequestContext();
            } finally {
                $this->tearDown_WebsiteLoadContextCache();
            }
        },
            'WebsiteStorefrontBaseUrl::testWebsiteExposesStorefrontBaseUrlResolverIncludingDefaultSite' => function () {
            $this->assert_WebsiteStorefrontBaseUrl_testWebsiteExposesStorefrontBaseUrlResolverIncludingDefaultSite();
        },
            'WebsiteStorefrontBaseUrl::testDefaultWebsiteResolvesAbsoluteStorefrontBase' => function () {
            $this->assert_WebsiteStorefrontBaseUrl_testDefaultWebsiteResolvesAbsoluteStorefrontBase();
        },
        ];
    }

private function assert_StoreChannelSchema_testStoreAndChannelDeclareTheirCompleteCompositeUniqueKeys(): void
{
    $parser = new SchemaParser();
    $store = $parser->parse(Store::class);
    $channel = $parser->parse(SalesChannel::class);
    self::assertNotNull($store);
    self::assertNotNull($channel);
    self::assertTrue($this->hasUniqueIndex_StoreChannelSchema($store->indexes, 'uk_website_store_code', [Store::schema_fields_WEBSITE_ID, Store::schema_fields_CODE]));
    self::assertTrue($this->hasUniqueIndex_StoreChannelSchema($channel->indexes, 'uk_store_channel_code', [SalesChannel::schema_fields_STORE_ID, SalesChannel::schema_fields_CODE]));
    self::assertContains(Store::schema_fields_LIFECYCLE_STATUS, array_column(array_map('get_object_vars', $store->columns), 'name'));
    self::assertContains(Store::schema_fields_TOMBSTONED_AT, array_column(array_map('get_object_vars', $store->columns), 'name'));
}

private function assert_StoreChannelSchema_testModuleAndRegistrationVersionsAreReleasedTogether(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Websites';
    $manifest = require $moduleRoot . '/etc/module.php';
    $registration = (string) file_get_contents($moduleRoot . '/register.php');
    self::assertSame('1.7.1', $manifest['version'] ?? null);
    self::assertMatchesRegularExpression("/'Weline_Websites',\\s*__DIR__,\\s*'1\\.7\\.1'/s", $registration);
}

private function assert_StoreChannelSchema_testDynamicDocumentEntryReferencesScopeContract(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Websites';
    self::assertFileExists($moduleRoot . '/doc/store-saleschannel-scope.md');
    $readme = (string) file_get_contents($moduleRoot . '/doc/README.md');
    self::assertStringContainsString('doc/store-saleschannel-scope.md', $readme);
}

/** @param list<IndexDefinition> $indexes @param list<string> $columns */
private function hasUniqueIndex_StoreChannelSchema(array $indexes, string $name, array $columns): bool
{
    foreach ($indexes as $index) {
        if ($index->name === $name && $index->type === 'UNIQUE' && $index->columns === $columns) {
            return true;
        }
    }
    return false;
}

private function assert_WebsiteLanguageFontWarmupHook_testSetWebsiteLanguagesSourceCallsThemeFontWarmup(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Model/WebsiteLanguage.php');
    self::assertStringContainsString('syncThemeFontWarmup', $src);
    self::assertStringContainsString('FontWarmupService', $src);
    self::assertStringContainsString('->warmup($codes)', $src);
}

private function assert_WebsiteLanguageFontWarmupHook_testLanguageAssignmentSourceCallsThemeFontWarmup(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/Localization/WebsiteLanguageAssignment.php');
    self::assertStringContainsString('syncThemeFontWarmup', $src);
    self::assertStringContainsString('FontWarmupService', $src);
    self::assertTrue(class_exists(WebsiteLanguage::class));
    self::assertTrue(class_exists(WebsiteLanguageAssignment::class));
}

private function setUp_WebsiteLoadContextCache(): void
{
    parent::setUp();
    Context::leave();
    Context::enter(new Context());
    RequestContext::init();
    WebsiteData::resetRequestState();
}

private function tearDown_WebsiteLoadContextCache(): void
{
    WebsiteData::resetRequestState();
    RequestContext::resetWelineVars();
    Context::leave();
    parent::tearDown();
}

private function assert_WebsiteLoadContextCache_testLoadPrefersRequestContextSnapshot(): void
{
    WebsiteData::setWebsite(new WebsiteLoadRowStub([Website::schema_fields_ID => 0, Website::schema_fields_CODE => 'default', Website::schema_fields_NAME => 'Default', Website::schema_fields_URL => 'https://default.example.test', Website::schema_fields_DEFAULT_CURRENCY => 'CNY', Website::schema_fields_DEFAULT_LANGUAGE => 'zh_Hans_CN', Website::schema_fields_DEFAULT_TIMEZONE => 'UTC']));
    $loaded = new WebsiteLoadSpy();
    $loaded->load(0);
    self::assertSame(0, $loaded->getWebsiteId());
    self::assertSame('default', $loaded->getCode());
    self::assertSame(0, $loaded->parentLoadCalls);
    self::assertFalse($loaded->lastForceReload);
}

private function assert_WebsiteLoadContextCache_testForceReloadBypassesRequestContext(): void
{
    WebsiteData::setWebsite(new WebsiteLoadRowStub([Website::schema_fields_ID => 3, Website::schema_fields_CODE => 'shop', Website::schema_fields_NAME => 'Shop', Website::schema_fields_URL => 'https://shop.example.test', Website::schema_fields_DEFAULT_CURRENCY => 'USD', Website::schema_fields_DEFAULT_LANGUAGE => 'en_US', Website::schema_fields_DEFAULT_TIMEZONE => 'UTC']));
    $loaded = new WebsiteLoadSpy();
    $loaded->load(3, forceReload: true);
    self::assertSame(1, $loaded->parentLoadCalls);
    self::assertTrue($loaded->lastForceReload);
    self::assertSame('forced', $loaded->getCode());
}

private function assert_WebsiteLoadContextCache_testLoadByCodePrefersRequestContext(): void
{
    WebsiteData::setWebsite(new WebsiteLoadRowStub([Website::schema_fields_ID => 5, Website::schema_fields_CODE => 'outlet', Website::schema_fields_NAME => 'Outlet', Website::schema_fields_URL => 'https://outlet.example.test', Website::schema_fields_DEFAULT_CURRENCY => 'USD', Website::schema_fields_DEFAULT_LANGUAGE => 'en_US', Website::schema_fields_DEFAULT_TIMEZONE => 'UTC']));
    $loaded = new WebsiteLoadSpy();
    $loaded->load(Website::schema_fields_CODE, 'outlet');
    self::assertSame(5, $loaded->getWebsiteId());
    self::assertSame('outlet', $loaded->getCode());
    self::assertSame(0, $loaded->parentLoadCalls);
}

private function assert_WebsiteStorefrontBaseUrl_testWebsiteExposesStorefrontBaseUrlResolverIncludingDefaultSite(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Model/Website.php');
    self::assertStringContainsString('function resolveStorefrontBaseUrl(int $websiteId)', $source);
    self::assertStringContainsString('CanonicalStorefrontUrl::fromStoreUrl', $source);
    self::assertStringContainsString('ID_DEFAULT', $source);
}

private function assert_WebsiteStorefrontBaseUrl_testDefaultWebsiteResolvesAbsoluteStorefrontBase(): void
{
    $base = Website::resolveStorefrontBaseUrl(Website::ID_DEFAULT);
    self::assertNotNull($base);
    self::assertMatchesRegularExpression('#^https?://#i', (string) $base);
    self::assertStringNotContainsString('localhost', (string) $base);
}
}

final class WebsiteLoadSpy extends Website
{
    public int $parentLoadCalls = 0;
    public bool $lastForceReload = false;
    public function load(int|string $field_or_pk_value, $value = null, bool $forceReload = false): AbstractModel
    {
        if (!$forceReload) {
            $method = new \ReflectionMethod(Website::class, 'resolveRowWithoutQuery');
            $method->setAccessible(true);
            $row = $method->invoke($this, $field_or_pk_value, $value);
            if (\is_array($row)) {
                $this->setData($row);
                return $this;
            }
        }
        $this->parentLoadCalls++;
        $this->lastForceReload = $forceReload;
        $this->setData([Website::schema_fields_ID => $value === null ? (int) $field_or_pk_value : 99, Website::schema_fields_CODE => $forceReload ? 'forced' : 'db', Website::schema_fields_NAME => $forceReload ? 'Forced' : 'Db', Website::schema_fields_URL => 'https://example.test', Website::schema_fields_DEFAULT_CURRENCY => 'USD', Website::schema_fields_DEFAULT_LANGUAGE => 'en_US', Website::schema_fields_DEFAULT_TIMEZONE => 'UTC']);
        return $this;
    }
}

final class WebsiteLoadRowStub extends Website
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }
    public function setData($key, $value = null, bool $is_unique = false): static
    {
        if (\is_array($key)) {
            $this->values = $key;
        } else {
            $this->values[(string) $key] = $value;
        }
        return $this;
    }
    public function getData(string $key = '', $index = null): mixed
    {
        if ($key === '') {
            return $this->values;
        }
        return $this->values[$key] ?? null;
    }
    public function hasData(string $key = ''): bool
    {
        return $key === '' ? $this->values !== [] : \array_key_exists($key, $this->values);
    }
}
