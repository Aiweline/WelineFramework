<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\WlsRuntime;

final class WlsRuntimeProcessUrlParseFullUriTest extends TestCase
{
    private array $originalServer = [];
    private array $originalGet = [];
    private array $originalPost = [];

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        RequestContext::cleanup();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        RequestContext::cleanup();
    }

    public function testProcessUrlParseRebuildsFullRequestUriFromCurrentRequest(): void
    {
        $_SERVER = [
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'p11005ce4.weline.local',
            'REQUEST_URI' => '/pagebuilder/backend/preview/full?page_id=152&visual_editor=1',
            'WELINE_FULL_REQUEST_URI' => 'https://p11005ce4.weline.local/pagebuilder/backend/ai-site-agent/stream-sse?public_id=stale',
            'WELINE_ORIGIN_REQUEST_URI' => '/pagebuilder/backend/ai-site-agent/stream-sse?public_id=stale',
        ];
        $_GET = [];
        $_POST = [];

        $parse = [
            'area' => 'backend',
            'uri' => '/pagebuilder/backend/preview/full?page_id=152&visual_editor=1',
            'server' => [
                'REQUEST_SCHEME' => 'https',
                'HTTP_HOST' => 'p11005ce4.weline.local',
                'REQUEST_URI' => '/pagebuilder/backend/preview/full?page_id=152&visual_editor=1',
                'QUERY_STRING' => 'page_id=152&visual_editor=1',
                'WELINE_AREA' => 'backend',
            ],
        ];

        $runtime = new WlsRuntime();
        $method = new ReflectionMethod(WlsRuntime::class, 'processUrlParse');
        $method->setAccessible(true);
        $method->invoke($runtime, $parse);

        self::assertSame(
            'https://p11005ce4.weline.local/pagebuilder/backend/preview/full?page_id=152&visual_editor=1',
            $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null
        );
        self::assertSame(
            '/pagebuilder/backend/preview/full?page_id=152&visual_editor=1',
            $_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? null
        );
    }
}
