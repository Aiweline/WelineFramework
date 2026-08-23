<?php

declare(strict_types=1);

namespace Weline\Review\Service;

use Weline\Framework\Registry\Service\RegistryModulePresence;
use Weline\Review\Model\ProductReview;

/**
 * 评论 AI 预审：先 AI，拿不准才转人工。
 *
 * pending → approved | rejected | ai_pending_blocked（+ w_msg）
 */
final class ReviewAiModerationService
{
    public const DECISION_APPROVE = 'approve';
    public const DECISION_REJECT = 'reject';
    public const DECISION_UNCERTAIN = 'uncertain';

    private const EXTRA_AI_KEY = '_ai_moderation';
    private const DEFAULT_BATCH = 20;

    public function __construct(
        private readonly ProductReview $reviews,
        private readonly ReviewMediaService $media,
        /** @var null|callable(string):(?string) */
        private $aiTextGenerator = null,
    ) {
    }

    /**
     * @return array{
     *   scanned:int,
     *   approved:int,
     *   rejected:int,
     *   uncertain:int,
     *   skipped:int,
     *   errors:int,
     *   ai_available:bool
     * }
     */
    public function processPendingBatch(int $limit = self::DEFAULT_BATCH): array
    {
        $limit = max(1, min(100, $limit));
        $stats = [
            'scanned' => 0,
            'approved' => 0,
            'rejected' => 0,
            'uncertain' => 0,
            'skipped' => 0,
            'errors' => 0,
            'ai_available' => $this->isAiAvailable(),
        ];

        if (!$stats['ai_available']) {
            return $stats;
        }

        $query = $this->reviews->reset()
            ->where(ProductReview::schema_fields_STATUS, ProductReview::STATUS_PENDING)
            ->order(ProductReview::schema_fields_CREATED_AT, 'ASC')
            ->pagination(1, $limit)
            ->select()
            ->fetch();

        foreach ($query->getItems() as $review) {
            if (!$review instanceof ProductReview) {
                continue;
            }
            $stats['scanned']++;
            try {
                $outcome = $this->moderateOne($review);
                $stats[$outcome]++;
            } catch (\Throwable) {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * @return 'approved'|'rejected'|'uncertain'|'skipped'
     */
    public function moderateOne(ProductReview $review): string
    {
        $reviewId = (int)($review->getId() ?? 0);
        if ($reviewId <= 0) {
            return 'skipped';
        }
        $status = strtolower(trim((string)$review->getData(ProductReview::schema_fields_STATUS)));
        if ($status !== ProductReview::STATUS_PENDING) {
            return 'skipped';
        }

        try {
            $raw = $this->askAi($review);
        } catch (\Throwable) {
            // 调用失败保留 pending，下一轮 Cron 重试，不打扰人工
            return 'skipped';
        }
        if ($raw === null || trim($raw) === '') {
            return 'skipped';
        }

        $decision = $this->parseDecision($raw);
        $now = date('Y-m-d H:i:s');
        $extra = $this->decodeExtra($review);
        $extra[self::EXTRA_AI_KEY] = [
            'decision' => $decision['decision'],
            'reason' => $decision['reason'],
            'decided_at' => $now,
            'raw' => $decision['raw'],
        ];

        if ($decision['decision'] === self::DECISION_APPROVE) {
            $this->persistStatus($review, ProductReview::STATUS_APPROVED, $extra, $now);

            return 'approved';
        }
        if ($decision['decision'] === self::DECISION_REJECT) {
            $this->persistStatus($review, ProductReview::STATUS_REJECTED, $extra, $now);

            return 'rejected';
        }

        $this->persistStatus($review, ProductReview::STATUS_AI_PENDING_BLOCKED, $extra, $now);
        $this->notifyHuman($reviewId, $decision['reason']);

        return 'uncertain';
    }

    /**
     * @return array{decision:string,reason:string,raw:string}
     */
    public function parseDecision(string $raw): array
    {
        $raw = trim($raw);
        $payload = $this->extractJsonObject($raw);
        $decision = strtolower(trim((string)($payload['decision'] ?? '')));
        $reason = trim((string)($payload['reason'] ?? ''));

        if (!in_array($decision, [self::DECISION_APPROVE, self::DECISION_REJECT, self::DECISION_UNCERTAIN], true)) {
            $decision = self::DECISION_UNCERTAIN;
            if ($reason === '') {
                $reason = 'AI 返回无法解析，需人工确认。';
                if (\function_exists('__')) {
                    $reason = (string)__('AI 返回无法解析，需人工确认。');
                }
            }
        }
        if ($reason === '') {
            $reason = '未提供理由。';
            if (\function_exists('__')) {
                $reason = (string)__('未提供理由。');
            }
        }

        return [
            'decision' => $decision,
            'reason' => mb_substr($reason, 0, 500),
            'raw' => mb_substr($raw, 0, 2000),
        ];
    }

    public function isAiAvailable(): bool
    {
        return RegistryModulePresence::isActivePresent('Weline_Ai');
    }

    /**
     * @throws \Throwable AI 传输/服务失败时抛出，供调用方保留 pending
     */
    private function askAi(ProductReview $review): ?string
    {
        $prompt = $this->buildPrompt($review);
        if (is_callable($this->aiTextGenerator)) {
            $raw = ($this->aiTextGenerator)($prompt);

            return is_string($raw) ? $raw : null;
        }
        $raw = w_query('ai', 'generateText', [
            'prompt' => $prompt,
            'is_backend' => true,
            'params' => [
                'temperature' => 0.1,
                'max_tokens' => 400,
            ],
        ]);
        if (!is_string($raw)) {
            return null;
        }

        return $raw;
    }

    private function buildPrompt(ProductReview $review): string
    {
        $reviewId = (int)($review->getId() ?? 0);
        $title = trim((string)$review->getData(ProductReview::schema_fields_TITLE));
        $content = trim((string)$review->getData(ProductReview::schema_fields_CONTENT));
        $rating = (int)$review->getData(ProductReview::schema_fields_RATING);
        $anonymous = (bool)$review->getData(ProductReview::schema_fields_IS_ANONYMOUS);
        $name = $anonymous
            ? (string)__('匿名用户')
            : (string)($review->getData(ProductReview::schema_fields_REVIEWER_NAME) ?: __('游客'));
        $media = $this->media->forReview($reviewId);
        $mediaSummary = [];
        foreach ($media as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mediaSummary[] = (string)($item['kind'] ?? 'file');
        }
        $mediaText = $mediaSummary === [] ? (string)__('无媒体') : implode(',', $mediaSummary);

        return <<<PROMPT
你是电商评论审核助手。请判断下列评论是否适合公开展示。
只输出一个 JSON 对象，不要 Markdown，不要多余文字。字段：
- decision: approve | reject | uncertain
- reason: 简短中文理由

判定规则：
- approve：内容正常、无明显违规（辱骂、色情、广告灌水、明显虚假刷单、违法）
- reject：明确违规或不适合公开
- uncertain：证据不足、语义暧昧、需要人工判断

评论 ID：{$reviewId}
评论者：{$name}
评分：{$rating}/5
标题：{$title}
正文：{$content}
媒体：{$mediaText}
PROMPT;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeExtra(ProductReview $review): array
    {
        $extra = json_decode((string)$review->getData(ProductReview::schema_fields_EXTRA), true);

        return is_array($extra) ? $extra : [];
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function persistStatus(ProductReview $review, string $status, array $extra, string $now): void
    {
        $review
            ->setData(ProductReview::schema_fields_STATUS, $status)
            ->setData(ProductReview::schema_fields_EXTRA, json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
            ->setData(ProductReview::schema_fields_UPDATED_AT, $now)
            ->save();
    }

    private function notifyHuman(int $reviewId, string $reason): void
    {
        if (!function_exists('w_msg')) {
            return;
        }
        try {
            w_msg(
                'review_ai_pending_blocked',
                'warning',
                (string)__('评论需人工审核'),
                (string)__('评论 #%{1} AI 无法明确判定，请到评论管理处理。理由：%{2}', [$reviewId, $reason]),
                [
                    'priority' => 7,
                    'icon' => 'comment-alert',
                    'dedupe_key' => 'review_ai_blocked_' . $reviewId,
                    'source_module' => 'Weline_Review',
                    'metadata' => [
                        'review_id' => $reviewId,
                        'status' => ProductReview::STATUS_AI_PENDING_BLOCKED,
                    ],
                ]
            );
        } catch (\Throwable) {
            // 通知失败不回滚审核状态
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function extractJsonObject(string $raw): array
    {
        $trimmed = trim($raw);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{[\s\S]*\}/', $trimmed, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
