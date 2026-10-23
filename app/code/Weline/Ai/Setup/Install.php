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
