<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Manager\ObjectManager;

final class AiSiteTaskPlanSseService
{
    private readonly AiSiteVirtualThemePlanService $virtualThemePlanService;

    public function __construct(?AiSiteVirtualThemePlanService $virtualThemePlanService = null)
    {
        $this->virtualThemePlanService = $virtualThemePlanService
            ?? ObjectManager::getInstance(AiSiteVirtualThemePlanService::class);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{
     *   prompt_mode:string,
     *   round:int,
     *   instruction:string,
     *   target_scope:string,
     *   virtual_theme_plan:array<string, mixed>,
     *   task_plan_structured:array<string, mixed>,
     *   draft_markdown:string,
     *   chunk_parts:list<string>,
     *   task_plan_change_scope_report:array<string, mixed>,
     *   task_plan_rebuild_summary:array<string, mixed>
     * }
     */
    public function buildDraftFromPrompt(
        array $scope,
        string $promptMode,
        string $instruction = '',
        string $targetScope = '',
        int $round = 1
    ): array {
        $normalizedPromptMode = $this->normalizePromptMode($promptMode);
        $normalizedInstruction = \trim($instruction);
        $normalizedTargetScope = \trim($targetScope);
        $normalizedRound = \max(1, $round);

        $baseArtifacts = $this->buildBaseArtifacts($scope);
        $structured = \is_array($baseArtifacts['structured'] ?? null) ? $baseArtifacts['structured'] : [];
        $virtualThemePlan = \is_array($baseArtifacts['virtual_theme_plan'] ?? null) ? $baseArtifacts['virtual_theme_plan'] : [];

        $changeScopeReport = [];
        $rebuildSummary = [];
        if ($normalizedPromptMode === 'rebuild_task_plan') {
            $rebuildSummary = $this->buildRebuildSummary($scope, $structured, $normalizedInstruction, $normalizedTargetScope, $normalizedRound);
            $structured['task_plan_rebuild_summary'] = $rebuildSummary;
            $structured['task_plan_change_scope_report'] = [];
        } else {
            $changeScopeReport = $this->buildChangeScopeReport($structured, $normalizedInstruction, $normalizedTargetScope, $normalizedRound);
            $structured['task_plan_change_scope_report'] = $changeScopeReport;
            $structured['task_plan_rebuild_summary'] = [];
        }

        $structured['task_plan_prompt_context'] = [
            'prompt_mode' => $normalizedPromptMode,
            'instruction' => $normalizedInstruction,
            'target_scope' => $normalizedTargetScope,
            'round' => $normalizedRound,
            'generated_at' => \date('Y-m-d H:i:s'),
        ];

        $virtualThemePlan = \array_replace($virtualThemePlan, $structured);
        $virtualThemePlan['signature'] = \sha1((string)\json_encode(
            $virtualThemePlan,
            \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR
        ));

        $markdown = $this->buildMarkdown($virtualThemePlan, $structured, $normalizedPromptMode, $normalizedInstruction, $normalizedTargetScope, $normalizedRound);

        return [
            'prompt_mode' => $normalizedPromptMode,
            'round' => $normalizedRound,
            'instruction' => $normalizedInstruction,
            'target_scope' => $normalizedTargetScope,
            'virtual_theme_plan' => $virtualThemePlan,
            'task_plan_structured' => $structured,
            'draft_markdown' => $markdown,
            'chunk_parts' => $this->splitMarkdownToChunks($markdown),
            'task_plan_change_scope_report' => $changeScopeReport,
            'task_plan_rebuild_summary' => $rebuildSummary,
        ];
    }

    private function normalizePromptMode(string $promptMode): string
    {
        return \trim($promptMode) === 'rebuild_task_plan' ? 'rebuild_task_plan' : 'refine_task_plan';
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{markdown:string,structured:array<string,mixed>,virtual_theme_plan:array<string,mixed>}
     */
    private function buildBaseArtifacts(array $scope): array
    {
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $artifacts = $this->virtualThemePlanService->buildTaskPlanArtifacts($scope, $buildBlueprint);
        if (!\is_array($artifacts['structured'] ?? null)) {
            $artifacts['structured'] = [];
        }
        if (!\is_array($artifacts['virtual_theme_plan'] ?? null)) {
            $artifacts['virtual_theme_plan'] = [];
        }
        $currentDraft = \is_array($scope['virtual_theme_plan']['draft'] ?? null) ? $scope['virtual_theme_plan']['draft'] : [];
        if ($currentDraft !== []) {
            $artifacts['virtual_theme_plan'] = \array_replace($artifacts['virtual_theme_plan'], $currentDraft);
        }
        return $artifacts;
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function buildChangeScopeReport(
        array $structured,
        string $instruction,
        string $targetScope,
        int $round
    ): array {
        $executionOrder = \is_array($structured['execution_order'] ?? null) ? $structured['execution_order'] : [];
        $touchedTaskKeys = [];
        foreach ($executionOrder as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            if ($targetScope !== '' && !$this->taskMatchesScope($task, $targetScope)) {
                continue;
            }
            $touchedTaskKeys[] = $taskKey;
            if (\count($touchedTaskKeys) >= 8) {
                break;
            }
        }
        if ($touchedTaskKeys === [] && $executionOrder !== []) {
            $fallback = \is_array($executionOrder[0] ?? null) ? $executionOrder[0] : [];
            $fallbackTaskKey = \trim((string)($fallback['task_key'] ?? ''));
            if ($fallbackTaskKey !== '') {
                $touchedTaskKeys[] = $fallbackTaskKey;
            }
        }

        return [
            'summary' => $instruction !== ''
                ? 'Applied refine instruction to selected scope.'
                : 'Refined the task plan with default optimization pass.',
            'target_scope' => $targetScope,
            'round' => $round,
            'instruction' => $instruction,
            'touched_task_keys' => $touchedTaskKeys,
            'change_count' => \count($touchedTaskKeys),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function buildRebuildSummary(
        array $scope,
        array $structured,
        string $instruction,
        string $targetScope,
        int $round
    ): array {
        $executionOrder = \is_array($structured['execution_order'] ?? null) ? $structured['execution_order'] : [];
        $pageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [];

        return [
            'summary' => 'Rebuilt task plan draft from current confirmed stage-1 blueprint.',
            'target_scope' => $targetScope,
            'round' => $round,
            'instruction' => $instruction,
            'task_count' => \count($executionOrder),
            'page_type_count' => \count($pageTypes),
        ];
    }

    /**
     * @param array<string, mixed> $task
     */
    private function taskMatchesScope(array $task, string $targetScope): bool
    {
        $needle = \strtolower(\trim($targetScope));
        if ($needle === '') {
            return true;
        }
        $taskKey = \strtolower((string)($task['task_key'] ?? ''));
        $pageType = \strtolower((string)($task['page_type'] ?? ''));
        $groupKey = \strtolower((string)($task['group_key'] ?? ''));
        return \str_contains($taskKey, $needle)
            || \str_contains($pageType, $needle)
            || \str_contains($groupKey, $needle);
    }

    /**
     * @param array<string, mixed> $virtualThemePlan
     * @param array<string, mixed> $structured
     */
    private function buildMarkdown(
        array $virtualThemePlan,
        array $structured,
        string $promptMode,
        string $instruction,
        string $targetScope,
        int $round
    ): string {
        $lines = [];
        $lines[] = '# Stage-2 Task Plan Draft';
        $lines[] = '';
        $lines[] = '- Prompt mode: ' . $promptMode;
        $lines[] = '- Round: ' . $round;
        $lines[] = '- Target scope: ' . ($targetScope !== '' ? $targetScope : 'all');
        $lines[] = '- Instruction: ' . ($instruction !== '' ? $instruction : 'N/A');
        $lines[] = '- Signature: ' . (string)($virtualThemePlan['signature'] ?? '');
        $lines[] = '';
        $lines[] = '## Execution Order';
        $executionOrder = \is_array($structured['execution_order'] ?? null) ? $structured['execution_order'] : [];
        if ($executionOrder === []) {
            $lines[] = '- (no tasks)';
        } else {
            foreach ($executionOrder as $index => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = (string)($task['task_key'] ?? 'task');
                $pageType = (string)($task['page_type'] ?? 'shared');
                $lines[] = ($index + 1) . '. ' . $taskKey . ' [' . $pageType . ']';
            }
        }
        $lines[] = '';
        if ($promptMode === 'rebuild_task_plan') {
            $summary = \is_array($structured['task_plan_rebuild_summary'] ?? null) ? $structured['task_plan_rebuild_summary'] : [];
            $lines[] = '## Rebuild Summary';
            $lines[] = '- Summary: ' . (string)($summary['summary'] ?? '');
            $lines[] = '- Task count: ' . (int)($summary['task_count'] ?? 0);
            $lines[] = '- Page type count: ' . (int)($summary['page_type_count'] ?? 0);
        } else {
            $report = \is_array($structured['task_plan_change_scope_report'] ?? null) ? $structured['task_plan_change_scope_report'] : [];
            $lines[] = '## Change Scope Report';
            $lines[] = '- Summary: ' . (string)($report['summary'] ?? '');
            $lines[] = '- Change count: ' . (int)($report['change_count'] ?? 0);
            $taskKeys = \is_array($report['touched_task_keys'] ?? null) ? $report['touched_task_keys'] : [];
            foreach ($taskKeys as $taskKey) {
                $lines[] = '- touched: ' . (string)$taskKey;
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function splitMarkdownToChunks(string $markdown): array
    {
        $lines = \explode("\n", $markdown);
        $chunks = [];
        $buffer = [];
        foreach ($lines as $line) {
            $buffer[] = $line;
            if (\count($buffer) < 4) {
                continue;
            }
            $chunks[] = \implode("\n", $buffer) . "\n";
            $buffer = [];
        }
        if ($buffer !== []) {
            $chunks[] = \implode("\n", $buffer) . "\n";
        }
        if ($chunks === []) {
            $chunks[] = $markdown;
        }
        return $chunks;
    }
}

