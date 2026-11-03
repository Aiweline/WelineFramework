<?php

declare(strict_types=1);

namespace Weline\Mail\Api\Relay;

/**
 * Public boundary for sealing/opening outbound relay credentials.
 */
interface RelaySecretInterface
{
    public function seal(string $plain): string;

    public function open(string $sealedB64): string;
}
