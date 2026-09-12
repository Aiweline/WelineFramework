<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\I18n\Service\AiTranslationWordSkipStore;

/**
 * Same word×locale failures must accumulate then skip; cache must prune.
 */
final class AiTranslationWordSkipStoreTest extends TestCase
{
    public function testReachesThresholdThenSkipsAndSurfacesReason(): void
    {
        $store = new AiTranslationWordSkipStore($this->arrayPool());
        $locale = 'ar_SA';
        $word = '色系文件不存在：%{file}';

        self::assertFalse($store->shouldSkip($locale, $word));

        for ($i = 1; $i < AiTranslationWordSkipStore::MAX_FAILURES; $i++) {
            $r = $store->recordFailure($locale, $word, '翻译丢失结构化占位符');
            self::assertFalse($r['skipped']);
            self::assertSame($i, $r['count']);
            self::assertFalse($store->shouldSkip($locale, $word));
        }

        $final = $store->recordFailure($locale, $word, '翻译丢失结构化占位符');
        self::assertTrue($final['skipped']);
        self::assertSame(AiTranslationWordSkipStore::MAX_FAILURES, $final['count']);
        self::assertTrue($store->shouldSkip($locale, $word));
        self::assertStringContainsString('翻译丢失结构化占位符', (string)$final['reason']);
    }

    public function testClearOnSuccessRemovesSkip(): void
    {
        $store = new AiTranslationWordSkipStore($this->arrayPool());
        $locale = 'pt_BR';
        $word = '草稿已发布';

        for ($i = 0; $i < AiTranslationWordSkipStore::MAX_FAILURES; $i++) {
            $store->recordFailure($locale, $word, 'empty');
        }
        self::assertTrue($store->shouldSkip($locale, $word));

        $store->clearSuccess($locale, $word);
        self::assertFalse($store->shouldSkip($locale, $word));
    }

    public function testPrunesWhenLocaleMapExceedsMaxEntries(): void
    {
        $pool = $this->arrayPool();
        $store = new AiTranslationWordSkipStore($pool);
        $locale = 'hi_IN';

        $max = AiTranslationWordSkipStore::MAX_ENTRIES_PER_LOCALE;
        for ($i = 0; $i < $max + 25; $i++) {
            $store->recordFailure($locale, 'word_' . $i, 'fail');
        }

        $map = $pool->get(AiTranslationWordSkipStore::localeMapKey($locale));
        self::assertIsArray($map);
        self::assertLessThanOrEqual($max, count($map));
    }

    public function testServiceWiresSkipStoreConstants(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/AiTranslationService.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('AiTranslationWordSkipStore', $source);
        self::assertStringContainsString('recordFailure', $source);
        self::assertStringContainsString('shouldSkip', $source);
        self::assertStringContainsString('多次失败已绕过', $source);
    }

    /**
     * Minimal in-memory pool for unit tests (get/set/delete only).
     */
    private function arrayPool(): CachePoolInterface
    {
        return new class implements CachePoolInterface {
            /** @var array<string, mixed> */
            private array $data = [];

            public function get(string $key): mixed
            {
                return $this->data[$key] ?? false;
            }

            public function set(string $key, mixed $value, int $ttl = 0): bool
            {
                $this->data[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->data[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->data = [];

                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }

            public function getIdentity(): string
            {
                return 'test';
            }

            public function getTip(): string
            {
                return 'test';
            }

            public function isPermanent(): bool
            {
                return false;
            }

            public function getMultiple(array $keys): array
            {
                $out = [];
                foreach ($keys as $k) {
                    $out[$k] = $this->get((string)$k);
                }

                return $out;
            }

            public function setMultiple(array $values, int $ttl = 0): bool
            {
                foreach ($values as $k => $v) {
                    $this->set((string)$k, $v, $ttl);
                }

                return true;
            }

            public function deleteMultiple(array $keys): bool
            {
                foreach ($keys as $k) {
                    $this->delete((string)$k);
                }

                return true;
            }

            public function getStats(): array
            {
                return ['identity' => 'test', 'hits' => 0, 'misses' => 0, 'hit_ratio' => 0.0, 'permanent' => false];
            }

            public function getCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): mixed
            {
                return $this->get($key);
            }

            public function setCustom(string $key, mixed $value, int $ttl = 0, bool $website = false, bool $lang = false, bool $currency = false): bool
            {
                return $this->set($key, $value, $ttl);
            }

            public function deleteCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): bool
            {
                return $this->delete($key);
            }

            public function hasCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): bool
            {
                return $this->has($key);
            }
        };
    }
}
