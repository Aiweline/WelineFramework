<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBlogFrontendContractsPass().
 */
final class BlogFrontendContractsTest extends TestCase
{
    public function testAllBlogFrontendContractsPass(): void
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
            'BlogCategorySeoTitle::testCategoryLeafTitleUsesNeutralBlogTemplates' => function () {
            $this->assert_BlogCategorySeoTitle_testCategoryLeafTitleUsesNeutralBlogTemplates();
        },
        ];
    }

private function assert_BlogCategorySeoTitle_testCategoryLeafTitleUsesNeutralBlogTemplates(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Controller/Frontend/Category.php');
    self::assertStringContainsString('分类 · 博客文章', $src);
    self::assertStringContainsString('blog_page_heading', $src);
    self::assertStringContainsString("WidgetI18n::label('博客分类')", $src);
    self::assertStringNotContainsString('汉服博客', $src);
    self::assertStringContainsString("mb_strlen((string)\$seo['description']) < 50", $src);
}
}
