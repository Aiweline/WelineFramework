<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/29 19:45:44
 */

namespace Weline\I18n\Observer;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Phrase\Parser;

class ParserWordsRegister implements \Weline\Framework\Event\ObserverInterface
{
    public const WORDS_CACHE_KEY = 'WELINE_FRAMEWORK_SYSTEM_WORDS_CACHE_KEY';
    public const FRONTEND_WORDS_CACHE_KEY = 'WELINE_FRAMEWORK_SYSTEM_WORDS_CACHE_KEY_FRONTEND';
    public const BACKEND_WORDS_CACHE_KEY = 'WELINE_FRAMEWORK_SYSTEM_WORDS_CACHE_KEY_BACKEND';
    private const BATCH_MARKER_PREFIX = 'WELINE_FRAMEWORK_SYSTEM_WORDS_BATCH_';
    private const BATCH_MARKER_TTL = 86400;
    private CachePoolInterface $cache;
    /**
     * @var \Weline\Framework\Http\Request
     */
    private Request $request;

    public function __construct(
        Request $request
    )
    {
        $this->cache = w_cache('i18n');
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $words = $this->normalizeWords(Parser::getUsedWordsWithTranslations());
        if (empty($words)) {
            return;
        }

        if ($this->request->isBackend()) {
            $this->mergeWords(self::BACKEND_WORDS_CACHE_KEY, $words);
        } else {
            $this->mergeWords(self::FRONTEND_WORDS_CACHE_KEY, $words);
        }
        $this->mergeWords(self::WORDS_CACHE_KEY, $words);
    }

    public function getWords(): array
    {
        $words = $this->cache->get(self::WORDS_CACHE_KEY);
        if (!is_array($words)) {
            $words = [];
        }
        return $words;
    }

    public function getBackendWords(): array
    {
        $words = $this->cache->get(self::BACKEND_WORDS_CACHE_KEY);
        if (!is_array($words)) {
            $words = [];
        }
        return $words;
    }

    public function getFrontendWords(): array
    {
        $words = $this->cache->get(self::FRONTEND_WORDS_CACHE_KEY);
        if (!is_array($words)) {
            $words = [];
        }
        return $words;
    }

    private function mergeWords(string $cacheKey, array $words): void
    {
        if (empty($words)) {
            return;
        }

        if ($this->isBatchAlreadyMerged($cacheKey, $words)) {
            return;
        }

        $existing = $this->cache->get($cacheKey);
        if (!is_array($existing)) {
            $existing = [];
        }

        $changed = false;
        foreach ($words as $word => $translation) {
            $word = trim((string)$word);
            if ($word === '') {
                continue;
            }

            $translation = is_string($translation) && $translation !== '' ? $translation : $word;
            if (!array_key_exists($word, $existing) || $existing[$word] !== $translation) {
                $existing[$word] = $translation;
                $changed = true;
            }
        }

        if ($changed) {
            $this->cache->set($cacheKey, $existing);
        }

        $this->markBatchMerged($cacheKey, $words);
    }

    /**
     * @param array<string, mixed> $words
     * @return array<string, string>
     */
    private function normalizeWords(array $words): array
    {
        $normalized = [];
        foreach ($words as $word => $translation) {
            $word = trim((string)$word);
            if ($word === '') {
                continue;
            }

            $normalized[$word] = is_string($translation) && $translation !== '' ? $translation : $word;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, string> $words
     */
    private function isBatchAlreadyMerged(string $cacheKey, array $words): bool
    {
        return (bool)$this->cache->get($this->buildBatchMarkerKey($cacheKey, $words));
    }

    /**
     * @param array<string, string> $words
     */
    private function markBatchMerged(string $cacheKey, array $words): void
    {
        $this->cache->set($this->buildBatchMarkerKey($cacheKey, $words), 1, self::BATCH_MARKER_TTL);
    }

    /**
     * @param array<string, string> $words
     */
    private function buildBatchMarkerKey(string $cacheKey, array $words): string
    {
        return self::BATCH_MARKER_PREFIX . $cacheKey . '_' . sha1(json_encode($words, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
