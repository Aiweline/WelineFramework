<?php
declare(strict_types=1);

namespace Weline\UrlManager\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\RequestContext;
use Weline\UrlManager\Model\UrlRewrite;

final class UrlRewriteCurrentWebsiteIdTest extends TestCase
{
    private array $originalServer = [];

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $_SERVER = [];
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();
        Context::leave();
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();
        Context::leave();
        $_SERVER = $this->originalServer;
    }

    public function testReadsWebsiteIdFromCurrentContextFirst(): void
    {
        Context::enter(new Context([
            'route' => [
                'website_id' => 7,
            ],
        ]));

        $_SERVER['WELINE_WEBSITE_ID'] = '0';

        self::assertSame(7, UrlRewrite::getCurrentWebsiteId());
    }

    public function testFallsBackToServerWhenNoContextExists(): void
    {
        $_SERVER['WELINE_WEBSITE_ID'] = '9';

        self::assertSame(9, UrlRewrite::getCurrentWebsiteId());
    }
}
