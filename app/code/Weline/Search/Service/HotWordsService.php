<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\RequestContext;
use Weline\Search\Model\SearchHotWord;

/**
 * Channel-scoped hot words from search_hot_word table.
 */
final class HotWordsService
{
    private const CACHE_POOL = 'search';
    private const FRESH_TTL_SECONDS = 300;
    private const STALE_TTL_SECONDS = 1800;

    public function __construct(
        private readonly SearchHotWord $hotWordModel,
        private readonly StorefrontScopeHotCache $hotCache,
    ) {
    }

    public static function logicalCacheKey(int $websiteId, int $storeId, int $channelId): string
    {
        return \sprintf(
            'search.hot_words.%d.%d.%d',
            max(0, $websiteId),
            max(0, $storeId),
            max(0, $channelId),
        );
    }

    public static function cachePool(): string
    {
        return self::CACHE_POOL;
    }

    /**
     * @return array{words:list<string>,source:string,success:bool,is_demo?:bool}
     */
    public function resolve(int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        $scope = RequestContext::scopeMetadata();
        if (!\is_array($scope) || (int)($scope['channel_id'] ?? 0) < 1) {
            return [
                'success' => true,
                'words' => [],
                'source' => 'empty',
            ];
        }

        $websiteId = (int)($scope['website_id'] ?? 0);
        $storeId = (int)($scope['store_id'] ?? 0);
        $channelId = (int)($scope['channel_id'] ?? 0);
        $logicalKey = self::logicalCacheKey($websiteId, $storeId, $channelId);

        try {
            /** @var list<string> $words */
            $words = $this->hotCache->remember(
                self::CACHE_POOL,
                $logicalKey,
                self::FRESH_TTL_SECONDS,
                fn(): array => $this->loadWords($websiteId, $storeId, $channelId, $limit),
                ['website' => true],
                self::STALE_TTL_SECONDS,
            );
            if ($words !== []) {
                return [
                    'success' => true,
                    'words' => \array_slice($words, 0, $limit),
                    'source' => 'search_hot_word',
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'success' => true,
            'words' => [],
            'source' => 'empty',
        ];
    }

    /**
     * @return list<string>
     */
    private function loadWords(int $websiteId, int $storeId, int $channelId, int $limit): array
    {
        $rows = $this->hotWordModel->reset()
            ->where([
                SearchHotWord::schema_fields_WEBSITE_ID => $websiteId,
                SearchHotWord::schema_fields_STORE_ID => $storeId,
                SearchHotWord::schema_fields_CHANNEL_ID => $channelId,
                SearchHotWord::schema_fields_IS_ACTIVE => 1,
            ])
            ->order(SearchHotWord::schema_fields_SORT_ORDER, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        $words = [];
        foreach ($rows as $row) {
            $word = \trim((string)($row[SearchHotWord::schema_fields_WORD] ?? ''));
            if ($word !== '' && self::isDisplayableHotWord($word)) {
                $words[] = $word;
            }
        }

        return $words;
    }

    private static function isDisplayableHotWord(string $word): bool
    {
        if (\mb_strlen($word) < 2) {
            return false;
        }
        if (\preg_match('/^[A-Z0-9]+-(STORE|CHANNEL|WEBSITE)-/i', $word)) {
            return false;
        }
        if (\preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $word)) {
            return false;
        }

        return true;
    }
}
