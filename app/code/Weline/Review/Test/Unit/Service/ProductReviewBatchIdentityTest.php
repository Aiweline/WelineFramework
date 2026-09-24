<?php

declare(strict_types=1);

namespace Weline\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Product\Api\ProductIdentity;
use Weline\Product\Api\ProductIdentityBatchResolverInterface;
use Weline\Product\Api\ProductIdentityResolverInterface;
use Weline\Review\Service\ProductReviewTypeProvider;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class ProductReviewBatchIdentityTest extends TestCase
{
    public function testBatchResolvesOfferFirstThenOnlyMissingProductIds(): void
    {
        $resolver = new class implements ProductIdentityBatchResolverInterface {
            public array $calls = [];
            public bool $failFallback = false;
            public function resolveBySku(string $sku): ?ProductIdentity { throw new \LogicException('single'); }
            public function resolveByOfferUuid(string $uuid): ?ProductIdentity { throw new \LogicException('single'); }
            public function resolveByProductUuid(string $uuid): ?ProductIdentity { throw new \LogicException('single'); }
            public function resolveByOfferUuids(array $uuids): array {
                $this->calls[] = ['offer', $uuids];
                return ['offer-a' => new ProductIdentity(7, 'SKU', 'product-a', 'offer-a', 'hash')];
            }
            public function resolveByProductUuids(array $uuids): array {
                $this->calls[] = ['product', $uuids];
                if ($this->failFallback) { throw new \RuntimeException('unavailable'); }
                return ['product-b' => new ProductIdentity(8, 'SKU2', 'product-b', 'offer-b', 'hash')];
            }
        };
        $runtime = (new \ReflectionClass(RuntimeProviderResolver::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($runtime, 'resolved'))->setValue($runtime, [ProductIdentityResolverInterface::class => $resolver]);
        ObjectManager::setInstance(RuntimeProviderResolver::class, $runtime);
        $type = new ProductReviewTypeProvider();
        self::assertTrue(method_exists($type, 'resolveEntities'));
        self::assertSame([], $type->resolveEntities([]));
        self::assertSame([], $resolver->calls);
        self::assertSame([
            'offer-a' => ['entity_id' => 7, 'entity_uuid' => 'product-a'],
            'product-b' => ['entity_id' => 8, 'entity_uuid' => 'product-b'],
            'missing' => null,
        ], $type->resolveEntities(['offer-a', 'offer-a', 'product-b', 'missing', '']));
        self::assertSame([['offer', ['offer-a', 'product-b', 'missing']], ['product', ['product-b', 'missing']]], $resolver->calls);
        $resolver->failFallback = true;
        self::assertSame(['offer-a' => ['entity_id' => 7, 'entity_uuid' => 'product-a'], 'product-b' => null],
            $type->resolveEntities(['offer-a', 'product-b']));
    }
}
