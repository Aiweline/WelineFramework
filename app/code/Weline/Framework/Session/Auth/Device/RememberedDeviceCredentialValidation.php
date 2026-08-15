<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth\Device;

final readonly class RememberedDeviceCredentialValidation
{
    public function __construct(
        public bool $valid,
        public ?string $principalId = null,
        public ?string $deviceId = null,
        public int $expiresAt = 0,
        public string $reason = '',
    ) {
        if ($this->valid
            && (trim((string)$this->principalId) === ''
                || trim((string)$this->deviceId) === ''
                || $this->expiresAt <= 0)) {
            throw new \InvalidArgumentException('A valid remembered-device credential is incomplete.');
        }
    }

    public static function valid(string $principalId, string $deviceId, int $expiresAt): self
    {
        return new self(true, $principalId, $deviceId, $expiresAt);
    }

    public static function invalid(string $reason = 'invalid'): self
    {
        return new self(false, null, null, 0, $reason);
    }
}
