<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticAssetHtmlRewriter;
use Weline\Framework\Deploy\StaticBuildId;

/**
 * 用户裁定的静态资源契约：磁盘单权威 flat 树；?v= 只是 query 破缓存令牌，
 * 绝不进 URL 路径段。flag（dev.static_flat_urls）开启后所有出口必须收敛为
 * /static/{稳定路径}，且对一切旧世代形状（12hex、tu- 批次、主题对段）幂等剥段。
 */
final class StaticFlatUrlContractTest extends TestCase
{
    private const ID = 'abc123def456';

    protected function setUp(): void
    {
        StaticBuildId::setFlatUrlsOverride(true);
    }

    protected function tearDown(): void
    {
        StaticBuildId::setFlatUrlsOverride(null);
    }

    public function testStablePathStripsAllGenerationShapes(): void
    {
        self::assertSame(
            '/static/Weline/Frontend/js/a.js',
            StaticBuildId::stablePath('/static/' . self::ID . '/Weline/Frontend/js/a.js'),
        );
        self::assertSame(
            '/static/Weline/hanfu/frontend/assets/images/ink/a.webp',
            StaticBuildId::stablePath('/static/tu-20261027-172510-4baf0d/Weline/hanfu/frontend/assets/images/ink/a.webp'),
        );
        self::assertSame(
            '/static/Weline/hanfu/a.css',
            StaticBuildId::stablePath('/static/hanfu/v3/' . self::ID . '/Weline/hanfu/a.css'),
        );
        // query 保留
        self::assertSame(
            '/static/Weline/hanfu/a.css?v=1.2.3',
            StaticBuildId::stablePath('/static/' . self::ID . '/Weline/hanfu/a.css?v=1.2.3'),
        );
        // 非世代形状原样
        self::assertSame(
            '/static/Weline/hanfu/a.css',
            StaticBuildId::stablePath('/static/Weline/hanfu/a.css'),
        );
        // 幂等
        $once = StaticBuildId::stablePath('/static/hanfu/v3/' . self::ID . '/Weline/x/a.css');
        self::assertSame($once, StaticBuildId::stablePath($once));
    }

    public function testVersionedPathIsIdentityOnFlatAndStripsLegacy(): void
    {
        self::assertSame(
            '/static/Weline/Frontend/js/a.js?v=9',
            StaticBuildId::versionedPath('/static/Weline/Frontend/js/a.js?v=9', self::ID),
        );
        self::assertSame(
            '/static/Weline/Frontend/js/a.js',
            StaticBuildId::versionedPath('/static/' . self::ID . '/Weline/Frontend/js/a.js', self::ID),
        );
        // flag 开启时即使显式传 buildId 也不注入路径段
        self::assertStringNotContainsString(self::ID, StaticBuildId::versionedPath('/static/Weline/M/a.js', self::ID));
    }

    public function testPreviewNamespaceUntouched(): void
    {
        $preview = '/static/__preview/k12345678/Weline/hanfu/a.css';
        self::assertSame($preview, StaticBuildId::versionedPath($preview, self::ID));
    }

    public function testRewriterNormalizesHtmlToFlat(): void
    {
        $hash = \str_repeat('a', 64);
        $html = '<link href="/static/' . self::ID . '/Weline/Frontend/js/already.js">'
            . '<script src="/static/hanfu/v3/' . self::ID . '/Weline/hanfu/a.js?v=7"></script>'
            . '<img src="/static/tu-20261027-172510-4baf0d/Weline/hanfu/x.png">'
            . '<div style="background:url(/static/Weline/hanfu/y.webp)"></div>'
            . '<link href="/static/' . StaticAssetHtmlRewriter::DEPLOY_PLACEHOLDER . '/Weline/Frontend/z.css">'
            . '<link href="/static/' . StaticAssetHtmlRewriter::PAIR_PLACEHOLDER . '/Weline/hanfu/w.css">'
            . '<img src="/static/Weline/Theme/widget-assets/' . $hash . '.css">'
            . '<img src="https://cdn.example.com/static/' . self::ID . '/x.js">';

        $out = StaticAssetHtmlRewriter::rewrite($html, self::ID);

        self::assertStringContainsString('href="/static/Weline/Frontend/js/already.js"', $out);
        self::assertStringContainsString('src="/static/Weline/hanfu/a.js?v=7"', $out);
        self::assertStringContainsString('src="/static/Weline/hanfu/x.png"', $out);
        self::assertStringContainsString('url(/static/Weline/hanfu/y.webp)', $out);
        self::assertStringContainsString('href="/static/Weline/Frontend/z.css"', $out);
        self::assertStringContainsString('href="/static/Weline/hanfu/w.css"', $out);
        // 内容寻址与外部 URL 不动
        self::assertStringContainsString('/static/Weline/Theme/widget-assets/' . $hash . '.css', $out);
        self::assertStringContainsString('https://cdn.example.com/static/' . self::ID . '/x.js', $out);
        // 本站路径中不得残留任何世代路径段（外部 URL 原样保留）
        self::assertStringNotContainsString('/static/' . self::ID . '/Weline', $out);
        self::assertStringNotContainsString('/static/tu-', $out);
        self::assertStringNotContainsString('/static/hanfu/v3/', $out);
        self::assertStringNotContainsString('__W_', $out);
    }

    public function testRewriterIdempotent(): void
    {
        $html = '<script src="/static/' . self::ID . '/Weline/hanfu/a.js?v=1"></script>';
        $once = StaticAssetHtmlRewriter::rewrite($html, self::ID);
        self::assertSame($once, StaticAssetHtmlRewriter::rewrite($once, self::ID));
    }

    public function testFlagOffKeepsLegacyGenerationPrefixing(): void
    {
        StaticBuildId::setFlatUrlsOverride(false);
        try {
            self::assertSame(
                '/static/' . self::ID . '/Weline/Frontend/js/a.js',
                StaticBuildId::versionedPath('/static/Weline/Frontend/js/a.js', self::ID),
            );
        } finally {
            StaticBuildId::setFlatUrlsOverride(true);
        }
    }
}
