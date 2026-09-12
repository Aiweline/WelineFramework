<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;

/**
 * Per-word×locale AI translation failure counter with bounded cache.
 *
 * After MAX_FAILURES validation/save failures the word is skipped from future batches
 * until cleared (success) or TTL/prune removes the entry.
 */
class AiTranslationWordSkipStore
{
    public const CACHE_POOL = 'i18n_ai_word_skip';
    public const MAX_FAILURES = 3;
    /** Max tracked words per locale map (oldest by updated_at pruned first). */
    public const MAX_ENTRIES_PER_LOCALE = 500;
    /** Map TTL seconds; refreshed on write. */
    public const MAP_TTL_SECONDS = 604800; // 7 days
    /** Drop entries idle longer than this even within map TTL. */
    public const ENTRY_IDLE_SECONDS = 604800;

    private CachePoolInterface $cache;

    public function __construct(CacheManager|CachePoolInterface $cacheOrManager)
    {
        if ($cacheOrManager instanceof CachePoolInterface) {
            $this->cache = $cacheOrManager;
        } else {
            $this->cache = $cacheOrManager->pool(self::CACHE_POOL);
        }
    }

    public static function localeMapKey(string $locale): string
    {
        $locale = trim(str_replace('-', '_', $locale));

        return 'failmap:' . ($locale !== '' ? $locale : '_');
    }

    public function shouldSkip(string $locale, string $word): bool
    {
        $word = trim($word);
        if ($word === '') {
            return false;
        }
        $entry = $this->getEntry($locale, $word);
        if ($entry === null) {
            return false;
        }

        return !empty($entry['skip']) || (int)($entry['count'] ?? 0) >= self::MAX_FAILURES;
    }

    /**
     * @return array{skipped:bool,count:int,reason:string}
     */
    public function recordFailure(string $locale, string $word, string $reason): array
    {
        $word = trim($word);
        $reason = trim($reason);
        if ($word === '') {
            return ['skipped' => false, 'count' => 0, 'reason' => $reason];
        }

        $map = $this->loadMap($locale);
        $key = $this->wordKey($word);
        $now = time();
        $entry = $map[$key] ?? ['count' => 0, 'reason' => '', 'updated_at' => $now, 'skip' => false];
        $count = max(0, (int)($entry['count'] ?? 0)) + 1;
        $skip = $count >= self::MAX_FAILURES;
        $map[$key] = [
            'count' => $count,
            'reason' => $reason !== '' ? $reason : (string)($entry['reason'] ?? ''),
            'updated_at' => $now,
            'skip' => $skip,
            'word_preview' => mb_substr($word, 0, 80, 'UTF-8'),
        ];
        $this->saveMap($locale, $map);

        return [
            'skipped' => $skip,
            'count' => $count,
            'reason' => (string)$map[$key]['reason'],
        ];
    }

    public function clearSuccess(string $locale, string $word): void
    {
        $word = trim($word);
        if ($word === '') {
            return;
        }
        $map = $this->loadMap($locale);
        $key = $this->wordKey($word);
        if (!isset($map[$key])) {
            return;
        }
        unset($map[$key]);
        $this->saveMap($locale, $map);
    }

    /**
     * @return array<string, array{count:int,reason:string,updated_at:int,skip:bool,word_preview?:string}>
     */
    private function loadMap(string $locale): array
    {
        $raw = $this->cache->get(self::localeMapKey($locale));
        if (!is_array($raw)) {
            return [];
        }

        return $this->pruneMap($raw);
    }

    /**
     * @param array<string, mixed> $map
     */
    private function saveMap(string $locale, array $map): void
    {
        $pruned = $this->pruneMap($map);
        if ($pruned === []) {
            $this->cache->delete(self::localeMapKey($locale));

            return;
        }
        $this->cache->set(self::localeMapKey($locale), $pruned, self::MAP_TTL_SECONDS);
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, array{count:int,reason:string,updated_at:int,skip:bool,word_preview?:string}>
     */
    private function pruneMap(array $map): array
    {
        $now = time();
        $clean = [];
        foreach ($map as $key => $entry) {
            if (!is_string($key) || $key === '' || !is_array($entry)) {
                continue;
            }
            $updatedAt = (int)($entry['updated_at'] ?? 0);
            if ($updatedAt > 0 && ($now - $updatedAt) > self::ENTRY_IDLE_SECONDS) {
                continue;
            }
            $count = max(0, (int)($entry['count'] ?? 0));
            if ($count <= 0) {
                continue;
            }
            $clean[$key] = [
                'count' => $count,
                'reason' => (string)($entry['reason'] ?? ''),
                'updated_at' => $updatedAt > 0 ? $updatedAt : $now,
                'skip' => !empty($entry['skip']) || $count >= self::MAX_FAILURES,
                'word_preview' => mb_substr((string)($entry['word_preview'] ?? ''), 0, 80, 'UTF-8'),
            ];
        }

        if (count($clean) <= self::MAX_ENTRIES_PER_LOCALE) {
            return $clean;
        }

        uasort($clean, static function (array $a, array $b): int {
            return ((int)$a['updated_at']) <=> ((int)$b['updated_at']);
        });
        // Keep newest MAX_ENTRIES_PER_LOCALE.
        $clean = array_slice($clean, -self::MAX_ENTRIES_PER_LOCALE, null, true);

        return $clean;
    }

    /**
     * @return array{count:int,reason:string,updated_at:int,skip:bool,word_preview?:string}|null
     */
    private function getEntry(string $locale, string $word): ?array
    {
        $map = $this->loadMap($locale);
        $key = $this->wordKey($word);
        $entry = $map[$key] ?? null;

        return is_array($entry) ? $entry : null;
    }

    private function wordKey(string $word): string
    {
        return hash('sha256', $word);
    }
}
