<?php

declare(strict_types=1);

namespace Weline\Ai\Setup;

use Weline\Ai\Model\AiTenant;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

/**
 * AI Module Installation Script
 *
 * Handles database schema creation and initial data setup for the Weline_Ai module.
 * Connection is obtained via Setup::getDbSetup() (set by setModuleContext before setup runs).
 *
 * @package Weline_Ai
 */
class Install implements InstallInterface
{
    /**
     * Execute installation
     * 
     * Creates all required database tables and indexes for the AI module.
     * 
     * @param Setup $setup Setup instance
     * @param Context $context Installation context
     * @return void
     */
    public function setup(Setup $setup, Context $context): void
    {
        $connection = $setup->getDbSetup();
        
        // Core AI Model table
        // 表名: ai_model (由 AiModel 类名自动推导，遵循 WelineFramework ORM 约定)
        // 字段定义与 AiModel::install() 保持完全一致
        $connection->createTable('ai_model', 'AI模型表')
            ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
            ->addColumn('supplier', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', '供应商')
            ->addColumn('model_code', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', '模型代码')
            ->addColumn('name', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '模型名称')
            ->addColumn('version', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '版本')
            ->addColumn('is_copy', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否复制')
            ->addColumn('origin_model_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '原始模型ID')
            ->addColumn('config', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '配置JSON')
            ->addColumn('capabilities', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '能力JSON')
            ->addColumn('max_tokens', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '最大Token数')
            ->addColumn('cost_per_token', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, '', '每Token成本')
            ->addColumn('token_price_input', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '输入令牌价格（每1000个令牌）')
            ->addColumn('token_price_output', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '输出令牌价格（每1000个令牌）')
            ->addColumn('proxy_info', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '代理配置信息JSON')
            ->addColumn('provider_config', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '提供商配置JSON')
            ->addColumn('status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'active\'', '状态')
            ->addColumn('is_active', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否激活')
            ->addColumn('is_default', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否默认')
            ->addColumn('connection_test_status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, "default 'pending'", '连通性测试状态: pending/success/failed')
            ->addColumn('connection_test_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '连通性测试时间戳')
            ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
            ->addColumn('updated_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
            ->addColumn('vendor', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 64, 'null', '厂商名称')
            ->addColumn('product', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 64, 'null', '产品名称')
            ->addColumn('model', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 128, 'null', '模型名称')
            ->addColumn('class', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'null', '模型类名')
            ->addColumn('default_api_key', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'null', '默认API Key')
            ->addColumn('default_api_url', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'null', '默认API URL')
            ->addIndex('UNIQUE', 'idx_vendor_product_model', ['vendor', 'product', 'model'])
            ->create();
        
        // AI API Key table
        $connection->createTable('ai_api_key', 'AI API密钥表')
            ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
            ->addColumn('name', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '密钥名称')
            ->addColumn('token', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', 'API密钥')
            ->addColumn('user_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '用户ID')
            ->addColumn('tenant_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '租户ID')
            ->addColumn('status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '状态')
            ->addColumn('quota_daily', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '每日配额')
            ->addColumn('quota_monthly', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '每月配额')
            ->addColumn('usage_daily', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '每日使用量')
            ->addColumn('usage_monthly', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '每月使用量')
            ->addColumn('last_used_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '最后使用时间')
            ->addColumn('expires_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '过期时间')
            ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
            ->addColumn('updated_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
            ->addIndex('INDEX', 'idx_ai_api_key_token', ['token'])
            ->addIndex('INDEX', 'idx_ai_api_key_tenant', ['tenant_id'])
            ->addIndex('UNIQUE', 'idx_ai_api_key_token_unique', ['token'])
            ->create();
        
        // ai_tenant 表由 AiTenant::install() 模型安装创建，初始数据也在模型内通过 save() 插入，不使用 SQL 方言
        // AI Model Monitoring table
        $connection->createTable('ai_model_monitoring', 'AI模型监控表')
            ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
            ->addColumn('model_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '模型ID')
            ->addColumn('tenant_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '租户ID')
            ->addColumn('request_count', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '请求次数')
            ->addColumn('success_count', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '成功次数')
            ->addColumn('error_count', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '错误次数')
            ->addColumn('avg_response_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,3', 'null', '平均响应时间')
            ->addColumn('p95_response_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,3', 'null', 'P95响应时间')
            ->addColumn('p99_response_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,3', 'null', 'P99响应时间')
            ->addColumn('total_cost', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '总成本')
            ->addColumn('date', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 10, 'not null', '日期')
            ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
            ->addIndex('INDEX', 'idx_ai_model_monitoring_date', ['date'])
            ->create();

        $this->seedDefaultTenant();
    }

    /** 业务初始化：无租户时插入默认租户（计划 3.10） */
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
