<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticAssetHtmlRewriter;
use Weline\Framework\Deploy\StaticBuildId;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime;

/**
 * 最终 HTML 统一加构建代次：历史硬编码 /static/ 也覆盖；已版本化/内容寻址不重复加。
 */
final class StaticAssetHtmlRewriterTest extends TestCase
{
    private const ID = 'abc123def456';

    public function testRewritesAttributesAndCssUrls(): void
    {
        $hash = \str_repeat('a', 64);
        $html = '<link rel="stylesheet" href="/static/Weline/hanfu/frontend/assets/css/a.css?v=20260101">'
            . '<script src="/static/Weline/Frontend/js/b.js"></script>'
            . '<img src="/static/Weline/hanfu/frontend/assets/images/x.png">'
            . '<div style="background:url(/static/Weline/hanfu/frontend/assets/images/y.webp)"></div>'
            . '<img src="/static/Weline/Theme/widget-assets/' . $hash . '.css">'
            . '<img src="/static/' . self::ID . '/Weline/Frontend/js/already.js">'
            . '<img src="https://cdn.example.com/static/Weline/x.js">';

        $out = StaticAssetHtmlRewriter::rewrite($html, self::ID);

        self::assertStringContainsString('href="/static/' . self::ID . '/Weline/hanfu/frontend/assets/css/a.css?v=20260101"', $out);
        self::assertStringContainsString('src="/static/' . self::ID . '/Weline/Frontend/js/b.js"', $out);
        self::assertStringContainsString('src="/static/' . self::ID . '/Weline/hanfu/frontend/assets/images/x.png"', $out);
        self::assertStringContainsString('url(/static/' . self::ID . '/Weline/hanfu/frontend/assets/images/y.webp)', $out);
        // 内容寻址文件不加前缀
        self::assertStringContainsString('/static/Weline/Theme/widget-assets/' . $hash . '.css', $out);
        // 已带当前构建段不重复加
        self::assertSame(1, \substr_count($out, '/static/' . self::ID . '/Weline/Frontend/js/already.js'));
        // 外部 URL 不动
        self::assertStringContainsString('https://cdn.example.com/static/Weline/x.js', $out);
    }

    public function testRealignsStaleThemePairToAppliedPair(): void
    {
        ThemeUpdateRuntime::forceBatch([
            'batch_id' => 'tu-test',
            'static_ns' => 'tu-test',
            'entity_root' => 'tu-test',
            'theme_prefix' => 'Weline/hanfu',
            'epoch' => 1,
            'pair_segment' => 'hanfu/v3',
        ]);
        try {
            $html = '<script src="/static/theme/v1/' . self::ID . '/Weline/Theme/view/theme/frontend/assets/js/a.js?v=1"></script>'
                . '<script src="/static/' . self::ID . '/Weline/Frontend/js/b.js"></script>';
            $out = StaticAssetHtmlRewriter::rewrite($html, self::ID);
            self::assertStringContainsString(
                '/static/hanfu/v3/' . self::ID . '/Weline/Theme/view/theme/frontend/assets/js/a.js?v=1',
                $out,
            );
            self::assertStringNotContainsString('/static/theme/v1/', $out);
            self::assertSame(1, \substr_count($out, '/static/' . self::ID . '/Weline/Frontend/js/b.js'));
        } finally {
            ThemeUpdateRuntime::reset();
        }
    }

    public function testPinnedThemeBuildKeepsModuleAssetsOnDeployBuild(): void
    {
        StaticBuildId::setFlatUrlsOverride(false);
        ThemeUpdateRuntime::forceBatch([
            'batch_id' => 'tu-test',
            'static_ns' => 'tu-test',
            'entity_root' => 'tu-test',
            'theme_prefix' => 'Weline/hanfu',
            'epoch' => 1,
            'pair_segment' => 'hanfu/v3',
            'static_build' => 'fedcba654321',
        ]);
        try {
            $html = '<link rel="stylesheet" href="/static/Weline/hanfu/frontend/assets/css/a.css">'
                . '<script src="/static/' . self::ID . '/Weline/Frontend/js/b.js"></script>';
            $out = StaticAssetHtmlRewriter::rewrite($html, self::ID);

            // 主题自有资源走指针上的静态段（每次换代换目录）
            self::assertStringContainsString('/static/hanfu/v3/fedcba654321/Weline/hanfu/frontend/assets/css/a.css', $out);
            // 模块/框架资源仍走部署构建号
            self::assertStringContainsString('/static/' . self::ID . '/Weline/Frontend/js/b.js', $out);
        } finally {
            ThemeUpdateRuntime::reset();
            StaticBuildId::setFlatUrlsOverride(null);
        }
    }

    public function testNoopWithoutBuildIdOrStaticReference(): void
    {
        $html = '<img src="/static/Weline/a.png">';
        self::assertSame($html, StaticAssetHtmlRewriter::rewrite($html, ''));
        self::assertSame('<p>no assets</p>', StaticAssetHtmlRewriter::rewrite('<p>no assets</p>', self::ID));
    }
}
