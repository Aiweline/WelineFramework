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
    private const MAX_RAW_DIRECTORY_ENTRIES = 4096;
    /** Semantic ceiling for endpoint JSON files only. */
    private const MAX_ENDPOINT_ENTRIES = 256;
    private const MAX_ENDPOINT_BYTES = 2_097_152;

    public function __construct(
        private readonly ServerInstanceManager $instances = new ServerInstanceManager(),
    ) {
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
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
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate gateway project endpoints.');
        }
        $names = [];
        $rawEntries = 0;
        $endpointEntries = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$rawEntries > self::MAX_RAW_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Gateway project endpoint directory exceeds its fixed raw entry limit.'
                    );
                }
                // Instance managers keep .json.lock / .tmp.* sidecars beside endpoints;
                // they must not consume the semantic endpoint budget.
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
            $endpoint = $this->read($name);
            if (!\is_array($endpoint)) {
                throw new \RuntimeException(
                    'Gateway project endpoint disappeared during complete discovery.'
                );
            }
            $endpoints[$name] = $endpoint;
        }
        return $endpoints;
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
        return \str_ends_with($leaf, '.json.lock')
            || \str_contains($leaf, '.tmp.');
    }
}
