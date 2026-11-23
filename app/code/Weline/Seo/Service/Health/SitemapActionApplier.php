<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

use Weline\Seo\Model\SitemapAction;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Model\UrlHealth;

/**
 * 动作执行器：把待执行台账落到 sitemap_url / 恢复 / 替换，并支持撤销。
 *
 * 每轮在 Provider 同步之后调用 reassertActiveRemovals()，抵消同步把 status 改回 1。
 */
final class SitemapActionApplier
{
    public function __construct(
        private SitemapAction $actionModel,
        private SitemapUrl $sitemapUrlModel,
        private UrlHealth $healthModel
    ) {
    }

    /**
     * @return array{applied:int, failed:int, skipped:int, by_action:array<string,int>}
     */
    public function applyWebsite(int $websiteId, int $limit = 200): array
    {
        $stats = ['applied' => 0, 'failed' => 0, 'skipped' => 0, 'by_action' => []];
        $query = $this->actionModel->reset()
            ->where(SitemapAction::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapAction::schema_fields_STATUS, SitemapAction::STATUS_PENDING)
            ->order(SitemapAction::schema_fields_ID, 'ASC')
            ->limit($limit);

        foreach ($query->select()->fetchArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $action = clone $this->actionModel;
            $action->reset()->setData($row);
            if ((int)$action->getId() <= 0) {
                $stats['skipped']++;
                continue;
            }

            try {
                $result = $this->applyOne($action);
                if ($result['ok']) {
                    $stats['applied']++;
                    $type = (string)$action->getData(SitemapAction::schema_fields_ACTION_TYPE);
                    $stats['by_action'][$type] = (int)($stats['by_action'][$type] ?? 0) + 1;
                } else {
                    $stats['failed']++;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $action->setData([
                    SitemapAction::schema_fields_STATUS => SitemapAction::STATUS_FAILED,
                    SitemapAction::schema_fields_REASON => mb_substr($e->getMessage(), 0, 255),
                ])->save();
                w_log_error('[Weline_Seo] sitemap action failed: ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * 重新断言“已生效的移除”：Provider 同步会把 status 改回 1，这里再压回 0。
     *
     * @return int 被重新压回的条数
     */
    public function reassertActiveRemovals(int $websiteId): int
    {
        $query = $this->actionModel->reset()
            ->where(SitemapAction::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapAction::schema_fields_ACTION_TYPE, SitemapAction::TYPE_REMOVE)
            ->where(SitemapAction::schema_fields_STATUS, SitemapAction::STATUS_APPLIED)
            ->order(SitemapAction::schema_fields_ID, 'ASC');

        $reasserted = 0;
        foreach ($query->select()->fetchArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = (string)($row[SitemapAction::schema_fields_URL] ?? '');
            $sitemapUrlId = (int)($row[SitemapAction::schema_fields_SITEMAP_URL_ID] ?? 0);
            $sitemapRow = $this->findSitemapRow($websiteId, $sitemapUrlId, $url);
            if ($sitemapRow === null) {
                continue;
            }
            if ((int)$sitemapRow->getData(SitemapUrl::schema_fields_STATUS) === 0) {
                continue;
            }
            $sitemapRow->setData(SitemapUrl::schema_fields_STATUS, 0)->save();
            $reasserted++;
        }

        return $reasserted;
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public function revert(int $actionId): array
    {
        $action = (clone $this->actionModel)->reset()->load($actionId);
        if (!$action->getId()) {
            return ['ok' => false, 'message' => (string)__('动作不存在')];
        }
        if ((string)$action->getData(SitemapAction::schema_fields_STATUS) !== SitemapAction::STATUS_APPLIED) {
            return ['ok' => false, 'message' => (string)__('只有已生效的动作可以撤销')];
        }
        $type = (string)$action->getData(SitemapAction::schema_fields_ACTION_TYPE);
        if (!in_array($type, [SitemapAction::TYPE_REMOVE, SitemapAction::TYPE_REPLACE], true)) {
            return ['ok' => false, 'message' => (string)__('该动作不支持撤销')];
        }

        $before = $action->getBeforeState();
        $sitemapUrlId = (int)($before['sitemap_url_id'] ?? 0);
        $url = (string)($before['url'] ?? $action->getData(SitemapAction::schema_fields_URL));
        $sitemapRow = $this->findSitemapRow((int)$action->getData(SitemapAction::schema_fields_WEBSITE_ID), $sitemapUrlId, $url);
        if ($sitemapRow === null) {
            return ['ok' => false, 'message' => (string)__('sitemap 中已找不到该地址，无法撤销')];
        }

        if ($type === SitemapAction::TYPE_REMOVE) {
            $sitemapRow->setData(SitemapUrl::schema_fields_STATUS, (int)($before['status'] ?? 1))->save();
            $this->setHealthState((int)$action->getData(SitemapAction::schema_fields_WEBSITE_ID), (string)$action->getData(SitemapAction::schema_fields_URL_HASH), UrlHealth::STATE_OPEN);
        } else {
            $sitemapRow->setData(SitemapUrl::schema_fields_URL, $url)->save();
        }

        $action->setData([
            SitemapAction::schema_fields_STATUS => SitemapAction::STATUS_REVERTED,
            SitemapAction::schema_fields_REVERT_AT => date('Y-m-d H:i:s'),
        ])->save();

        return ['ok' => true, 'message' => (string)__('已撤销')];
    }

    /**
     * @return array{ok:bool, message:string}
     */
    private function applyOne(SitemapAction $action): array
    {
        $type = (string)$action->getData(SitemapAction::schema_fields_ACTION_TYPE);
        $websiteId = (int)$action->getData(SitemapAction::schema_fields_WEBSITE_ID);
        $before = $action->getBeforeState();
        $sitemapUrlId = (int)($before['sitemap_url_id'] ?? $action->getData(SitemapAction::schema_fields_SITEMAP_URL_ID));
        $url = (string)$action->getData(SitemapAction::schema_fields_URL);
        $sitemapRow = $this->findSitemapRow($websiteId, $sitemapUrlId, $url);

        if ($type === SitemapAction::TYPE_REMOVE) {
            if ($sitemapRow !== null) {
                $beforeState = [
                    'sitemap_url_id' => (int)$sitemapRow->getId(),
                    'url' => (string)$sitemapRow->getData(SitemapUrl::schema_fields_URL),
                    'status' => (int)$sitemapRow->getData(SitemapUrl::schema_fields_STATUS),
                ];
                $sitemapRow->setData(SitemapUrl::schema_fields_STATUS, 0)->save();
                $action->setBeforeState($beforeState);
            }
            $this->setHealthState($websiteId, (string)$action->getData(SitemapAction::schema_fields_URL_HASH), UrlHealth::STATE_AUTO_REMOVED);
            $this->markApplied($action, ['removed' => $sitemapRow !== null]);

            return ['ok' => true, 'message' => 'removed'];
        }

        if ($type === SitemapAction::TYPE_RESTORE) {
            if ($sitemapRow === null) {
                return ['ok' => false, 'message' => 'sitemap row missing'];
            }
            $sitemapRow->setData(SitemapUrl::schema_fields_STATUS, 1)->save();
            $this->setHealthState($websiteId, (string)$action->getData(SitemapAction::schema_fields_URL_HASH), UrlHealth::STATE_AUTO_RESTORED);

            $removeId = (int)($before['remove_action_id'] ?? 0);
            if ($removeId > 0) {
                $remove = (clone $this->actionModel)->reset()->load($removeId);
                if ($remove->getId()) {
                    $remove->setData([
                        SitemapAction::schema_fields_STATUS => SitemapAction::STATUS_REVERTED,
                        SitemapAction::schema_fields_REVERT_AT => date('Y-m-d H:i:s'),
                    ])->save();
                }
            }
            $this->markApplied($action, ['restored' => true]);

            return ['ok' => true, 'message' => 'restored'];
        }

        if ($type === SitemapAction::TYPE_REPLACE) {
            if ($sitemapRow === null) {
                return ['ok' => false, 'message' => 'sitemap row missing'];
            }
            $after = $action->getAfterState();
            $target = (string)($after['target'] ?? '');
            if ($target === '' || !preg_match('#^https?://#i', $target)) {
                return ['ok' => false, 'message' => 'invalid target'];
            }
            $beforeState = [
                'sitemap_url_id' => (int)$sitemapRow->getId(),
                'url' => (string)$sitemapRow->getData(SitemapUrl::schema_fields_URL),
                'status' => (int)$sitemapRow->getData(SitemapUrl::schema_fields_STATUS),
            ];
            $sitemapRow->setData(SitemapUrl::schema_fields_URL, $target)->save();
            $action->setBeforeState($beforeState);
            $this->markApplied($action, ['replaced' => $target]);

            return ['ok' => true, 'message' => 'replaced'];
        }

        return ['ok' => false, 'message' => 'unsupported action'];
    }

    /** @param array<string, mixed> $after */
    private function markApplied(SitemapAction $action, array $after): void
    {
        $action->setData([
            SitemapAction::schema_fields_STATUS => SitemapAction::STATUS_APPLIED,
            SitemapAction::schema_fields_APPLIED_AT => date('Y-m-d H:i:s'),
        ]);
        $action->setAfterState(array_merge($action->getAfterState(), $after));
        $action->save();
    }

    private function setHealthState(int $websiteId, string $urlHash, string $state): void
    {
        if ($urlHash === '') {
            return;
        }
        $health = (clone $this->healthModel)->reset()
            ->where(UrlHealth::schema_fields_WEBSITE_ID, $websiteId)
            ->where(UrlHealth::schema_fields_URL_HASH, $urlHash)
            ->find()
            ->fetch();
        if ($health instanceof UrlHealth && (int)$health->getId() > 0) {
            $health->setData(UrlHealth::schema_fields_STATE, $state)->save();
        }
    }

    private function findSitemapRow(int $websiteId, int $sitemapUrlId, string $url): ?SitemapUrl
    {
        if ($sitemapUrlId > 0) {
            $row = (clone $this->sitemapUrlModel)->reset()->load($sitemapUrlId);
            if ($row->getId()
                && (int)$row->getData(SitemapUrl::schema_fields_WEBSITE_ID) === $websiteId
                && (string)$row->getData(SitemapUrl::schema_fields_URL) === $url
            ) {
                return $row;
            }
        }
        if ($url === '') {
            return null;
        }
        $row = (clone $this->sitemapUrlModel)->reset()
            ->where(SitemapUrl::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapUrl::schema_fields_URL, $url)
            ->find()
            ->fetch();

        return $row instanceof SitemapUrl && (int)$row->getId() > 0 ? $row : null;
    }
}
