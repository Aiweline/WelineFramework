<?php

declare(strict_types=1);

namespace {
    if (!\function_exists('__')) {
        function __(string $text, array|string|int $params = ''): string
        {
            $out = $text;
            if (!\is_array($params)) {
                $params = $params === '' ? [] : [$params];
            }
            foreach ($params as $i => $value) {
                $out = str_replace('%{' . ($i + 1) . '}', (string)$value, $out);
            }

            return $out;
        }
    }
}

namespace Weline\Order\Test\Unit\Service {

use PHPUnit\Framework\TestCase;
use Weline\Order\Model\Order;
use Weline\Order\Service\AccountCheckoutGroupLoader;
use Weline\Order\Service\AccountCheckoutGroupPresenter;
use Weline\Order\Service\AccountOrderScopePresenter;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class AccountOrderScopeContractTest extends TestCase
{
    public function testLoaderAcceptsStoreChannelFiltersAndExposesScopeDto(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AccountCheckoutGroupLoader.php'
        );
        self::assertStringContainsString('?int $storeId = null', $src);
        self::assertStringContainsString('?int $channelId = null', $src);
        self::assertStringContainsString('Order::schema_fields_STORE_ID', $src);
        self::assertStringContainsString('buildScopeFilter', $src);
        self::assertStringContainsString("'scope' =>", $src);
        self::assertStringContainsString('rowMatchesChannel', $src);
        self::assertStringContainsString('AccountOrderScopePresenter', $src);
    }

    public function testPresenterExposesScopeLabelFields(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AccountCheckoutGroupPresenter.php'
        );
        self::assertStringContainsString("'scope_label' =>", $src);
        self::assertStringContainsString("'scope_store_id' =>", $src);
        self::assertStringContainsString("'scope_display_type' =>", $src);
        self::assertStringContainsString('resolveScope', $src);

        $presenter = new AccountCheckoutGroupPresenter();
        $view = $presenter->present([
            'group_uuid' => 'g-scope-1',
            'display_number' => 'G-scope',
            'status' => 'paid',
            'grand_total_minor' => 1000,
            'currency' => 'CNY',
            'scope' => [
                'store_id' => 997,
                'store_code' => 'b2b',
                'store_name' => 'B2B Store',
                'channel_id' => 0,
                'channel_code' => '',
                'channel_name' => '',
                'display_type' => 'b2b',
                'display_type_label' => 'B2B',
                'label' => 'B2B Store · B2B',
            ],
            'orders' => [[
                'order_uuid' => 'ord-1',
                'display_number' => '1',
                'status' => 'paid',
                'amount_minor' => 1000,
                'refund_status' => 'none',
                'invoice_status' => 'none',
                'fulfillment_status' => 'none',
            ]],
        ]);

        self::assertSame('B2B Store · B2B', $view['scope_label']);
        self::assertSame(997, $view['scope_store_id']);
        self::assertSame('b2b', $view['scope_store_code']);
        self::assertSame('b2b', $view['scope_display_type']);
    }

    public function testScopePresenterFallsBackToSnapshotWhenCatalogMissing(): void
    {
        $emptyStores = new class implements StoreCatalogInterface {
            public function byWebsite(int $websiteId): array
            {
                return [];
            }

            public function byCode(int $websiteId, string $storeCode): ?StoreSummary
            {
                return null;
            }

            public function byId(int $storeId): ?StoreSummary
            {
                return null;
            }

            public function defaultStore(int $websiteId): ?StoreSummary
            {
                return null;
            }

            public function all(): array
            {
                return [];
            }
        };
        $emptyChannels = new class implements SalesChannelCatalogInterface {
            public function byStore(int $storeId): array
            {
                return [];
            }

            public function byCode(int $storeId, string $channelCode): ?SalesChannelSummary
            {
                return null;
            }

            public function byId(int $channelId): ?SalesChannelSummary
            {
                return null;
            }

            public function defaultChannel(int $storeId): ?SalesChannelSummary
            {
                return null;
            }

            public function defaultChannelForStore(StoreSummary $store): ?SalesChannelSummary
            {
                return null;
            }
        };

        $presenter = new AccountOrderScopePresenter($emptyStores, $emptyChannels, null, null);
        $scope = $presenter->presentFromOrderRow([
            Order::schema_fields_WEBSITE_ID => 0,
            Order::schema_fields_STORE_ID => 997,
            Order::schema_fields_SCOPE_SNAPSHOT_JSON => json_encode([
                'website_id' => 0,
                'store_id' => 997,
                'store_code' => 'b2b-main',
                'store_name' => '批发店',
                'channel_id' => 3,
                'channel_code' => 'web',
                'channel_name' => '官网',
                'display_type' => 'b2b',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        self::assertSame(997, $scope['store_id']);
        self::assertSame('b2b-main', $scope['store_code']);
        self::assertSame('批发店', $scope['store_name']);
        self::assertSame(3, $scope['channel_id']);
        self::assertStringContainsString('批发店', $scope['label']);
        self::assertStringContainsString('官网', $scope['label']);
        self::assertSame('b2b', $scope['display_type']);
    }

    public function testLoaderClassExists(): void
    {
        self::assertTrue(class_exists(AccountCheckoutGroupLoader::class));
        self::assertTrue(class_exists(AccountOrderScopePresenter::class));
    }
}
}
