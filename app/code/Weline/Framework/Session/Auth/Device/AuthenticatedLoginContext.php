<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth\Device;

final readonly class AuthenticatedLoginContext
{
    public const SOURCE_PASSWORD = 'password';
    public const SOURCE_REMEMBERED = 'remembered';
    public const SOURCE_LEGACY_REMEMBERED = 'legacy_remembered';
    /** Reuse the current session id (no regenerate). For trusted server-side binds such as theme preview auto-login. */
    public const SOURCE_TRUSTED_REUSE = 'trusted_reuse';

    public function __construct(
        public string $source = self::SOURCE_PASSWORD,
        public ?string $deviceId = null,
    ) {
        if (!in_array($this->source, [
            self::SOURCE_PASSWORD,
            self::SOURCE_REMEMBERED,
            self::SOURCE_LEGACY_REMEMBERED,
            self::SOURCE_TRUSTED_REUSE,
        ], true)) {
            throw new \InvalidArgumentException('The authenticated login source is not supported.');
        }
        if ($this->source === self::SOURCE_REMEMBERED && trim((string)$this->deviceId) === '') {
            throw new \InvalidArgumentException('Remembered login requires a device id.');
        }
    }

    public static function remembered(string $deviceId): self
    {
        return new self(self::SOURCE_REMEMBERED, trim($deviceId));
    }

    public static function legacyRemembered(): self
    {
        return new self(self::SOURCE_LEGACY_REMEMBERED);
    }

    public static function trustedReuse(): self
    {
        return new self(self::SOURCE_TRUSTED_REUSE);
    }
}
