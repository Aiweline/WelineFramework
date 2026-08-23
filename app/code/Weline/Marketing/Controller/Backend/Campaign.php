<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;
use Weline\Marketing\Model\Campaign\Campaign as CampaignModel;
use Weline\Marketing\Model\Rule\Rule as RuleModel;
use Weline\Marketing\Service\CampaignService;

/**
 * 促销活动管理控制器
 */
#[Acl('Weline_Marketing::campaign', '促销活动', 'circle', '促销活动管理', 'Weline_Backend::marketing_group')]
class Campaign extends BackendController
{
    /**
     * 活动列表
     */
    #[Acl('Weline_Marketing::campaign_list', '活动列表', 'list', '查看促销活动列表')]
    public function index(): string
    {
        try {
            /** @var CampaignModel $campaign */
            $campaign = ObjectManager::getInstance(CampaignModel::class);
            
            if ($search = $this->request->getGet('search')) {
                $campaign->where('name', "%{$search}%", 'like');
            }
            
            $campaign->pagination()->select()->fetch();
            $this->assign('campaigns', $campaign->getItems());
            $this->assign('pagination', $campaign->getPagination());
            
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载活动列表失败：%{1}', $e->getMessage()));
            $this->assign('campaigns', []);
            return $this->fetch();
        }
    }

    #[Acl('Weline_Marketing::campaign_add', '添加促销活动', 'plus', '打开促销活动新建表单')]
    public function getAdd(): string
    {
        try {
            /** @var RuleModel $rules */
            $rules = ObjectManager::getInstance(RuleModel::class);
            $rules->order(RuleModel::schema_fields_ID, 'DESC')->select()->fetch();
            $this->assign('rules', $rules->getItems());
        } catch (\Throwable $exception) {
            Message::error(__('加载促销活动表单失败：%{1}', $exception->getMessage()));
            $this->assign('rules', []);
        }

        return $this->fetch('form');
    }

    #[Acl('Weline_Marketing::campaign_save', '保存促销活动', 'save', '保存促销活动')]
    public function postSave(): string
    {
        try {
            /** @var CampaignService $service */
            $service = ObjectManager::getInstance(CampaignService::class);
            $service->createCampaign([
                CampaignModel::schema_fields_NAME => trim((string)$this->request->getPost('name', '')),
                CampaignModel::schema_fields_DESCRIPTION => trim((string)$this->request->getPost('description', '')),
                CampaignModel::schema_fields_RULE_ID => (int)$this->request->getPost('rule_id', 0),
                CampaignModel::schema_fields_STATUS => trim((string)$this->request->getPost('status', CampaignModel::STATUS_DRAFT)),
                CampaignModel::schema_fields_START_DATE => trim((string)$this->request->getPost('start_date', '')),
                CampaignModel::schema_fields_END_DATE => trim((string)$this->request->getPost('end_date', '')),
                CampaignModel::schema_fields_BUDGET => (float)$this->request->getPost('budget', 0),
            ]);
            Message::success(__('促销活动保存成功'));
        } catch (\Throwable $exception) {
            Message::error(__('保存促销活动失败：%{1}', $exception->getMessage()));

            return $this->redirect('marketing/backend/campaign/getAdd');
        }

        return $this->redirect('marketing/backend/campaign/index');
    }
}
