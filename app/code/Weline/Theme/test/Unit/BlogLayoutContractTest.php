<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class BlogLayoutContractTest extends TestCase
{
    public function testBlogDetailLayoutUsesAmazonArticleShell(): void
    {
        $layout = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/blog/default.phtml';
        self::assertFileExists($layout);
        $source = (string)file_get_contents($layout);
        self::assertStringContainsString('data-layout="blog"', $source);
        self::assertStringContainsString('blog-layout__panel', $source);
        self::assertStringContainsString('contentTemplate', $source);
        self::assertStringContainsString('amazon-blog-article', $source);
        self::assertStringContainsString('#eaeded', $source);
        self::assertStringContainsString('--weline-layout-content-max-width', $source);
        self::assertStringContainsString('blog-reviews', $source);
        self::assertStringContainsString('showReviews', $source);
    }

    public function testBlogDetailLayoutStylesContextualHanfuFigures(): void
    {
        $layout = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/blog/default.phtml';
        $source = (string)file_get_contents($layout);

        $figure = $this->cssRule($source, '.amazon-blog-article__content .hanfu-article-figure');
        self::assertStringContainsString('max-width: 100%', $figure);
        self::assertStringContainsString('margin: 1.5rem 0', $figure);
        self::assertStringContainsString('overflow: hidden', $figure);
        self::assertStringContainsString('border: 1px solid', $figure);

        $image = $this->cssRule($source, '.amazon-blog-article__content .hanfu-article-figure img');
        self::assertStringContainsString('display: block', $image);
        self::assertStringContainsString('width: 100%', $image);
        self::assertStringContainsString('height: auto', $image);
        self::assertStringContainsString('aspect-ratio: 3 / 2', $image);
        self::assertStringContainsString('object-fit: cover', $image);

        $caption = $this->cssRule($source, '.amazon-blog-article__content .hanfu-article-figure figcaption');
        self::assertStringContainsString('padding: 0.75rem 1rem', $caption);
        self::assertStringContainsString('color: #565959', $caption);
        self::assertStringContainsString('font-size:', $caption);
        self::assertStringContainsString('line-height: 1.5', $caption);
        self::assertStringContainsString('overflow-wrap: anywhere', $caption);

        $provenance = $this->cssRule($source, '.amazon-blog-article__content .hanfu-article-figure__provenance');
        self::assertStringContainsString('color: #565959', $provenance);
        self::assertStringContainsString('overflow-wrap: anywhere', $provenance);

        $link = $this->cssRule($source, '.amazon-blog-article__content .hanfu-article-figure a');
        self::assertStringContainsString('color: var(--color-link)', $link);
        self::assertStringContainsString('text-decoration: underline', $link);
        $focus = $this->cssRule($source, '.amazon-blog-article__content .hanfu-article-figure a:focus-visible');
        self::assertStringContainsString('outline: 2px solid var(--color-link)', $focus);

        $mobile = $this->cssMediaRule($source, '@media (max-width: 520px)');
        $mobileFigure = $this->cssRule($mobile, '.amazon-blog-article__content .hanfu-article-figure');
        self::assertStringContainsString('margin: 1.25rem 0', $mobileFigure);
        $mobileCaption = $this->cssRule($mobile, '.amazon-blog-article__content .hanfu-article-figure figcaption');
        self::assertStringContainsString('padding: 0.625rem 0.75rem', $mobileCaption);
    }

    public function testBlogCategoryLayoutUsesSidebarAndCardGrid(): void
    {
        $layout = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/blog_category/default.phtml';
        self::assertFileExists($layout);
        $source = (string)file_get_contents($layout);
        self::assertStringContainsString('data-layout="blog_category"', $source);
        self::assertStringContainsString('blog-category-layout__body--with-sidebar', $source);
        self::assertStringContainsString('category-filter.phtml', $source);
        self::assertStringContainsString('amazon-blog-listing__grid', $source);
        self::assertStringContainsString('contentTemplate', $source);
        self::assertStringContainsString('--weline-layout-content-max-width', $source);
    }

    private function cssRule(string $source, string $selector): string
    {
        $matched = preg_match(
            '/' . preg_quote($selector, '/') . '\\s*\\{(?<declarations>[^}]*)\\}/s',
            $source,
            $matches,
        );
        self::assertSame(1, $matched, 'Missing CSS rule for ' . $selector);

        return $matches['declarations'];
    }

    private function cssMediaRule(string $source, string $mediaQuery): string
    {
        $matched = preg_match(
            '/' . preg_quote($mediaQuery, '/') . '\\s*\\{(?<rules>(?:[^{}]|\\{[^{}]*\\})*)\\}/s',
            $source,
            $matches,
        );
        self::assertSame(1, $matched, 'Missing CSS media rule ' . $mediaQuery);

        return $matches['rules'];
    }
}
