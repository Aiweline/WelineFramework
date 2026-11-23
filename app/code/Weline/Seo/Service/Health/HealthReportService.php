<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

use Weline\Seo\Model\HealthRun;
use Weline\Seo\Model\SitemapAction;
use Weline\Seo\Model\UrlHealth;

/**
 * 索引健康页面的查询层：概览、分类 Tab 计数、列表、跑批记录、动作台账。
 */
final class HealthReportService
{
    public function __construct(
        private UrlHealth $healthModel,
        private HealthRun $runModel,
        private SitemapAction $actionModel
    ) {
    }

    /**
     * @param array{issue?:string, q?:string, state?:string, locale?:string} $filters
     * @return array<string, mixed>
     */
    public function summary(int $websiteId, array $filters = []): array
    {
        // 概览与分类 Tab 计数不受当前选中的 issue 影响，否则点进 404 后其它 Tab 全变 0。
        unset($filters['issue']);
        $query = $this->buildFilters($websiteId, $filters);
        $byIssue = [];
        $byState = [];
        $total = 0;
        $lastChecked = '';

        foreach ($query->select('issue_code,state,last_checked_at')->fetchIterator() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $total++;
            $issue = (string)($row[UrlHealth::schema_fields_ISSUE_CODE] ?? HealthIssueCatalog::UNKNOWN);
            $state = (string)($row[UrlHealth::schema_fields_STATE] ?? UrlHealth::STATE_OPEN);
            $byIssue[$issue] = (int)($byIssue[$issue] ?? 0) + 1;
            $byState[$state] = (int)($byState[$state] ?? 0) + 1;
            $checked = (string)($row[UrlHealth::schema_fields_LAST_CHECKED_AT] ?? '');
            if ($checked > $lastChecked) {
                $lastChecked = $checked;
            }
        }

        $healthy = (int)($byIssue[HealthIssueCatalog::OK] ?? 0);

        return [
            'total' => $total,
            'healthy' => $healthy,
            'issues' => max(0, $total - $healthy),
            'by_issue' => $byIssue,
            'by_state' => $byState,
            'open' => (int)($byState[UrlHealth::STATE_OPEN] ?? 0),
            'fixed' => (int)($byState[UrlHealth::STATE_FIXED] ?? 0),
            'ignored' => (int)($byState[UrlHealth::STATE_IGNORED] ?? 0),
            'auto_removed' => (int)($byState[UrlHealth::STATE_AUTO_REMOVED] ?? 0),
            'last_checked_at' => $lastChecked,
        ];
    }

    /**
     * @param array{issue?:string, q?:string, state?:string, locale?:string} $filters
     * @return list<array{code:string, label:string, count:int, tone:string, active:bool}>
     */
    public function tabs(int $websiteId, array $filters = []): array
    {
        $summary = $this->summary($websiteId, $filters);
        $counts = (array)($summary['by_issue'] ?? []);
        $activeIssue = (string)($filters['issue'] ?? '');
        $activeGroup = $activeIssue === '' || $activeIssue === 'all'
            ? 'all'
            : (HealthIssueCatalog::isGroupCode($activeIssue)
                ? $activeIssue
                : HealthIssueCatalog::groupForIssue($activeIssue));

        $tabs = [];
        foreach (HealthIssueCatalog::tabGroups() as $group) {
            $count = 0;
            if ($group['code'] === 'all') {
                $count = (int)($summary['total'] ?? 0);
            } else {
                foreach ($group['codes'] as $code) {
                    $count += (int)($counts[$code] ?? 0);
                }
            }
            $tabs[] = [
                'code' => (string)$group['code'],
                'label' => (string)$group['label'],
                'count' => $count,
                'tone' => (string)$group['tone'],
                'active' => $activeGroup === $group['code'],
            ];
        }

        return $tabs;
    }

    /**
     * @param array{issue?:string, q?:string, state?:string, locale?:string} $filters
     * @return array{rows:list<array<string, mixed>>, total:int, page:int, per_page:int, pages:int}
     */
    public function list(int $websiteId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = min(200, max(5, $perPage));

        $total = $this->buildFilters($websiteId, $filters)->total();
        $query = $this->buildFilters($websiteId, $filters)
            ->select()
            ->order(UrlHealth::schema_fields_LAST_CHECKED_AT, 'DESC')
            ->order(UrlHealth::schema_fields_ID, 'DESC')
            ->limit($perPage, ($page - 1) * $perPage);

        $rows = [];
        foreach ($query->fetchArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $issueCode = (string)($row[UrlHealth::schema_fields_ISSUE_CODE] ?? HealthIssueCatalog::UNKNOWN);
            $state = (string)($row[UrlHealth::schema_fields_STATE] ?? UrlHealth::STATE_OPEN);
            $redirectChain = json_decode((string)($row[UrlHealth::schema_fields_REDIRECT_CHAIN] ?? ''), true);
            $rows[] = [
                'health_id' => (int)($row[UrlHealth::schema_fields_ID] ?? 0),
                'url' => (string)($row[UrlHealth::schema_fields_URL] ?? ''),
                'url_hash' => (string)($row[UrlHealth::schema_fields_URL_HASH] ?? ''),
                'module' => (string)($row[UrlHealth::schema_fields_MODULE] ?? ''),
                'locale' => (string)($row[UrlHealth::schema_fields_LOCALE] ?? ''),
                'sitemap_url_id' => (int)($row[UrlHealth::schema_fields_SITEMAP_URL_ID] ?? 0),
                'issue_code' => $issueCode,
                'issue_label' => HealthIssueCatalog::label($issueCode),
                'tone' => HealthIssueCatalog::severityTone($issueCode),
                'http_status' => (int)($row[UrlHealth::schema_fields_HTTP_STATUS] ?? 0),
                'state' => $state,
                'state_label' => $this->stateLabel($state),
                'first_seen_at' => (string)($row[UrlHealth::schema_fields_FIRST_SEEN_AT] ?? ''),
                'last_checked_at' => (string)($row[UrlHealth::schema_fields_LAST_CHECKED_AT] ?? ''),
                'consecutive_failures' => (int)($row[UrlHealth::schema_fields_CONSECUTIVE_FAILURES] ?? 0),
                'final_url' => (string)($row[UrlHealth::schema_fields_FINAL_URL] ?? ''),
                'redirect_count' => is_array($redirectChain) ? count($redirectChain) : 0,
                'robots_state' => (string)($row[UrlHealth::schema_fields_ROBOTS_STATE] ?? 'unknown'),
                'response_ms' => (int)($row[UrlHealth::schema_fields_RESPONSE_MS] ?? 0),
            ];
        }

        return [
            'rows' => $rows,
            'total' => (int)$total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int)max(1, (int)ceil($total / $perPage)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function runs(int $websiteId, int $limit = 20): array
    {
        $limit = min(100, max(1, $limit));
        $query = $this->runModel->reset()
            ->where(HealthRun::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->order(HealthRun::schema_fields_STARTED_AT, 'DESC')
            ->limit($limit);

        $rows = [];
        foreach ($query->fetchArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $byIssue = json_decode((string)($row[HealthRun::schema_fields_BY_ISSUE] ?? ''), true);
            $rows[] = [
                'run_id' => (int)($row[HealthRun::schema_fields_ID] ?? 0),
                'trigger_type' => (string)($row[HealthRun::schema_fields_TRIGGER_TYPE] ?? ''),
                'status' => (string)($row[HealthRun::schema_fields_STATUS] ?? ''),
                'probed' => (int)($row[HealthRun::schema_fields_PROBED] ?? 0),
                'ok' => (int)($row[HealthRun::schema_fields_OK_COUNT] ?? 0),
                'issues' => (int)($row[HealthRun::schema_fields_ISSUE_COUNT] ?? 0),
                'actions_applied' => (int)($row[HealthRun::schema_fields_ACTIONS_APPLIED] ?? 0),
                'by_issue' => is_array($byIssue) ? $byIssue : [],
                'duration_ms' => (int)($row[HealthRun::schema_fields_DURATION_MS] ?? 0),
                'started_at' => (string)($row[HealthRun::schema_fields_STARTED_AT] ?? ''),
                'finished_at' => (string)($row[HealthRun::schema_fields_FINISHED_AT] ?? ''),
                'error' => (string)($row[HealthRun::schema_fields_ERROR] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestRun(int $websiteId): ?array
    {
        $runs = $this->runs($websiteId, 1);

        return $runs[0] ?? null;
    }

    /**
     * 动作台账（最近 N 条）。
     *
     * @return list<array<string, mixed>>
     */
    public function actions(int $websiteId, int $limit = 50): array
    {
        $limit = min(200, max(1, $limit));
        $query = $this->actionModel->reset()
            ->where(SitemapAction::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->order(SitemapAction::schema_fields_CREATED_AT, 'DESC')
            ->order(SitemapAction::schema_fields_ID, 'DESC')
            ->limit($limit);

        $rows = [];
        foreach ($query->fetchArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'action_id' => (int)($row[SitemapAction::schema_fields_ID] ?? 0),
                'url' => (string)($row[SitemapAction::schema_fields_URL] ?? ''),
                'issue_code' => (string)($row[SitemapAction::schema_fields_ISSUE_CODE] ?? ''),
                'action_type' => (string)($row[SitemapAction::schema_fields_ACTION_TYPE] ?? ''),
                'status' => (string)($row[SitemapAction::schema_fields_STATUS] ?? ''),
                'source' => (string)($row[SitemapAction::schema_fields_SOURCE] ?? ''),
                'run_id' => (int)($row[SitemapAction::schema_fields_RUN_ID] ?? 0),
                'reason' => (string)($row[SitemapAction::schema_fields_REASON] ?? ''),
                'applied_at' => (string)($row[SitemapAction::schema_fields_APPLIED_AT] ?? ''),
                'revert_at' => (string)($row[SitemapAction::schema_fields_REVERT_AT] ?? ''),
                'created_at' => (string)($row[SitemapAction::schema_fields_CREATED_AT] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param array{issue?:string, q?:string, state?:string, locale?:string} $filters
     * @return mixed
     */
    private function buildFilters(int $websiteId, array $filters)
    {
        $query = (clone $this->healthModel)->reset()
            ->where(UrlHealth::schema_fields_WEBSITE_ID, $websiteId);

        $issue = trim((string)($filters['issue'] ?? ''));
        if ($issue !== '' && $issue !== 'all') {
            if (HealthIssueCatalog::isGroupCode($issue)) {
                $codes = HealthIssueCatalog::groupCodes($issue);
                if ($codes !== []) {
                    $query->where(UrlHealth::schema_fields_ISSUE_CODE, $codes, 'IN');
                }
            } elseif (HealthIssueCatalog::isKnown($issue)) {
                $query->where(UrlHealth::schema_fields_ISSUE_CODE, $issue);
            }
        }

        $state = trim((string)($filters['state'] ?? ''));
        if ($state !== '' && $state !== 'all') {
            $query->where(UrlHealth::schema_fields_STATE, $state);
        }

        $locale = trim((string)($filters['locale'] ?? ''));
        if ($locale !== '') {
            $query->where(UrlHealth::schema_fields_LOCALE, $locale);
        }

        $keyword = trim((string)($filters['q'] ?? ''));
        if ($keyword !== '') {
            $pattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(UrlHealth::schema_fields_URL, $pattern, 'like');
        }

        return $query;
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            UrlHealth::STATE_OK => (string)__('正常'),
            UrlHealth::STATE_FIXED => (string)__('已修复'),
            UrlHealth::STATE_IGNORED => (string)__('已忽略'),
            UrlHealth::STATE_AUTO_REMOVED => (string)__('已自动移除'),
            UrlHealth::STATE_AUTO_RESTORED => (string)__('已自动恢复'),
            default => (string)__('待处理'),
        };
    }
}
