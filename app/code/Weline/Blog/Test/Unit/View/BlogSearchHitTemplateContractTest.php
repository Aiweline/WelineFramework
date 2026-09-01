<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BlogSearchHitTemplateContractTest extends TestCase
{
    public function testHitTemplateDispatchesByContentKind(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/search/hit.phtml');
        self::assertStringContainsString('search-hit-post.phtml', $template);
        self::assertStringContainsString('search-hit-cms.phtml', $template);
        self::assertStringContainsString('content_kind', $template);
    }

    public function testBlogSearchHitsRenderAsCards(): void
    {
        $post = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/search/partials/search-hit-post.phtml',
        );
        $cms = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/search/partials/search-hit-cms.phtml',
        );

        self::assertStringContainsString('blog-storefront__card', $post);
        self::assertStringContainsString('data-testid="storefront-blog-card"', $post);
        self::assertStringContainsString('blog-storefront__media', $post);
        self::assertStringContainsString('blog-storefront__body', $post);
        self::assertStringContainsString('StorefrontImagePlaceholder', $post);

        self::assertStringContainsString('blog-storefront__card', $cms);
        self::assertStringContainsString('data-testid="storefront-blog-card"', $cms);
        self::assertStringContainsString('data-testid="search-hit-blog-cms"', $cms);
    }
}
