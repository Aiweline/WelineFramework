<?php

declare(strict_types=1);

namespace Weline\Ai\Setup;

use Weline\Ai\Model\AiTenant;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

/**
 * AI Module Installation Script
 *
 * Schema for AiModel / AiApiKey / AiTenant is owned by declarative Models + SchemaDiff.
 * This Install only seeds data and creates tables that still lack a Model annotation.
 */
class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $connection = $setup->getDbSetup();

        // ai_model / ai_api_key / ai_tenant：由声明式 Model + SchemaDiff 创建，禁止在此再 CREATE。
        // 仅保留尚无 Model 注解的监控表（幂等：已存在则跳过）。
        if (!$connection->tableExist('ai_model_monitoring')) {
            $connection->createTable('ai_model_monitoring', 'AI模型监控表')
                ->addColumn('id', TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn('model_id', TableInterface::column_type_INTEGER, null, 'not null', '模型ID')
                ->addColumn('tenant_id', TableInterface::column_type_INTEGER, null, 'not null', '租户ID')
                ->addColumn('request_count', TableInterface::column_type_INTEGER, null, 'default 0', '请求次数')
                ->addColumn('success_count', TableInterface::column_type_INTEGER, null, 'default 0', '成功次数')
                ->addColumn('error_count', TableInterface::column_type_INTEGER, null, 'default 0', '错误次数')
                ->addColumn('avg_response_time', TableInterface::column_type_DECIMAL, '10,3', 'null', '平均响应时间')
                ->addColumn('p95_response_time', TableInterface::column_type_DECIMAL, '10,3', 'null', 'P95响应时间')
                ->addColumn('p99_response_time', TableInterface::column_type_DECIMAL, '10,3', 'null', 'P99响应时间')
                ->addColumn('total_cost', TableInterface::column_type_DECIMAL, '10,6', 'default 0', '总成本')
                ->addColumn('date', TableInterface::column_type_VARCHAR, 10, 'not null', '日期')
                ->addColumn('created_at', TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addIndex('INDEX', 'idx_ai_model_monitoring_date', ['date'])
                ->create();
        }

        $this->seedDefaultTenant();
    }

    /** 业务初始化：无租户时插入默认租户 */
    private function seedDefaultTenant(): void
    {
        $tenant = ObjectManager::getInstance(AiTenant::class);
        if ($tenant->reset()->total() > 0) {
            return;
        }
        $tenant->setData([
            'name' => 'Default Tenant',
            'domain' => 'default.localhost',
            'config' => ['timezone' => 'Asia/Shanghai', 'locale' => 'zh_Hans_CN'],
            'billing_plan' => AiTenant::PLAN_ENTERPRISE,
            'status' => AiTenant::STATUS_ACTIVE,
        ])->save();
    }
}
