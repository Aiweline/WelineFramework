<?php

declare(strict_types=1);

namespace Weline\Geo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Geo\Model\Feed;
use Weline\Geo\Service\FeedEventDispatcher;
use Weline\Geo\Service\SeoProfileGeoMetadataNormalizer;

class FeedSubmitRequest implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            return;
        }

        $url = trim((string)($data['url'] ?? ''));
        $scope = trim((string)($data['scope'] ?? ''));
        if ($url === '' || $scope === '') {
            return;
        }

        try {
            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feeds = $feedModel->reset()
                ->where(Feed::schema_fields_IS_ENABLED, 1)
                ->select()
                ->fetchArray();

            if ($feeds === []) {
                return;
            }

            /** @var FeedEventDispatcher $dispatcher */
            $dispatcher = ObjectManager::getInstance(FeedEventDispatcher::class);
            $itemType = $this->itemType($scope, $data);
            $itemId = $this->itemId($url, $data);
            $itemData = [
                'title' => (string)($data['title'] ?? $data['meta_title'] ?? $url),
                'content' => (string)($data['content'] ?? $data['description'] ?? $data['meta_description'] ?? ''),
                'url' => $url,
                'metadata' => array_filter([
                    'scope' => $scope,
                    'subject_type' => $data['subject_type'] ?? null,
                    'subject_id' => $data['subject_id'] ?? $data['subject_entity_id'] ?? null,
                    'tags' => $data['tags'] ?? null,
                    'updated_at' => $data['updated_at'] ?? null,
                ], static fn($value): bool => $value !== null && $value !== ''),
                'is_published' => (int)($data['is_published'] ?? 1),
                'published_at' => $this->timestamp($data['published_at'] ?? $data['updated_at'] ?? null),
            ];
            $itemData = $this->normalizeFeedItemData($itemType, $url, $data, $itemData);

            foreach ($feeds as $feed) {
                $feedId = (int)($feed[Feed::schema_fields_ID] ?? $feed['id'] ?? 0);
                if ($feedId <= 0 || !$this->matchesFeed($feed, $scope, $itemType)) {
                    continue;
                }
                $dispatcher->dispatchFeedItemUpdate($feedId, $itemType, $itemId, $itemData);
            }
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV) {
                w_log_error('Weline_Geo feed submit failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function itemType(string $scope, array $data): string
    {
        $type = (string)($data['item_type'] ?? $data['subject_type'] ?? $scope);
        return trim($type) !== '' ? trim($type) : 'content';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function itemId(string $url, array $data): int
    {
        $id = (int)($data['item_id'] ?? $data['subject_id'] ?? $data['subject_entity_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        return (int)sprintf('%u', crc32($url));
    }

    /**
     * @param array<string, mixed> $feed
     */
    private function matchesFeed(array $feed, string $scope, string $itemType): bool
    {
        $feedType = (string)($feed[Feed::schema_fields_FEED_TYPE] ?? '');
        if ($feedType === '' || $feedType === Feed::TYPE_CONTENT || $feedType === Feed::TYPE_CUSTOM) {
            return true;
        }
        return $feedType === $scope || $feedType === $itemType;
    }

    private function timestamp(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        if (is_string($value) && trim($value) !== '') {
            return strtotime($value) ?: time();
        }
        return time();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $itemData
     * @return array<string, mixed>
     */
    private function normalizeFeedItemData(string $itemType, string $url, array $data, array $itemData): array
    {
        try {
            /** @var SeoProfileGeoMetadataNormalizer $normalizer */
            $normalizer = ObjectManager::getInstance(SeoProfileGeoMetadataNormalizer::class);
        } catch (\Throwable) {
            $normalizer = new SeoProfileGeoMetadataNormalizer();
        }

        $normalized = $normalizer->toFeedItemData(array_replace($data, [
            'page_type' => $data['page_type'] ?? $itemType,
            'title' => $itemData['title'],
            'description' => $itemData['content'],
            'canonical_url' => $url,
            'url' => $url,
            'geo' => $itemData['metadata'],
        ]));

        return array_replace($itemData, array_filter($normalized, static fn (mixed $value): bool => $value !== '' && $value !== []));
    }
}
