<?php

declare(strict_types=1);

namespace Weline\Server\Api\Control;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;

/**
 * Narrow public boundary for module-requested force reloads.
 */
final class RuntimeReloadGateway
{
    public function __construct(
        private readonly IpcControlGateway $gateway,
    ) {
    }

    public function forceReloadAsync(
        string $instanceName,
        float $timeout = 5.0,
    ): RuntimeReloadResult {
        $result = $this->gateway->reloadAsync(
            $instanceName,
            ControlMessage::RELOAD_TYPE_FORCE,
            $timeout,
        );

        return new RuntimeReloadResult(
            (bool)($result['success'] ?? false),
            (string)($result['message'] ?? ''),
        );
    }
}
