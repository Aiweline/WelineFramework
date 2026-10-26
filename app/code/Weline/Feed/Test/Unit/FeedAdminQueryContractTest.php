<?php

declare(strict_types=1);

namespace Weline\Feed\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Feed\Api\Data\FeedChannelAccount;
use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedConnectionState;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedDocument;
use Weline\Feed\Api\FeedArtifactWriterInterface;
use Weline\Feed\Api\FeedCategoryMapReaderInterface;
use Weline\Feed\Api\FeedStoreCategoryReaderInterface;
use Weline\Feed\Extends\Module\Weline_Framework\Query\FeedAdminQueryProvider;
use Weline\Feed\Service\CatalogFeedSource;
use Weline\Feed\Service\Channel\NaverShoppingChannel;
use Weline\Feed\Service\Channel\YandexMarketChannel;
use Weline\Feed\Service\FeedChannelRegistry;
use Weline\Feed\Service\FeedConnectionRegistry;
use Weline\Feed\Service\FeedGenerationService;
use Weline\Feed\Service\FeedOperatorConnectionStatus;
use Weline\Product\Api\CatalogFeedOfferReaderInterface;
use Weline\Product\Api\Data\CatalogFeedOffer;
use Weline\Product\Api\Data\CatalogFeedReadQuery;

final class FeedAdminQueryContractTest extends TestCase
{
    public function testMissingCredentialsAreNotConnected(): void
    {
        $provider = new FeedAdminQueryProvider();
        $status = $provider->connectionStatus('google_merchant', []);
        self::assertSame(FeedOperatorConnectionStatus::DISCONNECTED, $status);
        self::assertSame('未连接', $status);
        self::assertNotSame('已授权', $status);
        self::assertNotSame('已连接', $status);
        self::assertSame(
            '未连接',
            $provider->connectionStatus('microsoft_merchant', [
                'client_id' => 'app',
                'client_secret' => 'secret',
            ]),
        );
        self::assertNotSame(
            '已授权',
            FeedOperatorConnectionStatus::resolve('pinterest_catalog', []),
        );
        $names = array_column($provider->getDescriptor()['operations'], 'name');
        self::assertSame(
            [
                'listFeeds',
                'saveFeed',
                'setFeedEnabled',
                'archiveFeed',
                'generateFeed',
                'listCategoryMaps',
                'saveCategoryMap',
                'deleteCategoryMap',
                'getScopeFeedMailbox',
                'ensureScopeFeedMailbox',
                'resetScopeFeedMailboxPassword',
            ],
            $names,
        );
    }

    public function testYandexUnmappedOfferIsLeftOutOfTheDocument(): void
    {
        $reader = $this->reader('offer-unmapped');
        $writer = $this->writer();
        $report = $this->generate($reader, $writer, 'yandex_market', null, null);

        self::assertTrue($reader->called);
        self::assertTrue($report->completed);
        self::assertSame(0, $report->itemCount);
        self::assertSame(1, $report->skippedCount);
        self::assertSame('未连接', $report->connectionStatus);
        self::assertNotSame('已授权', $report->connectionStatus);
        self::assertNotSame('已连接', $report->connectionStatus);
        self::assertSame('category_unmapped', $report->diagnostics[0]['code']);
        self::assertSame('offer-unmapped', $report->diagnostics[0]['offer_id']);
        self::assertNotSame('', trim($report->body));
        self::assertStringContainsString('<yml_catalog', $report->body);
        self::assertStringNotContainsString('offer-unmapped', $report->body);
        self::assertStringNotContainsString('<categoryId>', $report->body);
        self::assertSame(FeedConnectionState::ADDRESS_ONLY, $writer->ready[0]['state']);
        self::assertNotSame(FeedConnectionState::AUTHORIZED, $writer->ready[0]['state']);

        $fake = $this->generate(
            $this->reader('offer-fake'),
            $this->writer(),
            'yandex_market',
            $this->maps([[
                'website_id' => 1,
                'vocabulary' => 'yandex',
                'store_category_id' => 7,
                'map_value' => '女装',
                'map_label' => '女装',
            ]]),
            $this->categories(),
        );
        self::assertStringNotContainsString('offer-fake', $fake->body);
        self::assertSame('category_unmapped', $fake->diagnostics[0]['code']);

        $mapped = $this->generate(
            $this->reader('offer-mapped'),
            $this->writer(),
            'yandex_market',
            $this->maps([[
                'website_id' => 1,
                'vocabulary' => 'yandex',
                'store_category_id' => 7,
                'map_value' => '42',
                'map_label' => 'Платья',
            ]]),
            $this->categories(),
        );
        self::assertSame(1, $mapped->itemCount);
        self::assertSame(0, $mapped->skippedCount);
        self::assertStringContainsString('offer-mapped', $mapped->body);
        self::assertStringContainsString('<categoryId>42</categoryId>', $mapped->body);
        self::assertStringContainsString('<category id="42">', $mapped->body);
        self::assertStringNotContainsString('<categoryId>1</categoryId>', $mapped->body);
        self::assertSame([], $mapped->diagnostics);

        $google = $this->generate($this->reader('offer-google'), $this->writer(), 'google_merchant', null, null);
        self::assertSame(1, $google->itemCount);
        self::assertStringContainsString('offer-google', $google->body);
        self::assertStringNotContainsString('google_product_category', $google->body);
        self::assertSame('category_unmapped', $google->diagnostics[0]['code']);
        self::assertSame('未连接', $google->connectionStatus);
    }

    public function testMappedNaverRowKeepsShippingAndCategory(): void
    {
        $report = $this->generate(
            $this->reader('offer-naver'),
            $this->writer(),
            'naver_shopping',
            $this->maps([[
                'website_id' => 1,
                'vocabulary' => 'naver',
                'store_category_id' => 7,
                'map_value' => '의류',
                'map_label' => '',
            ]]),
            $this->categories(),
        );

        self::assertSame(1, $report->itemCount);
        self::assertStringContainsString('offer-naver', $report->body);
        self::assertStringContainsString('의류', $report->body);
        self::assertStringContainsString("\t0\n", $report->body);
        self::assertSame(NaverShoppingChannel::SHIPPING_UNCONFIGURED, $report->errorCode);
        self::assertNotSame('已授权', $report->connectionStatus);
    }

    private function generate(
        CatalogFeedOfferReaderInterface $reader,
        FeedArtifactWriterInterface $writer,
        string $channel,
        ?FeedCategoryMapReaderInterface $maps,
        ?FeedStoreCategoryReaderInterface $categories,
    ): \Weline\Feed\Api\Data\FeedGenerationReport {
        $registry = new FeedChannelRegistry([
            $channel === 'yandex_market' ? new YandexMarketChannel() : (
                $channel === 'naver_shopping' ? new NaverShoppingChannel() : new \Weline\Feed\Service\Channel\GoogleMerchantChannel()
            ),
        ]);
        $service = new FeedGenerationService(
            new FeedConnectionRegistry(),
            $registry,
            new CatalogFeedSource($reader),
            $writer,
            $maps,
            $categories,
        );

        return $service->generateReport(
            new FeedDefinition(
                definitionId: 1,
                websiteId: 1,
                sourceCode: 'catalog',
                channelCode: $channel,
                locale: 'zh_Hans_CN',
                currency: 'CNY',
                title: 'Catalog',
                enabled: true,
                connectionMode: FeedConnectionMode::FEED_URL,
                accountId: 4,
                publicToken: 'tok-admin',
            ),
            new FeedChannelAccount(
                accountId: 4,
                websiteId: 1,
                channelCode: $channel,
                connectionMode: FeedConnectionMode::FEED_URL,
                displayName: 'Main',
                secretFields: [],
                fingerprint: '',
                status: FeedConnectionState::APP_UNCONFIGURED,
            ),
        );
    }

    private function reader(string $offerId): CatalogFeedOfferReaderInterface
    {
        return new class($offerId) implements CatalogFeedOfferReaderInterface {
            public bool $called = false;

            public function __construct(private string $offerId)
            {
            }

            public function read(CatalogFeedReadQuery $query): iterable
            {
                $this->called = true;
                yield new CatalogFeedOffer(
                    id: $this->offerId,
                    offerId: 9,
                    productId: 9,
                    globalOfferUuid: $this->offerId,
                    sku: 'SKU-RED',
                    title: 'Robe',
                    description: 'A robe',
                    brand: 'Atelier',
                    link: 'https://shop.example/product/robe?color=red&offer=' . $this->offerId,
                    imageLink: 'https://shop.example/media/robe.jpg',
                    additionalImageLinks: [],
                    availability: CatalogFeedOffer::AVAILABILITY_IN_STOCK,
                    currency: 'CNY',
                    priceMinor: 2000,
                    salePriceMinor: null,
                    gtin: null,
                    mpn: null,
                    itemGroupId: '9',
                    attributes: [],
                );
            }
        };
    }

    /**
     * @param list<array{website_id:int,vocabulary:string,store_category_id:int,map_value:string,map_label:string}> $rows
     */
    private function maps(array $rows): FeedCategoryMapReaderInterface
    {
        return new class($rows) implements FeedCategoryMapReaderInterface {
            public function __construct(private array $rows)
            {
            }

            public function mapsForWebsite(int $websiteId): array
            {
                return $this->rows;
            }
        };
    }

    private function categories(): FeedStoreCategoryReaderInterface
    {
        return new class implements FeedStoreCategoryReaderInterface {
            public function categories(int $websiteId, string $locale = ''): array
            {
                return [[
                    'category_id' => 7,
                    'name' => '女装',
                    'path' => '女装',
                    'depth' => 0,
                ]];
            }

            public function assignments(int $websiteId, array $productIds, int $storeId = 0): array
            {
                return [[
                    'product_id' => 9,
                    'category_id' => 7,
                    'primary' => true,
                    'depth' => 0,
                    'name' => '女装',
                ]];
            }
        };
    }

    private function writer(): FeedArtifactWriterInterface
    {
        return new class implements FeedArtifactWriterInterface {
            /** @var list<array{state:string}> */
            public array $ready = [];

            public function writeFailed(
                FeedDefinition $definition,
                FeedChannelAccount $account,
                string $errorCode,
                string $connectionState,
            ): void {
            }

            public function writeReady(
                FeedDefinition $definition,
                FeedChannelAccount $account,
                FeedDocument $document,
                int $itemCount,
                string $connectionState,
            ): void {
                $this->ready[] = ['state' => $connectionState];
            }
        };
    }
}
