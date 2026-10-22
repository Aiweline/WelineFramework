<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\CatalogFeed;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\CatalogFeedOfferCandidateSourceInterface;
use Weline\Product\Api\Data\CatalogFeedOffer;
use Weline\Product\Api\Data\CatalogFeedOfferCandidate;
use Weline\Product\Api\Data\CatalogFeedReadQuery;
use Weline\Product\Api\Data\StorefrontOfferPriceView;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\StorefrontOfferPriceAssemblerInterface;
use Weline\Product\Service\CatalogFeed\CatalogFeedOfferReader;

final class CatalogFeedOfferReaderTest extends TestCase
{
    public function testZeroPriceIsExcludedAndPositivePriceComesFromAssembler(): void
    {
        $assembler = new class implements StorefrontOfferPriceAssemblerInterface {
            /** @var array<int, StorefrontPriceContext> */
            public array $seen = [];

            public function assemble(StorefrontPriceContext $context): StorefrontOfferPriceView
            {
                $this->seen[$context->productId] = $context;
                if ($context->productId === 1) {
                    return new StorefrontOfferPriceView('CNY', 0, 0, 0, false);
                }

                return new StorefrontOfferPriceView('CNY', 2000, 1500, 2000, true);
            }
        };
        $reader = new CatalogFeedOfferReader($assembler, $this->source([
            $this->candidate(offerId: 1, productId: 1, uuid: 'offer-zero', catalogPriceMinor: 0),
            $this->candidate(
                offerId: 2,
                productId: 2,
                uuid: 'offer-deal',
                sku: 'SKU-DEAL',
                catalogPriceMinor: 2000,
                gtin: 'SKU-DEAL',
            ),
        ]));

        $offers = $this->readAll($reader);

        self::assertCount(1, $offers);
        self::assertSame('offer-deal', $offers[0]->id);
        self::assertSame(0, $assembler->seen[1]->catalogPriceMinor);
        self::assertSame(2000, $assembler->seen[2]->catalogPriceMinor);
        self::assertSame('offer-deal', $assembler->seen[2]->globalOfferUuid);
        self::assertSame(2000, $offers[0]->priceMinor);
        self::assertSame(1500, $offers[0]->salePriceMinor);
        self::assertSame('CNY', $offers[0]->currency);
        self::assertNull($offers[0]->gtin);
    }

    public function testVariantLinkKeepsExistingQuery(): void
    {
        $assembler = new class implements StorefrontOfferPriceAssemblerInterface {
            public function assemble(StorefrontPriceContext $context): StorefrontOfferPriceView
            {
                return new StorefrontOfferPriceView('USD', 9900, 9900, 9900, false);
            }
        };
        $reader = new CatalogFeedOfferReader($assembler, $this->source([
            $this->candidate(
                offerId: 9,
                productId: 9,
                uuid: 'offer-red-m',
                linkBase: 'https://shop.example/product/robe?color=red&w_fpc_pierce=secret',
                gtin: '4006381333931',
                variant: true,
            ),
        ]));

        $offers = $this->readAll($reader);

        self::assertCount(1, $offers);
        $link = $offers[0]->link;
        self::assertStringContainsString('color=red', $link);
        self::assertStringContainsString('offer=offer-red-m', $link);
        self::assertStringNotContainsString('w_fpc_pierce', $link);
        self::assertNotSame('https://shop.example/product/robe', $link);
        self::assertSame('4006381333931', $offers[0]->gtin);
        self::assertSame('9', $offers[0]->itemGroupId);
        self::assertStringStartsWith('https://shop.example/product/robe?', $link);
    }

    public function testQuoteOnlyAndUnpublishedAreExcludedWhileOutOfStockRemains(): void
    {
        $assembler = new class implements StorefrontOfferPriceAssemblerInterface {
            public function assemble(StorefrontPriceContext $context): StorefrontOfferPriceView
            {
                return new StorefrontOfferPriceView('CNY', 1200, 1200, 1200, false);
            }
        };
        $reader = new CatalogFeedOfferReader($assembler, $this->source([
            $this->candidate(offerId: 3, productId: 3, uuid: 'offer-quote', quoteOnly: true),
            $this->candidate(offerId: 4, productId: 4, uuid: 'offer-draft', published: false),
            $this->candidate(offerId: 5, productId: 5, uuid: 'offer-oos', stock: 0),
        ]));

        $offers = $this->readAll($reader);

        self::assertCount(1, $offers);
        self::assertSame('offer-oos', $offers[0]->id);
        self::assertSame(CatalogFeedOffer::AVAILABILITY_OUT_OF_STOCK, $offers[0]->availability);
        self::assertNull($offers[0]->salePriceMinor);
        self::assertSame(1200, $offers[0]->priceMinor);
    }

    public function testReaderDoesNotDivideMinorOrCallSeoOfferUrl(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/CatalogFeed/CatalogFeedOfferReader.php',
        );
        self::assertStringNotContainsString('offerUrl', $source);
        self::assertStringNotContainsString('/ 100', $source);
        self::assertStringNotContainsString('finalPrice()', $source);
        self::assertStringNotContainsString('<?xml', $source);
    }

    /**
     * @param list<CatalogFeedOfferCandidate> $rows
     */
    private function source(array $rows): CatalogFeedOfferCandidateSourceInterface
    {
        return new class ($rows) implements CatalogFeedOfferCandidateSourceInterface {
            /** @param list<CatalogFeedOfferCandidate> $rows */
            public function __construct(private array $rows)
            {
            }

            public function collect(CatalogFeedReadQuery $query): iterable
            {
                $emitted = 0;
                foreach ($this->rows as $row) {
                    if ($row->offerId <= $query->afterOfferId) {
                        continue;
                    }
                    if ($emitted >= $query->limit) {
                        break;
                    }
                    yield $row;
                    ++$emitted;
                }
            }
        };
    }

    private function candidate(
        int $offerId,
        int $productId,
        string $uuid,
        string $sku = 'SKU-1',
        string $linkBase = 'https://shop.example/product/item',
        int $catalogPriceMinor = 1000,
        ?string $gtin = null,
        bool $published = true,
        bool $quoteOnly = false,
        bool $variant = false,
        ?int $stock = 4,
    ): CatalogFeedOfferCandidate {
        return new CatalogFeedOfferCandidate(
            offerId: $offerId,
            productId: $productId,
            globalOfferUuid: $uuid,
            sku: $sku,
            title: 'Robe',
            description: 'A robe',
            brand: '',
            gtin: $gtin,
            mpn: null,
            linkBase: $linkBase,
            imageLink: 'https://shop.example/media/robe.jpg',
            catalogPriceMinor: $catalogPriceMinor,
            published: $published,
            quoteOnly: $quoteOnly,
            variant: $variant,
            stock: $stock,
        );
    }

    /**
     * @return list<CatalogFeedOffer>
     */
    private function readAll(CatalogFeedOfferReader $reader): array
    {
        return iterator_to_array($reader->read(new CatalogFeedReadQuery(
            websiteId: 1,
            storeId: 0,
            locale: 'zh_Hans_CN',
            currency: 'CNY',
            sellingMode: 'toc',
        )), false);
    }
}
