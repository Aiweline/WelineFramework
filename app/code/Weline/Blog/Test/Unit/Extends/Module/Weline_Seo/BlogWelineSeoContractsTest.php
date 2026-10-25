<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Extends\Module\Weline_Seo;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBlogWeline_SeoContractsPass().
 */
final class BlogWelineSeoContractsTest extends TestCase
{
    public function testAllBlogWeline_SeoContractsPass(): void
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
            'BlogSeoProfileProvider::testProviderImplementsSeoProfileInterface' => function () {
            $this->assert_BlogSeoProfileProvider_testProviderImplementsSeoProfileInterface();
        },
        ];
    }

private function assert_BlogSeoProfileProvider_testProviderImplementsSeoProfileInterface(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Seo/SeoProfileProvider/BlogSeoProfileProvider.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('SeoProfileProviderInterface', $source);
    self::assertStringContainsString("'page_type' => 'blog_post'", (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogSeoFactsBuilder.php'));
    self::assertStringContainsString("'page_type' => 'blog_list'", (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogSeoFactsBuilder.php'));
    self::assertStringContainsString("'image_alt'", (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogSeoFactsBuilder.php'));
    self::assertStringContainsString('existingImage', $source);
    self::assertStringContainsString('preferredDetailCanonical', $source);
    self::assertStringContainsString('blog-article__header', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/post/detail.phtml'));
    self::assertStringNotContainsString('<header class="blog-article__header">', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/post/detail.phtml'));
    self::assertStringContainsString("getUrl('blog/' . \$slug)", (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Controller/Frontend/View.php'));
    $facts = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogSeoFactsBuilder.php');
    self::assertStringContainsString('localeAwarePath', $facts);
    self::assertStringContainsString('defaultAuthorIdentity', $facts);
    $detail = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/post/detail.phtml');
    self::assertStringContainsString("getUrl(ltrim(\$categoryUrl, '/')", $detail);
}
}
