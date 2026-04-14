<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

class PlanGenerationAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'pagebuilder_plan_generation';
    }

    public function getName(): string
    {
        return '页面构建器方案生成适配器';
    }

    public function getDescription(): string
    {
        return 'PageBuilder 阶段一建站方案生成专用适配器。'
            . '用于约束 AI 输出结构化 JSON，减少方案流式生成阶段的格式漂移。';
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
        $normalized = \trim($prompt);
        if ($normalized === '') {
            return $prompt;
        }

        $hasJsonConstraint = \stripos($normalized, 'json') !== false
            || \stripos($normalized, 'response_format') !== false;
        if ($hasJsonConstraint) {
            return $prompt;
        }

        return $normalized . "\n\n"
            . "输出约束：\n"
            . "1. 必须输出合法 JSON 对象。\n"
            . "2. 不要输出 markdown 代码块标记（如 ```json）。\n"
            . "3. 不要输出 JSON 之外的解释文字。\n";
    }

    public function processResponse(string $response, array $params = []): string
    {
        $content = \trim($response);
        if ($content === '') {
            return $response;
        }

        if (\preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $content, $matches)) {
            $content = \trim((string)($matches[1] ?? ''));
        }

        if (\preg_match('/(\{[\s\S]*\})/m', $content, $matches)) {
            $candidate = \trim((string)($matches[1] ?? ''));
            $decoded = \json_decode($candidate, true);
            if (\json_last_error() === \JSON_ERROR_NONE && \is_array($decoded)) {
                return $candidate;
            }
        }

        return $response;
    }

    public function validateParams(array $params = []): array
    {
        return [];
    }

    public function getParamTemplate(): array
    {
        return [
            'description' => '阶段一建站方案生成参数',
            'fields' => [],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => '生成阶段一方案',
                'description' => '根据站点信息输出完整建站方案 JSON',
                'input' => '生成一个用于印度市场游戏站点的阶段一方案',
                'expected_output' => '{"site_strategy":{},"pages":{},"execution_steps":[]}',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}

