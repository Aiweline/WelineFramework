<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\AutoLeadAgent\Model\TargetWebsite as TargetWebsiteModel;

/**
 * 搜索目标网站管理控制器
 */
#[Acl(
    'Weline_AutoLeadAgent::target_website',
    '搜索目标网站管理',
    'mdi-web',
    '管理搜索目标网站配置',
    'Weline_AutoLeadAgent::auto_lead_agent'
)]
class TargetWebsite extends BackendController
{
    /**
     * 目标网站列表页面
     */
    #[Acl(
        'Weline_AutoLeadAgent::target_website_index',
        '查看目标网站列表',
        'mdi-format-list-bulleted',
        '查看搜索目标网站列表'
    )]
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 获取目标网站列表（AJAX）
     */
    #[Acl(
        'Weline_AutoLeadAgent::target_website_list',
        '获取目标网站列表',
        'mdi-format-list-bulleted',
        '获取搜索目标网站列表数据'
    )]
    public function list()
    {
        if (!$this->request->isAjax()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持AJAX请求'),
            ]);
        }

        try {
            /** @var TargetWebsiteModel $targetWebsiteModel */
            $targetWebsiteModel = ObjectManager::getInstance(TargetWebsiteModel::class);
            
            // 获取筛选参数
            $isActive = $this->request->getGet('is_active', '');
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = (int)$this->request->getGet('page_size', 20);
            $search = $this->request->getGet('search', '');
            
            // 构建查询
            $targetWebsiteModel->clear();
            
            if ($isActive !== '') {
                $targetWebsiteModel->where(TargetWebsiteModel::schema_fields_IS_ACTIVE, (int)$isActive);
            }
            
            if (!empty($search)) {
                $targetWebsiteModel->where(
                    '(' . TargetWebsiteModel::schema_fields_NAME . ' LIKE ? OR ' . 
                    TargetWebsiteModel::schema_fields_DOMAIN . ' LIKE ?)',
                    ['%' . $search . '%', '%' . $search . '%']
                );
            }
            
            // 分页查询
            $targetWebsiteModel->order(TargetWebsiteModel::schema_fields_SORT_ORDER, 'ASC')
                ->order(TargetWebsiteModel::schema_fields_NAME, 'ASC')
                ->pagination($page, $pageSize)
                ->select()
                ->fetch();
            
            $items = $targetWebsiteModel->getItems();
            $paginationHtml = $targetWebsiteModel->getPagination();
            
            // 处理数据
            $data = [];
            foreach ($items as $item) {
                $data[] = [
                    'target_website_id' => $item->getData(TargetWebsiteModel::schema_fields_ID),
                    'name' => $item->getData(TargetWebsiteModel::schema_fields_NAME),
                    'domain' => $item->getData(TargetWebsiteModel::schema_fields_DOMAIN),
                    'search_syntax_template' => $item->getData(TargetWebsiteModel::schema_fields_SEARCH_SYNTAX_TEMPLATE),
                    'is_active' => (bool)$item->getData(TargetWebsiteModel::schema_fields_IS_ACTIVE),
                    'sort_order' => (int)$item->getData(TargetWebsiteModel::schema_fields_SORT_ORDER),
                    'description' => $item->getData(TargetWebsiteModel::schema_fields_DESCRIPTION),
                    'icon_url' => $item->getData(TargetWebsiteModel::schema_fields_ICON_URL),
                    'created_at' => $item->getData(TargetWebsiteModel::schema_fields_CREATED_AT),
                    'updated_at' => $item->getData(TargetWebsiteModel::schema_fields_UPDATED_AT),
                ];
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $data,
                'pagination' => $paginationHtml,
                'total' => $targetWebsiteModel->getTotalCount(),
                'page' => $page,
                'page_size' => $pageSize,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取列表失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取所有启用的目标网站列表（用于任务创建弹窗）
     */
    #[Acl(
        'Weline_AutoLeadAgent::target_website_list_active',
        '获取启用的目标网站列表',
        'mdi-format-list-bulleted',
        '获取所有启用的目标网站列表'
    )]
    public function listActive()
    {
        if (!$this->request->isAjax()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持AJAX请求'),
            ]);
        }

        try {
            /** @var TargetWebsiteModel $targetWebsiteModel */
            $targetWebsiteModel = ObjectManager::getInstance(TargetWebsiteModel::class);
            
            $websites = $targetWebsiteModel->getActiveWebsites();
            
            // 处理数据
            $data = [];
            foreach ($websites as $website) {
                $data[] = [
                    'target_website_id' => $website->getData(TargetWebsiteModel::schema_fields_ID),
                    'name' => $website->getData(TargetWebsiteModel::schema_fields_NAME),
                    'domain' => $website->getData(TargetWebsiteModel::schema_fields_DOMAIN),
                    'search_syntax_template' => $website->getData(TargetWebsiteModel::schema_fields_SEARCH_SYNTAX_TEMPLATE),
                    'description' => $website->getData(TargetWebsiteModel::schema_fields_DESCRIPTION),
                    'icon_url' => $website->getData(TargetWebsiteModel::schema_fields_ICON_URL),
                ];
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取列表失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 保存目标网站（新增/编辑）
     */
    #[Acl(
        'Weline_AutoLeadAgent::target_website_save',
        '保存目标网站',
        'mdi-content-save',
        '保存搜索目标网站'
    )]
    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        try {
            /** @var TargetWebsiteModel $targetWebsiteModel */
            $targetWebsiteModel = ObjectManager::getInstance(TargetWebsiteModel::class);
            
            $targetWebsiteId = (int)$this->request->getPost('target_website_id', 0);
            $name = trim($this->request->getPost('name', ''));
            $domain = trim($this->request->getPost('domain', ''));
            $searchSyntaxTemplate = trim($this->request->getPost('search_syntax_template', ''));
            $isActive = (int)$this->request->getPost('is_active', 1);
            $sortOrder = (int)$this->request->getPost('sort_order', 0);
            $description = trim($this->request->getPost('description', ''));
            $iconUrl = trim($this->request->getPost('icon_url', ''));
            
            // 验证
            if (empty($name)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('网站名称不能为空'),
                ]);
            }
            
            if (empty($domain)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('域名不能为空'),
                ]);
            }
            
            if (empty($searchSyntaxTemplate)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('搜索语法模板不能为空'),
                ]);
            }
            
            // 编辑或新增
            if ($targetWebsiteId > 0) {
                $targetWebsiteModel->clear()->load($targetWebsiteId);
                if (!$targetWebsiteModel->getId()) {
                    return $this->fetchJson([
                        'success' => false,
                        'message' => __('目标网站不存在'),
                    ]);
                }
            } else {
                $targetWebsiteModel->clear();
            }
            
            // 检查域名是否已存在（排除当前记录）
            $existing = $targetWebsiteModel->clear()->getByDomain($domain);
            if ($existing && $existing->getId() != $targetWebsiteId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('该域名已存在'),
                ]);
            }
            
            // 设置数据
            $targetWebsiteModel->setData(TargetWebsiteModel::schema_fields_NAME, $name)
                ->setData(TargetWebsiteModel::schema_fields_DOMAIN, $domain)
                ->setData(TargetWebsiteModel::schema_fields_SEARCH_SYNTAX_TEMPLATE, $searchSyntaxTemplate)
                ->setData(TargetWebsiteModel::schema_fields_IS_ACTIVE, $isActive)
                ->setData(TargetWebsiteModel::schema_fields_SORT_ORDER, $sortOrder)
                ->setData(TargetWebsiteModel::schema_fields_DESCRIPTION, $description)
                ->setData(TargetWebsiteModel::schema_fields_ICON_URL, $iconUrl ?: null)
                ->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => $targetWebsiteId > 0 ? __('更新成功') : __('创建成功'),
                'data' => [
                    'target_website_id' => $targetWebsiteModel->getId(),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 删除目标网站
     */
    #[Acl(
        'Weline_AutoLeadAgent::target_website_delete',
        '删除目标网站',
        'mdi-delete',
        '删除搜索目标网站'
    )]
    public function delete()
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        try {
            /** @var TargetWebsiteModel $targetWebsiteModel */
            $targetWebsiteModel = ObjectManager::getInstance(TargetWebsiteModel::class);
            
            $targetWebsiteId = (int)$this->request->getPost('target_website_id', 0);
            
            if ($targetWebsiteId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('目标网站ID无效'),
                ]);
            }
            
            $targetWebsiteModel->clear()->load($targetWebsiteId);
            if (!$targetWebsiteModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('目标网站不存在'),
                ]);
            }
            
            $targetWebsiteModel->delete();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('删除成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 启用/禁用目标网站
     */
    #[Acl(
        'Weline_AutoLeadAgent::target_website_toggle_active',
        '启用/禁用目标网站',
        'mdi-toggle-switch',
        '切换目标网站启用状态'
    )]
    public function toggleActive()
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        try {
            /** @var TargetWebsiteModel $targetWebsiteModel */
            $targetWebsiteModel = ObjectManager::getInstance(TargetWebsiteModel::class);
            
            $targetWebsiteId = (int)$this->request->getPost('target_website_id', 0);
            
            if ($targetWebsiteId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('目标网站ID无效'),
                ]);
            }
            
            $targetWebsiteModel->clear()->load($targetWebsiteId);
            if (!$targetWebsiteModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('目标网站不存在'),
                ]);
            }
            
            $currentStatus = (int)$targetWebsiteModel->getData(TargetWebsiteModel::schema_fields_IS_ACTIVE);
            $newStatus = $currentStatus ? 0 : 1;
            
            $targetWebsiteModel->setData(TargetWebsiteModel::schema_fields_IS_ACTIVE, $newStatus)->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => $newStatus ? __('已启用') : __('已禁用'),
                'data' => [
                    'is_active' => (bool)$newStatus,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('操作失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }
}

