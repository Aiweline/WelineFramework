<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;

class DispatcherHttpsRedirectHostPortTest extends TestCase
{
    public function testHostWithPortUsesHostPort(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $resolver = new \ReflectionMethod(Dispatcher::class, 'resolveHttpsRedirectHostAndPort');
        $resolver->setAccessible(true);

        $raw = "GET /hello HTTP/1.1\r\nHost: www.yonocashgames.com:10001\r\n\r\n";
        $result = $resolver->invoke($dispatcher, $raw);

        self::assertSame('www.yonocashgames.com', $result['host']);
        self::assertSame(10001, $result['port']);
    }

    public function testHostWithoutPortDefaultsTo443(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $resolver = new \ReflectionMethod(Dispatcher::class, 'resolveHttpsRedirectHostAndPort');
        $resolver->setAccessible(true);

        $raw = "GET /hello HTTP/1.1\r\nHost: www.yonocashgames.com\r\n\r\n";
        $result = $resolver->invoke($dispatcher, $raw);

        self::assertSame('www.yonocashgames.com', $result['host']);
        self::assertSame(443, $result['port']);
    }

    public function testPlainHttpPrefixDetectionAcceptsHttpMethods(): void
    {
        $checker = new \ReflectionMethod(Dispatcher::class, 'isPlainHttpRequestPrefix');
        $checker->setAccessible(true);
        $dispatcher = $this->newDispatcherWithoutConstructor();

        self::assertTrue((bool)$checker->invoke($dispatcher, "GET /de"));
        self::assertTrue((bool)$checker->invoke($dispatcher, "POST /d"));
        self::assertTrue((bool)$checker->invoke($dispatcher, "OPTIONS "));
    }

    public function testPlainHttpPrefixDetectionRejectsTlsAndEmptyPeek(): void
    {
        $checker = new \ReflectionMethod(Dispatcher::class, 'isPlainHttpRequestPrefix');
        $checker->setAccessible(true);
        $dispatcher = $this->newDispatcherWithoutConstructor();

        self::assertFalse((bool)$checker->invoke($dispatcher, "\x16\x03\x01\x02"));
        self::assertFalse((bool)$checker->invoke($dispatcher, ''));
    }

    private function newDispatcherWithoutConstructor(): Dispatcher
    {
        $reflector = new \ReflectionClass(Dispatcher::class);
        /** @var Dispatcher $dispatcher */
        $dispatcher = $reflector->newInstanceWithoutConstructor();
        return $dispatcher;
    }
}
