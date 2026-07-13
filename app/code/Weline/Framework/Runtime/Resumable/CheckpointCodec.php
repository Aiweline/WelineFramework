<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Canonical JSON codec for data persisted by the resumable-task runtime.
 *
 * This intentionally accepts only data that can be replayed after a new
 * process starts. Runtime objects, call stacks, resources and credentials do
 * not belong in a checkpoint or event payload.
 */
final class CheckpointCodec
{
    public const DEFAULT_MAX_DEPTH = 64;

    /**
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    public static function normalize(array $data, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        if ($maxDepth < 1) {
            throw new CheckpointValidationException('Checkpoint maximum depth must be positive.');
        }

        /** @var array<string|int, mixed> $normalized */
        $normalized = self::normalizeValue($data, '$', 0, $maxDepth);

        return $normalized;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    public static function encode(array $data, int $maxDepth = self::DEFAULT_MAX_DEPTH): string
    {
        try {
            return \json_encode(
                self::normalize($data, $maxDepth),
                \JSON_UNESCAPED_SLASHES
                | \JSON_UNESCAPED_UNICODE
                | \JSON_PRESERVE_ZERO_FRACTION
                | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new CheckpointValidationException('Checkpoint data is not valid JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<string|int, mixed>
     */
    public static function decode(string $json, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        try {
            $data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CheckpointValidationException('Checkpoint JSON cannot be decoded: ' . $exception->getMessage(), 0, $exception);
        }

        if (!\is_array($data)) {
            throw new CheckpointValidationException('Checkpoint JSON root must be an object or array.');
        }

        return self::normalize($data, $maxDepth);
    }

    /**
     * @param array<string|int, mixed> $data
     */
    public static function checksum(array $data, int $maxDepth = self::DEFAULT_MAX_DEPTH): string
    {
        return \hash('sha256', self::encode($data, $maxDepth));
    }

    private static function normalizeValue(mixed $value, string $path, int $depth, int $maxDepth): mixed
    {
        if ($depth > $maxDepth) {
            throw new CheckpointValidationException("Checkpoint data exceeds maximum depth at {$path}.");
        }

        if ($value === null || \is_bool($value) || \is_int($value) || \is_string($value)) {
            return $value;
        }

        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new CheckpointValidationException("Checkpoint data contains a non-finite float at {$path}.");
            }

            return $value;
        }

        if (\is_resource($value)) {
            throw new CheckpointValidationException("Checkpoint data contains a resource at {$path}.");
        }

        if (\is_object($value)) {
            $type = $value instanceof \Fiber ? 'Fiber' : ($value instanceof \Closure ? 'Closure' : $value::class);
            throw new CheckpointValidationException("Checkpoint data contains forbidden runtime object {$type} at {$path}.");
        }

        if (!\is_array($value)) {
            throw new CheckpointValidationException("Checkpoint data contains unsupported value at {$path}.");
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (!\is_int($key) && !\is_string($key)) {
                throw new CheckpointValidationException("Checkpoint data contains an invalid key at {$path}.");
            }
            if (\is_string($key)) {
                if ($key === '') {
                    throw new CheckpointValidationException("Checkpoint data contains an empty key at {$path}.");
                }
                self::assertSafeFieldName($key, $path);
            }
            $keyPath = \is_int($key) ? $path . '[' . $key . ']' : $path . '.' . $key;
            $normalized[$key] = self::normalizeValue($item, $keyPath, $depth + 1, $maxDepth);
        }

        if (!\array_is_list($normalized)) {
            \ksort($normalized, \SORT_STRING);
        }

        return $normalized;
    }

    private static function assertSafeFieldName(string $field, string $path): void
    {
        $normalized = \preg_replace('/(?<!^)[A-Z]/', '_$0', $field);
        $normalized = \strtolower(\str_replace(['-', '.', ' '], '_', (string)$normalized));
        if (\in_array($normalized, [
            'password',
            'passwd',
            'secret',
            'authorization',
            'cookie',
            'credential',
            'private_key',
            'api_key',
            'access_key',
            'auth_token',
            'access_token',
            'refresh_token',
            'client_secret',
            'session_token',
        ], true)) {
            throw new CheckpointValidationException("Checkpoint data contains sensitive field {$field} at {$path}.");
        }
    }
}
