<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Service\SearchProviderRegistry;

final class SearchProviderRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }
    }

    public function testExtensionClassResolvesFromSourceFileWhenClassNameMissing(): void
    {
        $registry = new SearchProviderRegistry($this->createMock(ObjectManager::class));
        $method = new ReflectionMethod(SearchProviderRegistry::class, 'extensionClass');
        $method->setAccessible(true);

        $sourceFile = dirname(__DIR__, 4) . '/Product/extends/module/Weline_Search/Searcher/ProductSearchProvider.php';
        $class = $method->invoke($registry, [
            'source_file' => $sourceFile,
            'file_path' => 'Searcher/ProductSearchProvider.php',
        ]);

        self::assertSame(
            'Weline\\Product\\Extends\\Module\\Weline_Search\\Searcher\\ProductSearchProvider',
            $class,
        );
    }

    public function testListTypesIncludesScopeChildrenFromProviders(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }

        $product = new class implements \Weline\Search\Api\SearchProviderInterface, \Weline\Search\Api\SearchScopeOptionsProviderInterface {
            public function code(): string { return 'product'; }
            public function label(): string { return '商品'; }
            public function sortOrder(): int { return 10; }
            public function areas(): array { return ['frontend']; }
            public function expression(\Weline\Search\Dto\SearchRequest $request): \Weline\Search\Service\SearchExpression {
                return \Weline\Search\Service\SearchExpression::of($request);
            }
            public function allowedClientParams(): array { return ['category_id' => ['type' => 'int', 'min' => 1]]; }
            public function hitTemplate(): string { return ''; }
            public function execute(\Weline\Search\Dto\SearchRequest $request, \Weline\Search\Service\SearchExpression $expression): \Weline\Search\Dto\SearchResult {
                return new \Weline\Search\Dto\SearchResult(ok: true, type: 'product', hits: [], hitCount: 0);
            }
            public function documentsForIndex(\Weline\Search\Dto\SearchRequest $request): array { return []; }
            public function listScopeOptions(): array {
                return [
                    [
                        'code' => 'category_5',
                        'label' => 'Electronics',
                        'params' => ['category_id' => 5],
                        'children' => [
                            [
                                'code' => 'category_6',
                                'label' => 'Phones',
                                'params' => ['category_id' => 6],
                                'children' => [
                                    [
                                        'code' => 'category_7',
                                        'label' => 'Smartphones',
                                        'params' => ['category_id' => 7],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $objectManager = $this->createMock(ObjectManager::class);
        $registry = new SearchProviderRegistry($objectManager);
        $reflection = new \ReflectionClass($registry);
        $property = $reflection->getProperty('providers');
        $property->setAccessible(true);
        $property->setValue($registry, ['product' => $product]);

        $types = $registry->listTypes();
        self::assertSame('all', $types[0]['code']);
        self::assertSame([], $types[0]['children']);
        self::assertSame('product', $types[1]['code']);
        self::assertCount(1, $types[1]['children']);
        self::assertSame('category_5', $types[1]['children'][0]['code']);
        self::assertSame(5, $types[1]['children'][0]['params']['category_id']);
        self::assertSame('Phones', $types[1]['children'][0]['children'][0]['label']);
        self::assertSame('Smartphones', $types[1]['children'][0]['children'][0]['children'][0]['label']);
        self::assertSame([], $types[1]['children'][0]['children'][0]['children'][0]['children']);

        $crumbs = $registry->resolveScopeBreadcrumb('product', 7, $types);
        self::assertCount(4, $crumbs);
        self::assertSame('商品', $crumbs[0]['label']);
        self::assertSame(0, $crumbs[0]['category_id']);
        self::assertSame('Electronics', $crumbs[1]['label']);
        self::assertSame(5, $crumbs[1]['category_id']);
        self::assertSame('Phones', $crumbs[2]['label']);
        self::assertSame('Smartphones', $crumbs[3]['label']);
        self::assertSame(7, $crumbs[3]['category_id']);
    }

    public function testListTypesUsesUnifiedChannelLanguageCachePolicy(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SearchProviderRegistry.php',
        );

        self::assertStringContainsString('StorefrontScopeHotCache', $source);
        self::assertStringContainsString('rememberPolicy', $source);
        self::assertStringContainsString("resource: 'search.provider_types'", $source);
        self::assertStringContainsString("scope: 'channel'", $source);
        self::assertStringContainsString("vary: ['lang', 'area']", $source);
    }

    public function testTranslatedTypeLabelsDependOnGlobalDictionaryVersion(): void
    {
        $policy = (new \ReflectionMethod(SearchProviderRegistry::class, 'typesPolicy'))->invoke(null);
        $identity = \Weline\Framework\Runtime\ScopeIdentity::channel(0, 'default', 'default', 'default', \Weline\Framework\Runtime\ScopeIdentity::MODE_NORMAL);
        self::assertContains('global/i18n', $policy->namespacePaths($identity));
    }

    public function testAreaReadsReuseUnfilteredProvidersWithoutLosingOtherAreas(): void
    {
        $frontend = $this->createStub(\Weline\Search\Api\SearchProviderInterface::class);
        $frontend->method('areas')->willReturn(['frontend']);
        $backend = $this->createStub(\Weline\Search\Api\SearchProviderInterface::class);
        $backend->method('areas')->willReturn(['backend']);
        $registry = new SearchProviderRegistry($this->createMock(ObjectManager::class));
        (new \ReflectionProperty($registry, 'providers'))->setValue($registry, [
            'product' => $frontend,
            'system_config' => $backend,
        ]);

        self::assertSame(['product' => $frontend], $registry->all(area: ' FRONTEND '));
        self::assertSame(['system_config' => $backend], $registry->all(area: 'backend'));
        self::assertSame(['product' => $frontend, 'system_config' => $backend], $registry->all());
    }

    public function testAreaVisibilityIsStillEvaluatedOnEachRead(): void
    {
        $provider = $this->createStub(\Weline\Search\Api\SearchProviderInterface::class);
        $provider->method('areas')->willReturnOnConsecutiveCalls(['frontend'], ['backend']);
        $registry = new SearchProviderRegistry($this->createMock(ObjectManager::class));
        (new \ReflectionProperty($registry, 'providers'))->setValue($registry, ['dynamic' => $provider]);

        self::assertSame(['dynamic' => $provider], $registry->all(area: 'frontend'));
        self::assertSame([], $registry->all(area: 'frontend'));
    }

    public function testHitTemplateMapCollectsProviderTemplates(): void
    {
        $product = new class implements \Weline\Search\Api\SearchProviderInterface {
            public function code(): string { return 'product'; }
            public function label(): string { return '商品'; }
            public function sortOrder(): int { return 10; }
            public function areas(): array { return ['frontend']; }
            public function expression(\Weline\Search\Dto\SearchRequest $request): \Weline\Search\Service\SearchExpression {
                return \Weline\Search\Service\SearchExpression::of($request);
            }
            public function allowedClientParams(): array { return []; }
            public function hitTemplate(): string {
                return 'Weline_Product::templates/frontend/search/hit.phtml';
            }
            public function execute(\Weline\Search\Dto\SearchRequest $request, \Weline\Search\Service\SearchExpression $expression): \Weline\Search\Dto\SearchResult {
                return new \Weline\Search\Dto\SearchResult(ok: true, type: 'product', hits: [], hitCount: 0);
            }
            public function documentsForIndex(\Weline\Search\Dto\SearchRequest $request): array { return []; }
        };

        $objectManager = $this->createMock(ObjectManager::class);
        $registry = new SearchProviderRegistry($objectManager);
        $reflection = new \ReflectionClass($registry);
        $property = $reflection->getProperty('providers');
        $property->setAccessible(true);
        $property->setValue($registry, ['product' => $product]);

        self::assertSame(
            ['product' => 'Weline_Product::templates/frontend/search/hit.phtml'],
            $registry->hitTemplateMap(),
        );
    }
}
