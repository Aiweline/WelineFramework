<?php
declare(strict_types=1);

namespace Weline\Bot\Setup;

use Weline\Framework\Database\Db\Ddl\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

/**
 * Weline_Bot 模块安装脚本
 */
class Install implements InstallInterface
{
    /**
     * 安装时创建种子数据
     */
    public function setup(Setup $setup, Context $context): void
    {
        // 数据表由 #[Col] 声明式自动创建，这里只添加种子数据
        $this->createDefaultRole($setup);
        $this->createBuiltinSkills($setup);
        $this->registerBotAdapters($setup);
    }

    /**
     * 创建默认角色
     */
    private function createDefaultRole(Setup $setup): void
    {
        $tableName = $setup->getTable('weline_bot_role');
        
        // 检查是否已有默认角色
        $connection = $setup->getConnection();
        $existingRole = $connection->query(
            "SELECT role_id FROM {$tableName} WHERE is_default = 1 LIMIT 1"
        )->fetch();
        
        if (!$existingRole) {
            // 获取默认模型 ID（安全检查表是否存在）
            $modelId = null;
            try {
                $modelTable = $setup->getTable('ai_model');
                // 先检查表是否存在
                $tables = $connection->query("SHOW TABLES LIKE '{$modelTable}'")->fetch();
                if ($tables) {
                    $defaultModel = $connection->query(
                        "SELECT id FROM {$modelTable} WHERE is_default = 1 AND is_active = 1 LIMIT 1"
                    )->fetch();
                    $modelId = $defaultModel ? (int) $defaultModel['id'] : null;
                }
            } catch (\Throwable) {
                // 表不存在，使用 null
            }

            $now = time();
            $connection->insert($tableName, [
                'code' => 'assistant',
                'name' => 'AI 助手',
                'system_prompt' => '你是一个智能助手，具备文件操作、Shell执行、网络请求等能力。请根据用户指令安全、高效地完成任务。对于危险操作，请务必先询问用户确认。',
                'permissions' => json_encode([
                    'fs.read:/app/*',
                    'fs.read:/var/*',
                    'fs.write:/var/*',
                    'http.request:*',
                ]),
                'skills' => json_encode(['filesystem.read', 'filesystem.write', 'http.request', 'shell.execute']),
                'model_id' => $modelId,
                'scenario_adapter_code' => 'bot_agent',
                'model_config' => json_encode([
                    'temperature' => 0.7,
                    'max_tokens' => 4096,
                ]),
                'status' => 'enabled',
                'is_default' => 1,
                'description' => '默认 AI 助手角色，具备基础文件操作和网络请求能力',
                'icon' => 'mdi-robot',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * 创建内置技能
     */
    private function createBuiltinSkills(Setup $setup): void
    {
        $tableName = $setup->getTable('weline_bot_skill');
        $connection = $setup->getConnection();
        $now = time();
        
        $builtinSkills = [
            [
                'code' => 'filesystem.read',
                'name' => '文件读取',
                'description' => '读取指定路径的文件内容',
                'category' => 'filesystem',
                'class_name' => 'Weline\\Bot\\Skill\\FilesystemSkill',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => '文件路径',
                        ],
                        'encoding' => [
                            'type' => 'string',
                            'default' => 'utf-8',
                            'description' => '文件编码',
                        ],
                    ],
                    'required' => ['path'],
                ]),
                'permission_required' => json_encode(['fs.read']),
                'is_dangerous' => 0,
                'requires_confirmation' => 0,
                'module' => 'Weline_Bot',
                'is_active' => 1,
                'is_builtin' => 1,
            ],
            [
                'code' => 'filesystem.write',
                'name' => '文件写入',
                'description' => '向指定路径写入内容',
                'category' => 'filesystem',
                'class_name' => 'Weline\\Bot\\Skill\\FilesystemSkill',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => '文件路径',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => '写入内容',
                        ],
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['write', 'append'],
                            'default' => 'write',
                            'description' => '写入模式',
                        ],
                    ],
                    'required' => ['path', 'content'],
                ]),
                'permission_required' => json_encode(['fs.write']),
                'is_dangerous' => 1,
                'requires_confirmation' => 1,
                'module' => 'Weline_Bot',
                'is_active' => 1,
                'is_builtin' => 1,
            ],
            [
                'code' => 'shell.execute',
                'name' => 'Shell 命令执行',
                'description' => '执行 Shell 命令（需白名单）',
                'category' => 'shell',
                'class_name' => 'Weline\\Bot\\Skill\\ShellSkill',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'command' => [
                            'type' => 'string',
                            'description' => '要执行的命令',
                        ],
                        'timeout' => [
                            'type' => 'integer',
                            'default' => 30,
                            'description' => '超时时间（秒）',
                        ],
                    ],
                    'required' => ['command'],
                ]),
                'permission_required' => json_encode(['shell.execute']),
                'is_dangerous' => 1,
                'requires_confirmation' => 1,
                'module' => 'Weline_Bot',
                'is_active' => 1,
                'is_builtin' => 1,
            ],
            [
                'code' => 'http.request',
                'name' => 'HTTP 请求',
                'description' => '发送 HTTP 请求',
                'category' => 'api',
                'class_name' => 'Weline\\Bot\\Skill\\HttpSkill',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => '请求 URL',
                        ],
                        'method' => [
                            'type' => 'string',
                            'enum' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
                            'default' => 'GET',
                        ],
                        'headers' => [
                            'type' => 'object',
                            'description' => '请求头',
                        ],
                        'body' => [
                            'type' => 'object',
                            'description' => '请求体',
                        ],
                    ],
                    'required' => ['url'],
                ]),
                'permission_required' => json_encode(['http.request']),
                'is_dangerous' => 0,
                'requires_confirmation' => 0,
                'module' => 'Weline_Bot',
                'is_active' => 1,
                'is_builtin' => 1,
            ],
            [
                'code' => 'database.query',
                'name' => '数据库查询',
                'description' => '执行数据库查询（只读）',
                'category' => 'database',
                'class_name' => 'Weline\\Bot\\Skill\\DatabaseSkill',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'sql' => [
                            'type' => 'string',
                            'description' => 'SQL 查询语句（仅 SELECT）',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'default' => 100,
                            'description' => '返回行数限制',
                        ],
                    ],
                    'required' => ['sql'],
                ]),
                'permission_required' => json_encode(['db.read']),
                'is_dangerous' => 0,
                'requires_confirmation' => 0,
                'module' => 'Weline_Bot',
                'is_active' => 1,
                'is_builtin' => 1,
            ],
        ];
        
        foreach ($builtinSkills as $skill) {
            // 检查是否已存在
            $existing = $connection->query(
                "SELECT skill_id FROM {$tableName} WHERE code = ?",
                [$skill['code']]
            )->fetch();
            
            if (!$existing) {
                $skill['created_at'] = $now;
                $skill['updated_at'] = $now;
                $connection->insert($tableName, $skill);
            }
        }
    }

    /**
     * 注册 Bot 场景适配器
     */
    private function registerBotAdapters(Setup $setup): void
    {
        $tableName = $setup->getTable('ai_scenario_adapter');
        $connection = $setup->getConnection();
        $now = time();

        // 检查表是否存在
        try {
            $tables = $connection->query("SHOW TABLES LIKE '{$tableName}'")->fetch();
            if (!$tables) {
                // 表不存在，跳过注册（Weline_Ai 可能未安装）
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $adapters = [
            [
                'code' => 'bot_agent',
                'name' => 'Bot 智能体适配器',
                'description' => '专为 Bot 智能体设计的场景适配器，支持角色上下文增强、工具调用格式化、记忆注入等能力。',
                'version' => '1.0.0',
                'class_name' => 'Weline\\Bot\\Adapter\\BotAgentAdapter',
                'file_path' => 'app/code/Weline/Bot/Adapter/BotAgentAdapter.php',
                'supported_models' => json_encode(['*']),
                'is_active' => 1,
            ],
            [
                'code' => 'bot_it_ops',
                'name' => 'IT 运维助手',
                'description' => '专为 IT 运维场景设计，支持服务器监控、日志分析、故障排查、自动化运维等能力。',
                'version' => '1.0.0',
                'class_name' => 'Weline\\Bot\\Adapter\\ITOpsAdapter',
                'file_path' => 'app/code/Weline/Bot/Adapter/ITOpsAdapter.php',
                'supported_models' => json_encode(['*']),
                'is_active' => 1,
            ],
            [
                'code' => 'bot_seo',
                'name' => 'SEO 优化助手',
                'description' => '专为 SEO 场景设计，支持关键词分析、内容优化建议、Meta 标签生成、结构化数据建议等能力。',
                'version' => '1.0.0',
                'class_name' => 'Weline\\Bot\\Adapter\\SEOAdapter',
                'file_path' => 'app/code/Weline/Bot/Adapter/SEOAdapter.php',
                'supported_models' => json_encode(['*']),
                'is_active' => 1,
            ],
        ];

        foreach ($adapters as $adapter) {
            // 检查是否已存在
            $existing = $connection->query(
                "SELECT id FROM {$tableName} WHERE code = ?",
                [$adapter['code']]
            )->fetch();

            if (!$existing) {
                $adapter['created_time'] = $now;
                $adapter['updated_time'] = $now;
                $connection->insert($tableName, $adapter);
            }
        }
    }
}
