<?php

declare(strict_types=1);

namespace WeShop\Promotion\Repository;

use WeShop\Promotion\Model\Campaign;
use Weline\Framework\Manager\ObjectManager;

class CampaignRepository implements CampaignRepositoryInterface
{
    public function createCampaign(): Campaign
    {
        return $this->createBaseCampaign();
    }

    public function findCampaignById(int $campaignId): ?Campaign
    {
        if ($campaignId <= 0) {
            return null;
        }

        $campaign = $this->createBaseCampaign();
        $campaign->load($campaignId);

        return $campaign->getId() ? $campaign : null;
    }

    /**
     * @return array{total_count:int,active_count:int,ended_count:int,total_campaigns:int}
     */
    public function getCampaignSummary(): array
    {
        $campaign = $this->createBaseCampaign();
        $totalCount = (int) $campaign->clear()->count();

        $now = date('Y-m-d H:i:s');

        // active: start_date <= now 且 (end_date >= now OR end_date = '')
        // 由于框架 QueryAst::where() 不接受 Closure 分组，这里改为两段无重叠 count 相加。
        $activeCountEndNotEmpty = (int) $campaign->clear()
            ->where('status', 1)
            ->where('start_date', $now, '<=')
            ->where('end_date', $now, '>=')
            ->count();

        $activeCountEndEmpty = (int) $campaign->clear()
            ->where('status', 1)
            ->where('start_date', $now, '<=')
            // end_date 为 datetime，不能用空字符串 '' 比较；改用 IS NULL 避免 Pgsql 解析失败
            ->where('end_date', null, 'IS NULL')
            ->count();

        $activeCount = $activeCountEndNotEmpty + $activeCountEndEmpty;

        $endedCount = (int) $campaign->clear()
            ->where('end_date', $now, '<')
            ->count();

        return [
            'total_count' => $totalCount,
            'active_count' => $activeCount,
            'ended_count' => $endedCount,
            'total_campaigns' => $totalCount,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listRecentCampaigns(int $limit = 5): array
    {
        $campaign = $this->createBaseCampaign();

        $query = $campaign->clear()->order('created_at', 'DESC');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->select()->fetchArray();
    }

    /**
     * @return array<int, Campaign>
     */
    public function listCampaigns(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $campaign = $this->createBaseCampaign();
        $query = $campaign->clear();

        if (!empty($filters['name'])) {
            $query->where('name', '%' . $filters['name'] . '%', 'LIKE');
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', (int) $filters['status']);
        }

        if (!empty($filters['is_featured'])) {
            $query->where('is_featured', (int) $filters['is_featured']);
        }

        $query->order('priority', 'DESC')
            ->order('created_at', 'DESC');

        if ($page > 0 && $pageSize > 0) {
            $offset = ($page - 1) * $pageSize;
            $query->limit($pageSize, $offset);
        }

        $result = $query->select()->fetch();
        if (is_array($result)) {
            return $result;
        }

        return [];
    }

    public function countCampaigns(array $filters = []): int
    {
        $campaign = $this->createBaseCampaign();
        $query = $campaign->clear();

        if (!empty($filters['name'])) {
            $query->where('name', '%' . $filters['name'] . '%', 'LIKE');
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', (int) $filters['status']);
        }

        if (!empty($filters['is_featured'])) {
            $query->where('is_featured', (int) $filters['is_featured']);
        }

        return (int) $query->count();
    }

    public function deleteCampaign(int $campaignId): bool
    {
        if ($campaignId <= 0) {
            return false;
        }

        $campaign = $this->findCampaignById($campaignId);
        if (!$campaign) {
            return false;
        }

        return (bool) $campaign->delete();
    }

    private function createBaseCampaign(): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = ObjectManager::getInstance(Campaign::class);
        return $campaign->clear();
    }
}
