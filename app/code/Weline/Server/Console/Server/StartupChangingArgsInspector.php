<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server;

/**
 * Detects CLI args that require a full WLS generation restart instead of rolling reload.
 */
trait StartupChangingArgsInspector
{
    /**
     * @param array<string, mixed> $args
     */
    protected function hasStartupChangingArgs(array $args): bool
    {
        $keys = [
            'p', 'port', 'host', 'h', 'count', 'c',
            'no-ssl', 'no_ssl', 'ssl-cert', 'ssl-key',
            'direct', 'dispatcher',
            'runtime-strategy', 'runtime_strategy',
            'event-loop', 'event_loop', 'loop-driver', 'loop_driver',
            'worker-memory-limit', 'worker_memory_limit', 'dispatcher-memory-limit', 'dispatcher_memory_limit',
        ];
        foreach ($keys as $key) {
            if (isset($args[$key])) {
                return true;
            }
        }

        return false;
    }
}
