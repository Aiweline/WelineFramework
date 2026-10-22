<?php

declare(strict_types=1);

namespace Weline\Feed\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Feed\Api\Data\FeedChannelAccount;
use Weline\Feed\Api\Data\FeedCollectQuery;
use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedConnectionState;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedDocument;
use Weline\Feed\Api\Data\FeedItem;
use Weline\Feed\Api\Data\FeedPublicSnapshot;
use Weline\Feed\Api\FeedArtifactWriterInterface;
use Weline\Feed\Api\FeedChannelInterface;
use Weline\Feed\Api\FeedPublicSnapshotSourceInterface;
use Weline\Feed\Api\FeedSourceInterface;
use Weline\Feed\Exception\FeedChannelUnregistered;
use Weline\Feed\Exception\FeedCredentialsMissing;
use Weline\Feed\Service\CatalogFeedSource;
use Weline\Feed\Service\FeedChannelRegistry;
use Weline\Feed\Service\FeedConnectionRegistry;
use Weline\Feed\Service\FeedGenerationService;
use Weline\Feed\Service\FeedPublicDocumentService;
use Weline\Feed\Service\FeedPublicPath;
use Weline\Product\Api\CatalogFeedOfferReaderInterface;
use Weline\Product\Api\Data\CatalogFeedOffer;
use Weline\Product\Api\Data\CatalogFeedReadQuery;

final class FeedKernelContractTest extends TestCase
{
    public function testMissingSecretsAreNotConnectedAndDoNotPublishADocument(): void
    {
        $writer = $this->writer();
        $service = $this->service($writer, new FeedChannelRegistry([]));
        $definition = $this->definition(FeedConnectionMode::OAUTH, 'tok-1', true);
        $account = $this->account(FeedConnectionMode::OAUTH, ['note' => 'super-secret-value']);

        try {
            $service->generate($definition, $account);
            self::fail('missing oauth material must fail');
        } catch (FeedCredentialsMissing $e) {
            self::assertSame('google_merchant', $e->channelCode);
            self::assertSame(FeedConnectionMode::OAUTH, $e->connectionMode);
            self::assertSame(['refresh_token', 'access_token'], $e->missingFields);
            self::assertStringNotContainsString('super-secret-value', $e->getMessage());
            self::assertStringNotContainsString('已授权', $e->getMessage());
            self::assertStringNotContainsString('已连接', $e->getMessage());
        }

        self::assertSame([], $writer->ready);
        self::assertSame('credentials_missing', $writer->failed[0]['error']);
        self::assertSame(FeedConnectionState::APP_UNCONFIGURED, $writer->failed[0]['state']);
        self::assertNotSame(FeedConnectionState::AUTHORIZED, $writer->failed[0]['state']);
        self::assertCount(6, FeedConnectionState::ALL);
        self::assertNotContains('已连接', FeedConnectionState::ALL);
    }

    public function testDisabledOrMissingArtifactIsNotFoundAndPathIsNotGeoFeed(): void
    {
        $documents = new FeedPublicDocumentService(new class implements FeedPublicSnapshotSourceInterface {
            public function findByToken(string $token): ?FeedPublicSnapshot
            {
                return match ($token) {
                    'disabled' => new FeedPublicSnapshot(false, true, true, '<stored/>', 'application/xml', 'var/feed-artifacts/1/1.bin'),
                    'nofile' => new FeedPublicSnapshot(true, false, false, '', '', ''),
                    'blank' => new FeedPublicSnapshot(true, true, true, '', 'application/xml', 'var/feed-artifacts/1/2.bin'),
                    'ready' => new FeedPublicSnapshot(true, true, true, '<stored/>', 'application/xml', 'var/feed-artifacts/2/1.bin'),
                    default => null,
                };
            }
        });

        self::assertSame(404, $documents->resolve('disabled')->statusCode);
        self::assertSame(404, $documents->resolve('nofile')->statusCode);
        self::assertSame(404, $documents->resolve('blank')->statusCode);
        self::assertSame(404, $documents->resolve('missing-token')->statusCode);
        self::assertSame(404, $documents->resolve('geo-feed.xml')->statusCode);
        $ready = $documents->resolve('ready');
        self::assertSame(200, $ready->statusCode);
        self::assertSame('<stored/>', $ready->body);

        $path = FeedPublicPath::forToken('ready');
        self::assertSame('/feed/protocol/document?token=ready', $path);
        self::assertStringNotContainsString('geo-feed.xml', $path);
        self::assertNotSame('/geo-feed.xml', $path);
    }

    public function testUnregisteredChannelAndEmptyBodyDoNotBecomeReadyOrAuthorized(): void
    {
        $writer = $this->writer();
        $service = $this->service($writer, new FeedChannelRegistry([]));
        $definition = $this->definition(FeedConnectionMode::FEED_URL, 'tok-2', true);
        $account = $this->account(FeedConnectionMode::FEED_URL, []);

        try {
            $service->generate($definition, $account);
            self::fail('unregistered channel must fail');
        } catch (FeedChannelUnregistered $e) {
            self::assertSame('google_merchant', $e->channelCode);
        }
        self::assertSame([], $writer->ready);
        self::assertSame(FeedConnectionState::ADDRESS_ONLY, $writer->failed[0]['state']);
        self::assertNotSame(FeedConnectionState::AUTHORIZED, $writer->failed[0]['state']);

        $emptyChannel = new class implements FeedChannelInterface {
            public function channelCode(): string
            {
                return 'google_merchant';
            }

            public function acceptedModes(): array
            {
                return [FeedConnectionMode::FEED_URL];
            }

            public function contentType(): string
            {
                return 'application/xml';
            }

            public function filenameSuffix(): string
            {
                return 'xml';
            }

            public function render(FeedDefinition $definition, iterable $items): FeedDocument
            {
                return new FeedDocument('application/xml', 'xml', '   ');
            }
        };
        $writer->failed = [];
        $again = $this->service($writer, new FeedChannelRegistry([$emptyChannel]));
        try {
            $again->generate($definition, $account);
            self::fail('blank document must fail');
        } catch (\RuntimeException $e) {
            self::assertSame('empty_document', $e->getMessage());
        }
        self::assertSame([], $writer->ready);
        self::assertSame('empty_document', $writer->failed[0]['error']);
    }

    public function testCatalogSourceKeepsMinorPriceAndLinkQuery(): void
    {
        $reader = new class implements CatalogFeedOfferReaderInterface {
            public function read(CatalogFeedReadQuery $query): iterable
            {
                yield new CatalogFeedOffer(
                    id: 'offer-red-m',
                    offerId: 9,
                    productId: 9,
                    globalOfferUuid: 'offer-red-m',
                    sku: 'SKU-RED',
                    title: 'Robe',
                    description: 'A robe',
                    brand: '',
                    link: 'https://shop.example/product/robe?color=red&offer=offer-red-m',
                    imageLink: 'https://shop.example/media/robe.jpg',
                    additionalImageLinks: [],
                    availability: CatalogFeedOffer::AVAILABILITY_IN_STOCK,
                    currency: 'CNY',
                    priceMinor: 2000,
                    salePriceMinor: 1500,
                    gtin: null,
                    mpn: null,
                    itemGroupId: '9',
                    attributes: [['code' => 'color', 'label' => '颜色', 'value' => '红']],
                );
            }
        };
        $items = iterator_to_array((new CatalogFeedSource($reader))->collect(new FeedCollectQuery(
            websiteId: 1,
            storeId: 0,
            locale: 'zh_Hans_CN',
            currency: 'CNY',
            sellingMode: 'toc',
            sourceCode: 'catalog',
        )), false);

        self::assertCount(1, $items);
        self::assertInstanceOf(FeedItem::class, $items[0]);
        self::assertSame(2000, $items[0]->priceMinor);
        self::assertSame(1500, $items[0]->salePriceMinor);
        self::assertSame('offer-red-m', $items[0]->id);
        self::assertSame('9', $items[0]->groupId);
        self::assertStringContainsString('color=red', $items[0]->link);
        self::assertStringContainsString('offer=offer-red-m', $items[0]->link);
        $generation = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/FeedGenerationService.php');
        self::assertStringNotContainsString('/ 100', $generation);
        self::assertStringNotContainsString('Weline\\Product\\Model', $generation);
    }

    private function service(FeedArtifactWriterInterface $writer, FeedChannelRegistry $channels): FeedGenerationService
    {
        return new FeedGenerationService(
            new FeedConnectionRegistry(),
            $channels,
            new class implements FeedSourceInterface {
                public function sourceCode(): string
                {
                    return 'catalog';
                }

                public function collect(FeedCollectQuery $query): iterable
                {
                    return [];
                }
            },
            $writer,
        );
    }

    private function writer(): FeedArtifactWriterInterface
    {
        return new class implements FeedArtifactWriterInterface {
            /** @var list<array{error:string,state:string}> */
            public array $failed = [];

            /** @var list<array{state:string,body:string}> */
            public array $ready = [];

            public function writeFailed(
                FeedDefinition $definition,
                FeedChannelAccount $account,
                string $errorCode,
                string $connectionState,
            ): void {
                $this->failed[] = ['error' => $errorCode, 'state' => $connectionState];
            }

            public function writeReady(
                FeedDefinition $definition,
                FeedChannelAccount $account,
                FeedDocument $document,
                int $itemCount,
                string $connectionState,
            ): void {
                $this->ready[] = ['state' => $connectionState, 'body' => $document->body];
            }
        };
    }

    private function definition(string $mode, string $token, bool $enabled): FeedDefinition
    {
        return new FeedDefinition(
            definitionId: 1,
            websiteId: 1,
            sourceCode: 'catalog',
            channelCode: 'google_merchant',
            locale: 'zh_Hans_CN',
            currency: 'CNY',
            title: 'Catalog',
            enabled: $enabled,
            connectionMode: $mode,
            accountId: 4,
            publicToken: $token,
        );
    }

    /**
     * @param array<string, string> $secrets
     */
    private function account(string $mode, array $secrets): FeedChannelAccount
    {
        return new FeedChannelAccount(
            accountId: 4,
            websiteId: 1,
            channelCode: 'google_merchant',
            connectionMode: $mode,
            displayName: 'Main',
            secretFields: $secrets,
            fingerprint: 'abc',
            status: FeedConnectionState::APP_UNCONFIGURED,
        );
    }
}
