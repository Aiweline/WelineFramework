<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Service;

use Weline\AutoLeadAgent\Model\SearchTask;
use Weline\AutoLeadAgent\Model\LeadCandidate;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;
use Weline\AutoLeadAgent\Service\SourceTypeHandlerInterface;
use Weline\AutoLeadAgent\Service\StoreProfileService;

/**
 * 寻客服务类
 * 
 * 负责创建搜索任务和管理搜索结果
 */
class LeadSearchService
{
    /**
     * 创建搜索任务
     * 
     * @param int $storeId 店铺ID（兼容字段，向后兼容）
     * @param string|null $sourceType 来源类型（如 'store'）
     * @param int|null $sourceId 来源ID（如店铺ID）
     * @param array $selectedSearchEngines 选中的搜索引擎列表
     * @param array $selectedTargetWebsites 选中的目标网站列表
     * @return int 任务ID
     * @throws Exception
     */
    public function createSearchTask(
        int $storeId, 
        ?string $sourceType = null, 
        ?int $sourceId = null,
        array $selectedSearchEngines = [],
        array $selectedTargetWebsites = []
    ): int
    {
        try {
            // 验证搜索引擎和目标网站
            if (empty($selectedSearchEngines) || !is_array($selectedSearchEngines)) {
                throw new Exception(__('必须至少选择一个搜索引擎'));
            }
            
            if (empty($selectedTargetWebsites) || !is_array($selectedTargetWebsites)) {
                throw new Exception(__('必须至少选择一个目标网站'));
            }
            
            /** @var SearchTask $taskModel */
            $taskModel = ObjectManager::getInstance(SearchTask::class);
            
            // 如果没有提供sourceType和sourceId，使用storeId作为兼容处理
            if ($sourceType === null && $sourceId === null) {
                $sourceType = 'store';
                $sourceId = $storeId;
            }
            
            $taskModel->clear()
                ->setData(SearchTask::fields_STORE_ID, $storeId) // 保留兼容字段
                ->setData(SearchTask::fields_SOURCE_TYPE, $sourceType)
                ->setData(SearchTask::fields_SOURCE_ID, $sourceId)
                ->setData(SearchTask::fields_STATUS, SearchTask::STATUS_PENDING)
                ->setData(SearchTask::fields_PROGRESS, 0.00)
                ->setData(SearchTask::fields_FOUND_COUNT, 0)
                ->setSelectedSearchEnginesArray($selectedSearchEngines)
                ->setSelectedTargetWebsitesArray($selectedTargetWebsites)
                ->save();

            // save() 方法返回的是 ID（整数），保存后 ID 会自动设置到模型对象中
            $taskId = $taskModel->getId();
            if (!$taskId) {
                throw new Exception(__('创建搜索任务失败'));
            }

            return $taskId;

        } catch (\Exception $e) {
            throw new Exception(__('创建搜索任务失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 获取搜索结果
     * 
     * @param int $taskId 任务ID
     * @return array 搜索结果
     * @throws Exception
     */
    public function getSearchResults(int $taskId): array
    {
        try {
            /** @var SearchTask $taskModel */
            $taskModel = ObjectManager::getInstance(SearchTask::class);
            $task = $taskModel->load($taskId);

            if (!$task->getId()) {
                throw new Exception(__('搜索任务不存在：%{1}', [$taskId]));
            }

            // 获取任务关联的潜在客户
            /** @var LeadCandidate $candidateModel */
            $candidateModel = ObjectManager::getInstance(LeadCandidate::class);
            
            $candidates = $candidateModel->clear()
                ->where(LeadCandidate::fields_STORE_ID, $task->getStoreId())
                ->order(LeadCandidate::fields_SCORE, 'DESC')
                ->fetch()
                ->getItems();

            $resultData = [];
            foreach ($candidates as $candidate) {
                $resultData[] = [
                    'candidate_id' => $candidate->getId(),
                    'score' => $candidate->getData(LeadCandidate::fields_SCORE),
                    'source_url' => $candidate->getData(LeadCandidate::fields_SOURCE_URL),
                    'status' => $candidate->getData(LeadCandidate::fields_STATUS),
                    'profile_data' => json_decode($candidate->getData(LeadCandidate::fields_PROFILE_DATA), true),
                ];
            }

            return [
                'task_id' => $task->getId(),
                'store_id' => $task->getData(SearchTask::fields_STORE_ID),
                'status' => $task->getData(SearchTask::fields_STATUS),
                'progress' => $task->getData(SearchTask::fields_PROGRESS),
                'found_count' => (int)$task->getData(SearchTask::fields_FOUND_COUNT),
                'candidates' => $resultData,
                'candidate_count' => count($resultData),
                'created_at' => $task->getData(SearchTask::fields_CREATED_AT),
                'updated_at' => $task->getData(SearchTask::fields_UPDATED_AT),
            ];

        } catch (\Exception $e) {
            throw new Exception(__('获取搜索结果失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 更新任务状态
     * 
     * @param int $taskId 任务ID
     * @param string $status 状态
     * @param float $progress 进度（0-100）
     * @return bool
     */
    public function updateTaskStatus(int $taskId, string $status, float $progress = 0.0): bool
    {
        try {
            /** @var SearchTask $taskModel */
            $taskModel = ObjectManager::getInstance(SearchTask::class);
            $task = $taskModel->load($taskId);

            if (!$task->getId()) {
                return false;
            }

            $task->setData(SearchTask::fields_STATUS, $status)
                ->setData(SearchTask::fields_PROGRESS, $progress)
                ->save();

            return true;

        } catch (\Exception $e) {
            error_log('LeadSearchService updateTaskStatus error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 保存搜索结果
     * 
     * @param int $taskId 任务ID
     * @param array $resultData 结果数据
     * @return bool
     */
    public function saveSearchResults(int $taskId, array $resultData): bool
    {
        try {
            /** @var SearchTask $taskModel */
            $taskModel = ObjectManager::getInstance(SearchTask::class);
            $task = $taskModel->load($taskId);

            if (!$task->getId()) {
                return false;
            }

            $task->setData(SearchTask::fields_RESULT_DATA, json_encode($resultData, JSON_UNESCAPED_UNICODE))
                ->setData(SearchTask::fields_STATUS, SearchTask::STATUS_COMPLETED)
                ->setData(SearchTask::fields_PROGRESS, 100.00)
                ->save();

            return true;

        } catch (\Exception $e) {
            error_log('LeadSearchService saveSearchResults error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取最新的未完成任务（pending 或 running）
     *
     * @param int|null $storeId 店铺ID（可选，为空则不按店铺过滤）
     * @return SearchTask|null
     * @throws Exception
     */
    public function getLatestActiveTask(?int $storeId = null): ?SearchTask
    {
        try {
            /** @var SearchTask $taskModel */
            $taskModel = ObjectManager::getInstance(SearchTask::class);

            $taskModel->clear()
                ->where(
                    SearchTask::fields_STATUS,
                    [SearchTask::STATUS_PENDING, SearchTask::STATUS_RUNNING],
                    'in'
                );

            if ($storeId !== null) {
                $taskModel->where(SearchTask::fields_STORE_ID, $storeId);
            }

            $taskModel->order(SearchTask::fields_CREATED_AT, 'DESC')
                ->limit(1)
                ->fetch();

            $items = $taskModel->getItems();
            if (empty($items)) {
                return null;
            }

            /** @var SearchTask $task */
            $task = reset($items);
            return $task instanceof SearchTask ? $task : null;
        } catch (\Exception $e) {
            throw new Exception(__('获取未完成任务失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 根据任务ID获取任务详情（简单包装）
     *
     * @param int $taskId
     * @return array|null
     * @throws Exception
     */
    public function getTaskDetail(int $taskId): ?array
    {
        try {
            /** @var SearchTask $taskModel */
            $taskModel = ObjectManager::getInstance(SearchTask::class);
            $task = $taskModel->load($taskId);

            if (!$task->getId()) {
                return null;
            }

            return [
                'task_id'     => $task->getId(),
                'store_id'    => $task->getData(SearchTask::fields_STORE_ID),
                'status'      => $task->getData(SearchTask::fields_STATUS),
                'progress'    => $task->getData(SearchTask::fields_PROGRESS),
                'found_count' => (int)$task->getData(SearchTask::fields_FOUND_COUNT),
                'created_at'  => $task->getData(SearchTask::fields_CREATED_AT),
                'updated_at'  => $task->getData(SearchTask::fields_UPDATED_AT),
            ];
        } catch (\Exception $e) {
            throw new Exception(__('获取任务详情失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 获取来源类型画像
     * 
     * @param string $sourceType 来源类型（如 'store'）
     * @param int $sourceId 来源ID（如店铺ID）
     * @return array 来源类型画像数据
     * @throws Exception
     */
    public function getSourceTypeProfile(string $sourceType, int $sourceId): array
    {
        try {
            // 通过事件系统获取来源类型处理器
            $handlerClass = null;
            
            // 触发事件收集来源类型
            $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $event = new \Weline\Framework\Event\Event();
            $event->setData('source_types', []);
            $eventManager->dispatch('Weline_AutoLeadAgent::lead_search_task::collect_source_types', $event);
            
            $sourceTypes = $event->getData('source_types') ?? [];
            
            // 查找对应的处理器类
            foreach ($sourceTypes as $type) {
                if (($type['type'] ?? '') === $sourceType) {
                    $handlerClass = $type['handler_class'] ?? null;
                    break;
                }
            }
            
            if (!$handlerClass || !class_exists($handlerClass)) {
                throw new Exception(__('未找到来源类型处理器：%{1}', [$sourceType]));
            }
            
            // 实例化处理器
            /** @var SourceTypeHandlerInterface $handler */
            $handler = ObjectManager::getInstance($handlerClass);
            
            if (!$handler instanceof SourceTypeHandlerInterface) {
                throw new Exception(__('来源类型处理器未实现SourceTypeHandlerInterface接口'));
            }
            
            // 获取详细信息
            $detail = $handler->getDetail($sourceId);
            
            if (empty($detail)) {
                throw new Exception(__('未找到来源类型详情：%{1} #%{2}', [$sourceType, $sourceId]));
            }
            
            // 构建画像数据
            $textContent = ($detail['name'] ?? '') . ' ' . 
                          ($detail['description'] ?? '') . ' ' . 
                          ($detail['meta_description'] ?? '') . ' ' . 
                          ($detail['meta_keywords'] ?? '');
            
            // 提取关键词
            $words = preg_split('/[\s,，。！？；：、]+/u', $textContent);
            $keywords = [];
            foreach ($words as $word) {
                $word = trim($word);
                if (mb_strlen($word) >= 2 && mb_strlen($word) <= 10) {
                    $keywords[] = $word;
                }
            }
            $keywords = array_unique(array_slice($keywords, 0, 20));
            
            // 构建画像
            $profile = [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'id' => $sourceId,
                'name' => $detail['name'] ?? '',
                'description' => $detail['description'] ?? '',
                'industry' => $detail['industry'] ?? '通用',
                'region' => $detail['region'] ?? $detail['address'] ?? '',
                'keywords' => $keywords,
                'products' => $detail['products'] ?? [],
                'detail' => $detail,
            ];
            
            return $profile;
            
        } catch (\Exception $e) {
            throw new Exception(__('获取来源类型画像失败：%{1}', [$e->getMessage()]));
        }
    }
}

