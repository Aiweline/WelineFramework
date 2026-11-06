<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\ResponseShareabilityDecider;

/**
 * 唯一共享性决策点：纯函数，不碰请求态、不计数、不落盘。
 */
final class ResponseShareabilityDeciderTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function decide(array $overrides = []): array
    {
        $base = [
            'method' => 'GET',
            'path' => '/x',
            'policy' => ['enabled' => true, 'ttl' => 300],
            'accepts_html' => false,
            'accepts_json' => false,
            'has_logged_in_session' => false,
            'is_sse' => false,
            'is_editor_or_preview' => false,
            'is_excluded_path' => false,
            'content_type' => '',
        ];

        return (new ResponseShareabilityDecider())->decide(array_merge($base, $overrides));
    }

    public function testNonGetHeadIsNeverShareable(): void
    {
        $decision = $this->decide(['method' => 'POST', 'accepts_json' => true]);
        self::assertFalse($decision['shareable']);
        self::assertSame('none', $decision['leaf']);
        self::assertSame('method', $decision['reason']);
    }

    public function testHeadIsShareableAsHtml(): void
    {
        $decision = $this->decide(['method' => 'HEAD', 'accepts_html' => true]);
        self::assertTrue($decision['shareable']);
        self::assertSame('html', $decision['leaf']);
        self::assertSame(300, $decision['ttl']);
    }

    public function testSseAndEditorPreviewAreNotShareable(): void
    {
        self::assertSame('sse', $this->decide(['is_sse' => true])['reason']);
        self::assertSame('editor', $this->decide(['is_editor_or_preview' => true])['reason']);
    }

    public function testHardExcludedPathIsNotShareable(): void
    {
        $decision = $this->decide(['is_excluded_path' => true]);
        self::assertSame('hard_excluded', $decision['reason']);
        self::assertSame('none', $decision['leaf']);
    }

    public function testPolicyDisabledIsNotShareable(): void
    {
        $decision = $this->decide(['policy' => ['enabled' => false, 'ttl' => 300], 'accepts_html' => true]);
        self::assertFalse($decision['shareable']);
        self::assertSame('policy_disabled', $decision['reason']);
    }

    public function testMissingPolicyIsFailClosed(): void
    {
        $decision = $this->decide(['policy' => [['enabled' => true]], 'accepts_html' => true]);
        self::assertSame('policy_disabled', $decision['reason']);
    }

    public function testHtmlAcceptYieldsHtmlLeaf(): void
    {
        $decision = $this->decide(['accepts_html' => true, 'accepts_json' => false]);
        self::assertSame('html', $decision['leaf']);
        self::assertTrue($decision['shareable']);
    }

    /**
     * 页面叶不吃登录门：已登录顾客照旧命中匿名 shell（Coordinator L797-799 的既有约定）。
     */
    public function testHtmlLeafIgnoresLoggedInSession(): void
    {
        $decision = $this->decide(['accepts_html' => true, 'has_logged_in_session' => true]);
        self::assertTrue($decision['shareable']);
        self::assertSame('html', $decision['leaf']);
    }

    public function testJsonOnlyAcceptYieldsBqLeaf(): void
    {
        $decision = $this->decide(['accepts_html' => false, 'accepts_json' => true]);
        self::assertSame('bq', $decision['leaf']);
        self::assertTrue($decision['shareable']);
    }

    /**
     * 接口叶吃登录门：已登录请求可能带个人化数据，宁可不共享。
     */
    public function testBqLeafIsBlockedByLoggedInSession(): void
    {
        $decision = $this->decide(['accepts_html' => false, 'accepts_json' => true, 'has_logged_in_session' => true]);
        self::assertFalse($decision['shareable']);
        self::assertSame('none', $decision['leaf']);
        self::assertSame('logged_in', $decision['reason']);
    }

    public function testNoAcceptableContentTypeIsNotShareable(): void
    {
        $decision = $this->decide(['accepts_html' => false, 'accepts_json' => false]);
        self::assertFalse($decision['shareable']);
        self::assertSame('unacceptable_accept', $decision['reason']);
    }

    /**
     * 发布期按响应 Content-Type 定叶：Accept 通配（accepts_html=true）也必须落 bq。
     */
    public function testResponseContentTypeOverridesAcceptForLeaf(): void
    {
        $decision = $this->decide([
            'accepts_html' => true,
            'accepts_json' => false,
            'content_type' => 'application/json; charset=utf-8',
        ]);
        self::assertSame('bq', $decision['leaf']);
        self::assertTrue($decision['shareable']);
        self::assertSame(300, $decision['ttl']);
    }

    public function testHtmlContentTypeKeepsHtmlLeaf(): void
    {
        $decision = $this->decide([
            'accepts_html' => false,
            'accepts_json' => true,
            'content_type' => 'text/html; charset=utf-8',
        ]);
        self::assertSame('html', $decision['leaf']);
        self::assertTrue($decision['shareable']);
    }

    public function testJsonContentTypeWithLoggedInSessionIsNotShareable(): void
    {
        $decision = $this->decide([
            'accepts_html' => true,
            'has_logged_in_session' => true,
            'content_type' => 'application/json',
        ]);
        self::assertFalse($decision['shareable']);
        self::assertSame('logged_in', $decision['reason']);
    }

    public function testUnknownContentTypeIsFailClosed(): void
    {
        $decision = $this->decide([
            'accepts_html' => true,
            'content_type' => 'application/pdf',
        ]);
        self::assertFalse($decision['shareable']);
        self::assertSame('content_type', $decision['reason']);
        self::assertSame('none', $decision['leaf']);
    }
}
