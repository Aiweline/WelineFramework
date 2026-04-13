<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Runtime\RequestContext;

final class AiSiteAgentSseMarkerTest extends TestCase
{
    private const AI_CHUNK_FORWARDER_KEY = 'pagebuilder.ai.chunk.forwarder';

    protected function tearDown(): void
    {
        RequestContext::remove(RequestContext::SSE_WRITER_KEY);
        RequestContext::remove(self::AI_CHUNK_FORWARDER_KEY);
        RequestContext::setId(null);
        parent::tearDown();
    }

    public function testClearAiChunkForwarderKeepsSseHandledMarker(): void
    {
        RequestContext::set(RequestContext::SSE_WRITER_KEY, new \stdClass());
        RequestContext::set(self::AI_CHUNK_FORWARDER_KEY, static function (): void {
        });

        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'clearAiChunkForwarder');
        $method->setAccessible(true);
        $method->invoke($controller);

        self::assertTrue((bool)RequestContext::get(RequestContext::SSE_WRITER_KEY, false));
        self::assertFalse(RequestContext::has(self::AI_CHUNK_FORWARDER_KEY));
    }
}
