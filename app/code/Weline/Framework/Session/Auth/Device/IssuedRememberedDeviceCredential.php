<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth\Device;

final readonly class IssuedRememberedDeviceCredential
{
    public function __construct(
        public string $token,
        public string $deviceId,
        public int $expiresAt,
    ) {
        if (trim($this->token) === '' || trim($this->deviceId) === '' || $this->expiresAt <= 0) {
            throw new \InvalidArgumentException('The issued remembered-device credential is incomplete.');
        }
    }
}
