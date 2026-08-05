<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Extends\Module\Weline_Framework\Query\WebsitesQueryProvider;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\SalesChannelCatalog;
use Weline\Websites\Service\StoreCatalog;
use Weline\Websites\Service\WebsiteStoreChannelDirectory;

final class StoreChannelCatalogContractTest extends TestCase
{
    public function testSummaryDtosAreFinalReadonlyAndProjectExactV1Fields(): void
    {
        $store = new StoreSummary(
            1,
            0,
            'default',
            '默认店铺',
            Store::MODE_NORMAL,
            true,
            true,
            Store::LIFECYCLE_ACTIVE,
            null,
            null,
        );
        $channel = new SalesChannelSummary(
            1,
            0,
            1,
            'default',
            '默认渠道',
            true,
            true,
            Store::LIFECYCLE_ACTIVE,
            true,
        );

        $storeReflection = new \ReflectionClass(StoreSummary::class);
        self::assertTrue($storeReflection->isFinal());
        self::assertTrue($storeReflection->isReadOnly());
        self::assertSame(
            [
                'store_id',
                'website_id',
                'code',
                'name',
                'store_mode',
                'is_default',
                'enabled',
                'lifecycle_status',
                'tombstoned_at',
                'url',
            ],
            \array_keys($store->toArray()),
        );

        $channelReflection = new \ReflectionClass(SalesChannelSummary::class);
        self::assertTrue($channelReflection->isFinal());
        self::assertTrue($channelReflection->isReadOnly());
        self::assertSame(
            [
                'channel_id',
                'website_id',
                'store_id',
                'code',
                'name',
                'is_default',
                'enabled',
                'parent_store_lifecycle_status',
                'effective_enabled',
            ],
            \array_keys($channel->toArray()),
        );
    }

    public function testStoreCatalogMapsZeroWebsiteActiveAndTombstoneRows(): void
    {
        $active = self::validStoreRow();
        $tombstone = self::validStoreRow([
            Store::schema_fields_ID => 2,
            Store::schema_fields_CODE => 'archived',
            Store::schema_fields_NAME => '归档店铺',
            Store::schema_fields_STORE_MODE => Store::MODE_TEST,
            Store::schema_fields_IS_DEFAULT => 0,
            Store::schema_fields_STATUS => 0,
            Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_TOMBSTONE,
            Store::schema_fields_TOMBSTONED_AT => '2026-07-24 00:00:00',
        ]);

        /** @var list<StoreSummary> $mapped */
        $mapped = self::invokePrivate($this->storeCatalog(), 'mapRows', [[$active, $tombstone], 0]);

        self::assertCount(2, $mapped);
        self::assertSame(0, $mapped[0]->websiteId);
        self::assertTrue($mapped[0]->isDefault);
        self::assertSame(Store::LIFECYCLE_ACTIVE, $mapped[0]->lifecycleStatus);
        self::assertFalse($mapped[1]->enabled);
        self::assertSame(Store::LIFECYCLE_TOMBSTONE, $mapped[1]->lifecycleStatus);
        self::assertSame('2026-07-24 00:00:00', $mapped[1]->tombstonedAt);
    }

    /** @param list<array<string, mixed>> $rows */
    #[DataProvider('invalidStoreRows')]
    public function testStoreCatalogRejectsInvalidV1Rows(array $rows, array $messages): void
    {
        try {
            self::invokePrivate($this->storeCatalog(), 'mapRows', [$rows, 0]);
            self::fail('Invalid Store Catalog rows must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertMessageContainsAny($exception->getMessage(), $messages);
        }
    }

    /** @return iterable<string, array{list<array<string, mixed>>, list<string>}> */
    public static function invalidStoreRows(): iterable
    {
        yield 'non-canonical code' => [[self::validStoreRow([
            Store::schema_fields_CODE => 'Bad Code',
            Store::schema_fields_IS_DEFAULT => 0,
        ])], ['不是规范值', 'non-canonical code']];

        yield 'code above 64 characters' => [[self::validStoreRow([
            Store::schema_fields_CODE => \str_repeat('a', Store::CODE_MAX_LENGTH + 1),
            Store::schema_fields_IS_DEFAULT => 0,
        ])], ['店铺代码不能超过', 'Store code cannot exceed']];

        yield 'name above 128 characters' => [[self::validStoreRow([
            Store::schema_fields_CODE => 'secondary',
            Store::schema_fields_NAME => \str_repeat('店', Store::NAME_MAX_LENGTH + 1),
            Store::schema_fields_IS_DEFAULT => 0,
        ])], ['店铺名称不能超过', 'Store name cannot exceed']];

        yield 'active row with tombstone timestamp' => [[self::validStoreRow([
            Store::schema_fields_CODE => 'active_with_tombstone',
            Store::schema_fields_IS_DEFAULT => 0,
            Store::schema_fields_TOMBSTONED_AT => '2026-07-24 00:00:00',
        ])], ['不允许存在墓碑时间', 'cannot have a tombstone timestamp']];

        yield 'enabled tombstone' => [[self::validStoreRow([
            Store::schema_fields_CODE => 'enabled_tombstone',
            Store::schema_fields_IS_DEFAULT => 0,
            Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_TOMBSTONE,
            Store::schema_fields_TOMBSTONED_AT => '2026-07-24 00:00:00',
        ])], ['必须处于停用状态', 'must be disabled']];

        yield 'default marker mismatch' => [[self::validStoreRow([
            Store::schema_fields_CODE => 'secondary',
        ])], ['default 代码与默认标记不一致', 'inconsistent default code and flag']];

        yield 'duplicate default codes' => [[
            self::validStoreRow(),
            self::validStoreRow([
                Store::schema_fields_ID => 2,
                Store::schema_fields_NAME => '第二默认店铺',
            ]),
        ], ['店铺代码重复', 'duplicate Store code']];
    }

    public function testSalesChannelEffectiveEnabledFollowsParentStore(): void
    {
        $activeParent = new StoreSummary(
            11,
            0,
            'web',
            '网站店铺',
            Store::MODE_DEV,
            false,
            true,
            Store::LIFECYCLE_ACTIVE,
            null,
        );
        $tombstoneParent = new StoreSummary(
            11,
            0,
            'web',
            '网站店铺',
            Store::MODE_DEV,
            false,
            false,
            Store::LIFECYCLE_TOMBSTONE,
            '2026-07-24 00:00:00',
        );
        $catalog = $this->salesChannelCatalog($activeParent);
        $row = self::validChannelRow();

        /** @var list<SalesChannelSummary> $active */
        $active = self::invokePrivate($catalog, 'mapRows', [[$row], 11, $activeParent]);
        /** @var list<SalesChannelSummary> $tombstone */
        $tombstone = self::invokePrivate($catalog, 'mapRows', [[$row], 11, $tombstoneParent]);

        self::assertTrue($active[0]->effectiveEnabled);
        self::assertSame(Store::LIFECYCLE_ACTIVE, $active[0]->parentStoreLifecycleStatus);
        self::assertFalse($tombstone[0]->effectiveEnabled);
        self::assertSame(Store::LIFECYCLE_TOMBSTONE, $tombstone[0]->parentStoreLifecycleStatus);
    }

    public function testSalesChannelCatalogRejectsCrossWebsiteParent(): void
    {
        $parent = new StoreSummary(
            11,
            0,
            'web',
            '网站店铺',
            Store::MODE_DEV,
            false,
            true,
            Store::LIFECYCLE_ACTIVE,
            null,
        );
        $row = self::validChannelRow([SalesChannel::schema_fields_WEBSITE_ID => 9]);

        try {
            self::invokePrivate($this->salesChannelCatalog($parent), 'mapRows', [[$row], 11, $parent]);
            self::fail('A cross-Website parent Store must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertMessageContainsAny(
                $exception->getMessage(),
                ['与父店铺的 Website 归属不一致', "does not belong to the parent Store's Website"],
            );
        }
    }

    #[DataProvider('invalidSalesChannelRows')]
    public function testSalesChannelCatalogRejectsInvalidV1Rows(array $row, array $messages): void
    {
        $parent = new StoreSummary(
            11,
            0,
            'web',
            '网站店铺',
            Store::MODE_DEV,
            false,
            true,
            Store::LIFECYCLE_ACTIVE,
            null,
        );

        try {
            self::invokePrivate($this->salesChannelCatalog($parent), 'mapRows', [[$row], 11, $parent]);
            self::fail('Invalid SalesChannel Catalog row must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertMessageContainsAny($exception->getMessage(), $messages);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidSalesChannelRows(): iterable
    {
        yield 'code above 64 characters' => [self::validChannelRow([
            SalesChannel::schema_fields_CODE => \str_repeat('a', SalesChannel::CODE_MAX_LENGTH + 1),
        ]), ['渠道代码不能超过', 'SalesChannel code cannot exceed']];

        yield 'name above 128 characters' => [self::validChannelRow([
            SalesChannel::schema_fields_NAME => \str_repeat('渠', SalesChannel::NAME_MAX_LENGTH + 1),
        ]), ['渠道名称不能超过', 'SalesChannel name cannot exceed']];
    }

    public function testSalesChannelByCodeRejectsOversizedStoreIdBeforeBlankCodeShortcut(): void
    {
        $parent = new StoreSummary(
            11,
            0,
            'web',
            '网站店铺',
            Store::MODE_DEV,
            false,
            true,
            Store::LIFECYCLE_ACTIVE,
            null,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->salesChannelCatalog($parent)->byCode(2147483648, '');
    }

    public function testDirectoryUsesCatalogContractsAndBuildsNestedProjection(): void
    {
        $store = new StoreSummary(
            1,
            0,
            'default',
            '默认店铺',
            Store::MODE_NORMAL,
            true,
            true,
            Store::LIFECYCLE_ACTIVE,
            null,
        );
        $channel = new SalesChannelSummary(
            1,
            0,
            1,
            'default',
            '默认渠道',
            true,
            true,
            Store::LIFECYCLE_ACTIVE,
            true,
        );
        $directory = new WebsiteStoreChannelDirectory(
            new FixedStoreCatalog($store),
            new FixedSalesChannelCatalog($channel),
        );

        $rows = $directory->forWebsite(0);

        self::assertCount(1, $rows);
        self::assertSame(0, $rows[0]['website_id']);
        self::assertSame('default', $rows[0]['code']);
        self::assertSame([$channel->toArray()], $rows[0]['channels']);
    }

    public function testStoreAndSalesChannelWriteSurfacesRemainClosed(): void
    {
        foreach ([
            BP . 'app/code/Weline/Websites/Controller/Admin/Store.php',
            BP . 'app/code/Weline/Websites/Controller/Admin/SalesChannel.php',
            BP . 'app/code/Weline/Websites/Controller/Backend/Store.php',
            BP . 'app/code/Weline/Websites/Controller/Backend/SalesChannel.php',
        ] as $controller) {
            self::assertFileDoesNotExist($controller);
        }

        $menu = (string)\file_get_contents(BP . 'app/code/Weline/Websites/etc/backend/menu.xml');
        self::assertDoesNotMatchRegularExpression(
            '/action=["\'][^"\']*(?:sales[-_]?channel|\/store)(?:\/|["\'])/i',
            $menu,
        );

        $index = (string)\file_get_contents(
            BP . 'app/code/Weline/Websites/view/templates/Admin/Website/index.phtml',
        );
        self::assertStringContainsString('method="get"', $index);
        self::assertStringContainsString('<label for="search-input"', $index);

        $form = (string)\file_get_contents(
            BP . 'app/code/Weline/Websites/view/templates/Admin/Website/form.phtml',
        );
        $table = (string)\file_get_contents(
            BP . 'app/code/Weline/Websites/view/templates/Admin/Website/table.phtml',
        );
        foreach ([$form, $table] as $template) {
            self::assertDoesNotMatchRegularExpression(
                '/<(?:input|select|textarea)[^>]+name=["\'][^"\']*(?:store|channel)/i',
                $template,
            );
            self::assertDoesNotMatchRegularExpression(
                '/(?:href|action)=["\'][^"\']*(?:sales[-_]?channel|\/store)(?:\/|["\'])/i',
                $template,
            );
        }
        self::assertStringContainsString('.accordion-button:focus-visible', $form);
        self::assertStringNotContainsString('.accordion-button:focus {', $form);
    }

    public function testQueryProviderPublishesOnlyTheTwoReadOnlyV1CatalogOperations(): void
    {
        $provider = ObjectManager::getInstance(WebsitesQueryProvider::class);
        $operations = $provider->getDescriptor()['operations'] ?? [];
        self::assertIsArray($operations);
        $catalogOperations = \array_values(\array_filter(
            $operations,
            static fn(array $operation): bool => \in_array(
                $operation['name'] ?? '',
                ['getStoreCatalogV1', 'getSalesChannelCatalogV1'],
                true,
            ),
        ));

        self::assertSame(
            ['getStoreCatalogV1', 'getSalesChannelCatalogV1'],
            \array_column($catalogOperations, 'name'),
        );
        foreach ($catalogOperations as $operation) {
            self::assertSame('v1', $operation['contract_version'] ?? null);
            self::assertSame('read', $operation['mode'] ?? null);
            // frontend=true 允许后台 Weline.Api worker；auth=backend + external=false 关闭前台匿名/外联
            self::assertTrue($operation['frontend'] ?? false);
            self::assertSame('backend', $operation['auth'] ?? null);
            self::assertTrue($operation['backend'] ?? false);
            self::assertFalse($operation['external'] ?? true);
            self::assertFalse($operation['graph'] ?? true);
        }

        self::assertSame(0, $catalogOperations[0]['params'][0]['min'] ?? null);
        self::assertSame(1, $catalogOperations[1]['params'][0]['min'] ?? null);
        self::assertSame(2147483647, $catalogOperations[0]['params'][0]['max'] ?? null);
        self::assertSame(2147483647, $catalogOperations[1]['params'][0]['max'] ?? null);
        self::assertSame(
            [
                'store_id',
                'website_id',
                'code',
                'name',
                'store_mode',
                'is_default',
                'enabled',
                'lifecycle_status',
                'tombstoned_at',
                'url',
            ],
            \array_column($catalogOperations[0]['returns']['items']['fields'] ?? [], 'name'),
        );
        self::assertSame(
            [
                'channel_id',
                'website_id',
                'store_id',
                'code',
                'name',
                'is_default',
                'enabled',
                'parent_store_lifecycle_status',
                'effective_enabled',
            ],
            \array_column($catalogOperations[1]['returns']['items']['fields'] ?? [], 'name'),
        );

        $storeOrChannelOperations = \array_values(\array_filter(
            $operations,
            static fn(array $operation): bool => \preg_match(
                '/(?:Store|SalesChannel)/D',
                (string)($operation['name'] ?? ''),
            ) === 1,
        ));
        self::assertCount(2, $storeOrChannelOperations);
    }

    #[DataProvider('invalidCatalogParams')]
    public function testQueryProviderRejectsNonCanonicalCatalogParameters(
        string $operation,
        array $params,
    ): void {
        $provider = ObjectManager::getInstance(WebsitesQueryProvider::class);

        $this->expectException(\InvalidArgumentException::class);
        $provider->execute($operation, $params);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidCatalogParams(): iterable
    {
        yield 'missing website' => ['getStoreCatalogV1', []];
        yield 'non-canonical website' => ['getStoreCatalogV1', ['website_id' => '00']];
        yield 'negative website' => ['getStoreCatalogV1', ['website_id' => -1]];
        yield 'website above signed int' => ['getStoreCatalogV1', ['website_id' => 2147483648]];
        yield 'website with extra parameter' => [
            'getStoreCatalogV1',
            ['website_id' => 0, 'unexpected' => true],
        ];
        yield 'missing store' => ['getSalesChannelCatalogV1', []];
        yield 'zero store' => ['getSalesChannelCatalogV1', ['store_id' => 0]];
        yield 'store above signed int' => ['getSalesChannelCatalogV1', ['store_id' => 2147483648]];
        yield 'store with extra parameter' => [
            'getSalesChannelCatalogV1',
            ['store_id' => 1, 'unexpected' => true],
        ];
    }

    public function testCatalogEnglishTranslationsContainNoChineseAndPreservePlaceholders(): void
    {
        $keys = [];
        foreach ([StoreCatalog::class, SalesChannelCatalog::class, WebsiteStoreChannelDirectory::class] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($file);
            $source = \file_get_contents($file);
            self::assertIsString($source);
            self::assertGreaterThan(0, \preg_match_all("/__\\(\\s*'([^']+)'/u", $source, $matches));
            $keys = \array_merge($keys, $matches[1]);
        }

        $keys = \array_values(\array_unique(\array_merge($keys, [
            '按网站读取店铺目录 v1',
            '网站 ID；0 是合法的系统默认站点',
            '按店铺读取销售渠道目录 v1',
            '目录查询缺少必填参数：%{1}',
            '目录查询只允许参数：%{1}',
            '目录查询参数 %{1} 超出整数范围',
            '目录查询参数 %{1} 必须是规范整数',
            '目录查询参数 %{1} 必须大于或等于 %{2}',
            '目录查询参数 %{1} 不能大于 %{2}',
            '正式',
            '开发',
            '测试',
            '活动',
            '墓碑',
            'Store / SalesChannel 目录',
            'Store 与 SalesChannel 只读目录',
            '%{1} 个 Store',
            '只读',
            '暂无 Store/Channel 目录，请运行 setup:upgrade 补齐默认目录。',
            '默认',
            '启用',
            '停用',
            '此 Store 暂无 SalesChannel',
            'SalesChannel 列表',
            '有效',
            '不可用',
            'Store / SalesChannel',
            '列表只读展示站点下的店铺模式、生命周期与销售渠道，不在此处修改目录。',
            'Store / SalesChannel 只读目录',
            '此处仅展示目录。Store 模式、生命周期与 SalesChannel 不会随 Website 表单保存而修改。',
            '保存 Website 后，系统会在升级与站点保存流程中建立默认 Store 和 SalesChannel。',
            '当前 Website 暂无 Store/Channel 目录，请运行 setup:upgrade 补齐默认目录。',
            '网站目录行缺少 website_id',
        ])));
        self::assertGreaterThanOrEqual(80, \count($keys));

        $translations = self::loadTranslations(
            BP . 'app/code/Weline/Websites/i18n/en_US.csv',
        );
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $translations, 'Missing en_US translation for: ' . $key);
            $translation = $translations[$key];
            self::assertNotSame('', \trim($translation), 'Empty en_US translation for: ' . $key);
            self::assertDoesNotMatchRegularExpression(
                '/\p{Han}/u',
                $translation,
                'en_US translation still contains Chinese for: ' . $key,
            );
            self::assertSame(
                self::placeholders($key),
                self::placeholders($translation),
                'Translation placeholders differ for: ' . $key,
            );
        }
    }

    /** @return array<string, string> */
    private static function loadTranslations(string $file): array
    {
        $handle = \fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open translation file: ' . $file);
        }

        $translations = [];
        try {
            while (($row = \fgetcsv($handle, null, ',', '"', '\\')) !== false) {
                if (\count($row) < 2) {
                    continue;
                }
                $translations[(string)$row[0]] = (string)$row[1];
            }
        } finally {
            \fclose($handle);
        }

        return $translations;
    }

    /** @return list<string> */
    private static function placeholders(string $value): array
    {
        \preg_match_all('/%\{[^}]+}/u', $value, $matches);
        $placeholders = $matches[0];
        \sort($placeholders);
        return $placeholders;
    }

    private function storeCatalog(): StoreCatalog
    {
        return new StoreCatalog(
            ObjectManager::getInstance(Store::class, [], false),
            ObjectManager::getInstance(Website::class, [], false),
        );
    }

    private function salesChannelCatalog(StoreSummary $parent): SalesChannelCatalog
    {
        return new SalesChannelCatalog(
            ObjectManager::getInstance(SalesChannel::class, [], false),
            new FixedStoreCatalog($parent),
        );
    }

    /** @param array<string, mixed> $replace */
    private static function validStoreRow(array $replace = []): array
    {
        return \array_replace([
            Store::schema_fields_ID => 1,
            Store::schema_fields_WEBSITE_ID => 0,
            Store::schema_fields_CODE => 'default',
            Store::schema_fields_NAME => '默认店铺',
            Store::schema_fields_STORE_MODE => Store::MODE_NORMAL,
            Store::schema_fields_IS_DEFAULT => 1,
            Store::schema_fields_STATUS => 1,
            Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_ACTIVE,
            Store::schema_fields_TOMBSTONED_AT => null,
            Store::schema_fields_URL => null,
        ], $replace);
    }

    /** @param array<string, mixed> $replace */
    private static function validChannelRow(array $replace = []): array
    {
        return \array_replace([
            SalesChannel::schema_fields_ID => 21,
            SalesChannel::schema_fields_WEBSITE_ID => 0,
            SalesChannel::schema_fields_STORE_ID => 11,
            SalesChannel::schema_fields_CODE => 'web',
            SalesChannel::schema_fields_NAME => '网站渠道',
            SalesChannel::schema_fields_IS_DEFAULT => 0,
            SalesChannel::schema_fields_STATUS => 1,
        ], $replace);
    }

    /** @param non-empty-list<string> $expectedFragments */
    private static function assertMessageContainsAny(string $actual, array $expectedFragments): void
    {
        foreach ($expectedFragments as $expectedFragment) {
            if (\str_contains($actual, $expectedFragment)) {
                self::assertTrue(true);
                return;
            }
        }

        self::fail(
            'Failed asserting that the localized message contains one of: '
            . \implode(' | ', $expectedFragments)
            . '. Actual: '
            . $actual,
        );
    }

    /** @param list<mixed> $arguments */
    private static function invokePrivate(object $target, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($target, $arguments);
    }
}

final class FixedStoreCatalog implements StoreCatalogInterface
{
    public function __construct(private readonly StoreSummary $store)
    {
    }

    public function byWebsite(int $websiteId): array
    {
        return $websiteId === $this->store->websiteId ? [$this->store] : [];
    }

    public function byCode(int $websiteId, string $storeCode): ?StoreSummary
    {
        return $websiteId === $this->store->websiteId && $storeCode === $this->store->code
            ? $this->store
            : null;
    }

    public function byId(int $storeId): ?StoreSummary
    {
        return $storeId === $this->store->id ? $this->store : null;
    }

    public function defaultStore(int $websiteId): ?StoreSummary
    {
        return $websiteId === $this->store->websiteId && $this->store->isDefault
            ? $this->store
            : null;
    }

    public function all(): array
    {
        return [$this->store];
    }
}

final class FixedSalesChannelCatalog implements SalesChannelCatalogInterface
{
    public function __construct(private readonly SalesChannelSummary $channel)
    {
    }

    public function byStore(int $storeId): array
    {
        return $storeId === $this->channel->storeId ? [$this->channel] : [];
    }

    public function byCode(int $storeId, string $channelCode): ?SalesChannelSummary
    {
        return $storeId === $this->channel->storeId && $channelCode === $this->channel->code
            ? $this->channel
            : null;
    }

    public function byId(int $channelId): ?SalesChannelSummary
    {
        return $channelId === $this->channel->id ? $this->channel : null;
    }

    public function defaultChannel(int $storeId): ?SalesChannelSummary
    {
        return $storeId === $this->channel->storeId && $this->channel->isDefault
            ? $this->channel
            : null;
    }
}
