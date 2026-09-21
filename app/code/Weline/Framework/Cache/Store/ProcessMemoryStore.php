<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Store;

use Weline\Framework\Cache\Contract\ProcessMemoryStoreInterface;
use Weline\Framework\Cache\Policy\ProcessMemoryEvictionPolicy;

/**
 * Process-local memo bag: heat for capacity, pin for pressure protection, optional locale buckets.
 */
final class ProcessMemoryStore implements ProcessMemoryStoreInterface
{
    /** @var array<string, array{value:mixed, heat:int, pin:int, bytes:int, bucket:?string}> */
    private array $entries = [];

    /** @var array<string, array<string, true>> */
    private array $buckets = [];

    /** @var array<string, int> */
    private array $bucketPins = [];

    private int $heatClock = 0;

    private int $maxItems;

    public function __construct(int $maxItems = 4096)
    {
        $this->maxItems = \max(1, $maxItems);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->entries[$key])) {
            return $default;
        }
        $this->entries[$key]['heat'] = ++$this->heatClock;

        return $this->entries[$key]['value'];
    }

    public function set(string $key, mixed $value, ?string $bucket = null, ?int $bytes = null): void
    {
        $bucket = $bucket !== null && $bucket !== '' ? $bucket : null;
        $estimated = $bytes ?? self::estimateValueBytes($value);
        if (isset($this->entries[$key])) {
            $oldBucket = $this->entries[$key]['bucket'];
            if ($oldBucket !== null && $oldBucket !== $bucket) {
                unset($this->buckets[$oldBucket][$key]);
                if ($this->buckets[$oldBucket] === []) {
                    unset($this->buckets[$oldBucket]);
                }
            }
        }

        $this->entries[$key] = [
            'value' => $value,
            'heat' => ++$this->heatClock,
            'pin' => (int)($this->entries[$key]['pin'] ?? 0),
            'bytes' => \max(0, $estimated),
            'bucket' => $bucket,
        ];
        if ($bucket !== null) {
            $this->buckets[$bucket][$key] = true;
        }

        $this->enforceCapacity();
    }

    public function delete(string $key): bool
    {
        if (!isset($this->entries[$key])) {
            return false;
        }
        $bucket = $this->entries[$key]['bucket'];
        unset($this->entries[$key]);
        if ($bucket !== null) {
            unset($this->buckets[$bucket][$key]);
            if (($this->buckets[$bucket] ?? []) === []) {
                unset($this->buckets[$bucket]);
            }
        }

        return true;
    }

    public function clear(?string $bucket = null): int
    {
        if ($bucket === null) {
            $n = \count($this->entries);
            $this->entries = [];
            $this->buckets = [];
            $this->bucketPins = [];

            return $n;
        }

        $keys = \array_keys($this->buckets[$bucket] ?? []);
        foreach ($keys as $key) {
            unset($this->entries[$key]);
        }
        unset($this->buckets[$bucket], $this->bucketPins[$bucket]);

        return \count($keys);
    }

    public function pin(string $key): void
    {
        if (!isset($this->entries[$key])) {
            return;
        }
        $this->entries[$key]['pin']++;
    }

    public function unpin(string $key): void
    {
        if (!isset($this->entries[$key])) {
            return;
        }
        $this->entries[$key]['pin'] = \max(0, $this->entries[$key]['pin'] - 1);
    }

    public function pinBucket(string $bucket): void
    {
        if ($bucket === '') {
            return;
        }
        $this->bucketPins[$bucket] = ($this->bucketPins[$bucket] ?? 0) + 1;
        foreach (\array_keys($this->buckets[$bucket] ?? []) as $key) {
            $this->pin($key);
        }
    }

    public function unpinBucket(string $bucket): void
    {
        if ($bucket === '' || ($this->bucketPins[$bucket] ?? 0) <= 0) {
            return;
        }
        $this->bucketPins[$bucket]--;
        if ($this->bucketPins[$bucket] <= 0) {
            unset($this->bucketPins[$bucket]);
        }
        foreach (\array_keys($this->buckets[$bucket] ?? []) as $key) {
            $this->unpin($key);
        }
    }

    public function getItemCount(): int
    {
        return \count($this->entries);
    }

    public function estimateBytes(): int
    {
        $bytes = 0;
        foreach ($this->entries as $entry) {
            $bytes += $entry['bytes'];
        }

        return $bytes;
    }

    public function evictByPolicy(string $tier, ?int $targetBytes = null, ?int $targetCount = null): array
    {
        $metas = [];
        foreach ($this->entries as $key => $entry) {
            $pin = $entry['pin'];
            $bucket = $entry['bucket'];
            if ($bucket !== null && ($this->bucketPins[$bucket] ?? 0) > 0) {
                $pin = \max($pin, 1);
            }
            $metas[$key] = [
                'pin' => $pin,
                'heat' => $entry['heat'],
                'bytes' => $entry['bytes'],
            ];
        }

        $victims = ProcessMemoryEvictionPolicy::selectVictims($metas, $tier, $targetBytes, $targetCount);
        foreach ($victims as $key) {
            $this->delete($key);
        }

        return $victims;
    }

    public function keysInBucket(string $bucket): array
    {
        return \array_keys($this->buckets[$bucket] ?? []);
    }

    private function enforceCapacity(): void
    {
        $overflow = \count($this->entries) - $this->maxItems;
        if ($overflow <= 0) {
            return;
        }
        $this->evictByPolicy(ProcessMemoryEvictionPolicy::TIER_SOFT, null, $overflow);
    }

    private static function estimateValueBytes(mixed $value): int
    {
        if (\is_string($value)) {
            return \strlen($value);
        }
        if (\is_array($value)) {
            return \min(1_048_576, \strlen((string)\json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?: 64);
        }
        if (\is_int($value) || \is_float($value) || \is_bool($value) || $value === null) {
            return 16;
        }

        return 256;
    }
}
