<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
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

        $context = (new PageSeoContextResolver())->resolve($template);

        self::assertSame('privacy_policy', $context['page_type']);
        self::assertSame('Privacy Policy', $context['title']);
        self::assertSame('https://shop.test/privacy-policy', $context['canonical_url']);
        self::assertCount(2, $context['schema_nodes']);
        self::assertSame(['@type' => 'DigitalDocument'], $context['schema_nodes'][0]);
        self::assertSame(['@type' => 'FAQPage', 'name' => 'Privacy FAQ'], $context['schema_nodes'][1]);
        self::assertCount(2, $context['breadcrumbs']);
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
