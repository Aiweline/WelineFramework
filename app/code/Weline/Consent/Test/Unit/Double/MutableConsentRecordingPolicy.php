<?php

declare(strict_types=1);

namespace Weline\Consent\Test\Unit\Double;

use Weline\Consent\Api\ConsentRecordingPolicyInterface;

final class MutableConsentRecordingPolicy implements ConsentRecordingPolicyInterface
{
    public function __construct(
        public bool $enabled = true,
    ) {
    }

    public function isRecordingEnabled(): bool
    {
        return $this->enabled;
    }
}
