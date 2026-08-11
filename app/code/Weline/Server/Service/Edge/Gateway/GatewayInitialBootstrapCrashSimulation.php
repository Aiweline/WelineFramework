<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/** Isolated test-only hard-crash boundary; production never enables it. */
final class GatewayInitialBootstrapCrashSimulation extends \RuntimeException
{
    public static function hit(string $phase, GatewayPaths $paths): void
    {
        if (!$paths->isTestMode()) {
            return;
        }
        $requested = \strtolower(\trim((string)(
            \getenv('WLS_GATEWAY_TEST_INITIAL_BOOTSTRAP_FAULT') ?: ''
        )));
        if ($requested !== '' && \hash_equals($requested, \strtolower($phase))) {
            throw new self(
                'Simulated hard crash at initial bootstrap phase: ' . $phase,
            );
        }
    }
}
