<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server;

if (!\defined('BP')) {
    \define('BP', \rtrim((string) \dirname(__DIR__, 7), "\\/") . DIRECTORY_SEPARATOR);
}

if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

if (!\function_exists(__NAMESPACE__ . '\\__')) {
    function __(string $message, array $args = []): string
    {
        $translated = $message;
        foreach (\array_values($args) as $index => $value) {
            $translated = \str_replace('%{' . ($index + 1) . '}', (string)$value, $translated);
            $translated = \str_replace('%' . ($index + 1), (string)$value, $translated);
        }

        return $translated;
    }
}

if (!\function_exists(__NAMESPACE__ . '\\stopTestRuntimeSelection')) {
    function stopTestRuntimeSelection(bool $dispatcher = false): \Weline\Server\Service\Runtime\RuntimeSelection
    {
        return \Weline\Server\Service\Runtime\RuntimeSelection::fromArray([
            'requested_topology' => 'auto',
            'effective_topology' => $dispatcher ? 'dispatcher' : 'direct',
            'topology_source' => 'unit-test',
            'os_family' => PHP_OS_FAMILY,
            'event_loop_driver' => 'select',
            'ssl_engine' => 'stream',
            'listener_mode' => $dispatcher ? 'single' : 'shared_fd',
            'policy_compatible' => true,
            'reason_codes' => ['unit_test'],
            'reason' => 'unit test runtime selection',
        ]);
    }
}
