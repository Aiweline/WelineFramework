<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\AutoLeadAgent\Service\LeadSearchService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 搜索API控制器
 * 
 * 提供搜索任务创建和结果查询接口
 */
class Search extends FrontendRestController
{
    /**
     * POST /api/v1/auto-lead-agent/search/create
     * 创建搜索任务
     */
    public function create(): string
    {
        try {
            $params = $this->request->getBodyParams();
            $storeId = (int)($params['store_id'] ?? 0);

            if ($storeId <= 0) {
                return $this->error(__('店铺ID参数无效'), [], 400);
            }

            /** @var LeadSearchService $searchService */
            $searchService = ObjectManager::getInstance(LeadSearchService::class);
            $taskId = $searchService->createSearchTask($storeId);

            return $this->success(__('搜索任务创建成功'), [
                'task_id' => $taskId,
                'store_id' => $storeId,
            ]);

        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    /**
     * GET /api/v1/auto-lead-agent/search/{taskId}
     * 获取搜索结果
     */
    public function get(): string
    {
        try {
            $taskId = (int)($this->request->getParam('taskId') ?? $this->request->getParam('task_id') ?? 0);

            if ($taskId <= 0) {
                return $this->error(__('任务ID参数无效'), [], 400);
            }

            /** @var LeadSearchService $searchService */
            $searchService = ObjectManager::getInstance(LeadSearchService::class);
            $results = $searchService->getSearchResults($taskId);

            return $this->success(__('获取搜索结果成功'), $results);

        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }
}

