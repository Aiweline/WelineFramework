<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 店面博客列表：响应式网格契约 + 类名基线。
 *
 * 背景（2026-10-28 线上）：/blog 列表一列通栏。根因是线上布局固化物缓存了改名前的
 * `.amazon-blog-listing__grid` CSS，而 DOM 已是 `.blog-listing__grid`，导致网格规则不命中。
 * 本测试锁三件事：
 * 1) 列表网格必须是 auto-fill 自适应（不再回到固定列断点/单列）；
 * 2) 新代码统一输出 `w-` 前缀类（w-blog-listing__grid）；
 * 3) Blog 店面运行时文件里不得再出现 amazon 厂商前缀类。
 */
final class StorefrontBlogListingResponsiveTest extends TestCase
{
    private function modulePath(string $suffix): string
    {
        return \dirname(__DIR__, 2) . '/view/' . $suffix;
    }

    public function testListingGridUsesAutoFillInsteadOfFixedColumns(): void
    {
        $layout = (string)file_get_contents($this->modulePath('theme/frontend/layouts/blog/default.phtml'));

        self::assertMatchesRegularExpression(
            '/\.blog-listing__grid\s*\{[^}]*repeat\(auto-fill,\s*minmax\(/s',
            $layout,
            '列表网格必须使用 repeat(auto-fill, minmax(...)) 自适应列数'
        );
        self::assertMatchesRegularExpression(
            '/\.w-blog-listing__grid\s*\{[^}]*repeat\(auto-fill,\s*minmax\(/s',
            $layout,
            'w- 前缀列表网格必须同样使用 auto-fill'
        );
        self::assertStringNotContainsString(
            '.blog-listing__grid { grid-template-columns: repeat(4',
            $layout,
            '列表网格不得回退到固定 4 列'
        );
    }

    public function testListingTemplateEmitsWPrefixedGridClass(): void
    {
        $index = (string)file_get_contents($this->modulePath('templates/frontend/index.phtml'));
        self::assertStringContainsString(
            'class="blog-listing__grid w-blog-listing__grid"',
            $index,
            '列表容器必须同时输出 w- 前缀新类（旧类保留一版兼容其他主题）'
        );
        self::assertStringContainsString(
            'w-blog-storefront__card',
            $index,
            '卡片必须输出 w- 前缀新类'
        );
    }

    public function testBlogStorefrontRuntimeHasNoAmazonVendorClasses(): void
    {
        $roots = [
            $this->modulePath('templates'),
            $this->modulePath('theme'),
            $this->modulePath('statics'),
        ];
        $hits = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (!in_array($file->getExtension(), ['phtml', 'css', 'js'], true)) {
                    continue;
                }
                $content = (string)file_get_contents($file->getPathname());
                if (str_contains($content, 'amazon-')) {
                    $hits[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        self::assertSame([], $hits, 'Blog 店面运行时文件不得再出现 amazon- 厂商类');
    }
}
