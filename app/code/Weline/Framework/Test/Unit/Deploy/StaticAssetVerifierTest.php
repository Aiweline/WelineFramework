<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticAssetVerifier;

/**
 * 发布验收：HTML 里每个本站 /static 资源必须可访问；未版本化的模块 JS/CSS 直接判失败。
 */
final class StaticAssetVerifierTest extends TestCase
{
    public function testExtractsDedupedLocalStaticAssetsOnly(): void
    {
        $hash = \str_repeat('a', 64);
        $html = <<<HTML
<link rel="stylesheet" href="/static/abc123def456/Weline/Frontend/css/a.css">
<script src="/static/Weline/Theme/widget-assets/{$hash}.js"></script>
<script src="/static/Weline/Frontend/js/b.js"></script>
<script src="/static/Weline/Frontend/js/b.js"></script>
<img src="https://cdn.example.com/x.png">
<script src="/pub/media/upload.js"></script>
HTML;
        $urls = StaticAssetVerifier::extractAssetUrls($html);

        self::assertSame([
            '/static/abc123def456/Weline/Frontend/css/a.css',
            "/static/Weline/Theme/widget-assets/{$hash}.js",
            '/static/Weline/Frontend/js/b.js',
        ], $urls);
    }

    public function testUnversionedModuleAssetAndBrokenUrlAreReported(): void
    {
        $hash = \str_repeat('a', 64);
        $html = <<<HTML
<link rel="stylesheet" href="/static/abc123def456/Weline/Frontend/css/a.css">
<script src="/static/Weline/Theme/widget-assets/{$hash}.js"></script>
<script src="/static/Weline/Frontend/js/b.js"></script>
HTML;
        $urls = StaticAssetVerifier::extractAssetUrls($html);
        $statuses = [
            '/static/abc123def456/Weline/Frontend/css/a.css' => 200,
            "/static/Weline/Theme/widget-assets/{$hash}.js" => 404,
            '/static/Weline/Frontend/js/b.js' => 200,
        ];
        $result = StaticAssetVerifier::verify($urls, static fn (string $url): int => $statuses[$url] ?? 404);

        self::assertSame(
            ['/static/Weline/Frontend/js/b.js'],
            $result['unversioned'],
            '未带版本段的模块 JS 必须单独报出来',
        );
        self::assertSame(
            [['url' => "/static/Weline/Theme/widget-assets/{$hash}.js", 'status' => 404, 'reason' => 'not_200']],
            $result['failures'],
        );
        self::assertFalse($result['ok']);
    }

    public function testAllVersionedAssetsReachableIsOk(): void
    {
        $urls = ['/static/abc123def456/Weline/Frontend/css/a.css'];
        $result = StaticAssetVerifier::verify($urls, static fn (): int => 200);

        self::assertTrue($result['ok']);
        self::assertSame([], $result['failures']);
        self::assertSame([], $result['unversioned']);
    }
}
