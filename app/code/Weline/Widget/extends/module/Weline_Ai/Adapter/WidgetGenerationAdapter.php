<?php

declare(strict_types=1);

namespace Weline\Widget\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Api\AdapterModelBindingInterface;
use Weline\Ai\Api\ScenarioAdapterInterface;

/**
 * 部件库 AI 生成场景（widget_generation），与 widget_builder 智能体配套。
 */
class WidgetGenerationAdapter implements ScenarioAdapterInterface, AdapterModelBindingInterface
{
    public function getDefaultModelBindings(): array
    {
        return [];
    }

    public function getCode(): string
    {
        return 'widget_generation';
    }

    public function getName(): string
    {
        return (string)__('Widget AI 生成');
    }

    public function getDescription(): string
    {
        return (string)__(
            '用于主题编辑器部件库「AI 生成」创建普通 Widget registry，与 widget_builder 智能体配套。',
        );
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
        return [
            'description' => 'Widget AI generation adapter parameters',
            'fields' => [
                'prompt' => ['type' => 'string'],
                'desired_type' => ['type' => 'string'],
                'generation_context' => ['type' => 'object'],
            ],
        ];
    }

    public function getExamples(): array
    {
        return [];
    }

    public function supportsModel(string $modelCode): bool
    {
        return $modelCode !== '';
    }
}
