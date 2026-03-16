<?php
declare(strict_types=1);

namespace Weline\Bot\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * Bot 智能体适配器
 *
 * 专为 Bot 智能体设计的场景适配器
 * 提供智能体角色上下文增强、工具调用格式化等能力
 */
class BotAgentAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'bot_agent';
    }

    public function getName(): string
    {
        return __('Bot 智能体适配器');
    }

    public function getDescription(): string
    {
        return __('专为 Bot 智能体设计的场景适配器，支持角色上下文增强、工具调用格式化、记忆注入等能力。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedModelTypes(): array
    {
        return ['*']; // 支持所有模型
    }

    /**
     * 适配提示词
     *
     * 增强 Bot 智能体的提示词：
     * - 添加角色上下文
     * - 添加工具使用说明
     * - 添加安全提示
     */
    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $roleName = $params['role_name'] ?? 'AI 助手';
        $skills = $params['skills'] ?? [];
        $permissions = $params['permissions'] ?? [];
        $memory = $params['memory'] ?? [];

        // 构建增强提示词
        $enhancedPrompt = "你是 {$roleName}。\n\n";

        // 添加记忆上下文
        if (!empty($memory)) {
            $enhancedPrompt .= "【相关记忆】\n";
            foreach ($memory as $item) {
                $type = $item['type'] ?? 'fact';
                $value = $item['value'] ?? '';
                $typeLabels = [
                    'fact' => '事实',
                    'preference' => '偏好',
                    'entity' => '实体',
                    'event' => '事件',
                ];
                $enhancedPrompt .= "- [{$typeLabels[$type] ?? $type}] {$value}\n";
            }
            $enhancedPrompt .= "\n";
        }

        // 添加技能说明
        if (!empty($skills)) {
            $enhancedPrompt .= "【可用技能】\n";
            foreach ($skills as $skill) {
                $skillName = is_array($skill) ? ($skill['name'] ?? $skill['code']) : $skill;
                $enhancedPrompt .= "- {$skillName}\n";
            }
            $enhancedPrompt .= "\n";
        }

        // 添加权限边界提示
        if (!empty($permissions)) {
            $enhancedPrompt .= "【权限范围】\n你可以在以下范围内操作：\n";
            foreach ($permissions as $perm) {
                $enhancedPrompt .= "- {$perm}\n";
            }
            $enhancedPrompt .= "\n";
        }

        // 添加安全提示
        $enhancedPrompt .= "【安全提示】\n";
        $enhancedPrompt .= "- 对于危险操作（如删除文件、执行命令），必须先询问用户确认\n";
        $enhancedPrompt .= "- 不要泄露敏感信息（如 API Key、密码）\n";
        $enhancedPrompt .= "- 遇到无法完成的任务，请诚实告知用户\n";
        $enhancedPrompt .= "- 使用技能时，请确保参数正确且在权限范围内\n\n";

        // 添加用户输入
        $enhancedPrompt .= "【用户输入】\n{$prompt}";

        return $enhancedPrompt;
    }

    /**
     * 处理响应
     *
     * 格式化 Bot 智能体的响应：
     * - 提取工具调用
     * - 清理敏感信息
     */
    public function processResponse(string $response, array $params = []): string
    {
        // 移除可能的敏感信息（API Key 等）
        $response = $this->redactSensitiveInfo($response);

        return $response;
    }

    /**
     * 验证输入参数
     */
    public function validateParams(array $params = []): array
    {
        $errors = [];

        // 检查必需参数
        if (isset($params['role_id']) && !is_numeric($params['role_id'])) {
            $errors[] = 'role_id 必须是数字';
        }

        if (isset($params['skills']) && !is_array($params['skills'])) {
            $errors[] = 'skills 必须是数组';
        }

        if (isset($params['permissions']) && !is_array($params['permissions'])) {
            $errors[] = 'permissions 必须是数组';
        }

        return $errors;
    }

    /**
     * 获取参数模板
     */
    public function getParamTemplate(): array
    {
        return [
            'role_id' => [
                'type' => 'int',
                'required' => false,
                'description' => '角色 ID',
            ],
            'role_name' => [
                'type' => 'string',
                'required' => false,
                'description' => '角色名称',
                'default' => 'AI 助手',
            ],
            'skills' => [
                'type' => 'array',
                'required' => false,
                'description' => '可用技能列表',
            ],
            'permissions' => [
                'type' => 'array',
                'required' => false,
                'description' => '权限列表',
            ],
            'memory' => [
                'type' => 'array',
                'required' => false,
                'description' => '相关记忆',
            ],
        ];
    }

    /**
     * 获取使用示例
     */
    public function getExamples(): array
    {
        return [
            [
                'title' => '基础对话',
                'description' => '使用默认角色进行对话',
                'input' => '帮我整理一下今天的待办事项',
                'expected_output' => 'AI 会根据角色配置和可用技能提供帮助',
            ],
            [
                'title' => '文件操作',
                'description' => '请求 AI 帮助处理文件',
                'input' => '读取 /var/www/config.json 文件',
                'expected_output' => 'AI 会调用文件系统技能完成任务',
            ],
            [
                'title' => 'Shell 命令',
                'description' => '请求 AI 执行命令',
                'input' => '查看当前目录下的文件列表',
                'expected_output' => 'AI 会调用 Shell 技能执行命令并返回结果',
            ],
        ];
    }

    /**
     * 检查是否支持指定模型
     */
    public function supportsModel(string $modelCode): bool
    {
        return true; // 支持所有模型
    }

    /**
     * 脱敏敏感信息
     */
    private function redactSensitiveInfo(string $text): string
    {
        // API Key 模式
        $patterns = [
            '/sk-[a-zA-Z0-9]{20,}/' => 'sk-****REDACTED****',
            '/api[_-]?key["\']?\s*[:=]\s*["\']?[a-zA-Z0-9_-]{20,}/i' => 'api_key: ****REDACTED****',
            '/password["\']?\s*[:=]\s*["\']?[^\s"\']+/i' => 'password: ****REDACTED****',
            '/secret["\']?\s*[:=]\s*["\']?[a-zA-Z0-9_-]{10,}/i' => 'secret: ****REDACTED****',
            '/token["\']?\s*[:=]\s*["\']?[a-zA-Z0-9_-]{20,}/i' => 'token: ****REDACTED****',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }
}
