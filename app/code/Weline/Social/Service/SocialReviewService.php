<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Model\SocialCreativeDraft;
use Weline\Social\Model\SocialMediaAsset;

class SocialReviewService
{
    public const REVIEW_NONE = 'none';
    public const REVIEW_PENDING = 'pending';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_REJECTED = 'rejected';

    public function __construct(
        private readonly ?ObjectManager $objectManager = null
    ) {
    }

    /**
     * @return array{ok:bool,draft_id:int,review_status:string,error:string}
     */
    public function submit(int $draftId): array
    {
        return $this->decide($draftId, 0, 'submit', '');
    }

    /**
     * @return array{ok:bool,draft_id:int,review_status:string,error:string}
     */
    public function approve(int $draftId, int $userId): array
    {
        return $this->decide($draftId, $userId, 'approve', '');
    }

    /**
     * @return array{ok:bool,draft_id:int,review_status:string,error:string}
     */
    public function reject(int $draftId, int $userId, string $reason): array
    {
        return $this->decide($draftId, $userId, 'reject', $reason);
    }

    /**
     * 草稿表当前没有 website_id 列，$websiteId 保留给调用方对齐作用域，不参与过滤。
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPending(int $websiteId = 0, int $limit = 20): array
    {
        $limit = \max(1, \min(100, $limit));
        $rows = $this->newDraft()->reset()
            ->where(SocialCreativeDraft::schema_fields_REVIEW_STATUS, self::REVIEW_PENDING)
            ->order(SocialCreativeDraft::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetch();

        $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
        if (!\is_array($items)) {
            return [];
        }

        $drafts = [];
        foreach ($items as $item) {
            if ($item instanceof SocialCreativeDraft) {
                $drafts[] = $this->summary($item);
                continue;
            }
            if (\is_array($item)) {
                $drafts[] = $this->summaryFromArray($item);
            }
        }

        return $drafts;
    }

    /**
     * 发布前配乐许可闸门：任一 audio 素材必须四件套齐全。
     *
     * @return array{ok:bool,error:string}
     */
    public function assertPublishable(int $draftId): array
    {
        $assets = $this->loadDraftAssets($draftId);
        if ($assets === null) {
            return ['ok' => false, 'error' => 'draft_not_found'];
        }

        return self::assertAssetsPublishable($assets);
    }

    /**
     * @return array<int, mixed>|null
     */
    protected function loadDraftAssets(int $draftId): ?array
    {
        $draft = $this->loadDraft($draftId);
        if (!$draft instanceof SocialCreativeDraft) {
            return null;
        }

        $decoded = \json_decode(
            (string)$draft->getData(SocialCreativeDraft::schema_fields_ASSETS_JSON),
            true
        );

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, mixed> $assets
     * @return array{ok:bool,error:string}
     */
    public static function assertAssetsPublishable(array $assets): array
    {
        foreach ($assets as $asset) {
            if (!\is_array($asset)) {
                continue;
            }
            if (\strtolower(\trim((string)($asset['kind'] ?? ''))) !== SocialMediaAsset::KIND_AUDIO) {
                continue;
            }
            foreach (['license', 'source_url', 'attribution', 'license_confirmed_at'] as $key) {
                if (\trim((string)($asset[$key] ?? '')) === '') {
                    return ['ok' => false, 'error' => 'music_unlicensed'];
                }
            }
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @return array{ok:bool,status:string,error:string}
     */
    public static function transition(string $currentStatus, string $decision): array
    {
        $current = self::normalizeStatus($currentStatus);
        $decision = \strtolower(\trim($decision));
        if (!\in_array($current, [
            self::REVIEW_NONE,
            self::REVIEW_PENDING,
            self::REVIEW_APPROVED,
            self::REVIEW_REJECTED,
        ], true)) {
            return ['ok' => false, 'status' => $current, 'error' => 'invalid_status'];
        }

        if ($decision === 'submit') {
            if ($current === self::REVIEW_APPROVED) {
                return ['ok' => false, 'status' => $current, 'error' => 'already_decided'];
            }
            if ($current === self::REVIEW_PENDING) {
                return ['ok' => false, 'status' => $current, 'error' => 'already_pending'];
            }

            return ['ok' => true, 'status' => self::REVIEW_PENDING, 'error' => ''];
        }

        if ($decision === 'approve' || $decision === 'reject') {
            $decided = \in_array($current, [self::REVIEW_APPROVED, self::REVIEW_REJECTED], true);
            if ($decided) {
                return ['ok' => false, 'status' => $current, 'error' => 'already_decided'];
            }
            if ($current !== self::REVIEW_PENDING) {
                return ['ok' => false, 'status' => $current, 'error' => 'not_pending'];
            }

            return [
                'ok' => true,
                'status' => $decision === 'approve' ? self::REVIEW_APPROVED : self::REVIEW_REJECTED,
                'error' => '',
            ];
        }

        return ['ok' => false, 'status' => $current, 'error' => 'invalid_decision'];
    }

    /**
     * @return array{ok:bool,draft_id:int,review_status:string,error:string}
     */
    private function decide(int $draftId, int $userId, string $decision, string $reason): array
    {
        $draft = $this->loadDraft($draftId);
        if (!$draft instanceof SocialCreativeDraft) {
            return $this->result(false, $draftId, self::REVIEW_NONE, 'draft_not_found');
        }

        $current = self::normalizeStatus((string)$draft->getData(SocialCreativeDraft::schema_fields_REVIEW_STATUS));
        $transition = self::transition($current, $decision);
        if (!$transition['ok']) {
            return $this->result(false, $draftId, $current, (string)$transition['error']);
        }
        if ($decision !== 'submit') {
            if ($userId <= 0) {
                return $this->result(false, $draftId, $current, 'invalid_user');
            }
            if ($decision === 'reject' && \trim($reason) === '') {
                return $this->result(false, $draftId, $current, 'missing_reason');
            }
        }

        $draft->setData(SocialCreativeDraft::schema_fields_REVIEW_STATUS, (string)$transition['status'])
            ->setData(SocialCreativeDraft::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
            ->save();

        return $this->result(true, $draftId, (string)$transition['status'], '');
    }

    private function loadDraft(int $draftId): ?SocialCreativeDraft
    {
        if ($draftId <= 0) {
            return null;
        }

        $draft = $this->newDraft();
        $draft->load($draftId);

        return $draft->getId() ? $draft : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(SocialCreativeDraft $draft): array
    {
        return [
            'draft_id' => (int)$draft->getId(),
            'title' => (string)$draft->getData(SocialCreativeDraft::schema_fields_TITLE),
            'content' => (string)$draft->getData(SocialCreativeDraft::schema_fields_CONTENT),
            'review_status' => self::normalizeStatus((string)$draft->getData(SocialCreativeDraft::schema_fields_REVIEW_STATUS)),
            'topic_source' => (string)$draft->getData(SocialCreativeDraft::schema_fields_TOPIC_SOURCE),
            'topic_source_url' => (string)$draft->getData(SocialCreativeDraft::schema_fields_TOPIC_SOURCE_URL),
            'created_at' => (string)$draft->getData(SocialCreativeDraft::schema_fields_CREATED_AT),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function summaryFromArray(array $item): array
    {
        return [
            'draft_id' => (int)($item[SocialCreativeDraft::schema_fields_ID] ?? 0),
            'title' => (string)($item[SocialCreativeDraft::schema_fields_TITLE] ?? ''),
            'content' => (string)($item[SocialCreativeDraft::schema_fields_CONTENT] ?? ''),
            'review_status' => self::normalizeStatus((string)($item[SocialCreativeDraft::schema_fields_REVIEW_STATUS] ?? '')),
            'topic_source' => (string)($item[SocialCreativeDraft::schema_fields_TOPIC_SOURCE] ?? ''),
            'topic_source_url' => (string)($item[SocialCreativeDraft::schema_fields_TOPIC_SOURCE_URL] ?? ''),
            'created_at' => (string)($item[SocialCreativeDraft::schema_fields_CREATED_AT] ?? ''),
        ];
    }

    private static function normalizeStatus(string $status): string
    {
        $status = \strtolower(\trim($status));

        return $status === '' ? self::REVIEW_NONE : $status;
    }

    /**
     * @return array{ok:bool,draft_id:int,review_status:string,error:string}
     */
    private function result(bool $ok, int $draftId, string $reviewStatus, string $error): array
    {
        return [
            'ok' => $ok,
            'draft_id' => $draftId,
            'review_status' => $reviewStatus,
            'error' => $error,
        ];
    }

    private function newDraft(): SocialCreativeDraft
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(SocialCreativeDraft::class);
    }
}
