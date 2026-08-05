<?php

declare(strict_types=1);

namespace Weline\Consent\Api;

interface ConsentRecordingPolicyInterface
{
    public function isRecordingEnabled(): bool;
}
