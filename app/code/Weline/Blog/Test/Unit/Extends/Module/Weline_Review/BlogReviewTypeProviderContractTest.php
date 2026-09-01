<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Extends\Module\Weline_Review;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Extends\Module\Weline_Review\ReviewTypeProvider\BlogReviewTypeProvider;
use Weline\Review\Api\ReviewTypeProviderInterface;

final class BlogReviewTypeProviderContractTest extends TestCase
{
    public function testProviderImplementsReviewTypeInterface(): void
    {
        self::assertTrue(is_a(BlogReviewTypeProvider::class, ReviewTypeProviderInterface::class, true));
    }

    public function testProviderSourceDeclaresBlogTypeAndEntityPrefix(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 5) . '/extends/module/Weline_Review/ReviewTypeProvider/BlogReviewTypeProvider.php');
        self::assertStringContainsString("return 'blog';", $source);
        self::assertStringContainsString("'blog:post:'", $source);
        self::assertStringNotContainsString('quality_rating', $source);
    }
}
