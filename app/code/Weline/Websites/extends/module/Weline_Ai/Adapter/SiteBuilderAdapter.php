<?php
declare(strict_types=1);
/*
 * 网站站点构建场景适配器（site_builder）
 *
 * 目的：
 * - 让场景码 `site_builder` 能被 AdapterScanner 注册到 `ai_scenario_adapter`
 * - 方便在后台为该场景配置默认模型
 *
 * 注意：
 * - 这里默认不改写 prompt/response（零改造适配器）
 */
namespace Weline\Websites\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Api\AdapterStyleBindingInterface;
use Weline\Ai\Api\ScenarioAdapterInterface;

class SiteBuilderAdapter implements ScenarioAdapterInterface, AdapterStyleBindingInterface
{
    public function getDefaultStyleCodes(): array
    {
        return [];
    }

    public function getCode(): string
    {
        return 'site_builder';
    }

    public function getName(): string
    {
        return __('网站站点构建场景适配器');
    }

    public function getDescription(): string
    {
        return __('用于 AI 网站站点构建（site_builder）场景的零改造适配器，支持为该场景配置默认模型。');
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
                'title' => __('站点构建请求示例'),
                'description' => __('输入站点构建目标与期望效果，让 AI 给出方案并执行。'),
                'input' => __('请帮我把一个博客站点搭起来，包含分类页与文章详情页。'),
                'expected_output' => __('系统会输出落地方案与后续构建进度/结果。'),
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}

