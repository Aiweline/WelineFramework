<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\PostResponseCaptureMode;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\WlsFiberContext;

final class PostResponseTaskQueueCaptureModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->drainAll();
        (new \ReflectionProperty(PostResponseTaskQueue::class, 'sharedFullByRequestId'))->setValue(null, []);
    }

    protected function tearDown(): void
    {
        $this->drainAll();
        (new \ReflectionProperty(PostResponseTaskQueue::class, 'sharedFullByRequestId'))->setValue(null, []);
        Runtime::resetModeCache();
        parent::tearDown();
    }

    public function testNoneModeStoresNoContextEvenWhenPersistent(): void
    {
        Runtime::setMode(Runtime::WLS);
        PostResponseTaskQueue::enqueue(
            'capture-none',
            static function (): void {},
            0.0,
            PostResponseCaptureMode::None,
        );
        $peek = PostResponseTaskQueue::debugPeekTask('capture-none');
        self::assertNotNull($peek);
        self::assertSame(PostResponseCaptureMode::None, $peek['mode']);
        self::assertNull($peek['context']);
        self::assertNull($peek['scope']);
        self::assertSame(1, PostResponseTaskQueue::getRuntimeDiagnostics()['capture_none']);
    }

    public function testFullModeSharesOneSnapshotPerRequestId(): void
    {
        Runtime::setMode(Runtime::WLS);
        RequestContext::cleanup();
        RequestContext::init();
        RequestContext::setId('unit-post-response-share');
        try {
            PostResponseTaskQueue::enqueue('share-a', static function (): void {});
            PostResponseTaskQueue::enqueue('share-b', static function (): void {});
            $a = PostResponseTaskQueue::debugPeekTask('share-a');
            $b = PostResponseTaskQueue::debugPeekTask('share-b');
            self::assertNotNull($a);
            self::assertNotNull($b);
            self::assertInstanceOf(WlsFiberContext::class, $a['context']);
            self::assertSame($a['context'], $b['context']);
            self::assertSame(1, PostResponseTaskQueue::sharedFullSnapshotCount());
            self::assertSame('unit-post-response-share', $a['shared_request_id']);
        } finally {
            RequestContext::cleanup();
        }
    }

    public function testLegacyThreeArgSignatureDefaultsToFullMode(): void
    {
        PostResponseTaskQueue::enqueue('legacy-full', static function (): void {});
        $peek = PostResponseTaskQueue::debugPeekTask('legacy-full');
        self::assertNotNull($peek);
        self::assertSame(PostResponseCaptureMode::Full, $peek['mode']);
        self::assertSame(1, PostResponseTaskQueue::getRuntimeDiagnostics()['capture_full']);
    }

    public function testScopeModeCapturesEnvelopeWithoutFullFiberContext(): void
    {
        Runtime::setMode(Runtime::WLS);
        RequestContext::cleanup();
        RequestContext::init();
        RequestContext::setId('unit-post-response-scope');
        try {
            RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
            RequestContext::setWelineWebsiteUrl('https://scope.example.test');
            $context = Context::getCurrent();
            self::assertNotNull($context);
            $context->set('input.host', 'scope.example.test');
            $context->set('input.scheme', 'https');
            $_SERVER['HTTP_HOST'] = 'scope.example.test';
            $_SERVER['REQUEST_SCHEME'] = 'https';
            PostResponseTaskQueue::enqueue(
                'capture-scope',
                static function (): void {},
                0.0,
                PostResponseCaptureMode::Scope,
            );
            $peek = PostResponseTaskQueue::debugPeekTask('capture-scope');
            self::assertNotNull($peek);
            self::assertSame(PostResponseCaptureMode::Scope, $peek['mode']);
            self::assertNull($peek['context']);
            self::assertIsArray($peek['scope']);
            self::assertArrayHasKey('envelope', $peek['scope']);
            self::assertSame(RequestContext::AREA_FRONTEND, $peek['scope']['area'] ?? null);
            self::assertSame('scope.example.test', $peek['scope']['host'] ?? null);
            self::assertSame('https', $peek['scope']['scheme'] ?? null);
            self::assertSame('https://scope.example.test', $peek['scope']['website_url'] ?? null);
            self::assertSame(1, PostResponseTaskQueue::getRuntimeDiagnostics()['capture_scope']);
            self::assertSame(0, PostResponseTaskQueue::sharedFullSnapshotCount());
        } finally {
            RequestContext::cleanup();
        }
    }

    public function testFullModeUsesCaptureForPostResponse(): void
    {
        $queueSrc = (string)\file_get_contents(
            (string)(new \ReflectionClass(PostResponseTaskQueue::class))->getFileName()
        );
        $fiberSrc = (string)\file_get_contents(
            (string)(new \ReflectionClass(WlsFiberContext::class))->getFileName()
        );
        self::assertStringContainsString('WlsFiberContext::captureForPostResponse()', $queueSrc);
        self::assertMatchesRegularExpression(
            '/function captureForPostResponse\(\): self\s*\{\s*return self::captureInternal\(false\);\s*\}/',
            $fiberSrc,
        );
        self::assertMatchesRegularExpression(
            '/if \(\$includeHeaderCollector\) \{\s*\$ctx->headerCollectorState = HeaderCollector::getInstance\(\)->captureState\(\);/',
            $fiberSrc,
        );
    }

    private function drainAll(): void
    {
        // Clear without drain(): Env::get inside drain requires full app BP bootstrap.
        (new \ReflectionProperty(PostResponseTaskQueue::class, 'tasks'))->setValue(null, []);
        (new \ReflectionProperty(PostResponseTaskQueue::class, 'draining'))->setValue(null, false);
    }
}
