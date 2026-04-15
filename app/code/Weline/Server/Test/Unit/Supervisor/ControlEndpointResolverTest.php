<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Supervisor;

use PHPUnit\Framework\TestCase;
use Weline\Server\Supervisor\Endpoint\ControlEndpoint;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;

final class ControlEndpointResolverTest extends TestCase
{
    public function testLinuxLikeSystemsUseStableUnixDomainSocketPath(): void
    {
        $resolver = new ControlEndpointResolver('/srv/weline');

        $endpoint = $resolver->resolve('default', 'Linux');

        self::assertTrue($endpoint->isUnix());
        self::assertSame(
            '/srv/weline' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR
            . 'run' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'supervisor.sock',
            $endpoint->address
        );
        self::assertSame('unix://' . $endpoint->address, $endpoint->uri());
    }

    public function testUnsafeInstanceNameIsSanitizedForSocketPath(): void
    {
        $resolver = new ControlEndpointResolver('/srv/weline/');

        $endpoint = $resolver->resolve('../my instance', 'Darwin');

        self::assertTrue($endpoint->isUnix());
        self::assertStringEndsWith(
            DIRECTORY_SEPARATOR . 'my-instance' . DIRECTORY_SEPARATOR . 'supervisor.sock',
            $endpoint->address
        );
    }

    public function testWindowsUsesStableLoopbackTcpPort(): void
    {
        $resolver = new ControlEndpointResolver('C:\\weline', 27000, 1000);

        $first = $resolver->resolve('default', 'Windows');
        $second = $resolver->resolve('default', 'Windows');
        $other = $resolver->resolve('other-instance', 'Windows');

        self::assertTrue($first->isTcp());
        self::assertSame('127.0.0.1', $first->host());
        self::assertSame($first->port(), $second->port());
        self::assertGreaterThanOrEqual(27000, $first->port());
        self::assertLessThan(28000, $first->port());
        self::assertNotSame($first->port(), $other->port());
        self::assertSame('tcp://' . $first->address, $first->uri());
    }

    public function testControlEndpointTcpFactoryExposesHostAndPort(): void
    {
        $endpoint = ControlEndpoint::tcp('127.0.0.1', 27777);

        self::assertTrue($endpoint->isTcp());
        self::assertSame('127.0.0.1', $endpoint->host());
        self::assertSame(27777, $endpoint->port());
        self::assertSame('tcp://127.0.0.1:27777', $endpoint->uri());
    }
}
