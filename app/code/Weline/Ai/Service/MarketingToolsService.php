<?php
declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiMarketingCampaign;
use Weline\Framework\Manager\ObjectManager;

/**
 * 营销工具服务
 */
class MarketingToolsService
{
    /**
     * 创建营销活动
     *
     * @param array $data
     * @return AiMarketingCampaign
     * @throws \Exception
     */
    public function createCampaign(array $data): AiMarketingCampaign
    {
        /** @var AiMarketingCampaign $campaign */
        $campaign = ObjectManager::getInstance(AiMarketingCampaign::class);
        
        // 验证必填字段
        if (empty($data['campaign_name'])) {
            throw new \Exception(__('活动名称不能为空'));
        }
        
        if (empty($data['campaign_type'])) {
            throw new \Exception(__('活动类型不能为空'));
        }
        
        if (empty($data['start_date'])) {
            throw new \Exception(__('开始日期不能为空'));
        }
        
        if (empty($data['end_date'])) {
            throw new \Exception(__('结束日期不能为空'));
        }
        
        // 验证日期
        if (strtotime($data['start_date']) > strtotime($data['end_date'])) {
            throw new \Exception(__('开始日期不能晚于结束日期'));
        }
        
        // 设置默认状态
        if (empty($data['status'])) {
            $data['status'] = AiMarketingCampaign::STATUS_DRAFT;
        }
        
        $campaign->setData($data);
        $campaign->save();
        
        return $campaign;
    }
    
    /**
     * 更新营销活动
     *
     * @param int $id
     * @param array $data
     * @return AiMarketingCampaign
     * @throws \Exception
     */
    public function updateCampaign(int $id, array $data): AiMarketingCampaign
    {
        /** @var AiMarketingCampaign $campaign */
        $campaign = ObjectManager::getInstance(AiMarketingCampaign::class);
        $campaign->load($id);
        
        if (!$campaign->getId()) {
            throw new \Exception(__('活动不存在'));
        }
        
        // 验证日期
        if (isset($data['start_date']) && isset($data['end_date'])) {
            if (strtotime($data['start_date']) > strtotime($data['end_date'])) {
                throw new \Exception(__('开始日期不能晚于结束日期'));
            }
        }
        
        $campaign->setData($data);
        $campaign->save();
        
        return $campaign;
    }
    
    /**
     * 删除营销活动
     *
     * @param int $id
     * @return bool
     * @throws \Exception
     */
    public function deleteCampaign(int $id): bool
    {
        /** @var AiMarketingCampaign $campaign */
        $campaign = ObjectManager::getInstance(AiMarketingCampaign::class);
        $campaign->load($id);
        
        if (!$campaign->getId()) {
            throw new \Exception(__('活动不存在'));
        }
        
        return $campaign->delete();
    }
    
    /**
     * 获取活动统计
     *
     * @return array
     */
    public function getStatistics(): array
    {
        /** @var AiMarketingCampaign $campaign */
        $campaign = ObjectManager::getInstance(AiMarketingCampaign::class);
        
        try {
            $totalCampaigns = $campaign->reset()->select()->fetch()->count();
        } catch (\Exception $e) {
            $totalCampaigns = 0;
        }
        
        try {
            $activeCampaigns = $campaign->reset()
                ->where(AiMarketingCampaign::fields_STATUS, AiMarketingCampaign::STATUS_ACTIVE)
                ->select()
                ->fetch()
                ->count();
        } catch (\Exception $e) {
            $activeCampaigns = 0;
        }
        
        try {
            $draftCampaigns = $campaign->reset()
                ->where(AiMarketingCampaign::fields_STATUS, AiMarketingCampaign::STATUS_DRAFT)
                ->select()
                ->fetch()
                ->count();
        } catch (\Exception $e) {
            $draftCampaigns = 0;
        }
        
        try {
            $completedCampaigns = $campaign->reset()
                ->where(AiMarketingCampaign::fields_STATUS, AiMarketingCampaign::STATUS_COMPLETED)
                ->select()
                ->fetch()
                ->count();
        } catch (\Exception $e) {
            $completedCampaigns = 0;
        }
        
        try {
            $totalBudget = $campaign->reset()
                ->fields(['total' => 'SUM(' . AiMarketingCampaign::fields_BUDGET . ')'])
                ->find()
                ->fetch();
            $totalBudget = round((float)($totalBudget['total'] ?? 0), 2);
        } catch (\Exception $e) {
            $totalBudget = 0;
        }
        
        return [
            'total_campaigns' => $totalCampaigns,
            'active_campaigns' => $activeCampaigns,
            'draft_campaigns' => $draftCampaigns,
            'completed_campaigns' => $completedCampaigns,
            'total_budget' => $totalBudget,
        ];
    }
    
    /**
     * 获取活动列表
     *
     * @param array $filters
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public function getCampaigns(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        /** @var AiMarketingCampaign $campaign */
        $campaign = ObjectManager::getInstance(AiMarketingCampaign::class);
        
        // 应用筛选条件
        if (!empty($filters['status'])) {
            $campaign->where(AiMarketingCampaign::fields_STATUS, $filters['status']);
        }
        
        if (!empty($filters['campaign_type'])) {
            $campaign->where(AiMarketingCampaign::fields_CAMPAIGN_TYPE, $filters['campaign_type']);
        }
        
        if (!empty($filters['search'])) {
            $campaign->where(AiMarketingCampaign::fields_CAMPAIGN_NAME, "%{$filters['search']}%", 'like');
        }
        
        // 分页
        $campaign->pagination($page, $pageSize);
        $campaign->order(AiMarketingCampaign::fields_CREATED_AT, 'DESC');
        
        $items = $campaign->select()->fetch()->getItems();
        $pagination = $campaign->getPagination();
        
        $campaigns = [];
        foreach ($items as $item) {
            $campaigns[] = $item->getData();
        }
        
        return [
            'items' => $campaigns,
            'pagination' => $pagination,
        ];
    }
}
