<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

class StartWindowsProxyBypassDetectionTest extends TestCase
{
    public function testWelineWildcardBypassRuleMatchesSubdomain(): void
    {
        $start = new Start();
        $start->__init();

        self::assertTrue($this->invokeMethod($start, 'hostMatchesWindowsProxyOverride', [
            'p11005ce4.weline.test',
            'localhost;127.*;*.weline.test;weline.test',
        ]));
    }

    public function testProxyInterceptionRiskDetectedWhenHostNotBypassed(): void
    {
        $start = new Start();
        $start->__init();

        self::assertTrue($this->invokeMethod($start, 'isWindowsProxyLikelyToInterceptHost', [
            'p11005ce4.weline.test',
            [
                'proxy_enabled' => true,
                'proxy_server' => '127.0.0.1:7897',
                'proxy_override' => 'localhost;127.*;10.*',
            ],
        ]));
    }

    public function testSuggestedBypassRuleCoversWelineTestDomains(): void
    {
        $start = new Start();
        $start->__init();

        self::assertSame('*.weline.test;weline.test', $this->invokeMethod($start, 'buildSuggestedWindowsProxyBypassRule', [
            'p11005ce4.weline.test',
        ]));
    }

    private function invokeMethod(object $object, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($object, $args);
    }
}
