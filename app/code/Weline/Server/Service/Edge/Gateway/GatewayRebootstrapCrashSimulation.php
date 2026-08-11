<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Test-only hard-crash boundary for replaying persisted rebootstrap phases.
 * Production code can never construct this through configuration.
 */
final class GatewayRebootstrapCrashSimulation extends \RuntimeException
{
}
