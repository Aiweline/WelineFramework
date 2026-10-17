<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Extends\Module\Weline_Review;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Extends\Module\Weline_Review\ReviewTypeProvider\BlogReviewTypeProvider;
use Weline\Review\Api\ReviewTypeProviderInterface;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBlogWeline_ReviewContractsPass().
 */
final class BlogWelineReviewContractsTest extends TestCase
{
    public function testAllBlogWeline_ReviewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'BlogReviewTypeProvider::testProviderImplementsReviewTypeInterface' => function () {
            $this->assert_BlogReviewTypeProvider_testProviderImplementsReviewTypeInterface();
        },
            'BlogReviewTypeProvider::testProviderSourceDeclaresBlogTypeAndEntityPrefix' => function () {
            $this->assert_BlogReviewTypeProvider_testProviderSourceDeclaresBlogTypeAndEntityPrefix();
        },
        ];
    }

private function assert_BlogReviewTypeProvider_testProviderImplementsReviewTypeInterface(): void
{
    self::assertTrue(is_a(BlogReviewTypeProvider::class, ReviewTypeProviderInterface::class, true));
}

private function assert_BlogReviewTypeProvider_testProviderSourceDeclaresBlogTypeAndEntityPrefix(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Review/ReviewTypeProvider/BlogReviewTypeProvider.php');
    self::assertStringContainsString("return 'blog';", $source);
    self::assertStringContainsString("'blog:post:'", $source);
    self::assertStringNotContainsString('quality_rating', $source);
}
}
