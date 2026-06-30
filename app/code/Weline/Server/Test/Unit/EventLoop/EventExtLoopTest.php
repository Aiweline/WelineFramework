<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\EventLoop;

use PHPUnit\Framework\TestCase;
use Weline\Server\EventLoop\EventExtLoop;

final class EventExtLoopTest extends TestCase
{
    public function testBackendIsEventWhenExtensionLoaded(): void
    {
        if (!\extension_loaded('event')) {
            $this->markTestSkipped('event extension is not loaded');
        }

        $loop = new EventExtLoop();
        self::assertSame('event', $loop->backend());
    }

    public function testWaitReturnsZeroOnTimeoutWithoutWatchers(): void
    {
        if (!\extension_loaded('event')) {
            $this->markTestSkipped('event extension is not loaded');
        }

        $loop = new EventExtLoop();
        $read = [];
        $write = [];
        $except = [];
        $changed = $loop->wait($read, $write, $except, 0, 1000);

        self::assertSame(0, $changed);
        self::assertSame([], $read);
        self::assertSame([], $write);
        self::assertSame([], $except);
    }

    public function testWaitDoesNotReportReadReadinessOnTimeout(): void
    {
        if (!\extension_loaded('event')) {
            $this->markTestSkipped('event extension is not loaded');
        }

        [$client, $server] = $this->createConnectedStreamPair();
        try {
            $loop = new EventExtLoop();
            $read = [$server];
            $write = [];
            $except = [];
            $changed = $loop->wait($read, $write, $except, 0, 1000);

            self::assertSame(0, $changed);
            self::assertSame([], $read);
            self::assertSame([], $write);
            self::assertSame([], $except);
        } finally {
            \fclose($client);
            \fclose($server);
        }
    }

    public function testWaitReportsRealReadReadiness(): void
    {
        if (!\extension_loaded('event')) {
            $this->markTestSkipped('event extension is not loaded');
        }

        [$client, $server] = $this->createConnectedStreamPair();
        try {
            self::assertSame(1, \fwrite($client, 'x'));

            $loop = new EventExtLoop();
            $read = [$server];
            $write = [];
            $except = [];
            $changed = $loop->wait($read, $write, $except, 0, 100000);

            self::assertSame(1, $changed);
            self::assertSame([$server], $read);
            self::assertSame([], $write);
            self::assertSame([], $except);
            self::assertSame('x', \fread($server, 1));
        } finally {
            \fclose($client);
            \fclose($server);
        }
    }

    public function testWaitReportsRealWriteReadiness(): void
    {
        if (!\extension_loaded('event')) {
            $this->markTestSkipped('event extension is not loaded');
        }

        [$client, $server] = $this->createConnectedStreamPair();
        try {
            $loop = new EventExtLoop();
            $read = [];
            $write = [$client];
            $except = [];
            $changed = $loop->wait($read, $write, $except, 0, 100000);

            self::assertSame(1, $changed);
            self::assertSame([], $read);
            self::assertSame([$client], $write);
            self::assertSame([], $except);
        } finally {
            \fclose($client);
            \fclose($server);
        }
    }

    public function testWatchersAreRemovedWhenResourceSetChanges(): void
    {
        if (!\extension_loaded('event')) {
            $this->markTestSkipped('event extension is not loaded');
        }

        [$client, $server] = $this->createConnectedStreamPair();
        try {
            $loop = new EventExtLoop();
            $read = [$server];
            $write = [];
            $except = [];
            $loop->wait($read, $write, $except, 0, 1000);

            $read = [];
            $write = [];
            $except = [];
            $loop->wait($read, $write, $except, 0, 0);

            $reflection = new \ReflectionClass($loop);
            $readWatchers = $reflection->getProperty('readWatchers');
            $writeWatchers = $reflection->getProperty('writeWatchers');

            self::assertSame([], $readWatchers->getValue($loop));
            self::assertSame([], $writeWatchers->getValue($loop));
        } finally {
            \fclose($client);
            \fclose($server);
        }
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function createConnectedStreamPair(): array
    {
        $serverSocket = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($serverSocket, "stream_socket_server failed: {$errno} {$errstr}");

        $address = \stream_socket_get_name($serverSocket, false);
        self::assertIsString($address);

        $client = @\stream_socket_client('tcp://' . $address, $clientErrno, $clientErrstr, 1.0);
        self::assertIsResource($client, "stream_socket_client failed: {$clientErrno} {$clientErrstr}");

        $accepted = @\stream_socket_accept($serverSocket, 1.0);
        \fclose($serverSocket);
        self::assertIsResource($accepted, 'stream_socket_accept failed');

        \stream_set_blocking($client, false);
        \stream_set_blocking($accepted, false);

        return [$client, $accepted];
    }
}
