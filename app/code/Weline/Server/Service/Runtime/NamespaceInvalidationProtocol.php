<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Cache\Namespace\NamespacePath;

/** Strict cache_namespace_invalidate_v1 wire contract. */
final class NamespaceInvalidationProtocol
{
    public const TYPE_INVALIDATE = 'cache_namespace_invalidate_v1';
    public const TYPE_ACK = 'cache_namespace_invalidate_ack_v1';
    public const CAPABILITY = 'cache_namespace_invalidation_v1';
    public const SCHEMA_VERSION = 1;
    public const MAX_CHANGES = 64;
    public const MAX_FRAME_BYTES = 65536;
    public const OPERATION_ID_PATTERN = '/^nsi_[a-f0-9]{32}$/D';

    public function __construct(private readonly NamespacePath $namespacePath)
    {
    }

    public function newOperationId(): string
    {
        return 'nsi_' . \bin2hex(\random_bytes(16));
    }

    /**
     * @param array<int|string,mixed> $changes
     * @return array{type:string,schema_version:int,operation_id:string,authority_clock:int,changes:list<array{namespace:string,generation:int}>}
     */
    public function buildFrame(int $authorityClock, array $changes, ?string $operationId = null): array
    {
        $frame = [
            'type' => self::TYPE_INVALIDATE,
            'schema_version' => self::SCHEMA_VERSION,
            'operation_id' => $operationId ?? $this->newOperationId(),
            'authority_clock' => $authorityClock,
            'changes' => $this->normalizePublisherChanges($changes),
        ];

        return $this->validateFrame($frame);
    }

    /**
     * @param array<string,mixed> $frame
     * @return array{type:string,schema_version:int,operation_id:string,authority_clock:int,changes:list<array{namespace:string,generation:int}>}
     */
    public function validateFrame(array $frame): array
    {
        $allowed = ['type', 'schema_version', 'operation_id', 'authority_clock', 'changes'];
        foreach (\array_keys($frame) as $key) {
            if (!\is_string($key) || !\in_array($key, $allowed, true)) {
                throw new NamespaceInvalidationProtocolException(
                    'frame_unknown_field',
                    (string)__('缓存命名空间失效帧包含未知字段。'),
                );
            }
        }
        if (\array_values(\array_intersect($allowed, \array_keys($frame))) !== $allowed) {
            throw new NamespaceInvalidationProtocolException(
                'frame_missing_field',
                (string)__('缓存命名空间失效帧不完整。'),
            );
        }
        if (($frame['type'] ?? null) !== self::TYPE_INVALIDATE
            || ($frame['schema_version'] ?? null) !== self::SCHEMA_VERSION
        ) {
            throw new NamespaceInvalidationProtocolException(
                'schema_unsupported',
                (string)__('不支持该缓存命名空间失效协议版本。'),
            );
        }

        $operationId = $frame['operation_id'] ?? null;
        if (!\is_string($operationId) || \preg_match(self::OPERATION_ID_PATTERN, $operationId) !== 1) {
            throw new NamespaceInvalidationProtocolException(
                'operation_id_invalid',
                (string)__('缓存命名空间失效操作 ID 无效。'),
            );
        }
        $authorityClock = $frame['authority_clock'] ?? null;
        if (!\is_int($authorityClock) || $authorityClock <= 0) {
            throw new NamespaceInvalidationProtocolException(
                'authority_clock_invalid',
                (string)__('缓存命名空间权威时钟必须为正整数。'),
            );
        }

        $rawChanges = $frame['changes'] ?? null;
        if (!\is_array($rawChanges)
            || !\array_is_list($rawChanges)
            || $rawChanges === []
            || \count($rawChanges) > self::MAX_CHANGES
        ) {
            throw new NamespaceInvalidationProtocolException(
                'changes_invalid',
                (string)__('缓存命名空间失效变更数必须为 1 至 64。'),
            );
        }

        $deduplicated = [];
        $previousNamespace = null;
        foreach ($rawChanges as $change) {
            if (!\is_array($change)
                || \array_keys($change) !== ['namespace', 'generation']
                || !\is_string($change['namespace'] ?? null)
                || !\is_int($change['generation'] ?? null)
                || $change['generation'] <= 0
            ) {
                throw new NamespaceInvalidationProtocolException(
                    'change_invalid',
                    (string)__('缓存命名空间失效变更无效。'),
                );
            }
            try {
                $namespace = $this->namespacePath->canonicalize($change['namespace']);
            } catch (\Throwable) {
                throw new NamespaceInvalidationProtocolException(
                    'namespace_invalid',
                    (string)__('缓存命名空间路径无效。'),
                );
            }
            if ($previousNamespace !== null && \strcmp($previousNamespace, $namespace) > 0) {
                throw new NamespaceInvalidationProtocolException(
                    'changes_not_sorted',
                    (string)__('缓存命名空间失效变更必须按命名空间排序。'),
                );
            }
            $previousNamespace = $namespace;
            $deduplicated[$namespace] = \max(
                (int)($deduplicated[$namespace] ?? 0),
                $change['generation'],
            );
        }

        $changes = [];
        foreach ($deduplicated as $namespace => $generation) {
            $changes[] = ['namespace' => $namespace, 'generation' => $generation];
        }
        if (\count($changes) > self::MAX_CHANGES) {
            throw new NamespaceInvalidationProtocolException(
                'changes_invalid',
                (string)__('缓存命名空间失效变更超出容量限制。'),
            );
        }

        $normalized = [
            'type' => self::TYPE_INVALIDATE,
            'schema_version' => self::SCHEMA_VERSION,
            'operation_id' => $operationId,
            'authority_clock' => $authorityClock,
            'changes' => $changes,
        ];
        $encoded = \json_encode(
            $normalized,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        ) . "\n";
        if (\strlen($encoded) > self::MAX_FRAME_BYTES) {
            throw new NamespaceInvalidationProtocolException(
                'frame_too_large',
                (string)__('缓存命名空间失效帧超过 64 KiB。'),
            );
        }

        return $normalized;
    }

    /** @param array<string,mixed> $frame */
    public function payloadHash(array $frame): string
    {
        $frame = $this->validateFrame($frame);
        $material = [
            'schema_version' => $frame['schema_version'],
            'authority_clock' => $frame['authority_clock'],
            'changes' => $frame['changes'],
        ];

        return \hash('sha256', (string)\json_encode(
            $material,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param array<int|string,mixed> $changes
     * @return list<array{namespace:string,generation:int}>
     */
    private function normalizePublisherChanges(array $changes): array
    {
        $byNamespace = [];
        foreach ($changes as $key => $value) {
            if (\is_string($key)) {
                $namespace = $key;
                $generation = $value;
            } elseif (\is_array($value)) {
                $namespace = $value['namespace'] ?? null;
                $generation = $value['generation'] ?? null;
            } else {
                throw new NamespaceInvalidationProtocolException(
                    'change_invalid',
                    (string)__('缓存命名空间失效变更无效。'),
                );
            }
            if (!\is_string($namespace) || !\is_int($generation) || $generation <= 0) {
                throw new NamespaceInvalidationProtocolException(
                    'change_invalid',
                    (string)__('缓存命名空间失效变更无效。'),
                );
            }
            try {
                $canonical = $this->namespacePath->canonicalize($namespace);
            } catch (\Throwable) {
                throw new NamespaceInvalidationProtocolException(
                    'namespace_invalid',
                    (string)__('缓存命名空间路径无效。'),
                );
            }
            $byNamespace[$canonical] = \max((int)($byNamespace[$canonical] ?? 0), $generation);
        }
        if ($byNamespace === [] || \count($byNamespace) > self::MAX_CHANGES) {
            throw new NamespaceInvalidationProtocolException(
                'changes_invalid',
                (string)__('缓存命名空间失效变更数必须为 1 至 64。'),
            );
        }
        \ksort($byNamespace, \SORT_STRING);

        $normalized = [];
        foreach ($byNamespace as $namespace => $generation) {
            $normalized[] = ['namespace' => $namespace, 'generation' => $generation];
        }

        return $normalized;
    }
}
