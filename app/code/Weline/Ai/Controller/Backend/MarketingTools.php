<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiMarketingCampaign;
use Weline\Ai\Service\MarketingToolsService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Acl\Acl;

/**
 * 营销工具管理控制器
 * 
 * 功能：
 * - 营销活动管理
 * - 内容生成工具
 * - 营销数据分析
 */
#[Acl('Weline_Ai::ai_marketing_tools', '营销工具', 'mdi-megaphone', '营销工具', 'Weline_Ai::ai')]
class MarketingTools extends BackendController
{
    /**
     * 营销工具首页
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_marketing_tools_index', '查看营销工具', 'mdi-view-dashboard', '查看营销工具')]
    public function index(): string
    {
        try {
            /** @var MarketingToolsService $service */
            $service = ObjectManager::getInstance(MarketingToolsService::class);
            
            // 获取筛选条件
            $filters = [
                'status' => $this->request->getGet('status', ''),
                'campaign_type' => $this->request->getGet('campaign_type', ''),
                'search' => $this->request->getGet('search', ''),
            ];
            
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;
            
            // 获取活动列表
            $result = $service->getCampaigns($filters, $page, $pageSize);
            $campaigns = $result['items'];
            $pagination = $result['pagination'];
            
            // 获取统计信息
            $stats = $service->getStatistics();
            
            $this->assign('campaigns', $campaigns);
            $this->assign('pagination', $pagination);
            $this->assign('stats', $stats);
            $this->assign('filters', $filters);
            $this->assign('campaign_types', [
                AiMarketingCampaign::CAMPAIGN_TYPE_PROMOTION => __('促销活动'),
                AiMarketingCampaign::CAMPAIGN_TYPE_REFERRAL => __('推荐活动'),
                AiMarketingCampaign::CAMPAIGN_TYPE_DISCOUNT => __('折扣活动'),
            ]);
            $this->assign('status_options', [
                AiMarketingCampaign::STATUS_DRAFT => __('草稿'),
                AiMarketingCampaign::STATUS_ACTIVE => __('进行中'),
                AiMarketingCampaign::STATUS_COMPLETED => __('已完成'),
                AiMarketingCampaign::STATUS_CANCELLED => __('已取消'),
            ]);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载营销工具失败：%{1}', $e->getMessage()));
            $this->assign('campaigns', []);
            $this->assign('pagination', null);
            $this->assign('stats', ['total_campaigns' => 0, 'active_campaigns' => 0, 'draft_campaigns' => 0, 'completed_campaigns' => 0, 'total_budget' => 0]);
            $this->assign('filters', []);
            return $this->fetch();
        }
    }
    
    /**
     * 创建活动页面
     *
     * @return string
     */
    #[Acl('Weline_Ai::ai_marketing_tools_create', '创建营销活动', 'mdi-plus-circle', '创建营销活动')]
    public function create(): string
    {
        $this->assign('campaign_types', [
            AiMarketingCampaign::CAMPAIGN_TYPE_PROMOTION => __('促销活动'),
            AiMarketingCampaign::CAMPAIGN_TYPE_REFERRAL => __('推荐活动'),
            AiMarketingCampaign::CAMPAIGN_TYPE_DISCOUNT => __('折扣活动'),
        ]);
        $this->assign('status_options', [
            AiMarketingCampaign::STATUS_DRAFT => __('草稿'),
            AiMarketingCampaign::STATUS_ACTIVE => __('进行中'),
        ]);
        return $this->fetch();
    }
    
    /**
     * 保存活动
     *
     * @return string
     */
    #[Acl('Weline_Ai::ai_marketing_tools_save', '保存营销活动', 'mdi-content-save', '保存营销活动')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => __('仅支持POST请求')]);
        }
        
        try {
            /** @var MarketingToolsService $service */
            $service = ObjectManager::getInstance(MarketingToolsService::class);
            
            $data = $this->request->getParams();
            $id = (int)($data['id'] ?? 0);
            
            if ($id > 0) {
                $campaign = $service->updateCampaign($id, $data);
                Message::success(__('活动更新成功'));
            } else {
                $campaign = $service->createCampaign($data);
                Message::success(__('活动创建成功'));
            }
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('保存成功'),
                'id' => $campaign->getId(),
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 编辑活动页面
     *
     * @return string
     */
    #[Acl('Weline_Ai::ai_marketing_tools_edit', '编辑营销活动', 'mdi-pencil', '编辑营销活动')]
    public function edit(): string
    {
        $id = (int)$this->request->getGet('id', 0);
        
        if ($id <= 0) {
            Message::error(__('活动ID无效'));
            $this->redirect('ai/backend/marketingtools');
            return '';
        }
        
        /** @var AiMarketingCampaign $campaign */
        $campaign = ObjectManager::getInstance(AiMarketingCampaign::class);
        $campaign->load($id);
        
        if (!$campaign->getId()) {
            Message::error(__('活动不存在'));
            $this->redirect('ai/backend/marketingtools');
            return '';
        }
        
        $this->assign('campaign', $campaign->getData());
        $this->assign('campaign_types', [
            AiMarketingCampaign::CAMPAIGN_TYPE_PROMOTION => __('促销活动'),
            AiMarketingCampaign::CAMPAIGN_TYPE_REFERRAL => __('推荐活动'),
            AiMarketingCampaign::CAMPAIGN_TYPE_DISCOUNT => __('折扣活动'),
        ]);
        $this->assign('status_options', [
            AiMarketingCampaign::STATUS_DRAFT => __('草稿'),
            AiMarketingCampaign::STATUS_ACTIVE => __('进行中'),
            AiMarketingCampaign::STATUS_COMPLETED => __('已完成'),
            AiMarketingCampaign::STATUS_CANCELLED => __('已取消'),
        ]);
        
        // 使用create.phtml模板（支持创建和编辑）
        return $this->fetch('create');
    }
    
    /**
     * 删除活动
     *
     * @return string
     */
    #[Acl('Weline_Ai::ai_marketing_tools_delete', '删除营销活动', 'mdi-delete', '删除营销活动')]
    public function delete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => __('仅支持POST请求')]);
        }
        
        try {
            $id = (int)$this->request->getPost('id', 0);
            
            if ($id <= 0) {
                return $this->jsonResponse(['success' => false, 'message' => __('活动ID无效')]);
            }
            
            /** @var MarketingToolsService $service */
            $service = ObjectManager::getInstance(MarketingToolsService::class);
            $service->deleteCampaign($id);
            
            Message::success(__('活动删除成功'));
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('删除成功'),
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 内容生成工具
     *
     * @return string
     */
    #[Acl('Weline_Ai::ai_marketing_tools_content', '内容生成工具', 'mdi-auto-fix', 'AI内容生成工具')]
    public function content(): string
    {
        return $this->fetch();
    }
    
    /**
     * 数据分析
     *
     * @return string
     */
    #[Acl('Weline_Ai::ai_marketing_tools_analytics', '营销数据分析', 'mdi-chart-line', '营销数据分析')]
    public function analytics(): string
    {
        try {
            /** @var MarketingToolsService $service */
            $service = ObjectManager::getInstance(MarketingToolsService::class);
            $stats = $service->getStatistics();
            
            $this->assign('stats', $stats);
            
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载数据分析失败：%{1}', $e->getMessage()));
            $this->assign('stats', []);
            return $this->fetch();
        }
    }
}
