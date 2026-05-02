<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

class TaskPlanGenerationAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'pagebuilder_task_plan_generation';
    }

    public function getName(): string
    {
        return 'PageBuilder 第二阶段任务方案生成适配器';
    }

    public function getDescription(): string
    {
        return '用于第二阶段任务方案草案/微调的提示词约束，确保输出可直接落库的结构化 JSON。';
    }

    public function getVersion(): string
    {
        return '1.0.1';
    }

    public function getSupportedModelTypes(): array
    {
        return ['*'];
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $normalized = trim($prompt);
        if ($normalized === '') {
            return $prompt;
        }

        $focusHints = [];
        $mode = trim((string)($params['prompt_mode'] ?? ''));
        if ($mode !== '') {
            $focusHints[] = '当前模式：' . $mode;
        }
        $targetScope = trim((string)($params['target_scope'] ?? ''));
        if ($targetScope !== '') {
            $focusHints[] = '目标范围：' . $targetScope;
        }
        $round = (int)($params['round'] ?? 0);
        if ($round > 0) {
            $focusHints[] = '轮次：' . $round;
        }

        $hasJsonConstraint = stripos($normalized, 'json') !== false
            || stripos($normalized, 'response_format') !== false;
        if ($hasJsonConstraint && $focusHints === []) {
            return $prompt;
        }

        $prefix = $focusHints === [] ? '' : ("\n" . implode("\n", $focusHints) . "\n");

        return $normalized . "\n\n"
            . $prefix
            . "输出约束：\n"
            . "1. 必须输出合法 JSON 对象。\n"
            . "2. 不要输出 markdown 代码块标记（如 ```json）。\n"
            . "3. 不要输出 JSON 之外的解释文字。\n"
            . "4. 输出必须能直接用于保存阶段二任务方案草案。\n"
            . "5. shared_tasks 只包含全站共享任务；page_tasks 只包含各页面任务。\n"
            . "6. 任务粒度应可被后续构建阶段逐项执行，不要把多个目标揉进同一 task。\n"
            . "7. 若是微调模式，只重写目标范围相关内容；若是重建模式，输出完整新草案，不沿用旧草案片段。\n";
    }

    public function processResponse(string $response, array $params = []): string
    {
        $content = trim($response);
        if ($content === '') {
            return $response;
        }

        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $content, $matches)) {
            $content = trim((string)($matches[1] ?? ''));
        }

        if (preg_match('/(\{[\s\S]*\})/m', $content, $matches)) {
            $candidate = trim((string)($matches[1] ?? ''));
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
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
            'description' => '阶段二任务方案生成参数',
            'fields' => [
                'prompt_mode' => 'refine_task_plan | rebuild_task_plan | detect_bootstrap_task_plan',
                'target_scope' => 'string',
                'round' => 'int',
            ],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => '生成阶段二任务方案',
                'description' => '根据已确认的第一阶段方案输出完整任务方案 JSON',
                'input' => '按已确认方向生成 stage-2 任务计划，覆盖 shared_tasks 与 page_tasks',
                'expected_output' => '{"markdown":"","virtual_theme_plan":{"shared_tasks":[],"page_tasks":{}}}',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}
