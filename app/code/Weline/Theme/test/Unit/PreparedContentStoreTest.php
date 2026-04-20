<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\PreparedContentStore;

final class PreparedContentStoreTest extends TestCase
{
    private ?Context $previousContext = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContext = Context::getCurrent();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        PreparedContentStore::resetRequestState();
    }

    protected function tearDown(): void
    {
        PreparedContentStore::resetRequestState();
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        if ($this->previousContext !== null) {
            Context::enter($this->previousContext);
        }
        parent::tearDown();
    }

    public function testWlsScopeUsesConnectionIdBuckets(): void
    {
        $contextA = new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]);
        Context::enter($contextA);
        RequestContext::setId('request-a');
        RequestContext::setConnectionId('conn-101');
        $keyA = PreparedContentStore::put('<section>A</section>');
        self::assertTrue(PreparedContentStore::has($keyA));

        Context::leave();

        $contextB = new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]);
        Context::enter($contextB);
        RequestContext::setId('request-a');
        RequestContext::setConnectionId('conn-202');
        $keyB = PreparedContentStore::put('<section>B</section>');
        self::assertTrue(PreparedContentStore::has($keyB));
        PreparedContentStore::resetRequestState();
        self::assertFalse(PreparedContentStore::has($keyB));

        Context::leave();

        Context::enter($contextA);
        self::assertTrue(PreparedContentStore::has($keyA));
        self::assertSame('<section>A</section>', PreparedContentStore::get($keyA));
    }

    public function testFpmScopeFallsBackToRequestId(): void
    {
        $contextA = new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]);
        Context::enter($contextA);
        RequestContext::setId('request-fpm-a');
        RequestContext::setConnectionId('request-fpm-a');
        $keyA = PreparedContentStore::put('<section>A</section>');
        self::assertTrue(PreparedContentStore::has($keyA));

        Context::leave();

        $contextB = new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]);
        Context::enter($contextB);
        RequestContext::setId('request-fpm-b');
        RequestContext::setConnectionId('request-fpm-b');
        $keyB = PreparedContentStore::put('<section>B</section>');
        self::assertTrue(PreparedContentStore::has($keyB));
        PreparedContentStore::resetRequestState();
        self::assertFalse(PreparedContentStore::has($keyB));

        Context::leave();

        Context::enter($contextA);
        self::assertTrue(PreparedContentStore::has($keyA));
        self::assertSame('<section>A</section>', PreparedContentStore::get($keyA));
    }

    public function testFallbackStoreStillWorksWithoutRequestContext(): void
    {
        $key = PreparedContentStore::put('<section>fallback</section>');

        self::assertTrue(PreparedContentStore::has($key));
        self::assertSame('<section>fallback</section>', PreparedContentStore::get($key));

        PreparedContentStore::resetRequestState();

        self::assertFalse(PreparedContentStore::has($key));
        self::assertSame('missing', PreparedContentStore::get($key, 'missing'));
    }

    public function testResolveLayoutContentSkipsEmptyMetaContentString(): void
    {
        $called = 0;
        $html = PreparedContentStore::resolveLayoutContent(
            null,
            null,
            ['content' => ''],
            null,
            'Weline_Queue::templates/Backend/Queue/content.phtml',
            static function (string $contentTemplate) use (&$called): string {
                $called++;
                self::assertNotSame('', $contentTemplate);

                return '<main>rendered</main>';
            }
        );
        self::assertSame('<main>rendered</main>', $html);
        self::assertSame(1, $called);
    }
}
