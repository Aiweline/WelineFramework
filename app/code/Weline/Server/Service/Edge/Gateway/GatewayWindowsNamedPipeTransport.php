<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Shared Windows transport for the authenticated controller and emergency
 * guardian clients. Authentication remains at the protocol-client layer; this
 * class only returns one complete bounded frame from the fixed native channel.
 */
final class GatewayWindowsNamedPipeTransport
{
    public function exchange(
        string $channel,
        string $frame,
        int $maximumFrameBytes,
        float $deadlineMonotonic,
        float $connectTimeoutSeconds,
    ): string {
        return GatewayBoundedCommandRunner::exchangeWindowsNamedPipe(
            $channel,
            $frame,
            $maximumFrameBytes,
            $deadlineMonotonic,
            $connectTimeoutSeconds,
        );
    }
}
