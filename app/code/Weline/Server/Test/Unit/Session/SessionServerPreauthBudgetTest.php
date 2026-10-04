<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionServer;

final class SessionServerPreauthBudgetTest extends TestCase
{
    private array $sockets = [];
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            foreach ($this->clients($server) as $client) {
                if (is_resource($client['socket'] ?? null)) {
                    fclose($client['socket']);
                }
            }
            (new \ReflectionProperty($server, 'clients'))->setValue($server, []);
        }
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    public function testOversizedUnterminatedAuthClosesAndReleasesBuffer(): void
    {
        [$server, $socket, $peer] = $this->connectedServer();
        fwrite($peer, str_repeat('x', 4097));
        $this->read($server, $socket);
        self::assertArrayNotHasKey((int)$socket, $this->clients($server));
    }

    public function testInvalidFirstLineCannotBeSkippedToAuthenticateLater(): void
    {
        [$server, $socket, $peer] = $this->connectedServer();
        fwrite($peer, "invalid\n" . $this->authFrame($server));
        $this->read($server, $socket);
        self::assertArrayNotHasKey((int)$socket, $this->clients($server));
    }

    public function testAuthAt4096BytesRemainsValid(): void
    {
        [$server, $socket, $peer] = $this->connectedServer();
        $auth = $this->authFrame($server);
        $auth = substr($auth, 0, -1) . str_repeat(' ', 4096 - strlen($auth)) . "\n";
        self::assertSame(4096, fwrite($peer, $auth));
        $this->read($server, $socket);
        self::assertTrue($this->clients($server)[(int)$socket]['authenticated']);
    }

    public function testOversizedCompleteAuthClosesBeforeAuthentication(): void
    {
        [$server,$socket,$peer] = $this->connectedServer();
        $auth = $this->authFrame($server);
        $auth = substr($auth, 0, -1) . str_repeat(' ', 4097 - strlen($auth)) . "\n";
        self::assertSame(4097, fwrite($peer, $auth));
        $id = (int)$socket;
        $this->read($server, $socket);
        self::assertArrayNotHasKey($id, $this->clients($server));
    }

    public function testAuthAndLargeBusinessCoalescedRemainValid(): void
    {
        [$server, $socket, $peer] = $this->connectedServer();
        $packet = $this->authFrame($server) . json_encode(['cmd' => 'set', 'sid' => 'preauth-unit', 'key' => 'large', 'val' => str_repeat('z', 5000)]) . "\n";
        self::assertSame(strlen($packet), fwrite($peer, $packet));
        $this->read($server, $socket);
        $read = [$socket];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 0, 1000) > 0) {
            $this->read($server, $socket);
        }
        self::assertSame(str_repeat('z', 5000), $server->getStore()->get('preauth-unit', 'large'));
    }

    public function testDeadlineExpiresEvenWithDripInput(): void
    {
        [$server, $socket, $peer] = $this->connectedServer(['preauth_timeout_sec' => 0.01]);
        fwrite($peer, 'x');
        $this->read($server, $socket);
        usleep(20000);
        (new \ReflectionMethod($server, 'doMaintenance'))->invoke($server);
        self::assertArrayNotHasKey((int)$socket, $this->clients($server));
    }

    public function testIdleUnauthenticatedClientExpiresAndAuthenticatedClientDoesNot(): void
    {
        [$idle,$socket] = $this->connectedServer(['preauth_timeout_sec' => 0.01]);
        [$authenticated,$authSocket,$peer] = $this->connectedServer(['preauth_timeout_sec' => 0.01]);
        fwrite($peer, $this->authFrame($authenticated));
        $this->read($authenticated, $authSocket);
        $idleId = (int)$socket;
        $authId = (int)$authSocket;
        usleep(20000);
        foreach ([$idle,$authenticated] as $server) {
            (new \ReflectionMethod($server, 'doMaintenance'))->invoke($server);
        }
        self::assertArrayNotHasKey($idleId, $this->clients($idle));
        self::assertArrayHasKey($authId, $this->clients($authenticated));
    }

    public function testDisabledAuthKeepsOrdinaryReadBudget(): void
    {
        [$server, $socket, $peer] = $this->connectedServer(['auth_enabled' => false]);
        fwrite($peer, str_repeat('x', 4097));
        $this->read($server, $socket);
        self::assertSame(4097, strlen($this->clients($server)[(int)$socket]['buffer']));
    }

    public function testAcceptCapAndDisconnectReleaseSlot(): void
    {
        $server = $this->server();
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($listener);
        $this->sockets[] = $listener;
        (new \ReflectionProperty($server, 'serverSocket'))->setValue($server, $listener);
        $endpoint = stream_socket_get_name($listener, false);
        $accept = new \ReflectionMethod($server, 'acceptConnection');
        for ($i = 0; $i < 65; ++$i) {
            $peer = stream_socket_client('tcp://' . $endpoint, $errno, $error, 1);
            self::assertIsResource($peer);
            $this->sockets[] = $peer;
            self::assertTrue($accept->invoke($server));
        }
        self::assertCount(64, $this->clients($server));
        (new \ReflectionMethod($server, 'disconnectClient'))->invoke($server, array_key_first($this->clients($server)));
        $peer = stream_socket_client('tcp://' . $endpoint, $errno, $error, 1);
        $this->sockets[] = $peer;
        self::assertTrue($accept->invoke($server));
        self::assertCount(64, $this->clients($server));
        foreach ($this->clients($server) as $client) {
            $this->sockets[] = $client['socket'];
        }
    }

    public function testAuthenticationAndInvalidSocketReleaseAcceptSlots(): void
    {
        [$server,$socket,$peer] = $this->connectedServer(['preauth_max_connections' => 1]);
        fwrite($peer, $this->authFrame($server));
        $this->read($server, $socket);
        $listener = (new \ReflectionProperty($server, 'serverSocket'))->getValue($server);
        $connect = fn () => stream_socket_client('tcp://' . stream_socket_get_name($listener, false), $errno, $error, 1);
        $next = $connect();
        $this->sockets[] = $next;
        $accept = new \ReflectionMethod($server, 'acceptConnection');
        self::assertTrue($accept->invoke($server));
        self::assertCount(2, $this->clients($server));
        $ids = array_keys($this->clients($server));
        $unauthId = $ids[1];
        fclose($this->clients($server)[$unauthId]['socket']);
        (new \ReflectionMethod($server, 'handleClientRead'))->invoke($server, $unauthId);
        $next = $connect();
        $this->sockets[] = $next;
        self::assertTrue($accept->invoke($server));
        self::assertCount(2, $this->clients($server));
        foreach ($this->clients($server) as $client) {
            $this->sockets[] = $client['socket'];
        }
    }

    private function server(array $config = []): SessionServer
    {
        $server = new SessionServer($config + ['port' => 0,'persist_enabled' => false,'auth_enabled' => true,
            'token_file_name' => 'preauth-unit-' . getmypid() . '.token',
            'memory_high_watermark_bytes' => PHP_INT_MAX,'memory_low_watermark_bytes' => PHP_INT_MAX - 1]);
        $this->servers[] = $server;
        return $server;
    }

    private function connectedServer(array $config = []): array
    {
        $server = $this->server($config);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($listener);
        $this->sockets[] = $listener;
        (new \ReflectionProperty($server, 'serverSocket'))->setValue($server, $listener);
        $peer = stream_socket_client('tcp://' . stream_socket_get_name($listener, false), $errno, $error, 1);
        self::assertIsResource($peer);
        self::assertTrue((new \ReflectionMethod($server, 'acceptConnection'))->invoke($server));
        $socket = $this->clients($server)[array_key_first($this->clients($server))]['socket'];
        stream_set_blocking($peer, false);
        $this->sockets[] = $socket;
        $this->sockets[] = $peer;
        return [$server,$socket,$peer];
    }

    private function clients(SessionServer $server): array
    {
        return (new \ReflectionProperty($server, 'clients'))->getValue($server);
    }

    private function read(SessionServer $server, $socket): void
    {
        $read = [$socket];
        $write = null;
        $except = null;
        self::assertGreaterThan(0, stream_select($read, $write, $except, 1));
        (new \ReflectionMethod($server, 'handleClientRead'))->invoke($server, (int)$socket);
    }

    private function authFrame(SessionServer $server): string
    {
        return json_encode(['cmd' => 'auth','token' => $server->getAuthToken()]) . "\n";
    }
}
