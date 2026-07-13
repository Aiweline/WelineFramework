<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Server\Socket\ListenSocketOptions;

/**
 * Master-owned POSIX listener shared with Workers through an inherited FD.
 *
 * Darwin SO_REUSEPORT permits duplicate binds but does not provide the
 * per-connection distribution required by WLS. The Master therefore binds one
 * public socket and explicitly grants only FD 3 to Worker launchers. Workers
 * compete on the same accept queue and never proxy bytes through the Master.
 */
final class DirectSharedListener
{
    public const INHERITED_FD = 3;

    /** @var resource|null */
    private mixed $listener = null;

    private string $host = '';

    private int $port = 0;

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

        $bound = @\stream_socket_get_name($listener, false);
        if (!\is_string($bound) || $bound === '') {
            @\fclose($listener);
            throw new \RuntimeException('Direct shared listener bind succeeded but its endpoint could not be verified.');
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
        $host = \trim($host, " \t\n\r\0\x0B[]");
        if ($host === '' || $host === '*') {
            return '0.0.0.0';
        }
        if (\strcasecmp($host, 'localhost') === 0) {
            return '127.0.0.1';
        }
        if (!\filter_var($host, \FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException(
                'Direct shared listener requires a resolved IPv4/IPv6 bind address; received "' . $host . '".'
            );
        }

        return $host;
    }
}
