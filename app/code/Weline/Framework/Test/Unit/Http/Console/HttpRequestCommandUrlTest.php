<?php

namespace Weline\Framework\Test\Unit\Http\Console;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Console\Http\Request;

final class HttpRequestCommandUrlTest extends TestCase
{
    private mixed $originalWlsConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalWlsConfig = Env::getInstance()->getConfig('wls', []);
    }

    protected function tearDown(): void
    {
        Env::getInstance()->applyRuntimeConfig(['wls' => $this->originalWlsConfig]);
        parent::tearDown();
    }

    public function testBuildUrlFallsBackWhenWlsConfigIsString(): void
    {
        $url = $this->buildUrlWithWlsConfig('wls', 9517, false);

        $this->assertSame('http://127.0.0.1:9517/pagebuilder/backend/ai-site-agent/workspace?public_id=test&expert=1', $url);
    }

    public function testBuildUrlStillUsesArrayWlsConfig(): void
    {
        $url = $this->buildUrlWithWlsConfig([
            'host' => '127.0.0.1',
            'port' => 9518,
            'https' => false,
        ], null, null);

        $this->assertSame('http://127.0.0.1:9518/pagebuilder/backend/ai-site-agent/workspace?public_id=test&expert=1', $url);
    }

    public function testBuildUrlUsesLoopbackWhenConfiguredHostBindsAllInterfaces(): void
    {
        $url = $this->buildUrlWithWlsConfig([
            'host' => '0.0.0.0',
            'port' => 9519,
            'https' => false,
        ], null, null);

        $this->assertSame('http://127.0.0.1:9519/pagebuilder/backend/ai-site-agent/workspace?public_id=test&expert=1', $url);
    }

    private function buildUrlWithWlsConfig(mixed $wlsConfig, ?int $overridePort, ?bool $overrideHttps): string
    {
        Env::getInstance()->applyRuntimeConfig(['wls' => $wlsConfig]);

        $command = new Request();
        $method = new ReflectionMethod(Request::class, 'buildUrl');
        $method->setAccessible(true);

        return $method->invoke(
            $command,
            'pagebuilder/backend/ai-site-agent/workspace?public_id=test&expert=1',
            false,
            false,
            $overridePort,
            $overrideHttps
        );
    }
}
