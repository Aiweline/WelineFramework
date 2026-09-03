<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Head\HeadProviderRegistry;
use Weline\Seo\Service\Head\PageSeoContextResolver;

class PageSeoContextResolverPlanJsonTest extends TestCase
{
    public function testResolvesPlanJsonPageSeoSchemaNodesAndBreadcrumbs(): void
    {
        $template = new PageSeoContextResolverPlanJsonTemplateStub([
            'site_name' => 'RoyalRummy',
            'seo' => [
                'page_type' => 'privacy_policy',
                'title' => 'Privacy Policy',
                'description' => 'Privacy policy for RoyalRummy players.',
                'canonical_url' => 'https://shop.test/privacy-policy',
                'schema_nodes' => [
                    ['@type' => 'DigitalDocument'],
                    ['@type' => 'FAQPage', 'name' => 'Privacy FAQ'],
                ],
                'breadcrumbs' => [
                    ['name' => 'Home', 'url' => 'https://shop.test/'],
                    ['name' => 'Privacy Policy', 'url' => 'https://shop.test/privacy-policy'],
                ],
            ],
        ]);

        $context = (new PageSeoContextResolver(new PageSeoContextResolverEmptyProviderRegistry()))->resolve($template);

        self::assertSame('privacy_policy', $context['page_type']);
        self::assertSame('Privacy Policy', $context['title']);
        self::assertSame('https://shop.test/privacy-policy', $context['canonical_url']);
        self::assertCount(2, $context['schema_nodes']);
        self::assertSame(['@type' => 'DigitalDocument'], $context['schema_nodes'][0]);
        self::assertSame(['@type' => 'FAQPage', 'name' => 'Privacy FAQ'], $context['schema_nodes'][1]);
        self::assertCount(2, $context['breadcrumbs']);
    }

    public function testResolvesProductFromSharedSeoProfileForIsolatedHeadTemplate(): void
    {
        $product = [
            'product_id' => 83,
            'name' => '汉服商品',
            'storefront_offers' => [
                ['sku' => 'ZZS-HANFU-RED-S', 'specifications' => ['color' => '48', 'size' => '57']],
                ['sku' => 'ZZS-HANFU-RED-M', 'specifications' => ['color' => '48', 'size' => '58']],
            ],
        ];
        $template = new PageSeoContextResolverPlanJsonTemplateStub([
            'seo' => [
                'page_type' => 'product',
                'title' => '汉服商品',
                'product' => $product,
            ],
        ]);

        $context = (new PageSeoContextResolver(new PageSeoContextResolverEmptyProviderRegistry()))->resolve($template);

        self::assertSame('product', $context['page_type']);
        self::assertSame($product, $context['product']);
        self::assertCount(2, $context['product']['storefront_offers']);
    }
    public function testLazilyResolvesIntegrationContextWhenOptionalDependencyIsOmitted(): void
    {
        if (!defined('BP')) {
            define('BP', dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
        if (!defined('CLI')) {
            define('CLI', true);
        }
        if (!defined('PROD')) {
            define('PROD', false);
        }
        \Weline\Framework\Manager\ObjectManager::getInstance();

        $integration = $this->createMock(\Weline\Seo\Service\Head\HeadIntegrationContextService::class);
        $integration->expects(self::once())
            ->method('resolve')
            ->willReturn([
                'locale' => 'en_US',
                'alternates' => [
                    'en_US' => 'https://shop.test/en_US/products',
                    'x-default' => 'https://shop.test/products',
                ],
            ]);
        \Weline\Framework\Manager\ObjectManager::setInstance(
            \Weline\Seo\Service\Head\HeadIntegrationContextService::class,
            $integration,
        );

        try {
            $template = new PageSeoContextResolverPlanJsonTemplateStub([
                'seo' => [
                    'title' => 'Hanfu Collection',
                    'canonical_url' => 'https://shop.test/en_US/products',
                ],
            ]);

            $context = (new PageSeoContextResolver(new PageSeoContextResolverEmptyProviderRegistry()))
                ->resolve($template);

            self::assertSame('en_US', $context['locale']);
            self::assertSame(
                'https://shop.test/products',
                $context['alternates']['x-default'] ?? null,
            );
        } finally {
            \Weline\Framework\Manager\ObjectManager::removeInstance(
                \Weline\Seo\Service\Head\HeadIntegrationContextService::class,
            );
        }
    }
}

final class PageSeoContextResolverPlanJsonTemplateStub
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}

final class PageSeoContextResolverEmptyProviderRegistry extends HeadProviderRegistry
{
    public function __construct()
    {
    }

    public function getSeoProfileProviders(bool $forceReload = false): array
    {
        return [];
    }
}
