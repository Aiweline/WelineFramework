<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Model Monitoring Entity
 * 
 * @package Weline_Ai
 */
class AiModelMonitoring extends Model
{
    public function _init(): void
    {
        $this->useMainDbMaster();
        // 表名由框架自动推导：AiModelMonitoring -> ai_model_monitoring
    }

    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('AI模型监控表')
                ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn('model_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '模型ID')
                ->addColumn('tenant_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '租户ID')
                ->addColumn('request_count', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '请求数')
                ->addColumn('success_count', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '成功数')
                ->addColumn('error_count', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '错误数')
                ->addColumn('avg_response_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, '', '平均响应时间')
                ->addColumn('p95_response_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, '', 'P95响应时间')
                ->addColumn('p99_response_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, '', 'P99响应时间')
                ->addColumn('total_cost', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default 0', '总成本')
                ->addColumn('date', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'not null', '日期')
                ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '创建时间')
                ->addIndex('model_id', '', '', 'idx_model_id')
                ->addIndex('tenant_id', '', '', 'idx_tenant_id')
                ->addIndex('date', '', '', 'idx_date')
                ->create();
        }
    }

    public function getSuccessRate(): float
    {
        $total = $this->getData('request_count');
        if ($total == 0) {
            return 0.0;
        }

        $success = $this->getData('success_count');
        return ($success / $total) * 100;
    }

    public function getErrorRate(): float
    {
        $total = $this->getData('request_count');
        if ($total == 0) {
            return 0.0;
        }

        $errors = $this->getData('error_count');
        return ($errors / $total) * 100;
    }

    public function incrementRequest(bool $success = true, float $responseTime = 0, float $cost = 0): void
    {
        $this->setData('request_count', $this->getData('request_count') + 1);
        
        if ($success) {
            $this->setData('success_count', $this->getData('success_count') + 1);
        } else {
            $this->setData('error_count', $this->getData('error_count') + 1);
        }

        $this->setData('total_cost', $this->getData('total_cost') + $cost);
        
        // Update average response time
        $this->updateAverageResponseTime($responseTime);
    }

    private function updateAverageResponseTime(float $newTime): void
    {
        $count = $this->getData('request_count');
        $currentAvg = $this->getData('avg_response_time') ?? 0;
        
        $newAvg = (($currentAvg * ($count - 1)) + $newTime) / $count;
        $this->setData('avg_response_time', $newAvg);
    }
}

