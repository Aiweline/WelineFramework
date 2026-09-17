<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Head;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;
use Weline\Seo\Service\Head\SeoPageProfileBag;

/**
 * Root-cause contract: content is primed before layout head.
 * Product-card setData('storefront_offer') must not redefine the page SEO bag.
 */
final class SeoPageProfileBagNoCardPollutionTest extends TestCase
{
    protected function tearDown(): void
    {
        SeoPageProfileBag::reset();
        RequestContext::remove(SeoPageProfileBag::REQUEST_KEY);
        parent::tearDown();
    }

    public function testOnlyExplicitSeoPayloadWritesPageProfileBag(): void
    {
        $template = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $publish = new ReflectionMethod(Template::class, 'publishAssignedSeoPageProfile');
        $publish->setAccessible(true);

        $publish->invoke($template, [
            'seo' => [
                'page_type' => 'products',
                'title' => 'All Products',
                'item_list' => [
                    ['name' => 'Listing Item', 'url' => '/product/1'],
                ],
            ],
        ]);

        // Simulate product-card / add-to-cart partial during content priming.
        $publish->invoke($template, [
            'storefront_offer' => [
                'product_id' => 107,
                'name' => 'Card Offer',
            ],
        ]);
        $publish->invoke($template, [
            'product' => [
                'product_id' => 107,
                'name' => 'Should Not Become Page Product',
            ],
        ]);

        $profile = SeoPageProfileBag::pull();
        self::assertSame('products', $profile['page_type'] ?? null);
        self::assertSame('All Products', $profile['title'] ?? null);
        self::assertArrayNotHasKey('product', $profile);
        self::assertSame('Listing Item', $profile['item_list'][0]['name'] ?? null);
    }

    public function testExplicitSeoAssignOwnsProductPageBag(): void
    {
        $template = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $publish = new ReflectionMethod(Template::class, 'publishAssignedSeoPageProfile');
        $publish->setAccessible(true);

        $publish->invoke($template, [
            'seo' => [
                'page_type' => 'product',
                'title' => 'Hanfu Detail',
                'product' => ['product_id' => 100, 'name' => 'Hanfu Detail'],
            ],
        ]);
        $publish->invoke($template, [
            'storefront_offer' => [
                'product_id' => 999,
                'name' => 'Later Card Must Not Win',
            ],
        ]);

        $profile = SeoPageProfileBag::pull();
        self::assertSame('product', $profile['page_type'] ?? null);
        self::assertSame(100, $profile['product']['product_id'] ?? null);
        self::assertSame('Hanfu Detail', $profile['title'] ?? null);
    }

    public function testTemplateSetDataOnlyPublishesSeoKey(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 5) . '/Framework/View/Template.php'
        );
        self::assertStringContainsString("elseif (\$key === 'seo' && is_array(\$value))", $source);
        self::assertStringContainsString('ONLY the `seo` assign owns this bag', $source);
        self::assertStringNotContainsString("'storefront_offer',", $source);
    }
}
