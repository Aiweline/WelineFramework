<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI使用日志模型
 * 
 * 功能：
 * - 记录AI模型调用日志
 * - 统计Token使用情况
 * - 计算使用成本
 * - 支持场景分析
 */
class AiUsageLog extends Model
{
    public const table = 'ai_usage_log';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_USER_ID = 'user_id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_MODEL_CODE = 'model_code';
    public const fields_VENDOR = 'vendor';
    public const fields_SCENARIO_CODE = 'scenario_code';
    public const fields_PROMPT_TOKENS = 'prompt_tokens';
    public const fields_COMPLETION_TOKENS = 'completion_tokens';
    public const fields_TOTAL_TOKENS = 'total_tokens';
    public const fields_TOTAL_COST = 'total_cost';
    public const fields_RESPONSE_TIME = 'response_time';
    public const fields_REQUEST_DATA = 'request_data';
    public const fields_RESPONSE_DATA = 'response_data';
    public const fields_ERROR_MESSAGE = 'error_message';
    public const fields_IS_STREAM = 'is_stream';
    public const fields_CREATED_TIME = 'created_time';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, 11, 'null', '用户ID')
                ->addColumn(self::fields_TENANT_ID, TableInterface::column_type_INTEGER, 11, 'null', '租户ID')
                ->addColumn(self::fields_MODEL_CODE, TableInterface::column_type_VARCHAR, 100, 'not null', '模型代码')
                ->addColumn(self::fields_VENDOR, TableInterface::column_type_VARCHAR, 50, 'not null', '供应商')
                ->addColumn(self::fields_SCENARIO_CODE, TableInterface::column_type_VARCHAR, 100, 'null', '场景代码')
                ->addColumn(self::fields_PROMPT_TOKENS, TableInterface::column_type_INTEGER, 11, 'not null default 0', '提示Token数')
                ->addColumn(self::fields_COMPLETION_TOKENS, TableInterface::column_type_INTEGER, 11, 'not null default 0', '完成Token数')
                ->addColumn(self::fields_TOTAL_TOKENS, TableInterface::column_type_INTEGER, 11, 'not null default 0', '总Token数')
                ->addColumn(self::fields_TOTAL_COST, TableInterface::column_type_DECIMAL, '10,6', 'not null default 0.000000', '总成本')
                ->addColumn(self::fields_RESPONSE_TIME, TableInterface::column_type_INTEGER, 11, 'null', '响应时间(毫秒)')
                ->addColumn(self::fields_REQUEST_DATA, TableInterface::column_type_TEXT, null, 'null', '请求数据')
                ->addColumn(self::fields_RESPONSE_DATA, TableInterface::column_type_TEXT, null, 'null', '响应数据')
                ->addColumn(self::fields_ERROR_MESSAGE, TableInterface::column_type_TEXT, null, 'null', '错误信息')
                ->addColumn(self::fields_IS_STREAM, TableInterface::column_type_INTEGER, 1, 'not null default 0', '是否流式')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID)
                ->addIndex(TableInterface::index_type_KEY, 'idx_tenant_id', self::fields_TENANT_ID)
                ->addIndex(TableInterface::index_type_KEY, 'idx_model_code', self::fields_MODEL_CODE)
                ->addIndex(TableInterface::index_type_KEY, 'idx_scenario_code', self::fields_SCENARIO_CODE)
                ->addIndex(TableInterface::index_type_KEY, 'idx_created_time', self::fields_CREATED_TIME)
                ->create();
        }
    }

    /**
     * 获取用户使用统计
     * 
     * @param int $userId
     * @param int $startTime
     * @param int $endTime
     * @return array
     */
    public function getUserStats(int $userId, int $startTime = 0, int $endTime = 0): array
    {
        $query = $this->reset()->where(self::fields_USER_ID, $userId);
        
        if ($startTime > 0) {
            $query->where(self::fields_CREATED_TIME, '>=', $startTime);
        }
        
        if ($endTime > 0) {
            $query->where(self::fields_CREATED_TIME, '<=', $endTime);
        }
        
        $logs = $query->select()->fetch();
        
        $totalRequests = count($logs->getItems());
        $totalTokens = 0;
        $totalCost = 0;
        
        foreach ($logs->getItems() as $log) {
            $totalTokens += $log->getData(self::fields_TOTAL_TOKENS) ?? 0;
            $totalCost += $log->getData(self::fields_TOTAL_COST) ?? 0;
        }
        
        return [
            'total_requests' => $totalRequests,
            'total_tokens' => $totalTokens,
            'total_cost' => $totalCost,
            'avg_tokens_per_request' => $totalRequests > 0 ? round($totalTokens / $totalRequests) : 0,
        ];
    }

    /**
     * 获取模型使用统计
     * 
     * @param string $modelCode
     * @param int $startTime
     * @param int $endTime
     * @return array
     */
    public function getModelStats(string $modelCode, int $startTime = 0, int $endTime = 0): array
    {
        $query = $this->reset()->where(self::fields_MODEL_CODE, $modelCode);
        
        if ($startTime > 0) {
            $query->where(self::fields_CREATED_TIME, '>=', $startTime);
        }
        
        if ($endTime > 0) {
            $query->where(self::fields_CREATED_TIME, '<=', $endTime);
        }
        
        $logs = $query->select()->fetch();
        
        $totalRequests = count($logs->getItems());
        $totalTokens = 0;
        $totalCost = 0;
        $avgResponseTime = 0;
        $totalResponseTime = 0;
        $responseTimeCount = 0;
        
        foreach ($logs->getItems() as $log) {
            $totalTokens += $log->getData(self::fields_TOTAL_TOKENS) ?? 0;
            $totalCost += $log->getData(self::fields_TOTAL_COST) ?? 0;
            
            $responseTime = $log->getData(self::fields_RESPONSE_TIME);
            if ($responseTime !== null) {
                $totalResponseTime += $responseTime;
                $responseTimeCount++;
            }
        }
        
        if ($responseTimeCount > 0) {
            $avgResponseTime = round($totalResponseTime / $responseTimeCount);
        }
        
        return [
            'total_requests' => $totalRequests,
            'total_tokens' => $totalTokens,
            'total_cost' => $totalCost,
            'avg_response_time' => $avgResponseTime,
            'avg_tokens_per_request' => $totalRequests > 0 ? round($totalTokens / $totalRequests) : 0,
        ];
    }

    /**
     * 记录使用日志
     * 
     * @param array $data
     * @return $this
     */
    public function logUsage(array $data): self
    {
        $this->reset();
        $this->setData([
            self::fields_USER_ID => $data['user_id'] ?? null,
            self::fields_TENANT_ID => $data['tenant_id'] ?? null,
            self::fields_MODEL_CODE => $data['model_code'] ?? '',
            self::fields_VENDOR => $data['vendor'] ?? '',
            self::fields_SCENARIO_CODE => $data['scenario_code'] ?? null,
            self::fields_PROMPT_TOKENS => $data['prompt_tokens'] ?? 0,
            self::fields_COMPLETION_TOKENS => $data['completion_tokens'] ?? 0,
            self::fields_TOTAL_TOKENS => $data['total_tokens'] ?? 0,
            self::fields_TOTAL_COST => $data['total_cost'] ?? 0,
            self::fields_RESPONSE_TIME => $data['response_time'] ?? null,
            self::fields_REQUEST_DATA => $data['request_data'] ?? null,
            self::fields_RESPONSE_DATA => $data['response_data'] ?? null,
            self::fields_ERROR_MESSAGE => $data['error_message'] ?? null,
            self::fields_IS_STREAM => $data['is_stream'] ?? 0,
            self::fields_CREATED_TIME => time(),
        ]);
        
        $this->save();
        
        return $this;
    }
}

