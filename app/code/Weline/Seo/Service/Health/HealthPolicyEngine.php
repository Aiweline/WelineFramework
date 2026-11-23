<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

use Weline\Seo\Model\HealthPolicy;
use Weline\Seo\Model\SitemapAction;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Model\UrlHealth;

/**
 * 按站点规则把健康状态翻译成动作（待执行），不直接改 sitemap。
 *
 * 决策与执行分离：cron 先 decide 写台账，再由 SitemapActionApplier 在发布前应用。
 */
final class HealthPolicyEngine
{
    public function __construct(
        private UrlHealth $healthModel,
        private SitemapAction $actionModel,
        private HealthPolicyService $policies,
        private SitemapUrl $sitemapUrlModel
    ) {
    }

    /**
     * @return array{
     *   planned:int, restores:int, by_action:array<string,int>, skipped:int,
     *   brake:bool, brake_reason:string, limit:int
     * }
     */
    public function decideWebsite(int $websiteId, int $runId, int $maxRemovePerRun = 50): array
    {
        $policyMap = $this->policies->effectiveMap($websiteId);
        $activeActionHashes = $this->loadActiveActionHashes($websiteId);
        $appliedRemovals = $this->loadAppliedRemovals($websiteId);
        $activeUrls = (int)(clone $this->sitemapUrlModel)->reset()
            ->where(SitemapUrl::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapUrl::schema_fields_STATUS, 1)
            ->count();
        $limit = max(1, min($maxRemovePerRun, (int)floor($activeUrls * 0.05)));
        if ($activeUrls <= 0) {
            $limit = $maxRemovePerRun;
        }

        $stats = [
            'planned' => 0,
            'restores' => 0,
            'by_action' => [],
            'skipped' => 0,
            'brake' => false,
            'brake_reason' => '',
            'limit' => $limit,
        ];

        $query = $this->healthModel->reset()
            ->where(UrlHealth::schema_fields_WEBSITE_ID, $websiteId)
            ->select(
                'url_hash,url,module,scope,url_key,locale,sitemap_url_id,issue_code,http_status,'
                . 'final_url,consecutive_failures,consecutive_ok,state'
            );

        $removals = 0;
        foreach ($query->fetchIterator() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $issueCode = (string)($row[UrlHealth::schema_fields_ISSUE_CODE] ?? HealthIssueCatalog::UNKNOWN);
            $state = (string)($row[UrlHealth::schema_fields_STATE] ?? UrlHealth::STATE_OPEN);
            if ($issueCode === HealthIssueCatalog::OK) {
                $this->decideRestore($websiteId, $runId, $row, $policyMap, $appliedRemovals, $stats);
                continue;
            }
            if ($state === UrlHealth::STATE_IGNORED) {
                continue;
            }

            $urlHash = (string)($row[UrlHealth::schema_fields_URL_HASH] ?? '');
            $policy = $policyMap[$issueCode] ?? HealthIssueCatalog::defaultPolicy($issueCode);
            $decision = $this->decideIssue(
                $issueCode,
                $policy,
                (int)($row[UrlHealth::schema_fields_CONSECUTIVE_FAILURES] ?? 0),
                isset($activeActionHashes[$urlHash])
            );
            if (!$decision['act']) {
                $stats['skipped']++;
                continue;
            }

            if (in_array($decision['action'], [SitemapAction::TYPE_REMOVE, SitemapAction::TYPE_REPLACE], true)) {
                if ($removals >= $limit) {
                    $stats['brake'] = true;
                    $stats['brake_reason'] = sprintf('单次上限 %d 条（活跃 URL %d 的 5%% 且不超过 %d）', $limit, $activeUrls, $maxRemovePerRun);
                    break;
                }
                $removals++;
            }

            if ($decision['action'] === SitemapAction::TYPE_REPLACE) {
                $finalUrl = (string)($row[UrlHealth::schema_fields_FINAL_URL] ?? '');
                $status = (int)($row[UrlHealth::schema_fields_HTTP_STATUS] ?? 0);
                if ($finalUrl === ''
                    || $status < 200
                    || $status >= 300
                    || !UrlHealthKey::sameOrigin((string)($row[UrlHealth::schema_fields_URL] ?? ''), $finalUrl)
                ) {
                    $stats['skipped']++;
                    continue;
                }
            }

            $hash = (string)($row[UrlHealth::schema_fields_URL_HASH] ?? '');
            $dedupe = $this->buildDedupeKey($websiteId, $hash, $decision['action'], $policy, $runId);
            if ($this->actionExists($dedupe)) {
                $stats['skipped']++;
                continue;
            }

            $action = clone $this->actionModel;
            $action->reset()->setData([
                SitemapAction::schema_fields_WEBSITE_ID => $websiteId,
                SitemapAction::schema_fields_URL_HASH => (string)($row[UrlHealth::schema_fields_URL_HASH] ?? ''),
                SitemapAction::schema_fields_URL => (string)($row[UrlHealth::schema_fields_URL] ?? ''),
                SitemapAction::schema_fields_SITEMAP_URL_ID => (int)($row[UrlHealth::schema_fields_SITEMAP_URL_ID] ?? 0),
                SitemapAction::schema_fields_ISSUE_CODE => $issueCode,
                SitemapAction::schema_fields_ACTION_TYPE => $decision['action'],
                SitemapAction::schema_fields_STATUS => ($policy['auto_mode'] ?? '') === HealthPolicy::AUTO_DRY_RUN
                    ? SitemapAction::STATUS_DRY_RUN
                    : SitemapAction::STATUS_PENDING,
                SitemapAction::schema_fields_SOURCE => SitemapAction::SOURCE_AUTO,
                SitemapAction::schema_fields_POLICY_SNAPSHOT => json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                SitemapAction::schema_fields_BEFORE_STATE => json_encode([
                    'sitemap_url_id' => (int)($row[UrlHealth::schema_fields_SITEMAP_URL_ID] ?? 0),
                    'url' => (string)($row[UrlHealth::schema_fields_URL] ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                SitemapAction::schema_fields_AFTER_STATE => json_encode([
                    'target' => $decision['action'] === SitemapAction::TYPE_REPLACE
                        ? (string)($row[UrlHealth::schema_fields_FINAL_URL] ?? '')
                        : '',
                    'reason' => $decision['reason'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                SitemapAction::schema_fields_REASON => $decision['reason'],
                SitemapAction::schema_fields_RUN_ID => $runId,
                SitemapAction::schema_fields_DEDUPE_KEY => $dedupe,
            ])->save();

            $stats['planned']++;
            $stats['by_action'][$decision['action']] = (int)($stats['by_action'][$decision['action']] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * 单条决策（纯函数，便于单测）。
     *
     * @param array<string, mixed> $policy
     * @return array{act:bool, action:string, reason:string}
     */
    public function decideIssue(string $issueCode, array $policy, int $consecutiveFailures, bool $hasActiveAction): array
    {
        if (empty($policy['is_enabled'])) {
            return ['act' => false, 'action' => '', 'reason' => 'policy disabled'];
        }
        $mode = (string)($policy['auto_mode'] ?? HealthPolicy::AUTO_NOTIFY);
        if (!in_array($mode, [HealthPolicy::AUTO_AUTO, HealthPolicy::AUTO_DRY_RUN], true)) {
            return ['act' => false, 'action' => '', 'reason' => 'mode is ' . $mode];
        }
        if ($hasActiveAction) {
            return ['act' => false, 'action' => '', 'reason' => 'action already active'];
        }
        $grace = max(1, (int)($policy['grace_runs'] ?? 1));
        if ($consecutiveFailures < $grace) {
            return ['act' => false, 'action' => '', 'reason' => sprintf('failures %d < grace %d', $consecutiveFailures, $grace)];
        }

        $action = (string)($policy['action'] ?? HealthPolicy::ACTION_NOTIFY);
        if ($action === HealthPolicy::ACTION_REMOVE) {
            return ['act' => true, 'action' => SitemapAction::TYPE_REMOVE, 'reason' => sprintf('连续 %d 次异常，移出 sitemap', $consecutiveFailures)];
        }
        if ($action === HealthPolicy::ACTION_REPLACE) {
            return ['act' => true, 'action' => SitemapAction::TYPE_REPLACE, 'reason' => '重定向到同源 200 目标'];
        }
        if ($action === HealthPolicy::ACTION_REDIRECT) {
            return ['act' => true, 'action' => SitemapAction::TYPE_REPLACE, 'reason' => '按重定向目标替换（未开启写入 url_rewrite）'];
        }

        return ['act' => false, 'action' => '', 'reason' => 'action is ' . $action];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $policyMap
     * @param array<string, mixed> $stats
     */
    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $policyMap
     * @param array<string, SitemapAction> $appliedRemovals
     * @param array<string, mixed> $stats
     */
    private function decideRestore(int $websiteId, int $runId, array $row, array $policyMap, array $appliedRemovals, array &$stats): void
    {
        $state = (string)($row[UrlHealth::schema_fields_STATE] ?? '');
        if ($state !== UrlHealth::STATE_AUTO_REMOVED) {
            return;
        }
        $issueCode = (string)($row[UrlHealth::schema_fields_ISSUE_CODE] ?? '');
        if ($issueCode !== HealthIssueCatalog::OK) {
            return;
        }
        $urlHash = (string)($row[UrlHealth::schema_fields_URL_HASH] ?? '');
        $removeAction = $appliedRemovals[$urlHash] ?? null;
        if ($removeAction === null) {
            return;
        }
        $removedIssue = (string)$removeAction->getData(SitemapAction::schema_fields_ISSUE_CODE);
        $policy = $policyMap[$removedIssue] ?? HealthIssueCatalog::defaultPolicy($removedIssue);
        if (empty($policy['restore_enabled'])) {
            return;
        }
        $grace = max(1, (int)($policy['restore_grace_runs'] ?? 3));
        if ((int)($row[UrlHealth::schema_fields_CONSECUTIVE_OK] ?? 0) < $grace) {
            return;
        }
        $dedupe = sprintf('auto:restore:%d:%d', $websiteId, (int)$removeAction->getId());
        if ($this->actionExists($dedupe)) {
            return;
        }

        $action = clone $this->actionModel;
        $action->reset()->setData([
            SitemapAction::schema_fields_WEBSITE_ID => $websiteId,
            SitemapAction::schema_fields_URL_HASH => $urlHash,
            SitemapAction::schema_fields_URL => (string)($row[UrlHealth::schema_fields_URL] ?? ''),
            SitemapAction::schema_fields_SITEMAP_URL_ID => (int)($row[UrlHealth::schema_fields_SITEMAP_URL_ID] ?? 0),
            SitemapAction::schema_fields_ISSUE_CODE => HealthIssueCatalog::OK,
            SitemapAction::schema_fields_ACTION_TYPE => SitemapAction::TYPE_RESTORE,
            SitemapAction::schema_fields_STATUS => SitemapAction::STATUS_PENDING,
            SitemapAction::schema_fields_SOURCE => SitemapAction::SOURCE_AUTO,
            SitemapAction::schema_fields_POLICY_SNAPSHOT => json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            SitemapAction::schema_fields_BEFORE_STATE => json_encode([
                'remove_action_id' => (int)$removeAction->getId(),
                'sitemap_url_id' => (int)($row[UrlHealth::schema_fields_SITEMAP_URL_ID] ?? 0),
                'url' => (string)($row[UrlHealth::schema_fields_URL] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            SitemapAction::schema_fields_REASON => sprintf('连续 %d 次正常，自动加回 sitemap', (int)($row[UrlHealth::schema_fields_CONSECUTIVE_OK] ?? 0)),
            SitemapAction::schema_fields_RUN_ID => $runId,
            SitemapAction::schema_fields_DEDUPE_KEY => $dedupe,
        ])->save();
        $stats['restores']++;
        $stats['by_action'][SitemapAction::TYPE_RESTORE] = (int)($stats['by_action'][SitemapAction::TYPE_RESTORE] ?? 0) + 1;
    }

    /** @return array<string, bool> url_hash => true */
    private function loadActiveActionHashes(int $websiteId): array
    {
        $query = $this->actionModel->reset()
            ->where(SitemapAction::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapAction::schema_fields_ACTION_TYPE, [SitemapAction::TYPE_REMOVE, SitemapAction::TYPE_REPLACE], 'IN')
            ->where(SitemapAction::schema_fields_STATUS, [SitemapAction::STATUS_PENDING, SitemapAction::STATUS_APPLIED], 'IN')
            ->select('url_hash');

        $map = [];
        foreach ($query->fetchIterator() as $row) {
            if (is_array($row)) {
                $hash = (string)($row[SitemapAction::schema_fields_URL_HASH] ?? '');
                if ($hash !== '') {
                    $map[$hash] = true;
                }
            }
        }

        return $map;
    }

    /** @return array<string, SitemapAction> url_hash => latest applied remove action */
    private function loadAppliedRemovals(int $websiteId): array
    {
        $query = $this->actionModel->reset()
            ->where(SitemapAction::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapAction::schema_fields_ACTION_TYPE, SitemapAction::TYPE_REMOVE)
            ->where(SitemapAction::schema_fields_STATUS, SitemapAction::STATUS_APPLIED)
            ->order(SitemapAction::schema_fields_ID, 'ASC')
            ->select();

        $map = [];
        foreach ($query->fetchArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hash = (string)($row[SitemapAction::schema_fields_URL_HASH] ?? '');
            if ($hash === '') {
                continue;
            }
            $action = clone $this->actionModel;
            $action->reset()->setData($row);
            if ((int)$action->getId() > 0) {
                $map[$hash] = $action;
            }
        }

        return $map;
    }

    /**
     * 自动动作的幂等键：同一轮只生成一条；恢复后可进入下一轮（cycle+1）。
     *
     * @param array<string, mixed> $policy
     */
    private function buildDedupeKey(int $websiteId, string $urlHash, string $actionType, array $policy, int $runId): string
    {
        $mode = (string)($policy['auto_mode'] ?? '');
        if ($mode === HealthPolicy::AUTO_DRY_RUN) {
            $cycle = $runId > 0 ? (string)$runId : date('YmdHis');

            return sprintf('auto:%s:%d:%s:dry:%s', $actionType, $websiteId, $urlHash, $cycle);
        }

        $cycle = (int)(clone $this->actionModel)->reset()
            ->where(SitemapAction::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapAction::schema_fields_URL_HASH, $urlHash)
            ->where(SitemapAction::schema_fields_ACTION_TYPE, $actionType)
            ->count();

        return sprintf('auto:%s:%d:%s:%d', $actionType, $websiteId, $urlHash, $cycle + 1);
    }

    private function actionExists(string $dedupeKey): bool
    {
        if ($dedupeKey === '') {
            return false;
        }
        $count = (clone $this->actionModel)->reset()
            ->where(SitemapAction::schema_fields_DEDUPE_KEY, $dedupeKey)
            ->count();

        return (int)$count > 0;
    }
}
