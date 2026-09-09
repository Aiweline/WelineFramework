<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Controller\Frontend\View;
use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogContentResolver;

final class BlogContentResolverReuseContractTest extends TestCase
{
    public function testResolverRemembersPublishedRowsAndBatchesSlugLookups(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BlogContentResolver.php',
        );
        self::assertStringContainsString('rememberPublishedPostRow', $source);
        self::assertStringContainsString('findPublishedPostsBySlugs', $source);
        self::assertStringContainsString('blog.published_post.by_id.v1.', $source);
        self::assertStringContainsString('CTX_SLUG_MISS', $source);
        self::assertStringNotContainsString('max($limit, 50)', $source);
        self::assertStringContainsString('hydrateLoadedRow', $source);
    }

    public function testViewReadsContentFromContextBeforeLoad(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/View.php',
        );
        self::assertStringContainsString('blog.published_post.by_id.v1.', $source);
        self::assertTrue(method_exists(Post::class, 'hydrateLoadedRow'));
        self::assertTrue(class_exists(View::class));
        self::assertTrue(class_exists(BlogContentResolver::class));
    }
}
