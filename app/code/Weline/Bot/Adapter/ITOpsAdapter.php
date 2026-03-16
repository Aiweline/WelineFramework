<?php
declare(strict_types=1);

namespace Weline\Bot\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * IT 运维适配器
 *
 * 专为 IT 运维场景设计的适配器
 */
class ITOpsAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'bot_it_ops';
    }

    public function getName(): string
    {
        return __('IT 运维助手');
    }

    public function getDescription(): string
    {
        return __('专为 IT 运维场景设计，支持服务器监控、日志分析、故障排查、自动化运维等能力。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedModelTypes(): array
    {
        return ['*'];
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $systemPrompt = "你是一个专业的 IT 运维助手，具备以下能力：\n\n";
        $systemPrompt .= "【核心能力】\n";
        $systemPrompt .= "- 服务器监控和状态检查\n";
        $systemPrompt .= "- 日志分析和错误排查\n";
        $systemPrompt .= "- 服务管理和配置检查\n";
        $systemPrompt .= "- 性能优化建议\n";
        $systemPrompt .= "- 安全审计和漏洞检查\n\n";
        $systemPrompt .= "【工作原则】\n";
        $systemPrompt .= "- 操作前先备份重要数据\n";
        $systemPrompt .= "- 危险操作需用户确认\n";
        $systemPrompt .= "- 提供详细的操作日志\n";
        $systemPrompt .= "- 给出问题和解决方案的详细分析\n\n";
        $systemPrompt .= "用户请求：{$prompt}";

        return $systemPrompt;
    }

    public function processResponse(string $response, array $params = []): string
    {
        return $response;
    }

    public function validateParams(array $params = []): array
    {
        return [];
    }

    public function getParamTemplate(): array
    {
        return [
            'server' => [
                'type' => 'string',
                'required' => false,
                'description' => '目标服务器',
            ],
            'service' => [
                'type' => 'string',
                'required' => false,
                'description' => '目标服务',
            ],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => '检查服务状态',
                'input' => '检查 nginx 服务状态',
                'expected_output' => '返回 nginx 服务运行状态、端口监听、最近日志等信息',
            ],
            [
                'title' => '日志分析',
                'input' => '分析 /var/log/nginx/error.log 最近 100 行的错误',
                'expected_output' => '返回错误类型统计、主要问题、修复建议',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}
