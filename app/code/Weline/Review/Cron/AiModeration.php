<?php

declare(strict_types=1);

namespace Weline\Review\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Review\Service\ReviewAiModerationService;

/**
 * 扫描 pending 评论：AI 明确则自动通过/拒绝，拿不准转人工。
 */
final class AiModeration implements CronTaskInterface
{
    public function __construct(private readonly ReviewAiModerationService $moderation)
    {
    }

    public function name(): string
    {
        return (string)__('评论 AI 预审');
    }

    public function execute_name(): string
    {
        return 'weline_review_ai_moderation';
    }

    public function tip(): string
    {
        return (string)__('先由 AI 审核 pending 评论；仅拿不准时转人工');
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function unlock_timeout(int $minute = 10): int
    {
        return max(5, $minute);
    }

    public function execute(): string
    {
        $stats = $this->moderation->processPendingBatch(20);
        if (!$stats['ai_available']) {
            return (string)__('评论 AI 预审跳过：Weline_Ai 未启用');
        }

        return (string)__(
            '评论 AI 预审完成：扫描 %{1}，通过 %{2}，拒绝 %{3}，转人工 %{4}，跳过 %{5}，错误 %{6}',
            [
                $stats['scanned'],
                $stats['approved'],
                $stats['rejected'],
                $stats['uncertain'],
                $stats['skipped'],
                $stats['errors'],
            ]
        );
    }
}
