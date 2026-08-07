<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\ServerInstanceManager;

/**
 * Bounded, no-follow endpoint discovery for security-sensitive gateway flows.
 *
 * The framework's legacy instance listing intentionally remains compatible.
 * Gateway mutations require a complete, fail-closed project view and therefore
 * use this narrower reader instead of glob() and unbounded file_get_contents().
 */
final class GatewayProjectEndpointReader
{
    /** Hard ceiling for any directory leaf (including .json.lock / .tmp sidecars). */
    public const MAX_RAW_DIRECTORY_ENTRIES = 4096;
    /** Semantic ceiling for endpoint JSON files only. */
    public const MAX_ENDPOINT_ENTRIES = 256;
    public const MAX_ENDPOINT_BYTES = 2_097_152;

    public function __construct(
        private readonly ServerInstanceManager $instances = new ServerInstanceManager(),
    ) {
    }

    /** @return array<string,array<string,mixed>> */
    public function all(?float $deadlineMonotonic = null): array
    {
        self::assertDeadline($deadlineMonotonic);
        $directory = $this->instances->getInstanceDir();
        $status = @\lstat($directory);
        if (!\is_array($status)) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException(
                    'Gateway project endpoint directory is indeterminate or unsafe.'
                );
            }
            return [];
        }
        if (\is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Gateway project endpoint directory is linked or special.'
            );
        }
        $waitTimeout = 300.0;
        if ($deadlineMonotonic !== null) {
            $waitTimeout = \min(
                $waitTimeout,
                $deadlineMonotonic - (\hrtime(true) / 1_000_000_000),
            );
            if ($waitTimeout <= 0.0) {
                self::assertDeadline($deadlineMonotonic);
            }
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            \rtrim($directory, '/\\') . DIRECTORY_SEPARATOR
                . ServerInstanceManager::GATEWAY_ENDPOINT_NAMESPACE_LOCK,
            fn (): array => $this->readCompleteSnapshotLocked(
                $directory,
                $deadlineMonotonic,
            ),
            waitTimeoutSeconds: $waitTimeout,
        );
    }

    /** @return array<string,array<string,mixed>> */
    private function readCompleteSnapshotLocked(
        string $directory,
        ?float $deadlineMonotonic,
    ): array {
        self::assertDeadline($deadlineMonotonic);
        $lockedStatus = @\lstat($directory);
        if (!\is_array($lockedStatus)
            || \is_link($directory)
            || ((((int)($lockedStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Gateway project endpoint directory changed during discovery.'
            );
        }
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate gateway project endpoints.');
        }
        $names = [];
        $rawEntries = 0;
        $endpointEntries = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                self::assertDeadline($deadlineMonotonic);
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$rawEntries > self::MAX_RAW_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Gateway project endpoint directory exceeds its fixed raw entry limit.'
                    );
                }
                // Instance managers keep .json.lock sidecars beside endpoints;
                // they must not consume the semantic endpoint budget. Temporary
                // files do not end in .json and are filtered below. Matching a
                // generic ".tmp." substring would hide valid instance IDs such
                // as "primary.tmp.worker" from the complete project view.
                if (self::isSidecarLeaf($leaf)) {
                    continue;
                }
                if (!\str_ends_with($leaf, '.json')) {
                    continue;
                }
                if (++$endpointEntries > self::MAX_ENDPOINT_ENTRIES) {
                    throw new \RuntimeException(
                        'Gateway project endpoint directory exceeds its fixed endpoint entry limit.'
                    );
                }
                $name = \substr($leaf, 0, -5);
                self::assertInstanceName($name);
                if (isset($names[$name])) {
                    throw new \RuntimeException(
                        'Gateway project endpoint directory contains a duplicate instance identity.'
                    );
                }
                GatewayProjectStateFilesystem::size(
                    $directory . DIRECTORY_SEPARATOR . $leaf,
                    self::MAX_ENDPOINT_BYTES,
                    'gateway project endpoint',
                );
                $names[$name] = true;
            }
        } finally {
            @\closedir($handle);
        }
        \ksort($names, SORT_STRING);
        $endpoints = [];
        foreach (\array_keys($names) as $name) {
            self::assertDeadline($deadlineMonotonic);
            $endpoint = $this->read($name);
            self::assertDeadline($deadlineMonotonic);
            if (!\is_array($endpoint)) {
                throw new \RuntimeException(
                    'Gateway project endpoint disappeared during complete discovery.'
                );
            }
            $endpoints[$name] = $endpoint;
        }
        self::assertDeadline($deadlineMonotonic);
        return $endpoints;
    }

    private static function assertDeadline(?float $deadlineMonotonic): void
    {
        if ($deadlineMonotonic === null) {
            return;
        }
        if (!\is_finite($deadlineMonotonic)
            || (\hrtime(true) / 1_000_000_000) >= $deadlineMonotonic
        ) {
            throw new \RuntimeException(
                'Gateway project endpoint discovery deadline was exhausted.',
            );
        }
    }

    /** @return array<string,mixed>|null */
    public function read(string $instanceName): ?array
    {
        self::assertInstanceName($instanceName);
        $encoded = GatewayProjectStateFilesystem::readOptional(
            $this->instances->getInstanceFile($instanceName),
            self::MAX_ENDPOINT_BYTES,
            'gateway project endpoint',
        );
        if ($encoded === null) {
            return null;
        }
        try {
            $endpoint = \json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Gateway project endpoint JSON is invalid.',
                0,
                $exception,
            );
        }
        if (!\is_array($endpoint) || \array_is_list($endpoint)) {
            throw new \RuntimeException('Gateway project endpoint payload is invalid.');
        }
        return $endpoint;
    }

    private static function assertInstanceName(string $instanceName): void
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException('Gateway project instance name is invalid.');
        }
    }

    private static function isSidecarLeaf(string $leaf): bool
    {
        return \str_ends_with($leaf, '.json.lock');
    }
}
