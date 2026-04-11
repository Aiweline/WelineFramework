<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request\ServerBag;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class ServerBagMemorySnapshotTest extends TestCase
{
    private array $originalServer = [];

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();
        $_SERVER = [
            'REQUEST_URI' => '/stale-globals',
            'HTTP_HOST' => 'stale.test',
            'SCRIPT_NAME' => '/index.php',
        ];
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();
        Runtime::resetModeCache();
        $_SERVER = $this->originalServer;
    }

    public function testInitFromGlobalsUsesCurrentMemorySnapshotInWlsMode(): void
    {
        RequestContext::init();
        WelineEnv::getInstance()->initFromSnapshot(
            [],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/fresh-memory',
                'HTTP_HOST' => 'fresh.test',
                'REQUEST_METHOD' => 'POST',
                'WELINE_AREA' => 'backend',
                'WELINE_WEBSITE_URL' => 'https://fresh.test',
            ]
        );

        $serverBag = (new ServerBag())->initFromGlobals();

        self::assertSame('/fresh-memory', $serverBag->getRequestUri());
        self::assertSame('fresh.test', $serverBag->getHost());
        self::assertSame('POST', $serverBag->getMethod());
        self::assertSame('backend', $serverBag->get('WELINE_AREA'));
        self::assertSame('https://fresh.test', $serverBag->get('WELINE_WEBSITE_URL'));
        self::assertSame('/index.php', $serverBag->get('SCRIPT_NAME'));
    }
}
