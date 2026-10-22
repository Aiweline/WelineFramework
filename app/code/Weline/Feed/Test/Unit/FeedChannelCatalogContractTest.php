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
use Weline\Feed\Api\FeedArtifactWriterInterface;
use Weline\Feed\Api\FeedSourceInterface;
use Weline\Feed\Exception\FeedCredentialsMissing;
use Weline\Feed\Service\Channel\AbstractFeedChannel;
use Weline\Feed\Service\Channel\NaverShoppingChannel;
use Weline\Feed\Service\FeedChannelRegistry;
use Weline\Feed\Service\FeedConnectionRegistry;
use Weline\Feed\Service\FeedGenerationService;
use Weline\Feed\Service\FeedPublicPath;
use Weline\Feed\Service\FeedSamplePath;
use Weline\Feed\Service\Orm\OrmFeedArtifactWriter;

final class FeedChannelCatalogContractTest extends TestCase
{
    /** @var list<string> */
    private const CODES = [
        'google_merchant',
        'microsoft_merchant',
        'yandex_market',
        'meta_catalog',
        'tiktok_catalog',
        'pinterest_catalog',
        'snapchat_catalog',
        'criteo',
        'vk_ads',
        'naver_shopping',
    ];

    public function testRegistryIsTheTenChannelsAndPublicPathIsNotGeoFeed(): void
    {
        $registry = new FeedChannelRegistry();
        self::assertSame(self::CODES, $registry->codes());

        $oauth = ['google_merchant', 'microsoft_merchant', 'meta_catalog', 'tiktok_catalog', 'pinterest_catalog', 'snapchat_catalog'];
        $consent = ['yandex_market', 'vk_ads'];
        $pullOnly = ['criteo', 'naver_shopping'];
        $paths = [];
        foreach (self::CODES as $code) {
            $channel = $registry->find($code);
            self::assertInstanceOf(AbstractFeedChannel::class, $channel);
            self::assertContains(FeedConnectionMode::FEED_URL, $channel->acceptedModes());
            $paths[] = FeedSamplePath::forChannel($code);
            if (in_array($code, $oauth, true)) {
                self::assertContains(FeedConnectionMode::OAUTH, $channel->acceptedModes());
                self::assertStringContainsString('oauth', $channel->connectionDescription());
            }
            if (in_array($code, $consent, true)) {
                self::assertContains(FeedConnectionMode::OAUTH, $channel->acceptedModes());
                self::assertStringContainsString('同意页', $channel->connectionDescription());
                self::assertStringContainsString('不算已授权', $channel->connectionDescription());
            }
            if (in_array($code, $pullOnly, true)) {
                self::assertContains(FeedConnectionMode::API_KEY, $channel->acceptedModes());
                self::assertNotContains(FeedConnectionMode::OAUTH, $channel->acceptedModes());
                self::assertStringContainsString('不做同意页', $channel->connectionDescription());
            }
        }
        self::assertCount(10, array_unique($paths));
        foreach ($paths as $path) {
            self::assertStringNotContainsString('geo-feed', $path);
            self::assertNotSame('/geo-feed.xml', $path);
            self::assertNotSame('/geo-feed.json', $path);
        }
        $public = FeedPublicPath::forToken('ready-token');
        self::assertSame('/feed/protocol/document?token=ready-token', $public);
        self::assertStringNotContainsString('geo-feed', $public);
        self::assertNotSame('/geo-feed.xml', $public);
    }

    public function testGoogleRequiredNodesAndSkippedRowsAreNotAnEmptySuccess(): void
    {
        $channel = (new FeedChannelRegistry())->find('google_merchant');
        self::assertInstanceOf(AbstractFeedChannel::class, $channel);
        $definition = $this->definition('google_merchant', FeedConnectionMode::FEED_URL, '');

        $empty = $channel->render($definition, []);
        self::assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $empty->body);
        self::assertStringContainsString('<rss', $empty->body);
        self::assertStringContainsString('<channel>', $empty->body);
        self::assertStringNotContainsString('<item>', $empty->body);
        self::assertNotSame('', trim($empty->body));

        $stock = $this->item(availability: 'out_of_stock', brand: 'Atelier');
        $body = $channel->render($definition, [$stock])->body;
        foreach (['g:id', 'g:title', 'g:description', 'g:link', 'g:image_link', 'g:availability', 'g:price', 'g:brand'] as $node) {
            self::assertStringContainsString('<' . $node . '>', $body);
        }
        self::assertStringContainsString('out_of_stock', $body);
        self::assertStringContainsString('20.00 CNY', $body);
        self::assertStringContainsString('color=red', $body);
        self::assertStringContainsString('offer=offer-red-m', $body);
        self::assertStringNotContainsString('<g:gtin>', $body);
        self::assertStringNotContainsString('schema.org', $body);

        $sample = $channel->renderSample($definition);
        self::assertStringNotContainsString('<g:gtin>', $sample->body);
        self::assertStringContainsString('<g:brand>', $sample->body);

        $skipped = $channel->render($definition, [$this->item(brand: '')]);
        self::assertSame('', trim($skipped->body));
        self::assertSame('items_skipped', $skipped->diagnosticCode);
    }

    public function testBingAndYandexSampleNodes(): void
    {
        $registry = new FeedChannelRegistry();
        $bing = $registry->find('microsoft_merchant');
        $yandex = $registry->find('yandex_market');
        self::assertInstanceOf(AbstractFeedChannel::class, $bing);
        self::assertInstanceOf(AbstractFeedChannel::class, $yandex);
        $bingBody = $bing->renderSample($this->definition('microsoft_merchant', FeedConnectionMode::FEED_URL, ''))->body;
        foreach (['g:id', 'g:title', 'g:description', 'g:link', 'g:image_link', 'g:price', 'g:availability', 'g:brand'] as $node) {
            self::assertStringContainsString('<' . $node . '>', $bingBody);
        }
        self::assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $bingBody);
        self::assertStringNotContainsString('<g:gtin>', $bingBody);

        self::assertSame('yandex_market', $yandex->channelCode());
        self::assertStringContainsString('Yandex Direct', $yandex->displayName());
        self::assertStringNotContainsString('开店', $yandex->displayName());
        $yml = $yandex->renderSample($this->definition('yandex_market', FeedConnectionMode::FEED_URL, ''))->body;
        self::assertStringContainsString('<yml_catalog date="', $yml);
        self::assertStringContainsString('<name>', $yml);
        self::assertStringContainsString('<company>', $yml);
        self::assertStringContainsString('<url>', $yml);
        self::assertStringContainsString('<currency id="', $yml);
        self::assertStringContainsString('rate="1"', $yml);
        self::assertStringContainsString('<category id="1">', $yml);
        self::assertStringContainsString('<offer id="', $yml);
        self::assertStringContainsString('available="true"', $yml);
        foreach (['<url>', '<price>', '<currencyId>', '<categoryId>', '<picture>', '<name>'] as $node) {
            self::assertStringContainsString($node, $yml);
        }
        self::assertStringContainsString('Yandex Direct', $yml);
        self::assertStringNotContainsString('开店', $yml);
        self::assertNotSame(FeedSamplePath::forChannel('microsoft_merchant'), FeedSamplePath::forChannel('yandex_market'));
    }

    public function testSevenSampleDocumentsContainRequiredNodes(): void
    {
        $required = [
            'meta_catalog' => ['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand'],
            'tiktok_catalog' => ['sku_id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand'],
            'pinterest_catalog' => ['id', 'title', 'description', 'link', 'image_link', 'price', 'availability'],
            'snapchat_catalog' => ['id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'brand'],
            'criteo' => ['id', 'title', 'description', 'link', 'image_link', 'price', 'availability'],
            'vk_ads' => ['id', 'title', 'description', 'link', 'image_link', 'price', 'availability'],
            'naver_shopping' => ['id', 'title', 'price_pc', 'link', 'image_link', 'category_name1', 'shipping'],
        ];
        $registry = new FeedChannelRegistry();
        foreach ($required as $code => $columns) {
            $channel = $registry->find($code);
            self::assertInstanceOf(AbstractFeedChannel::class, $channel);
            $document = $channel->renderSample($this->definition($code, FeedConnectionMode::FEED_URL, ''));
            self::assertNotSame('', trim($document->body));
            foreach ($columns as $column) {
                self::assertStringContainsString($column, $document->body, $code . ' missing ' . $column);
            }
            if (in_array('condition', $columns, true)) {
                self::assertStringContainsString('new', $document->body);
            }
        }
        $naver = $registry->find('naver_shopping');
        self::assertInstanceOf(NaverShoppingChannel::class, $naver);
        $unset = $naver->renderSample($this->definition('naver_shopping', FeedConnectionMode::FEED_URL, ''));
        self::assertStringContainsString("\t0\n", $unset->body);
        self::assertSame(NaverShoppingChannel::SHIPPING_UNCONFIGURED, $unset->diagnosticCode);
        self::assertSame(
            NaverShoppingChannel::SHIPPING_UNCONFIGURED,
            OrmFeedArtifactWriter::readyArtifactErrorCode($unset),
        );
        $set = $naver->renderSample($this->definition('naver_shopping', FeedConnectionMode::FEED_URL, '{"shipping":"2500"}'));
        self::assertStringContainsString("\t2500\n", $set->body);
        self::assertSame('', $set->diagnosticCode);
    }

    public function testMissingSecretsAndLocalFileAreNotAuthorized(): void
    {
        $writer = $this->writer();
        $service = new FeedGenerationService(
            new FeedConnectionRegistry(),
            new FeedChannelRegistry(),
            $this->source([$this->item(brand: 'Atelier')]),
            $writer,
        );

        try {
            $service->generate(
                $this->definition('google_merchant', FeedConnectionMode::OAUTH, 'tok-g'),
                $this->account('google_merchant', FeedConnectionMode::OAUTH, []),
            );
            self::fail('missing oauth material must fail');
        } catch (FeedCredentialsMissing $e) {
            self::assertSame(['refresh_token', 'access_token'], $e->missingFields);
        }
        self::assertSame([], $writer->ready);
        self::assertSame(FeedConnectionState::APP_UNCONFIGURED, $writer->failed[0]['state']);

        $writer->failed = [];
        try {
            $service->generate(
                $this->definition('criteo', FeedConnectionMode::OAUTH, 'tok-c'),
                $this->account('criteo', FeedConnectionMode::OAUTH, [
                    'refresh_token' => 'r',
                    'access_token' => 'a',
                ]),
            );
            self::fail('criteo has no consent page');
        } catch (FeedCredentialsMissing $e) {
            self::assertSame(['connection_mode'], $e->missingFields);
        }
        self::assertSame([], $writer->ready);

        $writer->failed = [];
        $service->generate(
            $this->definition('naver_shopping', FeedConnectionMode::FEED_URL, 'tok-n', true),
            $this->account('naver_shopping', FeedConnectionMode::FEED_URL, []),
        );
        self::assertCount(1, $writer->ready);
        self::assertSame(FeedConnectionState::ADDRESS_ONLY, $writer->ready[0]['state']);
        self::assertNotSame(FeedConnectionState::AUTHORIZED, $writer->ready[0]['state']);
        self::assertSame(NaverShoppingChannel::SHIPPING_UNCONFIGURED, $writer->ready[0]['diagnostic']);
        self::assertStringContainsString('shipping', $writer->ready[0]['body']);
        self::assertStringNotContainsString('offer-red-m', $writer->ready[0]['body']);

        $writer->ready = [];
        $service->generate(
            $this->definition('google_merchant', FeedConnectionMode::FEED_URL, 'tok-empty', true),
            $this->account('google_merchant', FeedConnectionMode::FEED_URL, []),
        );
        // source still yields one valid item; this call uses the same source. Assert the ready file is not authorized.
        self::assertSame(FeedConnectionState::ADDRESS_ONLY, $writer->ready[0]['state']);
    }

    public function testAllInvalidRowsFailInsteadOfPublishingAnEmptyDocument(): void
    {
        $writer = $this->writer();
        $service = new FeedGenerationService(
            new FeedConnectionRegistry(),
            new FeedChannelRegistry(),
            $this->source([$this->item(brand: '')]),
            $writer,
        );
        try {
            $service->generate(
                $this->definition('google_merchant', FeedConnectionMode::FEED_URL, 'tok-bad', true),
                $this->account('google_merchant', FeedConnectionMode::FEED_URL, []),
            );
            self::fail('skipped rows must not become a ready document');
        } catch (\RuntimeException $e) {
            self::assertSame('items_skipped', $e->getMessage());
        }
        self::assertSame([], $writer->ready);
        self::assertSame('items_skipped', $writer->failed[0]['error']);
        self::assertNotSame(FeedConnectionState::AUTHORIZED, $writer->failed[0]['state']);
    }

    /**
     * @param list<FeedItem> $items
     */
    private function source(array $items): FeedSourceInterface
    {
        return new class($items) implements FeedSourceInterface {
            /**
             * @param list<FeedItem> $items
             */
            public function __construct(private array $items)
            {
            }

            public function sourceCode(): string
            {
                return 'catalog';
            }

            public function collect(FeedCollectQuery $query): iterable
            {
                return $this->items;
            }
        };
    }

    private function writer(): FeedArtifactWriterInterface
    {
        return new class implements FeedArtifactWriterInterface {
            /** @var list<array{error:string,state:string}> */
            public array $failed = [];

            /** @var list<array{state:string,body:string,diagnostic:string}> */
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
                $this->ready[] = [
                    'state' => $connectionState,
                    'body' => $document->body,
                    'diagnostic' => $document->diagnosticCode,
                ];
            }
        };
    }

    private function definition(string $channel, string $mode, string $filter, bool $enabled = true, string $token = 'tok'): FeedDefinition
    {
        return new FeedDefinition(
            definitionId: 1,
            websiteId: 1,
            sourceCode: 'catalog',
            channelCode: $channel,
            locale: 'zh_Hans_CN',
            currency: 'CNY',
            title: 'Catalog',
            enabled: $enabled,
            connectionMode: $mode,
            accountId: 4,
            publicToken: $token,
            filterJson: $filter,
        );
    }

    /**
     * @param array<string, string> $secrets
     */
    private function account(string $channel, string $mode, array $secrets): FeedChannelAccount
    {
        return new FeedChannelAccount(
            accountId: 4,
            websiteId: 1,
            channelCode: $channel,
            connectionMode: $mode,
            displayName: 'Main',
            secretFields: $secrets,
            fingerprint: 'abc',
            status: FeedConnectionState::APP_UNCONFIGURED,
        );
    }

    private function item(string $availability = 'in_stock', string $brand = 'Atelier'): FeedItem
    {
        return new FeedItem(
            id: 'offer-red-m',
            productId: 9,
            globalOfferUuid: 'offer-red-m',
            groupId: '9',
            sku: 'SKU-RED',
            title: 'Robe',
            description: 'A robe',
            brand: $brand,
            link: 'https://shop.example/product/robe?color=red&offer=offer-red-m',
            imageLink: 'https://shop.example/media/robe.jpg',
            additionalImageLinks: [],
            availability: $availability,
            currency: 'CNY',
            priceMinor: 2000,
            salePriceMinor: null,
            gtin: null,
            mpn: null,
            attributes: [],
        );
    }
}
