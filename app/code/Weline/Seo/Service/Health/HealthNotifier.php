<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

use Weline\Framework\Manager\ObjectManager;

/**
 * 把一轮探测 + 自动处理汇总成一条 MSG；同一站点每天去重累计。
 */
final class HealthNotifier
{
    public const TOPIC = 'seo_sitemap';

    /**
     * @param array<string, mixed> $probe
     * @param array<string, mixed> $decisions
     * @param array<string, mixed> $applied
     */
    public function notifyRun(int $websiteId, array $probe, array $decisions, array $applied): void
    {
        $payload = $this->buildPayload($websiteId, $probe, $decisions, $applied);
        if ($payload === null) {
            return;
        }

        try {
            w_msg(
                self::TOPIC,
                (string)$payload['type'],
                (string)$payload['title'],
                (string)$payload['content'],
                [
                    'source_module' => 'Weline_Seo',
                    'icon' => 'search',
                    'dedupe_key' => (string)$payload['dedupe_key'],
                    'metadata' => (array)$payload['metadata'],
                ]
            );
        } catch (\Throwable $e) {
            w_log_error('[Weline_Seo] health notify failed: ' . $e->getMessage());
        }
    }

    /**
     * 纯构造，便于单测；无问题时返回 null。
     *
     * @param array<string, mixed> $probe
     * @param array<string, mixed> $decisions
     * @param array<string, mixed> $applied
     * @return array{topic:string,type:string,title:string,content:string,dedupe_key:string,metadata:array<string,mixed>}|null
     */
    public function buildPayload(int $websiteId, array $probe, array $decisions, array $applied): ?array
    {
        $issues = (int)($probe['issues'] ?? 0);
        $planned = (int)($decisions['planned'] ?? 0);
        $restores = (int)($decisions['restores'] ?? 0);
        $appliedCount = (int)($applied['applied'] ?? 0);
        $failed = (int)($applied['failed'] ?? 0);
        $brake = !empty($decisions['brake']);

        if ($issues <= 0 && $planned <= 0 && $restores <= 0) {
            return null;
        }

        $byIssue = (array)($probe['by_issue'] ?? []);
        $parts = [];
        foreach ($byIssue as $code => $count) {
            $parts[] = HealthIssueCatalog::label((string)$code) . ' ' . (int)$count;
        }
        $issueSummary = $parts !== [] ? implode('、', array_slice($parts, 0, 6)) : '—';

        $title = (string)__('SEO 索引健康：站点 #%{1}', [$websiteId]);
        $content = (string)__(
            '发现问题 %{1} 条：%{2}。本轮计划处理 %{3} 条，已生效 %{4} 条，恢复 %{5} 条，失败 %{6} 条。',
            [$issues, $issueSummary, $planned, $appliedCount, $restores, $failed]
        );
        if ($brake) {
            $content .= ' ' . (string)__('安全阀已触发：%{1}', [(string)($decisions['brake_reason'] ?? '')]);
        }

        $reportUrl = '';
        try {
            $reportUrl = (string)ObjectManager::getInstance(\Weline\Framework\Http\Url::class)
                ->getBackendUrl('seo/backend/health', ['website_id' => $websiteId]);
        } catch (\Throwable) {
            $reportUrl = '';
        }
        if ($reportUrl !== '') {
            $content .= ' ' . (string)__('查看：%{1}', [$reportUrl]);
        }

        return [
            'topic' => self::TOPIC,
            'type' => $brake || $failed > 0 ? 'error' : 'warning',
            'title' => $title,
            'content' => $content,
            'dedupe_key' => sprintf('seo_health:%d:%s', $websiteId, date('Y-m-d')),
            'metadata' => [
                'website_id' => $websiteId,
                'report_url' => $reportUrl,
                'issues' => $issues,
                'by_issue' => $byIssue,
                'planned' => $planned,
                'applied' => $appliedCount,
                'restores' => $restores,
                'failed' => $failed,
                'brake' => $brake,
            ],
        ];
    }
}
