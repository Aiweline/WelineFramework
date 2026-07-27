<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

final readonly class RuntimeProviderResolution
{
    public const AVAILABLE = 'available';
    public const NOT_CONFIGURED = 'not_configured';
    public const CONFIGURED_UNAVAILABLE = 'configured_unavailable';

    public function __construct(
        public string $status,
        public ?object $provider = null,
        public ?string $implementation = null,
        public string $errorCode = '',
        public string $error = '',
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->status === self::AVAILABLE && $this->provider !== null;
    }
}
