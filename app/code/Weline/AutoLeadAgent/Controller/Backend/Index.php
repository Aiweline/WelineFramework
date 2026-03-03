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
use Weline\AutoLeadAgent\Model\SearchTask;
use Weline\AutoLeadAgent\Model\LeadCandidate;
use Weline\AutoLeadAgent\Service\LeadSearchService;
use Weline\AutoLeadAgent\Service\SearchEngineMappingService;
use WeShop\Store\Model\Store;
use Weline\Framework\Manager\ObjectManager;
use Weline\TranslationService\Api\TranslationServiceInterface;

/**
 * 后台管理控制器
 */
#[Acl(
    'Weline_AutoLeadAgent::manage',
    '自动寻客管理',
    'mdi-account-search',
    '管理自动寻客任务',
    'Weline_AutoLeadAgent::auto_lead_agent'
)]
class Index extends BackendController
{
    /**
     * 首页
     */
    #[Acl(
        'Weline_AutoLeadAgent::task_index',
        '查看自动寻客任务',
        'mdi-format-list-bulleted',
        '查看自动寻客任务列表'
    )]
    public function index()
    {
        // 分页参数
        $page = max(1, (int)$this->request->getParam('page', 1));
        $pageSize = max(1, min(100, (int)$this->request->getParam('pageSize', 20)));

        // 获取任务列表（使用 ORM 内置分页）
        /** @var SearchTask $taskModel */
        $taskModel = ObjectManager::getInstance(SearchTask::class);
        $taskModel->clear()
            ->order(SearchTask::fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize)
            ->select()
            ->fetch();
        
        $tasks = $taskModel->getItems();
        $paginationHtml = $taskModel->getPagination();

        // 获取潜在客户统计
        /** @var LeadCandidate $candidateModel */
        $candidateModel = ObjectManager::getInstance(LeadCandidate::class);
        $candidateCount = $candidateModel->clear()
            ->count();

        // 获取最新的未完成任务（用于弹窗提示）
        /** @var LeadSearchService $leadSearchService */
        $leadSearchService = ObjectManager::getInstance(LeadSearchService::class);
        $latestActiveTask = null;
        try {
            $latestActiveTask = $leadSearchService->getLatestActiveTask();
        } catch (\Throwable $e) {
            // 静默失败，避免影响后台页面
        }

        // 获取可用店铺列表
        /** @var Store $storeModel */
        $storeModel = ObjectManager::getInstance(Store::class);
        $stores = $storeModel->reset()
            ->where(Store::fields_STATUS, Store::STATUS_ENABLED)
            ->order(Store::fields_NAME, 'ASC')
            ->fetch()
            ->getItems();

        $this->assign('tasks', $tasks);
        $this->assign('candidate_count', $candidateCount);
        $this->assign('latest_active_task', $latestActiveTask);
        $this->assign('stores', $stores);
        $this->assign('pagination_html', $paginationHtml);
        
        return $this->fetch();
    }

    /**
     * 查看任务详情
     */
    #[Acl(
        'Weline_AutoLeadAgent::task_view',
        '查看自动寻客任务详情',
        'mdi-eye',
        '查看自动寻客任务的详细信息'
    )]
    public function view()
    {
        $taskId = (int)$this->request->getParam('taskId', 0);
        if ($taskId <= 0) {
            $this->getMessageManager()->addError(__('任务ID参数无效'));
            return $this->redirect($this->_url->getBackendUrl('auto-lead-agent/backend/index/index'));
        }

        /** @var SearchTask $taskModel */
        $taskModel = ObjectManager::getInstance(SearchTask::class);
        $task = $taskModel->load($taskId);

        if (!$task->getId()) {
            $this->getMessageManager()->addError(__('任务不存在：%{1}', [$taskId]));
            return $this->redirect($this->_url->getBackendUrl('auto-lead-agent/backend/index/index'));
        }

        // 获取任务关联的潜在客户
        /** @var LeadCandidate $candidateModel */
        $candidateModel = ObjectManager::getInstance(LeadCandidate::class);
        
        // 优先使用 source_id，如果没有则使用 store_id（向后兼容）
        $storeId = $task->getData(SearchTask::fields_STORE_ID);
        $sourceId = $task->getData(SearchTask::fields_SOURCE_ID);
        $queryStoreId = $sourceId ?: $storeId;
        
        $candidates = $candidateModel->clear()
            ->where(LeadCandidate::fields_STORE_ID, $queryStoreId)
            ->order(LeadCandidate::fields_CREATED_AT, 'DESC')
            ->pagination(1, 50)
            ->select()
            ->fetch()
            ->getItems();
        
        $candidatePagination = $candidateModel->getPagination();
        
        // 获取选中的搜索引擎和目标网站
        $selectedSearchEngines = $task->getSelectedSearchEnginesArray();
        $selectedTargetWebsites = $task->getSelectedTargetWebsitesArray();
        
        // 转换目标网站ID为名称
        $targetWebsiteNames = [];
        if (!empty($selectedTargetWebsites)) {
            /** @var \Weline\AutoLeadAgent\Model\TargetWebsite $targetWebsiteModel */
            $targetWebsiteModel = ObjectManager::getInstance(\Weline\AutoLeadAgent\Model\TargetWebsite::class);
            foreach ($selectedTargetWebsites as $websiteId) {
                // 如果已经是字符串（名称），直接使用
                if (is_string($websiteId) && !is_numeric($websiteId)) {
                    $targetWebsiteNames[] = $websiteId;
                    continue;
                }
                
                // 如果是数字ID，查询名称
                $website = $targetWebsiteModel->clear()->load((int)$websiteId);
                if ($website->getId()) {
                    $name = $website->getData(\Weline\AutoLeadAgent\Model\TargetWebsite::fields_NAME);
                    if ($name) {
                        $targetWebsiteNames[] = $name;
                    } else {
                        $targetWebsiteNames[] = $website->getData(\Weline\AutoLeadAgent\Model\TargetWebsite::fields_DOMAIN) ?: __('网站ID') . ': ' . $websiteId;
                    }
                } else {
                    $targetWebsiteNames[] = __('网站ID') . ': ' . $websiteId;
                }
            }
        }

        $this->assign('task', $task);
        $this->assign('candidates', $candidates);
        $this->assign('candidate_pagination', $candidatePagination);
        $this->assign('selected_search_engines', $selectedSearchEngines);
        $this->assign('selected_target_websites', $targetWebsiteNames);
        
        return $this->fetch();
    }

    /**
     * 创建搜索任务（AJAX）
     */
    #[Acl(
        'Weline_AutoLeadAgent::task_create',
        '创建自动寻客任务',
        'mdi-plus-circle',
        '创建新的自动寻客任务'
    )]
    public function createTask(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        $storeId = (int)$this->request->getPost('store_id', 0);
        $sourceType = $this->request->getPost('source_type', '');
        $sourceId = (int)$this->request->getPost('source_id', 0);
        
        // 获取选中的搜索引擎和目标网站
        $selectedSearchEngines = $this->request->getPost('selected_search_engines', []);
        $selectedTargetWebsites = $this->request->getPost('selected_target_websites', []);
        
        // 验证搜索引擎和目标网站选择
        if (empty($selectedSearchEngines) || !is_array($selectedSearchEngines)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请至少选择一个搜索引擎'),
            ]);
        }
        
        if (empty($selectedTargetWebsites) || !is_array($selectedTargetWebsites)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请至少选择一个目标网站'),
            ]);
        }
        
        // 兼容处理：如果没有提供sourceType和sourceId，使用storeId
        if (empty($sourceType) && $sourceId <= 0) {
        if ($storeId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('店铺ID参数无效'),
                ]);
            }
            $sourceType = 'store';
            $sourceId = $storeId;
        } else if ($sourceId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('来源ID参数无效'),
            ]);
        }

        try {
            /** @var LeadSearchService $leadSearchService */
            $leadSearchService = ObjectManager::getInstance(LeadSearchService::class);
            $taskId = $leadSearchService->createSearchTask(
                $storeId, 
                $sourceType, 
                $sourceId,
                $selectedSearchEngines,
                $selectedTargetWebsites
            );

            // 获取来源类型画像信息，供前端AI模型使用
            $sourceTypeProfile = $this->getSourceTypeProfile($sourceType, $sourceId);

            return $this->fetchJson([
                'success' => true,
                'message' => __('搜索任务创建成功'),
                'data'    => [
                    'task_id'            => $taskId,
                    'store_id'           => $storeId,
                    'source_type'        => $sourceType,
                    'source_id'          => $sourceId,
                    'source_type_profile' => $sourceTypeProfile,
                    'store_profile'      => $sourceTypeProfile, // 兼容字段
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('创建搜索任务失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取任务详情（AJAX）
     */
    #[Acl(
        'Weline_AutoLeadAgent::task_detail',
        '获取任务详情',
        'mdi-information',
        '获取任务详情数据'
    )]
    public function taskDetail(): string
    {
        if (!$this->request->isAjax()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持AJAX请求'),
            ]);
        }

        $taskId = (int)$this->request->getGet('task_id', 0);
        if ($taskId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('任务ID参数无效'),
            ]);
        }

        /** @var SearchTask $taskModel */
        $taskModel = ObjectManager::getInstance(SearchTask::class);
        $task = $taskModel->load($taskId);

        if (!$task->getId()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('搜索任务不存在：%{1}', [$taskId]),
            ]);
        }

        // 获取目标网站详细信息
        $targetWebsiteIds = $task->getSelectedTargetWebsitesArray();
        $targetWebsites = [];
        if (!empty($targetWebsiteIds)) {
            /** @var \Weline\AutoLeadAgent\Model\TargetWebsite $targetWebsiteModel */
            $targetWebsiteModel = ObjectManager::getInstance(\Weline\AutoLeadAgent\Model\TargetWebsite::class);
            foreach ($targetWebsiteIds as $websiteId) {
                $website = $targetWebsiteModel->clear()->load($websiteId);
                if ($website->getId()) {
                    $targetWebsites[] = [
                        'target_website_id' => $website->getId(),
                        'name' => $website->getData(\Weline\AutoLeadAgent\Model\TargetWebsite::fields_NAME),
                        'domain' => $website->getData(\Weline\AutoLeadAgent\Model\TargetWebsite::fields_DOMAIN),
                        'search_syntax_template' => $website->getData(\Weline\AutoLeadAgent\Model\TargetWebsite::fields_SEARCH_SYNTAX_TEMPLATE),
                    ];
                }
            }
        }

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'task_id' => $task->getId(),
                'store_id' => $task->getData(SearchTask::fields_STORE_ID),
                'source_type' => $task->getData(SearchTask::fields_SOURCE_TYPE),
                'source_id' => $task->getData(SearchTask::fields_SOURCE_ID),
                'status' => $task->getData(SearchTask::fields_STATUS),
                'found_count' => (int)$task->getData(SearchTask::fields_FOUND_COUNT),
                'selected_search_engines' => $task->getSelectedSearchEnginesArray(),
                'selected_target_websites' => $targetWebsites, // 返回完整的目标网站信息
                'created_at' => $task->getData(SearchTask::fields_CREATED_AT),
                'updated_at' => $task->getData(SearchTask::fields_UPDATED_AT),
            ],
        ]);
    }

    /**
     * 继续未完成任务（AJAX）
     */
    #[Acl(
        'Weline_AutoLeadAgent::task_continue',
        '继续自动寻客任务',
        'mdi-play-circle',
        '继续未完成的自动寻客任务'
    )]
    public function continueTask(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        $taskId = (int)$this->request->getPost('task_id', 0);
        if ($taskId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('任务ID参数无效'),
            ]);
        }

        /** @var SearchTask $taskModel */
        $taskModel = ObjectManager::getInstance(SearchTask::class);
        $task = $taskModel->load($taskId);

        if (!$task->getId()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('搜索任务不存在：%{1}', [$taskId]),
            ]);
        }

        $currentStatus = $task->getData(SearchTask::fields_STATUS);
        
        // 如果任务状态是 running，说明之前运行异常中断，自动标记为失败
        if ($currentStatus === SearchTask::STATUS_RUNNING) {
            /** @var LeadSearchService $leadSearchService */
            $leadSearchService = ObjectManager::getInstance(LeadSearchService::class);
            $leadSearchService->updateTaskStatus(
                $taskId,
                SearchTask::STATUS_FAILED,
                (float)$task->getData(SearchTask::fields_PROGRESS)
            );
            $currentStatus = SearchTask::STATUS_FAILED;
        }
        
        // 支持暂停状态也转换为失败后继续
        if ($currentStatus === 'paused') {
            /** @var LeadSearchService $leadSearchService */
            $leadSearchService = ObjectManager::getInstance(LeadSearchService::class);
            $leadSearchService->updateTaskStatus(
                $taskId,
                SearchTask::STATUS_FAILED,
                (float)$task->getData(SearchTask::fields_PROGRESS)
            );
            $currentStatus = SearchTask::STATUS_FAILED;
        }
        
        // 只允许从这些状态继续
        if (!in_array($currentStatus, [
            SearchTask::STATUS_PENDING,
            SearchTask::STATUS_FAILED,
            SearchTask::STATUS_CANCELLED,
        ], true)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('当前任务状态不允许继续：%{1}', [$currentStatus]),
            ]);
        }

        // 验证任务是否有搜索引擎和目标网站配置
        $selectedSearchEngines = $task->getSelectedSearchEnginesArray();
        $selectedTargetWebsites = $task->getSelectedTargetWebsitesArray();
        
        if (empty($selectedSearchEngines)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('任务缺少搜索引擎配置，无法执行。请先编辑任务并选择至少一个搜索引擎。'),
            ]);
        }
        
        if (empty($selectedTargetWebsites)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('任务缺少目标网站配置，无法执行。请先编辑任务并选择至少一个目标网站。'),
            ]);
        }

        /** @var LeadSearchService $leadSearchService */
        $leadSearchService = ObjectManager::getInstance(LeadSearchService::class);
        $updated = $leadSearchService->updateTaskStatus(
            $taskId,
            SearchTask::STATUS_RUNNING,
            (float)$task->getData(SearchTask::fields_PROGRESS)
        );

        if (!$updated) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('更新任务状态失败'),
            ]);
        }

        // 获取来源类型画像信息，供前端AI模型使用
        $storeId = (int)$task->getData(SearchTask::fields_STORE_ID);
        $sourceType = $task->getData(SearchTask::fields_SOURCE_TYPE);
        $sourceId = (int)$task->getData(SearchTask::fields_SOURCE_ID);
        
        // 兼容处理：如果没有sourceType和sourceId，使用storeId
        if (empty($sourceType) && $sourceId <= 0) {
            $sourceType = 'store';
            $sourceId = $storeId;
        }
        
        $sourceTypeProfile = $this->getSourceTypeProfile($sourceType, $sourceId);

        return $this->fetchJson([
            'success' => true,
            'message' => __('任务已继续执行'),
            'data'    => [
                'task_id'             => $taskId,
                'store_id'             => $storeId,
                'source_type'          => $sourceType,
                'source_id'            => $sourceId,
                'source_type_profile'  => $sourceTypeProfile,
                'store_profile'        => $sourceTypeProfile, // 兼容字段
            ],
        ]);
    }

    /**
     * 异步获取任务列表（AJAX）
     */
    #[Acl(
        'Weline_AutoLeadAgent::task_list',
        '获取任务列表',
        'mdi-format-list-bulleted',
        '异步获取自动寻客任务列表'
    )]
    public function taskList(): string
    {
        $page = max(1, (int)$this->request->getParam('page', 1));
        $pageSize = max(1, min(100, (int)$this->request->getParam('pageSize', 20)));

        /** @var SearchTask $taskModel */
        $taskModel = ObjectManager::getInstance(SearchTask::class);
        $taskModel->clear()
            ->order(SearchTask::fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize)
            ->select()
            ->fetch();

        $tasks = [];
        foreach ($taskModel->getItems() as $task) {
            $tasks[] = [
                'task_id'     => $task->getId(),
                'store_id'    => $task->getData(SearchTask::fields_STORE_ID), // 兼容字段
                'source_type' => $task->getData(SearchTask::fields_SOURCE_TYPE) ?: 'store', // 来源类型
                'source_id'   => (int)$task->getData(SearchTask::fields_SOURCE_ID) ?: (int)$task->getData(SearchTask::fields_STORE_ID), // 来源ID
                'status'      => $task->getData(SearchTask::fields_STATUS),
                'progress'    => (float)$task->getData(SearchTask::fields_PROGRESS),
                'found_count' => (int)$task->getData(SearchTask::fields_FOUND_COUNT),
                'created_at'  => $task->getData(SearchTask::fields_CREATED_AT),
            ];
        }

        return $this->fetchJson([
            'success' => true,
            'data'    => [
                'tasks' => $tasks,
                'page'  => $page,
                'pageSize' => $pageSize,
            ],
        ]);
    }

    /**
     * 标记中断的 running 任务为异常（AJAX）
     * 当前端检测到模型没有运行但有 running 状态任务时调用
     */
    #[Acl(
        'Weline_AutoLeadAgent::task_mark_interrupted',
        '标记中断任务',
        'mdi-alert-circle',
        '标记异常中断的任务'
    )]
    public function markInterrupted(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        $taskId = (int)$this->request->getPost('task_id', 0);
        
        /** @var SearchTask $taskModel */
        $taskModel = ObjectManager::getInstance(SearchTask::class);
        
        // 如果没有指定 taskId，查找所有 running 状态的任务
        if ($taskId <= 0) {
            $taskModel->clear()
                ->where(SearchTask::fields_STATUS, SearchTask::STATUS_RUNNING)
                ->select()
                ->fetch();
            $runningTasks = $taskModel->getItems();
            
            $markedCount = 0;
            /** @var LeadSearchService $leadSearchService */
            $leadSearchService = ObjectManager::getInstance(LeadSearchService::class);
            
            foreach ($runningTasks as $task) {
                $leadSearchService->updateTaskStatus(
                    (int)$task->getId(),
                    SearchTask::STATUS_FAILED,
                    (float)$task->getData(SearchTask::fields_PROGRESS)
                );
                $markedCount++;
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('已标记 %{1} 个中断任务为异常', [$markedCount]),
                'data'    => ['marked_count' => $markedCount],
            ]);
        }

        // 指定了 taskId，只处理该任务
        $task = $taskModel->load($taskId);
        if (!$task->getId()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('任务不存在'),
            ]);
        }

        if ($task->getData(SearchTask::fields_STATUS) !== SearchTask::STATUS_RUNNING) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('任务状态不是运行中'),
            ]);
        }

        /** @var LeadSearchService $leadSearchService */
        $leadSearchService = ObjectManager::getInstance(LeadSearchService::class);
        $leadSearchService->updateTaskStatus(
            $taskId,
            SearchTask::STATUS_FAILED,
            (float)$task->getData(SearchTask::fields_PROGRESS)
        );

        return $this->fetchJson([
            'success' => true,
            'message' => __('任务已标记为异常中断'),
        ]);
    }

    /**
     * 获取可用店铺列表（AJAX）
     */
    #[Acl(
        'Weline_AutoLeadAgent::store_list',
        '获取店铺列表',
        'mdi-store',
        '获取可用于自动寻客的店铺列表'
    )]
    public function getStores(): string
    {
        /** @var Store $storeModel */
        $storeModel = ObjectManager::getInstance(Store::class);

        $storeModel->clear()
            ->where(Store::fields_STATUS, Store::STATUS_ENABLED)
            ->order(Store::fields_NAME, 'ASC')
            ->fetch();

        $items = $storeModel->getItems();
        $stores = [];
        foreach ($items as $store) {
            if (!$store instanceof Store) {
                continue;
            }
            $stores[] = [
                'store_id'   => $store->getId(),
                'name'       => $store->getData(Store::fields_NAME),
                'code'       => $store->getData(Store::fields_CODE),
                'website_id' => $store->getData(Store::fields_WEBSITE_ID),
            ];
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('获取店铺列表成功'),
            'data'    => $stores,
        ]);
    }

    /**
     * 更新任务进度（供前端AI模型调用）
     * 
     * @param int task_id 任务ID
     * @param string status 状态（可选）
     * @param int found_count 找到的客户数量（可选）
     */
    #[Acl(
        'Weline_AutoLeadAgent::task_progress',
        '更新任务进度',
        'mdi-progress-check',
        '更新自动寻客任务的执行进度'
    )]
    public function postTaskProgress(): string
    {

        $taskId = (int)$this->request->getPost('task_id', 0);
        $status = (string)$this->request->getPost('status', '');
        $foundCount = $this->request->getPost('found_count');

        if ($taskId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('任务ID参数无效'),
            ]);
        }

        try {
            /** @var SearchTask $taskModel */
            $taskModel = ObjectManager::getInstance(SearchTask::class);
            $task = $taskModel->load($taskId);
            
            if (!$task->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('任务不存在'),
                ]);
            }

            // 更新状态
            if (!empty($status)) {
                $task->setData(SearchTask::fields_STATUS, $status);
            }
            
            // 更新找到的客户数量
            if ($foundCount !== null) {
                $task->setData(SearchTask::fields_FOUND_COUNT, (int)$foundCount);
            }
            
            $task->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('进度更新成功'),
                'data'    => [
                    'task_id'     => $taskId,
                    'status'      => $task->getData(SearchTask::fields_STATUS),
                    'found_count' => (int)$task->getData(SearchTask::fields_FOUND_COUNT),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('更新进度失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 保存候选客户（供前端AI模型调用）
     */
    #[Acl(
        'Weline_AutoLeadAgent::save_candidates',
        '保存候选客户',
        'mdi-account-plus',
        '保存AI模型发现的候选客户'
    )]
    public function saveCandidates(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        // 尝试从JSON body获取数据
        $bodyParams = $this->request->getBodyParams();
        $data = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? json_decode($bodyParams, true) : null);
        
        $taskId = (int)($data['task_id'] ?? 0);
        $candidates = $data['candidates'] ?? [];

        if ($taskId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('任务ID参数无效'),
            ]);
        }

        if (empty($candidates) || !is_array($candidates)) {
            return $this->fetchJson([
                'success' => true,
                'message' => __('没有候选客户需要保存'),
                'data'    => ['saved_count' => 0],
            ]);
        }

        try {
            // 获取任务信息
            /** @var SearchTask $taskModel */
            $taskModel = ObjectManager::getInstance(SearchTask::class);
            $task = $taskModel->load($taskId);

            if (!$task->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('任务不存在：%{1}', [$taskId]),
                ]);
            }

            $storeId = (int)$task->getData(SearchTask::fields_STORE_ID);
            $savedCount = 0;

            // 保存每个候选客户
            foreach ($candidates as $candidate) {
                /** @var LeadCandidate $candidateModel */
                $candidateModel = ObjectManager::getInstance(LeadCandidate::class);
                
                // 构建profile_data（包含所有原始数据）
                $profileData = $candidate;
                
                $candidateModel->clear()
                    ->setData(LeadCandidate::fields_STORE_ID, $storeId)
                    ->setData(LeadCandidate::fields_PROFILE_DATA, json_encode($profileData, JSON_UNESCAPED_UNICODE))
                    ->setData(LeadCandidate::fields_SCORE, (float)($candidate['score'] ?? 0))
                    ->setData(LeadCandidate::fields_SOURCE_URL, $candidate['profileUrl'] ?? $candidate['url'] ?? '')
                    ->setData(LeadCandidate::fields_STATUS, 'pending');
                
                // 保存新字段
                if (!empty($candidate['email'])) {
                    $candidateModel->setData(LeadCandidate::fields_EMAIL, $candidate['email']);
                }
                if (!empty($candidate['phone'])) {
                    $candidateModel->setData(LeadCandidate::fields_PHONE, $candidate['phone']);
                }
                if (!empty($candidate['socialMediaAccounts'])) {
                    $candidateModel->setData(
                        LeadCandidate::fields_SOCIAL_MEDIA_ACCOUNTS,
                        json_encode($candidate['socialMediaAccounts'], JSON_UNESCAPED_UNICODE)
                    );
                }
                if (!empty($candidate['matchedTextSegments'])) {
                    $candidateModel->setData(
                        LeadCandidate::fields_MATCHED_TEXT_SEGMENTS,
                        json_encode($candidate['matchedTextSegments'], JSON_UNESCAPED_UNICODE)
                    );
                }
                // 保存所有搜索过的网址
                if (!empty($candidate['sourceUrls'])) {
                    $candidateModel->setData(
                        LeadCandidate::fields_SOURCE_URLS,
                        json_encode($candidate['sourceUrls'], JSON_UNESCAPED_UNICODE)
                    );
                }
                
                $candidateModel->save();
                $savedCount++;
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('成功保存 %{1} 位候选客户', [$savedCount]),
                'data'    => ['saved_count' => $savedCount],
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存候选客户失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 报告任务错误（供前端AI模型调用）
     */
    #[Acl(
        'Weline_AutoLeadAgent::report_error',
        '报告任务错误',
        'mdi-alert-circle',
        '报告AI模型执行任务时的错误'
    )]
    public function reportTaskError(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        $taskId = (int)$this->request->getPost('task_id', 0);
        $error = (string)$this->request->getPost('error', '');

        if ($taskId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('任务ID参数无效'),
            ]);
        }

        try {
            /** @var LeadSearchService $leadSearchService */
            $leadSearchService = ObjectManager::getInstance(LeadSearchService::class);
            $leadSearchService->updateTaskStatus($taskId, SearchTask::STATUS_FAILED, 0);

            // 可以将错误信息保存到任务的result_data字段
            /** @var SearchTask $taskModel */
            $taskModel = ObjectManager::getInstance(SearchTask::class);
            $task = $taskModel->load($taskId);
            if ($task->getId()) {
                $task->setData(SearchTask::fields_RESULT_DATA, json_encode([
                    'error' => $error,
                    'error_time' => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE))
                    ->save();
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('错误已记录'),
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('记录错误失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取来源类型画像信息
     * 
     * @param string $sourceType 来源类型
     * @param int $sourceId 来源ID
     * @return array
     */
    private function getSourceTypeProfile(string $sourceType, int $sourceId): array
    {
        try {
            /** @var LeadSearchService $leadSearchService */
            $leadSearchService = ObjectManager::getInstance(LeadSearchService::class);
            return $leadSearchService->getSourceTypeProfile($sourceType, $sourceId);
        } catch (\Throwable $e) {
            // 如果获取失败，返回默认画像
            return [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'id' => $sourceId,
                'name' => '来源 ' . $sourceId,
                'description' => '',
                'industry' => '通用',
                'region' => '',
                'keywords' => [],
                'products' => [],
            ];
        }
    }

    /**
     * 获取来源类型列表（AJAX）
     * 通过事件系统收集所有注册的来源类型
     */
    #[Acl(
        'Weline_AutoLeadAgent::get_source_types',
        '获取来源类型列表',
        'mdi-format-list-bulleted',
        '获取所有可用的来源类型及其选项'
    )]
    public function getSourceTypes(): string
    {
        if (!$this->request->isAjax()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持AJAX请求'),
            ]);
        }

        try {
            // 触发事件收集来源类型
            $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $event = new \Weline\Framework\Event\Event();
            $event->setData('source_types', []);
            $eventManager->dispatch('Weline_AutoLeadAgent::lead_search_task::collect_source_types', $event);
            
            $sourceTypes = $event->getData('source_types') ?? [];
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('获取来源类型列表成功'),
                'data' => $sourceTypes,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取来源类型列表失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 删除任务（AJAX）
     */
    #[Acl(
        'Weline_AutoLeadAgent::task_delete',
        '删除任务',
        'mdi-delete',
        '删除自动寻客任务'
    )]
    public function deleteTask(): string
    {
        // 支持 POST 和 DELETE 请求
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($requestMethod, ['POST', 'DELETE'], true)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST或DELETE请求'),
            ]);
        }

        try {
            // DELETE请求通常使用URL参数，POST请求使用body参数
            $taskId = (int)($this->request->getParam('task_id', 0) ?: $this->request->getPost('task_id', 0));
            
            if ($taskId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('任务ID无效'),
                ]);
            }
            
            /** @var SearchTask $taskModel */
            $taskModel = ObjectManager::getInstance(SearchTask::class);
            $task = $taskModel->load($taskId);
            
            if (!$task->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('任务不存在'),
                ]);
            }
            
            // 检查任务状态，如果正在运行则不允许删除
            $status = $task->getData(SearchTask::fields_STATUS);
            if (in_array($status, [SearchTask::STATUS_RUNNING, SearchTask::STATUS_INFERRING, SearchTask::STATUS_CRAWLING])) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('无法删除正在运行的任务，请先停止任务'),
                ]);
            }
            
            // 删除任务（使用ORM的正确删除方式）
            $taskModel->clear()
                ->where(SearchTask::fields_ID, $taskId)
                ->delete()
                ->fetch();
            
            // 验证删除是否成功
            $taskModel->clear()->load($taskId);
            if ($taskModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('任务删除失败，请重试'),
                ]);
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('任务删除成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取店铺画像信息（兼容方法）
     */
    private function getStoreProfile(int $storeId): array
    {
        try {
            /** @var Store $storeModel */
            $storeModel = ObjectManager::getInstance(Store::class);
            $store = $storeModel->load($storeId);

            if (!$store->getId()) {
                return [
                    'id' => $storeId,
                    'name' => '未知店铺',
                    'description' => '',
                    'industry' => '通用',
                    'products' => [],
                    'keywords' => [],
                ];
            }

            // 构建店铺画像
            $profile = [
                'id' => $storeId,
                'name' => $store->getData(Store::fields_NAME) ?? '',
                'code' => $store->getData(Store::fields_CODE) ?? '',
                'description' => $store->getData('description') ?? '',
                'industry' => $store->getData('industry') ?? '通用',
                'region' => $store->getData('region') ?? '',
                'products' => [],
                'keywords' => [],
            ];

            // 从店铺名称和描述提取关键词
            $text = $profile['name'] . ' ' . $profile['description'];
            $words = preg_split('/[\s,，。！？；：、]+/', $text);
            $keywords = [];
            foreach ($words as $word) {
                $word = trim($word);
                if (mb_strlen($word) >= 2 && mb_strlen($word) <= 10) {
                    $keywords[] = $word;
                }
            }
            $profile['keywords'] = array_unique(array_slice($keywords, 0, 20));

            return $profile;

        } catch (\Throwable $e) {
            return [
                'id' => $storeId,
                'name' => '店铺 ' . $storeId,
                'description' => '',
                'industry' => '通用',
                'products' => [],
                'keywords' => [],
            ];
        }
    }

    /**
     * 下载浏览器扩展包
     * 
     * 使用 DownloadException 机制，兼容 FPM 和 WLS 模式
     * 
     * @return never
     */
    #[Acl(
        'Weline_AutoLeadAgent::extension_download',
        '下载浏览器扩展',
        'mdi-download',
        '下载自动寻客浏览器扩展包'
    )]
    public function getDownloadExtension(): never
    {
        $extensionDir = BP . '/app/code/Weline/AutoLeadAgent/browser-extension';
        $zipFileName = 'AutoLeadAgent-Extension.zip';
        $tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipFileName;

        // 检查扩展目录是否存在
        if (!is_dir($extensionDir)) {
            throw new \Weline\Framework\Http\NoRouterException(404, __('扩展目录不存在'));
        }

        // 创建 ZIP 文件
        $zip = new \ZipArchive();
        if ($zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(__('无法创建ZIP文件'));
        }

        // 递归添加文件到 ZIP
        $this->addFilesToZip($zip, $extensionDir, 'AutoLeadAgent-Extension');
        $zip->close();

        // 通过 Response::download() 触发 DownloadException，兼容 FPM 和 WLS 模式
        // 第三个参数 true 表示下载完成后删除临时文件
        $this->request->getResponse()->download($tempZipPath, $zipFileName, true);
    }

    /**
     * 递归添加文件到 ZIP
     *
     * @param \ZipArchive $zip
     * @param string $sourceDir
     * @param string $zipPath
     * @return void
     */
    private function addFilesToZip(\ZipArchive $zip, string $sourceDir, string $zipPath): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getRealPath() ?: $file->getPathname();

            // 统一路径分隔符，避免在 Windows 下因反斜杠与正斜杠混用导致相对路径计算错误
            $normalizedSource = rtrim(str_replace('\\', '/', $sourceDir), '/');
            $normalizedPath   = str_replace('\\', '/', $filePath);

            if (strpos($normalizedPath, $normalizedSource) === 0) {
                $relative = ltrim(substr($normalizedPath, strlen($normalizedSource)), '/');
            } else {
                // 兜底：当前路径不包含预期前缀时，仅使用文件名，确保文件名不被截断
                $relative = basename($normalizedPath);
            }

            $zip->addFile($filePath, $zipPath . '/' . $relative);
        }
    }

    /**
     * 翻译关键词为英语
     * 使用 Google 翻译 API 作为主要翻译服务
     * 
     * @return string
     */
    #[Acl(
        'Weline_AutoLeadAgent::translate_keywords',
        '翻译搜索关键词',
        'mdi-translate',
        '将搜索关键词翻译为英语以提高搜索匹配度'
    )]
    public function postTranslateKeywords(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        $keywords = $this->request->getPost('keywords', []);
        $targetLang = $this->request->getPost('target_lang', 'en'); // 默认翻译为英语

        if (empty($keywords) || !is_array($keywords)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('关键词参数无效'),
            ]);
        }

        try {
            // 将关键词数组合并为文本（用逗号分隔）
            $textToTranslate = implode(', ', $keywords);
            
            // 使用 Google 翻译 API
            $translatedText = $this->translateWithGoogle($textToTranslate, $targetLang);
            
            if (empty($translatedText)) {
                // 如果 Google 翻译失败，尝试使用框架的翻译服务作为备用
                try {
                    /** @var TranslationServiceInterface $translationService */
                    $translationService = ObjectManager::getInstance(TranslationServiceInterface::class);
                    $translatedText = $translationService->translate(
                        $textToTranslate,
                        $targetLang,
                        'auto',
                        null,
                        ['module_name' => 'Weline_AutoLeadAgent']
                    );
                } catch (\Exception $e) {
                    w_log_error('Fallback translation failed: ' . $e->getMessage());
                }
            }
            
            // 如果仍然失败，尝试逐个翻译关键词
            if (empty($translatedText)) {
                $translatedKeywords = [];
                foreach ($keywords as $keyword) {
                    if (empty($keyword)) {
                        continue;
                    }
                    
                    $translated = $this->translateWithGoogle($keyword, $targetLang);
                    if (empty($translated)) {
                        // 如果单个关键词翻译也失败，抛出异常而不是使用原关键词
                        throw new \Exception(__('无法翻译关键词：%1', [$keyword]));
                    }
                    $translatedKeywords[] = $translated;
                }
                $translatedText = implode(', ', $translatedKeywords);
            }
            
            // 将翻译后的文本分割回数组
            $translatedKeywords = array_map('trim', explode(',', $translatedText));
            
            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'original' => $keywords,
                    'translated' => $translatedKeywords,
                    'target_lang' => $targetLang
                ],
                'message' => __('翻译完成'),
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('翻译失败：%1', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 检测 Google 服务是否可访问
     * 
     * @return bool 可访问返回 true，否则返回 false
     */
    private function isGoogleAccessible(): bool
    {
        static $cache = null;
        static $cacheTime = 0;
        $cacheDuration = 300; // 缓存5分钟
        
        // 使用缓存避免频繁检测
        if ($cache !== null && (time() - $cacheTime) < $cacheDuration) {
            return $cache;
        }
        
        try {
            // 尝试连接 Google 翻译服务的域名和端口
            $host = 'translate-pa.googleapis.com';
            $port = 443;
            $timeout = 2; // 2秒超时
            
            // 使用 fsockopen 检测端口连通性
            $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
            
            if ($connection) {
                fclose($connection);
                $cache = true;
                $cacheTime = time();
                return true;
            }
            
            // 如果 fsockopen 失败，尝试使用 curl 检测
            $ch = curl_init('https://' . $host);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true, // HEAD 请求
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            
            $result = @curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            $accessible = ($result !== false && $httpCode > 0 && empty($curlError));
            $cache = $accessible;
            $cacheTime = time();
            
            return $accessible;
            
        } catch (\Throwable $e) {
            w_log_error('Google accessibility check failed: ' . $e->getMessage());
            $cache = false;
            $cacheTime = time();
            return false;
        }
    }

    /**
     * 使用 Google 翻译 API 翻译文本
     * 
     * @param string $text 要翻译的文本
     * @param string $targetLang 目标语言代码（如 'en'）
     * @param string $sourceLang 源语言代码（默认 'auto' 自动检测）
     * @return string 翻译后的文本，失败返回空字符串
     */
    private function translateWithGoogle(string $text, string $targetLang, string $sourceLang = 'auto'): string
    {
        if (empty($text) || trim($text) === '') {
            return '';
        }

        // 先检测 Google 服务是否可访问
        if (!$this->isGoogleAccessible()) {
            w_log_warning('Google Translate service is not accessible, skipping');
            return '';
        }

        try {
            // 标准化语言代码
            $finalTarget = $this->normalizeGoogleLang($targetLang);
            $finalSource = $sourceLang === 'auto' ? 'auto' : $this->normalizeGoogleLang($sourceLang);
            
            $cleanText = trim($text);
            $apiKey = 'AIzaSyATBXajvzQLTDHEQbcpq0Ihe0vWDHmO520'; // Google 翻译 API Key
            
            // 构建请求体
            $requestBody = json_encode([[[$cleanText], $finalSource, $finalTarget], 'te_lib']);
            
            // 使用 cURL 发送请求
            $ch = curl_init('https://translate-pa.googleapis.com/v1/translateHtml');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json+protobuf',
                    'x-goog-api-key: ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => $requestBody,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                w_log_error('Google Translate cURL error: ' . $curlError);
                return '';
            }
            
            if ($httpCode !== 200) {
                w_log_error('Google Translate HTTP error: ' . $httpCode . ' - ' . $response);
                return '';
            }
            
            $data = json_decode($response, true);
            
            if (!is_array($data) || empty($data) || !is_array($data[0])) {
                w_log_error('Google Translate invalid response format');
                return '';
            }
            
            $result = $data[0];
            $translatedText = $result[0] ?? '';
            
            if (is_array($translatedText)) {
                $translatedText = $translatedText[0] ?? '';
            }
            
            if (is_string($translatedText)) {
                // 移除末尾的单个点（Google 翻译有时会添加）
                $translatedText = preg_replace('/\.+$/', '', $translatedText);
            }
            
            return (string)$translatedText;
            
        } catch (\Throwable $e) {
            w_log_error('Google Translate exception: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 标准化 Google 语言代码
     * 
     * @param string $lang 语言代码
     * @return string 标准化后的语言代码
     */
    private function normalizeGoogleLang(string $lang): string
    {
        $langMap = [
            'zh' => 'zh-CN',
            'zh-cn' => 'zh-CN',
            'zh-hans' => 'zh-CN',
            'zh-hans-cn' => 'zh-CN',
            'en' => 'en',
            'en-us' => 'en',
            'ja' => 'ja',
            'ko' => 'ko',
            'es' => 'es',
            'fr' => 'fr',
            'de' => 'de',
            'ru' => 'ru',
            'pt' => 'pt',
            'it' => 'it',
            'ar' => 'ar',
        ];
        
        $normalized = strtolower($lang);
        return $langMap[$normalized] ?? $lang;
    }

    /**
     * 获取场景映射规则（根据地区/语言）
     * 
     * @return string
     */
    #[Acl(
        'Weline_AutoLeadAgent::get_scene_mapping',
        '获取场景映射规则',
        'mdi-map',
        '获取画像到场景的映射规则（支持多语言/多地区）'
    )]
    public function getSceneMapping(): string
    {
        try {
            $region = $this->request->getParam('region', '');
            $language = $this->request->getParam('language', '');
            
            /** @var \Weline\AutoLeadAgent\Service\SceneMappingService $sceneMappingService */
            $sceneMappingService = ObjectManager::getInstance(\Weline\AutoLeadAgent\Service\SceneMappingService::class);
            
            $mapping = $sceneMappingService->getSceneMapping($region, $language);
            
            return $this->fetchJson([
                'success' => true,
                'data' => $mapping,
                'region' => $region,
                'language' => $language,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取场景映射规则失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }
}

