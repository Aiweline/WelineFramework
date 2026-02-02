<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 站点分配管理控制器
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\WebsiteUser as WebsiteUserModel;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website as WebsiteModel;

#[Acl('GuoLaiRen_PageBuilder::website_assignment', '站点分配', 'mdi mdi-shield-account', '管理站点与后台用户一对一分配关系', 'GuoLaiRen_PageBuilder::website_management')]
class Website extends BackendController
{
    private WebsiteUserModel $websiteUserModel;
    private WebsiteModel $websiteModel;
    private BackendUser $backendUserModel;

    public function __construct(
        WebsiteUserModel $websiteUserModel,
        WebsiteModel     $websiteModel,
        BackendUser      $backendUserModel
    ) {
        $this->websiteUserModel = $websiteUserModel;
        $this->websiteModel = $websiteModel;
        $this->backendUserModel = $backendUserModel;
    }

    /**
     * 站点分配列表与保存
     */
    public function index()
    {
        // 处理保存请求
        if ($this->request->isPost()) {
            $assignData = $this->request->getPost('assign', []);

            if (is_array($assignData)) {
                foreach ($assignData as $websiteId => $backendUserId) {
                    $websiteId = (int)$websiteId;
                    $backendUserId = (int)$backendUserId;

                    if ($websiteId <= 0) {
                        continue;
                    }

                    // 先删除该站点之前的归属关系（一个站点只能绑定一个用户）
                    $cleaner = clone $this->websiteUserModel;
                    $cleaner->clear()
                        ->where(WebsiteUserModel::fields_WEBSITE_ID, $websiteId)
                        ->delete()
                        ->fetch();

                    // 如果选择了新的后台用户，则创建新的归属记录
                    if ($backendUserId > 0) {
                        $newMapping = clone $this->websiteUserModel;
                        $newMapping->clear()
                            ->setData(WebsiteUserModel::fields_WEBSITE_ID, $websiteId)
                            ->setData(WebsiteUserModel::fields_BACKEND_USER_ID, $backendUserId)
                            ->setData(WebsiteUserModel::fields_IS_OWNER, 0)
                            ->save(true);
                    }
                }
            }

            MessageManager::success(__('站点分配已更新'));
        }

        // 获取所有站点（拥有站点分配权限的用户可以看到所有站点）
        $websiteCollection = clone $this->websiteModel;
        $websites = $websiteCollection->clearQuery()
            ->order(WebsiteModel::fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        // 获取当前每个站点已分配的后台用户
        $assignedMap = [];
        if (!empty($websites)) {
            foreach ($websites as $website) {
                $websiteId = (int)($website['website_id'] ?? 0);
                $mapping = clone $this->websiteUserModel;
                $mapping->clear()
                    ->where(WebsiteUserModel::fields_WEBSITE_ID, $websiteId)
                    ->find()
                    ->fetch();

                if ($mapping->getId()) {
                    $assignedMap[$websiteId] = (int)$mapping->getData(WebsiteUserModel::fields_BACKEND_USER_ID);
                }
            }
        }

        // 获取所有后台用户（用于下拉选择）
        $backendUserCollection = clone $this->backendUserModel;
        $backendUsers = $backendUserCollection->clear()
            ->where(BackendUser::fields_is_deleted, 0)
            ->where(BackendUser::fields_is_enabled, 1)
            ->order(BackendUser::fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $this->assign('page_title', __('站点分配'));
        $this->assign('breadcrumb_parent', __('页面管理'));
        $this->assign('breadcrumb_current', __('站点分配'));

        $this->assign('websites', $websites);
        $this->assign('assigned_map', $assignedMap);
        $this->assign('backend_users', $backendUsers);

        return $this->fetch();
    }

    /**
     * 快速创建站点（代理到 Websites 模块）
     * 用于一键建站等场景的快速创建
     * 自动设置 scope 为 page_builder，标识站点来源
     */
    #[Acl('Weline_Websites::website_quick_save', '快速创建站点', '', '快速创建站点')]
    public function quickSave()
    {
        // 自动设置 scope 参数为 page_builder，标识该站点由 PageBuilder 创建
        // 这样在 SEO 账户关联时可以按 scope 过滤
        $this->request->setPost('scope', 'page_builder');
        
        // 代理到 Websites 模块的 quickSave 方法
        $websitesController = ObjectManager::getInstance(\Weline\Websites\Controller\Admin\Website::class);
        return $websitesController->quickSave();
    }
}

