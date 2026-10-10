<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\Cache\Contract\ProcessMemoryStoreInterface;
use Weline\Framework\Runtime\MemoryReclaimableInterface;
use Weline\Framework\Runtime\WlsConcurrency;

/**
 * Phrase 进程袋压力回收：优先整清最冷（未 pin）locale 桶，再回扫袋键。
 * 候选 = LRU ∪ 袋内语种；Store 空但袋仍有键的语种也可驱逐。
 */
final class PhraseProcessMemoryReclaimable implements MemoryReclaimableInterface
{
    public function __construct(
        private readonly MemoryReclaimableInterface $inner,
        private readonly ProcessMemoryStoreInterface $store,
    ) {
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function estimateBytes(): int
    {
        return $this->inner->estimateBytes();
    }

    public function reclaimPriority(): int
    {
        return $this->inner->reclaimPriority();
    }

    public function minRetainBytes(): int
    {
        return $this->inner->minRetainBytes();
    }

    public function compact(): array
    {
        if (!WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }
        $before = $this->store->estimateBytes();
        $candidates = DictionaryCacheNamespace::reclaimCandidateLocales();
        $unpinned = $this->unpinnedLocales($candidates);
        if ($unpinned !== []) {
            $drop = \max(1, (int)\ceil(\count($unpinned) / 2));
            for ($i = 0; $i < $drop; $i++) {
                DictionaryCacheNamespace::evictLocaleBucket($unpinned[$i]);
            }
        } else {
            $this->inner->compact();
            DictionaryCacheNamespace::reconcileAfterStoreEviction($candidates);
        }
        $after = $this->store->estimateBytes();

        return [
            'freed_bytes' => \max(0, $before - $after),
            'skipped' => false,
            'name' => $this->getName(),
        ];
    }

    public function evict(int $targetBytes): array
    {
        if ($targetBytes <= 0 || !WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }
        $before = $this->store->estimateBytes();
        $locales = DictionaryCacheNamespace::reclaimCandidateLocales();
        foreach ($this->unpinnedLocales($locales) as $locale) {
            if (($before - $this->store->estimateBytes()) >= $targetBytes) {
                break;
            }
            DictionaryCacheNamespace::evictLocaleBucket($locale);
        }
        $freed = \max(0, $before - $this->store->estimateBytes());
        if ($freed < $targetBytes) {
            $this->inner->evict($targetBytes - $freed);
            DictionaryCacheNamespace::reconcileAfterStoreEviction($locales);
        }
        $after = $this->store->estimateBytes();

        return [
            'freed_bytes' => \max(0, $before - $after),
            'skipped' => false,
            'name' => $this->getName(),
        ];
    }

    /**
     * @param list<string> $locales oldest → newest (then bag-only)
     * @return list<string>
     */
    private function unpinnedLocales(array $locales): array
    {
        $out = [];
        foreach ($locales as $locale) {
            if (!\is_string($locale) || $locale === '') {
                continue;
            }
            if (DictionaryCacheNamespace::isLocaleBucketPinned($locale)) {
                continue;
            }
            $hasStore = $this->store->keysInBucket($locale) !== [];
            $hasBag = DictionaryCacheNamespace::localeHasBoundBagKeys($locale);
            if (!$hasStore && !$hasBag) {
                continue;
            }
            $out[] = $locale;
        }

        return $out;
    }
}
