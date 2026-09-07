<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Websites\Api\WebsiteTargetLookupInterface;

final class StorefrontCatalogInvalidationScopeTest extends TestCase
{
    protected function setUp(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('catalog-invalidation-scope');
        RequestContext::setWelineWebsiteId(0);
        RequestContext::setWelineWebsiteCode('default');
        $lookup = new class implements WebsiteTargetLookupInterface {
            public function find(int $websiteId): ?array
            {
                return match ($websiteId) {
                    0 => ['id' => 0, 'name' => 'System website', 'code' => 'default'],
                    7 => ['id' => 7, 'name' => 'Other website', 'code' => 'other_shop'],
                    default => null,
                };
            }
        };
        ObjectManager::setInstance(WebsiteTargetLookupInterface::class, $lookup);
    }

    protected function tearDown(): void
    {
        ObjectManager::clearInstances();
        RequestContext::cleanup();
        Context::leave();
    }

    public function testChangingAnotherWebsiteInvalidatesItsChildrenOnly(): void
    {
        [$coordinator, $generations] = $this->coordinator();
        $target = ['website/other_shop/catalog/store/retail/normal/channel/web'];
        $unrelated = ['website/default/catalog/store/retail/normal/channel/web'];
        $targetBefore = $generations->fingerprint($target);
        $unrelatedBefore = $generations->fingerprint($unrelated);

        $this->bump($coordinator, 7);

        self::assertNotSame($targetBefore, $generations->fingerprint($target));
        self::assertSame($unrelatedBefore, $generations->fingerprint($unrelated));
    }

    public function testDefaultWebsiteTargetDoesNotInheritTheCurrentRequestWebsite(): void
    {
        RequestContext::setWelineWebsiteId(7);
        RequestContext::setWelineWebsiteCode('other_shop');
        [$coordinator, $generations] = $this->coordinator();
        $target = ['website/default/catalog'];
        $unrelated = ['website/other_shop/catalog'];
        $targetBefore = $generations->fingerprint($target);
        $unrelatedBefore = $generations->fingerprint($unrelated);

        $this->bump($coordinator, 0);

        self::assertNotSame($targetBefore, $generations->fingerprint($target));
        self::assertSame($unrelatedBefore, $generations->fingerprint($unrelated));
    }

    public function testMissingTargetDoesNotInvalidateTheCurrentWebsite(): void
    {
        [$coordinator, $generations] = $this->coordinator();
        $namespace = ['website/default/catalog'];
        $before = $generations->fingerprint($namespace);

        $this->bump($coordinator, 99);

        self::assertSame($before, $generations->fingerprint($namespace));
    }

    private function coordinator(): array
    {
        $coordinator = (new \ReflectionClass(StorefrontCatalogCacheCoordinator::class))->newInstanceWithoutConstructor();
        $generations = new CatalogInvalidationGenerations();
        (new \ReflectionProperty($coordinator, 'namespaceGenerations'))->setValue($coordinator, $generations);
        (new \ReflectionProperty($coordinator, 'namespacePath'))->setValue($coordinator, new NamespacePath());
        return [$coordinator, $generations];
    }

    private function bump(StorefrontCatalogCacheCoordinator $coordinator, int $websiteId): void
    {
        (new \ReflectionMethod($coordinator, 'bumpStorefrontCatalogGeneration'))->invoke($coordinator, $websiteId);
    }
}

/** In-memory generation store with the real namespace ancestor rules. */
final class CatalogInvalidationGenerations implements NamespaceGenerationInterface
{
    private array $generations = [];

    public function fingerprint(array $namespaces): string
    {
        $vector = [];
        foreach ((new NamespacePath())->expandAncestors($namespaces) as $path) {
            $vector[$path] = $this->generations[$path] ?? 0;
        }
        return hash('sha256', json_encode($vector, JSON_THROW_ON_ERROR));
    }

    public function bump(string $namespace): array
    {
        return $this->bumpMany([$namespace]);
    }

    public function bumpMany(array $namespaces): array
    {
        foreach ((new NamespacePath())->canonicalizeMany($namespaces) as $path) {
            $this->generations[$path] = ($this->generations[$path] ?? 0) + 1;
        }
        return ['authority_clock' => array_sum($this->generations), 'changes' => []];
    }
}
