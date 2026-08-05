<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\SearchDegradeMarkerStoreInterface;
use Weline\Search\Model\SearchDegradeState;
use Weline\Search\Model\SearchShardKey;

/**
 * Durable marker store with writer-token CAS.
 */
final class DatabaseSearchDegradeMarkerStore implements SearchDegradeMarkerStoreInterface
{
    private const MAX_CAS_ATTEMPTS = 8;

    public function __construct(
        private readonly SearchDegradeState $state,
    ) {
    }

    public function get(int $websiteId): ?array
    {
        SearchShardKey::fromWebsiteId($websiteId);
        $row = $this->find($websiteId);

        return $row === null ? null : $this->normalize($row->getData());
    }

    public function mark(
        int $websiteId,
        string $reason,
        int $requiredSourceWatermark,
        int $indexWatermarkAtMark,
    ): array {
        SearchShardKey::fromWebsiteId($websiteId);
        $reason = \trim($reason);
        if ($reason === '' || \strlen($reason) > 64
            || $requiredSourceWatermark < 0
            || $indexWatermarkAtMark < 0
        ) {
            throw new \InvalidArgumentException('search_degrade_marker_invalid');
        }

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $current = $this->find($websiteId);
            $writerToken = \bin2hex(\random_bytes(32));
            $now = \gmdate('Y-m-d H:i:s');
            if ($current === null) {
                try {
                    $this->newState()->setData([
                        SearchDegradeState::schema_fields_WEBSITE_ID => $websiteId,
                        SearchDegradeState::schema_fields_ACTIVE => 1,
                        SearchDegradeState::schema_fields_REASON => $reason,
                        SearchDegradeState::schema_fields_REQUIRED_SOURCE_WATERMARK => $requiredSourceWatermark,
                        SearchDegradeState::schema_fields_INDEX_WATERMARK_AT_MARK => $indexWatermarkAtMark,
                        SearchDegradeState::schema_fields_MARKER_VERSION => 1,
                        SearchDegradeState::schema_fields_CAS_TOKEN => $writerToken,
                        SearchDegradeState::schema_fields_MARKED_AT => $now,
                        SearchDegradeState::schema_fields_CLEARED_AT => null,
                        SearchDegradeState::schema_fields_UPDATED_AT => $now,
                    ])->save();
                } catch (\Throwable $insertError) {
                    if ($this->find($websiteId) === null) {
                        throw $insertError;
                    }
                    continue;
                }
            } else {
                $version = (int)$current->getData(SearchDegradeState::schema_fields_MARKER_VERSION);
                $token = (string)$current->getData(SearchDegradeState::schema_fields_CAS_TOKEN);
                $required = \max(
                    $requiredSourceWatermark,
                    (int)$current->getData(
                        SearchDegradeState::schema_fields_REQUIRED_SOURCE_WATERMARK,
                    ),
                );
                $candidate = $this->newState()
                    ->where(SearchDegradeState::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(SearchDegradeState::schema_fields_MARKER_VERSION, $version)
                    ->where(SearchDegradeState::schema_fields_CAS_TOKEN, $token);
                $candidate->getQuery()->update([
                    SearchDegradeState::schema_fields_ACTIVE => 1,
                    SearchDegradeState::schema_fields_REASON => $reason,
                    SearchDegradeState::schema_fields_REQUIRED_SOURCE_WATERMARK => $required,
                    SearchDegradeState::schema_fields_INDEX_WATERMARK_AT_MARK => $indexWatermarkAtMark,
                    SearchDegradeState::schema_fields_MARKER_VERSION => $version + 1,
                    SearchDegradeState::schema_fields_CAS_TOKEN => $writerToken,
                    SearchDegradeState::schema_fields_MARKED_AT => $now,
                    SearchDegradeState::schema_fields_CLEARED_AT => null,
                    SearchDegradeState::schema_fields_UPDATED_AT => $now,
                ])->fetch();
            }

            $winner = $this->find($websiteId);
            if ($winner !== null
                && \hash_equals(
                    $writerToken,
                    (string)$winner->getData(SearchDegradeState::schema_fields_CAS_TOKEN),
                )
            ) {
                return $this->normalize($winner->getData());
            }
        }

        throw new SearchQueryException(
            SearchQueryException::ERROR_RECOVERY_CONFLICT,
            (string)__('Search 降级标记写入冲突'),
            ['website_id' => $websiteId],
        );
    }

    public function clearIfRecovered(
        int $websiteId,
        int $currentIndexWatermark,
        int $currentSourceWatermark,
    ): array {
        SearchShardKey::fromWebsiteId($websiteId);
        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $current = $this->find($websiteId);
            if ($current === null
                || (int)$current->getData(SearchDegradeState::schema_fields_ACTIVE) !== 1
            ) {
                return $current === null
                    ? $this->emptyState($websiteId)
                    : $this->normalize($current->getData());
            }
            $state = $this->normalize($current->getData());
            $this->assertRecovered($state, $currentIndexWatermark, $currentSourceWatermark);
            $writerToken = \bin2hex(\random_bytes(32));
            $version = (int)$state['marker_version'];
            $token = (string)$current->getData(SearchDegradeState::schema_fields_CAS_TOKEN);
            $now = \gmdate('Y-m-d H:i:s');
            $candidate = $this->newState()
                ->where(SearchDegradeState::schema_fields_WEBSITE_ID, $websiteId)
                ->where(SearchDegradeState::schema_fields_ACTIVE, 1)
                ->where(SearchDegradeState::schema_fields_MARKER_VERSION, $version)
                ->where(SearchDegradeState::schema_fields_CAS_TOKEN, $token);
            $candidate->getQuery()->update([
                SearchDegradeState::schema_fields_ACTIVE => 0,
                SearchDegradeState::schema_fields_MARKER_VERSION => $version + 1,
                SearchDegradeState::schema_fields_CAS_TOKEN => $writerToken,
                SearchDegradeState::schema_fields_CLEARED_AT => $now,
                SearchDegradeState::schema_fields_UPDATED_AT => $now,
            ])->fetch();
            $winner = $this->find($websiteId);
            if ($winner !== null
                && \hash_equals(
                    $writerToken,
                    (string)$winner->getData(SearchDegradeState::schema_fields_CAS_TOKEN),
                )
                && (int)$winner->getData(SearchDegradeState::schema_fields_ACTIVE) === 0
            ) {
                return $this->normalize($winner->getData());
            }
        }

        throw new SearchQueryException(
            SearchQueryException::ERROR_RECOVERY_CONFLICT,
            (string)__('Search 恢复标记 CAS 冲突'),
            ['website_id' => $websiteId],
        );
    }

    public function clearForRollback(int $websiteId, string $expectedReason): bool
    {
        SearchShardKey::fromWebsiteId($websiteId);
        $expectedReason = \trim($expectedReason);
        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $current = $this->find($websiteId);
            if ($current === null
                || (int)$current->getData(SearchDegradeState::schema_fields_ACTIVE) !== 1
            ) {
                return true;
            }
            if (!\hash_equals(
                (string)$current->getData(SearchDegradeState::schema_fields_REASON),
                $expectedReason,
            )) {
                return false;
            }
            $version = (int)$current->getData(SearchDegradeState::schema_fields_MARKER_VERSION);
            $token = (string)$current->getData(SearchDegradeState::schema_fields_CAS_TOKEN);
            $writerToken = \bin2hex(\random_bytes(32));
            $now = \gmdate('Y-m-d H:i:s');
            $candidate = $this->newState()
                ->where(SearchDegradeState::schema_fields_WEBSITE_ID, $websiteId)
                ->where(SearchDegradeState::schema_fields_ACTIVE, 1)
                ->where(SearchDegradeState::schema_fields_REASON, $expectedReason)
                ->where(SearchDegradeState::schema_fields_MARKER_VERSION, $version)
                ->where(SearchDegradeState::schema_fields_CAS_TOKEN, $token);
            $candidate->getQuery()->update([
                SearchDegradeState::schema_fields_ACTIVE => 0,
                SearchDegradeState::schema_fields_MARKER_VERSION => $version + 1,
                SearchDegradeState::schema_fields_CAS_TOKEN => $writerToken,
                SearchDegradeState::schema_fields_CLEARED_AT => $now,
                SearchDegradeState::schema_fields_UPDATED_AT => $now,
            ])->fetch();
            $winner = $this->find($websiteId);
            if ($winner !== null
                && \hash_equals(
                    $writerToken,
                    (string)$winner->getData(SearchDegradeState::schema_fields_CAS_TOKEN),
                )
            ) {
                return (int)$winner->getData(SearchDegradeState::schema_fields_ACTIVE) === 0;
            }
        }

        return false;
    }

    private function find(int $websiteId): ?SearchDegradeState
    {
        $state = $this->newState()
            ->where(SearchDegradeState::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();

        return $state->getId() ? $state : null;
    }

    private function newState(): SearchDegradeState
    {
        return (clone $this->state)->clearData()->clearQuery();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function normalize(array $data): array
    {
        return [
            'website_id' => (int)($data[SearchDegradeState::schema_fields_WEBSITE_ID] ?? -1),
            'active' => (int)($data[SearchDegradeState::schema_fields_ACTIVE] ?? 0) === 1,
            'reason' => (string)($data[SearchDegradeState::schema_fields_REASON] ?? ''),
            'required_source_watermark' => (int)(
                $data[SearchDegradeState::schema_fields_REQUIRED_SOURCE_WATERMARK] ?? 0
            ),
            'index_watermark_at_mark' => (int)(
                $data[SearchDegradeState::schema_fields_INDEX_WATERMARK_AT_MARK] ?? 0
            ),
            'marker_version' => (int)(
                $data[SearchDegradeState::schema_fields_MARKER_VERSION] ?? 0
            ),
            'marked_at' => (string)($data[SearchDegradeState::schema_fields_MARKED_AT] ?? ''),
            'cleared_at' => ($data[SearchDegradeState::schema_fields_CLEARED_AT] ?? null) !== null
                ? (string)$data[SearchDegradeState::schema_fields_CLEARED_AT]
                : null,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyState(int $websiteId): array
    {
        return [
            'website_id' => $websiteId,
            'active' => false,
            'reason' => '',
            'required_source_watermark' => 0,
            'index_watermark_at_mark' => 0,
            'marker_version' => 0,
            'marked_at' => '',
            'cleared_at' => null,
        ];
    }

    /** @param array<string,mixed> $state */
    private function assertRecovered(
        array $state,
        int $currentIndexWatermark,
        int $currentSourceWatermark,
    ): void {
        $required = (int)$state['required_source_watermark'];
        if ($currentIndexWatermark < $required
            || $currentSourceWatermark < $required
            || $currentIndexWatermark !== $currentSourceWatermark
        ) {
            throw new SearchQueryException(
                SearchQueryException::ERROR_RECOVERY_WATERMARK,
                (string)__('Search 恢复水位未与 Product current 追平'),
                [
                    'website_id' => $state['website_id'],
                    'required' => $required,
                    'index' => $currentIndexWatermark,
                    'source' => $currentSourceWatermark,
                ],
            );
        }
    }
}
