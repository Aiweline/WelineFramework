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
use Weline\AutoLeadAgent\Model\SearchEngineMapping;

/**
 * 搜索引擎映射管理控制器
 */
#[Acl(
    'Weline_AutoLeadAgent::mapping',
    '搜索引擎映射管理',
    'mdi-map-marker-multiple',
    '管理地区-语言-搜索引擎映射关系',
    'Weline_AutoLeadAgent::auto_lead_agent'
)]
class Mapping extends BackendController
{
    /**
     * 映射列表页面
     */
    #[Acl(
        'Weline_AutoLeadAgent::mapping_index',
        '查看映射列表',
        'mdi-format-list-bulleted',
        '查看搜索引擎映射列表'
    )]
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 获取映射列表（AJAX）
     */
    #[Acl(
        'Weline_AutoLeadAgent::mapping_list',
        '获取映射列表',
        'mdi-format-list-bulleted',
        '获取搜索引擎映射列表数据'
    )]
    public function getList()
    {
        if (!$this->request->isAjax()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持AJAX请求'),
            ]);
        }

        try {
            /** @var SearchEngineMapping $mappingModel */
            $mappingModel = ObjectManager::getInstance(SearchEngineMapping::class);
            
            // 获取筛选参数
            $region = $this->request->getGet('region', '');
            $language = $this->request->getGet('language', '');
            $isActive = $this->request->getGet('is_active', '');
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = (int)$this->request->getGet('page_size', 20);
            $search = $this->request->getGet('search', '');
            
            // 构建查询
            $mappingModel->clear();
            
            if (!empty($region)) {
                $mappingModel->where(SearchEngineMapping::schema_fields_REGION, '%' . $region . '%', 'like');
            }
            
            if (!empty($language)) {
                $mappingModel->where(SearchEngineMapping::schema_fields_LANGUAGE, '%' . $language . '%', 'like');
            }
            
            if ($isActive !== '') {
                $mappingModel->where(SearchEngineMapping::schema_fields_IS_ACTIVE, (int)$isActive);
            }
            
            if (!empty($search)) {
                $mappingModel->where(
                    '(' . SearchEngineMapping::schema_fields_REGION . ' LIKE ? OR ' . 
                    SearchEngineMapping::schema_fields_LANGUAGE . ' LIKE ?)',
                    ['%' . $search . '%', '%' . $search . '%']
                );
            }
            
            // 分页查询
            $mappingModel->order(SearchEngineMapping::schema_fields_SORT_ORDER, 'ASC')
                ->order(SearchEngineMapping::schema_fields_REGION, 'ASC')
                ->order(SearchEngineMapping::schema_fields_LANGUAGE, 'ASC')
                ->pagination($page, $pageSize)
                ->select()
                ->fetch();
            
            $items = $mappingModel->getItems();
            $paginationHtml = $mappingModel->getPagination();
            
            // 处理数据
            $data = [];
            foreach ($items as $item) {
                $data[] = [
                    'mapping_id' => $item->getData(SearchEngineMapping::schema_fields_ID),
                    'region' => $item->getData(SearchEngineMapping::schema_fields_REGION),
                    'language' => $item->getData(SearchEngineMapping::schema_fields_LANGUAGE),
                    'search_engines' => $item->getSearchEnginesArray(),
                    'is_active' => (bool)$item->getData(SearchEngineMapping::schema_fields_IS_ACTIVE),
                    'sort_order' => (int)$item->getData(SearchEngineMapping::schema_fields_SORT_ORDER),
                    'created_at' => $item->getData(SearchEngineMapping::schema_fields_CREATED_AT),
                    'updated_at' => $item->getData(SearchEngineMapping::schema_fields_UPDATED_AT),
                ];
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => $data,
                'pagination' => $paginationHtml,
                'total' => $mappingModel->getTotalCount(),
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
     * 保存映射（新增/编辑）
     */
    #[Acl(
        'Weline_AutoLeadAgent::mapping_save',
        '保存映射',
        'mdi-content-save',
        '保存搜索引擎映射'
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
            /** @var SearchEngineMapping $mappingModel */
            $mappingModel = ObjectManager::getInstance(SearchEngineMapping::class);
            
            $mappingId = (int)$this->request->getPost('mapping_id', 0);
            $region = trim($this->request->getPost('region', ''));
            $language = trim($this->request->getPost('language', ''));
            $searchEngines = $this->request->getPost('search_engines', []);
            $isActive = (int)$this->request->getPost('is_active', 1);
            $sortOrder = (int)$this->request->getPost('sort_order', 0);
            
            // 验证必填字段
            if (empty($region)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('地区名称不能为空'),
                ]);
            }
            
            if (empty($language)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('语言代码不能为空'),
                ]);
            }
            
            if (empty($searchEngines) || !is_array($searchEngines)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('至少需要选择一个搜索引擎'),
                ]);
            }
            
            // 如果是编辑，加载现有数据
            if ($mappingId > 0) {
                $mappingModel->load($mappingId);
                if (!$mappingModel->getId()) {
                    return $this->fetchJson([
                        'success' => false,
                        'message' => __('映射记录不存在'),
                    ]);
                }
            } else {
                // 检查是否已存在相同的地区-语言组合
                $existing = $mappingModel->clear()
                    ->where(SearchEngineMapping::schema_fields_REGION, $region)
                    ->where(SearchEngineMapping::schema_fields_LANGUAGE, $language)
                    ->find()
                    ->fetch();
                
                if ($existing && $existing->getId()) {
                    return $this->fetchJson([
                        'success' => false,
                        'message' => __('该地区-语言组合已存在'),
                    ]);
                }
            }
            
            // 设置数据
            $mappingModel->setData(SearchEngineMapping::schema_fields_REGION, $region)
                ->setData(SearchEngineMapping::schema_fields_LANGUAGE, $language)
                ->setSearchEnginesArray($searchEngines)
                ->setData(SearchEngineMapping::schema_fields_IS_ACTIVE, $isActive)
                ->setData(SearchEngineMapping::schema_fields_SORT_ORDER, $sortOrder);
            
            // 验证
            if (!$mappingModel->validate()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $mappingModel->getError() ?: __('数据验证失败'),
                ]);
            }
            
            // 保存
            $mappingModel->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => $mappingId > 0 ? __('映射更新成功') : __('映射创建成功'),
                'data' => [
                    'mapping_id' => $mappingModel->getId(),
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
     * 删除映射
     */
    #[Acl(
        'Weline_AutoLeadAgent::mapping_delete',
        '删除映射',
        'mdi-delete',
        '删除搜索引擎映射'
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
            /** @var SearchEngineMapping $mappingModel */
            $mappingModel = ObjectManager::getInstance(SearchEngineMapping::class);
            
            $mappingId = (int)$this->request->getPost('mapping_id', 0);
            
            if ($mappingId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('映射ID无效'),
                ]);
            }
            
            $mappingModel->load($mappingId);
            
            if (!$mappingModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('映射记录不存在'),
                ]);
            }
            
            $mappingModel->delete();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('映射删除成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取表单数据（编辑时）
     */
    #[Acl(
        'Weline_AutoLeadAgent::mapping_get_form',
        '获取表单数据',
        'mdi-form-select',
        '获取映射表单数据'
    )]
    public function getForm()
    {
        if (!$this->request->isAjax()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持AJAX请求'),
            ]);
        }

        try {
            /** @var SearchEngineMapping $mappingModel */
            $mappingModel = ObjectManager::getInstance(SearchEngineMapping::class);
            
            $mappingId = (int)$this->request->getGet('mapping_id', 0);
            
            if ($mappingId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('映射ID无效'),
                ]);
            }
            
            $mappingModel->load($mappingId);
            
            if (!$mappingModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('映射记录不存在'),
                ]);
            }
            
            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'mapping_id' => $mappingModel->getData(SearchEngineMapping::schema_fields_ID),
                    'region' => $mappingModel->getData(SearchEngineMapping::schema_fields_REGION),
                    'language' => $mappingModel->getData(SearchEngineMapping::schema_fields_LANGUAGE),
                    'search_engines' => $mappingModel->getSearchEnginesArray(),
                    'is_active' => (bool)$mappingModel->getData(SearchEngineMapping::schema_fields_IS_ACTIVE),
                    'sort_order' => (int)$mappingModel->getData(SearchEngineMapping::schema_fields_SORT_ORDER),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取数据失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 切换启用状态
     */
    #[Acl(
        'Weline_AutoLeadAgent::mapping_toggle',
        '切换启用状态',
        'mdi-toggle-switch',
        '切换映射启用状态'
    )]
    public function toggle()
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        try {
            /** @var SearchEngineMapping $mappingModel */
            $mappingModel = ObjectManager::getInstance(SearchEngineMapping::class);
            
            $mappingId = (int)$this->request->getPost('mapping_id', 0);
            
            if ($mappingId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('映射ID无效'),
                ]);
            }
            
            $mappingModel->load($mappingId);
            
            if (!$mappingModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('映射记录不存在'),
                ]);
            }
            
            $currentStatus = (int)$mappingModel->getData(SearchEngineMapping::schema_fields_IS_ACTIVE);
            $newStatus = $currentStatus ? 0 : 1;
            
            $mappingModel->setData(SearchEngineMapping::schema_fields_IS_ACTIVE, $newStatus)
                ->save();
            
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

