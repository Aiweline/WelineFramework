<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth\Device;

final readonly class AuthenticatedDeviceContext
{
    public function __construct(
        public string $area,
        public string $principalId,
        public string $sessionId,
        public int $sessionExpiresAt,
        public ?string $deviceId = null,
    ) {
        if (trim($this->area) === ''
            || trim($this->principalId) === ''
            || trim($this->sessionId) === ''
            || $this->sessionExpiresAt <= 0) {
            throw new \InvalidArgumentException('Authenticated device context is incomplete.');
        }
    }

    public function withDeviceId(?string $deviceId): self
    {
        return new self(
            area: $this->area,
            principalId: $this->principalId,
            sessionId: $this->sessionId,
            sessionExpiresAt: $this->sessionExpiresAt,
            deviceId: $deviceId,
        );
    }

    public static function sessionKeyForArea(string $area): string
    {
        $realm = match (strtolower(trim($area))) {
            'backend', 'rest_backend' => 'backend',
            'frontend', 'api', 'checkout' => 'frontend',
            default => preg_replace('/[^a-z0-9_]+/i', '_', strtolower(trim($area))) ?: 'custom',
        };
        return '__weline_authenticated_device_' . $realm;
    }
}
