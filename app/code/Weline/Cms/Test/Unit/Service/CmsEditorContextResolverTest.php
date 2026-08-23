<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Model\Page;
use Weline\Cms\Service\CmsEditorContextResolver;
use Weline\Cms\Service\PageLocaleService;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Layout\LayoutScopeNormalizerInterface;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class CmsEditorContextResolverTest extends TestCase
{
    public function testDefaultWebsiteZeroUsesItsDefaultStoreAndCanonicalScope(): void
    {
        $store = $this->store(4, 0, 'default', true, true);
        $resolver = $this->resolver(
            defaultStore: $store,
            byId: [$store->id => $store],
            byWebsite: [$store],
        );

        $context = $resolver->resolve($this->page(0, 'default'), null, 'zh-hans-cn');

        self::assertSame(0, $context->websiteId);
        self::assertSame(4, $context->storeId);
        self::assertSame('zh_Hans_CN', $context->localeCode);
        self::assertSame('default.__store__.default', $context->canonicalScope);
        self::assertSame(ScopeIdentity::KIND_STORE, $context->scopeIdentity->scopeKind);
        self::assertTrue($context->defaultStore);
    }

    public function testExplicitStoreMustBelongToPageWebsite(): void
    {
        $foreign = $this->store(8, 2, 'foreign', false, true);
        $resolver = $this->resolver(
            defaultStore: null,
            byId: [$foreign->id => $foreign],
            byWebsite: [],
        );

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolve($this->page(1, 'shop'), $foreign->id, 'en_US');
    }

    public function testDisabledStoreCanKeepDraftButCannotPublish(): void
    {
        $disabled = $this->store(7, 1, 'outlet', false, false);
        $resolver = $this->resolver(
            defaultStore: $disabled,
            byId: [$disabled->id => $disabled],
            byWebsite: [$disabled],
        );

        $draftContext = $resolver->resolve($this->page(1, 'shop'), $disabled->id, 'en_US', false);
        self::assertFalse($draftContext->storeEnabled);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolve($this->page(1, 'shop'), $disabled->id, 'en_US', true);
    }

    public function testMissingDefaultStoreFailsExplicitly(): void
    {
        $resolver = $this->resolver(defaultStore: null, byId: [], byWebsite: []);

        $this->expectException(\RuntimeException::class);
        $resolver->resolve($this->page(1, 'shop'), null, 'en_US');
    }

    public function testStoreOptionsExcludeTombstonesAndCarryServerCanonicalScope(): void
    {
        $active = $this->store(3, 1, 'main', true, true);
        $tombstone = new StoreSummary(
            9,
            1,
            'retired',
            'Retired',
            ScopeIdentity::MODE_NORMAL,
            false,
            false,
            'tombstoned',
            '2026-08-22 12:00:00',
        );
        $resolver = $this->resolver(
            defaultStore: $active,
            byId: [$active->id => $active, $tombstone->id => $tombstone],
            byWebsite: [$active, $tombstone],
        );

        $options = $resolver->activeStoreOptions($this->page(1, 'shop'));

        self::assertCount(1, $options);
        self::assertSame(3, $options[0]['store_id']);
        self::assertSame('shop.__store__.default', $options[0]['canonical_scope']);
    }

    /**
     * @param array<int,StoreSummary> $byId
     * @param list<StoreSummary> $byWebsite
     */
    private function resolver(
        ?StoreSummary $defaultStore,
        array $byId,
        array $byWebsite,
    ): CmsEditorContextResolver {
        $stores = $this->createMock(StoreCatalogInterface::class);
        $stores->method('defaultStore')->willReturn($defaultStore);
        $stores->method('byId')->willReturnCallback(
            static fn (int $storeId): ?StoreSummary => $byId[$storeId] ?? null,
        );
        $stores->method('byWebsite')->willReturn($byWebsite);

        $hierarchy = $this->createMock(ScopeHierarchyInterface::class);
        $hierarchy->method('toStorageScope')->willReturnCallback(
            static function (ScopeIdentity $identity): string {
                if ($identity->storeCode === 'default' || $identity->storeCode === 'main') {
                    return $identity->websiteCode . '.__store__.default';
                }
                return $identity->websiteCode . '.' . $identity->storeCode . '.default';
            },
        );
        $normalizer = $this->createMock(LayoutScopeNormalizerInterface::class);
        $normalizer->method('encodeStorageScope')->willReturnCallback(
            static fn (string $scope, string $mode): string => $mode === ScopeIdentity::MODE_NORMAL
                ? $scope
                : $scope . '~' . $mode,
        );
        $locales = $this->getMockBuilder(PageLocaleService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['assertWebsiteLocale', 'resolveSourceLocale'])
            ->getMock();
        $locales->method('assertWebsiteLocale')->willReturnCallback(
            static function (int $_websiteId, string $locale): string {
                $parts = explode('_', str_replace('-', '_', $locale));
                $parts[0] = strtolower($parts[0]);
                if (isset($parts[1])) {
                    $parts[1] = strlen($parts[1]) === 4
                        ? ucfirst(strtolower($parts[1]))
                        : strtoupper($parts[1]);
                }
                if (isset($parts[2])) {
                    $parts[2] = strtoupper($parts[2]);
                }
                return implode('_', $parts);
            },
        );
        $locales->method('resolveSourceLocale')->willReturn('en_US');

        return new CmsEditorContextResolver($stores, $hierarchy, $normalizer, $locales);
    }

    private function page(int $websiteId, string $websiteCode): Page
    {
        return new Page([
            Page::schema_fields_ID => 12,
            Page::schema_fields_WEBSITE_ID => $websiteId,
            Page::schema_fields_WEBSITE_CODE => $websiteCode,
            Page::schema_fields_TITLE => 'Page',
            Page::schema_fields_SOURCE_LOCALE => 'en_US',
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
        ]);
    }

    private function store(
        int $id,
        int $websiteId,
        string $code,
        bool $isDefault,
        bool $enabled,
    ): StoreSummary {
        return new StoreSummary(
            $id,
            $websiteId,
            $code,
            ucfirst($code),
            ScopeIdentity::MODE_NORMAL,
            $isDefault,
            $enabled,
            'active',
            null,
        );
    }
}
