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

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;

/**
 * 模拟AI提供者
 * 
 * 功能：
 * - 用于测试和开发
 * - 不调用实际的AI API
 * - 返回模拟数据
 */
class MockProvider implements ProviderInterface, ProviderConnectionTestInterface
{
    use ProviderConnectionTestTrait;

    /**
     * 生成内容
     * 
     * @param AiModel $model
     * @param string $prompt
     * @param array $params
     * @return array
     */
    public function generate(AiModel $model, string $prompt, array $params = []): array
    {
        // 模拟API延迟
        usleep(500000); // 0.5秒

        $mockResponse = $this->generateMockResponse($prompt, $model);

        return [
            'content' => $mockResponse,
            'usage' => [
                'prompt_tokens' => $this->estimateTokens($prompt),
                'completion_tokens' => $this->estimateTokens($mockResponse),
                'total_tokens' => $this->estimateTokens($prompt . $mockResponse),
            ],
            'model' => $model->getModelCode(),
            'finish_reason' => 'stop',
        ];
    }

    /**
     * 流式生成
     * 
     * @param AiModel $model
     * @param string $prompt
     * @param callable $callback
     * @param array $params
     * @return array
     */
    public function generateStream(AiModel $model, string $prompt, callable $callback, array $params = []): array
    {
        $mockResponse = $this->generateMockResponse($prompt, $model);
        
        // 分块发送
        $chunks = str_split($mockResponse, max(1, (int)ceil(strlen($mockResponse) / 20)));
        
        foreach ($chunks as $chunk) {
            $callback($chunk);
            usleep(50000); // 0.05秒延迟
        }

        return [
            'content' => $mockResponse,
            'usage' => [
                'prompt_tokens' => $this->estimateTokens($prompt),
                'completion_tokens' => $this->estimateTokens($mockResponse),
                'total_tokens' => $this->estimateTokens($prompt . $mockResponse),
            ],
        ];
    }

    /**
     * 生成模拟响应
     * 
     * @param string $prompt
     * @param AiModel $model
     * @return string
     */
    private function generateMockResponse(string $prompt, AiModel $model): string
    {
        $modelName = $model->getName();
        $vendor = $model->getVendor();
        
        $templates = [
            "这是来自{$vendor}的{$modelName}模型的模拟响应。\n\n您的提示词是：{$prompt}\n\n这是一个测试响应，用于开发和测试目的。在生产环境中，请配置真实的API密钥以使用实际的AI服务。",
            "您好！我是{$modelName}（{$vendor}）的模拟版本。\n\n我收到了您的问题：「{$prompt}」\n\n由于当前使用的是模拟模式，我无法提供真实的AI生成内容。请配置API密钥以启用真实服务。",
            "【模拟模式】\n\n模型：{$modelName}\n提供商：{$vendor}\n提示词：{$prompt}\n\n这是一个模拟响应。要使用真实的AI功能，请：\n1. 配置API密钥\n2. 检查网络连接\n3. 确认API配额",
        ];

        $template = $templates[array_rand($templates)];
        
        return strtr($template, [
            '{$vendor}' => $vendor,
            '{$modelName}' => $modelName,
            '{$prompt}' => mb_substr($prompt, 0, 100) . (mb_strlen($prompt) > 100 ? '...' : ''),
        ]);
    }

    /**
     * 估算token数量
     * 
     * @param string $text
     * @return int
     */
    private function estimateTokens(string $text): int
    {
        // 简单估算
        return (int)ceil(mb_strlen($text) / 3);
    }

    /**
     * 检查模型支持
     * 
     * @param string $modelCode
     * @return bool
     */
    public function supports(string $modelCode): bool
    {
        // 模拟提供者支持所有模型
        return true;
    }

    /**
     * 获取供应商代码
     * 
     * @return string
     */
    public function getProviderCode(): string
    {
        return 'mock';
    }

    /**
     * 获取该供应商支持的模型列表
     * 
     * @return array
     */
    public function getSupportedModels(): array
    {
        // 模拟提供者返回一个测试模型
        return [
            [
                'code' => 'mock-model',
                'name' => 'Mock Model',
                'description' => '用于测试的模拟模型',
                'max_tokens' => 4096,
                'context_window' => 8192,
                'input_price' => 0,
                'output_price' => 0,
                'capabilities' => ['chat', 'code']
            ]
        ];
    }
}

