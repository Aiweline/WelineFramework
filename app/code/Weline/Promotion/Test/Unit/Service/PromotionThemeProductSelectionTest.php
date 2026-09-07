<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductAdminReadInterface;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Promotion\Model\PromotionActivityThemeProduct;
use Weline\Promotion\Service\PromotionThemeProductService;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class PromotionThemeProductSelectionTest extends TestCase
{
    private array $savedInstances = [];
    private object $catalogProbe;

    protected function setUp(): void
    {
        Context::enter(new Context([
            'input' => ['uri' => '/products'],
            'runtime' => ['request_context' => ['initialized' => true, 'request_id' => 'promotion-selection-test']],
        ]));
        $instances = ObjectManager::getInstances();
        foreach ([ProductAdminReadInterface::class, StorefrontCatalogViewService::class] as $class) {
            $this->savedInstances[$class] = $instances[$class] ?? null;
        }
        // Observe the forbidden catalog edge without starting the real recursive projection.
        $this->catalogProbe = new class {
            public int $calls = 0;
            public function publishedOffersForProductIds(array $productIds, int $limit): array
            {
                ++$this->calls;
                return [];
            }
        };
        ObjectManager::setInstance(StorefrontCatalogViewService::class, $this->catalogProbe);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedInstances as $class => $instance) {
            if ($instance !== null) {
                ObjectManager::setInstance($class, $instance);
            } else {
                ObjectManager::removeInstance($class);
            }
        }
        Context::leave();
    }

    public function testEmptyFilterMatchDoesNotLoadTheStorefrontCatalog(): void
    {
        $reader = $this->createMock(ProductAdminReadInterface::class);
        $reader->expects(self::once())->method('search')->with(7, ['status' => 'published', 'sku' => 'NO-MATCH'])->willReturn([]);
        ObjectManager::setInstance(ProductAdminReadInterface::class, $reader);

        self::assertSame([], $this->service()->resolveStorefrontProductIds($this->theme(['sku' => 'NO-MATCH']), $this->scope()));
        self::assertSame(0, $this->catalogProbe->calls, 'Empty product selection must not build the catalog that is resolving this promotion.');
    }

    public function testReaderFailureDoesNotLoadTheStorefrontCatalog(): void
    {
        $reader = $this->createMock(ProductAdminReadInterface::class);
        $reader->expects(self::once())->method('search')->with(7, ['status' => 'published'])->willThrowException(new \RuntimeException('Reader unavailable'));
        ObjectManager::setInstance(ProductAdminReadInterface::class, $reader);

        self::assertSame([], $this->service()->resolveStorefrontProductIds($this->theme(), $this->scope()));
        self::assertSame(0, $this->catalogProbe->calls, 'A reader failure must not enter recursive storefront projection.');
    }

    public function testUnavailableReaderDoesNotLoadTheStorefrontCatalog(): void
    {
        $unavailableReader = new \stdClass();
        ObjectManager::setInstance(ProductAdminReadInterface::class, $unavailableReader);

        self::assertSame([], $this->service()->resolveStorefrontProductIds($this->theme(), $this->scope()));
        self::assertSame(0, $this->catalogProbe->calls, 'Unavailable reader must not enter recursive storefront projection.');
    }

    public function testMatchingFilterPreservesReaderOrderAndLimit(): void
    {
        $reader = $this->createMock(ProductAdminReadInterface::class);
        $reader->expects(self::once())->method('search')->with(7, ['status' => 'published', 'product_type' => 'simple'])->willReturn([
            ['product_id' => 0],
            ['product_id' => 27],
            ['product_id' => 9],
            ['product_id' => 44],
        ]);
        ObjectManager::setInstance(ProductAdminReadInterface::class, $reader);

        self::assertSame([27, 9], $this->service()->resolveStorefrontProductIds($this->theme(['product_type' => 'simple', 'limit' => 2]), $this->scope()));
        self::assertSame(0, $this->catalogProbe->calls);
    }

    public function testManualBindingsBatchPublishedScopeValidation(): void
    {
        $reader = $this->createMock(ProductAdminReadInterface::class);
        $reader->expects(self::once())
            ->method('search')
            ->with(7, ['status' => 'published'])
            ->willReturn([
                ['product_id' => 101],
                ['product_id' => 102],
                ['product_id' => 103],
            ]);
        ObjectManager::setInstance(ProductAdminReadInterface::class, $reader);

        $theme = $this->scope() + [
            'id' => 11,
            'product_pick_mode' => PromotionThemeProductService::PICK_MODE_MANUAL,
            'product_filter_json' => '',
        ];
        $bindings = [
            ['product_id' => 101, 'website_id' => 0, 'sort_order' => 0],
            ['product_id' => 102, 'website_id' => 0, 'sort_order' => 1],
            ['product_id' => 103, 'website_id' => 0, 'sort_order' => 2],
        ];

        self::assertSame(
            [101, 102, 103],
            (new PromotionThemeProductService($this->bindingDouble($bindings), $this->createStub(StoreCatalogInterface::class)))
                ->resolveStorefrontProductIds($theme, $this->scope()),
        );
    }

    private function service(): PromotionThemeProductService
    {
        return new PromotionThemeProductService(
            $this->createStub(PromotionActivityThemeProduct::class),
            $this->createStub(StoreCatalogInterface::class),
        );
    }

    private function scope(): array
    {
        return ['website_id' => 7, 'store_code' => '', 'channel_code' => ''];
    }

    private function theme(array $filters = []): array
    {
        return $this->scope() + [
            'id' => 11,
            'product_pick_mode' => 'filter',
            'product_filter_json' => json_encode($filters + ['status' => 'published', 'limit' => 12], JSON_THROW_ON_ERROR),
        ];
    }

    /** @param list<array{product_id:int,website_id:int,sort_order:int}> $bindings */
    private function bindingDouble(array $bindings): PromotionActivityThemeProduct
    {
        return new class($bindings) extends PromotionActivityThemeProduct {
            /** @param list<array{product_id:int,website_id:int,sort_order:int}> $bindings */
            public function __construct(private readonly array $bindings)
            {
            }

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function getItems(): array
            {
                return array_map(
                    static fn(array $binding): object => new class($binding) {
                        public function __construct(private readonly array $binding)
                        {
                        }

                        public function getData(string $key = '', $index = null): mixed
                        {
                            return $this->binding[$key] ?? null;
                        }
                    },
                    $this->bindings,
                );
            }

            public function __call($method, $args)
            {
                if (in_array($method, ['where', 'order', 'select', 'fetch'], true)) {
                    return $this;
                }

                return parent::__call($method, $args);
            }
        };
    }
}
