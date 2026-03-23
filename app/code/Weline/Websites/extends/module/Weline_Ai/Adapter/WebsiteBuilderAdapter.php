<?php
declare(strict_types=1);
/*
 * AI 建站工作台场景适配器
 *
 * 目的：
 * - 让场景码 `website_builder` 能被 AdapterScanner 注册到 `ai_scenario_adapter`
 * - 方便在后台为该场景配置默认模型
 *
 * 注意：
 * - 这里默认不改写 prompt/response（零改造适配器）
 */
namespace Weline\Websites\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

class WebsiteBuilderAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'website_builder';
    }

    public function getName(): string
    {
        return __('AI 建站工作台场景适配器');
    }

    public function getDescription(): string
    {
        return __('用于 AI 建站工作台（website_builder）场景的零改造适配器，支持为该场景配置默认模型。');
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
        return $prompt;
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
        return [];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => __('建站请求示例'),
                'description' => __('输入建站需求，让 AI 按流程完成建议与落地动作。'),
                'input' => __('我想做一个茶叶电商网站，风格偏高级简约，域名优先用 .com。'),
                'expected_output' => __('系统会输出域名建议与后续建站进度/结果。'),
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}

