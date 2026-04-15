<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor\Endpoint;

final class ControlEndpoint
{
    public const KIND_UNIX = 'unix';
    public const KIND_TCP = 'tcp';

    private function __construct(
        public readonly string $kind,
        public readonly string $address,
    ) {
    }

    public static function unix(string $path): self
    {
        return new self(self::KIND_UNIX, $path);
    }

    public static function tcp(string $host, int $port): self
    {
        return new self(self::KIND_TCP, "{$host}:{$port}");
    }

    public function isUnix(): bool
    {
        return $this->kind === self::KIND_UNIX;
    }

    public function isTcp(): bool
    {
        return $this->kind === self::KIND_TCP;
    }

    public function host(): string
    {
        if (!$this->isTcp()) {
            return '';
        }

        [$host] = \explode(':', $this->address, 2);

        return $host;
    }

    public function port(): int
    {
        if (!$this->isTcp()) {
            return 0;
        }

        [, $port] = \explode(':', $this->address, 2);

        return (int) $port;
    }

    public function uri(): string
    {
        if ($this->isUnix()) {
            return 'unix://' . $this->address;
        }

        return 'tcp://' . $this->address;
    }
}
