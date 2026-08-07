<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Connection;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Session\Server\SharedStateTokenStore;
use Weline\Server\Shared\Connection\PooledConnection;

final class PooledConnectionTokenRotationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'wls-pooled-token-rotation-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(@\mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && \is_dir($this->directory)) {
            foreach ((array)\scandir($this->directory) as $leaf) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                $path = $this->directory . DIRECTORY_SEPARATOR . $leaf;
                if (\is_dir($path) && !\is_link($path)) {
                    @\rmdir($path);
                } else {
                    @\unlink($path);
                }
            }
            @\rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testTokenRotationReconnectsBeforeRetryingAuthentication(): void
    {
        if (!\function_exists('pcntl_fork') || \PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The token-rotation transport fixture requires pcntl_fork.');
        }
        $tokenPath = $this->directory . DIRECTORY_SEPARATOR . 'session_25422.token';
        $oldSecret = \str_repeat('a', 64);
        $newSecret = \str_repeat('b', 64);

        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server, $error . ' (' . $errno . ')');
        $address = (string)\stream_socket_get_name($server, false);
        $port = (int)\substr($address, (int)\strrpos($address, ':') + 1);
        self::assertGreaterThan(0, $port);
        $authority = $this->authority($port);
        (new SharedStateTokenStore($tokenPath, 0.25, $authority))->publish($oldSecret, 1);

        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $exitCode = $this->serveRotatedAuthentication(
                $server,
                $tokenPath,
                $oldSecret,
                $newSecret,
                $authority,
            );
            @\fclose($server);
            exit($exitCode);
        }

        @\fclose($server);
        $connection = new PooledConnection(
            '127.0.0.1',
            $port,
            2.0,
            1.5,
            $tokenPath,
            false,
            'TokenRotationTest',
            false,
        );

        try {
            self::assertTrue(
                $connection->connect(),
                'A fresh token must be retried on a fresh transport, not the server-closed socket.',
            );
        } finally {
            $connection->close();
            $status = 0;
            $deadline = \hrtime(true) + 3_000_000_000;
            do {
                $waited = \pcntl_waitpid($pid, $status, \WNOHANG);
                if ($waited === $pid || $waited === -1) {
                    break;
                }
                \usleep(10_000);
            } while (\hrtime(true) < $deadline);
            if ($waited !== $pid) {
                @\posix_kill($pid, \SIGKILL);
                \pcntl_waitpid($pid, $status);
            }
        }

        self::assertTrue(\pcntl_wifexited($status));
        self::assertSame(0, \pcntl_wexitstatus($status));
    }

    public function testConfiguredMissingTokenFileFailsClosed(): void
    {
        $missingTokenPath = $this->directory . DIRECTORY_SEPARATOR
            . 'missing-session.token';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server, $error . ' (' . $errno . ')');
        $address = (string)\stream_socket_get_name($server, false);
        $port = (int)\substr($address, (int)\strrpos($address, ':') + 1);
        self::assertGreaterThan(0, $port);

        $connection = new PooledConnection(
            '127.0.0.1',
            $port,
            1.0,
            0.5,
            $missingTokenPath,
            false,
            'MissingTokenTest',
            false,
        );

        try {
            self::assertFalse(
                $connection->connect(),
                'A configured but unavailable capability file must never be interpreted as auth disabled.',
            );
        } finally {
            $connection->close();
            @\fclose($server);
        }
    }

    public function testObservedHigherGenerationRejectsLaterLowerGeneration(): void
    {
        $tokenPath = $this->directory . DIRECTORY_SEPARATOR . 'session_25423.token';
        $authority = $this->authority(25423);
        $highSecret = \str_repeat('c', 64);
        (new SharedStateTokenStore($tokenPath, 0.25, $authority))->publish($highSecret, 10);
        $connection = $this->connectionForTokenLoad(25423, $tokenPath);

        self::assertSame($highSecret, $this->loadToken($connection, true));
        $this->rewriteTokenEnvelope($tokenPath, 9, \str_repeat('d', 64));

        self::assertNull(
            $this->loadToken($connection, true),
            'A client that observed generation 10 must fail closed on generation 9.',
        );
    }

    public function testObservedGenerationRejectsDifferentSecretAtSameGeneration(): void
    {
        $tokenPath = $this->directory . DIRECTORY_SEPARATOR . 'session_25424.token';
        $authority = $this->authority(25424);
        $firstSecret = \str_repeat('e', 64);
        (new SharedStateTokenStore($tokenPath, 0.25, $authority))->publish($firstSecret, 20);
        $connection = $this->connectionForTokenLoad(25424, $tokenPath);

        self::assertSame($firstSecret, $this->loadToken($connection, true));
        $this->rewriteTokenEnvelope($tokenPath, 20, \str_repeat('f', 64));

        self::assertNull(
            $this->loadToken($connection, true),
            'A same-generation secret fork must never replace an observed capability.',
        );
    }

    public function testHigherGenerationRotationIsAcceptedAfterObservedGeneration(): void
    {
        $tokenPath = $this->directory . DIRECTORY_SEPARATOR . 'session_25425.token';
        $authority = $this->authority(25425);
        $firstSecret = \str_repeat('1', 64);
        $nextSecret = \str_repeat('2', 64);
        (new SharedStateTokenStore($tokenPath, 0.25, $authority))->publish($firstSecret, 30);
        $connection = $this->connectionForTokenLoad(25425, $tokenPath);

        self::assertSame($firstSecret, $this->loadToken($connection, true));
        $this->rewriteTokenEnvelope($tokenPath, 31, $nextSecret);

        self::assertSame($nextSecret, $this->loadToken($connection, true));
    }

    public function testObservedTombstoneRejectsSameGenerationReactivation(): void
    {
        $tokenPath = $this->directory . DIRECTORY_SEPARATOR . 'session_25428.token';
        $authority = $this->authority(25428);
        $secret = \str_repeat('4', 64);
        $store = new SharedStateTokenStore($tokenPath, 0.25, $authority);
        $store->publish($secret, 50);
        $connection = $this->connectionForTokenLoad(25428, $tokenPath);

        self::assertSame($secret, $this->loadToken($connection, true));
        self::assertTrue($store->removeIfMatches($secret, 50));
        self::assertNull($this->loadToken($connection, true));
        $this->rewriteTokenEnvelope($tokenPath, 51, \str_repeat('5', 64));

        self::assertNull(
            $this->loadToken($connection, true),
            'An observed tombstone generation cannot be reactivated with another digest.',
        );
    }

    public function testTokenAuthorityMustMatchRequestedEndpoint(): void
    {
        $tokenPath = $this->directory . DIRECTORY_SEPARATOR . 'session_25426.token';
        $secret = \str_repeat('3', 64);
        (new SharedStateTokenStore(
            $tokenPath,
            0.25,
            $this->authority(25427),
        ))->publish($secret, 40);

        self::assertNull(
            $this->loadToken($this->connectionForTokenLoad(25426, $tokenPath), true),
            'A capability bound to another endpoint must fail closed.',
        );
    }

    public function testCachedTokenFailsClosedAfterCapabilityFileDisappears(): void
    {
        $tokenPath = $this->directory . DIRECTORY_SEPARATOR . 'session_25429.token';
        $authority = $this->authority(25429);
        $secret = \str_repeat('6', 64);
        (new SharedStateTokenStore($tokenPath, 0.25, $authority))->publish($secret, 60);
        $connection = $this->connectionForTokenLoad(25429, $tokenPath);

        self::assertSame($secret, $this->loadToken($connection, false));
        self::assertTrue(\unlink($tokenPath));

        self::assertNull(
            $this->loadToken($connection, false),
            'A cached secret must be retired as soon as its capability path disappears.',
        );
    }

    public function testSameSecondHigherGenerationIsObservedWithoutForcedReload(): void
    {
        $tokenPath = $this->directory . DIRECTORY_SEPARATOR . 'session_25430.token';
        $authority = $this->authority(25430);
        $firstSecret = \str_repeat('7', 64);
        $nextSecret = \str_repeat('8', 64);
        (new SharedStateTokenStore($tokenPath, 0.25, $authority))->publish($firstSecret, 70);
        $connection = $this->connectionForTokenLoad(25430, $tokenPath);

        self::assertSame($firstSecret, $this->loadToken($connection, false));
        $originalMtime = (int)\filemtime($tokenPath);
        $this->rewriteTokenEnvelope($tokenPath, 71, $nextSecret);
        self::assertTrue(\touch($tokenPath, $originalMtime));
        \clearstatcache(true, $tokenPath);

        self::assertSame(
            $nextSecret,
            $this->loadToken($connection, false),
            'Second-resolution mtime equality must not hide a capability generation change.',
        );
    }

    /** @param resource $server */
    private function serveRotatedAuthentication(
        $server,
        string $tokenPath,
        string $oldSecret,
        string $newSecret,
        array $authority,
    ): int {
        @\stream_set_blocking($server, true);
        $first = @\stream_socket_accept($server, 2.0);
        if (!\is_resource($first)) {
            return 10;
        }
        $firstFrame = $this->readFrame($first);
        if (($firstFrame['cmd'] ?? null) !== SessionProtocol::CMD_AUTH
            || !\hash_equals($oldSecret, (string)($firstFrame['token'] ?? ''))
        ) {
            @\fclose($first);
            return 11;
        }

        (new SharedStateTokenStore($tokenPath, 0.25, $authority))->publish($newSecret, 2);
        @\fwrite($first, SessionProtocol::encodeError('Invalid token', 'AUTH_FAILED'));
        @\fclose($first);

        $second = @\stream_socket_accept($server, 2.0);
        if (!\is_resource($second)) {
            return 12;
        }
        $secondFrame = $this->readFrame($second);
        if (($secondFrame['cmd'] ?? null) !== SessionProtocol::CMD_AUTH
            || !\hash_equals($newSecret, (string)($secondFrame['token'] ?? ''))
        ) {
            @\fclose($second);
            return 13;
        }
        @\fwrite($second, SessionProtocol::encodeSuccess('Authenticated'));
        @\fclose($second);

        return 0;
    }

    /** @param resource $stream @return array<string,mixed> */
    private function readFrame($stream): array
    {
        @\stream_set_timeout($stream, 2);
        $buffer = '';
        while (!\feof($stream) && \strlen($buffer) <= SessionProtocol::MAX_BUFFER_BYTES) {
            $chunk = @\fread($stream, 65536);
            if (!\is_string($chunk) || $chunk === '') {
                $meta = \stream_get_meta_data($stream);
                if ($meta['timed_out'] === true) {
                    break;
                }
                \usleep(1_000);
                continue;
            }
            $buffer .= $chunk;
            $messages = SessionProtocol::extractMessages($buffer);
            if ($messages !== []) {
                return \is_array($messages[0]) ? $messages[0] : [];
            }
        }

        return [];
    }

    /** @return array{role:string,host:string,port:int,instance:string} */
    private function authority(int $port): array
    {
        return [
            'role' => 'session_server',
            'host' => '127.0.0.1',
            'port' => $port,
            'instance' => 'session_server@loopback:' . $port,
        ];
    }

    private function connectionForTokenLoad(int $port, string $tokenPath): PooledConnection
    {
        return new PooledConnection(
            '127.0.0.1',
            $port,
            1.0,
            0.5,
            $tokenPath,
            false,
            'session_server',
            false,
        );
    }

    private function loadToken(PooledConnection $connection, bool $force): ?string
    {
        $method = new \ReflectionMethod($connection, 'loadToken');

        return $method->invoke($connection, $force);
    }

    private function rewriteTokenEnvelope(string $path, int $generation, string $secret): void
    {
        $document = \json_decode((string)\file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $document['generation'] = $generation;
        $document['state'] = 'active';
        $document['secret'] = $secret;
        $base = [
            'schema' => 'wls-shared-state-token/2',
            'state' => 'active',
            'generation' => $generation,
            'authority' => $document['authority'],
            'secret' => $secret,
        ];
        $document['digest'] = \hash('sha256', $this->canonicalJson($base));
        $encoded = $this->canonicalJson($document);
        self::assertSame(\strlen($encoded), \file_put_contents($path, $encoded));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($path, 0600));
        }
        \clearstatcache(true, $path);
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!\is_array($item)) {
                return $item;
            }
            if (!\array_is_list($item)) {
                \ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }

            return $item;
        };

        return \json_encode(
            $normalize($value),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        );
    }
}
