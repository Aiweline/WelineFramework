<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Controller\Backend;

use Weline\Framework\App\Exception;
use Weline\TranslationService\Model\TranslationRecord;
use Weline\TranslationService\Model\TranslationProvider;
use Weline\TranslationService\Helper\LanguageCodeConverter;

/**
 * 翻译记录管理控制器
 */
class Record extends \Weline\Admin\Controller\BaseController
{
    /**
     * @var TranslationRecord
     */
    private TranslationRecord $recordModel;

    /**
     * @var TranslationProvider
     */
    private TranslationProvider $providerModel;

    /**
     * 构造函数
     */
    public function __construct(
        TranslationRecord $recordModel,
        TranslationProvider $providerModel
    ) {
        $this->recordModel = $recordModel;
        $this->providerModel = $providerModel;
    }

    /**
     * 显示翻译记录列表
     */
    public function index(): string
    {
        $page = (int)($this->request->getParam('page') ?? 1);
        $pageSize = 20;
        
        // 获取筛选条件
        $providerId = $this->request->getParam('provider_id');
        $status = $this->request->getParam('status');
        $sourceLanguage = $this->request->getParam('source_language');
        $targetLanguage = $this->request->getParam('target_language');
        $moduleName = $this->request->getParam('module_name');
        $startDate = $this->request->getParam('start_date');
        $endDate = $this->request->getParam('end_date');
        
        // 构建查询
        $query = $this->recordModel->clear();
        
        if ($providerId) {
            $query->where(TranslationRecord::fields_PROVIDER_ID, $providerId);
        }
        if ($status) {
            $query->where(TranslationRecord::fields_STATUS, $status);
        }
        if ($sourceLanguage) {
            $query->where(TranslationRecord::fields_SOURCE_LANGUAGE, $sourceLanguage);
        }
        if ($targetLanguage) {
            $query->where(TranslationRecord::fields_TARGET_LANGUAGE, $targetLanguage);
        }
        if ($moduleName) {
            $query->where(TranslationRecord::fields_MODULE_NAME, $moduleName);
        }
        if ($startDate) {
            $query->where(TranslationRecord::fields_CREATED_AT, $startDate, '>=');
        }
        if ($endDate) {
            $query->where(TranslationRecord::fields_CREATED_AT, $endDate . ' 23:59:59', '<=');
        }
        
        // 获取总数
        $total = $query->count();
        
        // 获取分页数据
        $records = $query->clear()
            ->order(TranslationRecord::fields_CREATED_AT, 'DESC')
            ->limit($pageSize, ($page - 1) * $pageSize)
            ->select()
            ->fetch();
        
        // 获取所有渠道（用于筛选）
        $providers = $this->providerModel->clear()->select()->fetch();
        
        // 获取统计信息
        $stats = $this->getStatistics();
        
        $this->assign('records', $records);
        $this->assign('providers', $providers);
        $this->assign('stats', $stats);
        $this->assign('page', $page);
        $this->assign('pageSize', $pageSize);
        $this->assign('total', $total);
        $this->assign('totalPages', ceil($total / $pageSize));
        $this->assign('filters', [
            'provider_id' => $providerId,
            'status' => $status,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'module_name' => $moduleName,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        
        return $this->fetch();
    }

    /**
     * 查看翻译记录详情
     */
    public function view(): string
    {
        $recordId = (int)$this->request->getParam('id');
        
        if (!$recordId) {
            $this->getMessageManager()->addError(__('记录ID不能为空'));
            return $this->redirect($this->getBackendUrl('*/backend/record'));
        }
        
        $record = $this->recordModel->clear()->load($recordId);
        if (!$record->getId()) {
            $this->getMessageManager()->addError(__('记录不存在'));
            return $this->redirect($this->getBackendUrl('*/backend/record'));
        }
        
        // 获取渠道信息
        $provider = $this->providerModel->clear()->load($record->getData(TranslationRecord::fields_PROVIDER_ID));
        
        $this->assign('record', $record);
        $this->assign('provider', $provider);
        
        return $this->fetch();
    }

    /**
     * 删除翻译记录
     */
    public function delete(): string
    {
        $recordId = (int)$this->request->getParam('id');
        
        if (!$recordId) {
            $this->getMessageManager()->addError(__('记录ID不能为空'));
            return $this->redirect($this->getBackendUrl('*/backend/record'));
        }
        
        try {
            $record = $this->recordModel->clear()->load($recordId);
            if (!$record->getId()) {
                throw new Exception(__('记录不存在'));
            }
            
            $record->delete();
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } catch (Exception $e) {
            $this->getMessageManager()->addError(__('删除失败：%{1}', [$e->getMessage()]));
        }
        
        return $this->redirect($this->getBackendUrl('*/backend/record'));
    }

    /**
     * 批量删除翻译记录
     */
    public function batchDelete(): string
    {
        if (!$this->request->isPost()) {
            $this->getMessageManager()->addError(__('仅支持POST请求'));
            return $this->redirect($this->getBackendUrl('*/backend/record'));
        }
        
        $ids = $this->request->getPost('ids');
        if (empty($ids) || !is_array($ids)) {
            $this->getMessageManager()->addError(__('请选择要删除的记录'));
            return $this->redirect($this->getBackendUrl('*/backend/record'));
        }
        
        try {
            $count = 0;
            foreach ($ids as $id) {
                $record = $this->recordModel->clear()->load((int)$id);
                if ($record->getId()) {
                    $record->delete();
                    $count++;
                }
            }
            
            $this->getMessageManager()->addSuccess(__('成功删除 %{1} 条记录', [$count]));
        } catch (Exception $e) {
            $this->getMessageManager()->addError(__('删除失败：%{1}', [$e->getMessage()]));
        }
        
        return $this->redirect($this->getBackendUrl('*/backend/record'));
    }

    /**
     * 获取统计信息
     * 
     * @return array
     */
    private function getStatistics(): array
    {
        // 总记录数
        $totalRecords = $this->recordModel->clear()->count();
        
        // 成功记录数
        $successRecords = $this->recordModel->clear()
            ->where(TranslationRecord::fields_STATUS, TranslationRecord::STATUS_SUCCESS)
            ->count();
        
        // 失败记录数
        $failedRecords = $this->recordModel->clear()
            ->where(TranslationRecord::fields_STATUS, TranslationRecord::STATUS_FAILED)
            ->count();
        
        // 总字符数
        $totalCharacters = $this->recordModel->clear()
            ->where(TranslationRecord::fields_STATUS, TranslationRecord::STATUS_SUCCESS)
            ->sum(TranslationRecord::fields_CHARACTER_COUNT) ?? 0;
        
        // 总成本
        $totalCost = $this->recordModel->clear()
            ->where(TranslationRecord::fields_STATUS, TranslationRecord::STATUS_SUCCESS)
            ->sum(TranslationRecord::fields_COST) ?? 0;
        
        // 平均响应时间
        $avgResponseTime = $this->recordModel->clear()
            ->where(TranslationRecord::fields_STATUS, TranslationRecord::STATUS_SUCCESS)
            ->avg(TranslationRecord::fields_RESPONSE_TIME) ?? 0;
        
        // 今日统计
        $todayStart = date('Y-m-d 00:00:00');
        $todayRecords = $this->recordModel->clear()
            ->where(TranslationRecord::fields_CREATED_AT, $todayStart, '>=')
            ->count();
        
        $todayCharacters = $this->recordModel->clear()
            ->where(TranslationRecord::fields_CREATED_AT, $todayStart, '>=')
            ->where(TranslationRecord::fields_STATUS, TranslationRecord::STATUS_SUCCESS)
            ->sum(TranslationRecord::fields_CHARACTER_COUNT) ?? 0;
        
        $todayCost = $this->recordModel->clear()
            ->where(TranslationRecord::fields_CREATED_AT, $todayStart, '>=')
            ->where(TranslationRecord::fields_STATUS, TranslationRecord::STATUS_SUCCESS)
            ->sum(TranslationRecord::fields_COST) ?? 0;
        
        return [
            'total_records' => $totalRecords,
            'success_records' => $successRecords,
            'failed_records' => $failedRecords,
            'total_characters' => $totalCharacters,
            'total_cost' => $totalCost,
            'avg_response_time' => round($avgResponseTime, 2),
            'today_records' => $todayRecords,
            'today_characters' => $todayCharacters,
            'today_cost' => $todayCost,
        ];
    }

    /**
     * 导出统计报表（JSON格式）
     */
    public function exportStats(): string
    {
        $stats = $this->getStatistics();
        
        // 按渠道统计
        $providerStats = [];
        $providers = $this->providerModel->clear()->select()->fetch();
        foreach ($providers as $provider) {
            $providerId = $provider->getId();
            $providerStats[$provider->getData(TranslationProvider::fields_PROVIDER_CODE)] = [
                'name' => $provider->getData(TranslationProvider::fields_PROVIDER_NAME),
                'total_records' => $this->recordModel->clear()
                    ->where(TranslationRecord::fields_PROVIDER_ID, $providerId)
                    ->count(),
                'success_records' => $this->recordModel->clear()
                    ->where(TranslationRecord::fields_PROVIDER_ID, $providerId)
                    ->where(TranslationRecord::fields_STATUS, TranslationRecord::STATUS_SUCCESS)
                    ->count(),
                'total_characters' => $this->recordModel->clear()
                    ->where(TranslationRecord::fields_PROVIDER_ID, $providerId)
                    ->where(TranslationRecord::fields_STATUS, TranslationRecord::STATUS_SUCCESS)
                    ->sum(TranslationRecord::fields_CHARACTER_COUNT) ?? 0,
                'total_cost' => $this->recordModel->clear()
                    ->where(TranslationRecord::fields_PROVIDER_ID, $providerId)
                    ->where(TranslationRecord::fields_STATUS, TranslationRecord::STATUS_SUCCESS)
                    ->sum(TranslationRecord::fields_COST) ?? 0,
            ];
        }
        
        $stats['by_provider'] = $providerStats;
        
        return $this->json($stats);
    }
}

