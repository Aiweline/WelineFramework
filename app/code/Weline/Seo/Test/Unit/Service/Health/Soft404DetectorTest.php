<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Health;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Health\Soft404Detector;

final class Soft404DetectorTest extends TestCase
{
    private Soft404Detector $detector;

    protected function setUp(): void
    {
        $this->detector = new Soft404Detector();
    }

    public function testMatchesWhenTitleEquals404Template(): void
    {
        $fingerprint = [
            'status' => 404,
            'title' => '页面不存在 - 长安汉服',
            'text_length' => 40,
            'marker_hits' => ['页面不存在'],
        ];
        $probe = [
            'status' => 200,
            'final_url' => 'https://example.com/old',
            'body' => '<html><head><title>页面不存在 - 长安汉服</title></head><body>换个页面看看</body></html>',
        ];

        $result = $this->detector->match($probe, $fingerprint);

        self::assertTrue($result['matched']);
        self::assertStringContainsString('title', $result['reason']);
    }

    public function testMatchesShortPageWithMarkerNearTop(): void
    {
        $probe = [
            'status' => 200,
            'final_url' => 'https://example.com/old',
            'body' => '<html><body><h1>页面不存在</h1><p>你访问的商品已经下架。</p></body></html>',
        ];

        $result = $this->detector->match($probe, null, ['min_text_length' => 10]);

        self::assertTrue($result['matched']);
    }

    public function testDoesNotMatchSubstantialArticleThatMentions404(): void
    {
        $paragraph = str_repeat('这是一篇正常的长文章，讲解 HTTP 404 状态码的含义与处理方式。', 200);
        $probe = [
            'status' => 200,
            'final_url' => 'https://example.com/blog/http-status',
            'body' => '<html><head><title>HTTP 状态码详解</title></head><body><article>' . $paragraph . '</article></body></html>',
        ];

        $result = $this->detector->match($probe, null);

        self::assertFalse($result['matched']);
    }

    public function testNon2xxIsNeverSoft404(): void
    {
        $probe = [
            'status' => 404,
            'final_url' => 'https://example.com/missing',
            'body' => '<html><title>404</title><body>页面不存在</body></html>',
        ];

        $result = $this->detector->match($probe, null);

        self::assertFalse($result['matched']);
    }

    public function testFingerprintIsBuiltFromReal404Page(): void
    {
        $probe = [
            'status' => 404,
            'final_url' => 'https://example.com/missing',
            'body' => '<html><head><title>404 页面不存在</title></head><body>页面不存在</body></html>',
        ];

        $fingerprint = $this->detector->buildFingerprint($probe);

        self::assertSame(404, $fingerprint['status']);
        self::assertSame('404 页面不存在', $fingerprint['title']);
        self::assertGreaterThan(0, $fingerprint['text_length']);
        self::assertContains('页面不存在', $fingerprint['marker_hits']);
    }
}
