<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Server\Socket\ListenSocketOptions;

/**
 * Master-owned POSIX listener shared with Workers through an inherited FD.
 *
 * The Master binds the selected Nginx-backend or pure-WLS address and
 * explicitly grants only FD 3 to Worker launchers. Workers compete on the
 * same accept queue and never proxy bytes through the Master.
 */
final class DirectSharedListener
{
    public const INHERITED_FD = 3;

    /** @var resource|null Startup-owned listener awaiting Master adoption. */
    private static mixed $startupListener = null;

    private static string $startupHost = '';

    private static int $startupPort = 0;

    private static string $startupLeaseId = '';

    /** @var resource|null */
    private mixed $listener = null;

    private string $host = '';

    private int $port = 0;

    /**
     * Install a listener that was bound before the Master started. The actual
     * socket endpoint is checked here, before any child can receive FD 3.
     * Ownership transfers to the first matching acquire() call.
     *
     * @param resource $listener
     */
    public static function installStartupListener(
        mixed $listener,
        string $host,
        int $port,
        string $leaseId,
    ): void {
        if (!\is_resource($listener)) {
            throw new \InvalidArgumentException('Inherited startup listener must be a stream resource.');
        }
        $host = self::normalizeLiteralHost($host);
        if ($port < 1 || $port > 65535
            || \preg_match('/\A[a-f0-9]{32}\z/D', $leaseId) !== 1
        ) {
            throw new \InvalidArgumentException('Inherited startup listener identity is invalid.');
        }
        self::assertStreamEndpoint($listener, $host, $port);
        if (!@\stream_set_blocking($listener, false)) {
            throw new \RuntimeException('Unable to configure the inherited startup listener as non-blocking.');
        }
        if (\is_resource(self::$startupListener)) {
            if (\get_resource_id(self::$startupListener) === \get_resource_id($listener)
                && self::$startupHost === $host
                && self::$startupPort === $port
                && \hash_equals(self::$startupLeaseId, $leaseId)
            ) {
                return;
            }
            throw new \RuntimeException('A different inherited startup listener is already installed.');
        }
        self::$startupListener = $listener;
        self::$startupHost = $host;
        self::$startupPort = $port;
        self::$startupLeaseId = $leaseId;
    }

    public static function discardStartupListener(): void
    {
        if (\is_resource(self::$startupListener)) {
            @\fclose(self::$startupListener);
        }
        self::$startupListener = null;
        self::$startupHost = '';
        self::$startupPort = 0;
        self::$startupLeaseId = '';
    }

    public function acquire(string $host, int $port): mixed
    {
        $host = $this->normalizeHost($host);
        if ($port <= 0 || $port > 65535) {
            throw new \InvalidArgumentException('Direct shared listener port must be between 1 and 65535.');
        }
        if ($this->isListening()) {
            if ($this->host !== $host || $this->port !== $port) {
                throw new \RuntimeException(
                    "Direct shared listener is already bound to {$this->host}:{$this->port}; requested {$host}:{$port}."
                );
            }

            return $this->listener;
        }

        if (\is_resource(self::$startupListener)) {
            if (self::$startupHost !== $host || self::$startupPort !== $port) {
                throw new \RuntimeException(
                    'Inherited startup listener endpoint does not match the requested Master listener.'
                );
            }
            self::assertStreamEndpoint(self::$startupListener, $host, $port);
            $this->listener = self::$startupListener;
            $this->host = self::$startupHost;
            $this->port = self::$startupPort;
            self::$startupListener = null;
            self::$startupHost = '';
            self::$startupPort = 0;
            self::$startupLeaseId = '';
            return $this->listener;
        }

        $addressHost = \str_contains($host, ':') && !\str_starts_with($host, '[')
            ? '[' . $host . ']'
            : $host;
        $context = \stream_context_create([
            'socket' => ListenSocketOptions::streamContextOptions([
                'backlog' => 102400,
            ]),
        ]);
        $errno = 0;
        $errstr = '';
        $listener = @\stream_socket_server(
            'tcp://' . $addressHost . ':' . $port,
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            $context,
        );
        if (!\is_resource($listener)) {
            throw new \RuntimeException(
                "Unable to create direct shared listener {$host}:{$port}: {$errstr} (errno={$errno})."
            );
        }
        if (!@\stream_set_blocking($listener, false)) {
            @\fclose($listener);
            throw new \RuntimeException('Unable to configure the direct shared listener as non-blocking.');
        }

        try {
            self::assertStreamEndpoint($listener, $host, $port);
        } catch (\Throwable $throwable) {
            @\fclose($listener);
            throw $throwable;
        }

        $this->listener = $listener;
        $this->host = $host;
        $this->port = $port;

        return $listener;
    }

    /**
     * @return array<int, resource>
     */
    public function descriptorMap(): array
    {
        if (!$this->isListening()) {
            throw new \RuntimeException('Direct shared listener is not initialized.');
        }

        return [self::INHERITED_FD => $this->listener];
    }

    public function isListening(): bool
    {
        return \is_resource($this->listener);
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function matches(string $host, int $port): bool
    {
        return $this->isListening()
            && $this->host === $this->normalizeHost($host)
            && $this->port === $port;
    }

    public function close(): void
    {
        if (\is_resource($this->listener)) {
            @\fclose($this->listener);
        }
        $this->listener = null;
        $this->host = '';
        $this->port = 0;
    }

    private function normalizeHost(string $host): string
    {
        return self::normalizeLiteralHost($host);
    }

    private static function normalizeLiteralHost(string $host): string
    {
        $host = \trim($host, " \t\n\r\0\x0B[]");
        if ($host === '' || $host === '*') {
            return '0.0.0.0';
        }
        if (\strcasecmp($host, 'localhost') === 0) {
            return '127.0.0.1';
        }
        $packed = @\inet_pton($host);
        $normalized = \is_string($packed) ? @\inet_ntop($packed) : false;
        if (!\is_string($normalized) || $normalized === '') {
            throw new \InvalidArgumentException(
                'Direct shared listener requires a resolved IPv4/IPv6 bind address; received "' . $host . '".'
            );
        }
        return \strtolower($normalized);
    }

    /** @param resource $listener */
    private static function assertStreamEndpoint(mixed $listener, string $host, int $port): void
    {
        $bound = @\stream_socket_get_name($listener, false);
        if (!\is_string($bound) || $bound === '') {
            throw new \RuntimeException('Direct shared listener endpoint could not be read.');
        }
        $separator = \strrpos($bound, ':');
        if ($separator === false) {
            throw new \RuntimeException('Direct shared listener endpoint is malformed.');
        }
        $actualHost = self::normalizeLiteralHost(\substr($bound, 0, $separator));
        $actualPort = (int)\substr($bound, $separator + 1);
        if ($actualHost !== $host || $actualPort !== $port) {
            throw new \RuntimeException(
                "Direct shared listener endpoint mismatch: expected {$host}:{$port}, got {$actualHost}:{$actualPort}."
            );
        }
        if (\function_exists('socket_import_stream')) {
            $socket = @\socket_import_stream($listener);
            if ($socket === false
                || (\defined('SO_ACCEPTCONN')
                    && @\socket_get_option($socket, \SOL_SOCKET, \SO_ACCEPTCONN) !== 1)
            ) {
                throw new \RuntimeException('Inherited startup socket is not a listening TCP socket.');
            }
        }
    }
}
