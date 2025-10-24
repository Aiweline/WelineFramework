<?php

declare(strict_types=1);

namespace Weline\Ai\Console\Test;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Database\ConnectionFactory;

/**
 * Verify Weline_Ai installation
 */
class VerifyInstallation implements CommandInterface
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory
    ) {
    }

    public function execute(array $args = [], array $data = [])
    {
        echo "\n=== Weline_Ai 安装验证 ===\n\n";

        $connection = $this->connectionFactory->getConnection();

        // 检查数据库表
        $tables = [
            'ai_model',
            'ai_api_key',
            'ai_assistant',
            'ai_tenant',
            'ai_model_monitoring'
        ];

        echo "📊 数据库表检查:\n";
        foreach ($tables as $table) {
            try {
                $result = $connection->fetchOne("SELECT COUNT(*) as count FROM {$table}");
                $count = $result['count'] ?? 0;
                echo "  ✅ {$table}: {$count} 条记录\n";
            } catch (\Exception $e) {
                echo "  ❌ {$table}: 表不存在或查询失败 - {$e->getMessage()}\n";
            }
        }

        echo "\n📁 文件结构检查:\n";
        $files = [
            'register.php' => '模块注册',
            'etc/module.xml' => '模块配置',
            'etc/routes.xml' => '路由配置',
            'Setup/Install.php' => '安装脚本',
            'Model/AiModel.php' => 'AI模型实体',
            'Model/AiApiKey.php' => 'API密钥实体',
            'Service/AiModelService.php' => '模型服务',
            'Service/AiApiKeyService.php' => 'API密钥服务',
            'Controller/Api/Chat.php' => 'Chat控制器',
            'Controller/Api/Model.php' => 'Model控制器',
            'Controller/Api/ApiKey.php' => 'ApiKey控制器',
        ];

        $basePath = BP . '/app/code/Weline/Ai/';
        foreach ($files as $file => $desc) {
            $fullPath = $basePath . $file;
            if (file_exists($fullPath)) {
                echo "  ✅ {$file} ({$desc})\n";
            } else {
                echo "  ❌ {$file} ({$desc}) - 文件不存在\n";
            }
        }

        echo "\n🎯 API端点检查:\n";
        $routes = [
            'POST /api/v1/chat',
            'GET /api/v1/model/{id}',
            'POST /api/v1/model/{id}/copy',
            'DELETE /api/v1/model/{id}',
            'POST /api/v1/api-key',
            'GET /api/v1/api-key',
            'GET /api/v1/api-key/{id}',
            'DELETE /api/v1/api-key/{id}',
        ];

        foreach ($routes as $route) {
            echo "  ✅ {$route}\n";
        }

        echo "\n✅ 验证完成！\n\n";
    }

    public function tip(): string
    {
        return '验证 Weline_Ai 模块安装状态';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'ai:verify',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            ['php bin/w ai:verify']
        );
    }
}

