<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth\Device;

final readonly class AuthenticatedDeviceValidation
{
    public function __construct(
        public bool $valid,
        public ?string $deviceId = null,
        public string $reason = '',
    ) {
        if ($this->valid && trim((string)$this->deviceId) === '') {
            throw new \InvalidArgumentException('A valid authenticated device binding requires a public device id.');
        }
    }

    public static function valid(string $deviceId): self
    {
        return new self(true, $deviceId);
    }

    public static function invalid(string $reason = 'invalid'): self
    {
        return new self(false, null, $reason);
    }
}
