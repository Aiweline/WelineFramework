<?php

declare(strict_types=1);

namespace Weline\Ai\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Database\Connection\Db\ConnectionFactory;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Module Installation Script
 * 
 * Handles database schema creation and initial data setup for the Weline_Ai module.
 * Following WelineFramework's Setup/Install.php pattern.
 * 
 * @package Weline_Ai
 */
class Install implements InstallInterface
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory
    ) {
    }

    /**
     * Execute installation
     * 
     * Creates all required database tables and indexes for the AI module.
     * 
     * @param Context $context Installation context
     * @return void
     */
    public function setup(Context $context): void
    {
        $connection = $this->connectionFactory->getConnection();
        
        // Core AI Model table
        $connection->createTable('ai_model', [
            'id' => ['type' => 'INTEGER', 'primary' => true, 'auto_increment' => true],
            'supplier' => ['type' => 'VARCHAR', 'length' => 100, 'not_null' => true],
            'model_code' => ['type' => 'VARCHAR', 'length' => 100, 'not_null' => true],
            'name' => ['type' => 'VARCHAR', 'length' => 255, 'not_null' => true],
            'version' => ['type' => 'VARCHAR', 'length' => 50, 'not_null' => true],
            'is_copy' => ['type' => 'BOOLEAN', 'not_null' => true, 'default' => 0],
            'origin_model_id' => ['type' => 'INTEGER', 'nullable' => true],
            'config' => ['type' => 'JSON', 'nullable' => true],
            'capabilities' => ['type' => 'JSON', 'nullable' => true],
            'max_tokens' => ['type' => 'INTEGER', 'nullable' => true],
            'cost_per_token' => ['type' => 'DECIMAL', 'precision' => 10, 'scale' => 6, 'nullable' => true],
            'status' => ['type' => 'ENUM', 'values' => ['active', 'deprecated', 'maintenance'], 'not_null' => true, 'default' => 'active'],
            'created_at' => ['type' => 'TIMESTAMP', 'not_null' => true, 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'TIMESTAMP', 'not_null' => true, 'default' => 'CURRENT_TIMESTAMP', 'on_update' => 'CURRENT_TIMESTAMP'],
        ]);
        
        // Create indexes for ai_model
        $connection->addIndex('ai_model', 'idx_ai_model_supplier_code', ['supplier', 'model_code']);
        $connection->addIndex('ai_model', 'idx_ai_model_is_copy', ['is_copy']);
        $connection->addUniqueIndex('ai_model', 'idx_ai_model_supplier_code_unique', ['supplier', 'model_code']);
        
        // AI API Key table
        $connection->createTable('ai_api_key', [
            'id' => ['type' => 'INTEGER', 'primary' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'length' => 255, 'not_null' => true],
            'token' => ['type' => 'VARCHAR', 'length' => 255, 'not_null' => true],
            'user_id' => ['type' => 'INTEGER', 'not_null' => true],
            'tenant_id' => ['type' => 'INTEGER', 'not_null' => true],
            'status' => ['type' => 'ENUM', 'values' => ['pending', 'approved', 'suspended', 'revoked'], 'not_null' => true, 'default' => 'pending'],
            'quota_daily' => ['type' => 'INTEGER', 'nullable' => true],
            'quota_monthly' => ['type' => 'INTEGER', 'nullable' => true],
            'usage_daily' => ['type' => 'INTEGER', 'not_null' => true, 'default' => 0],
            'usage_monthly' => ['type' => 'INTEGER', 'not_null' => true, 'default' => 0],
            'last_used_at' => ['type' => 'TIMESTAMP', 'nullable' => true],
            'expires_at' => ['type' => 'TIMESTAMP', 'nullable' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'not_null' => true, 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'TIMESTAMP', 'not_null' => true, 'default' => 'CURRENT_TIMESTAMP', 'on_update' => 'CURRENT_TIMESTAMP'],
        ]);
        
        // Create indexes for ai_api_key
        $connection->addIndex('ai_api_key', 'idx_ai_api_key_token', ['token']);
        $connection->addIndex('ai_api_key', 'idx_ai_api_key_tenant', ['tenant_id']);
        $connection->addUniqueIndex('ai_api_key', 'idx_ai_api_key_token_unique', ['token']);
        
        // AI Assistant table
        $connection->createTable('ai_assistant', [
            'id' => ['type' => 'INTEGER', 'primary' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'length' => 255, 'not_null' => true],
            'description' => ['type' => 'TEXT', 'nullable' => true],
            'prompt_template' => ['type' => 'TEXT', 'not_null' => true],
            'model_id' => ['type' => 'INTEGER', 'not_null' => true],
            'user_id' => ['type' => 'INTEGER', 'not_null' => true],
            'tenant_id' => ['type' => 'INTEGER', 'not_null' => true],
            'config' => ['type' => 'JSON', 'nullable' => true],
            'is_public' => ['type' => 'BOOLEAN', 'not_null' => true, 'default' => 0],
            'usage_count' => ['type' => 'INTEGER', 'not_null' => true, 'default' => 0],
            'status' => ['type' => 'ENUM', 'values' => ['active', 'inactive', 'archived'], 'not_null' => true, 'default' => 'active'],
            'created_at' => ['type' => 'TIMESTAMP', 'not_null' => true, 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'TIMESTAMP', 'not_null' => true, 'default' => 'CURRENT_TIMESTAMP', 'on_update' => 'CURRENT_TIMESTAMP'],
        ]);
        
        // Create indexes for ai_assistant
        $connection->addIndex('ai_assistant', 'idx_ai_assistant_tenant', ['tenant_id']);
        
        // AI Tenant table
        $connection->createTable('ai_tenant', [
            'id' => ['type' => 'INTEGER', 'primary' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'length' => 255, 'not_null' => true],
            'domain' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => true],
            'config' => ['type' => 'JSON', 'nullable' => true],
            'quota_monthly' => ['type' => 'INTEGER', 'nullable' => true],
            'usage_monthly' => ['type' => 'INTEGER', 'not_null' => true, 'default' => 0],
            'billing_plan' => ['type' => 'ENUM', 'values' => ['free', 'basic', 'premium', 'enterprise'], 'not_null' => true, 'default' => 'free'],
            'status' => ['type' => 'ENUM', 'values' => ['active', 'suspended', 'cancelled'], 'not_null' => true, 'default' => 'active'],
            'created_at' => ['type' => 'TIMESTAMP', 'not_null' => true, 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'TIMESTAMP', 'not_null' => true, 'default' => 'CURRENT_TIMESTAMP', 'on_update' => 'CURRENT_TIMESTAMP'],
        ]);
        
        // Create indexes for ai_tenant
        $connection->addUniqueIndex('ai_tenant', 'idx_ai_tenant_domain_unique', ['domain']);
        
        // AI Model Monitoring table
        $connection->createTable('ai_model_monitoring', [
            'id' => ['type' => 'INTEGER', 'primary' => true, 'auto_increment' => true],
            'model_id' => ['type' => 'INTEGER', 'not_null' => true],
            'tenant_id' => ['type' => 'INTEGER', 'not_null' => true],
            'request_count' => ['type' => 'INTEGER', 'not_null' => true, 'default' => 0],
            'success_count' => ['type' => 'INTEGER', 'not_null' => true, 'default' => 0],
            'error_count' => ['type' => 'INTEGER', 'not_null' => true, 'default' => 0],
            'avg_response_time' => ['type' => 'DECIMAL', 'precision' => 10, 'scale' => 3, 'nullable' => true],
            'p95_response_time' => ['type' => 'DECIMAL', 'precision' => 10, 'scale' => 3, 'nullable' => true],
            'p99_response_time' => ['type' => 'DECIMAL', 'precision' => 10, 'scale' => 3, 'nullable' => true],
            'total_cost' => ['type' => 'DECIMAL', 'precision' => 10, 'scale' => 6, 'not_null' => true, 'default' => 0],
            'date' => ['type' => 'DATE', 'not_null' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'not_null' => true, 'default' => 'CURRENT_TIMESTAMP'],
        ]);
        
        // Create indexes for ai_model_monitoring
        $connection->addIndex('ai_model_monitoring', 'idx_ai_model_monitoring_date', ['date']);
        
        // Insert initial data - default tenant
        $connection->insert('ai_tenant', [
            'name' => 'Default Tenant',
            'domain' => 'default.localhost',
            'config' => json_encode(['timezone' => 'Asia/Shanghai', 'locale' => 'zh_Hans_CN']),
            'billing_plan' => 'enterprise',
            'status' => 'active',
        ]);
    }
}
