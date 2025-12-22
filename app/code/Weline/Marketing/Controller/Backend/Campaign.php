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

/**
 * 促销活动管理控制器
 */
#[Acl('Weline_Marketing::campaign', '促销活动', 'mdi-bullhorn', '促销活动管理', 'Weline_Marketing::marketing_manager')]
class Campaign extends BackendController
{
    /**
     * 活动列表
     */
    #[Acl('Weline_Marketing::campaign_list', '活动列表', 'mdi-format-list-bulleted', '查看促销活动列表')]
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
}

