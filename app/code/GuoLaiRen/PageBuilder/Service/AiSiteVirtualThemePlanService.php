<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;

final class AiSiteVirtualThemePlanService
{
    private const BLOCK_TASK_SCHEMA_VERSION = 'stage2-block-task-v1';

    private const STAGE2_BLOCK_TASK_FANOUT_GROUP = 'stage2.block_task_plan';

    private const FRONTEND_DESIGN_SKILL_SOURCE = 'https://github.com/anthropics/claude-code/blob/main/plugins/frontend-design/skills/frontend-design/SKILL.md';

    private const FRONTEND_DESIGN_SKILL_LOCAL_PATH = 'app/code/GuoLaiRen/PageBuilder/Service/AI/prompt_guides/frontend-design/SKILL.md';

    private const BLOCK_TASK_REQUIRED_FIELDS = [
        'task_goal',
        'meta_fields',
        'content_plan',
        'style_plan',
        'planning_reason',
        'sort_order',
    ];

    public function __construct(
        private readonly ?AiService $aiService = null,
    ) {
    }

    /**
     * 按批次生成第二阶段任务计划：shared 一次，随后按 page_type 逐页生成，再由服务端组装完整文档。
     *
     * @return array{markdown:string,structured:array<string,mixed>,virtual_theme_plan:array<string,mixed>}
     */
    private function buildTaskPlanArtifactsByAiInBatches(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        ?callable $chunkCallback = null,
        ?callable $heartbeatCallback = null,
        string $mode = 'generate_task_plan',
        string $instruction = '',
        string $targetScope = '',
        ?array $selectedBatchIds = null,
        ?callable $progressCallback = null
    ): array {
        $pageTypes = \array_values(\array_filter(\array_map('strval', \array_keys(\is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : []))));
        $assembledStructured = $structured;
        $assembledVirtualThemePlan = $virtualThemePlan;
        $riskNotes = \is_array($structured['risk_notes'] ?? null) ? \array_values($structured['risk_notes']) : [];
        $selectedBatchMap = \is_array($selectedBatchIds) ? \array_fill_keys($selectedBatchIds, true) : null;
        $allBatches = $this->buildTaskPlanGenerationBatches($structured);
        $effectiveBatches = [];
        foreach ($allBatches as $candidateBatch) {
            if ($selectedBatchMap !== null && !isset($selectedBatchMap[$this->buildTaskPlanBatchId($candidateBatch)])) {
                continue;
            }
            $effectiveBatches[] = $candidateBatch;
        }
        $totalBatches = \count($effectiveBatches);
        $completedBatches = 0;

        foreach ($effectiveBatches as $batchIndex => $batch) {
            $fanoutBatches[] = ['batch' => $batch, 'batch_index' => $batchIndex];
        }

        if ($fanoutBatches !== []) {
            $decodedByBatchId = $this->requestTaskPlanFanoutBatchesConcurrently(
                $scope,
                $buildBlueprint,
                $assembledStructured,
                $assembledVirtualThemePlan,
                $fanoutBatches,
                $mode,
                $instruction,
                $targetScope,
                $chunkCallback,
                $heartbeatCallback,
                $progressCallback,
                $totalBatches,
                $completedBatches
            );

            foreach ($fanoutBatches as $fanoutBatch) {
                $batch = \is_array($fanoutBatch['batch'] ?? null) ? $fanoutBatch['batch'] : [];
                $batchIndex = (int)($fanoutBatch['batch_index'] ?? 0);
                $batchId = $this->buildTaskPlanBatchId($batch);
                $decoded = \is_array($decodedByBatchId[$batchId] ?? null) ? $decodedByBatchId[$batchId] : [];
                ['structured' => $assembledStructured, 'virtual_theme_plan' => $assembledVirtualThemePlan, 'risk_notes' => $riskNotes] =
                    $this->mergeTaskPlanGenerationBatch(
                        $assembledStructured,
                        $assembledVirtualThemePlan,
                        $riskNotes,
                        $batch,
                        $decoded
                    );
                if ($heartbeatCallback !== null) {
                    $heartbeatCallback();
                }
                $completedBatches++;
                $this->emitTaskPlanBatchProgress($progressCallback, 'batch_done', $batch, $batchIndex, $totalBatches, $completedBatches, [
                    'structured' => $assembledStructured,
                    'virtual_theme_plan' => $assembledVirtualThemePlan,
                ]);
                if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent()) {
                    SchedulerSystem::yieldDelay(1);
                }
            }
        }

        $assembledStructured['risk_notes'] = $riskNotes;
        $assembledVirtualThemePlan['risk_notes'] = $riskNotes;
        $assembledStructured = $this->sanitizePromptLikeTaskPlanStructured($assembledStructured);
        $assembledStructured = $this->applyBlockTaskSchemaToStructured($assembledStructured);
        $assembledStructured = $this->ensureTaskDirectoryHierarchy($assembledStructured);
        $assembledStructured = $this->syncStageTwoRuntimeContexts($assembledStructured);
        $assembledStructured = $this->syncStageTwoTaskSortArtifacts($assembledStructured, $pageTypes);
        $assembledVirtualThemePlan = \array_replace_recursive($assembledVirtualThemePlan, [
            'block_task_schema' => $assembledStructured['block_task_schema'] ?? [],
            'task_directory_tree' => $assembledStructured['task_directory_tree'] ?? [],
            'task_tree' => $assembledStructured['task_tree'] ?? [],
            'shared_tasks' => $assembledStructured['shared_tasks'] ?? [],
            'page_tasks' => $assembledStructured['page_tasks'] ?? [],
            'shared_block_tasks' => $assembledStructured['shared_block_tasks'] ?? [],
            'page_block_tasks' => $assembledStructured['page_block_tasks'] ?? [],
            'virtual_theme_build_tree' => $assembledStructured['virtual_theme_build_tree'] ?? [],
            'execution_blueprint' => $assembledStructured['execution_blueprint'] ?? [],
        ]);
        $this->assertAiTaskPlanIsContentful($assembledStructured);
        $assembledVirtualThemePlan['signature'] = $this->buildSignature($assembledStructured);

        return [
            'markdown' => $this->buildStageTwoMarkdown(
                $pageTypes,
                \is_array($assembledStructured['shared_tasks'] ?? null) ? $assembledStructured['shared_tasks'] : [],
                \is_array($assembledStructured['page_tasks'] ?? null) ? $assembledStructured['page_tasks'] : [],
                $assembledStructured
            ),
            'structured' => $assembledStructured,
            'virtual_theme_plan' => $assembledVirtualThemePlan,
        ];
    }

    /**
     * @param callable|null $progressCallback
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $extra
     */
    private function emitTaskPlanBatchProgress(
        ?callable $progressCallback,
        string $status,
        array $batch,
        int $batchIndex,
        int $totalBatches,
        int $completedBatches,
        array $extra = []
    ): void {
        if ($progressCallback === null) {
            return;
        }

        $progressCallback(
            \array_replace([
                'status' => $status,
                'batch_id' => $this->buildTaskPlanBatchId($batch),
                'batch_type' => (string)($batch['type'] ?? ''),
                'batch_key' => (string)($batch['key'] ?? ''),
                'block_key' => (string)($batch['block_key'] ?? ''),
                'batch_index' => $batchIndex + 1,
                'total_batches' => $totalBatches,
                'completed_batches' => $completedBatches,
                'remaining_batches' => \max(0, $totalBatches - $completedBatches),
                'task_keys_count' => \count(\is_array($batch['task_keys'] ?? null) ? $batch['task_keys'] : []),
            ], $extra)
        );
    }

    /**
     * @param list<array{batch:array<string,mixed>,batch_index:int}> $fanoutBatches
     * @return array<string, array<string, mixed>>
     */
    private function requestTaskPlanFanoutBatchesConcurrently(
        array $scope,
        array $buildBlueprint,
        array $assembledStructured,
        array $assembledVirtualThemePlan,
        array $fanoutBatches,
        string $mode,
        string $instruction,
        string $targetScope,
        ?callable $chunkCallback,
        ?callable $heartbeatCallback,
        ?callable $progressCallback,
        int $totalBatches,
        int $completedBatches
    ): array {
        foreach ($fanoutBatches as $fanoutBatch) {
            $batch = \is_array($fanoutBatch['batch'] ?? null) ? $fanoutBatch['batch'] : [];
            $batchIndex = (int)($fanoutBatch['batch_index'] ?? 0);
            $this->emitTaskPlanBatchProgress($progressCallback, 'batch_begin', $batch, $batchIndex, $totalBatches, $completedBatches);
        }
        if ($heartbeatCallback !== null) {
            $heartbeatCallback();
        }

        if (\count($fanoutBatches) <= 1 || !\class_exists(\Fiber::class)) {
            $decodedByBatchId = [];
            foreach ($fanoutBatches as $fanoutBatch) {
                $batch = \is_array($fanoutBatch['batch'] ?? null) ? $fanoutBatch['batch'] : [];
                $batchIndex = (int)($fanoutBatch['batch_index'] ?? 0);
                try {
                    $decodedByBatchId[$this->buildTaskPlanBatchId($batch)] = $this->requestTaskPlanJsonFromAi(
                        $scope,
                        $this->buildTaskPlanGenerationBatchPrompt(
                            $scope,
                            $buildBlueprint,
                            $assembledStructured,
                            $assembledVirtualThemePlan,
                            $batch,
                            $mode,
                            $instruction,
                            $targetScope
                        ),
                        $this->resolveTaskPlanBatchMaxTokens($batch),
                        $chunkCallback,
                        $heartbeatCallback
                    );
                } catch (\Throwable $batchThrowable) {
                    $decodedByBatchId[$this->buildTaskPlanBatchId($batch)] = $this->buildRecoverableTaskPlanBatchPayload(
                        $assembledStructured,
                        $batch,
                        $batchThrowable->getMessage()
                    );
                    $this->emitTaskPlanBatchProgress($progressCallback, 'batch_done', $batch, $batchIndex, $totalBatches, $completedBatches, [
                        'attempt_no' => 3,
                        'recovered' => true,
                        'warning_message' => $batchThrowable->getMessage(),
                    ]);
                }
            }
            return $decodedByBatchId;
        }

        /** @var array<string, \Fiber> $fibers */
        $fibers = [];
        $batchById = [];
        $batchIndexById = [];
        foreach ($fanoutBatches as $fanoutBatch) {
            $batch = \is_array($fanoutBatch['batch'] ?? null) ? $fanoutBatch['batch'] : [];
            $batchIndex = (int)($fanoutBatch['batch_index'] ?? 0);
            $batchId = $this->buildTaskPlanBatchId($batch);
            $batchById[$batchId] = $batch;
            $batchIndexById[$batchId] = $batchIndex;
            $prompt = $this->buildTaskPlanGenerationBatchPrompt(
                $scope,
                $buildBlueprint,
                $assembledStructured,
                $assembledVirtualThemePlan,
                $batch,
                $mode,
                $instruction,
                $targetScope
            );
            $maxTokens = $this->resolveTaskPlanBatchMaxTokens($batch);
            $fibers[$batchId] = new \Fiber(function () use ($scope, $prompt, $maxTokens, $chunkCallback, $heartbeatCallback): array {
                return $this->requestTaskPlanJsonFromAi(
                    $scope,
                    $prompt,
                    $maxTokens,
                    $chunkCallback,
                    $heartbeatCallback
                );
            });
        }

        $decodedByBatchId = [];
        $errors = [];
        foreach ($fibers as $batchId => $fiber) {
            try {
                $fiber->start();
            } catch (\Throwable $throwable) {
                $decodedByBatchId[$batchId] = $this->buildRecoverableTaskPlanBatchPayload(
                    $assembledStructured,
                    \is_array($batchById[$batchId] ?? null) ? $batchById[$batchId] : [],
                    $throwable->getMessage()
                );
                $this->emitTaskPlanBatchProgress(
                    $progressCallback,
                    'batch_done',
                    \is_array($batchById[$batchId] ?? null) ? $batchById[$batchId] : [],
                    (int)($batchIndexById[$batchId] ?? 0),
                    $totalBatches,
                    $completedBatches,
                    [
                        'attempt_no' => 3,
                        'recovered' => true,
                        'warning_message' => $throwable->getMessage(),
                    ]
                );
            }
        }

        while (\count($decodedByBatchId) + \count($errors) < \count($fibers)) {
            $madeProgress = false;
            foreach ($fibers as $batchId => $fiber) {
                if (isset($decodedByBatchId[$batchId]) || isset($errors[$batchId])) {
                    continue;
                }

                try {
                    if ($fiber->isTerminated()) {
                        $decodedByBatchId[$batchId] = $fiber->getReturn();
                        $madeProgress = true;
                        continue;
                    }
                    if ($fiber->isSuspended()) {
                        if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent()) {
                            SchedulerSystem::yieldDelay(1);
                        } else {
                            $fiber->resume();
                        }
                        $madeProgress = true;
                    }
                } catch (\Throwable $throwable) {
                    $decodedByBatchId[$batchId] = $this->buildRecoverableTaskPlanBatchPayload(
                        $assembledStructured,
                        \is_array($batchById[$batchId] ?? null) ? $batchById[$batchId] : [],
                        $throwable->getMessage()
                    );
                    $this->emitTaskPlanBatchProgress(
                        $progressCallback,
                        'batch_done',
                        \is_array($batchById[$batchId] ?? null) ? $batchById[$batchId] : [],
                        (int)($batchIndexById[$batchId] ?? 0),
                        $totalBatches,
                        $completedBatches,
                        [
                            'attempt_no' => 3,
                            'recovered' => true,
                            'warning_message' => $throwable->getMessage(),
                        ]
                    );
                    $madeProgress = true;
                }
            }

            if (!$madeProgress && \count($decodedByBatchId) + \count($errors) < \count($fibers)) {
                \usleep(1000);
            }
        }

        return $decodedByBatchId;
    }

    /**
     * Keep stage-2 generation usable when a model returns truncated or malformed
     * JSON for a single batch. The deterministic baseline still carries the
     * confirmed stage-1 page/block tree and can be refined by the user later.
     *
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function buildRecoverableTaskPlanBatchPayload(array $structured, array $batch, string $reason): array
    {
        $riskNotes = [
            'AI batch output was not usable JSON; deterministic stage-2 task baseline was used for this batch. Reason: ' . $this->excerptText($reason, 360),
        ];

        if (($batch['type'] ?? '') === 'shared') {
            return [
                'shared_tasks' => $this->filterTaskPlanTaskListForBatch(
                    \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [],
                    $batch
                ),
                'risk_notes' => $riskNotes,
            ];
        }

        $pageType = (string)($batch['key'] ?? '');
        return [
            'page_type' => $pageType,
            'page_tasks' => $this->filterTaskPlanTaskListForBatch(
                \is_array($structured['page_tasks'][$pageType] ?? null) ? $structured['page_tasks'][$pageType] : [],
                $batch
            ),
            'risk_notes' => $riskNotes,
        ];
    }

    /**
     * @param array<string, mixed> $structured
     * @return list<array<string, mixed>>
     */
    private function buildTaskPlanGenerationBatches(array $structured): array
    {
        $batches = [];
        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $blockKey = $this->firstNonEmptyString([
                $task['block_key'] ?? null,
                $task['component'] ?? null,
                \str_starts_with($taskKey, 'shared:') ? \substr($taskKey, 7) : null,
                $taskKey,
            ]);
            $batches[] = [
                'type' => 'shared',
                'key' => 'shared',
                'block_key' => $blockKey !== '' ? $blockKey : $taskKey,
                'task_keys' => [$taskKey],
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'depends_on' => \array_values(\array_unique(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [])))),
                'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                'queue_job_key' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP . ':shared:' . ($blockKey !== '' ? $blockKey : $taskKey),
            ];
        }

        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks) || $tasks === []) {
                continue;
            }
            $pageType = (string)$pageType;
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $blockKey = $this->firstNonEmptyString([
                    $task['plan_context']['block_code'] ?? null,
                    $task['section_code'] ?? null,
                    $task['block_key'] ?? null,
                    \str_contains($taskKey, ':') ? \substr($taskKey, \strrpos($taskKey, ':') + 1) : null,
                    $taskKey,
                ]);
                $batches[] = [
                    'type' => 'page',
                    'key' => $pageType,
                    'block_key' => $blockKey !== '' ? $blockKey : $taskKey,
                    'task_keys' => [$taskKey],
                    'sort_order' => (int)($task['sort_order'] ?? 0),
                    'depends_on' => \array_values(\array_unique(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [])))),
                    'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                    'queue_job_key' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP . ':' . $pageType . ':' . ($blockKey !== '' ? $blockKey : $taskKey),
                ];
            }
        }

        return $batches;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function isStageTwoTaskPlanBatchComplete(array $task): bool
    {
        $status = \trim((string)($task['status'] ?? ''));
        if ($status !== 'done') {
            return false;
        }

        return \is_array($task['block_task'] ?? null)
            || \is_array($task['task_script'] ?? null)
            || \is_array($task['field_content_requirements'] ?? null);
    }

    /**
     * @param array<string, mixed> $batch
     */
    private function buildTaskPlanBatchId(array $batch): string
    {
        if (($batch['type'] ?? '') === 'shared') {
            $blockKey = \trim((string)($batch['block_key'] ?? $batch['key'] ?? ''));
            return 'shared:' . $this->normalizeTaskPlanBatchIdPart($blockKey !== '' ? $blockKey : 'block');
        }

        $pageType = \trim((string)($batch['key'] ?? 'page'));
        $blockKey = \trim((string)($batch['block_key'] ?? ''));
        if ($blockKey !== '') {
            return 'page:' . $pageType . ':' . $this->normalizeTaskPlanBatchIdPart($blockKey);
        }

        $taskKeys = \array_values(\array_filter(\array_map('strval', \is_array($batch['task_keys'] ?? null) ? $batch['task_keys'] : [])));
        if (\count($taskKeys) === 1) {
            return 'page:' . $pageType . ':' . \substr(\sha1($taskKeys[0]), 0, 12);
        }

        return 'page:' . $pageType;
    }

    private function normalizeTaskPlanBatchIdPart(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return 'block';
        }

        $normalized = (string)(\preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value) ?? $value);
        $normalized = \trim($normalized, '-_');

        return $normalized !== '' ? $normalized : \substr(\sha1($value), 0, 12);
    }

    /**
     * @return list<string>|null
     */
    private function resolveTaskPlanBatchIdsForTargetScope(array $structured, string $targetScope): ?array
    {
        $targetScope = \trim($targetScope);
        if ($targetScope === '' || $targetScope === 'task_plan' || $targetScope === 'all') {
            return null;
        }
        if (\str_starts_with($targetScope, 'shared')) {
            $sharedBatchIds = [];
            foreach ($this->buildTaskPlanGenerationBatches($structured) as $batch) {
                if (($batch['type'] ?? '') === 'shared') {
                    $sharedBatchIds[] = $this->buildTaskPlanBatchId($batch);
                }
            }

            return $sharedBatchIds !== [] ? \array_values(\array_unique($sharedBatchIds)) : null;
        }

        $selectedBatchIds = [];
        foreach ($this->buildTaskPlanGenerationBatches($structured) as $batch) {
            $haystackParts = [
                (string)($batch['key'] ?? ''),
                (string)($batch['block_key'] ?? ''),
                (string)($batch['queue_job_key'] ?? ''),
            ];
            foreach (\is_array($batch['task_keys'] ?? null) ? $batch['task_keys'] : [] as $taskKey) {
                $haystackParts[] = (string)$taskKey;
            }
            foreach ($haystackParts as $part) {
                if ($part !== '' && \str_contains($targetScope, $part)) {
                    $selectedBatchIds[] = $this->buildTaskPlanBatchId($batch);
                    break;
                }
            }
        }

        return $selectedBatchIds !== [] ? \array_values(\array_unique($selectedBatchIds)) : null;
    }

    /**
     * @param array<string, mixed> $batch
     */
    private function resolveTaskPlanBatchMaxTokens(array $batch): int
    {
        // Page-group batches carry multiple block task_script payloads; keep within provider limit but avoid truncating JSON.
        return \count(\is_array($batch['task_keys'] ?? null) ? $batch['task_keys'] : []) <= 1 ? 4200 : 8192;
    }

    /**
     * @param array{type:string,key:string,task_keys:list<string>} $batch
     */
    private function buildTaskPlanGenerationBatchPrompt(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        array $batch,
        string $mode = 'generate_task_plan',
        string $instruction = '',
        string $targetScope = ''
    ): string {
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $stage1TaskCues = \is_array($structured['stage1_task_cues'] ?? null) ? $structured['stage1_task_cues'] : [];
        $planLocale = \trim((string)($scope['plan_locale'] ?? ($scope['plan_workbench']['plan_locale'] ?? '')));
        $defaultLocale = \trim((string)($scope['default_locale'] ?? ''));
        $pageCoverage = \is_array($scope['page_coverage'] ?? null) ? $scope['page_coverage'] : [];
        $pageType = $batch['type'] === 'page' ? (string)$batch['key'] : '';

        $userBriefBatch = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ($scope['plan_workbench']['stage1']['request_summary']['raw_requirement'] ?? '')));
        $oneLineRequirementBatch = $userBriefBatch !== '' ? $userBriefBatch : ($instruction !== '' ? $instruction : '-');

        $batchContext = $this->buildTaskPlanBatchPromptContext($scope, $structured, $batch, $pageType);
        $lines = [
            'You are PageBuilder AI planner for stage-2 virtual theme task planning of a real website (batched call).',
            'PRIMARY GOAL: For this ONE batch, expand the confirmed stage-1 theme and block plan into one or more CONCRETE EXECUTABLE frontend component tasks.',
            '中文要求：本批次只生成当前 batch 的 task。它是阶段二任务方案，不是阶段一方案复述；每条任务必须携带阶段一主题信息、页面归属、块归属、字段规划、技术实现细节、资源需求和完成规则。',
            '【用户一句话需求】(authoritative): ' . $oneLineRequirementBatch,
            'This is a batched stage-2 planning call. The server will assemble the final full plan.',
            'Return STRICT JSON only.',
            'Do not wrap the response in markdown fences.',
            'Do not output explanations, comments, code fences, markdown, or any text outside JSON.',
            'The first non-whitespace character in the response must be { and the last non-whitespace character must be }.',
            'Escape any line break inside JSON strings as \\n.',
            'Do not echo the schema example, GOOD/BAD examples, or these instructions back in the response.',
            'Do not copy or summarize any large stage-1 context, baseline snapshot, execution blueprint, theme snapshot, or prompt section into the output.',
            'Output only the requested batch payload: shared_tasks OR page_tasks plus optional risk_notes.',
            'If mode/instruction contains queue:run, [FORCE], or forced rebuild text, treat it only as an operator command. Never copy it into story_goal, SEO, source_instruction, content_strategy, or customer-facing fields.',
            'Batch type: ' . $batch['type'],
            'Batch key: ' . $batch['key'],
            'Mode: ' . $mode,
            'Task keys in this batch: ' . \implode(', ', $batch['task_keys']),
            'Fanout group: ' . (string)($batch['fanout_group'] ?? ''),
            'Queue job key: ' . (string)($batch['queue_job_key'] ?? ''),
            'Block key: ' . (string)($batch['block_key'] ?? ''),
            'Dependencies preserved from stage-1 task tree: ' . \implode(', ', \is_array($batch['depends_on'] ?? null) ? $batch['depends_on'] : []),
            'Only return tasks that belong to this batch.',
            'Preserve the provided task order.',
            'Treat this as a customer-visible implementation plan: every text field must read like final task content, not prompt guidance.',
            'Compact context for this batch only:',
            \json_encode($batchContext, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}',
        ];
        \array_push($lines, ...$this->buildFrontendDesignSkillPromptGuide($batch));
        if ($instruction !== '') {
            $lines[] = 'User instruction: ' . $instruction;
        }
        if ($targetScope !== '') {
            $lines[] = 'Target scope: ' . $targetScope;
        }
        if ($mode === 'refine_task_plan') {
            $lines[] = 'Refine mode: only update details in this batch that are relevant to target_scope and linked task dependencies.';
        } elseif ($mode === 'rebuild_task_plan') {
            $lines[] = 'Rebuild mode: rebuild this batch from confirmed stage-1 context, but still return only this batch payload.';
        }

        if ($batch['type'] === 'shared') {
            $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
            $sharedTasks = $this->filterTaskPlanTaskListForBatch($sharedTasks, $batch);
            $lines[] = 'Output schema: {"shared_tasks":[...],"risk_notes":["string"]}';
            $lines[] = 'Do not output page_tasks or a full virtual_theme_plan wrapper.';
            $lines[] = 'For shared batch, generate only shared header/footer task details. Header and footer belong to group_key "shared" and are designed for parallel generation before page blocks.';
            $lines[] = 'Shared task skeleton:';
            $lines[] = \json_encode($this->compactTaskPlanPromptTasks($sharedTasks), \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '[]';
            $lines[] = 'Compact shared JSON example to imitate (shape only; replace values with this batch context):';
            $lines[] = \json_encode($this->buildSharedTaskPlanPromptExample($batch), \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
            $lines[] = 'Each returned shared_tasks[] item MUST include planning_reason explaining why this shared block structure, fields, navigation/link choices, style rules, and responsive behavior fit the confirmed stage-1 shared cues.';
            $lines[] = 'Use the matching stage-1 shared cue reason/implementation_detail for shared_tasks[].planning_reason; never leave planning_reason blank or replace it with a generic shared-component rationale.';
        } else {
            $pageTasks = \is_array($structured['page_tasks'][$pageType] ?? null) ? $structured['page_tasks'][$pageType] : [];
            $pageTasks = $this->filterTaskPlanTaskListForBatch($pageTasks, $batch);
            $lines[] = 'Page type: ' . $pageType;
            $lines[] = 'Output schema: {"page_type":"' . $pageType . '","page_tasks":[...],"risk_notes":["string"]}';
            $lines[] = 'Do not output shared_tasks or a full virtual_theme_plan wrapper.';
            $lines[] = 'For page batch, generate only tasks for page_type "' . $pageType . '". The task tree must make page ownership and block ownership clear: page_type, page_key, block_key, section_code, sort_order, dependencies.';
            $lines[] = 'Page task skeleton:';
            $lines[] = \json_encode($this->compactTaskPlanPromptTasks($pageTasks), \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '[]';
            $lines[] = 'Compact page JSON example to imitate (shape only; replace values with this batch context):';
            $lines[] = \json_encode($this->buildPageTaskPlanPromptExample($pageType, $batch), \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
            $lines[] = 'Each stage-2 block task MUST ground task_goal/content_plan/style_plan/planning_reason in its stage-1 cue fields: block_goal, realtime_content, style_direction, reason.';
        }

        if ($planLocale !== '') {
            $lines[] = 'Plan locale: ' . $planLocale;
        }
        if ($defaultLocale !== '') {
            $lines[] = 'Default locale: ' . $defaultLocale;
        }

        $lines[] = 'Filtered build_blueprint tasks for this batch:';
        $lines[] = \json_encode($this->filterBuildBlueprintForTaskKeys($buildBlueprint, $batch['task_keys']), \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Hard rules:';
        $lines[] = '- This is stage 2: convert stage-1 page blocks into executable frontend-component tasks. Do not create a new marketing plan.';
        $lines[] = '- The final plan is assembled by shared group + page groups. Shared group contains header/footer and is parallelizable. Each page_type group contains its own block tasks and is parallelizable after shared dependencies.';
        $lines[] = '- Every task must identify group_key, page_type, page_key, block_key, section_code, sort_order, dependencies, fanout_group, queue_job_key, status="done", and attempt_no.';
        $lines[] = '- Every task must include plan_context with stage1_theme_summary, stage1_block_goal, stage1_block_content, stage1_style_direction, source_page_type, source_block_key, and source_context_hash where available.';
        $lines[] = '- Every task_script must include component_type, technical_steps, data_contract, state_contract, responsive_contract, accessibility_contract, asset_requirements, validation_points, and completion_rule.';
        $lines[] = '- asset_requirements must combine stage-1 block description and theme description: images/icons/backgrounds/textures/brand assets/data fields. Use concrete descriptions and avoid vague placeholders.';
        $lines[] = '- Every returned shared_tasks[] item must include planning_reason that explains why the shared block, field defaults, navigation/link grouping, style, and responsive plan follow the confirmed stage-1 shared cues.';
        $lines[] = '- Every returned page_tasks[] entry must include block_task with required fields: task_goal, meta_fields, content_plan, style_plan, planning_reason, sort_order.';
        $lines[] = '- block_task.task_goal is the visible block outcome; block_task.meta_fields is the exact editable field list; block_task.content_plan and block_task.style_plan are concrete arrays; block_task.planning_reason explains the stage-1 rationale; block_task.sort_order mirrors the task sort_order.';
        $lines[] = '- For page block tasks, read the matching Relevant stage-1 page cues entry and explicitly use block_goal for task_goal, realtime_content for content_plan examples, design_tags/style_direction for style_plan, and reason for planning_reason.';
        $lines[] = '- Never use task_key, page_type, section_code, block_key, component paths, or internal IDs as customer-visible copy.';
        $lines[] = '- Visible-language rule: customer-visible copy, field samples, CTA labels, link labels, and alt text must use content_locale/default_locale, which is the website requirement language. Do NOT use plan_locale as the website content language unless it is identical to default_locale. Keep internal IDs in task_key/section_code only.';
        $lines[] = '- block_task.style_plan must preserve design_tags exactly enough for stage 3: visual tags, motion tags, interaction tags, texture tags, responsive tags, and implementation_note must appear in the style_plan values.';
        $lines[] = '- Every planning_reason must be concrete and traceable to stage-1 reason/implementation_detail; generic wording such as "needed for the page" is invalid.';
        $lines[] = '- block_task.style_plan MUST include concrete color, font, spacing, and responsive keys. Each key must be directly usable by stage 3: color names palette/hex usage, font names family/weight/scale, spacing names section padding/gap/radius rhythm, responsive names desktop/mobile behavior.';
        $lines[] = '- Every returned task must include plan_context, implementation_contract, task_script, field_content_requirements, result_ref, and completion_rule-compatible detail.';
        $lines[] = '- Keep task_key, group_key, page_type, and sort_order compatible with the provided skeleton.';
        $lines[] = '- Do not drop any task from this batch.';
        $lines[] = '- This batch is one stage2.block_task_plan queue job when Fanout group is stage2.block_task_plan; generate every task listed in task_keys and preserve dependencies/sort_order.';
        $lines[] = '- Do not add unselected pages or tasks outside this batch.';
        $lines[] = '- risk_notes should mention only issues relevant to this batch.';
        $lines[] = '- story_goal, content_fill_rule, and every field sample must be direct implementation content; never write blueprint guidance such as "围绕...说明" or "阶段一仅给方向".';
        $lines[] = '- story_goal MUST describe a visible on-page outcome ("访客读到/看到 ___"), NOT a writing instruction ("撰写文案说明 ___").';
        $lines[] = '- content_fill_rule MUST enumerate fields and provide at least one concrete example value per critical field (heading/subheading/CTA/proof point).';
        $lines[] = '- Task details must be inspectable in UI: put enough detail in task_script, field_content_requirements, block_task.content_plan, block_task.style_plan, and implementation_contract for a user to open a hero block and see exactly what will be built.';
        $lines[] = '- Every field_content_requirements[].sample is final or "[假设]" + concrete copy (Chinese >=6 chars, English >=3 words). Forbidden: "待补充", "突出卖点", "详见后文", "围绕主题展开".';
        $lines[] = '- Every nav/link entry must have a real label and href (or page_type); "nav TBD"/"链接1" are invalid.';
        $lines[] = '- Final audit (silently before output): drop or rewrite any task that fails the above checks.';

        return \implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function buildSharedTaskPlanPromptExample(array $batch): array
    {
        $taskKey = (string)(\is_array($batch['task_keys'] ?? null) && ($batch['task_keys'][0] ?? '') !== '' ? $batch['task_keys'][0] : 'shared:header');
        $blockKey = (string)($batch['block_key'] ?? 'shared');

        return [
            'shared_tasks' => [
                [
                    'task_key' => $taskKey,
                    'group_key' => 'shared',
                    'page_type' => '',
                    'page_key' => '',
                    'block_key' => $blockKey,
                    'section_code' => '',
                    'label' => 'Header or Footer',
                    'sort_order' => 10,
                    'dependencies' => [],
                    'status' => 'done',
                    'attempt_no' => 1,
                    'plan_context' => [
                        'stage1_theme_summary' => 'Use the confirmed brand tone, palette, and navigation intent.',
                        'stage1_block_goal' => 'Reusable site navigation or footer trust area.',
                        'stage1_block_content' => 'Concrete labels, links, contact/policy fields.',
                        'stage1_style_direction' => 'Match confirmed typography, color, spacing, and responsive behavior.',
                        'source_page_type' => '',
                        'source_block_key' => $blockKey,
                    ],
                    'task_script' => [
                        'component_type' => 'shared_header_or_footer',
                        'story_goal' => 'Visitors can navigate core pages and find the primary action immediately.',
                        'content_fill_rule' => 'Use real labels such as Home, Games, About, Contact, and a concrete CTA.',
                        'technical_steps' => ['Build semantic nav/footer structure', 'Bind editable link fields', 'Add mobile collapse behavior'],
                        'data_contract' => ['brand_name' => 'string', 'nav_items' => 'array<label,href>', 'primary_cta' => 'object'],
                        'state_contract' => ['mobile_menu_open' => 'boolean'],
                        'responsive_contract' => ['desktop' => 'horizontal nav', 'mobile' => 'collapsed menu'],
                        'accessibility_contract' => ['keyboard focus visible', 'aria label for menu button'],
                        'asset_requirements' => ['brand logo or text mark', 'optional small trust icons'],
                        'validation_points' => ['all links have labels and hrefs', 'mobile menu opens and closes'],
                        'completion_rule' => 'Shared component renders with real content and works on desktop/mobile.',
                    ],
                    'field_content_requirements' => [
                        ['field' => 'brand_name', 'sample' => 'Royal Indian Games', 'reason' => 'brand identity'],
                        ['field' => 'primary_cta.label', 'sample' => 'Play Now', 'reason' => 'primary conversion action'],
                    ],
                    'implementation_contract' => [
                        'acceptance' => ['No empty nav labels', 'Responsive menu works', 'CTA link is editable'],
                    ],
                    'planning_reason' => 'The shared component anchors every page and must be generated before page blocks.',
                    'result_ref' => ['target' => 'shared_component'],
                ],
            ],
            'risk_notes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function buildPageTaskPlanPromptExample(string $pageType, array $batch): array
    {
        $taskKey = (string)(\is_array($batch['task_keys'] ?? null) && ($batch['task_keys'][0] ?? '') !== '' ? $batch['task_keys'][0] : ('page:' . $pageType . ':hero'));
        $blockKey = (string)($batch['block_key'] ?? 'hero');

        return [
            'page_type' => $pageType !== '' ? $pageType : 'home_page',
            'page_tasks' => [
                [
                    'task_key' => $taskKey,
                    'group_key' => $pageType !== '' ? $pageType : 'home_page',
                    'page_type' => $pageType !== '' ? $pageType : 'home_page',
                    'page_key' => $pageType !== '' ? $pageType : 'home_page',
                    'block_key' => $blockKey,
                    'section_code' => 'content/example-hero',
                    'label' => 'Hero',
                    'sort_order' => 100,
                    'dependencies' => ['shared:header'],
                    'status' => 'done',
                    'attempt_no' => 1,
                    'plan_context' => [
                        'stage1_theme_summary' => 'Premium gaming brand using dark background and ember accents.',
                        'stage1_block_goal' => 'Explain the page value and drive the primary CTA.',
                        'stage1_block_content' => 'Headline, supporting copy, CTA, and visual slot from stage one.',
                        'stage1_style_direction' => 'Large headline, high contrast CTA, mobile stacked layout.',
                        'source_page_type' => $pageType !== '' ? $pageType : 'home_page',
                        'source_block_key' => $blockKey,
                    ],
                    'task_script' => [
                        'component_type' => 'hero_section',
                        'story_goal' => 'Visitors understand the offer in five seconds and click Play Now.',
                        'content_fill_rule' => 'Use concrete headline, subheading, CTA label, CTA href, and visual alt text.',
                        'technical_steps' => ['Build hero layout', 'Map editable fields', 'Apply responsive breakpoints'],
                        'data_contract' => ['title' => 'string', 'description' => 'string', 'cta' => 'object', 'image' => 'object'],
                        'state_contract' => ['image_loaded' => 'boolean'],
                        'responsive_contract' => ['desktop' => 'two columns', 'mobile' => 'single column'],
                        'accessibility_contract' => ['CTA has accessible name', 'image has alt text'],
                        'asset_requirements' => ['hero image matching stage-one theme', 'optional game icon set'],
                        'validation_points' => ['no placeholder copy', 'CTA href is valid', 'mobile layout is readable'],
                        'completion_rule' => 'Block renders with concrete copy, assets, CTA, and responsive styling.',
                    ],
                    'block_task' => [
                        'schema_version' => self::BLOCK_TASK_SCHEMA_VERSION,
                        'task_goal' => 'Explain value and drive primary action.',
                        'meta_fields' => [
                            ['field' => 'title', 'type' => 'string', 'default' => 'Royal Indian Games', 'sample' => 'Royal Indian Games'],
                            ['field' => 'cta.label', 'type' => 'string', 'default' => 'Play Now', 'sample' => 'Play Now'],
                        ],
                        'content_plan' => [
                            'content_copy' => [['field' => 'title', 'copy' => 'Royal Indian Games']],
                            'cta_plan' => [['label' => 'Play Now', 'href' => '/play']],
                            'asset_plan' => [['slot' => 'hero_image', 'description' => 'Indian card game visual']],
                        ],
                        'style_plan' => [
                            'color' => 'dark background with ember CTA',
                            'font' => 'bold display heading, readable body',
                            'spacing' => 'generous hero padding and CTA gap',
                            'responsive' => 'desktop two-column, mobile stacked',
                        ],
                        'planning_reason' => 'Stage-one hero block requires immediate value communication and conversion.',
                        'sort_order' => 100,
                    ],
                    'field_content_requirements' => [
                        ['field' => 'title', 'sample' => 'Royal Indian Games', 'reason' => 'visible headline'],
                        ['field' => 'cta.label', 'sample' => 'Play Now', 'reason' => 'primary action'],
                    ],
                    'implementation_contract' => [
                        'acceptance' => ['fields are editable', 'style follows theme tokens', 'CTA works'],
                    ],
                    'result_ref' => ['target' => 'page_block'],
                ],
            ],
            'risk_notes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function buildTaskPlanBatchPromptContext(array $scope, array $structured, array $batch, string $pageType): array
    {
        $taskKeys = \array_values(\array_filter(\array_map('strval', \is_array($batch['task_keys'] ?? null) ? $batch['task_keys'] : [])));
        $taskKeyMap = \array_fill_keys($taskKeys, true);
        $stage1TaskCues = \is_array($structured['stage1_task_cues'] ?? null) ? $structured['stage1_task_cues'] : [];
        $styleTokens = \is_array($structured['style_tokens'] ?? null) ? $structured['style_tokens'] : [];
        $contentRules = \is_array($structured['content_rules'] ?? null) ? $structured['content_rules'] : [];

        $relevantCues = [];
        $cueBucket = ($batch['type'] ?? '') === 'shared'
            ? (\is_array($stage1TaskCues['shared'] ?? null) ? $stage1TaskCues['shared'] : [])
            : (\is_array($stage1TaskCues['pages'] ?? null) ? $stage1TaskCues['pages'] : []);
        foreach ($cueBucket as $taskKey => $cue) {
            if (!isset($taskKeyMap[(string)$taskKey]) || !\is_array($cue)) {
                continue;
            }
            $relevantCues[(string)$taskKey] = $this->compactStageTwoCueForPrompt($cue);
        }

        return [
            'site' => [
                'title' => (string)($scope['site_title'] ?? ''),
                'tagline' => (string)($scope['site_tagline'] ?? ''),
                'brief' => $this->excerptText((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''), 480),
                'plan_locale' => (string)($scope['plan_locale'] ?? ''),
                'default_locale' => (string)($scope['default_locale'] ?? ''),
                'content_locale' => $this->resolveStageTwoContentLocale($scope),
            ],
            'theme' => [
                'palette' => \is_array($styleTokens['palette'] ?? null) ? $this->compactPromptMap($styleTokens['palette'], 8, 180) : [],
                'theme_style' => \is_array($styleTokens['theme_style'] ?? null) ? $this->compactPromptMap($styleTokens['theme_style'], 8, 180) : [],
                'seo_strategy' => \is_array($contentRules['seo_strategy'] ?? null) ? $this->compactPromptMap($contentRules['seo_strategy'], 6, 180) : [],
                'navigation_plan' => \is_array($contentRules['navigation_plan'] ?? null) ? $this->compactPromptMap($contentRules['navigation_plan'], 6, 180) : [],
                'footer_plan' => \is_array($contentRules['footer_plan'] ?? null) ? $this->compactPromptMap($contentRules['footer_plan'], 6, 180) : [],
            ],
            'batch' => [
                'type' => (string)($batch['type'] ?? ''),
                'key' => (string)($batch['key'] ?? ''),
                'page_type' => $pageType,
                'block_key' => (string)($batch['block_key'] ?? ''),
                'task_keys' => $taskKeys,
                'depends_on' => \array_values(\array_filter(\array_map('strval', \is_array($batch['depends_on'] ?? null) ? $batch['depends_on'] : []))),
                'fanout_group' => (string)($batch['fanout_group'] ?? ''),
                'queue_job_key' => (string)($batch['queue_job_key'] ?? ''),
            ],
            'stage1_cues' => $relevantCues,
        ];
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function compactTaskPlanPromptTasks(array $tasks): array
    {
        $compact = [];
        foreach ($tasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
            $runtime = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
            $compact[] = [
                'task_key' => (string)($task['task_key'] ?? ''),
                'group_key' => (string)($task['group_key'] ?? ''),
                'page_type' => (string)($task['page_type'] ?? ''),
                'page_key' => (string)($task['page_key'] ?? ($task['page_type'] ?? '')),
                'block_key' => (string)($task['block_key'] ?? ($planContext['block_code'] ?? '')),
                'section_code' => (string)($task['section_code'] ?? ($planContext['section_code'] ?? '')),
                'label' => (string)($task['label'] ?? ''),
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                'plan_context' => $this->compactPromptMap($planContext, 12, 220),
                'runtime_context' => [
                    'fanout_group' => (string)($runtime['fanout_group'] ?? ''),
                    'fanout_job_key' => (string)($runtime['fanout_job_key'] ?? ''),
                    'stage2_context_hash' => (string)($runtime['stage2_context_hash'] ?? ''),
                ],
            ];
        }

        return $compact;
    }

    /**
     * @param array<string, mixed> $cue
     * @return array<string, mixed>
     */
    private function compactStageTwoCueForPrompt(array $cue): array
    {
        return [
            'task_key' => (string)($cue['task_key'] ?? ''),
            'page_type' => (string)($cue['page_type'] ?? ''),
            'block_key' => (string)($cue['block_key'] ?? $cue['source_block_key'] ?? ''),
            'section_code' => (string)($cue['section_code'] ?? $cue['component_kind'] ?? ''),
            'block_goal' => $this->excerptText((string)($cue['block_goal'] ?? $cue['stage1_goal'] ?? $cue['goal'] ?? ''), 260),
            'realtime_content' => \is_array($cue['realtime_content'] ?? null) ? $this->compactPromptMap($cue['realtime_content'], 10, 180) : [],
            'style_direction' => $this->excerptText((string)($cue['style_direction'] ?? ''), 260),
            'design_tags' => \is_array($cue['design_tags'] ?? null) ? $this->compactPromptMap($cue['design_tags'], 8, 140) : [],
            'reason' => $this->excerptText((string)($cue['reason'] ?? $cue['implementation_detail'] ?? ''), 320),
            'editable_fields' => \array_values(\array_slice(\array_filter(\array_map('strval', \is_array($cue['editable_fields'] ?? null) ? $cue['editable_fields'] : [])), 0, 12)),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function compactPromptMap(array $source, int $maxItems = 8, int $maxText = 180): array
    {
        $out = [];
        $count = 0;
        foreach ($source as $key => $value) {
            if ($count >= $maxItems) {
                break;
            }
            $key = (string)$key;
            if (\is_array($value)) {
                $out[$key] = $this->compactPromptListOrMap($value, $maxItems, $maxText);
            } else {
                $out[$key] = $this->excerptText((string)$value, $maxText);
            }
            $count++;
        }

        return $out;
    }

    /**
     * @param array<mixed> $source
     * @return array<mixed>
     */
    private function compactPromptListOrMap(array $source, int $maxItems, int $maxText): array
    {
        $out = [];
        $count = 0;
        foreach ($source as $key => $value) {
            if ($count >= $maxItems) {
                break;
            }
            if (\is_array($value)) {
                $next = [];
                $innerCount = 0;
                foreach ($value as $innerKey => $innerValue) {
                    if ($innerCount >= $maxItems) {
                        break;
                    }
                    $next[$innerKey] = \is_array($innerValue)
                        ? '[nested]'
                        : $this->excerptText((string)$innerValue, $maxText);
                    $innerCount++;
                }
                $out[$key] = $next;
            } else {
                $out[$key] = $this->excerptText((string)$value, $maxText);
            }
            $count++;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $batch
     * @return list<string>
     */
    private function buildFrontendDesignSkillPromptGuide(array $batch): array
    {
        $batchType = (string)($batch['type'] ?? '');
        $componentScope = $batchType === 'shared'
            ? 'shared theme component such as header/footer'
            : 'page-owned theme block component';

        return [
            '',
            'Frontend design skill reference (mandatory for every generated theme component task):',
            '- Local skill file: ' . self::FRONTEND_DESIGN_SKILL_LOCAL_PATH,
            '- Source skill: ' . self::FRONTEND_DESIGN_SKILL_SOURCE,
            '- Scope for this batch: ' . $componentScope,
            '- Apply frontend-design skill before writing task_script/style_plan: pick a clear aesthetic direction that matches the site purpose, audience, page role, and block goal.',
            '- Avoid generic AI aesthetics: no default Inter/Roboto/Arial/system-font look, no timid purple-gradient-on-white templates, no cookie-cutter card grids, no interchangeable SaaS hero patterns unless stage-1 explicitly demands that visual language.',
            '- Make each component memorable through a deliberate typography, color, spatial composition, motion, texture, and visual-detail decision that can be implemented by stage 3.',
            '- Match complexity to the chosen aesthetic: refined/minimal components need precise spacing, type scale, and restraint; expressive/maximal components need purposeful layering, motion, and atmosphere.',
            '- For each returned task, encode the skill outcome in block_task.style_plan and task_script.responsive_contract/accessibility_contract/asset_requirements; do not merely mention this skill in prose.',
            '- style_plan must include concrete typography, color/theme, motion, spatial composition, background/texture/detail, responsive behavior, and accessibility notes when relevant to the component.',
            '- Shared components must keep the same aesthetic system while adapting interaction density: header navigation clarity, footer trust/compliance structure, and mobile ergonomics are mandatory.',
            '- Page block components must translate stage-1 block_goal/realtime_content/style_direction into visible frontend decisions, not into instructions for a future designer.',
            '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTaskPlanStageOneCompactContext(array $scope, string $pageType = ''): array
    {
        $planWorkbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
        $confirmedWorkbench = \is_array($planWorkbench['confirmed'] ?? null) ? $planWorkbench['confirmed'] : [];
        $confirmedPlanBook = $this->resolveConfirmedStageOnePlanBook($scope);
        $planStructured = \is_array($confirmedWorkbench['structured_plan'] ?? null)
            ? $confirmedWorkbench['structured_plan']
            : (\is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : []);
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];

        $summary = [
            'site_title' => (string)($scope['site_title'] ?? ''),
            'site_tagline' => (string)($scope['site_tagline'] ?? ''),
            'brief_description' => (string)($scope['brief_description'] ?? ''),
            'plan_locale' => (string)($scope['plan_locale'] ?? ''),
            'default_locale' => (string)($scope['default_locale'] ?? ''),
            'page_types' => \array_values(\array_map('strval', \is_array($executionBlueprint['page_types'] ?? null) ? $executionBlueprint['page_types'] : [])),
            'confirmed_signature' => (string)($scope['execution_blueprint_confirmed_signature'] ?? ''),
            'theme_context_snapshot' => \is_array($planStructured['theme_context_snapshot'] ?? null)
                ? $planStructured['theme_context_snapshot']
                : (\is_array($planWorkbench['stage1']['theme_context_snapshot'] ?? null) ? $planWorkbench['stage1']['theme_context_snapshot'] : []),
            'site_strategy' => \is_array($planStructured['site_strategy'] ?? null) ? $planStructured['site_strategy'] : [],
            'palette' => \is_array($planStructured['palette'] ?? null) ? $planStructured['palette'] : [],
            'theme_style' => \is_array($planStructured['theme_style'] ?? null) ? $planStructured['theme_style'] : [],
            'seo_strategy' => \is_array($planStructured['seo_strategy'] ?? null) ? $planStructured['seo_strategy'] : [],
            'navigation_plan' => \is_array($planStructured['navigation_plan'] ?? null) ? $planStructured['navigation_plan'] : [],
            'footer_plan' => \is_array($planStructured['footer_plan'] ?? null) ? $planStructured['footer_plan'] : [],
            'shared_prompt_context' => \is_array($planWorkbench['confirmed']['shared_prompt_context'] ?? null)
                ? $planWorkbench['confirmed']['shared_prompt_context']
                : [],
            'confirmed_block_tree_source' => $confirmedPlanBook !== [] ? 'plan_workbench.confirmed.plan_book.structured' : '',
            'confirmed_plan_book_context_hash' => (string)($confirmedPlanBook['context_hash'] ?? ''),
            'confirmed_plan_book' => $this->compactConfirmedPlanBookForPrompt($confirmedPlanBook, $pageType),
            'plan_markdown_excerpt' => $confirmedPlanBook === [] ? $this->excerptText((string)($scope['plan_markdown'] ?? ''), 1200) : '',
        ];

        if ($pageType !== '' && $pageType !== '__shared__') {
            $summary['execution_blueprint_page'] = \is_array($executionBlueprint['pages'][$pageType] ?? null)
                ? $executionBlueprint['pages'][$pageType]
                : [];
        }

        return $summary;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @param array<string, mixed> $batch
     * @return list<array<string, mixed>>
     */
    private function filterTaskPlanTaskListForBatch(array $tasks, array $batch): array
    {
        $taskKeys = \array_values(\array_filter(\array_map('strval', \is_array($batch['task_keys'] ?? null) ? $batch['task_keys'] : [])));
        if ($taskKeys === []) {
            return \array_values(\array_filter($tasks, static fn($task): bool => \is_array($task)));
        }

        $taskKeyMap = \array_fill_keys($taskKeys, true);
        return \array_values(\array_filter($tasks, static function ($task) use ($taskKeyMap): bool {
            return \is_array($task) && isset($taskKeyMap[(string)($task['task_key'] ?? '')]);
        }));
    }

    /**
     * @param array{type:string,key:string,task_keys:list<string>} $batch
     * @return array<string, mixed>
     */
    private function filterVirtualThemePlanForBatch(array $virtualThemePlan, array $batch): array
    {
        $snapshot = [
            'signature' => (string)($virtualThemePlan['signature'] ?? ''),
            'plan_signature' => (string)($virtualThemePlan['plan_signature'] ?? ''),
            'virtual_theme_strategy' => \is_array($virtualThemePlan['virtual_theme_strategy'] ?? null) ? $virtualThemePlan['virtual_theme_strategy'] : [],
            'task_script_brief' => \is_array($virtualThemePlan['task_script_brief'] ?? null) ? $virtualThemePlan['task_script_brief'] : [],
            'risk_notes' => \array_values(\is_array($virtualThemePlan['risk_notes'] ?? null) ? $virtualThemePlan['risk_notes'] : []),
        ];

        if (($batch['type'] ?? '') === 'shared') {
            $snapshot['shared_tasks'] = \array_values(\is_array($virtualThemePlan['shared_tasks'] ?? null) ? $virtualThemePlan['shared_tasks'] : []);
            return $snapshot;
        }

        $pageType = (string)($batch['key'] ?? '');
        $snapshot['page_type'] = $pageType;
        $snapshot['page_tasks'] = $this->filterTaskPlanTaskListForBatch(
            \is_array($virtualThemePlan['page_tasks'][$pageType] ?? null) ? $virtualThemePlan['page_tasks'][$pageType] : [],
            $batch
        );
        $snapshot['shared_task_summary'] = \array_map(
            static fn(array $task): array => [
                'task_key' => (string)($task['task_key'] ?? ''),
                'label' => (string)($task['label'] ?? ''),
                'sort_order' => (int)($task['sort_order'] ?? 0),
            ],
            \array_values(\is_array($virtualThemePlan['shared_tasks'] ?? null) ? $virtualThemePlan['shared_tasks'] : [])
        );

        return $snapshot;
    }

    private function excerptText(string $text, int $maxLength): string
    {
        $text = \trim($text);
        if ($text === '') {
            return '';
        }

        if (\function_exists('mb_strlen') && \function_exists('mb_substr')) {
            if (\mb_strlen($text) <= $maxLength) {
                return $text;
            }

            return \rtrim(\mb_substr($text, 0, $maxLength)) . '...';
        }

        if (\strlen($text) <= $maxLength) {
            return $text;
        }

        return \rtrim(\substr($text, 0, $maxLength)) . '...';
    }

    /**
     * @param list<string> $taskKeys
     * @return array<string, mixed>
     */
    private function filterBuildBlueprintForTaskKeys(array $buildBlueprint, array $taskKeys): array
    {
        $taskKeyMap = \array_fill_keys($taskKeys, true);
        $tasks = \is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [];
        $filteredTasks = [];
        foreach ($tasks as $task) {
            if (!\is_array($task) || !isset($taskKeyMap[(string)($task['task_key'] ?? '')])) {
                continue;
            }
            $filteredTasks[] = [
                'task_key' => (string)($task['task_key'] ?? ''),
                'group_key' => (string)($task['group_key'] ?? ''),
                'page_type' => (string)($task['page_type'] ?? ''),
                'label' => (string)($task['label'] ?? ''),
                'section_code' => (string)($task['section_code'] ?? ''),
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
            ];
        }

        return [
            'tasks' => $filteredTasks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestTaskPlanJsonFromAi(
        array $scope,
        string $prompt,
        int $maxTokens,
        ?callable $chunkCallback = null,
        ?callable $heartbeatCallback = null
    ): array {
        $ai = $this->getAiService();
        if ($ai === null) {
            throw new \RuntimeException('AI task plan generation failed: AiService unavailable.');
        }

        $publicId = \trim((string)($scope['public_id'] ?? ''));
        $requestParams = [
            'allow_zero_balance_provider' => true,
            'temperature' => 0.2,
            'max_tokens' => \min(8192, \max(512, $maxTokens)),
            'timeout' => 0,
            'disable_ai_timeout' => true,
            'disable_cli_timeout' => true,
            'session_id' => $publicId,
            'disable_conversation_history' => true,
            'disable_conversation_persist' => true,
        ];
        $jsonRequestParams = \array_merge($requestParams, [
            'response_format' => ['type' => 'json_object'],
        ]);

        if ($chunkCallback === null && $heartbeatCallback === null) {
            $raw = (string)$ai->generate(
                $prompt,
                null,
                'pagebuilder_task_plan_generation',
                null,
                $jsonRequestParams
            );
            $decoded = $this->decodeJsonObjectWithStringControlCharFix($raw);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('AI task plan generation failed: invalid JSON response.');
            }

            return $decoded;
        }

        $raw = '';
        $decoded = null;
        $streamThrowable = null;
        $streamChunkCount = 0;
        $streamCallback = function (string $chunk) use (&$raw, $chunkCallback, &$streamChunkCount): void {
            $raw .= $chunk;
            $streamChunkCount++;
            if ($chunkCallback !== null) {
                $chunkCallback($chunk);
            }
            if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent()) {
                SchedulerSystem::yieldDelay(1);
            }
        };

        $streamRequestParams = \array_merge($jsonRequestParams, [
            'enforce_timeout_in_stream' => false,
        ]);
        if ($heartbeatCallback !== null) {
            $streamRequestParams['on_heartbeat'] = $heartbeatCallback;
        }

        try {
            $ai->generateStream(
                $prompt,
                $streamCallback,
                null,
                'pagebuilder_task_plan_generation',
                null,
                $streamRequestParams
            );
            $decoded = $this->decodeJsonObjectWithStringControlCharFix($raw);
        } catch (\Throwable $throwable) {
            $streamThrowable = $throwable;
        }

        if (!\is_array($decoded)) {
            if ($chunkCallback !== null || $heartbeatCallback !== null) {
                $rawRetry = '';
                $retryCallback = static function (string $chunk) use (&$rawRetry, $chunkCallback): void {
                    $rawRetry .= $chunk;
                    if ($chunkCallback !== null) {
                        $chunkCallback($chunk);
                    }
                    if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent()) {
                        SchedulerSystem::yieldDelay(1);
                    }
                };
                try {
                    $retryRequestParams = $streamRequestParams;
                    $retryRequestParams['max_tokens'] = \min(
                        8192,
                        \max(
                            (int)($streamRequestParams['max_tokens'] ?? 0) * 2,
                            (int)($streamRequestParams['max_tokens'] ?? 0) + 1800
                        )
                    );
                    $ai->generateStream(
                        $prompt,
                        $retryCallback,
                        null,
                        'pagebuilder_task_plan_generation',
                        null,
                        $retryRequestParams
                    );
                    $decoded = $this->decodeJsonObjectWithStringControlCharFix($rawRetry);
                } catch (\Throwable $retryThrowable) {
                    if ($streamThrowable === null) {
                        $streamThrowable = $retryThrowable;
                    }
                }
            }
            if (!\is_array($decoded)) {
                $jsonRaw = (string)$ai->generate(
                    $prompt,
                    null,
                    'pagebuilder_task_plan_generation',
                    null,
                    $jsonRequestParams
                );
                $decoded = $this->decodeJsonObjectWithStringControlCharFix($jsonRaw);
            }
        }

        if (!\is_array($decoded)) {
            $primaryTail = (string)\mb_substr($raw, -220, null, 'UTF-8');
            $retryTail = isset($rawRetry) ? (string)\mb_substr((string)$rawRetry, -220, null, 'UTF-8') : '';
            $diagnostic = [
                'json_error' => \json_last_error_msg(),
                'primary_len' => \strlen($raw),
                'retry_len' => isset($rawRetry) ? \strlen((string)$rawRetry) : 0,
                'primary_tail' => $primaryTail,
                'retry_tail' => $retryTail,
            ];
            throw new \RuntimeException(
                'AI task plan generation failed: invalid JSON response. [debug='
                . (string)\json_encode($diagnostic, \JSON_UNESCAPED_UNICODE)
                . ']',
                0,
                $streamThrowable
            );
        }

        return $decoded;
    }

    private function decodeJsonObjectWithStringControlCharFix(string $raw): ?array
    {
        foreach ($this->buildJsonObjectCandidates($raw) as $candidate) {
            $decoded = $this->decodeJsonObjectCandidate($candidate);
            if (\is_array($decoded) && $this->looksLikeTaskPlanJsonPayload($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function buildJsonObjectCandidates(string $raw): array
    {
        $candidates = [];
        $appendCandidate = static function (?string $candidate) use (&$candidates): void {
            if ($candidate === null) {
                return;
            }

            $candidate = \trim($candidate);
            if ($candidate === '') {
                return;
            }

            foreach ($candidates as $existing) {
                if ($existing === $candidate) {
                    return;
                }
            }

            $candidates[] = $candidate;
        };

        $normalized = $this->normalizeJsonResponseText($raw);
        $appendCandidate($raw);
        $appendCandidate($normalized);

        foreach ([$raw, $normalized] as $source) {
            foreach ($this->extractBalancedJsonObjects($source) as $candidate) {
                $appendCandidate($candidate);
            }
        }

        \usort($candidates, static fn(string $left, string $right): int => \strlen($right) <=> \strlen($left));

        return $candidates;
    }

    private function normalizeJsonResponseText(string $raw): string
    {
        $text = \preg_replace('/^\x{FEFF}/u', '', $raw) ?? $raw;
        $text = \trim($text);
        if ($text === '') {
            return '';
        }

        if (\preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches) === 1) {
            $text = \trim((string)($matches[1] ?? ''));
        }

        if (\preg_match('/^json\s*(\{.*)$/is', $text, $matches) === 1) {
            $text = \trim((string)($matches[1] ?? ''));
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function extractBalancedJsonObjects(string $text, int $limit = 8): array
    {
        $candidates = [];
        $length = \strlen($text);
        if ($length === 0) {
            return $candidates;
        }

        for ($start = 0; $start < $length && \count($candidates) < $limit; $start++) {
            if ($text[$start] !== '{') {
                continue;
            }

            $end = $this->findBalancedJsonObjectEnd($text, $start);
            if ($end === null) {
                continue;
            }

            $candidates[] = \substr($text, $start, $end - $start + 1);
        }

        return $candidates;
    }

    private function findBalancedJsonObjectEnd(string $text, int $start): ?int
    {
        $length = \strlen($text);
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($index = $start; $index < $length; $index++) {
            $char = $text[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                $depth++;
                continue;
            }
            if ($char !== '}') {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private function decodeJsonObjectCandidate(string $raw): mixed
    {
        foreach ($this->buildJsonDecodeRepairCandidates($raw) as $candidate) {
            $decoded = \json_decode($candidate, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function buildJsonDecodeRepairCandidates(string $raw): array
    {
        $candidates = [];
        $append = static function (string $candidate) use (&$candidates): void {
            $candidate = \trim($candidate);
            if ($candidate === '') {
                return;
            }
            foreach ($candidates as $existing) {
                if ($existing === $candidate) {
                    return;
                }
            }
            $candidates[] = $candidate;
        };

        $append($raw);

        $fixedControl = $this->escapeControlCharsInsideJsonStrings($raw);
        $append($fixedControl);

        $append($this->removeTrailingCommasBeforeClosers($raw));
        if ($fixedControl !== $raw) {
            $append($this->removeTrailingCommasBeforeClosers($fixedControl));
        }

        $repairedTruncated = $this->repairLikelyTruncatedJsonObject($raw);
        $append($repairedTruncated);
        $append($this->removeTrailingCommasBeforeClosers($repairedTruncated));

        if ($fixedControl !== $raw) {
            $repairedFromFixed = $this->repairLikelyTruncatedJsonObject($fixedControl);
            $append($repairedFromFixed);
            $append($this->removeTrailingCommasBeforeClosers($repairedFromFixed));
        }

        return $candidates;
    }

    private function removeTrailingCommasBeforeClosers(string $raw): string
    {
        return (string)(\preg_replace('/,\s*([\}\]])/u', '$1', $raw) ?? $raw);
    }

    private function repairLikelyTruncatedJsonObject(string $raw): string
    {
        $json = \trim($raw);
        if ($json === '') {
            return $json;
        }
        $firstBrace = \strpos($json, '{');
        if ($firstBrace === false) {
            return $json;
        }
        if ($firstBrace > 0) {
            $json = \substr($json, $firstBrace);
        }

        $inString = false;
        $escaped = false;
        $braceDepth = 0;
        $bracketDepth = 0;
        $length = \strlen($json);

        for ($index = 0; $index < $length; $index++) {
            $char = $json[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                $braceDepth++;
                continue;
            }
            if ($char === '}') {
                $braceDepth = \max(0, $braceDepth - 1);
                continue;
            }
            if ($char === '[') {
                $bracketDepth++;
                continue;
            }
            if ($char === ']') {
                $bracketDepth = \max(0, $bracketDepth - 1);
            }
        }

        if ($inString) {
            $json .= '"';
        }
        if ($bracketDepth > 0) {
            $json .= \str_repeat(']', $bracketDepth);
        }
        if ($braceDepth > 0) {
            $json .= \str_repeat('}', $braceDepth);
        }

        return $json;
    }

    private function looksLikeTaskPlanJsonPayload(array $payload): bool
    {
        if ($this->taskPlanPayloadContainsTasks($payload)) {
            return true;
        }

        $virtualThemePlan = \is_array($payload['virtual_theme_plan'] ?? null) ? $payload['virtual_theme_plan'] : [];

        return $virtualThemePlan !== [] && $this->taskPlanPayloadContainsTasks($virtualThemePlan);
    }

    private function taskPlanPayloadContainsTasks(array $payload): bool
    {
        return $this->taskEntryCollectionHasItems($payload['shared_tasks'] ?? null)
            || $this->taskEntryCollectionHasItems($payload['page_tasks'] ?? null);
    }

    private function taskEntryCollectionHasItems(mixed $value): bool
    {
        if (!\is_array($value) || $value === []) {
            return false;
        }

        if ($this->taskEntryListHasItems($value)) {
            return true;
        }

        foreach ($value as $nested) {
            if ($this->taskEntryListHasItems($nested)) {
                return true;
            }
        }

        return false;
    }

    private function taskEntryListHasItems(mixed $value): bool
    {
        if (!\is_array($value) || $value === []) {
            return false;
        }

        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            if ((string)($entry['task_key'] ?? '') !== '') {
                return true;
            }
            if ((string)($entry['label'] ?? '') !== '') {
                return true;
            }
            if (\is_array($entry['task_script'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function escapeControlCharsInsideJsonStrings(string $raw): string
    {
        $len = \strlen($raw);
        if ($len === 0) {
            return $raw;
        }
        $out = '';
        $inString = false;
        $escaped = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($inString) {
                if ($escaped) {
                    $out .= $ch;
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $out .= $ch;
                    $escaped = true;
                    continue;
                }
                if ($ch === '"') {
                    $out .= $ch;
                    $inString = false;
                    continue;
                }
                $ord = \ord($ch);
                if ($ord <= 0x1F) {
                    if ($ch === "\n") {
                        $out .= '\\n';
                    } elseif ($ch === "\r") {
                        $out .= '\\r';
                    } elseif ($ch === "\t") {
                        $out .= '\\t';
                    } else {
                        $out .= '\\u' . \str_pad(\strtoupper(\dechex($ord)), 4, '0', STR_PAD_LEFT);
                    }
                    continue;
                }
                $out .= $ch;
                continue;
            }
            if ($ch === '"') {
                $inString = true;
            }
            $out .= $ch;
        }
        return $out;
    }

    /**
     * @param list<string> $riskNotes
     * @param array{type:string,key:string,task_keys:list<string>} $batch
     * @return array{structured:array<string,mixed>,virtual_theme_plan:array<string,mixed>,risk_notes:list<string>}
     */
    private function mergeTaskPlanGenerationBatch(
        array $structured,
        array $virtualThemePlan,
        array $riskNotes,
        array $batch,
        array $decoded
    ): array {
        $source = \is_array($decoded['virtual_theme_plan'] ?? null) ? $decoded['virtual_theme_plan'] : $decoded;
        $incomingRiskNotes = \is_array($source['risk_notes'] ?? null) ? $source['risk_notes'] : (\is_array($decoded['risk_notes'] ?? null) ? $decoded['risk_notes'] : []);

        if ($batch['type'] === 'shared') {
            $incomingTasks = \is_array($source['shared_tasks'] ?? null) ? $source['shared_tasks'] : [];
            $mergedTasks = $this->mergeTaskListByKey(
                \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [],
                $incomingTasks,
                'shared'
            );
            $structured['shared_tasks'] = $mergedTasks;
            $virtualThemePlan['shared_tasks'] = $mergedTasks;
        } else {
            $pageType = $batch['key'];
            $incomingPageTasks = \is_array($source['page_tasks'] ?? null) ? $source['page_tasks'] : [];
            if (\array_is_list($incomingPageTasks)) {
                $incomingTasks = $incomingPageTasks;
            } else {
                $incomingTasks = \is_array($incomingPageTasks[$pageType] ?? null) ? $incomingPageTasks[$pageType] : [];
            }
            $baselinePageTasks = \is_array($structured['page_tasks'][$pageType] ?? null) ? $structured['page_tasks'][$pageType] : [];
            $incomingTasks = $this->normalizeIncomingBlockTasksWithBaseline($incomingTasks, $baselinePageTasks, (string)$pageType);
            $this->assertIncomingBlockTasksHaveRequiredContract($incomingTasks, 'page:' . $pageType, $baselinePageTasks);
            $mergedTasks = $this->mergeTaskListByKey(
                $baselinePageTasks,
                $incomingTasks,
                'page:' . $pageType
            );
            $structured['page_tasks'][$pageType] = $mergedTasks;
            $virtualThemePlan['page_tasks'][$pageType] = $mergedTasks;
        }

        return [
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
            'risk_notes' => $this->mergeRiskNotes($riskNotes, $incomingRiskNotes),
        ];
    }

    /**
     * @param list<array<string, mixed>> $incomingTasks
     * @param list<array<string, mixed>> $baselineTasks
     * @return list<array<string, mixed>>
     */
    private function normalizeIncomingBlockTasksWithBaseline(array $incomingTasks, array $baselineTasks, string $pageType): array
    {
        $baselineByAlias = [];
        foreach ($baselineTasks as $baselineIndex => $baselineTask) {
            if (!\is_array($baselineTask)) {
                continue;
            }
            foreach ($this->buildStageTwoTaskMatchAliases($baselineTask, $pageType) as $alias) {
                $baselineByAlias[$alias] = $baselineTask;
            }
            $baselineByAlias['#' . $baselineIndex] ??= $baselineTask;
        }

        foreach ($incomingTasks as $idx => $incomingTask) {
            if (!\is_array($incomingTask)) {
                continue;
            }
            $baselineTask = [];
            foreach ($this->buildStageTwoTaskMatchAliases($incomingTask, $pageType) as $alias) {
                if (\is_array($baselineByAlias[$alias] ?? null)) {
                    $baselineTask = $baselineByAlias[$alias];
                    break;
                }
            }
            if ($baselineTask === [] && \is_array($baselineByAlias['#' . $idx] ?? null)) {
                $baselineTask = $baselineByAlias['#' . $idx];
            }

            $normalizedTask = \array_replace_recursive($baselineTask, $incomingTask);
            if ($baselineTask !== [] && \trim((string)($baselineTask['task_key'] ?? '')) !== '') {
                $normalizedTask['task_key'] = \trim((string)$baselineTask['task_key']);
            }

            $incomingTasks[$idx] = $this->applyBlockTaskSchemaToTask(
                $normalizedTask,
                $pageType
            );
        }

        return \array_values($incomingTasks);
    }

    /**
     * @param array<string, mixed> $task
     * @return list<string>
     */
    private function buildStageTwoTaskMatchAliases(array $task, string $pageType): array
    {
        $aliases = [];
        foreach ([
            $task['task_key'] ?? null,
            $task['section_code'] ?? null,
            $task['block_key'] ?? null,
            $task['plan_context']['section_code'] ?? null,
            $task['plan_context']['block_code'] ?? null,
            $task['plan_context']['source_block_key'] ?? null,
        ] as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $raw = \trim((string)$value);
            if ($raw === '') {
                continue;
            }
            foreach ($this->expandStageTwoTaskAlias($raw, $pageType) as $alias) {
                $aliases[] = $alias;
            }
        }

        return \array_values(\array_unique(\array_filter($aliases, static fn(string $alias): bool => $alias !== '')));
    }

    /**
     * @return list<string>
     */
    private function expandStageTwoTaskAlias(string $value, string $pageType): array
    {
        $value = \trim($value);
        if ($value === '') {
            return [];
        }

        $aliases = [$value];
        $tail = $value;
        if (\str_contains($tail, ':')) {
            $tail = (string)\substr($tail, (int)\strrpos($tail, ':') + 1);
            $aliases[] = $tail;
        }
        if (\str_starts_with($tail, 'content/')) {
            $withoutContent = \substr($tail, 8);
            $aliases[] = $withoutContent;
            $aliases[] = 'content/' . $withoutContent;
            if ($pageType !== '') {
                $pageSlug = $this->slugifyStageTwoTaskAlias($pageType);
                $short = \preg_replace('/^' . \preg_quote($pageSlug, '/') . '-/i', '', $withoutContent) ?? $withoutContent;
                $aliases[] = $short;
                $aliases[] = $pageType . ':' . $short;
                $aliases[] = 'page:' . $pageType . ':' . $short;
            }
        } elseif ($pageType !== '') {
            $aliases[] = 'page:' . $pageType . ':' . $tail;
            $aliases[] = $pageType . ':' . $tail;
            $pageSlug = $this->slugifyStageTwoTaskAlias($pageType);
            $aliases[] = 'content/' . $pageSlug . '-' . $tail;
            $aliases[] = 'page:' . $pageType . ':content/' . $pageSlug . '-' . $tail;
        }

        return \array_values(\array_unique($aliases));
    }

    private function slugifyStageTwoTaskAlias(string $value): string
    {
        $slug = \strtolower(\trim($value));
        $slug = \preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? $slug;
        $slug = \trim($slug, '-');

        return $slug !== '' ? $slug : 'page';
    }

    /**
     * @param list<array<string, mixed>> $incomingTasks
     * @param list<array<string, mixed>> $baselineTasks
     */
    private function assertIncomingBlockTasksHaveRequiredContract(array &$incomingTasks, string $context, array $baselineTasks = []): void
    {
        $baselineSortOrders = [];
        foreach ($baselineTasks as $baselineTask) {
            if (!\is_array($baselineTask)) {
                continue;
            }
            $baselineTaskKey = \trim((string)($baselineTask['task_key'] ?? ''));
            if ($baselineTaskKey === '') {
                continue;
            }
            $baselineSortOrders[$baselineTaskKey] = (int)($baselineTask['sort_order'] ?? $baselineTask['block_task']['sort_order'] ?? 0);
        }

        foreach ($incomingTasks as $taskIndex => $task) {
            if (!\is_array($task)) {
                continue;
            }

            $taskKey = \trim((string)($task['task_key'] ?? ''));
            $taskLabel = $taskKey !== '' ? $taskKey : ($context . '#' . ((int)$taskIndex + 1));
            if (!\is_array($task['block_task'] ?? null)) {
                throw new \RuntimeException('AI task plan generation failed: ' . $taskLabel . ' is missing block_task.');
            }

            $blockTask = $task['block_task'];
            if (!\array_key_exists('sort_order', $blockTask)) {
                $sortOrder = null;
                if (\array_key_exists('sort_order', $task)) {
                    $sortOrder = (int)$task['sort_order'];
                } elseif ($taskKey !== '' && \array_key_exists($taskKey, $baselineSortOrders)) {
                    $sortOrder = (int)$baselineSortOrders[$taskKey];
                }
                if ($sortOrder !== null) {
                    $blockTask['sort_order'] = $sortOrder;
                    $incomingTasks[$taskIndex]['block_task'] = $blockTask;
                    if (!\array_key_exists('sort_order', $incomingTasks[$taskIndex])) {
                        $incomingTasks[$taskIndex]['sort_order'] = $sortOrder;
                    }
                }
            }
            foreach (self::BLOCK_TASK_REQUIRED_FIELDS as $requiredField) {
                if (!\array_key_exists($requiredField, $blockTask)) {
                    throw new \RuntimeException('AI task plan generation failed: ' . $taskLabel . ' block_task missing required field ' . $requiredField . '.');
                }
            }

            if (\trim((string)($blockTask['task_goal'] ?? '')) === '') {
                throw new \RuntimeException('AI task plan generation failed: ' . $taskLabel . ' block_task task_goal is empty.');
            }
            if (!\is_array($blockTask['meta_fields'] ?? null) || $blockTask['meta_fields'] === []) {
                throw new \RuntimeException('AI task plan generation failed: ' . $taskLabel . ' block_task meta_fields is empty.');
            }
            if (!\is_array($blockTask['content_plan'] ?? null) || $blockTask['content_plan'] === []) {
                throw new \RuntimeException('AI task plan generation failed: ' . $taskLabel . ' block_task content_plan is empty.');
            }
            if (!\is_array($blockTask['style_plan'] ?? null) || $blockTask['style_plan'] === []) {
                throw new \RuntimeException('AI task plan generation failed: ' . $taskLabel . ' block_task style_plan is empty.');
            }
            if (\trim((string)($blockTask['planning_reason'] ?? '')) === '') {
                throw new \RuntimeException('AI task plan generation failed: ' . $taskLabel . ' block_task planning_reason is empty.');
            }

            $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];
            foreach (['color', 'font', 'spacing', 'responsive'] as $styleKey) {
                if (\trim((string)($stylePlan[$styleKey] ?? '')) === '') {
                    throw new \RuntimeException('AI task plan generation failed: ' . $taskLabel . ' block_task style_plan missing ' . $styleKey . '.');
                }
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $baselineTasks
     * @param list<array<string, mixed>> $incomingTasks
     * @return list<array<string, mixed>>
     */
    private function mergeTaskListByKey(array $baselineTasks, array $incomingTasks, string $context): array
    {
        $incomingByKey = [];
        foreach ($incomingTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $incomingByKey[$taskKey] = $task;
        }

        $merged = [];
        foreach ($baselineTasks as $baselineTask) {
            if (!\is_array($baselineTask)) {
                continue;
            }
            $taskKey = \trim((string)($baselineTask['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            if (!isset($incomingByKey[$taskKey])) {
                // refine/rebuild 局部批次允许 AI 仅返回受影响任务；缺失项回退到基线任务保持结构完整。
                $merged[] = $baselineTask;
                continue;
            }
            $mergedTask = \array_replace_recursive($baselineTask, $incomingByKey[$taskKey]);
            $mergedTask = $this->sanitizeMergedTaskPlanTask($baselineTask, $mergedTask);
            $mergedTask['status'] = 'done';
            $mergedTask['task_status'] = 'done';
            $mergedTask['attempt_no'] = \max((int)($baselineTask['attempt_no'] ?? 0), (int)($mergedTask['attempt_no'] ?? 0), 0) + 1;
            $mergedTask['generated_at'] = \date('Y-m-d H:i:s');
            $merged[] = $mergedTask;
            unset($incomingByKey[$taskKey]);
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $baselineTask
     * @param array<string, mixed> $mergedTask
     * @return array<string, mixed>
     */
    private function sanitizeMergedTaskPlanTask(array $baselineTask, array $mergedTask): array
    {
        $baselineScript = \is_array($baselineTask['task_script'] ?? null) ? $baselineTask['task_script'] : [];
        $taskScript = \is_array($mergedTask['task_script'] ?? null) ? $mergedTask['task_script'] : [];

        foreach (['story_goal', 'content_fill_rule'] as $key) {
            $candidate = \trim((string)($taskScript[$key] ?? ''));
            if ($candidate === '' || !$this->isStageTwoMetaInstructionLike($candidate)) {
                continue;
            }
            $fallback = \trim((string)($baselineScript[$key] ?? ''));
            if ($fallback !== '') {
                $taskScript[$key] = $fallback;
            }
        }

        $requirements = \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [];
        $fallbackRequirements = \is_array($baselineScript['field_content_requirements'] ?? null) ? $baselineScript['field_content_requirements'] : [];
        $taskScript['field_content_requirements'] = $this->sanitizeTaskFieldRequirementSamples($requirements, $fallbackRequirements);
        $mergedTask['task_script'] = $taskScript;

        return $mergedTask;
    }

    /**
     * @param list<array<string, mixed>> $requirements
     * @param list<array<string, mixed>> $fallbackRequirements
     * @return list<array<string, mixed>>
     */
    private function sanitizeTaskFieldRequirementSamples(array $requirements, array $fallbackRequirements): array
    {
        $fallbackByField = [];
        foreach ($fallbackRequirements as $index => $fallbackRequirement) {
            if (!\is_array($fallbackRequirement)) {
                continue;
            }
            $field = \trim((string)($fallbackRequirement['field'] ?? ''));
            if ($field !== '') {
                $fallbackByField[$field] = $fallbackRequirement;
            }
            $fallbackByField['#' . $index] ??= $fallbackRequirement;
        }

        foreach ($requirements as $index => $requirement) {
            if (!\is_array($requirement)) {
                continue;
            }
            $field = \trim((string)($requirement['field'] ?? ''));
            $fallbackRequirement = \is_array($fallbackByField[$field] ?? null)
                ? $fallbackByField[$field]
                : (\is_array($fallbackByField['#' . $index] ?? null) ? $fallbackByField['#' . $index] : []);

            $sample = \trim((string)($requirement['sample'] ?? ''));
            if ($sample === '' || $this->isStageTwoMetaInstructionLike($sample)) {
                $fallbackSample = \trim((string)($fallbackRequirement['sample'] ?? ''));
                if ($fallbackSample !== '') {
                    $requirement['sample'] = $fallbackSample;
                }
            }

            $requirements[$index] = $requirement;
        }

        return $requirements;
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function sanitizePromptLikeTaskPlanStructured(array $structured): array
    {
        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        foreach ($sharedTasks as $index => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $sharedTasks[$index] = $this->sanitizePromptLikeTaskScriptWithContext($task, 'shared');
        }

        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $index => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $tasks[$index] = $this->sanitizePromptLikeTaskScriptWithContext($task, (string)$pageType);
            }
            $pageTasks[$pageType] = $tasks;
        }

        $structured['shared_tasks'] = $sharedTasks;
        $structured['page_tasks'] = $pageTasks;

        return $structured;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function sanitizePromptLikeTaskScriptWithContext(array $task, string $pageType): array
    {
        $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
        $isShared = $pageType === 'shared' || \str_starts_with(\trim((string)($task['task_key'] ?? '')), 'shared:');

        $storyGoal = \trim((string)($taskScript['story_goal'] ?? ''));
        if ($storyGoal === '' || $this->isStageTwoMetaInstructionLike($storyGoal)) {
            $taskScript['story_goal'] = $isShared
                ? $this->composeConcreteSharedStoryGoal($task)
                : $this->composeConcretePageStoryGoal($task, $pageType);
        }

        $contentFillRule = \trim((string)($taskScript['content_fill_rule'] ?? ''));
        if ($contentFillRule === '' || $this->isStageTwoMetaInstructionLike($contentFillRule)) {
            $taskScript['content_fill_rule'] = $isShared
                ? $this->composeConcreteSharedFillRule($task)
                : $this->composeConcretePageFillRule($task, $pageType);
        }

        $requirements = \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [];
        $fallbackRequirements = $isShared
            ? $this->buildSharedTaskFieldRequirements($task)
            : $this->resolveStageTwoFallbackRequirementsFromPlanContext($task);
        $taskScript['field_content_requirements'] = $this->sanitizeTaskFieldRequirementSamples($requirements, $fallbackRequirements);

        $task['task_script'] = $taskScript;

        return $task;
    }

    /**
     * @param array<string, mixed> $task
     * @return list<array<string, mixed>>
     */
    private function resolveStageTwoFallbackRequirementsFromPlanContext(array $task): array
    {
        $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
        $fieldPlan = \is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : [];
        $requirements = [];
        foreach ($fieldPlan as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $field = \trim((string)($row['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $requirements[] = [
                'field' => $field,
                'sample' => \trim((string)($row['sample'] ?? '')),
                'reason' => \trim((string)($row['reason'] ?? '')),
            ];
        }

        return $requirements;
    }

    /**
     * @param list<string> $existing
     * @param array<int, mixed> $incoming
     * @return list<string>
     */
    private function mergeRiskNotes(array $existing, array $incoming): array
    {
        $notes = [];
        foreach (\array_merge($existing, $incoming) as $note) {
            $text = \is_scalar($note) ? \trim((string)$note) : '';
            if ($text === '' || \in_array($text, $notes, true)) {
                continue;
            }
            $notes[] = $text;
        }

        return $notes;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @return array{
     *   markdown:string,
     *   structured:array<string, mixed>,
     *   virtual_theme_plan:array<string, mixed>,
     *   generation_source:string
     * }
     */
    public function buildTaskPlanArtifacts(array $scope, array $buildBlueprint): array
    {
        return $this->buildTaskPlanArtifactsInternal($scope, $buildBlueprint, null);
    }

    /**
     * 以流式方式生成第二阶段任务方案。
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param callable|null $chunkCallback function(string $chunk): void
     * @param callable|null $heartbeatCallback function(): void
     * @return array{markdown:string,structured:array<string, mixed>,virtual_theme_plan:array<string, mixed>,generation_source:string}
     */
    public function buildTaskPlanArtifactsStream(
        array $scope,
        array $buildBlueprint,
        ?callable $chunkCallback = null,
        ?callable $heartbeatCallback = null,
        ?callable $progressCallback = null
    ): array
    {
        return $this->buildTaskPlanArtifactsInternal($scope, $buildBlueprint, $chunkCallback, $heartbeatCallback, $progressCallback);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param callable|null $chunkCallback
     * @param callable|null $heartbeatCallback
     * @return array{markdown:string,structured:array<string, mixed>,virtual_theme_plan:array<string, mixed>,generation_source:string}
     */
    private function buildTaskPlanArtifactsInternal(
        array $scope,
        array $buildBlueprint,
        ?callable $chunkCallback,
        ?callable $heartbeatCallback = null,
        ?callable $progressCallback = null
    ): array
    {
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $planWorkbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
        $stage1Workbench = \is_array($planWorkbench['stage1'] ?? null) ? $planWorkbench['stage1'] : [];
        $confirmedWorkbench = \is_array($planWorkbench['confirmed'] ?? null) ? $planWorkbench['confirmed'] : [];
        $confirmedPlanBook = $this->resolveConfirmedStageOnePlanBook($scope);
        if ($confirmedPlanBook === []) {
            throw new \RuntimeException('Stage-2 task plan requires confirmed stage-1 plan_book.structured.');
        }
        $planStructured = \is_array($confirmedWorkbench['structured_plan'] ?? null)
            ? $confirmedWorkbench['structured_plan']
            : (\is_array($confirmedWorkbench['plan_json'] ?? null)
                ? $confirmedWorkbench['plan_json']
                : []);
        $themeContextSnapshot = \is_array($stage1Workbench['theme_context_snapshot'] ?? null)
            ? $stage1Workbench['theme_context_snapshot']
            : (\is_array($executionBlueprint['theme_context_snapshot'] ?? null) ? $executionBlueprint['theme_context_snapshot'] : []);
        $sharedPromptContext = \is_array($confirmedWorkbench['shared_prompt_context'] ?? null)
            ? $confirmedWorkbench['shared_prompt_context']
            : (\is_array($executionBlueprint['shared_prompt_context'] ?? null) ? $executionBlueprint['shared_prompt_context'] : []);
        $pageTypes = \array_values(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            \is_array($executionBlueprint['page_types'] ?? null) ? $executionBlueprint['page_types'] : ($scope['page_types'] ?? [])
        ), static fn(string $value): bool => $value !== ''));
        $pageTypes = $this->resolveStageTwoPageTypes($pageTypes, $confirmedPlanBook);

        $buildTasks = $this->resolveStageTwoBuildTasks($buildBlueprint, $confirmedPlanBook);
        \usort($buildTasks, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
        if ($buildTasks === []) {
            throw new \RuntimeException('Stage-2 task plan requires confirmed stage-1 shared/page blocks.');
        }

        $sharedTasks = [];
        $pageTasks = [];
        foreach ($buildTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $groupKey = \trim((string)($task['group_key'] ?? ''));
            $pageType = \trim((string)($task['page_type'] ?? ''));
            if ($groupKey === 'shared' || $pageType === '') {
                $sharedTasks[] = $task;
                continue;
            }
            $pageTasks[$pageType] ??= [];
            $pageTasks[$pageType][] = $task;
        }

        $pagePlans = $this->resolveStageTwoPagePlans($executionBlueprint, $confirmedPlanBook);
        $metaFieldMatrix = [];
        $blockPlanMatrix = [];
        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $blocks = \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [];
            foreach ($blocks as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockKey = (string)($block['block_key'] ?? $block['section_code'] ?? 'block');
                $metaFieldMatrix[$pageType][$blockKey] = [
                    'goal' => (string)($block['goal'] ?? ''),
                    'field_plan' => \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [],
                    'result_ref' => \is_array($block['result_ref'] ?? null) ? $block['result_ref'] : [],
                ];
                $blockPlanMatrix[$pageType][$blockKey] = $block;
            }
        }

        $sharedComponentPlans = $this->resolveStageTwoSharedComponentPlans($executionBlueprint, $confirmedPlanBook);
        $sharedTasks = $this->ensureStageTwoSharedTasks($sharedTasks, $sharedComponentPlans, $sharedPromptContext, $themeContextSnapshot);

        [$sharedTasks, $pageTasks] = $this->enrichTasksWithStage1PlanContext(
            $sharedTasks,
            $this->ensureStageTwoBlockTaskPlanFanoutTasks($pageTasks, $pagePlans, $blockPlanMatrix),
            $metaFieldMatrix,
            $blockPlanMatrix,
            $pagePlans
        );

        $stage1TaskCues = [
            'shared' => [],
            'pages' => [],
        ];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $region = \trim((string)($task['region'] ?? ''));
            if ($region === '' && \str_starts_with($taskKey, 'shared:')) {
                $region = \trim(\substr($taskKey, 7));
            }
            $sharedPlan = ($region !== '' && \is_array($sharedComponentPlans[$region] ?? null))
                ? $sharedComponentPlans[$region]
                : [];
            $stage1TaskCues['shared'][$taskKey] = [
                'task_key' => $taskKey,
                'stage1_goal' => (string)($sharedPlan['goal'] ?? $task['plan_context']['stage1_goal'] ?? $task['label'] ?? ''),
            ];
        }
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                $stage1TaskCues['pages'][$taskKey] = [
                    'task_key' => $taskKey,
                    'page_type' => (string)$pageType,
                    'section_code' => (string)($task['section_code'] ?? ''),
                    'block_goal' => (string)($planContext['block_goal'] ?? ''),
                    'page_goal' => (string)($planContext['page_goal'] ?? ''),
                    'realtime_content' => \is_array($planContext['realtime_content'] ?? null) ? $planContext['realtime_content'] : [],
                    'design_tags' => \is_array($planContext['design_tags'] ?? null) ? $planContext['design_tags'] : [],
                    'style_direction' => (string)($planContext['style_direction'] ?? ''),
                    'reason' => (string)($planContext['block_reason'] ?? $planContext['block_why'] ?? ''),
                    'why' => (string)($planContext['block_why'] ?? $planContext['block_reason'] ?? ''),
                ];
            }
        }

        $executionOrder = \array_values(\array_map(
            static fn(array $task): array => [
                'task_key' => (string)($task['task_key'] ?? ''),
                'group_key' => (string)($task['group_key'] ?? ''),
                'page_type' => (string)($task['page_type'] ?? ''),
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
            ],
            $buildTasks
        ));

        $sourceSignature = $this->resolveStageTwoSourceSignature($scope, $executionBlueprint, $confirmedPlanBook);
        $stage2ContextSnapshot = $this->buildStageTwoContextSnapshot(
            $themeContextSnapshot,
            $sharedPromptContext,
            $sharedTasks,
            $pagePlans,
            $planStructured,
            $scope,
            $sourceSignature,
            $confirmedPlanBook
        );

        $sessionScope = (string)($scope['public_id'] ?? $scope['session_id'] ?? '');
        foreach ($sharedTasks as $idx => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $sharedTasks[$idx] = \array_replace($task, [
                'runtime_context' => $this->buildTaskRuntimeContext($scope, $task, $sessionScope, 'root', 'shared', $stage2ContextSnapshot),
            ]);
        }
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $pageTasks[$pageType][$idx] = \array_replace($task, [
                    'runtime_context' => $this->buildTaskRuntimeContext($scope, $task, $sessionScope, 'shared', $pageType, $stage2ContextSnapshot),
                ]);
            }
        }

        $taskTree = [
            'root' => [
                'node_key' => 'root',
                'node_type' => 'site',
                'task_key' => 'site:virtual_theme',
                'status' => 'pending',
                'goal' => '从第一阶段确认方案拆解出可执行第二阶段任务树并映射为执行清单',
                'reason' => '保证第二阶段只执行已确认方案，避免运行期漂移',
                'inputs' => [
                    'plan_signature' => $sourceSignature,
                ],
                'outputs' => ['task_tree', 'execution_blueprint.tasks'],
                'completion_rule' => 'first-stage confirmed plan fully decomposed into stage-2 execution tasks',
                'dependencies' => [],
                'resource_plan' => [],
                'parallel_group' => 'site',
                'children' => [],
            ],
            'shared' => [],
            'pages' => [],
        ];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = (string)($task['task_key'] ?? 'shared:task');
            $taskTree['shared'][] = [
                'node_key' => $taskKey,
                'parent_key' => 'root',
                'node_type' => 'shared',
                'task_key' => $taskKey,
                'status' => (string)($task['status'] ?? 'pending'),
                'goal' => (string)($task['label'] ?? $taskKey),
                'reason' => '共享任务需要先完成，后续页面任务才能复用',
                'inputs' => [
                    'task_key' => $taskKey,
                    'page_type' => '',
                ],
                'outputs' => [
                    'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
                ],
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                'completion_rule' => (string)($task['completion_rule'] ?? 'shared task complete when its output can be reused globally'),
                'resource_plan' => [
                    'field_plan' => \is_array($task['field_plan'] ?? null) ? $task['field_plan'] : [],
                    'content_brief' => \is_array($task['content_brief'] ?? null) ? $task['content_brief'] : [],
                ],
                'parallel_group' => 'shared',
                'children' => [],
            ];
        }
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = (string)($task['task_key'] ?? ($pageType . ':task'));
                $taskTree['pages'][$pageType][] = [
                    'node_key' => $taskKey,
                    'parent_key' => 'shared',
                    'node_type' => 'page_task',
                    'task_key' => $taskKey,
                    'page_type' => $pageType,
                    'status' => (string)($task['status'] ?? 'pending'),
                    'goal' => (string)($task['label'] ?? $taskKey),
                    'reason' => (string)($task['plan_context']['block_goal'] ?? '页面任务完成后支持该页面物化与编辑'),
                    'inputs' => [
                        'task_key' => $taskKey,
                        'page_type' => $pageType,
                    ],
                    'outputs' => [
                        'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
                    ],
                    'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                    'completion_rule' => (string)($task['completion_rule'] ?? 'page task complete when the page can be materialized and edited'),
                    'resource_plan' => [
                        'field_plan' => \is_array($task['field_plan'] ?? null) ? $task['field_plan'] : [],
                        'content_brief' => \is_array($task['content_brief'] ?? null) ? $task['content_brief'] : [],
                        'seo_brief' => \is_array($task['seo_brief'] ?? null) ? $task['seo_brief'] : [],
                    ],
                    'parallel_group' => 'page:' . $pageType,
                    'children' => [],
                ];
            }
        }

        $executionBlueprintTasks = [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $executionBlueprintTasks[] = [
                'task_key' => (string)($task['task_key'] ?? ''),
                'from_node_key' => (string)($task['task_key'] ?? ''),
                'group_key' => 'shared',
                'task_group' => 'shared',
                'page_type' => '',
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                'status' => (string)($task['status'] ?? 'pending'),
                'parent_task_key' => 'root',
                'can_parallel' => true,
                'materialize_after_done' => false,
                'materialize_policy' => 'none',
                'prompt_template_key' => 'stage2_task_execute',
                'prompt_variables' => [
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'page_type' => '',
                ],
                'progress_weight' => (float)($task['progress_weight'] ?? 1.0),
                'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
                'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
            ];
        }
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $executionBlueprintTasks[] = [
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'from_node_key' => (string)($task['task_key'] ?? ''),
                    'group_key' => (string)($task['group_key'] ?? 'page'),
                    'task_group' => $pageType === 'home_page' ? 'home' : 'other',
                    'page_type' => $pageType,
                    'sort_order' => (int)($task['sort_order'] ?? 0),
                    'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                    'status' => (string)($task['status'] ?? 'pending'),
                    'parent_task_key' => 'shared',
                    'can_parallel' => true,
                    'materialize_after_done' => true,
                    'materialize_policy' => 'page',
                    'prompt_template_key' => 'stage2_task_execute',
                    'prompt_variables' => [
                        'task_key' => (string)($task['task_key'] ?? ''),
                        'page_type' => $pageType,
                    ],
                    'progress_weight' => (float)($task['progress_weight'] ?? 1.0),
                    'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
                    'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
                ];
            }
        }
        \usort($executionBlueprintTasks, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
        $executionBlueprintTasks = $this->normalizeExecutionBlueprintTasks($executionBlueprintTasks);
        $executionBlueprintPlan = [
            'signature' => (string)($executionBlueprint['signature'] ?? ''),
            'task_groups' => [
                'shared' => \array_values(\array_filter(\array_map(static fn(array $task): array => [
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'status' => (string)($task['status'] ?? 'pending'),
                    'can_parallel' => (bool)($task['can_parallel'] ?? true),
                    'materialize_after_done' => (bool)($task['materialize_after_done'] ?? false),
                    'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
                ], $sharedTasks), static fn(array $task): bool => $task['task_key'] !== '')),
                'pages' => [],
            ],
            'tasks' => \array_values($executionBlueprintTasks),
            'task_count' => \count($executionBlueprintTasks),
        ];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            $executionBlueprintPlan['task_groups']['pages'][$pageType] = \array_values(\array_map(static fn(array $task): array => [
                'task_key' => (string)($task['task_key'] ?? ''),
                'status' => (string)($task['status'] ?? 'pending'),
                'can_parallel' => (bool)($task['can_parallel'] ?? true),
                'materialize_after_done' => (bool)($task['materialize_after_done'] ?? true),
                'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
            ], $tasks));
        }

        $structured = [
            'plan_signature' => $sourceSignature,
            'stage2_context_snapshot' => $stage2ContextSnapshot,
            'theme_context_snapshot' => $themeContextSnapshot,
            'shared_prompt_context' => $sharedPromptContext,
            'content_locale' => $this->resolveStageTwoContentLocale($scope),
            'plan_locale' => \trim((string)($scope['plan_locale'] ?? '')),
            'virtual_theme_strategy' => [
                'workspace_track' => (string)($executionBlueprint['workspace_track'] ?? $scope['workspace_track'] ?? ''),
                'site_summary' => (string)($planStructured['site_strategy']['summary'] ?? ''),
                'site_display_name' => (string)($planStructured['site_strategy']['site_display_name'] ?? $scope['site_title'] ?? ''),
            ],
            'task_script_brief' => [
                'goal' => '将第一阶段方向骨架转为可直接编码实现的任务脚本，第三阶段仅按脚本生成组件。',
                'rule' => '每个任务必须包含完整字段、内容意图、示例值与验收条件。',
            ],
            'stage1_task_cues' => $stage1TaskCues,
            'shared_tasks' => $sharedTasks,
            'page_tasks' => $pageTasks,
            'task_tree' => $taskTree,
            'execution_blueprint' => $executionBlueprintPlan,
            'meta_field_matrix' => $metaFieldMatrix,
            'style_tokens' => [
                'palette' => $this->resolveStageTwoThemePalette($planStructured, $themeContextSnapshot, $scope),
                'theme_style' => $this->resolveStageTwoThemeStyle($planStructured, $themeContextSnapshot, $scope),
                'theme_design' => \is_array($themeContextSnapshot['theme_design'] ?? null) ? $themeContextSnapshot['theme_design'] : $themeContextSnapshot,
            ],
            'content_rules' => [
                'seo_strategy' => \is_array($planStructured['seo_strategy'] ?? null) ? $planStructured['seo_strategy'] : [],
                'navigation_plan' => \is_array($planStructured['navigation_plan'] ?? null) ? $planStructured['navigation_plan'] : [],
                'footer_plan' => \is_array($planStructured['footer_plan'] ?? null) ? $planStructured['footer_plan'] : [],
            ],
            'responsive_rules' => [
                'global_rule' => (string)($planStructured['theme_style']['responsive_rule'] ?? ''),
                'page_types' => $pageTypes,
            ],
            'execution_order' => $executionOrder,
            'task_runtime' => [
                'isolation' => [
                    'session_scope' => $sessionScope,
                    'shared_prompt_only' => true,
                    'task_key_required' => true,
                ],
                'parallelism' => [
                    'page_level_parallel' => true,
                    'component_level_parallel' => true,
                    'independent_stream_buffer_per_task' => true,
                ],
            ],
            'risk_notes' => [
                '共享组件需先完成，再推进页面任务。',
                '恢复执行时应跳过已完成任务，从首个未完成任务继续。',
                '页面生成语言应遵循 default_locale，方案/任务说明语言应遵循 plan_locale（若已提供）。',
                '同一份提示词可以并发复用，但每个 SSE 会话必须具备独立 task_key 与 chunk 缓冲。',
            ],
        ];
        $structured = $this->applyBlockTaskSchemaToStructured($structured);

        $virtualThemePlan = $structured;
        $virtualThemePlan['signature'] = \sha1((string)\json_encode($structured, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));

        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            $deterministic = $this->buildDeterministicTaskPlanStructured($structured);
            $deterministic = $this->applyReadableDeterministicTaskPlanContent($deterministic);
            $deterministic = $this->applyBlockTaskSchemaToStructured($deterministic);
            $deterministic = $this->ensureTaskDirectoryHierarchy($deterministic);
            $deterministic = $this->syncStageTwoRuntimeContexts($deterministic);
            $markdown = $this->buildStageTwoMarkdown(
                $pageTypes,
                \is_array($deterministic['shared_tasks'] ?? null) ? $deterministic['shared_tasks'] : [],
                \is_array($deterministic['page_tasks'] ?? null) ? $deterministic['page_tasks'] : [],
                $deterministic
            );
            $virtualThemePlan = \array_replace_recursive($virtualThemePlan, $deterministic, [
                'task_directory_tree' => $deterministic['task_directory_tree'] ?? [],
                'task_tree' => $deterministic['task_tree'] ?? [],
            ]);
            $virtualThemePlan['signature'] = $this->buildSignature($deterministic);
            return [
                'markdown' => $markdown,
                'structured' => $deterministic,
                'virtual_theme_plan' => $virtualThemePlan,
                'generation_source' => 'deterministic',
            ];
        }

        $aiTaskPlan = $this->buildTaskPlanArtifactsByAi(
            $scope,
            $buildBlueprint,
            $structured,
            $virtualThemePlan,
            $chunkCallback,
            $heartbeatCallback,
            $progressCallback
        );
        $markdown = \trim((string)($aiTaskPlan['markdown'] ?? ''));
        $aiVirtualThemePlan = \is_array($aiTaskPlan['virtual_theme_plan'] ?? null) ? $aiTaskPlan['virtual_theme_plan'] : [];
        if ($markdown === '' || $aiVirtualThemePlan === []) {
            throw new \RuntimeException('AI task plan generation failed: empty markdown or virtual_theme_plan.');
        }
        $mergedVirtualThemePlan = \array_replace_recursive($virtualThemePlan, $aiVirtualThemePlan);
        $mergedStructured = \array_replace_recursive($structured, $mergedVirtualThemePlan);
        if (\is_array($structured['stage1_task_cues'] ?? null)) {
            $mergedStructured['stage1_task_cues'] = $structured['stage1_task_cues'];
        }
        $mergedStructured = $this->sanitizePromptLikeTaskPlanStructured($mergedStructured);
        $mergedStructured = $this->applyBlockTaskSchemaToStructured($mergedStructured);
        $this->assertAiTaskPlanIsContentful($mergedStructured);
        $mergedStructured = $this->ensureTaskDirectoryHierarchy($mergedStructured);
        $mergedStructured = $this->syncStageTwoRuntimeContexts($mergedStructured);
        $mergedVirtualThemePlan = \array_replace_recursive($mergedVirtualThemePlan, [
            'block_task_schema' => $mergedStructured['block_task_schema'] ?? [],
            'task_directory_tree' => $mergedStructured['task_directory_tree'] ?? [],
            'task_tree' => $mergedStructured['task_tree'] ?? [],
            'shared_tasks' => $mergedStructured['shared_tasks'] ?? [],
            'page_tasks' => $mergedStructured['page_tasks'] ?? [],
            'execution_blueprint' => $mergedStructured['execution_blueprint'] ?? [],
        ]);
        $mergedVirtualThemePlan['signature'] = $this->buildSignature($mergedStructured);
        return [
            'markdown' => $markdown,
            'structured' => $mergedStructured,
            'virtual_theme_plan' => $mergedVirtualThemePlan,
            'generation_source' => 'ai',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $draftPlan
     * @param array<string, mixed> $payload
     * @param callable|null $heartbeatCallback 流式期间轻量保活（如 SseWriter::sendComment）
     * @return array{
     *   markdown:string,
     *   structured:array<string, mixed>,
     *   virtual_theme_plan:array<string, mixed>,
     *   change_scope_report:array<string, mixed>,
     *   generation_source:string
     * }
     */
    public function refineDraftTaskPlan(
        array $scope,
        array $buildBlueprint,
        array $draftPlan,
        array $payload,
        ?callable $chunkCallback = null,
        ?callable $heartbeatCallback = null,
        ?callable $progressCallback = null
    ): array {
        $artifacts = $this->buildTaskPlanArtifactsByAiMode(
            $scope,
            $buildBlueprint,
            'refine_task_plan',
            $payload,
            $draftPlan,
            $chunkCallback,
            $heartbeatCallback,
            $progressCallback
        );
        $markdown = (string)($artifacts['markdown'] ?? '');
        $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
        $virtualThemePlan = \is_array($artifacts['virtual_theme_plan'] ?? null) ? $artifacts['virtual_theme_plan'] : [];

        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $report = [
            'mode' => 'refine_task_plan',
            'round' => $round,
            'target_scope' => $targetScope,
            'updated_at' => \date('Y-m-d H:i:s'),
            'changes' => [
                [
                    'target' => $targetScope !== '' ? $targetScope : 'task_plan',
                    'reason' => '局部优化当前任务方案',
                ],
            ],
        ];
        $structured['change_scope_report'] = $report;
        $structured = $this->ensureTaskDirectoryHierarchy($structured);
        $virtualThemePlan['change_scope_report'] = $report;
        $virtualThemePlan = \array_replace_recursive($virtualThemePlan, [
            'task_directory_tree' => $structured['task_directory_tree'] ?? [],
            'task_tree' => $structured['task_tree'] ?? [],
        ]);
        $virtualThemePlan['signature'] = $this->buildSignature(\array_replace($virtualThemePlan, ['markdown' => $markdown]));

        return [
            'markdown' => $markdown,
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
            'change_scope_report' => $report,
            'generation_source' => (string)($artifacts['generation_source'] ?? 'ai'),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $payload
     * @param callable|null $heartbeatCallback 流式期间轻量保活（如 SseWriter::sendComment），与阶段一/detect_bootstrap 一致
     * @return array{
     *   markdown:string,
     *   structured:array<string, mixed>,
     *   virtual_theme_plan:array<string, mixed>,
     *   rebuild_summary:array<string, mixed>,
     *   generation_source:string
     * }
     */
    public function rebuildDraftTaskPlan(
        array $scope,
        array $buildBlueprint,
        array $payload,
        ?callable $chunkCallback = null,
        ?callable $heartbeatCallback = null,
        ?callable $progressCallback = null
    ): array {
        $artifacts = $this->buildTaskPlanArtifactsByAiMode(
            $scope,
            $buildBlueprint,
            'rebuild_task_plan',
            $payload,
            [],
            $chunkCallback,
            $heartbeatCallback,
            $progressCallback
        );
        $markdown = (string)($artifacts['markdown'] ?? '');
        $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
        $virtualThemePlan = \is_array($artifacts['virtual_theme_plan'] ?? null) ? $artifacts['virtual_theme_plan'] : [];

        $round = \max(1, (int)($payload['round'] ?? 1));
        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        $taskTree = \is_array($structured['task_tree'] ?? null) ? $structured['task_tree'] : [];
        $taskCount = \count(\is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : []);
        $pageTaskCount = 0;
        foreach ($pageTasks as $tasks) {
            $pageTaskCount += \is_array($tasks) ? \count($tasks) : 0;
        }
        $summary = [
            'mode' => 'rebuild_task_plan',
            'round' => $round,
            'task_count' => $taskCount,
            'shared_task_count' => \count($sharedTasks),
            'page_task_count' => $pageTaskCount,
            'task_tree_node_count' => $this->countTaskTreeNodes($taskTree),
            'updated_at' => \date('Y-m-d H:i:s'),
            'risk_notes' => \is_array($structured['risk_notes'] ?? null) ? $structured['risk_notes'] : [],
        ];
        $structured['rebuild_summary'] = $summary;
        $structured = $this->ensureTaskDirectoryHierarchy($structured);
        $virtualThemePlan['rebuild_summary'] = $summary;
        $virtualThemePlan = \array_replace_recursive($virtualThemePlan, [
            'task_directory_tree' => $structured['task_directory_tree'] ?? [],
            'task_tree' => $structured['task_tree'] ?? [],
        ]);
        $virtualThemePlan['signature'] = $this->buildSignature(\array_replace($virtualThemePlan, ['markdown' => $markdown]));

        return [
            'markdown' => $markdown,
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
            'rebuild_summary' => $summary,
            'generation_source' => (string)($artifacts['generation_source'] ?? 'ai'),
        ];
    }

    /**
     * 将 deterministic/fake_mode 的任务文案统一修正为可读中文，避免预览中出现乱码。
     *
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function applyReadableDeterministicTaskPlanContent(array $structured): array
    {
        $structured['risk_notes'] = [
            '共享组件任务需要先完成，再推进页面任务。',
            '恢复执行时应跳过已完成任务，从首个未完成任务继续。',
            '页面生成语言遵循 default_locale，任务方案说明优先遵循 plan_locale。',
            '同一套提示词可以并发复用，但每个 SSE 会话都必须绑定独立 task_key 和 chunk 缓冲。',
        ];

        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        foreach ($sharedTasks as $idx => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $label = \trim((string)($task['label'] ?? $task['task_key'] ?? '共享任务'));
            $task['task_script'] = \array_replace(
                \is_array($task['task_script'] ?? null) ? $task['task_script'] : [],
                [
                    'story_goal' => $label . ' 需要先稳定落地，供后续页面复用。',
                    'content_fill_rule' => '先实现可复用结构，再补充必要文案与链接，不引入额外功能分歧。',
                    'stage3_directive' => '按照共享组件规范实现，并保留后续页面复用能力。',
                    'field_content_requirements' => [
                        [
                            'field' => 'title',
                            'sample' => $label,
                            'reason' => '明确共享组件的识别信息与用途。',
                        ],
                    ],
                ]
            );
            $task['task_script']['story_goal'] = $this->composeConcreteSharedStoryGoal($task);
            $task['task_script']['content_fill_rule'] = $this->composeConcreteSharedFillRule($task);
            $task['task_script']['field_content_requirements'] = $this->buildSharedTaskFieldRequirements($task);
            $task['implementation_contract'] = \array_replace(
                \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [],
                [
                    'acceptance' => [
                        '共享组件可被所有已选页面复用。',
                        '字段配置具备可编辑性，并可直接进入第三阶段生成。',
                    ],
                ]
            );
            $task['task_script']['story_goal'] = $this->composeConcreteSharedStoryGoal($task);
            $task['task_script']['content_fill_rule'] = $this->composeConcreteSharedFillRule($task);
            $task['task_script']['field_content_requirements'] = $this->buildSharedTaskFieldRequirements($task);
            $task['task_script']['story_goal'] = $this->composeConcreteSharedStoryGoal($task);
            $task['task_script']['content_fill_rule'] = $this->composeConcreteSharedFillRule($task);
            $task['task_script']['field_content_requirements'] = $this->buildSharedTaskFieldRequirements($task);
            $sharedTasks[$idx] = $task;
        }

        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                $fieldPlan = \is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : [];
                $requirements = [];
                foreach ($fieldPlan as $field) {
                    if (!\is_array($field)) {
                        continue;
                    }
                    $name = \trim((string)($field['field'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $sample = \trim((string)($field['sample'] ?? ''));
                    $requirements[] = [
                        'field' => $name,
                        'sample' => $sample !== '' ? $sample : ('为 `' . $name . '` 提供可用示例'),
                        'reason' => \trim((string)($field['reason'] ?? '')) !== '' ? (string)$field['reason'] : '保证该区块字段在第三阶段可直接使用。',
                    ];
                }
                if ($requirements === []) {
                    $requirements[] = [
                        'field' => 'content',
                        'sample' => '根据该区块目标生成可直接展示的内容。',
                        'reason' => '确保任务脚本具备最小可执行字段样例。',
                    ];
                }
                $blockGoal = \trim((string)($planContext['block_goal'] ?? ''));
                $pageGoal = \trim((string)($planContext['page_goal'] ?? ''));
                $label = \trim((string)($task['label'] ?? $task['task_key'] ?? $pageType));
                $task['task_script'] = \array_replace(
                    \is_array($task['task_script'] ?? null) ? $task['task_script'] : [],
                    [
                        'story_goal' => $blockGoal !== '' ? $blockGoal : ($label . ' 需要服务于页面目标：' . ($pageGoal !== '' ? $pageGoal : $pageType)),
                        'content_fill_rule' => '严格围绕区块目标填充内容，保持字段样例、SEO 意图与 CTA 方向一致。',
                        'stage3_directive' => '按该任务脚本直接生成组件配置、文案与结构，不再额外发散规划。',
                        'field_content_requirements' => $requirements,
                    ]
                );
                $task['task_script']['story_goal'] = $this->composeConcretePageStoryGoal($task, (string)$pageType);
                $task['task_script']['content_fill_rule'] = $this->composeConcretePageFillRule($task, (string)$pageType);
                $task['implementation_contract'] = \array_replace(
                    \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [],
                    [
                        'acceptance' => [
                            '区块输出需要覆盖 block_goal 与 page_goal。',
                            'field_content_requirements 中每个字段都提供可直接使用的样例值。',
                        ],
                    ]
                );
                $tasks[$idx] = $task;
            }
            $pageTasks[$pageType] = \array_values($tasks);
        }

        $structured['shared_tasks'] = \array_values($sharedTasks);
        $structured['page_tasks'] = $pageTasks;
        return $structured;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function composeConcreteSharedStoryGoal(array $task): string
    {
        $taskKey = \trim((string)($task['task_key'] ?? ''));
        if (\str_contains($taskKey, 'header')) {
            return '直接产出可上屏的 Header 内容，包括品牌名、导航项和主 CTA 文案。';
        }
        if (\str_contains($taskKey, 'footer')) {
            return '直接产出可上屏的 Footer 内容，包括信息分组、政策链接和联系入口文案。';
        }

        return '直接产出可复用的共享内容与字段样例，不写方向说明。';

        if (\str_contains($taskKey, 'header')) {
            return '直接产出可上屏的 Header 内容，包括品牌名、导航项和主 CTA 文案。';
        }
        if (\str_contains($taskKey, 'footer')) {
            return '直接产出可上屏的 Footer 内容，包括信息分组、政策链接和联系入口文案。';
        }

        return '直接产出可复用的共享内容与字段样例，不写方向说明。';
    }

    /**
     * @param array<string, mixed> $task
     */
    private function composeConcreteSharedFillRule(array $task): string
    {
        $taskKey = \trim((string)($task['task_key'] ?? ''));
        if (\str_contains($taskKey, 'header')) {
            return '直接给出导航项、品牌文案、CTA 文案与可编辑链接；缺少真实事实时保留可编辑字段，不写策略说明。';
        }
        if (\str_contains($taskKey, 'footer')) {
            return '直接给出页脚信息分组、政策链接和联系字段样例；未知信息保留占位字段，不输出方向描述。';
        }

        return '共享任务直接产出可复用内容与字段样例；没有确定事实时使用可编辑占位字段，不写“围绕…说明”这类提示语。';

        if (\str_contains($taskKey, 'header')) {
            return '直接给出导航项、品牌文案、CTA 文案与可编辑链接；缺少真实事实时保留可编辑字段，不写策略说明。';
        }
        if (\str_contains($taskKey, 'footer')) {
            return '直接给出页脚信息分组、政策链接和联系字段样例；未知信息保留占位字段，不输出方向描述。';
        }

        return '共享任务直接产出可复用内容与字段样例；没有确定事实时使用可编辑占位字段，不写“围绕…说明”这类提示语。';
    }

    /**
     * @param array<string, mixed> $task
     * @return list<array<string, string>>
     */
    private function buildSharedTaskFieldRequirements(array $task): array
    {
        $taskKey = \trim((string)($task['task_key'] ?? ''));
        $label = \trim((string)($task['label'] ?? ($taskKey !== '' ? $taskKey : '共享任务')));
        if (\str_contains($taskKey, 'header')) {
            return [
                ['field' => 'title', 'sample' => ($label !== '' ? $label : 'Header'), 'reason' => '明确共享头部的识别名称。'],
                ['field' => 'navigation_items', 'sample' => '首页 / 关于我们 / 联系我们', 'reason' => '给出可直接渲染的导航样例。'],
                ['field' => 'primary_cta', 'sample' => '立即咨询', 'reason' => '给出共享主动作文案。'],
            ];
        }
        if (\str_contains($taskKey, 'footer')) {
            return [
                ['field' => 'title', 'sample' => ($label !== '' ? $label : 'Footer'), 'reason' => '明确共享页脚的识别名称。'],
                ['field' => 'policy_links', 'sample' => '隐私政策 / 服务条款 / 联系我们', 'reason' => '给出页脚政策链接样例。'],
                ['field' => 'contact_fields', 'sample' => '邮箱 / WhatsApp / 在线客服', 'reason' => '给出可编辑的联系信息样例。'],
            ];
        }

        return [
            ['field' => 'title', 'sample' => ($label !== '' ? $label : '共享任务'), 'reason' => '明确共享组件的识别信息与用途。'],
        ];

        $taskKey = \trim((string)($task['task_key'] ?? ''));
        $label = \trim((string)($task['label'] ?? ($taskKey !== '' ? $taskKey : '共享任务')));
        if (\str_contains($taskKey, 'header')) {
            return [
                ['field' => 'title', 'sample' => $label, 'reason' => '明确共享头部的识别名称。'],
                ['field' => 'navigation_items', 'sample' => '首页 / 关于我们 / 联系我们', 'reason' => '给出可直接渲染的导航样例。'],
                ['field' => 'primary_cta', 'sample' => '立即咨询', 'reason' => '给出共享主动作文案。'],
            ];
        }
        if (\str_contains($taskKey, 'footer')) {
            return [
                ['field' => 'title', 'sample' => $label, 'reason' => '明确共享页脚的识别名称。'],
                ['field' => 'policy_links', 'sample' => '隐私政策 / 服务条款 / 联系我们', 'reason' => '给出页脚政策链接样例。'],
                ['field' => 'contact_fields', 'sample' => '邮箱 / WhatsApp / 在线客服', 'reason' => '给出可编辑的联系信息样例。'],
            ];
        }

        return [
            ['field' => 'title', 'sample' => $label, 'reason' => '明确共享组件的识别信息与用途。'],
        ];
    }

    /**
     * @param array<string, mixed> $task
     */
    private function composeConcretePageStoryGoal(array $task, string $pageType): string
    {
        $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
        $realtimeContent = \is_array($planContext['realtime_content'] ?? null) ? $planContext['realtime_content'] : [];
        $headline = \trim((string)($realtimeContent['headline'] ?? ''));
        $supporting = $this->collectTaskContentSamples($planContext, 1);
        $ctaLabels = $this->collectTaskCtaLabels($planContext, 1);
        if ($headline !== '') {
            $sentence = '直接实现“' . $headline . '”这组区块内容';
            if ($supporting !== []) {
                $sentence .= '，并配套“' . $supporting[0] . '”这段说明';
            }
            if ($ctaLabels !== []) {
                $sentence .= '，主动作使用“' . $ctaLabels[0] . '”';
            }

            return $sentence . '。';
        }

        $blockGoal = \trim((string)($planContext['block_goal'] ?? ''));
        $label = \trim((string)($task['label'] ?? $task['task_key'] ?? $pageType));
        return $blockGoal !== ''
            ? ($blockGoal . '，输出必须直接可上屏，而不是元提示。')
            : ($label . ' 需要产出访客可直接看到的内容，而不是描述怎么写内容。');

        if ($headline !== '') {
            $sentence = '直接实现“' . $headline . '”这组区块内容';
            if ($supporting !== []) {
                $sentence .= '，并配套“' . $supporting[0] . '”这段说明';
            }
            if ($ctaLabels !== []) {
                $sentence .= '，主动作使用“' . $ctaLabels[0] . '”';
            }

            return $sentence . '。';
        }

        $blockGoal = \trim((string)($planContext['block_goal'] ?? ''));
        $label = \trim((string)($task['label'] ?? $task['task_key'] ?? $pageType));
        return $blockGoal !== ''
            ? ($blockGoal . '，输出必须直接可上屏，而不是方向说明。')
            : ($label . ' 需要产出访客可直接看到的内容，而不是描述怎么写内容。');
    }

    /**
     * @param array<string, mixed> $task
     */
    private function composeConcretePageFillRule(array $task, string $pageType): string
    {
        $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
        $samples = $this->collectTaskContentSamples($planContext, 3);
        $ctaLabels = $this->collectTaskCtaLabels($planContext, 1);
        $parts = ['优先沿用第一阶段确认的标题、正文和字段样例'];
        if ($samples !== []) {
            $parts[] = '例如：' . \implode(' / ', $samples);
        }
        if ($ctaLabels !== []) {
            $parts[] = '主按钮保持“' . $ctaLabels[0] . '”';
        }
        $parts[] = '输出必须是访客可见内容，不能写方向型提示语或元说明';

        return \implode('；', $parts) . '。';

        $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
        $samples = $this->collectTaskContentSamples($planContext, 3);
        $ctaLabels = $this->collectTaskCtaLabels($planContext, 1);
        $parts = ['优先沿用第一阶段确认的标题、正文和字段样例'];
        if ($samples !== []) {
            $parts[] = '例如：' . \implode(' / ', $samples);
        }
        if ($ctaLabels !== []) {
            $parts[] = '主按钮保持“' . $ctaLabels[0] . '”';
        }
        $parts[] = '输出必须是访客可见内容，不能写“围绕…说明”“阶段一仅给方向”之类提示语';

        return \implode('；', $parts) . '。';
    }

    /**
     * @param array<string, mixed> $planContext
     * @return list<string>
     */
    private function collectTaskContentSamples(array $planContext, int $limit = 3): array
    {
        $samples = [];
        $realtimeContent = \is_array($planContext['realtime_content'] ?? null) ? $planContext['realtime_content'] : [];
        $headline = \trim((string)($realtimeContent['headline'] ?? ''));
        if ($headline !== '' && !$this->containsTaskBlueprintInstruction($headline)) {
            $samples[] = $headline;
        }
        foreach (\is_array($realtimeContent['supporting_copy'] ?? null) ? $realtimeContent['supporting_copy'] : [] as $value) {
            $text = \is_scalar($value) ? \trim((string)$value) : '';
            if ($text === '' || $this->containsTaskBlueprintInstruction($text)) {
                continue;
            }
            $samples[] = $text;
            if (\count($samples) >= $limit) {
                return \array_values(\array_unique($samples));
            }
        }
        foreach (\is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : [] as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $sample = \trim((string)($field['sample'] ?? ''));
            if ($sample === '' || $this->containsTaskBlueprintInstruction($sample)) {
                continue;
            }
            $samples[] = $sample;
            if (\count($samples) >= $limit) {
                break;
            }
        }

        return \array_values(\array_unique($samples));
    }

    /**
     * @param array<string, mixed> $planContext
     * @return list<string>
     */
    private function collectTaskCtaLabels(array $planContext, int $limit = 1): array
    {
        $labels = [];
        $realtimeContent = \is_array($planContext['realtime_content'] ?? null) ? $planContext['realtime_content'] : [];
        foreach (\is_array($realtimeContent['cta'] ?? null) ? $realtimeContent['cta'] : [] as $cta) {
            if (!\is_array($cta)) {
                continue;
            }
            $label = \trim((string)($cta['label'] ?? ''));
            if ($label === '' || $this->containsTaskBlueprintInstruction($label)) {
                continue;
            }
            $labels[] = $label;
            if (\count($labels) >= $limit) {
                break;
            }
        }

        return \array_values(\array_unique($labels));
    }

    private function containsTaskBlueprintInstruction(string $text): bool
    {
        $normalized = \mb_strtolower(\trim($text));
        if ($normalized === '') {
            return false;
        }

        if ($this->isInternalComponentReference($normalized)) {
            return true;
        }

        foreach (['阶段一', '蓝图', '方向', '围绕', '说明核心价值', 'block direction', 'list 2-4', 'specify heading font'] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isInternalComponentReference(string $text): bool
    {
        $value = \trim($text);
        if ($value === '') {
            return false;
        }

        return \preg_match('/^(page:[a-z0-9_]+:)?content\/[a-z0-9][a-z0-9_-]*$/i', $value) === 1
            || \preg_match('/^[a-z0-9_]+:(shared|header|footer|content)\/[a-z0-9][a-z0-9_-]*$/i', $value) === 1;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $fieldPlan
     */
    private function resolveVisibleSampleForStageTwoField(string $field, array $context, array $fieldPlan = []): string
    {
        $realtimeContent = \is_array($context['realtime_content'] ?? null) ? $context['realtime_content'] : [];
        if (\preg_match('/(link|href|url|target)/i', $field) === 1) {
            foreach (\is_array($realtimeContent['cta'] ?? null) ? $realtimeContent['cta'] : [] as $cta) {
                if (!\is_array($cta)) {
                    continue;
                }
                $target = \trim((string)($cta['target'] ?? $cta['href'] ?? $cta['url'] ?? ''));
                if ($target !== '' && !$this->isInternalComponentReference($target)) {
                    return $target;
                }
            }
            return '#start';
        }

        if (\preg_match('/(image|visual|media|asset)/i', $field) === 1) {
            foreach (\is_array($realtimeContent['media'] ?? null) ? $realtimeContent['media'] : [] as $media) {
                if (!\is_array($media)) {
                    continue;
                }
                $description = $this->firstNonEmptyString([
                    $media['description'] ?? null,
                    $media['rule'] ?? null,
                    $media['alt_text'] ?? null,
                ]);
                if ($description !== '' && !$this->isInternalComponentReference($description)) {
                    return $description;
                }
            }
        }

        if (\preg_match('/(description|subtitle|body|copy)/i', $field) === 1) {
            foreach (\is_array($realtimeContent['supporting_copy'] ?? null) ? $realtimeContent['supporting_copy'] : [] as $value) {
                $sample = \trim((string)$value);
                if ($sample !== '' && !$this->isInternalComponentReference($sample)) {
                    return $sample;
                }
            }
        }

        if (\preg_match('/(cta|button|action)/i', $field) === 1) {
            foreach (\is_array($realtimeContent['cta'] ?? null) ? $realtimeContent['cta'] : [] as $cta) {
                $label = \is_array($cta) ? \trim((string)($cta['label'] ?? '')) : \trim((string)$cta);
                if ($label !== '' && !$this->isInternalComponentReference($label)) {
                    return $label;
                }
            }
        }

        foreach (['headline', 'title', 'heading'] as $key) {
            $value = \trim((string)($realtimeContent[$key] ?? ''));
            if ($value !== '' && !$this->isInternalComponentReference($value)) {
                return $value;
            }
        }

        foreach (\is_array($realtimeContent['supporting_copy'] ?? null) ? $realtimeContent['supporting_copy'] : [] as $value) {
            $sample = \trim((string)$value);
            if ($sample !== '' && !$this->isInternalComponentReference($sample)) {
                return $sample;
            }
        }

        foreach ($fieldPlan as $row) {
            $sample = \trim((string)($row['sample'] ?? ''));
            if ($sample !== '' && !$this->isInternalComponentReference($sample)) {
                return $sample;
            }
        }

        return 'Concrete stage-1 content sample';
    }

    /**
     * 阶段二字段校验：识别「像写作要求/元说明」而非「真实拓写方案」的劣质输出。
     *
     * 返回 true 表示文本仍像教模型/作者「该怎么写」的空话；合格内容应是：在用户一句话需求基础上（经阶段一确认后），
     * 已展开为可执行的任务方案——具体导航文案、字段示例、CTA 文案、步骤与交付物，而非写作大纲。
     *
     * 下方 foreach 中的字符串是 **产出校验用敏感子串**（拦截常见套话），不是发给大模型的提示词正文。
     */
    private function isStageTwoMetaInstructionLike(string $text): bool
    {
        $normalized = \mb_strtolower(\trim($text));
        if ($normalized === '') {
            return false;
        }

        // 与「真实方案拓写」对立：常见 AI 套话/占位/教学法用语（命中则视为非具体方案）
        foreach ([
            '阶段一仅给方向', '蓝图方向', '说明核心价值', '标题围绕核心价值', '围绕区块目标', '说明要写什么',
            '围绕 hero', '围绕 header', '围绕 footer',
            'block direction', 'direction only', 'blueprint direction', 'stage one only gives direction', 'list 2-4', 'specify heading font',
            'write the title around', 'title around core value', 'write around', 'explain the core value', 'describe what should be written',
            '待补充', '待撰写', '详见后文', '突出卖点', '完善导航', '优化体验',
            '需要进一步', '建议后续', '应当突出', '旨在说明', '重点在于说明',
        ] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $pageTypes
     * @param list<array<string, mixed>> $sharedTasks
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @param array<string, mixed> $structured
     */
    private function buildStageTwoMarkdown(array $pageTypes, array $sharedTasks, array $pageTasks, array $structured): string
    {
        $lines = [];
        $lines[] = '# 第二阶段任务方案';
        $lines[] = '';
        $lines[] = '- 计划签名：' . (string)($structured['plan_signature'] ?? '');
        $lines[] = '- 站点：' . (string)($structured['virtual_theme_strategy']['site_display_name'] ?? '未命名站点');
        $lines[] = '- 页面类型：' . (\count($pageTypes) > 0 ? \implode('、', $pageTypes) : '未指定');
        $lines[] = '';
        $lines[] = '## 执行顺序';
        $orderIndex = 1;
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $lines[] = $orderIndex . '. ' . (string)($task['task_key'] ?? 'shared');
            $orderIndex++;
        }
        foreach ($pageTasks as $pageType => $tasks) {
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $lines[] = $orderIndex . '. ' . (string)($task['task_key'] ?? $pageType);
                $orderIndex++;
            }
        }
        $lines[] = '';
        $lines[] = '## 共享任务';
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $lines[] = '- ' . (string)($task['task_key'] ?? 'shared');
            $lines[] = '  - 目标：' . (string)($task['label'] ?? '');
            $sharedScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
            if ($sharedScript !== []) {
                $lines[] = '  - 脚本目标：' . (string)($sharedScript['story_goal'] ?? '');
                $lines[] = '  - 内容规则：' . (string)($sharedScript['content_fill_rule'] ?? '');
                $lines[] = '  - 第三阶段指令：' . (string)($sharedScript['stage3_directive'] ?? '');
            }
        }
        $lines[] = '';
        $lines[] = '## 页面任务';
        foreach ($pageTasks as $pageType => $tasks) {
            $lines[] = '### ' . $pageType;
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $lines[] = '- ' . (string)($task['task_key'] ?? '');
                $lines[] = '  - 区块：' . (string)($task['label'] ?? $task['section_code'] ?? '');
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                if ($planContext !== []) {
                    $lines[] = '  - 页面目标：' . (string)($planContext['page_goal'] ?? '');
                    $lines[] = '  - 区块目标：' . (string)($planContext['block_goal'] ?? '');
                }
                $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
                if ($taskScript !== []) {
                    $lines[] = '  - 脚本目标：' . (string)($taskScript['story_goal'] ?? '');
                    $lines[] = '  - 内容填充规则：' . (string)($taskScript['content_fill_rule'] ?? '');
                }
                $requirements = \is_array($taskScript['field_content_requirements'] ?? null)
                    ? $taskScript['field_content_requirements']
                    : (\is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : []);
                if ($requirements !== []) {
                    $lines[] = '  - 字段内容规划：';
                    foreach ($requirements as $req) {
                        if (!\is_array($req)) {
                            continue;
                        }
                        $field = (string)($req['field'] ?? '');
                        if ($field === '') {
                            continue;
                        }
                        $sample = (string)($req['sample'] ?? '');
                        $reason = (string)($req['reason'] ?? '');
                        $lines[] = '    - 字段 `' . $field . '`';
                        if ($sample !== '') {
                            $lines[] = '      - 示例值：' . $sample;
                        }
                        if ($reason !== '') {
                            $lines[] = '      - 规划理由：' . $reason;
                        }
                    }
                }
                $implementationContract = \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [];
                if (\is_array($implementationContract['acceptance'] ?? null) && $implementationContract['acceptance'] !== []) {
                    $lines[] = '  - 验收要求：';
                    foreach ($implementationContract['acceptance'] as $item) {
                        $itemText = \is_scalar($item) ? \trim((string)$item) : '';
                        if ($itemText !== '') {
                            $lines[] = '    - ' . $itemText;
                        }
                    }
                }
                if ($taskScript !== []) {
                    $lines[] = '  - 第三阶段执行指令：' . (string)($taskScript['stage3_directive'] ?? '');
                }
            }
            $lines[] = '';
        }

        return \implode("\n", $lines);

        $taskTree = \is_array($structured['task_tree'] ?? null) ? $structured['task_tree'] : [];
        $lines = [];
        $lines[] = '# 第二阶段任务方案';
        $lines[] = '';
        $lines[] = '- 计划签名：' . (string)($structured['plan_signature'] ?? '');
        $lines[] = '- 站点：' . (string)($structured['virtual_theme_strategy']['site_display_name'] ?? '未命名站点');
        $lines[] = '- 页面类型：' . (\count($pageTypes) > 0 ? \implode('、', $pageTypes) : '未指定');
        $lines[] = '';
        $lines[] = '## 任务树';
        $lines[] = $this->renderTaskTreeMarkdown($taskTree);
        $lines[] = '';
        $lines[] = '## 执行顺序';
        $lines[] = '1. shared:header';
        $lines[] = '2. shared:footer';
        $orderIndex = 3;
        foreach ($pageTasks as $pageType => $tasks) {
            foreach ($tasks as $task) {
                $lines[] = $orderIndex . '. ' . (string)($task['task_key'] ?? $pageType);
                $orderIndex++;
            }
        }
        $lines[] = '';
        $lines[] = '## 共享任务';
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $lines[] = '- ' . (string)($task['task_key'] ?? 'shared');
            $lines[] = '  - 目标：' . (string)($task['label'] ?? '');
            $sharedScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
            if ($sharedScript !== []) {
                $lines[] = '  - 脚本场景：' . (string)($sharedScript['scene'] ?? '');
                $lines[] = '  - 脚本目标：' . (string)($sharedScript['story_goal'] ?? '');
                $lines[] = '  - 内容规则：' . (string)($sharedScript['content_fill_rule'] ?? '');
                $lines[] = '  - 第三阶段执行指令：' . (string)($sharedScript['stage3_directive'] ?? '');
            }
        }
        $lines[] = '';
        $lines[] = '## 页面任务';
        foreach ($pageTasks as $pageType => $tasks) {
            $lines[] = '### ' . $pageType;
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $lines[] = '- ' . (string)($task['task_key'] ?? '');
                $lines[] = '  - 区块：' . (string)($task['label'] ?? $task['section_code'] ?? '');
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                if ($planContext !== []) {
                    $lines[] = '  - 页面目标：' . (string)($planContext['page_goal'] ?? '');
                    $lines[] = '  - 区块目标：' . (string)($planContext['block_goal'] ?? '');
                }
                $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
                if ($taskScript !== []) {
                    $lines[] = '  - 脚本场景：' . (string)($taskScript['scene'] ?? '');
                    $lines[] = '  - 脚本目标：' . (string)($taskScript['story_goal'] ?? '');
                    $lines[] = '  - 内容填充规则：' . (string)($taskScript['content_fill_rule'] ?? '');
                }
                $requirements = \is_array($taskScript['field_content_requirements'] ?? null)
                    ? $taskScript['field_content_requirements']
                    : (\is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : []);
                if ($requirements !== []) {
                    $lines[] = '  - 字段内容规划：';
                    foreach ($requirements as $req) {
                        if (!\is_array($req)) {
                            continue;
                        }
                        $field = (string)($req['field'] ?? '');
                        if ($field === '') {
                            continue;
                        }
                        $sample = (string)($req['sample'] ?? '');
                        $reason = (string)($req['reason'] ?? '');
                        $lines[] = '    - 字段 `' . $field . '`';
                        if ($sample !== '') {
                            $lines[] = '      - 示例值：' . $sample;
                        }
                        if ($reason !== '') {
                            $lines[] = '      - 规划理由：' . $reason;
                        }
                    }
                }
                $implementationContract = \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [];
                if (\is_array($implementationContract['acceptance'] ?? null) && $implementationContract['acceptance'] !== []) {
                    $lines[] = '  - 验收要求：';
                    foreach ($implementationContract['acceptance'] as $item) {
                        $itemText = \is_scalar($item) ? \trim((string)$item) : '';
                        if ($itemText !== '') {
                            $lines[] = '    - ' . $itemText;
                        }
                    }
                }
                if ($taskScript !== []) {
                    $lines[] = '  - 第三阶段执行指令：' . (string)($taskScript['stage3_directive'] ?? '');
                }
            }
            $lines[] = '';
        }

        return \implode("\n", $lines);
    }

    /**
     * @param list<string> $pageTypes
     * @param list<array<string, mixed>> $sharedTasks
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @param array<string, mixed> $structured
     */
    private function buildMarkdown(array $pageTypes, array $sharedTasks, array $pageTasks, array $structured): string
    {
        return $this->buildStageTwoMarkdown($pageTypes, $sharedTasks, $pageTasks, $structured);

        $taskTree = \is_array($structured['task_tree'] ?? null) ? $structured['task_tree'] : [];
        $lines = [];
        $lines[] = '# 第二阶段任务方案';
        $lines[] = '';
        $lines[] = '- 计划签名：' . (string)($structured['plan_signature'] ?? '');
        $lines[] = '- 站点：' . (string)($structured['virtual_theme_strategy']['site_display_name'] ?? '未命名站点');
        $lines[] = '- 页面类型：' . (\count($pageTypes) > 0 ? \implode('、', $pageTypes) : '未指定');
        $lines[] = '';
        $lines[] = '## 任务树';
        $lines[] = $this->renderTaskTreeMarkdown($taskTree);
        $lines[] = '';
        $lines[] = '## 执行顺序';
        $lines[] = '1. shared:header';
        $lines[] = '2. shared:footer';
        $orderIndex = 3;
        foreach ($pageTasks as $pageType => $tasks) {
            foreach ($tasks as $task) {
                $lines[] = $orderIndex . '. ' . (string)($task['task_key'] ?? $pageType);
                $orderIndex++;
            }
        }
        $lines[] = '';
        $lines[] = '## 共享任务';
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $lines[] = '- ' . (string)($task['task_key'] ?? 'shared');
            $lines[] = '  - 目标：' . (string)($task['label'] ?? '');
            $sharedScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
            if ($sharedScript !== []) {
                $lines[] = '  - 脚本场景：' . (string)($sharedScript['scene'] ?? '');
                $lines[] = '  - 脚本目标：' . (string)($sharedScript['story_goal'] ?? '');
                $lines[] = '  - 内容规则：' . (string)($sharedScript['content_fill_rule'] ?? '');
                $lines[] = '  - 第三阶段执行指令：' . (string)($sharedScript['stage3_directive'] ?? '');
            }
        }
        $lines[] = '';
        $lines[] = '## 页面任务';
        foreach ($pageTasks as $pageType => $tasks) {
            $lines[] = '### ' . $pageType;
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $lines[] = '- ' . (string)($task['task_key'] ?? '');
                $lines[] = '  - 区块：' . (string)($task['label'] ?? $task['section_code'] ?? '');
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                if ($planContext !== []) {
                    $lines[] = '  - 页面目标：' . (string)($planContext['page_goal'] ?? '');
                    $lines[] = '  - 区块目标：' . (string)($planContext['block_goal'] ?? '');
                }
                $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
                if ($taskScript !== []) {
                    $lines[] = '  - 脚本场景：' . (string)($taskScript['scene'] ?? '');
                    $lines[] = '  - 脚本目标：' . (string)($taskScript['story_goal'] ?? '');
                    $lines[] = '  - 内容填充规则：' . (string)($taskScript['content_fill_rule'] ?? '');
                }
                $requirements = \is_array($taskScript['field_content_requirements'] ?? null)
                    ? $taskScript['field_content_requirements']
                    : (\is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : []);
                if ($requirements !== []) {
                    $lines[] = '  - 字段内容规划：';
                    foreach ($requirements as $req) {
                        if (!\is_array($req)) {
                            continue;
                        }
                        $field = (string)($req['field'] ?? '');
                        if ($field === '') {
                            continue;
                        }
                        $sample = (string)($req['sample'] ?? '');
                        $reason = (string)($req['reason'] ?? '');
                        $lines[] = '    - 字段 `' . $field . '`';
                        if ($sample !== '') {
                            $lines[] = '      - 示例值：' . $sample;
                        }
                        if ($reason !== '') {
                            $lines[] = '      - 规划理由：' . $reason;
                        }
                    }
                }
                $implementationContract = \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [];
                if (\is_array($implementationContract['acceptance'] ?? null) && $implementationContract['acceptance'] !== []) {
                    $lines[] = '  - 验收要求：';
                    foreach ($implementationContract['acceptance'] as $item) {
                        $itemText = \is_scalar($item) ? \trim((string)$item) : '';
                        if ($itemText !== '') {
                            $lines[] = '    - ' . $itemText;
                        }
                    }
                }
                if ($taskScript !== []) {
                    $lines[] = '  - 第三阶段执行指令：' . (string)($taskScript['stage3_directive'] ?? '');
                }
            }
            $lines[] = '';
        }

        return \implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildSignature(array $payload): string
    {
        return \sha1((string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * 递归统计任务树节点数。
     *
     * @param array<string, mixed> $taskTree
     */
    private function countTaskTreeNodes(array $taskTree): int
    {
        $count = 0;
        foreach ($taskTree as $key => $node) {
            if ($key === 'root') {
                $count++;
                continue;
            }
            if (\is_array($node)) {
                if (\array_is_list($node)) {
                    foreach ($node as $child) {
                        if (\is_array($child)) {
                            $count++;
                            $count += $this->countTaskTreeNodes($child);
                        }
                    }
                } else {
                    $count++;
                    $count += $this->countTaskTreeNodes($node);
                }
            }
        }
        return $count;
    }

    /**
     * 渲染任务树为 Markdown。
     *
     * @param array<string, mixed> $taskTree
     */
    private function renderTaskTreeMarkdown(array $taskTree): string
    {
        $lines = [];
        if (\is_array($taskTree['root'] ?? null)) {
            $root = $taskTree['root'];
            $lines[] = '- root: ' . (string)($root['task_key'] ?? 'site:virtual_theme');
            $lines[] = '  - completion: ' . (string)($root['completion_rule'] ?? '');
        }
        foreach (['shared', 'pages'] as $groupKey) {
            $nodes = $taskTree[$groupKey] ?? [];
            $lines[] = '- ' . $groupKey;
            if (!\is_array($nodes)) {
                continue;
            }
            foreach ($nodes as $pageKey => $pageNodes) {
                if ($groupKey === 'pages') {
                    $lines[] = '  - ' . (string)$pageKey;
                }
                if (!\is_array($pageNodes)) {
                    continue;
                }
                foreach ($pageNodes as $node) {
                    if (!\is_array($node)) {
                        continue;
                    }
                    $lines[] = '    - ' . (string)($node['task_key'] ?? ($node['node_key'] ?? 'task')) . ' [' . (string)($node['status'] ?? 'pending') . ']';
                    $lines[] = '      - parent: ' . (string)($node['parent_key'] ?? '');
                    $lines[] = '      - completion: ' . (string)($node['completion_rule'] ?? '');
                }
            }
        }
        return $lines === [] ? '-（空）' : \implode("\n", $lines);
    }

    /**
     * 构建任务运行上下文，供并发 SSE / 会话隔离使用。
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolveConfirmedStageOnePlanBook(array $scope): array
    {
        $planWorkbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
        $confirmedWorkbench = \is_array($planWorkbench['confirmed'] ?? null) ? $planWorkbench['confirmed'] : [];
        $candidate = $confirmedWorkbench['plan_book']['structured'] ?? null;
        if (\is_array($candidate) && $this->looksLikeConfirmedStageOnePlanBook($candidate)) {
            return $candidate;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $planBook
     */
    private function looksLikeConfirmedStageOnePlanBook(array $planBook): bool
    {
        return \is_array($planBook['pages'] ?? null)
            || \is_array($planBook['shared_blocks'] ?? null)
            || (string)($planBook['source'] ?? '') === 'stage1.block_tree';
    }

    /**
     * @param array<string, mixed> $planBook
     * @return array<string, mixed>
     */
    private function compactConfirmedPlanBookForPrompt(array $planBook, string $pageType = ''): array
    {
        if ($planBook === []) {
            return [];
        }

        $compact = [
            'source' => (string)($planBook['source'] ?? 'stage1.block_tree'),
            'source_signature' => (string)($planBook['source_signature'] ?? ''),
            'context_hash' => (string)($planBook['context_hash'] ?? ''),
            'plan_locale' => (string)($planBook['plan_locale'] ?? ''),
            'theme_context_hash' => (string)($planBook['theme_context_hash'] ?? ''),
            'shared_context_hash' => (string)($planBook['shared_context_hash'] ?? ''),
            'counts' => \is_array($planBook['counts'] ?? null) ? $planBook['counts'] : [],
            'shared_blocks' => [],
            'pages' => [],
        ];

        foreach (\is_array($planBook['shared_blocks'] ?? null) ? $planBook['shared_blocks'] : [] as $block) {
            if (\is_array($block)) {
                $compact['shared_blocks'][] = $this->compactStageOnePlanBookBlock($block, true);
            }
        }

        if ($pageType === '__shared__') {
            return $compact;
        }

        foreach (\is_array($planBook['pages'] ?? null) ? $planBook['pages'] : [] as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageKey = (string)($page['page_key'] ?? $key);
            if ($pageType !== '' && $pageKey !== $pageType) {
                continue;
            }
            $blocks = [];
            foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                if (\is_array($block)) {
                    $blocks[] = $this->compactStageOnePlanBookBlock($block, false);
                }
            }
            $compact['pages'][$pageKey] = [
                'page_key' => $pageKey,
                'page_label' => (string)($page['page_label'] ?? $pageKey),
                'page_goal' => (string)($page['page_goal'] ?? ''),
                'theme_alignment_summary' => (string)($page['theme_alignment_summary'] ?? ''),
                'shared_context_hash' => (string)($page['shared_context_hash'] ?? ''),
                'theme_context_hash' => (string)($page['theme_context_hash'] ?? ''),
                'page_context_hash' => (string)($page['page_context_hash'] ?? ''),
                'blocks' => $blocks,
            ];
        }

        return $compact;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function compactStageOnePlanBookBlock(array $block, bool $shared): array
    {
        return [
            'task_key' => (string)($block['task_key'] ?? ''),
            'block_key' => (string)($block['block_key'] ?? ''),
            'source_block_key' => (string)($block['source_block_key'] ?? ''),
            'scope' => $shared ? 'shared' : 'page',
            'component' => (string)($block['component'] ?? ''),
            'component_kind' => (string)($block['component_kind'] ?? ''),
            'sort_order' => (int)($block['sort_order'] ?? 0),
            'title' => (string)($block['title'] ?? ''),
            'goal' => (string)($block['goal'] ?? ''),
            'implementation_detail' => (string)($block['implementation_detail'] ?? ''),
            'realtime_content' => \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [],
            'reason' => (string)($block['reason'] ?? ''),
            'completion_rule' => (string)($block['completion_rule'] ?? ''),
            'editable_fields' => \array_values(\array_filter(\array_map('strval', \is_array($block['editable_fields'] ?? null) ? $block['editable_fields'] : []))),
            'style_direction' => (string)($block['style_direction'] ?? ''),
            'responsive_rule' => (string)($block['responsive_rule'] ?? ''),
            'context_hash' => (string)($block['context_hash'] ?? ''),
        ];
    }

    /**
     * @param list<string> $fallbackPageTypes
     * @param array<string, mixed> $planBook
     * @return list<string>
     */
    private function resolveStageTwoPageTypes(array $fallbackPageTypes, array $planBook): array
    {
        $pageTypes = [];
        foreach (\is_array($planBook['pages'] ?? null) ? $planBook['pages'] : [] as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageKey = \trim((string)($page['page_key'] ?? $key));
            if ($pageKey !== '') {
                $pageTypes[] = $pageKey;
            }
        }

        return $pageTypes !== [] ? \array_values(\array_unique($pageTypes)) : $fallbackPageTypes;
    }

    /**
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $planBook
     * @return list<array<string, mixed>>
     */
    private function resolveStageTwoBuildTasks(array $buildBlueprint, array $planBook): array
    {
        $tasks = [];
        foreach (\is_array($planBook['shared_blocks'] ?? null) ? $planBook['shared_blocks'] : [] as $block) {
            if (\is_array($block)) {
                $tasks[] = $this->buildStageTwoSharedTaskFromPlanBookBlock($block);
            }
        }
        foreach (\is_array($planBook['pages'] ?? null) ? $planBook['pages'] : [] as $pageKey => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $resolvedPageKey = \trim((string)($page['page_key'] ?? $pageKey));
            foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                if (\is_array($block)) {
                    $tasks[] = $this->buildStageTwoPageTaskFromPlanBookBlock($resolvedPageKey, $block);
                }
            }
        }

        return $tasks;
    }

    /**
     * Ensure stage-2 task planning fans out at least one task per confirmed stage-1 page block.
     *
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @param array<string, array<string, mixed>> $pagePlans
     * @param array<string, array<string, array<string, mixed>>> $blockPlanMatrix
     * @return array<string, list<array<string, mixed>>>
     */
    private function ensureStageTwoBlockTaskPlanFanoutTasks(array $pageTasks, array $pagePlans, array $blockPlanMatrix): array
    {
        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $pageType = (string)$pageType;
            $blocks = \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [];
            if ($pageType === '' || $blocks === []) {
                continue;
            }

            $tasks = \is_array($pageTasks[$pageType] ?? null) ? \array_values($pageTasks[$pageType]) : [];
            $existingBlockKeys = [];
            $nextSortOrder = 100;
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $blockCode = $this->resolveTaskBlockCodeFromPlan($task, $pageType, $blockPlanMatrix);
                if ($blockCode !== '') {
                    $existingBlockKeys[$blockCode] = true;
                }
                $nextSortOrder = \max($nextSortOrder, (int)($task['sort_order'] ?? 0) + 10);
            }

            foreach ($blocks as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? $block['component_kind'] ?? ''));
                if ($blockKey === '' || isset($existingBlockKeys[$blockKey])) {
                    continue;
                }
                $tasks[] = $this->buildStageTwoPageTaskFromPlanBlock($pageType, $block, $nextSortOrder);
                $existingBlockKeys[$blockKey] = true;
                $nextSortOrder += 10;
            }

            \usort($tasks, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
            $pageTasks[$pageType] = \array_values($tasks);
        }

        return $pageTasks;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildStageTwoPageTaskFromPlanBlock(string $pageType, array $block, int $fallbackSortOrder): array
    {
        $blockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? $block['component_kind'] ?? 'block'));
        $sectionCode = \trim((string)($block['section_code'] ?? $block['component_kind'] ?? $blockKey));
        $label = $this->firstNonEmptyString([
            $block['title'] ?? null,
            $block['section_name'] ?? null,
            $block['label'] ?? null,
            $blockKey,
        ]);

        return [
            'task_key' => 'page:' . $pageType . ':' . $blockKey,
            'task_type' => 'page_section',
            'scope_key' => 'page_sections.' . $pageType . '.' . $blockKey,
            'group_key' => $pageType,
            'page_type' => $pageType,
            'region' => 'content',
            'section_code' => $sectionCode !== '' ? $sectionCode : $blockKey,
            'section_key' => $blockKey,
            'block_key' => $blockKey,
            'label' => $label !== '' ? $label : $blockKey,
            'sort_order' => (int)($block['sort_order'] ?? $fallbackSortOrder),
            'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($block['dependencies'] ?? null) ? $block['dependencies'] : []))),
            'result_ref' => \is_array($block['result_ref'] ?? null) ? $block['result_ref'] : [
                'source' => 'stage1.block_tree',
                'scope_path' => 'pages.' . $pageType . '.blocks.' . $blockKey,
                'context_hash' => (string)($block['context_hash'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildStageTwoSharedTaskFromPlanBookBlock(array $block): array
    {
        $component = \trim((string)($block['component'] ?? ''));
        $taskKey = \trim((string)($block['task_key'] ?? $block['block_key'] ?? ''));
        if ($taskKey === '') {
            $taskKey = 'shared:' . ($component !== '' ? $component : 'block');
        }
        if ($component === '' && \str_starts_with($taskKey, 'shared:')) {
            $component = \trim(\substr($taskKey, 7));
        }

        return [
            'task_key' => $taskKey,
            'task_type' => 'shared_component',
            'scope_key' => 'shared_components.' . ($component !== '' ? $component : $taskKey),
            'group_key' => 'shared',
            'page_type' => '',
            'region' => $component,
            'label' => (string)($block['title'] ?? ($component !== '' ? $component : $taskKey)),
            'sort_order' => (int)($block['sort_order'] ?? 0),
            'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($block['dependencies'] ?? null) ? $block['dependencies'] : []))),
            'result_ref' => [
                'source' => 'plan_workbench.confirmed.plan_book.structured',
                'scope_path' => 'shared_blocks.' . $taskKey,
                'context_hash' => (string)($block['context_hash'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildStageTwoPageTaskFromPlanBookBlock(string $pageKey, array $block): array
    {
        $sourceBlockKey = $this->resolvePlanBookSourceBlockKey($block);
        $taskKey = \trim((string)($block['task_key'] ?? ''));
        if ($taskKey === '') {
            $taskKey = 'page:' . $pageKey . ':' . $sourceBlockKey;
        }
        $sectionCode = \trim((string)($block['component_kind'] ?? $block['title'] ?? $sourceBlockKey));

        return [
            'task_key' => $taskKey,
            'task_type' => 'page_section',
            'scope_key' => 'page_sections.' . $pageKey . '.' . $sourceBlockKey,
            'group_key' => $pageKey,
            'page_type' => $pageKey,
            'region' => 'content',
            'section_code' => $sectionCode !== '' ? $sectionCode : $sourceBlockKey,
            'section_key' => $sourceBlockKey,
            'block_key' => $sourceBlockKey,
            'label' => (string)($block['title'] ?? $sourceBlockKey),
            'sort_order' => (int)($block['sort_order'] ?? 0),
            'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($block['dependencies'] ?? null) ? $block['dependencies'] : []))),
            'result_ref' => [
                'source' => 'plan_workbench.confirmed.plan_book.structured',
                'scope_path' => 'pages.' . $pageKey . '.blocks.' . $sourceBlockKey,
                'context_hash' => (string)($block['context_hash'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $executionBlueprint
     * @param array<string, mixed> $planBook
     * @return array<string, array<string, mixed>>
     */
    private function resolveStageTwoPagePlans(array $executionBlueprint, array $planBook): array
    {
        $pages = [];
        foreach (\is_array($planBook['pages'] ?? null) ? $planBook['pages'] : [] as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageKey = \trim((string)($page['page_key'] ?? $key));
            if ($pageKey === '') {
                continue;
            }
            $blocks = [];
            foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                if (\is_array($block)) {
                    $blocks[] = $this->buildStageTwoPagePlanBlockFromPlanBookBlock($pageKey, $block);
                }
            }
            $pages[$pageKey] = [
                'page_label' => (string)($page['page_label'] ?? $pageKey),
                'page_goal' => (string)($page['page_goal'] ?? ''),
                'page_status' => (string)($page['page_status'] ?? 'done'),
                'theme_alignment_summary' => (string)($page['theme_alignment_summary'] ?? ''),
                'shared_context_hash' => (string)($page['shared_context_hash'] ?? $planBook['shared_context_hash'] ?? ''),
                'theme_context_hash' => (string)($page['theme_context_hash'] ?? $planBook['theme_context_hash'] ?? ''),
                'page_context_hash' => (string)($page['page_context_hash'] ?? ''),
                'blocks' => $blocks,
            ];
        }

        if ($pages !== []) {
            return $pages;
        }

        return \is_array($executionBlueprint['pages'] ?? null) ? $executionBlueprint['pages'] : [];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildStageTwoPagePlanBlockFromPlanBookBlock(string $pageKey, array $block): array
    {
        $sourceBlockKey = $this->resolvePlanBookSourceBlockKey($block);
        $sectionCode = \trim((string)($block['component_kind'] ?? $block['title'] ?? $sourceBlockKey));
        $resultRef = [
            'source' => 'plan_workbench.confirmed.plan_book.structured',
            'scope_path' => 'pages.' . $pageKey . '.blocks.' . $sourceBlockKey,
            'context_hash' => (string)($block['context_hash'] ?? ''),
        ];

        return [
            'block_key' => $sourceBlockKey,
            'section_code' => $sectionCode !== '' ? $sectionCode : $sourceBlockKey,
            'component_kind' => (string)($block['component_kind'] ?? ''),
            'sort_order' => (int)($block['sort_order'] ?? 0),
            'goal' => (string)($block['goal'] ?? ''),
            'why' => (string)($block['reason'] ?? ''),
            'implementation_detail' => (string)($block['implementation_detail'] ?? ''),
            'realtime_content' => \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [],
            'editable_fields' => \array_values(\array_filter(\array_map('strval', \is_array($block['editable_fields'] ?? null) ? $block['editable_fields'] : []))),
            'completion_rule' => (string)($block['completion_rule'] ?? ''),
            'content_brief' => \is_array($block['content_source'] ?? null) ? ['content_source' => $block['content_source']] : [],
            'field_plan' => $this->buildStageTwoFieldPlanFromPlanBookBlock($block),
            'result_ref' => $resultRef,
            'style_direction' => (string)($block['style_direction'] ?? ''),
            'design_tags' => \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : [],
            'responsive_rule' => (string)($block['responsive_rule'] ?? ''),
            'seo_brief' => \is_array($block['seo_brief'] ?? null) ? $block['seo_brief'] : [],
            'context_hash' => (string)($block['context_hash'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return list<array<string, mixed>>
     */
    private function buildStageTwoFieldPlanFromPlanBookBlock(array $block): array
    {
        if (\is_array($block['field_plan'] ?? null) && $block['field_plan'] !== []) {
            $fieldPlan = \array_values(\array_filter($block['field_plan'], static fn($row): bool => \is_array($row)));
            foreach ($fieldPlan as $index => $row) {
                $sample = \trim((string)($row['sample'] ?? ''));
                if ($sample === '' || !$this->isInternalComponentReference($sample)) {
                    continue;
                }
                $fieldPlan[$index]['sample'] = $this->resolveVisibleSampleForStageTwoField(
                    \trim((string)($row['field'] ?? '')),
                    $block,
                    $fieldPlan
                );
            }

            return $fieldPlan;
        }

        $fields = \array_values(\array_filter(\array_map('strval', \is_array($block['editable_fields'] ?? null) ? $block['editable_fields'] : [])));
        $realtimeContent = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        $fieldPlan = [];
        foreach ($fields as $field) {
            $sample = '';
            if (isset($realtimeContent[$field]) && \is_scalar($realtimeContent[$field])) {
                $sample = \trim((string)$realtimeContent[$field]);
            }
            if ($sample === '' || $this->isInternalComponentReference($sample)) {
                $sample = $this->resolveVisibleSampleForStageTwoField($field, $block, $fieldPlan);
            }
            $fieldPlan[] = [
                'field' => $field,
                'sample' => $sample !== '' && !$this->isInternalComponentReference($sample) ? $sample : $this->resolveVisibleSampleForStageTwoField($field, $block, $fieldPlan),
                'reason' => 'Inherited from confirmed stage-1 block content and editable field contract.',
            ];
        }

        return $fieldPlan;
    }

    /**
     * @param array<string, mixed> $executionBlueprint
     * @param array<string, mixed> $planBook
     * @return array<string, array<string, mixed>>
     */
    private function resolveStageTwoSharedComponentPlans(array $executionBlueprint, array $planBook): array
    {
        $shared = [];
        foreach (\is_array($planBook['shared_blocks'] ?? null) ? $planBook['shared_blocks'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $component = \trim((string)($block['component'] ?? ''));
            $taskKey = \trim((string)($block['task_key'] ?? $block['block_key'] ?? ''));
            if ($component === '' && \str_starts_with($taskKey, 'shared:')) {
                $component = \trim(\substr($taskKey, 7));
            }
            if ($component === '') {
                $component = $taskKey !== '' ? $taskKey : 'shared';
            }
            $shared[$component] = [
                'task_key' => $taskKey !== '' ? $taskKey : 'shared:' . $component,
                'component' => (string)($block['title'] ?? $component),
                'goal' => (string)($block['goal'] ?? ''),
                'sort_order' => (int)($block['sort_order'] ?? 0),
                'realtime_content' => \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [],
                'reason' => (string)($block['reason'] ?? ''),
                'completion_rule' => (string)($block['completion_rule'] ?? ''),
                'editable_fields' => \is_array($block['editable_fields'] ?? null) ? $block['editable_fields'] : [],
                'style_direction' => (string)($block['style_direction'] ?? ''),
                'responsive_rule' => (string)($block['responsive_rule'] ?? ''),
                'context_hash' => (string)($block['context_hash'] ?? ''),
            ];
        }

        if ($shared !== []) {
            return $shared;
        }

        return \is_array($executionBlueprint['shared_components'] ?? null) ? $executionBlueprint['shared_components'] : [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $executionBlueprint
     * @param array<string, mixed> $planBook
     */
    private function resolveStageTwoSourceSignature(array $scope, array $executionBlueprint, array $planBook): string
    {
        foreach ([
            $planBook['source_signature'] ?? null,
            $scope['execution_blueprint_confirmed_signature'] ?? null,
            $executionBlueprint['signature'] ?? null,
            $planBook['context_hash'] ?? null,
        ] as $candidate) {
            $signature = \trim((string)$candidate);
            if ($signature !== '') {
                return $signature;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolvePlanBookSourceBlockKey(array $block): string
    {
        foreach (['source_block_key', 'block_key', 'component_kind', 'title'] as $key) {
            $value = \trim((string)($block[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($key === 'block_key' && \str_contains($value, ':')) {
                $parts = \explode(':', $value);
                $tail = \trim((string)\end($parts));
                if ($tail !== '') {
                    return $tail;
                }
            }
            return $value;
        }

        return 'block';
    }

    private function buildTaskRuntimeContext(array $scope, array $task, string $sessionScope, string $parentTaskKey, string $sseScope, array $stage2ContextSnapshot = []): array
    {
        $taskKey = \trim((string)($task['task_key'] ?? ''));
        return [
            'session_id' => $sessionScope,
            'task_session_id' => $sessionScope !== '' && $taskKey !== '' ? \sha1($sessionScope . ':' . $taskKey) : '',
            'task_key' => $taskKey,
            'parent_task_key' => $parentTaskKey,
            'prompt_mode' => 'task_plan',
            'prompt_template_key' => 'stage2_task_execute',
            'round' => (int)($scope['task_plan_round'] ?? 1),
            'source_signature' => (string)($stage2ContextSnapshot['source_confirmed_signature'] ?? $scope['execution_blueprint_confirmed_signature'] ?? ''),
            'content_locale' => $this->resolveStageTwoContentLocale($scope),
            'plan_locale' => \trim((string)($scope['plan_locale'] ?? '')),
            'target_scope' => (string)($task['page_type'] ?? ''),
            'sse_scope' => $sseScope,
            'stream_session_key' => $sessionScope !== '' && $taskKey !== '' ? ($sessionScope . ':' . $taskKey) : $taskKey,
            'theme_context_snapshot' => \is_array($stage2ContextSnapshot['theme_context_snapshot'] ?? null) ? $stage2ContextSnapshot['theme_context_snapshot'] : [],
            'stage2_context_snapshot' => $stage2ContextSnapshot,
            'stage2_context_hash' => (string)($stage2ContextSnapshot['context_hash'] ?? ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $sharedTasks
     * @param array<string, array<string, mixed>> $pagePlans
     * @param array<string, mixed> $planStructured
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildStageTwoContextSnapshot(
        array $themeContextSnapshot,
        array $sharedPromptContext,
        array $sharedTasks,
        array $pagePlans,
        array $planStructured,
        array $scope,
        string $sourceSignature,
        array $confirmedPlanBook = []
    ): array {
        $sharedTaskSummary = [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $sharedTaskSummary[$taskKey] = [
                'task_key' => $taskKey,
                'label' => (string)($task['label'] ?? $taskKey),
                'region' => (string)($task['region'] ?? \str_replace('shared:', '', $taskKey)),
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
            ];
        }

        $pageTone = [];
        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $pageTone[(string)$pageType] = [
                'page_goal' => (string)($pagePlan['page_goal'] ?? ''),
                'content_tone' => (string)($pagePlan['content_tone'] ?? $pagePlan['tone'] ?? ''),
                'seo_brief' => \is_array($pagePlan['seo_brief'] ?? null) ? $pagePlan['seo_brief'] : [],
            ];
        }

        $snapshot = [
            'source_confirmed_signature' => $sourceSignature,
            'confirmed_stage1_source' => $confirmedPlanBook !== [] ? 'plan_workbench.confirmed.plan_book.structured' : 'execution_blueprint',
            'confirmed_plan_book_context_hash' => (string)($confirmedPlanBook['context_hash'] ?? ''),
            'content_locale' => $this->resolveStageTwoContentLocale($scope),
            'plan_locale' => \trim((string)($scope['plan_locale'] ?? '')),
            'confirmed_block_tree' => $this->compactConfirmedPlanBookForPrompt($confirmedPlanBook),
            'theme_context_snapshot' => $themeContextSnapshot,
            'shared_prompt_context' => $sharedPromptContext,
            'shared_task_summary' => $sharedTaskSummary,
            'page_content_tone' => $pageTone,
            'prompt_version' => 'stage2-block-task-plan-v2',
            'anti_hardcode_rules' => \is_array($planStructured['anti_hardcode_rules'] ?? null)
                ? $planStructured['anti_hardcode_rules']
                : (\is_array($scope['anti_hardcode_rules'] ?? null) ? $scope['anti_hardcode_rules'] : []),
        ];
        $snapshot['context_hash'] = \sha1((string)\json_encode($snapshot, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        return $snapshot;
    }

    /**
     * @param list<array<string, mixed>> $sharedTasks
     * @param array<string, array<string, mixed>> $sharedComponentPlans
     * @param array<string, mixed> $sharedPromptContext
     * @param array<string, mixed> $themeContextSnapshot
     * @return list<array<string, mixed>>
     */
    private function ensureStageTwoSharedTasks(
        array $sharedTasks,
        array $sharedComponentPlans,
        array $sharedPromptContext,
        array $themeContextSnapshot
    ): array {
        $byKey = [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey !== '') {
                $byKey[$taskKey] = $task;
            }
        }

        foreach (['header', 'footer'] as $index => $region) {
            $taskKey = 'shared:' . $region;
            $plan = \is_array($sharedComponentPlans[$region] ?? null) ? $sharedComponentPlans[$region] : [];
            $label = $region === 'header' ? 'Header' : 'Footer';
            $goal = \trim((string)($plan['goal'] ?? $sharedPromptContext[$region . '_plan']['goal'] ?? ''));
            if ($goal === '') {
                $goal = $region === 'header'
                    ? 'Build the global header with brand identity, navigation, and primary CTA from the confirmed stage-1 plan.'
                    : 'Build the global footer with grouped links, support/trust content, and compliance paths from the confirmed stage-1 plan.';
            }
            $realtimeContent = \is_array($plan['realtime_content'] ?? null) ? $plan['realtime_content'] : [];
            $styleDirection = \trim((string)($plan['style_direction'] ?? $themeContextSnapshot['visual_direction']['visual_tone'] ?? $themeContextSnapshot['content_tone'] ?? ''));
            $fieldRequirements = $this->buildSharedTaskFieldRequirementsFromPlan($region, $plan, $sharedPromptContext);
            $baseTask = [
                'task_key' => $taskKey,
                'task_type' => 'shared_component',
                'group_key' => 'shared',
                'page_type' => '',
                'page_key' => '',
                'block_key' => $taskKey,
                'section_code' => $taskKey,
                'region' => $region,
                'label' => (string)($plan['component'] ?? $label),
                'sort_order' => (int)($plan['sort_order'] ?? (($index + 1) * 10)),
                'dependencies' => [],
                'status' => 'done',
                'attempt_no' => 1,
                'result_ref' => \is_array($plan['result_ref'] ?? null) ? $plan['result_ref'] : ['scope' => 'shared_components.' . $region],
                'plan_context' => [
                    'source_stage' => 'stage_1',
                    'scope' => 'shared',
                    'stage1_theme_summary' => (string)($themeContextSnapshot['theme_purpose'] ?? $themeContextSnapshot['site_positioning'] ?? ''),
                    'stage1_block_goal' => $goal,
                    'stage1_block_content' => \json_encode($realtimeContent, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '',
                    'stage1_style_direction' => $styleDirection,
                    'stage1_shared_context' => $sharedPromptContext,
                    'completion_rule' => (string)($plan['completion_rule'] ?? ''),
                    'editable_fields' => \is_array($plan['editable_fields'] ?? null) ? $plan['editable_fields'] : [],
                    'realtime_content' => $realtimeContent,
                ],
                'task_script' => [
                    'scene' => $taskKey,
                    'story_goal' => $goal,
                    'content_fill_rule' => $this->buildSharedTaskContentFillRule($region, $fieldRequirements),
                    'stage3_directive' => 'Generate the shared ' . $region . ' component from this task, the confirmed stage-1 theme, and the shared prompt context. Do not invent unrelated navigation or footer content.',
                    'component_type' => $region === 'header' ? 'SharedHeader' : 'SharedFooter',
                    'technical_steps' => [
                        'Use the confirmed stage-1 brand/navigation/footer data.',
                        'Apply the confirmed theme palette and typography.',
                        'Keep desktop and mobile interaction states explicit.',
                    ],
                    'data_contract' => [
                        'required_data' => \array_values(\array_map(static fn(array $row): string => (string)($row['field'] ?? ''), $fieldRequirements)),
                    ],
                    'responsive_contract' => $region === 'header' ? 'Desktop horizontal nav; mobile collapses without hiding primary CTA.' : 'Desktop grouped columns; mobile stacked link groups with readable spacing.',
                    'accessibility_contract' => ['Keyboard reachable links', 'Visible focus states', 'Readable contrast'],
                    'asset_requirements' => ['Use inline SVG/CSS visuals for logo or trust icons unless a verified brand asset URL exists.'],
                    'validation_points' => ['No missing nav labels', 'No placeholder links', 'Theme colors visible', 'Mobile layout usable'],
                    'completion_rule' => (string)($plan['completion_rule'] ?? ($region . ' component can be reused across every generated page.')),
                    'field_content_requirements' => $fieldRequirements,
                ],
                'implementation_contract' => [
                    'delivery_rule' => 'Implement only the shared ' . $region . ' component described by this task.',
                    'implementation_detail' => (string)($plan['implementation_detail'] ?? ''),
                    'realtime_output' => $realtimeContent,
                    'completion_rule' => (string)($plan['completion_rule'] ?? ''),
                    'acceptance' => [
                        'Uses confirmed stage-1 shared context and theme.',
                        'Contains no placeholder navigation/footer copy.',
                        'Can be reused by all page tasks.',
                    ],
                ],
                'block_task' => [
                    'schema_version' => self::BLOCK_TASK_SCHEMA_VERSION,
                    'task_goal' => $goal,
                    'meta_fields' => $fieldRequirements,
                    'content_plan' => [
                        'story_goal' => $goal,
                        'content_fill_rule' => $this->buildSharedTaskContentFillRule($region, $fieldRequirements),
                        'field_content_requirements' => $fieldRequirements,
                        'content_copy' => $fieldRequirements,
                    ],
                    'style_plan' => [
                        'color' => 'Use confirmed stage-1 palette for background, text, active states, and CTA.',
                        'font' => 'Use confirmed stage-1 typography for logo/title, nav labels, and support text.',
                        'spacing' => 'Use confirmed stage-1 spacing/radius for header/footer density.',
                        'responsive' => $region === 'header' ? 'Mobile navigation remains reachable and CTA stays visible.' : 'Footer groups stack cleanly on mobile.',
                    ],
                    'planning_reason' => (string)($plan['reason'] ?? $goal),
                    'sort_order' => (int)($plan['sort_order'] ?? (($index + 1) * 10)),
                ],
            ];
            $byKey[$taskKey] = \array_replace_recursive($baseTask, \is_array($byKey[$taskKey] ?? null) ? $byKey[$taskKey] : []);
        }

        \uasort($byKey, static fn(array $a, array $b): int => ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0)));
        return \array_values($byKey);
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $sharedPromptContext
     * @return list<array<string, mixed>>
     */
    private function buildSharedTaskFieldRequirementsFromPlan(string $region, array $plan, array $sharedPromptContext): array
    {
        $rows = [];
        foreach (\is_array($plan['field_plan'] ?? null) ? $plan['field_plan'] : [] as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }
        if ($rows !== []) {
            return $rows;
        }
        if ($region === 'header') {
            $items = \is_array($sharedPromptContext['header_items'] ?? null) ? $sharedPromptContext['header_items'] : [];
            return [
                ['field' => 'brand_name', 'sample' => (string)($sharedPromptContext['site_display_name'] ?? 'Site'), 'reason' => 'Header brand identity.'],
                ['field' => 'navigation_items', 'sample' => \json_encode($items, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]', 'reason' => 'Confirmed stage-1 navigation items.'],
                ['field' => 'primary_cta', 'sample' => (string)($sharedPromptContext['shared_cta_strategy']['primary_action'] ?? 'Get Started'), 'reason' => 'Confirmed shared CTA.'],
            ];
        }

        $featured = \is_array($sharedPromptContext['footer_featured'] ?? null) ? $sharedPromptContext['footer_featured'] : [];
        return [
            ['field' => 'brand_summary', 'sample' => (string)($sharedPromptContext['site_positioning'] ?? $sharedPromptContext['site_display_name'] ?? 'Site'), 'reason' => 'Footer brand summary.'],
            ['field' => 'featured_links', 'sample' => \json_encode($featured, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]', 'reason' => 'Confirmed footer featured links.'],
            ['field' => 'support_text', 'sample' => 'Support / Contact / Policy', 'reason' => 'Footer support and compliance path.'],
        ];
    }

    private function resolveStageTwoContentLocale(array $scope): string
    {
        foreach ([
            $scope['content_locale'] ?? null,
            $scope['website_profile']['content_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
            $scope['default_language'] ?? null,
        ] as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $locale = \trim((string)$value);
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $planStructured
     * @param array<string, mixed> $themeContextSnapshot
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolveStageTwoThemePalette(array $planStructured, array $themeContextSnapshot, array $scope): array
    {
        foreach ([
            $themeContextSnapshot['palette'] ?? null,
            $themeContextSnapshot['color_scheme'] ?? null,
            $themeContextSnapshot['theme_design']['color_scheme'] ?? null,
            $planStructured['palette'] ?? null,
            $scope['palette'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $planStructured
     * @param array<string, mixed> $themeContextSnapshot
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolveStageTwoThemeStyle(array $planStructured, array $themeContextSnapshot, array $scope): array
    {
        foreach ([
            $themeContextSnapshot['visual_direction'] ?? null,
            $themeContextSnapshot['theme_style'] ?? null,
            $themeContextSnapshot['theme_design'] ?? null,
            $planStructured['theme_style'] ?? null,
            $scope['theme_style'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $fieldRequirements
     */
    private function buildSharedTaskContentFillRule(string $region, array $fieldRequirements): string
    {
        $samples = [];
        foreach ($fieldRequirements as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $field = \trim((string)($row['field'] ?? ''));
            $sample = \trim((string)($row['sample'] ?? ''));
            if ($field !== '' && $sample !== '') {
                $samples[] = $field . ': ' . $sample;
            }
        }
        return 'Populate the shared ' . $region . ' using these confirmed fields: ' . \implode(' | ', \array_slice($samples, 0, 6));
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function syncStageTwoRuntimeContexts(array $structured): array
    {
        $stage2ContextSnapshot = \is_array($structured['stage2_context_snapshot'] ?? null) ? $structured['stage2_context_snapshot'] : [];
        $themeContextSnapshot = \is_array($stage2ContextSnapshot['theme_context_snapshot'] ?? null) ? $stage2ContextSnapshot['theme_context_snapshot'] : [];
        $contextHash = (string)($stage2ContextSnapshot['context_hash'] ?? '');

        $structured['shared_tasks'] = $this->syncStageTwoRuntimeContextList(
            \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [],
            $stage2ContextSnapshot,
            $themeContextSnapshot,
            $contextHash
        );

        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            $pageTasks[$pageType] = $this->syncStageTwoRuntimeContextList($tasks, $stage2ContextSnapshot, $themeContextSnapshot, $contextHash);
        }
        $structured['page_tasks'] = $pageTasks;

        if (\is_array($structured['execution_blueprint']['tasks'] ?? null)) {
            foreach ($structured['execution_blueprint']['tasks'] as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
                $runtimeContext['theme_context_snapshot'] = $themeContextSnapshot;
                $runtimeContext['stage2_context_snapshot'] = $stage2ContextSnapshot;
                $runtimeContext['stage2_context_hash'] = $contextHash;
                $structured['execution_blueprint']['tasks'][$idx]['runtime_context'] = $runtimeContext;
            }
        }

        foreach (['shared', 'pages'] as $groupKey) {
            if (!\is_array($structured['execution_blueprint']['task_groups'][$groupKey] ?? null)) {
                continue;
            }
            if ($groupKey === 'shared') {
                $structured['execution_blueprint']['task_groups'][$groupKey] = $this->syncStageTwoRuntimeContextList(
                    $structured['execution_blueprint']['task_groups'][$groupKey],
                    $stage2ContextSnapshot,
                    $themeContextSnapshot,
                    $contextHash
                );
                continue;
            }
            foreach ($structured['execution_blueprint']['task_groups'][$groupKey] as $pageType => $tasks) {
                if (!\is_array($tasks)) {
                    continue;
                }
                $structured['execution_blueprint']['task_groups'][$groupKey][$pageType] = $this->syncStageTwoRuntimeContextList(
                    $tasks,
                    $stage2ContextSnapshot,
                    $themeContextSnapshot,
                    $contextHash
                );
            }
        }

        return $this->applyStageTwoBlockTaskPlanFanoutToStructured($structured);
    }

    /**
     * @param array<int, mixed> $tasks
     * @return list<array<string, mixed>>
     */
    private function syncStageTwoRuntimeContextList(array $tasks, array $stage2ContextSnapshot, array $themeContextSnapshot, string $contextHash): array
    {
        $synced = [];
        foreach ($tasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
            $runtimeContext['theme_context_snapshot'] = $themeContextSnapshot;
            $runtimeContext['stage2_context_snapshot'] = $stage2ContextSnapshot;
            $runtimeContext['stage2_context_hash'] = $contextHash;
            $task['runtime_context'] = $runtimeContext;
            $synced[] = $task;
        }
        return $synced;
    }

    /**
     * Add the stage-2 block-task minimum schema to every page block task.
     *
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function applyBlockTaskSchemaToStructured(array $structured): array
    {
        $structured['block_task_schema'] = [
            'schema_version' => self::BLOCK_TASK_SCHEMA_VERSION,
            'required_fields' => self::BLOCK_TASK_REQUIRED_FIELDS,
            'style_plan_required_keys' => ['color', 'font', 'spacing', 'responsive'],
            'field_contract' => [
                'task_goal' => 'Visible outcome this block must accomplish.',
                'meta_fields' => 'Editable fields with type/default/sample/reason.',
                'content_plan' => 'Concrete copy/content instructions for stage-3 execution.',
                'style_plan' => 'Concrete styling direction derived from the confirmed stage-1 plan; required keys: color, font, spacing, responsive.',
                'planning_reason' => 'Why this block task exists in the confirmed plan.',
                'sort_order' => 'Integer order matching the stage-2 task order.',
            ],
        ];

        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $tasks[$idx] = $this->applyBlockTaskSchemaToTask($task, (string)$pageType);
            }
            $pageTasks[$pageType] = \array_values($tasks);
        }
        $structured['page_tasks'] = $pageTasks;

        return $this->applyStageTwoBlockTaskPlanFanoutToStructured($structured);
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function applyStageTwoBlockTaskPlanFanoutToStructured(array $structured): array
    {
        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        $jobs = \is_array($structured['stage2_queue']['jobs'] ?? null) ? $structured['stage2_queue']['jobs'] : [];
        $sequence = \array_values(\array_filter(\array_map('strval', \is_array($structured['stage2_queue']['sequence'] ?? null) ? $structured['stage2_queue']['sequence'] : [])));
        $taskFanoutMap = [];
        $blockJobKeys = [];

        foreach ($sharedTasks as $idx => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $resultRef = \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [];
            $blockKey = $this->firstNonEmptyString([
                $task['block_key'] ?? null,
                $task['component'] ?? null,
                $task['region'] ?? null,
                \str_starts_with($taskKey, 'shared:') ? \substr($taskKey, 7) : null,
                $taskKey,
            ]);
            if (\str_starts_with($blockKey, 'shared:')) {
                $blockKey = \substr($blockKey, 7);
            }
            $jobKey = self::STAGE2_BLOCK_TASK_FANOUT_GROUP . ':shared:' . $blockKey;
            $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
            $runtimeContext = \array_replace($runtimeContext, [
                'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                'fanout_job_key' => $jobKey,
                'block_key' => $blockKey,
                'task_granularity' => 'one_block_one_task',
            ]);

            $task = \array_replace($task, [
                'block_key' => $blockKey,
                'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                'fanout_job_key' => $jobKey,
                'runtime_context' => $runtimeContext,
            ]);
            $sharedTasks[$idx] = $task;

            $dependsOn = \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [])));
            $jobs[$jobKey] = [
                'job_key' => $jobKey,
                'job_type' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                'stage' => 'stage2_block_task_plan',
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'task_key' => $taskKey,
                'page_type' => '',
                'block_key' => $blockKey,
                'depends_on' => $dependsOn,
                'depends_on_task_keys' => $dependsOn,
                'status' => (string)($task['status'] ?? 'pending'),
                'prompt_version' => (string)($runtimeContext['prompt_version'] ?? 'stage2-block-task-plan-v2'),
                'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                'queue_driver' => 'weline_queue',
                'can_parallel_after_dependencies' => true,
                'concurrency' => [
                    'mode' => 'fiber_coroutine',
                    'group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                    'task_granularity' => 'one_block_one_task',
                    'dependency_policy' => 'preserve_task_dependencies_and_sort_order',
                    'queue_driver' => 'weline_queue',
                ],
                'inputs' => [
                    'task_key' => $taskKey,
                    'page_type' => '',
                    'block_key' => $blockKey,
                    'context_hash' => (string)($resultRef['context_hash'] ?? $runtimeContext['stage2_context_hash'] ?? ''),
                ],
                'outputs' => [
                    'block_task' => \is_array($task['block_task'] ?? null) ? $task['block_task'] : [],
                    'result_ref' => $resultRef,
                ],
            ];
            $blockJobKeys[] = $jobKey;
            if (!\in_array($jobKey, $sequence, true)) {
                $sequence[] = $jobKey;
            }
            $taskFanoutMap[$taskKey] = [
                'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                'fanout_job_key' => $jobKey,
                'block_key' => $blockKey,
                'runtime_context' => $runtimeContext,
            ];
        }

        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            $pageType = (string)$pageType;
            foreach ($tasks as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                $resultRef = \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [];
                $blockKey = $this->firstNonEmptyString([
                    $task['block_key'] ?? null,
                    $planContext['block_code'] ?? null,
                    $task['section_key'] ?? null,
                    $task['section_code'] ?? null,
                    $taskKey,
                ]);
                $jobKey = self::STAGE2_BLOCK_TASK_FANOUT_GROUP . ':' . $pageType . ':' . $blockKey;
                $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
                $runtimeContext = \array_replace($runtimeContext, [
                    'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                    'fanout_job_key' => $jobKey,
                    'block_key' => $blockKey,
                    'task_granularity' => 'one_block_one_task',
                ]);

                $task = \array_replace($task, [
                    'block_key' => $blockKey,
                    'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                    'fanout_job_key' => $jobKey,
                    'runtime_context' => $runtimeContext,
                ]);
                $tasks[$idx] = $task;

                $dependsOn = \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [])));
                $jobs[$jobKey] = [
                    'job_key' => $jobKey,
                    'job_type' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                    'stage' => 'stage2_block_task_plan',
                    'sort_order' => (int)($task['sort_order'] ?? 0),
                    'task_key' => $taskKey,
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'depends_on' => $dependsOn,
                    'depends_on_task_keys' => $dependsOn,
                    'status' => (string)($task['status'] ?? 'pending'),
                    'prompt_version' => (string)($runtimeContext['prompt_version'] ?? 'stage2-block-task-plan-v2'),
                    'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                    'queue_driver' => 'weline_queue',
                    'can_parallel_after_dependencies' => true,
                    'concurrency' => [
                        'mode' => 'fiber_coroutine',
                        'group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                        'task_granularity' => 'one_block_one_task',
                        'dependency_policy' => 'preserve_task_dependencies_and_sort_order',
                        'queue_driver' => 'weline_queue',
                    ],
                    'inputs' => [
                        'task_key' => $taskKey,
                        'page_type' => $pageType,
                        'block_key' => $blockKey,
                        'context_hash' => (string)($resultRef['context_hash'] ?? $runtimeContext['stage2_context_hash'] ?? ''),
                    ],
                    'outputs' => [
                        'block_task' => \is_array($task['block_task'] ?? null) ? $task['block_task'] : [],
                        'result_ref' => $resultRef,
                    ],
                ];
                $blockJobKeys[] = $jobKey;
                if (!\in_array($jobKey, $sequence, true)) {
                    $sequence[] = $jobKey;
                }
                $taskFanoutMap[$taskKey] = [
                    'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                    'fanout_job_key' => $jobKey,
                    'block_key' => $blockKey,
                    'runtime_context' => $runtimeContext,
                ];
            }
            $pageTasks[$pageType] = \array_values($tasks);
        }

        $structured['shared_tasks'] = \array_values($sharedTasks);
        $structured['page_tasks'] = $pageTasks;
        $structured['stage2_queue'] = [
            'sequence' => $sequence,
            'jobs' => $jobs,
            'fanout' => [
                'trigger_after' => 'stage1.confirmed_block_tree',
                'mode' => 'fiber_coroutine',
                'queue_driver' => 'weline_queue',
                'dispatch_policy' => 'all_blocks_parallel_after_stage1_theme',
                'dependency_policy' => 'preserve_block_sort_order_and_task_dependencies',
                'task_granularity' => 'one_block_one_task',
                'fanout_group' => self::STAGE2_BLOCK_TASK_FANOUT_GROUP,
                'block_job_count' => \count($blockJobKeys),
                'block_job_keys' => $blockJobKeys,
            ],
        ];

        if ($taskFanoutMap !== [] && \is_array($structured['execution_blueprint']['tasks'] ?? null)) {
            foreach ($structured['execution_blueprint']['tasks'] as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '' || !isset($taskFanoutMap[$taskKey])) {
                    continue;
                }
                $fanout = $taskFanoutMap[$taskKey];
                $runtimeContext = \array_replace(
                    \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
                    \is_array($fanout['runtime_context'] ?? null) ? $fanout['runtime_context'] : []
                );
                $structured['execution_blueprint']['tasks'][$idx] = \array_replace($task, [
                    'fanout_group' => (string)$fanout['fanout_group'],
                    'fanout_job_key' => (string)$fanout['fanout_job_key'],
                    'block_key' => (string)$fanout['block_key'],
                    'runtime_context' => $runtimeContext,
                ]);
            }
        }

        return $structured;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function applyBlockTaskSchemaToTask(array $task, string $pageType): array
    {
        $existing = \is_array($task['block_task'] ?? null) ? $task['block_task'] : [];
        $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
        $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];

        $taskScriptRequirements = \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [];
        $existingMetaFields = \is_array($existing['meta_fields'] ?? null) ? $existing['meta_fields'] : [];
        $existingContentPlan = \is_array($existing['content_plan'] ?? null) ? $existing['content_plan'] : [];
        $existingContentRequirements = \is_array($existingContentPlan['field_content_requirements'] ?? null) ? $existingContentPlan['field_content_requirements'] : [];
        $planFieldPlan = \is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : [];
        $metaFieldSource = $this->hasUsableStageTwoFieldRequirements($existingMetaFields)
            ? $existingMetaFields
            : ($this->hasUsableStageTwoFieldRequirements($existingContentRequirements)
                ? $existingContentRequirements
                : ($this->hasUsableStageTwoFieldRequirements($taskScriptRequirements)
                    ? $taskScriptRequirements
                    : ($this->hasUsableStageTwoFieldRequirements($planFieldPlan) ? $planFieldPlan : $this->buildMinimalBlockTaskMetaFieldSource($task, $planContext, $existing))));
        $metaFields = $this->normalizeBlockTaskMetaFields($metaFieldSource);
        $metaFields = $this->repairStageTwoMetaFieldsWithContext($metaFields, $planContext, $task);

        $taskGoal = $this->firstNonEmptyString([
            $planContext['block_goal'] ?? null,
            $existing['task_goal'] ?? null,
            $taskScript['story_goal'] ?? null,
            $task['label'] ?? null,
            $task['task_key'] ?? null,
        ]);
        if ($taskGoal === '') {
            $taskGoal = 'Deliver the ' . ($pageType !== '' ? $pageType . ' ' : '') . 'block output.';
        }

        $contentPlan = $existingContentPlan;
        $contentPlan['story_goal'] = $this->firstNonEmptyString([
            $contentPlan['story_goal'] ?? null,
            $taskScript['story_goal'] ?? null,
            $taskGoal,
        ]);
        $contentPlan['content_fill_rule'] = $this->firstNonEmptyString([
            $contentPlan['content_fill_rule'] ?? null,
            $taskScript['content_fill_rule'] ?? null,
            $planContext['content_brief']['goal'] ?? null,
            'Fill the block fields with concrete copy and CTA content from the confirmed stage-1 plan.',
        ]);
        $contentPlan['stage3_directive'] = $this->firstNonEmptyString([
            $contentPlan['stage3_directive'] ?? null,
            $taskScript['stage3_directive'] ?? null,
            'Generate the frontend block from the confirmed stage-1 theme context, this stage-2 task detail, block_task.content_plan, block_task.style_plan, and the frontend component skill.',
        ]);
        $contentPlan['field_content_requirements'] = \is_array($contentPlan['field_content_requirements'] ?? null)
            ? $contentPlan['field_content_requirements']
            : $metaFields;
        $contentPlan = $this->ensureConcreteBlockContentPlan(
            $contentPlan,
            $metaFields,
            $planContext,
            $taskScript,
            $task
        );

        $stylePlan = \is_array($existing['style_plan'] ?? null) ? $existing['style_plan'] : [];
        $stylePlan['color'] = $this->firstNonEmptyString([
            $stylePlan['color'] ?? null,
            $stylePlan['color_rule'] ?? null,
            $stylePlan['palette'] ?? null,
            $planContext['style_plan']['color'] ?? null,
            $planContext['style_brief']['color'] ?? null,
            $planContext['style_brief']['palette'] ?? null,
            'Use the confirmed stage-1 palette for background, text, CTA, and accent states.',
        ]);
        $stylePlan['font'] = $this->firstNonEmptyString([
            $stylePlan['font'] ?? null,
            $stylePlan['font_rule'] ?? null,
            $stylePlan['typography'] ?? null,
            $planContext['style_plan']['font'] ?? null,
            $planContext['style_brief']['font'] ?? null,
            $planContext['style_brief']['typography'] ?? null,
            'Use the confirmed stage-1 typography scale for heading, body, and CTA text.',
        ]);
        $stylePlan['spacing'] = $this->firstNonEmptyString([
            $stylePlan['spacing'] ?? null,
            $stylePlan['spacing_rule'] ?? null,
            $planContext['style_plan']['spacing'] ?? null,
            $planContext['style_brief']['spacing'] ?? null,
            $planContext['style_brief']['layout_spacing'] ?? null,
            'Use the confirmed stage-1 spacing rhythm for section padding, card gaps, and radius.',
        ]);
        $stylePlan['responsive'] = $this->firstNonEmptyString([
            $stylePlan['responsive'] ?? null,
            $stylePlan['responsive_rule'] ?? null,
            $planContext['style_plan']['responsive'] ?? null,
            $planContext['responsive_rule'] ?? null,
            $task['responsive_rule'] ?? null,
            'Keep the block usable on mobile and desktop breakpoints.',
        ]);
        $stylePlan['style_direction'] = $this->firstNonEmptyString([
            $stylePlan['style_direction'] ?? null,
            $planContext['style_direction'] ?? null,
            $planContext['implementation_detail'] ?? null,
            $task['style_direction'] ?? null,
            'Follow the confirmed stage-1 palette, typography, spacing, and responsive direction.',
        ]);
        $stylePlan['responsive_rule'] = $this->firstNonEmptyString([
            $stylePlan['responsive_rule'] ?? null,
            $stylePlan['responsive'] ?? null,
            $planContext['responsive_rule'] ?? null,
            'Keep the block usable on mobile and desktop breakpoints.',
        ]);

        $planningReason = $this->firstNonEmptyString([
            $planContext['block_why'] ?? null,
            $planContext['implementation_detail'] ?? null,
            $planContext['block_goal'] ?? null,
            $existing['planning_reason'] ?? null,
            'This block task is derived from the confirmed stage-1 block tree.',
        ]);

        $task['block_task'] = [
            'schema_version' => self::BLOCK_TASK_SCHEMA_VERSION,
            'task_goal' => $taskGoal,
            'meta_fields' => $metaFields,
            'content_plan' => $contentPlan,
            'style_plan' => $stylePlan,
            'planning_reason' => $planningReason,
            'sort_order' => (int)($task['sort_order'] ?? $existing['sort_order'] ?? 0),
        ];
        $task['task_script'] = $this->ensureTaskScriptFromBlockTask($task, $task['block_task'], $planContext, $pageType);
        if (!\is_array($task['field_content_requirements'] ?? null) || $task['field_content_requirements'] === []) {
            $task['field_content_requirements'] = $task['task_script']['field_content_requirements'] ?? [];
        }

        return $task;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $planContext
     * @param array<string, mixed> $existingBlockTask
     * @return list<array<string, mixed>>
     */
    private function buildMinimalBlockTaskMetaFieldSource(array $task, array $planContext, array $existingBlockTask): array
    {
        $contentPlan = \is_array($existingBlockTask['content_plan'] ?? null) ? $existingBlockTask['content_plan'] : [];
        $requirements = \is_array($contentPlan['field_content_requirements'] ?? null) ? $contentPlan['field_content_requirements'] : [];
        if ($requirements !== []) {
            return $requirements;
        }

        $fields = [];
        $realtimeContent = \is_array($planContext['realtime_content'] ?? null) ? $planContext['realtime_content'] : [];
        $headline = $this->firstNonEmptyString([
            $realtimeContent['headline'] ?? null,
            $realtimeContent['title'] ?? null,
            $planContext['title'] ?? null,
            $task['label'] ?? null,
        ]);
        if ($headline !== '') {
            $fields[] = [
                'field' => 'headline',
                'type' => 'string',
                'sample' => $headline,
                'reason' => 'Primary visible headline from the confirmed stage-1 block context.',
            ];
        }

        foreach ($this->collectTaskContentSamples($planContext, 2) as $index => $sample) {
            if ($sample === '' || $sample === $headline) {
                continue;
            }
            $fields[] = [
                'field' => $index === 0 ? 'supporting_copy' : 'proof_copy_' . ((int)$index + 1),
                'type' => 'string',
                'sample' => $sample,
                'reason' => 'Visible copy sample inherited from the confirmed stage-1 block context.',
            ];
        }

        foreach ($this->collectTaskCtaLabels($planContext, 1) as $ctaLabel) {
            if ($ctaLabel === '') {
                continue;
            }
            $fields[] = [
                'field' => 'primary_cta',
                'type' => 'string',
                'sample' => $ctaLabel,
                'reason' => 'Primary action label inherited from the confirmed stage-1 block context.',
            ];
        }

        if ($fields === []) {
            $blockGoal = $this->firstNonEmptyString([
                $planContext['block_goal'] ?? null,
                $existingBlockTask['task_goal'] ?? null,
                $task['label'] ?? null,
                $task['task_key'] ?? null,
            ]);
            $fields[] = [
                'field' => 'headline',
                'type' => 'string',
                'sample' => $blockGoal !== '' ? $blockGoal : 'Primary block message',
                'reason' => 'Minimum editable field derived from the confirmed stage-1 task context.',
            ];
        }

        return $fields;
    }

    /**
     * @param array<int, mixed> $requirements
     */
    private function hasUsableStageTwoFieldRequirements(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if (!\is_array($requirement)) {
                continue;
            }
            $field = $this->firstNonEmptyString([$requirement['field'] ?? null, $requirement['name'] ?? null, $requirement['key'] ?? null]);
            $sample = $this->firstNonEmptyString([$requirement['sample'] ?? null, $requirement['example'] ?? null, $requirement['default'] ?? null]);
            if ($field !== '' && $sample !== '' && !$this->isStageTwoMetaInstructionLike($sample)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $metaFields
     * @param array<string, mixed> $planContext
     * @param array<string, mixed> $task
     * @return list<array<string, mixed>>
     */
    private function repairStageTwoMetaFieldsWithContext(array $metaFields, array $planContext, array $task): array
    {
        foreach ($metaFields as $index => $field) {
            if (!\is_array($field)) {
                continue;
            }
            $fieldName = $this->firstNonEmptyString([$field['field'] ?? null, $field['name'] ?? null, $field['key'] ?? null]);
            $sample = $this->firstNonEmptyString([$field['sample'] ?? null, $field['default'] ?? null]);
            if ($sample !== '' && !$this->isInternalComponentReference($sample) && !$this->isStageTwoMetaInstructionLike($sample)) {
                continue;
            }

            $replacement = $fieldName !== '' ? $this->resolveVisibleSampleForStageTwoField($fieldName, $planContext, $metaFields) : '';
            if ($replacement === '' || $this->isInternalComponentReference($replacement) || $this->isStageTwoMetaInstructionLike($replacement)) {
                $replacement = $this->firstReusableStageTwoFieldSample($metaFields);
            }
            if ($replacement === '' || $this->isInternalComponentReference($replacement) || $this->isStageTwoMetaInstructionLike($replacement)) {
                $replacement = $this->defaultStageTwoFieldSample($fieldName, $task);
            }

            $field['sample'] = $replacement;
            if (!isset($field['default']) || $field['default'] === '' || $this->isInternalComponentReference((string)$field['default']) || $this->isStageTwoMetaInstructionLike((string)$field['default'])) {
                $field['default'] = $replacement;
            }
            $metaFields[$index] = $field;
        }

        return \array_values($metaFields);
    }

    /**
     * @param list<array<string, mixed>> $metaFields
     */
    private function firstReusableStageTwoFieldSample(array $metaFields): string
    {
        foreach ($metaFields as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $sample = $this->firstNonEmptyString([$field['sample'] ?? null, $field['default'] ?? null]);
            if ($sample !== '' && !$this->isInternalComponentReference($sample) && !$this->isStageTwoMetaInstructionLike($sample)) {
                return $sample;
            }
        }

        return '';
    }

    private function defaultStageTwoFieldSample(string $fieldName, array $task): string
    {
        if (\preg_match('/(link|href|url|target)/i', $fieldName) === 1) {
            return '#start';
        }
        if (\preg_match('/(cta|button|action)/i', $fieldName) === 1) {
            return 'Start now';
        }
        if (\preg_match('/(image|visual|media|asset)/i', $fieldName) === 1) {
            return 'SVG visual matching the confirmed theme and block goal';
        }

        $label = $this->firstNonEmptyString([$task['label'] ?? null, $task['page_type'] ?? null, 'Primary block message']);
        return $this->isInternalComponentReference($label) ? 'Primary block message' : $label;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $blockTask
     * @param array<string, mixed> $planContext
     * @return array<string, mixed>
     */
    private function ensureTaskScriptFromBlockTask(array $task, array $blockTask, array $planContext, string $pageType): array
    {
        $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
        $contentPlan = \is_array($blockTask['content_plan'] ?? null) ? $blockTask['content_plan'] : [];
        $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];
        $metaFields = \is_array($blockTask['meta_fields'] ?? null) ? $blockTask['meta_fields'] : [];
        $implementationContract = \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [];
        $blockCode = $this->firstNonEmptyString([
            $planContext['block_code'] ?? null,
            $planContext['section_code'] ?? null,
            $task['section_code'] ?? null,
            $task['block_key'] ?? null,
            $task['task_key'] ?? null,
        ]);

        $taskScript['scene'] = $this->firstNonEmptyString([
            $taskScript['scene'] ?? null,
            $blockCode !== '' ? ('page:' . $pageType . '/block:' . $blockCode) : null,
            $task['task_key'] ?? null,
        ]);

        $storyGoal = \trim((string)($taskScript['story_goal'] ?? ''));
        if ($storyGoal === '' || $this->isStageTwoMetaInstructionLike($storyGoal)) {
            $taskScript['story_goal'] = $this->firstNonEmptyString([
                $contentPlan['story_goal'] ?? null,
                $blockTask['task_goal'] ?? null,
                $planContext['block_goal'] ?? null,
                $this->composeConcretePageStoryGoal($task, $pageType),
            ]);
        }

        $contentPlanRequirements = \is_array($contentPlan['field_content_requirements'] ?? null) ? $contentPlan['field_content_requirements'] : [];
        $requirements = $this->hasUsableStageTwoFieldRequirements($contentPlanRequirements)
            ? $contentPlanRequirements
            : (\is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : []);
        if (!$this->hasUsableStageTwoFieldRequirements($requirements)) {
            $requirements = $metaFields;
        }
        $requirements = $this->sanitizeTaskFieldRequirementSamples($requirements, $metaFields);
        if ($requirements === []) {
            $requirements = $metaFields;
        }
        $taskScript['field_content_requirements'] = \array_values($requirements);

        $contentFillRule = \trim((string)($taskScript['content_fill_rule'] ?? ''));
        if ($contentFillRule === '' || $this->isStageTwoMetaInstructionLike($contentFillRule)) {
            $taskScript['content_fill_rule'] = $this->firstNonEmptyString([
                $contentPlan['content_fill_rule'] ?? null,
                $this->buildTaskScriptFillRuleFromRequirements($taskScript['field_content_requirements']),
                $this->composeConcretePageFillRule($task, $pageType),
            ]);
        }

        $taskScript['stage3_directive'] = $this->firstNonEmptyString([
            $taskScript['stage3_directive'] ?? null,
            $contentPlan['stage3_directive'] ?? null,
            'Generate the frontend block from the confirmed stage-1 theme context, this task_script, block_task.content_plan, block_task.style_plan, and the frontend component skill. Do not add page labels, task ids, or plan-instruction copy as visible text.',
        ]);
        $taskScript['component_type'] = $this->firstNonEmptyString([
            $taskScript['component_type'] ?? null,
            $task['component_type'] ?? null,
            $planContext['component_type'] ?? null,
            $blockCode !== '' ? $blockCode . '_section' : 'page_section',
        ]);

        if (!\is_array($taskScript['technical_steps'] ?? null) || $taskScript['technical_steps'] === []) {
            $taskScript['technical_steps'] = [
                'Map every required field into semantic HTML visible content.',
                'Apply block_task.style_plan for palette, typography, spacing, responsive layout, and motion.',
                'Use block_task.content_plan assets or SVG/CSS visuals when no real media URL exists.',
            ];
        }
        if (!\is_array($taskScript['data_contract'] ?? null) || $taskScript['data_contract'] === []) {
            $taskScript['data_contract'] = $this->buildTaskScriptDataContract($taskScript['field_content_requirements']);
        }
        if (!\is_array($taskScript['state_contract'] ?? null) || $taskScript['state_contract'] === []) {
            $taskScript['state_contract'] = ['interactive_state' => 'Use only state needed by this block, such as hover, focus, carousel, menu, or form state.'];
        }
        if (!\is_array($taskScript['responsive_contract'] ?? null) || $taskScript['responsive_contract'] === []) {
            $taskScript['responsive_contract'] = [
                'desktop' => $this->firstNonEmptyString([$stylePlan['responsive'] ?? null, 'Use the confirmed desktop composition from stage 1.']),
                'mobile' => 'Keep text readable, buttons reachable, and visuals stacked without overlap.',
            ];
        }
        if (!\is_array($taskScript['accessibility_contract'] ?? null) || $taskScript['accessibility_contract'] === []) {
            $taskScript['accessibility_contract'] = [
                'Use semantic landmarks/headings and descriptive alt text for visuals.',
                'Keep CTA focus states visible and preserve color contrast.',
            ];
        }
        if (!\is_array($taskScript['asset_requirements'] ?? null) || $taskScript['asset_requirements'] === []) {
            $taskScript['asset_requirements'] = \is_array($contentPlan['asset_plan'] ?? null) ? $contentPlan['asset_plan'] : [];
        }
        if (!\is_array($taskScript['validation_points'] ?? null) || $taskScript['validation_points'] === []) {
            $taskScript['validation_points'] = [
                'Visible copy matches task_script.field_content_requirements and does not expose internal task keys.',
                'Theme colors, typography, spacing, and responsive behavior match block_task.style_plan.',
                'No broken images, example.com media URLs, or empty visual slots.',
            ];
        }
        $taskScript['completion_rule'] = $this->firstNonEmptyString([
            $taskScript['completion_rule'] ?? null,
            $implementationContract['completion_rule'] ?? null,
            $task['completion_rule'] ?? null,
            'The block is complete when stage 3 renders production-ready HTML/CSS/JS matching the confirmed stage-1 theme and this stage-2 task detail.',
        ]);

        return $taskScript;
    }

    /**
     * @param list<array<string, mixed>> $requirements
     */
    private function buildTaskScriptFillRuleFromRequirements(array $requirements): string
    {
        $examples = [];
        foreach ($requirements as $requirement) {
            if (!\is_array($requirement)) {
                continue;
            }
            $field = $this->firstNonEmptyString([$requirement['field'] ?? null, $requirement['name'] ?? null]);
            $sample = $this->firstNonEmptyString([$requirement['sample'] ?? null, $requirement['default'] ?? null]);
            if ($field === '' || $sample === '') {
                continue;
            }
            $examples[] = $field . '=' . $sample;
        }

        return $examples !== []
            ? 'Populate these visible fields from the confirmed stage-1 plan: ' . \implode(' | ', \array_slice($examples, 0, 8)) . '.'
            : '';
    }

    /**
     * @param list<array<string, mixed>> $requirements
     * @return array<string, string>
     */
    private function buildTaskScriptDataContract(array $requirements): array
    {
        $contract = [];
        foreach ($requirements as $requirement) {
            if (!\is_array($requirement)) {
                continue;
            }
            $field = $this->firstNonEmptyString([$requirement['field'] ?? null, $requirement['name'] ?? null]);
            if ($field === '') {
                continue;
            }
            $contract[$field] = $this->firstNonEmptyString([$requirement['type'] ?? null, 'string']);
        }

        return $contract !== [] ? $contract : ['content' => 'string'];
    }


    /**
     * @param array<string, mixed> $contentPlan
     * @param list<array<string, mixed>> $metaFields
     * @param array<string, mixed> $planContext
     * @param array<string, mixed> $taskScript
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function ensureConcreteBlockContentPlan(
        array $contentPlan,
        array $metaFields,
        array $planContext,
        array $taskScript,
        array $task
    ): array {
        if (!\is_array($contentPlan['content_copy'] ?? null) || $contentPlan['content_copy'] === []) {
            $contentCopy = [];
            foreach ($metaFields as $field) {
                $fieldName = $this->firstNonEmptyString([$field['field'] ?? null, $field['name'] ?? null]);
                $sample = $this->firstNonEmptyString([$field['sample'] ?? null, $field['default'] ?? null]);
                if ($fieldName === '' || $sample === '') {
                    continue;
                }
                $contentCopy[] = [
                    'field' => $fieldName,
                    'copy' => $sample,
                    'usage' => $this->firstNonEmptyString([$field['reason'] ?? null, 'Use as final on-page copy for this block field.']),
                ];
            }
            if ($contentCopy === []) {
                $contentCopy[] = [
                    'field' => 'body',
                    'copy' => $this->firstNonEmptyString([$taskScript['story_goal'] ?? null, $planContext['block_goal'] ?? null, $task['label'] ?? null, 'Concrete block content sample']),
                    'usage' => 'Use as the primary visible copy seed for this block.',
                ];
            }
            $contentPlan['content_copy'] = $contentCopy;
        }

        if (!\is_array($contentPlan['cta_plan'] ?? null) || $contentPlan['cta_plan'] === []) {
            $ctaPlan = [];
            $realtimeCtaTarget = '';
            $realtimeContent = \is_array($planContext['realtime_content'] ?? null) ? $planContext['realtime_content'] : [];
            foreach (\is_array($realtimeContent['cta'] ?? null) ? $realtimeContent['cta'] : [] as $cta) {
                if (!\is_array($cta)) {
                    continue;
                }
                $realtimeCtaTarget = $this->firstNonEmptyString([$cta['target'] ?? null, $cta['href'] ?? null, $cta['url'] ?? null]);
                if ($realtimeCtaTarget !== '') {
                    break;
                }
            }
            foreach ($metaFields as $field) {
                $fieldName = $this->firstNonEmptyString([$field['field'] ?? null, $field['name'] ?? null]);
                if ($fieldName === '' || !\preg_match('/(cta|button|action)/i', $fieldName)) {
                    continue;
                }
                if (\preg_match('/(link|href|url|target)/i', $fieldName) === 1) {
                    continue;
                }
                $ctaPlan[] = [
                    'label' => $this->firstNonEmptyString([$field['sample'] ?? null, $field['default'] ?? null, 'Start now']),
                    'target' => $this->firstNonEmptyString([$field['href'] ?? null, $field['url'] ?? null, $field['target'] ?? null, $realtimeCtaTarget, '#contact']),
                    'source_field' => $fieldName,
                ];
            }
            if ($ctaPlan === []) {
                $ctaPlan[] = [
                    'label' => $this->extractBracketedLabel((string)($taskScript['story_goal'] ?? '')) ?: 'Start now',
                    'target' => $this->firstNonEmptyString([$planContext['content_brief']['cta_target'] ?? null, $planContext['cta_target'] ?? null, $realtimeCtaTarget, '#contact']),
                    'source_field' => 'primary_cta',
                ];
            }
            $contentPlan['cta_plan'] = $ctaPlan;
        }

        if (!\is_array($contentPlan['link_plan'] ?? null) || $contentPlan['link_plan'] === []) {
            $links = [];
            $internalLinks = \is_array($planContext['seo_brief']['internal_links'] ?? null) ? $planContext['seo_brief']['internal_links'] : [];
            foreach ($internalLinks as $index => $link) {
                if (\is_array($link)) {
                    $href = $this->firstNonEmptyString([$link['href'] ?? null, $link['url'] ?? null, $link['target'] ?? null]);
                    $label = $this->firstNonEmptyString([$link['label'] ?? null, $link['title'] ?? null, $href]);
                } else {
                    $href = $this->firstNonEmptyString([$link]);
                    $label = $href;
                }
                if ($href === '') {
                    continue;
                }
                $links[] = [
                    'label' => $label !== '' ? $label : ('Internal link ' . ((int)$index + 1)),
                    'href' => $href,
                    'purpose' => 'Support the stage-1 internal-link/SEO path for this block.',
                ];
            }
            foreach ($contentPlan['cta_plan'] as $cta) {
                if (!\is_array($cta)) {
                    continue;
                }
                $target = $this->firstNonEmptyString([$cta['href'] ?? null, $cta['url'] ?? null, $cta['target'] ?? null]);
                if ($target === '') {
                    continue;
                }
                $links[] = [
                    'label' => $this->firstNonEmptyString([$cta['label'] ?? null, 'Primary CTA']),
                    'href' => $target,
                    'purpose' => 'CTA destination for this block.',
                ];
            }
            if ($links === []) {
                $links[] = [
                    'label' => 'Contact',
                    'href' => '#contact',
                    'purpose' => 'Default conversion path when stage-1 does not provide a more specific link.',
                ];
            }
            $contentPlan['link_plan'] = $links;
        }

        if (!\is_array($contentPlan['asset_plan'] ?? null) || $contentPlan['asset_plan'] === []) {
            $assets = [];
            foreach (['asset_plan', 'assets', 'media_assets', 'visual_assets'] as $key) {
                if (!\is_array($planContext[$key] ?? null)) {
                    continue;
                }
                foreach ($planContext[$key] as $index => $asset) {
                    if (\is_array($asset)) {
                        $description = $this->firstNonEmptyString([$asset['description'] ?? null, $asset['asset'] ?? null, $asset['prompt'] ?? null, $asset['alt_text'] ?? null]);
                        $slot = $this->firstNonEmptyString([$asset['slot'] ?? null, $asset['field'] ?? null, 'asset_' . ((int)$index + 1)]);
                        $altText = $this->firstNonEmptyString([$asset['alt_text'] ?? null, $description]);
                    } else {
                        $description = $this->firstNonEmptyString([$asset]);
                        $slot = 'asset_' . ((int)$index + 1);
                        $altText = $description;
                    }
                    if ($description === '') {
                        continue;
                    }
                    $assets[] = [
                        'slot' => $slot,
                        'description' => $description,
                        'alt_text' => $altText !== '' ? $altText : $description,
                        'source_rule' => 'Use a licensed, brand-compatible asset that matches the confirmed stage-1 plan.',
                    ];
                }
            }
            if ($assets === []) {
                $label = $this->firstNonEmptyString([$task['label'] ?? null, $planContext['block_code'] ?? null, 'block']);
                if ($this->isInternalComponentReference($label)) {
                    $label = $this->firstNonEmptyString([$planContext['block_goal'] ?? null, $planContext['page_goal'] ?? null, 'block']);
                }
                $assets[] = [
                    'slot' => 'primary_visual',
                    'description' => $label . ' visual that illustrates the block promise without adding unstated requirements.',
                    'alt_text' => $label . ' visual for ' . $this->firstNonEmptyString([$planContext['page_goal'] ?? null, $planContext['block_goal'] ?? null, 'the page']),
                    'source_rule' => 'Use an existing brand asset or generate a licensed illustration/photo consistent with stage-1 style.',
                ];
            }
            $contentPlan['asset_plan'] = $assets;
        }

        $contentPlan = $this->replaceInternalComponentReferencesInContentPlan($contentPlan, $metaFields, $planContext);

        return $contentPlan;
    }

    /**
     * @param array<string, mixed> $contentPlan
     * @param list<array<string, mixed>> $metaFields
     * @param array<string, mixed> $planContext
     * @return array<string, mixed>
     */
    private function replaceInternalComponentReferencesInContentPlan(array $contentPlan, array $metaFields, array $planContext): array
    {
        $fieldPlan = \is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : $metaFields;
        if (\is_array($contentPlan['field_content_requirements'] ?? null)) {
            foreach ($contentPlan['field_content_requirements'] as $index => $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $sample = \trim((string)($row['sample'] ?? ''));
                if ($sample === '' || !$this->isInternalComponentReference($sample)) {
                    continue;
                }
                $contentPlan['field_content_requirements'][$index]['sample'] = $this->resolveVisibleSampleForStageTwoField(
                    \trim((string)($row['field'] ?? '')),
                    $planContext,
                    $fieldPlan
                );
            }
        }

        if (\is_array($contentPlan['content_copy'] ?? null)) {
            foreach ($contentPlan['content_copy'] as $index => $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $copy = \trim((string)($row['copy'] ?? ''));
                if ($copy === '' || !$this->isInternalComponentReference($copy)) {
                    continue;
                }
                $contentPlan['content_copy'][$index]['copy'] = $this->resolveVisibleSampleForStageTwoField(
                    \trim((string)($row['field'] ?? '')),
                    $planContext,
                    $fieldPlan
                );
            }
        }

        if (\is_array($contentPlan['cta_plan'] ?? null)) {
            foreach ($contentPlan['cta_plan'] as $index => $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $label = \trim((string)($row['label'] ?? ''));
                if ($label === '' || !$this->isInternalComponentReference($label)) {
                    continue;
                }
                $contentPlan['cta_plan'][$index]['label'] = $this->resolveVisibleSampleForStageTwoField('primary_cta', $planContext, $fieldPlan);
            }
        }

        return $contentPlan;
    }

    private function extractBracketedLabel(string $text): string
    {
        if (\preg_match('/\[([^\]]{2,80})\]/u', $text, $matches) !== 1) {
            return '';
        }
        return \trim((string)($matches[1] ?? ''));
    }

    /**
     * @param array<int, mixed> $fields
     * @return list<array<string, mixed>>
     */
    private function normalizeBlockTaskMetaFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $index => $field) {
            if (!\is_array($field)) {
                continue;
            }
            $name = $this->firstNonEmptyString([
                $field['field'] ?? null,
                $field['name'] ?? null,
                $field['key'] ?? null,
            ]);
            if ($name === '') {
                continue;
            }
            $sample = $this->firstNonEmptyString([
                $field['sample'] ?? null,
                $field['example'] ?? null,
                $field['default'] ?? null,
            ]);
            $normalized[] = [
                'field' => $name,
                'type' => $this->firstNonEmptyString([$field['type'] ?? null, 'string']),
                'default' => $field['default'] ?? $sample,
                'sample' => $sample !== '' ? $sample : ('Sample value for ' . $name),
                'reason' => $this->firstNonEmptyString([
                    $field['reason'] ?? null,
                    $field['description'] ?? null,
                    'Required by block task schema field #' . ((int)$index + 1) . '.',
                ]),
            ];
        }

        if ($normalized === []) {
            $normalized[] = [
                'field' => 'content',
                'type' => 'string',
                'default' => '',
                'sample' => 'Concrete block content sample',
                'reason' => 'Fallback editable content field required by the block task schema.',
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmptyString(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    /**
     * Normalize execution blueprint tasks.
     *
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function normalizeExecutionBlueprintTasks(array $tasks): array
    {
        $normalized = [];
        foreach ($tasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $normalized[] = [
                'task_key' => $taskKey,
                'from_node_key' => \trim((string)($task['from_node_key'] ?? $taskKey)),
                'group_key' => \trim((string)($task['group_key'] ?? '')),
                'task_group' => \trim((string)($task['task_group'] ?? '')),
                'page_type' => \trim((string)($task['page_type'] ?? '')),
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                'status' => \trim((string)($task['status'] ?? 'pending')),
                'parent_task_key' => \trim((string)($task['parent_task_key'] ?? '')),
                'can_parallel' => (bool)($task['can_parallel'] ?? true),
                'materialize_after_done' => (bool)($task['materialize_after_done'] ?? false),
                'materialize_policy' => \trim((string)($task['materialize_policy'] ?? 'none')),
                'prompt_template_key' => \trim((string)($task['prompt_template_key'] ?? 'stage2_task_execute')),
                'prompt_variables' => \is_array($task['prompt_variables'] ?? null) ? $task['prompt_variables'] : [],
                'progress_weight' => (float)($task['progress_weight'] ?? 1.0),
                'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
                'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
            ];
        }
        return $normalized;
    }

    /**
     * 为第二阶段任务补齐第一阶段计划上下文，确保任务可直接驱动实现。
     *
     * @param list<array<string, mixed>> $sharedTasks
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @param array<string, array<string, mixed>> $metaFieldMatrix
     * @param array<string, array<string, array<string, mixed>>> $blockPlanMatrix
     * @param array<string, array<string, mixed>> $pagePlans
     * @return array{0:list<array<string, mixed>>,1:array<string, list<array<string, mixed>>>}
     */
    private function enrichTasksWithStage1PlanContext(
        array $sharedTasks,
        array $pageTasks,
        array $metaFieldMatrix,
        array $blockPlanMatrix,
        array $pagePlans
    ): array {
        foreach ($pageTasks as $pageType => $tasks) {
            foreach ($tasks as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $blockCode = $this->resolveTaskBlockCodeFromPlan($task, (string)$pageType, $blockPlanMatrix);
                $pageGoal = (string)($pagePlans[$pageType]['page_goal'] ?? '');
                $blockMeta = \is_array($metaFieldMatrix[$pageType][$blockCode] ?? null) ? $metaFieldMatrix[$pageType][$blockCode] : [];
                $blockPlan = \is_array($blockPlanMatrix[$pageType][$blockCode] ?? null) ? $blockPlanMatrix[$pageType][$blockCode] : [];
                $blockReason = (string)($blockPlan['reason'] ?? $blockPlan['why'] ?? '');
                $task['plan_context'] = [
                    'source_stage' => 'stage_1',
                    'page_type' => $pageType,
                    'page_goal' => $pageGoal,
                    'block_code' => $blockCode,
                    'section_code' => (string)($blockPlan['section_code'] ?? $task['section_code'] ?? ''),
                    'block_goal' => (string)($blockMeta['goal'] ?? ''),
                    'block_reason' => $blockReason,
                    'block_why' => $blockReason,
                    'implementation_detail' => (string)($blockPlan['implementation_detail'] ?? ''),
                    'realtime_content' => \is_array($blockPlan['realtime_content'] ?? null) ? $blockPlan['realtime_content'] : [],
                    'editable_fields' => \is_array($blockPlan['editable_fields'] ?? null) ? $blockPlan['editable_fields'] : [],
                    'completion_rule' => (string)($blockPlan['completion_rule'] ?? ''),
                    'content_brief' => \is_array($blockPlan['content_brief'] ?? null) ? $blockPlan['content_brief'] : [],
                    'field_plan' => \is_array($blockMeta['field_plan'] ?? null) ? $blockMeta['field_plan'] : [],
                    'design_tags' => \is_array($blockPlan['design_tags'] ?? null) ? $blockPlan['design_tags'] : [],
                    'style_direction' => (string)($blockPlan['style_direction'] ?? ''),
                    'responsive_rule' => (string)($blockPlan['responsive_rule'] ?? ''),
                    'result_ref' => \is_array($blockMeta['result_ref'] ?? null) ? $blockMeta['result_ref'] : [],
                ];
                $task['implementation_contract'] = [
                    'delivery_rule' => '按任务脚本直接实现组件，不做额外内容脑补。',
                    'implementation_detail' => (string)($blockPlan['implementation_detail'] ?? ''),
                    'realtime_output' => \is_array($blockPlan['realtime_content'] ?? null) ? $blockPlan['realtime_content'] : [],
                    'completion_rule' => (string)($blockPlan['completion_rule'] ?? ''),
                ];
                $task['task_script'] = [
                    'scene' => 'page:' . $pageType . '/block:' . $blockCode,
                ];
                $tasks[$idx] = $task;
            }
            $pageTasks[$pageType] = \array_values($tasks);
        }

        foreach ($sharedTasks as $idx => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $task['plan_context'] = [
                'source_stage' => 'stage_1',
                'scope' => 'shared',
                'stage1_goal' => (string)($task['label'] ?? $task['task_key'] ?? 'shared'),
                'content_rules' => [
                    'navigation_or_footer' => '遵循第一阶段 navigation_plan/footer_plan 与 seo_strategy 约束',
                ],
            ];
            $task['implementation_contract'] = [
                'delivery_rule' => '共享任务实现必须优先满足第一阶段全站规则与可复用性。',
            ];
            $task['task_script'] = [
                'scene' => (string)($task['task_key'] ?? 'shared'),
                'story_goal' => (string)($task['label'] ?? $task['task_key'] ?? 'shared task') . ' 需要作为一次独立的 SSE 对话一次性生成。',
                'content_fill_rule' => '共享任务只生成一次，必须输出可复用的全站组件定义，不拆分成多个重复 task。',
                'stage3_directive' => '按该共享任务脚本直接生成组件，确保 header/footer 只出现一次且可被全站复用。',
                'field_content_requirements' => [
                    [
                        'field' => 'title',
                        'sample' => (string)($task['label'] ?? $task['task_key'] ?? 'shared task'),
                        'reason' => '提供共享组件的标题或识别名称。',
                    ],
                ],
            ];
            $sharedTasks[$idx] = $task;
        }

        return [\array_values($sharedTasks), $pageTasks];
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function buildDeterministicTaskPlanStructured(array $structured): array
    {
        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        foreach ($sharedTasks as $idx => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $label = \trim((string)($task['label'] ?? $task['task_key'] ?? 'shared task'));
            $task['task_script'] = \array_replace(
                \is_array($task['task_script'] ?? null) ? $task['task_script'] : [],
                [
                    'story_goal' => $label . ' must be implemented first so later pages can reuse it.',
                    'content_fill_rule' => 'Implement reusable structure, required copy, and links without adding unrelated features.',
                    'stage3_directive' => 'Implement according to the shared component contract and keep it reusable.',
                    'field_content_requirements' => [
                        [
                            'field' => 'title',
                            'sample' => $label,
                            'reason' => 'Identify the shared component and its purpose.',
                        ],
                    ],
                ]
            );
            $task['implementation_contract'] = \array_replace(
                \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [],
                [
                    'acceptance' => [
                        'Shared component can be reused by selected pages.',
                        'Field configuration stays editable and can enter stage 3 generation directly.',
                    ],
                ]
            );
            $sharedTasks[$idx] = $task;
        }

        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                $fieldPlan = \is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : [];
                $requirements = [];
                foreach ($fieldPlan as $field) {
                    if (!\is_array($field)) {
                        continue;
                    }
                    $name = \trim((string)($field['field'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $sample = \trim((string)($field['sample'] ?? ''));
                    $requirements[] = [
                        'field' => $name,
                        'sample' => $sample !== '' ? $sample : ('Display-ready sample for ' . $name),
                        'reason' => \trim((string)($field['reason'] ?? '')) !== '' ? (string)$field['reason'] : 'Keep this field directly usable in stage 3 generation.',
                    ];
                }
                if ($requirements === []) {
                    $requirements[] = [
                        'field' => 'content',
                        'sample' => 'Display-ready content for this block.',
                        'reason' => 'Provide a minimum executable field sample.',
                    ];
                }
                $blockGoal = \trim((string)($planContext['block_goal'] ?? ''));
                $pageGoal = \trim((string)($planContext['page_goal'] ?? ''));
                $label = \trim((string)($task['label'] ?? $task['task_key'] ?? $pageType));
                $task['task_script'] = \array_replace(
                    \is_array($task['task_script'] ?? null) ? $task['task_script'] : [],
                    [
                        'story_goal' => $blockGoal !== '' ? $blockGoal : ($label . ' supports page goal: ' . ($pageGoal !== '' ? $pageGoal : $pageType)),
                        'content_fill_rule' => 'Fill content around the block goal and keep field samples, SEO intent, and CTA direction aligned.',
                        'stage3_directive' => 'Generate component config, copy, and structure directly from this task script.',
                        'field_content_requirements' => $requirements,
                    ]
                );
                $task['task_script']['story_goal'] = $this->composeConcretePageStoryGoal($task, (string)$pageType);
                $task['task_script']['content_fill_rule'] = $this->composeConcretePageFillRule($task, (string)$pageType);
                $task['implementation_contract'] = \array_replace(
                    \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [],
                    [
                        'acceptance' => [
                            'Block output covers block_goal and page_goal.',
                            'Every field_content_requirements field has a directly usable sample value.',
                        ],
                    ]
                );
                $tasks[$idx] = $task;
            }
            $pageTasks[$pageType] = \array_values($tasks);
        }

        $structured['shared_tasks'] = \array_values($sharedTasks);
        $structured['page_tasks'] = $pageTasks;
        return $structured;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $draftPlan
     * @param callable|null $heartbeatCallback 传给 AI generateStream 的 on_heartbeat
     * @return array{markdown:string,structured:array<string,mixed>,virtual_theme_plan:array<string,mixed>}
     */
    private function buildTaskPlanArtifactsByAiMode(
        array $scope,
        array $buildBlueprint,
        string $mode,
        array $payload,
        array $draftPlan = [],
        ?callable $chunkCallback = null,
        ?callable $heartbeatCallback = null,
        ?callable $progressCallback = null
    ): array {
        $baselineArtifacts = $this->buildTaskPlanArtifacts(\array_replace($scope, ['fake_mode' => 1]), $buildBlueprint);
        $baselineStructured = \is_array($baselineArtifacts['structured'] ?? null) ? $baselineArtifacts['structured'] : [];
        $baselineVirtualThemePlan = \is_array($baselineArtifacts['virtual_theme_plan'] ?? null) ? $baselineArtifacts['virtual_theme_plan'] : [];
        if ($mode === 'refine_task_plan' && $draftPlan !== []) {
            $baselineVirtualThemePlan = \array_replace_recursive($baselineVirtualThemePlan, $draftPlan);
            $baselineStructured = \array_replace_recursive($baselineStructured, $baselineVirtualThemePlan);
        }

        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            return $this->applyFakeModeTaskPlanPreviewMutation(
                $scope,
                $buildBlueprint,
                $baselineStructured,
                $baselineVirtualThemePlan,
                $mode,
                $instruction,
                $targetScope,
                \max(1, (int)($payload['round'] ?? 1))
            );
        }

        $ai = $this->getAiService();
        if ($ai === null) {
            throw new \RuntimeException('AI task plan generation failed: AiService unavailable.');
        }

        return $this->buildTaskPlanArtifactsByAiInBatches(
            $scope,
            $buildBlueprint,
            $baselineStructured,
            $baselineVirtualThemePlan,
            $chunkCallback,
            $heartbeatCallback,
            $mode,
            $instruction,
            $targetScope,
            $mode === 'refine_task_plan' ? $this->resolveTaskPlanBatchIdsForTargetScope($baselineStructured, $targetScope) : null,
            $progressCallback
        );

        $prompt = $mode === 'refine_task_plan'
            ? $this->buildTaskPlanRefinePrompt($scope, $buildBlueprint, $baselineStructured, $baselineVirtualThemePlan, $payload)
            : $this->buildTaskPlanRebuildPrompt($scope, $buildBlueprint, $baselineStructured, $baselineVirtualThemePlan, $payload);

        $publicId = \trim((string)($scope['public_id'] ?? ''));
        $requestParams = [
            'allow_zero_balance_provider' => true,
            'temperature' => $mode === 'refine_task_plan' ? 0.15 : 0.2,
            // 第二阶段返回 markdown + virtual_theme_plan，体量远大于阶段一，需显式抬高输出预算。
            'max_tokens' => 8192,
            // SSE 任务方案流不设置 provider 业务超时，避免生成中途被参数阈值截断。
            'timeout' => 0,
            'disable_ai_timeout' => true,
            'disable_cli_timeout' => true,
            'session_id' => $publicId,
            'disable_conversation_history' => true,
            'disable_conversation_persist' => true,
        ];
        
        $jsonRequestParams = \array_merge($requestParams, [
            'response_format' => ['type' => 'json_object'],
        ]);

        if ($chunkCallback === null && $heartbeatCallback === null) {
            $raw = (string)$ai->generate(
                $prompt,
                null,
                'pagebuilder_task_plan_generation',
                null,
                $jsonRequestParams
            );
            $decoded = \json_decode($raw, true);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('AI task plan generation failed: invalid JSON response.');
            }

            return $this->mergeAiTaskPlanArtifacts($baselineStructured, $baselineVirtualThemePlan, $decoded);
        }

        $raw = '';
        $decoded = null;
        $streamThrowable = null;
        $streamCallback = static function (string $chunk) use (&$raw, $chunkCallback): void {
            $raw .= $chunk;
            if ($chunkCallback !== null) {
                $chunkCallback($chunk);
            }
            if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent()) {
                SchedulerSystem::yieldDelay(1);
            }
        };

        $streamRequestParams = \array_merge($jsonRequestParams, [
            'enforce_timeout_in_stream' => false,
        ]);
        if ($heartbeatCallback !== null) {
            $streamRequestParams['on_heartbeat'] = $heartbeatCallback;
        }

        try {
            $ai->generateStream(
                $prompt,
                $streamCallback,
                null,
                'pagebuilder_task_plan_generation',
                null,
                $streamRequestParams
            );
            $decoded = \json_decode($raw, true);
        } catch (\Throwable $throwable) {
            $streamThrowable = $throwable;
        }

        if (!\is_array($decoded)) {
            $jsonRaw = (string)$ai->generate(
                $prompt,
                null,
                'pagebuilder_task_plan_generation',
                null,
                $jsonRequestParams
            );
            $decoded = \json_decode($jsonRaw, true);
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException('AI task plan generation failed: invalid JSON response.', 0, $streamThrowable);
        }

        return $this->mergeAiTaskPlanArtifacts($baselineStructured, $baselineVirtualThemePlan, $decoded);
    }

    /**
     * fake_mode 下需要让块级操作在第二阶段预览中产生稳定且可观察的差异。
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     * @return array{markdown:string,structured:array<string,mixed>,virtual_theme_plan:array<string,mixed>,generation_source:string}
     */
    private function applyFakeModeTaskPlanPreviewMutation(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        string $mode,
        string $instruction,
        string $targetScope,
        int $round
    ): array {
        $operationSeed = (string)\json_encode([
            'mode' => $mode,
            'instruction' => $instruction,
            'target_scope' => $targetScope,
            'round' => $round,
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $operationId = \substr(\sha1($operationSeed), 0, 8);
        $isSharedTarget = \str_contains(\strtolower($targetScope), 'shared');
        $pageType = $this->resolveFakeModeTaskPlanPageType($structured, $targetScope);

        if ($isSharedTarget) {
            $structured['shared_tasks'] = $this->mutateFakeModeTaskPlanTaskList(
                \array_values(\is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : []),
                'shared',
                $instruction,
                $targetScope,
                $mode,
                $round,
                $operationId
            );
        } else {
            $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
            $pageTasks[$pageType] = $this->mutateFakeModeTaskPlanTaskList(
                \array_values(\is_array($pageTasks[$pageType] ?? null) ? $pageTasks[$pageType] : []),
                $pageType,
                $instruction,
                $targetScope,
                $mode,
                $round,
                $operationId
            );
            $structured['page_tasks'] = $pageTasks;
        }

        $structured = $this->applyReadableDeterministicTaskPlanContent($structured);
        $riskNotes = \is_array($structured['risk_notes'] ?? null) ? \array_values($structured['risk_notes']) : [];
        $riskNotes[] = '预览变更 #' . $operationId . '：' . ($instruction !== '' ? $instruction : ($mode === 'rebuild_task_plan' ? '重新整理任务方案结构。' : '微调当前任务方案。'));
        $structured['risk_notes'] = \array_values(\array_unique(\array_filter(\array_map('strval', $riskNotes))));
        $structured = $this->syncStageTwoRuntimeContexts($structured);

        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        $pageTypes = \array_values(\array_filter(\array_map('strval', \array_keys($pageTasks))));
        $markdown = $this->buildStageTwoMarkdown($pageTypes, $sharedTasks, $pageTasks, $structured);

        $virtualThemePlan = \array_replace_recursive($virtualThemePlan, [
            'shared_tasks' => $sharedTasks,
            'page_tasks' => $pageTasks,
            'risk_notes' => $structured['risk_notes'] ?? [],
        ]);
        $virtualThemePlan['signature'] = $this->buildSignature(\array_replace($structured, ['markdown' => $markdown]));

        return [
            'markdown' => $markdown,
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
            'generation_source' => 'deterministic',
        ];
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function mutateFakeModeTaskPlanTaskList(
        array $tasks,
        string $scopeKey,
        string $instruction,
        string $targetScope,
        string $mode,
        int $round,
        string $operationId
    ): array {
        if ($this->looksLikeFakeModeTaskPlanAddInstruction($instruction)) {
            $tasks[] = $this->buildFakeModeTaskPlanTask($scopeKey, $instruction, $round, $operationId);
            return \array_values($tasks);
        }

        if ($this->looksLikeFakeModeTaskPlanDeleteInstruction($instruction)) {
            $tasks = $this->removeFakeModeTaskPlanTask($tasks, $targetScope);
            if ($tasks === []) {
                $tasks[] = $this->buildFakeModeTaskPlanTask($scopeKey, '恢复一个最小任务块，避免预览为空。', $round, $operationId);
            }
            return \array_values($tasks);
        }

        foreach ($tasks as $index => $task) {
            if (!\is_array($task)) {
                continue;
            }
            if ($targetScope !== '' && !$this->taskPlanTaskMatchesTarget($task, $targetScope)) {
                continue;
            }
            $tasks[$index] = $this->annotateFakeModeTaskPlanTask($task, $instruction, $mode, $round, $operationId);
            return \array_values($tasks);
        }

        if ($tasks === []) {
            $tasks[] = $this->buildFakeModeTaskPlanTask($scopeKey, $instruction, $round, $operationId);
        } else {
            $tasks[0] = $this->annotateFakeModeTaskPlanTask($tasks[0], $instruction, $mode, $round, $operationId);
        }

        return \array_values($tasks);
    }

    private function resolveFakeModeTaskPlanPageType(array $structured, string $targetScope): string
    {
        if (\preg_match('/page:([a-z0-9_]+)/i', $targetScope, $matches) === 1) {
            return (string)$matches[1];
        }
        if (\preg_match('/pages\.([a-z0-9_]+)/i', $targetScope, $matches) === 1) {
            return (string)$matches[1];
        }
        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        return (string)(\array_key_first($pageTasks) ?? 'home_page');
    }

    private function looksLikeFakeModeTaskPlanAddInstruction(string $instruction): bool
    {
        $text = \mb_strtolower(\trim($instruction));
        if ($text === '') {
            return false;
        }
        return \str_contains($text, 'add block')
            || \str_contains($text, '新增')
            || \str_contains($text, '添加')
            || \str_contains($text, '补足');
    }

    private function looksLikeFakeModeTaskPlanDeleteInstruction(string $instruction): bool
    {
        $text = \mb_strtolower(\trim($instruction));
        if ($text === '') {
            return false;
        }
        return \str_contains($text, 'delete')
            || \str_contains($text, 'remove')
            || \str_contains($text, '删除')
            || \str_contains($text, '移除');
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function annotateFakeModeTaskPlanTask(array $task, string $instruction, string $mode, int $round, string $operationId): array
    {
        $verb = $mode === 'rebuild_task_plan' ? '重建' : '微调';
        $note = $instruction !== '' ? $instruction : ('第 ' . $round . ' 轮' . $verb);
        $task['label'] = \trim((string)($task['label'] ?? '任务块')) . ' · ' . $verb . ' #' . $round;
        $task['task_script'] = \array_replace(
            \is_array($task['task_script'] ?? null) ? $task['task_script'] : [],
            [
                'story_goal' => $note . '（fake-mode:' . $operationId . '）',
                'content_fill_rule' => '保持原有任务方向，同时将本轮操作结果显式写入任务描述中，便于前端预览观察变化。',
                'stage3_directive' => '按照当前预览中的任务说明继续生成，并保留本轮 ' . $verb . ' 的差异标记。',
            ]
        );
        $task['implementation_contract'] = \array_replace(
            \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [],
            [
                'acceptance' => [
                    '当前任务卡已带有本轮 ' . $verb . ' 标记 #' . $round,
                    '变更可在第二阶段预览与 Markdown 中直接看到。',
                ],
            ]
        );

        return $task;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function removeFakeModeTaskPlanTask(array $tasks, string $targetScope): array
    {
        foreach ($tasks as $index => $task) {
            if (!\is_array($task)) {
                continue;
            }
            if ($this->taskPlanTaskMatchesTarget($task, $targetScope)) {
                unset($tasks[$index]);
                return \array_values($tasks);
            }
        }

        \array_pop($tasks);
        return \array_values($tasks);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function taskPlanTaskMatchesTarget(array $task, string $targetScope): bool
    {
        $targetScope = \trim($targetScope);
        if ($targetScope === '') {
            return false;
        }

        $taskKey = \trim((string)($task['task_key'] ?? ''));
        $sectionCode = \trim((string)($task['section_code'] ?? ''));
        $pageType = \trim((string)($task['page_type'] ?? ''));

        if ($taskKey !== '' && \str_contains($targetScope, $taskKey)) {
            return true;
        }
        if ($sectionCode !== '' && \str_contains($targetScope, $sectionCode)) {
            return true;
        }
        return $pageType !== '' && \str_contains($targetScope, $pageType);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFakeModeTaskPlanTask(string $scopeKey, string $instruction, int $round, string $operationId): array
    {
        $isShared = $scopeKey === 'shared';
        $pageType = $isShared ? '' : $scopeKey;
        $sectionCode = 'custom_' . \strtolower($operationId);
        $label = $instruction !== '' ? $instruction : ($isShared ? '新增共享任务块' : '新增页面任务块');

        return [
            'task_key' => $isShared ? ('shared:' . $sectionCode) : ('page:' . $pageType . ':' . $sectionCode),
            'label' => $label,
            'group_key' => $isShared ? 'shared' : $pageType,
            'page_type' => $pageType,
            'section_code' => $sectionCode,
            'sort_order' => 900 + $round,
            'status' => 'pending',
            'plan_context' => [
                'page_goal' => $isShared ? '补足全站复用任务。' : ('补足 ' . $pageType . ' 页面当前缺失的执行任务。'),
                'block_goal' => $label,
                'field_plan' => [
                    [
                        'field' => 'headline',
                        'sample' => '新增任务块 ' . $round,
                        'reason' => '保证新增任务块在预览和 Markdown 中可辨识。',
                    ],
                ],
            ],
            'task_script' => [
                'scene' => $isShared ? ('shared:' . $sectionCode) : ($pageType . ':' . $sectionCode),
                'story_goal' => $label . '（fake-mode:' . $operationId . '）',
                'content_fill_rule' => '产出一个可直接观察到的新增任务块，包含最小必要字段、示例值与交付要求。',
                'stage3_directive' => '第三阶段按这个新增任务块继续生成，不需要额外补充上下文。',
                'field_content_requirements' => [
                    [
                        'field' => 'headline',
                        'sample' => '新增任务块 ' . $round,
                        'reason' => '用于证明新增任务块已经进入当前草稿。',
                    ],
                ],
            ],
            'implementation_contract' => [
                'acceptance' => [
                    '新增任务块可在当前阶段预览里直接看到。',
                    '新增任务块已带有清晰的字段示例和第三阶段执行说明。',
                ],
            ],
            'dependencies' => [],
            'result_ref' => [],
            'progress_weight' => 1.0,
        ];
    }

    /**
     * @param array<string, mixed> $task
     */
    private function resolveTaskBlockCode(array $task): string
    {
        $blockKey = \trim((string)($task['block_key'] ?? ''));
        if ($blockKey !== '') {
            return $blockKey;
        }
        $sectionCode = \trim((string)($task['section_code'] ?? ''));
        if ($sectionCode !== '') {
            return $sectionCode;
        }
        $taskKey = \trim((string)($task['task_key'] ?? ''));
        if ($taskKey !== '' && \str_contains($taskKey, ':')) {
            $parts = \explode(':', $taskKey);
            $tail = \trim((string)\end($parts));
            if ($tail !== '') {
                return $tail;
            }
        }
        return 'block';
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, array<string, array<string, mixed>>> $blockPlanMatrix
     */
    private function resolveTaskBlockCodeFromPlan(array $task, string $pageType, array $blockPlanMatrix): string
    {
        $sectionCode = \trim((string)($task['section_code'] ?? ''));
        if ($sectionCode !== '') {
            $pageBlocks = \is_array($blockPlanMatrix[$pageType] ?? null) ? $blockPlanMatrix[$pageType] : [];
            $taskAliases = $this->expandStageTwoTaskAlias($sectionCode, $pageType);
            foreach ($pageBlocks as $blockKey => $blockPlan) {
                if (!\is_array($blockPlan)) {
                    continue;
                }
                $planAliases = [];
                foreach ([
                    $blockKey,
                    $blockPlan['block_key'] ?? null,
                    $blockPlan['section_code'] ?? null,
                    $blockPlan['component_kind'] ?? null,
                    $blockPlan['title'] ?? null,
                ] as $value) {
                    if (!\is_scalar($value)) {
                        continue;
                    }
                    $planAliases = \array_merge($planAliases, $this->expandStageTwoTaskAlias(\trim((string)$value), $pageType));
                }
                if (\array_intersect($taskAliases, \array_values(\array_unique($planAliases))) !== []) {
                    return (string)$blockKey;
                }
            }
        }
        return $this->resolveTaskBlockCode($task);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     * @param callable|null $chunkCallback
     * @param callable|null $heartbeatCallback
     * @return array<string, mixed>|null
     */
    private function buildTaskPlanArtifactsByAi(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        ?callable $chunkCallback = null,
        ?callable $heartbeatCallback = null,
        ?callable $progressCallback = null
    ): array {
        return $this->buildTaskPlanArtifactsByAiInBatches(
            $scope,
            $buildBlueprint,
            $structured,
            $virtualThemePlan,
            $chunkCallback,
            $heartbeatCallback,
            'generate_task_plan',
            '',
            '',
            null,
            $progressCallback
        );

        $ai = $this->getAiService();
        if ($ai === null) {
            throw new \RuntimeException('AI task plan generation failed: AiService unavailable.');
        }
        $prompt = $this->buildTaskPlanGenerationPrompt($scope, $buildBlueprint, $structured, $virtualThemePlan);
        $requestParams = [
            'allow_zero_balance_provider' => true,
            'temperature' => 0.2,
            // detect_bootstrap 返回完整任务计划 JSON，需足够输出预算避免中途截断。
            'max_tokens' => 8192,
            'timeout' => 0,
            'disable_ai_timeout' => true,
            'disable_cli_timeout' => true,
        ];
        try {
            $raw = '';
            $decoded = null;
            $streamThrowable = null;
            $streamCallback = static function (string $chunk) use (&$raw, $chunkCallback): void {
                $raw .= $chunk;
                if ($chunkCallback !== null) {
                    $chunkCallback($chunk);
                }
                if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent()) {
                    SchedulerSystem::yieldDelay(1);
                }
            };
            try {
                $streamRequestParams = \array_merge($requestParams, [
                    'enforce_timeout_in_stream' => false,
                    'response_format' => ['type' => 'json_object'],
                ]);
                if ($heartbeatCallback !== null) {
                    $streamRequestParams['on_heartbeat'] = $heartbeatCallback;
                }
                $ai->generateStream(
                    $prompt,
                    $streamCallback,
                    null,
                    'pagebuilder_task_plan_generation',
                    null,
                    $streamRequestParams
                );
                $decoded = \json_decode($raw, true);
            } catch (\Throwable $throwable) {
                $streamThrowable = $throwable;
                $decoded = null;
            }
            if (!\is_array($decoded)) {
                $jsonRaw = (string)$ai->generate(
                    $prompt,
                    null,
                    'pagebuilder_task_plan_generation',
                    null,
                    \array_merge($requestParams, [
                        'response_format' => ['type' => 'json_object'],
                    ])
                );
                $decoded = \json_decode($jsonRaw, true);
            }
            if (!\is_array($decoded)) {
                throw new \RuntimeException(
                    'AI task plan generation failed: invalid JSON response.',
                    0,
                    $streamThrowable
                );
            }
            return $decoded;
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'AI task plan generation failed: ' . $throwable->getMessage(),
                (int)$throwable->getCode(),
                $throwable
            );
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     */
    private function buildTaskPlanGenerationPrompt(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan
    ): string {
        return $this->buildTaskPlanPromptBase(
            $scope,
            $buildBlueprint,
            $structured,
            $virtualThemePlan,
            'generate_task_plan'
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     * @param array<string, mixed> $payload
     */
    private function buildTaskPlanRefinePrompt(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        array $payload
    ): string {
        return $this->buildTaskPlanPromptBase(
            $scope,
            $buildBlueprint,
            $structured,
            $virtualThemePlan,
            'refine_task_plan',
            \trim((string)($payload['instruction'] ?? '')),
            \trim((string)($payload['target_scope'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     * @param array<string, mixed> $payload
     */
    private function buildTaskPlanRebuildPrompt(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        array $payload
    ): string {
        return $this->buildTaskPlanPromptBase(
            $scope,
            $buildBlueprint,
            $structured,
            $virtualThemePlan,
            'rebuild_task_plan',
            \trim((string)($payload['instruction'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     */
    private function buildTaskPlanPromptBase(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        string $mode,
        string $instruction = '',
        string $targetScope = ''
    ): string {
        $stage1PlanJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $stage1PlanMarkdown = \trim((string)($scope['plan_markdown'] ?? ''));
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $stage1TaskCues = \is_array($structured['stage1_task_cues'] ?? null) ? $structured['stage1_task_cues'] : [];
        $planLocale = \trim((string)($scope['plan_locale'] ?? ($scope['plan_workbench']['plan_locale'] ?? '')));
        $defaultLocale = \trim((string)($scope['default_locale'] ?? ''));
        $pageCoverage = \is_array($scope['page_coverage'] ?? null) ? $scope['page_coverage'] : [];
        $modeRules = match ($mode) {
            'refine_task_plan' => [
                'Mode: refine_task_plan.',
                'Only modify the user-specified target_scope and the minimum necessary linked content.',
                'Keep the rest of the confirmed plan and execution blueprint stable.',
                'Return a full replacement document for the affected scope, not an annotation patch.',
            ],
            'rebuild_task_plan' => [
                'Mode: rebuild_task_plan.',
                'Rebuild the entire second-stage task plan from the confirmed first-stage document.',
                'Do not inherit partial old draft content as default truth.',
                'Return a full rebuilt document, not a patch.',
            ],
            default => [
                'Mode: generate_task_plan.',
                'Derive the initial second-stage task plan from the confirmed first-stage document (user brief already expanded there).',
                'Output is a concrete task/asset plan, not meta instructions on how to write.',
                'Return the complete task-plan document.',
            ],
        };

        $userBrief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ($scope['plan_workbench']['stage1']['request_summary']['raw_requirement'] ?? '')));
        $siteDisplayName = \trim((string)($scope['site_title'] ?? ($stage1PlanJson['site_strategy']['site_display_name'] ?? '')));
        $oneLineRequirement = $userBrief !== '' ? $userBrief : ($instruction !== '' ? $instruction : '-');

        $lines = [
            // === 角色与意图：先讲清“做什么”，再讲格式 ===
            'You are PageBuilder AI planner for stage-2 virtual theme task planning of a real website.',
            'PRIMARY GOAL: Take the user one-line website requirement (already expanded in stage-1) and turn it into a CONCRETE EXECUTABLE TASK PLAN. Each task must contain real on-page copy samples, real field keys, real CTA labels, real link targets — NOT meta instructions on how to write.',
            '中文要求：第二阶段产出的是「真实可落地的任务方案」——把用户一句话需求经阶段一确认后的意图，进一步拓写为具体任务、字段示例、文案样例与执行顺序；严禁通篇 “围绕…/突出…/完善…/优化…/说明…” 这类元描述。',
            '【用户一句话需求】(authoritative): ' . $oneLineRequirement,
            '【站点名】: ' . ($siteDisplayName !== '' ? $siteDisplayName : '-'),
            '',
            'CONCRETENESS CONTRACT (must satisfy ALL):',
            '1) Every task carries REAL strings: nav labels, page titles, headings, body sentences, CTA labels, link targets, form field labels, trust points.',
            '2) task_script.story_goal describes a visible on-page outcome ("访客读到/看到 ___"), not a writing instruction ("撰写文案说明___").',
            '3) task_script.content_fill_rule enumerates each field to populate AND gives at least one concrete example value per critical field.',
            '4) field_content_requirements[].sample is final copy (or "[假设]" + still-concrete copy). Forbidden samples: "待补充", "突出卖点", "详见后文", "围绕主题展开".',
            '5) Reuse or improve concrete strings from confirmed stage-1 (nav labels, hero copy, footer link titles); never replace them with abstract descriptions.',
            '6) shared:header / shared:footer MUST list nav items and links by exact label + page_type or href; "nav TBD" / "补充政策链接" are invalid.',
            '',
            'GOOD vs BAD task examples (style only, do NOT copy verbatim):',
            'BAD task_script.story_goal     : "撰写首页 Hero 文案，突出产品价值"',
            'GOOD task_script.story_goal    : "访客在 5 秒内看到一句话价值『把发票、收入、税务一次理清』，并能点击 [免费试用 30 天]。"',
            'BAD task_script.content_fill_rule : "按品牌语气补充正文"',
            'GOOD task_script.content_fill_rule: "headline 用 14~22 字短句；subheadline 给出最大用户痛点，例：‘报税前 3 天总在翻收据？’；cta_primary 文案‘免费试用 30 天’，cta_secondary‘查看演示’。"',
            'BAD field_content_requirements[].sample : "突出卖点"',
            'GOOD field_content_requirements[].sample: "已有 1,200+ 自由职业者使用，每月平均节省 4 小时记账时间。"',
            'BAD shared:header nav item     : {"label":"导航1"}',
            'GOOD shared:header nav item    : {"label":"定价","page_type":"pricing_page","href":"/pricing"}',
            '',
            'Return STRICT JSON only.',
            'The first non-whitespace character must be { and the last non-whitespace character must be }.',
            'Do not wrap the response in markdown fences.',
            'Do not output explanations, comments, code fences, or any text outside JSON.',
            'The JSON root object must contain exactly these keys: markdown, virtual_theme_plan.',
            'This is the confirmed virtual-theme task plan for stage 2: output must be directly usable for virtual_theme_plan.confirmed persistence after user confirmation.',
            'The markdown field is the human-readable task-plan document.',
            'The virtual_theme_plan field is the structured execution source of truth.',
            'Output schema:',
            '{',
            '  "markdown": "string",',
            '  "virtual_theme_plan": {',
            '    "plan_signature": "string",',
            '    "task_script_brief": {"goal":"string","rule":"string"},',
            '    "virtual_theme_strategy": {},',
            '    "shared_tasks": [],',
            '    "page_tasks": {},',
            '    "block_task_schema": {"schema_version":"' . self::BLOCK_TASK_SCHEMA_VERSION . '","required_fields":["task_goal","meta_fields","content_plan","style_plan","planning_reason","sort_order"],"style_plan_required_keys":["color","font","spacing","responsive"]},',
            '    "task_tree": {},',
            '    "meta_field_matrix": {},',
            '    "style_tokens": {},',
            '    "content_rules": {},',
            '    "responsive_rules": {},',
            '    "execution_order": [],',
            '    "risk_notes": []',
            '  }',
            '}',
            'Hard rules:',
            '- Use the confirmed stage-1 plan as the only source of truth.',
            '- The second stage must拆解第一阶段方案文档，先形成 task_tree，再组装 execution_blueprint.tasks.',
            '- Each task_tree node must state what to do, why to do it, completion criteria, and dependencies.',
            '- Each task must be independently executable by one SSE session, with isolated context and buffered chunks.',
            '- Header and footer are global shared tasks and must appear explicitly.',
            '- Page-level tasks must cover every selected page, and only selected pages.',
            '- Do not invent unselected pages or omit selected pages.',
            '- Every task must include enough content detail for direct implementation in stage 3: a builder must produce theme/HTML without guessing; reuse or improve concrete CTA labels, nav labels, hero strings, and footer link titles from stage-1—always spell them out again here.',
            '- Every shared_tasks[] item MUST include planning_reason that explains why the shared block structure, field defaults, navigation/link grouping, style rules, and responsive behavior follow the confirmed stage-1 shared cues.',
            '- Every page_tasks[] item MUST include block_task with required fields task_goal, meta_fields, content_plan, style_plan, planning_reason, sort_order; this block_task is the minimum structured source of truth for one stage-2 block task.',
            '- For every page block task, use the matching extracted stage-1 task cue fields: block_goal drives task_goal, realtime_content drives content_plan examples, design_tags/style_direction drives style_plan, and reason drives planning_reason.',
            '- Preserve stage-1 design_tags in block_task.style_plan so stage 3 can recreate effects, shadows, radius, image treatment, motion timing, hover/interaction behavior, and responsive behavior.',
            '- Every planning_reason field MUST be concrete and traceable to stage-1 reason/implementation_detail; generic wording such as "needed for the page" is invalid.',
            '- Every block_task.style_plan MUST include concrete color, font, spacing, and responsive keys. The color key names palette/hex usage; font names family/weight/scale; spacing names section padding, card gaps, and radius rhythm; responsive names desktop/mobile behavior from the confirmed stage-1 plan.',
            '- Every task must include plan_context, implementation_contract, task_script, field_content_requirements, result_ref, completion_rule.',
            '- The markdown must explain concrete execution steps by shared tasks, page tasks, and task tree order; every section MUST name real labels, routes, field keys, and example copy—never-only phrases like "完善导航" or "优化体验" without specifics.',
            '- The stage-2 document must include page coverage, task tree, execution order, and risk notes.',
            '- The second stage must define task completion by status, and status changes drive progress and recovery.',
            '- The AI module must support session isolation for concurrent SSE runs; one prompt template can be used by many tasks, but each task must isolate session_id, task_key, and runtime state.',
            '- Concurrent tasks may run in parallel across pages/components, but they must not share streaming buffers or stateful caches.',
            '- Page completion may materialize a page immediately and open its visual editing SSE.',
            '- Component-level generation may also run concurrently in isolated SSE sessions.',
            '- Shared tasks and page tasks must preserve the confirmed locale rules: plan_locale for plan text, default_locale for content generation.',
            '- Stage-2 contract: derive ONLY from confirmed stage-1 markdown + plan_json + execution_blueprint; never invent requirements absent from stage-1.',
            '- Produce virtual_theme_plan fields: plan_signature, virtual_theme_strategy, shared_tasks, page_tasks, block_task_schema, task_tree, meta_field_matrix, style_tokens, content_rules, responsive_rules, execution_order, risk_notes.',
            '- shared:header must specify visuals, nav structure, brand slot, CTA slots, variable fields, defaults, responsive collapse rules, SEO/internal-link rationale; list each nav item as label + target page_type or href—no empty "nav TBD".',
            '- shared:footer must specify information groups, policy links, trust blocks, social/contact slots, variable fields, defaults, SEO/crawl rationale; each group MUST name the exact link labels users see.',
            '- Each page-type block task must include order, block goal, design rationale, content fields, variable meta, CTA direction, internal links, SEO keywords, and anchors; task_script.story_goal MUST describe a visible on-page outcome (what the visitor reads/sees), not a method like "撰写文案说明...".',
            '- task_script.content_fill_rule MUST enumerate fields to populate, allowed tone, and at least one concrete example sentence or value range per critical field.',
            '- field_content_requirements[].sample MUST be final or "[假设]" plus realistic copy (Chinese >=6 chars or English >=3 words); forbid "待补充", "突出卖点", "详见后文".',
            '- execution_order must follow: shared:header, shared:footer, home page tasks, then other page types in blueprint order.',
            '- If dependencies block ordering, explain why in risk_notes.',
            '- The task plan must make shared -> home -> other page execution explicit and explain why shared tasks block later tasks.',
            '- Use the confirmed page coverage report as the page scope authority.',
            '- For refine mode, only update target_scope and linked tasks; output change_scope_report.',
            '- For rebuild mode, output rebuild_summary and a full new task tree.',
            '- Final audit (silently before output): for each task verify (a) story_goal describes visible on-page outcome; (b) content_fill_rule enumerates fields with at least one concrete example value; (c) every field_content_requirements[].sample is concrete or "[假设]" + concrete; (d) every nav/link entry has real label and href/page_type; (e) no sentence relies only on verbs like "围绕/突出/说明/完善/优化". REWRITE any task that fails the audit before returning.',
        ];
        if ($planLocale !== '') {
            $lines[] = 'Plan locale: ' . $planLocale;
        }
        if ($defaultLocale !== '') {
            $lines[] = 'Default locale: ' . $defaultLocale;
        }
        if ($pageCoverage !== []) {
            $lines[] = 'Page coverage report:';
            $lines[] = \json_encode($pageCoverage, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '[]';
        }
        foreach ($modeRules as $rule) {
            $lines[] = '- ' . $rule;
        }
        if ($instruction !== '') {
            $lines[] = 'User instruction: ' . $instruction;
        }
        if ($targetScope !== '') {
            $lines[] = 'Target scope: ' . $targetScope;
        }
        $lines[] = 'Stage-1 plan_json:';
        $lines[] = \json_encode($stage1PlanJson, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Stage-1 plan_markdown:';
        $lines[] = $stage1PlanMarkdown !== '' ? $stage1PlanMarkdown : '-';
        $lines[] = 'Confirmed execution_blueprint:';
        $lines[] = \json_encode($executionBlueprint, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Current build_blueprint:';
        $lines[] = \json_encode($buildBlueprint, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Baseline virtual_theme_plan (must keep keys compatible):';
        $lines[] = \json_encode($virtualThemePlan, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Extracted stage-1 task cues:';
        $lines[] = \json_encode($stage1TaskCues, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Baseline structured:';
        $lines[] = \json_encode($structured, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';

        return \implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     * @param array<string, mixed> $aiTaskPlan
     * @return array{markdown:string,structured:array<string,mixed>,virtual_theme_plan:array<string,mixed>}
     */
    private function mergeAiTaskPlanArtifacts(array $structured, array $virtualThemePlan, array $aiTaskPlan): array
    {
        $markdown = \trim((string)($aiTaskPlan['markdown'] ?? ''));
        $aiVirtualThemePlan = \is_array($aiTaskPlan['virtual_theme_plan'] ?? null) ? $aiTaskPlan['virtual_theme_plan'] : [];
        if ($markdown === '' || $aiVirtualThemePlan === []) {
            throw new \RuntimeException('AI task plan generation failed: empty markdown or virtual_theme_plan.');
        }
        $mergedVirtualThemePlan = \array_replace_recursive($virtualThemePlan, $aiVirtualThemePlan);
        $mergedStructured = \array_replace_recursive($structured, $mergedVirtualThemePlan);
        $mergedStructured = $this->sanitizePromptLikeTaskPlanStructured($mergedStructured);
        $mergedStructured = $this->applyBlockTaskSchemaToStructured($mergedStructured);
        $mergedStructured = $this->ensureTaskDirectoryHierarchy($mergedStructured);
        $mergedStructured = $this->syncStageTwoTaskSortArtifacts($mergedStructured);
        $mergedVirtualThemePlan = \array_replace_recursive($mergedVirtualThemePlan, [
            'task_directory_tree' => $mergedStructured['task_directory_tree'] ?? [],
            'task_tree' => $mergedStructured['task_tree'] ?? [],
            'shared_block_tasks' => $mergedStructured['shared_block_tasks'] ?? [],
            'page_block_tasks' => $mergedStructured['page_block_tasks'] ?? [],
            'virtual_theme_build_tree' => $mergedStructured['virtual_theme_build_tree'] ?? [],
        ]);
        $this->assertAiTaskPlanIsContentful($mergedStructured);
        $mergedVirtualThemePlan['signature'] = $this->buildSignature($mergedStructured);
        return [
            'markdown' => $markdown,
            'structured' => $mergedStructured,
            'virtual_theme_plan' => $mergedVirtualThemePlan,
        ];
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function ensureTaskDirectoryHierarchy(array $structured): array
    {
        $existing = \is_array($structured['task_directory_tree'] ?? null) ? $structured['task_directory_tree'] : [];
        if ($existing !== []) {
            return $structured;
        }
        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        $executionOrder = \is_array($structured['execution_order'] ?? null) ? $structured['execution_order'] : [];

        $sharedNodes = [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $sharedNodes[] = [
                'task_key' => $taskKey,
                'label' => (string)($task['label'] ?? $taskKey),
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'group_key' => (string)($task['group_key'] ?? 'shared'),
            ];
        }

        $pageNodes = [];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            $nodes = [];
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $nodes[] = [
                    'task_key' => $taskKey,
                    'label' => (string)($task['label'] ?? $taskKey),
                    'sort_order' => (int)($task['sort_order'] ?? 0),
                    'section_code' => (string)($task['section_code'] ?? ''),
                ];
            }
            if ($nodes !== []) {
                $pageNodes[(string)$pageType] = [
                    'page_type' => (string)$pageType,
                    'label' => (string)$pageType,
                    'tasks' => $nodes,
                ];
            }
        }

        $structured['task_directory_tree'] = [
            'shared' => [
                'label' => 'shared',
                'tasks' => $sharedNodes,
            ],
            'pages' => $pageNodes,
            'execution_order' => $executionOrder,
        ];
        return $structured;
    }

    /**
     * @param array<string, mixed> $structured
     */
    private function assertAiTaskPlanIsContentful(array $structured): void
    {
        $contentLocale = $this->resolveStageTwoStructuredContentLocale($structured);
        $requiresEnglishContent = $this->isStageTwoEnglishLocale($contentLocale);
        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        if ($pageTasks === []) {
            throw new \RuntimeException('AI task plan generation failed: empty page_tasks.');
        }
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks) || $tasks === []) {
                throw new \RuntimeException('AI task plan generation failed: empty tasks for page ' . (string)$pageType);
            }
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    throw new \RuntimeException('AI task plan generation failed: invalid task node.');
                }
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
                $blockTask = \is_array($task['block_task'] ?? null) ? $task['block_task'] : [];
                $blockGoal = \trim((string)($planContext['block_goal'] ?? ''));
                $storyGoal = \trim((string)($taskScript['story_goal'] ?? ''));
                $contentFillRule = \trim((string)($taskScript['content_fill_rule'] ?? ''));
                $requirements = \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [];
                $missingScriptFields = [];
                if ($blockGoal === '') {
                    $missingScriptFields[] = 'plan_context.block_goal';
                }
                if ($storyGoal === '') {
                    $missingScriptFields[] = 'task_script.story_goal';
                }
                if ($contentFillRule === '') {
                    $missingScriptFields[] = 'task_script.content_fill_rule';
                }
                if ($requirements === []) {
                    $missingScriptFields[] = 'task_script.field_content_requirements';
                }
                if ($missingScriptFields !== []) {
                    throw new \RuntimeException(
                        'AI task plan generation failed: task script content is incomplete for '
                        . (string)($task['task_key'] ?? 'page task')
                        . ' missing '
                        . \implode(', ', $missingScriptFields)
                        . '.'
                    );
                }
                foreach (self::BLOCK_TASK_REQUIRED_FIELDS as $requiredField) {
                    if (!\array_key_exists($requiredField, $blockTask)) {
                        throw new \RuntimeException('AI task plan generation failed: block_task schema is incomplete.');
                    }
                }
                if (
                    \trim((string)($blockTask['task_goal'] ?? '')) === ''
                    || !\is_array($blockTask['meta_fields'] ?? null)
                    || $blockTask['meta_fields'] === []
                    || !\is_array($blockTask['content_plan'] ?? null)
                    || $blockTask['content_plan'] === []
                    || !\is_array($blockTask['style_plan'] ?? null)
                    || $blockTask['style_plan'] === []
                    || \trim((string)($blockTask['planning_reason'] ?? '')) === ''
                ) {
                    throw new \RuntimeException('AI task plan generation failed: block_task schema is incomplete.');
                }
                $stylePlan = \is_array($blockTask['style_plan'] ?? null) ? $blockTask['style_plan'] : [];
                foreach (['color', 'font', 'spacing', 'responsive'] as $styleKey) {
                    if (\trim((string)($stylePlan[$styleKey] ?? '')) === '') {
                        throw new \RuntimeException('AI task plan generation failed: block_task style_plan is incomplete.');
                    }
                }
                $hasSample = false;
                foreach ($requirements as $requirement) {
                    if (!\is_array($requirement)) {
                        continue;
                    }
                    if (\trim((string)($requirement['sample'] ?? '')) !== '') {
                        $hasSample = true;
                        break;
                    }
                }
                if (!$hasSample) {
                    throw new \RuntimeException('AI task plan generation failed: field samples are missing.');
                }
                if ($requiresEnglishContent && $this->stageTwoTaskHasCjkVisibleContent($taskScript, $blockTask)) {
                    throw new \RuntimeException('AI task plan generation failed: task visible content language does not match content_locale.');
                }
                if ($this->isStageTwoMetaInstructionLike($storyGoal)) {
                    throw new \RuntimeException('AI task plan generation failed: story_goal still contains blueprint guidance.');
                }
                if ($this->isStageTwoMetaInstructionLike($contentFillRule)) {
                    throw new \RuntimeException('AI task plan generation failed: content_fill_rule still contains blueprint guidance.');
                }
                foreach ($requirements as $requirement) {
                    if (!\is_array($requirement)) {
                        continue;
                    }
                    if ($this->isStageTwoMetaInstructionLike(\trim((string)($requirement['sample'] ?? '')))) {
                        throw new \RuntimeException('AI task plan generation failed: field sample still contains blueprint guidance.');
                    }
                }
            }
        }
    }

    private function resolveStageTwoStructuredContentLocale(array $structured): string
    {
        foreach ([
            $structured['stage2_context_snapshot']['content_locale'] ?? null,
            $structured['content_locale'] ?? null,
            $structured['site_context']['content_locale'] ?? null,
            $structured['virtual_theme_strategy']['content_locale'] ?? null,
            $structured['default_locale'] ?? null,
        ] as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $locale = \trim((string)$value);
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    private function isStageTwoEnglishLocale(string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        return $locale === 'en' || \str_starts_with($locale, 'en_') || \str_starts_with($locale, 'en-');
    }

    private function stageTwoTaskHasCjkVisibleContent(array $taskScript, array $blockTask): bool
    {
        $contentPlan = \is_array($blockTask['content_plan'] ?? null) ? $blockTask['content_plan'] : [];
        foreach ([
            $taskScript['field_content_requirements'] ?? [],
            $blockTask['meta_fields'] ?? [],
            $contentPlan['field_content_requirements'] ?? [],
            $contentPlan['content_copy'] ?? [],
            $contentPlan['cta_plan'] ?? [],
            $contentPlan['asset_plan'] ?? [],
        ] as $rows) {
            if (!\is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                foreach (['sample', 'default', 'copy', 'label', 'description', 'alt_text'] as $key) {
                    $value = \trim((string)($row[$key] ?? ''));
                    if ($value !== '' && $this->containsStageTwoCjkText($value)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function containsStageTwoCjkText(string $text): bool
    {
        return \preg_match('/\p{Han}/u', $text) === 1;
    }

    /**
     * Mutate the stage-2 draft task plan without breaking the structured aliases used by build/progress views.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $taskPatch
     * @return array{
     *     markdown:string,
     *     structured:array<string, mixed>,
     *     virtual_theme_plan:array<string, mixed>,
     *     mutation_summary:array<string, mixed>
     * }
     */
    public function mutateDraftTaskPlanTask(
        array $scope,
        string $action,
        string $bucket,
        string $pageType,
        string $taskKey,
        array $taskPatch = [],
        string $instruction = ''
    ): array {
        $normalizedAction = \strtolower(\trim($action));
        if (!\in_array($normalizedAction, ['refine', 'rebuild', 'delete', 'create'], true)) {
            throw new \RuntimeException('Unsupported stage-2 task mutation action: ' . $action);
        }

        $normalizedBucket = \strtolower(\trim($bucket)) === 'shared' ? 'shared' : 'page';
        $pageType = \trim($pageType);
        $taskKey = \trim($taskKey);
        $instruction = \trim($instruction);
        if ($normalizedAction !== 'create' && $taskKey === '') {
            throw new \RuntimeException('task_key is required for stage-2 task mutation.');
        }

        $structured = \is_array($scope['task_plan_structured'] ?? null)
            ? $scope['task_plan_structured']
            : (\is_array($scope['virtual_theme_plan']['draft'] ?? null) ? $scope['virtual_theme_plan']['draft'] : []);
        if ($structured === []) {
            throw new \RuntimeException('Stage-2 task plan draft is empty.');
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan']['draft'] ?? null)
            ? $scope['virtual_theme_plan']['draft']
            : $structured;
        $sharedTasks = \array_values(\array_filter(
            \is_array($structured['shared_tasks'] ?? null)
                ? $structured['shared_tasks']
                : (\is_array($virtualThemePlan['shared_tasks'] ?? null) ? $virtualThemePlan['shared_tasks'] : []),
            static fn($task): bool => \is_array($task)
        ));
        $pageTasks = \is_array($structured['page_tasks'] ?? null)
            ? $structured['page_tasks']
            : (\is_array($virtualThemePlan['page_tasks'] ?? null) ? $virtualThemePlan['page_tasks'] : []);

        if ($normalizedBucket === 'page' && $pageType === '') {
            $pageType = $this->resolveStageTwoTaskMutationPageType($pageTasks, $taskKey, $taskPatch);
        }
        if ($normalizedBucket === 'page' && $pageType === '') {
            throw new \RuntimeException('page_type is required for stage-2 page task mutation.');
        }

        $operationId = 'task_plan_' . \substr(\sha1($normalizedAction . '|' . $normalizedBucket . '|' . $pageType . '|' . $taskKey . '|' . \microtime(true)), 0, 12);
        $updatedAt = \date('Y-m-d H:i:s');
        $targetTaskKey = $taskKey;
        $mutationChanged = false;

        if ($normalizedBucket === 'shared') {
            [$sharedTasks, $targetTaskKey, $mutationChanged] = $this->mutateStageTwoTaskList(
                $sharedTasks,
                $normalizedAction,
                $taskKey,
                $taskPatch,
                $instruction,
                'shared',
                $operationId,
                $updatedAt
            );
        } else {
            $pageTaskList = \array_values(\array_filter(
                \is_array($pageTasks[$pageType] ?? null) ? $pageTasks[$pageType] : [],
                static fn($task): bool => \is_array($task)
            ));
            [$pageTaskList, $targetTaskKey, $mutationChanged] = $this->mutateStageTwoTaskList(
                $pageTaskList,
                $normalizedAction,
                $taskKey,
                $taskPatch,
                $instruction,
                $pageType,
                $operationId,
                $updatedAt
            );
            $pageTasks[$pageType] = $pageTaskList;
        }

        if (!$mutationChanged) {
            throw new \RuntimeException('Stage-2 task was not changed.');
        }

        $pageTypes = $this->resolveDraftTaskPlanPageTypes($scope, $structured, $pageTasks);
        if ($normalizedBucket === 'page' && $pageType !== '' && !\in_array($pageType, $pageTypes, true)) {
            $pageTypes[] = $pageType;
        }

        $sharedTasks = $this->normalizeStageTwoTaskSortOrderList($sharedTasks, 10);
        $orderedPageTasks = [];
        $pageBase = 100;
        foreach ($pageTypes as $resolvedPageType) {
            $tasks = \is_array($pageTasks[$resolvedPageType] ?? null) ? $pageTasks[$resolvedPageType] : [];
            if ($tasks === []) {
                continue;
            }
            $orderedPageTasks[$resolvedPageType] = $this->normalizeStageTwoTaskSortOrderList($tasks, $pageBase);
            $pageBase += 100;
        }
        foreach ($pageTasks as $resolvedPageType => $tasks) {
            if (isset($orderedPageTasks[$resolvedPageType]) || !\is_array($tasks) || $tasks === []) {
                continue;
            }
            $orderedPageTasks[(string)$resolvedPageType] = $this->normalizeStageTwoTaskSortOrderList($tasks, $pageBase);
            $pageBase += 100;
        }

        $previousVersion = \max(
            (int)($structured['task_plan_version'] ?? 0),
            (int)($virtualThemePlan['task_plan_version'] ?? 0)
        );
        $operationLog = \is_array($structured['task_plan_operation_log'] ?? null)
            ? $structured['task_plan_operation_log']
            : (\is_array($virtualThemePlan['task_plan_operation_log'] ?? null) ? $virtualThemePlan['task_plan_operation_log'] : []);
        $operationEntry = [
            'operation_id' => $operationId,
            'action' => $normalizedAction,
            'bucket' => $normalizedBucket,
            'page_type' => $normalizedBucket === 'shared' ? '' : $pageType,
            'task_key' => $targetTaskKey,
            'instruction' => $instruction,
            'updated_at' => $updatedAt,
            'version' => $previousVersion + 1,
        ];
        $operationLog[] = $operationEntry;

        $structured = $this->rebuildDraftTaskPlanStructure($structured, $sharedTasks, $orderedPageTasks, $pageTypes);
        $structured['task_plan_version'] = $previousVersion + 1;
        $structured['task_plan_operation_log'] = \array_values(\array_filter($operationLog, static fn($entry): bool => \is_array($entry)));
        $structured['last_task_plan_operation'] = $operationEntry;
        $structured['signature'] = $this->buildSignature($structured);

        $virtualThemePlan = \array_replace_recursive($virtualThemePlan, [
            'shared_tasks' => $structured['shared_tasks'],
            'page_tasks' => $structured['page_tasks'],
            'shared_block_tasks' => $structured['shared_block_tasks'] ?? [],
            'page_block_tasks' => $structured['page_block_tasks'] ?? [],
            'task_tree' => $structured['task_tree'],
            'virtual_theme_build_tree' => $structured['virtual_theme_build_tree'] ?? [],
            'execution_blueprint' => $structured['execution_blueprint'],
            'execution_order' => $structured['execution_order'],
            'task_directory_tree' => $structured['task_directory_tree'] ?? [],
            'task_plan_version' => $structured['task_plan_version'],
            'task_plan_operation_log' => $structured['task_plan_operation_log'],
            'last_task_plan_operation' => $operationEntry,
        ]);
        $virtualThemePlan['signature'] = (string)$structured['signature'];

        $markdown = $this->buildMarkdown($pageTypes, $structured['shared_tasks'], $structured['page_tasks'], $structured);

        return [
            'markdown' => $markdown,
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
            'mutation_summary' => $operationEntry,
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @param array<string, mixed> $taskPatch
     */
    private function resolveStageTwoTaskMutationPageType(array $pageTasks, string $taskKey, array $taskPatch): string
    {
        $patchedPageType = \trim((string)($taskPatch['page_type'] ?? ''));
        if ($patchedPageType !== '') {
            return $patchedPageType;
        }
        foreach ($pageTasks as $pageType => $tasks) {
            foreach (\is_array($tasks) ? $tasks : [] as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                if ((string)($task['task_key'] ?? '') === $taskKey) {
                    return (string)$pageType;
                }
            }
        }
        if (\preg_match('/^page:([^:]+):/i', $taskKey, $matches) === 1) {
            return (string)$matches[1];
        }
        return '';
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @param array<string, mixed> $taskPatch
     * @return array{0:list<array<string, mixed>>,1:string,2:bool}
     */
    private function mutateStageTwoTaskList(
        array $tasks,
        string $action,
        string $taskKey,
        array $taskPatch,
        string $instruction,
        string $scopeKey,
        string $operationId,
        string $updatedAt
    ): array {
        if ($action === 'create') {
            $task = $this->buildStageTwoTaskPlanMutationTask($scopeKey, $taskPatch, $instruction, $operationId);
            $tasks[] = $task;
            return [\array_values($tasks), (string)($task['task_key'] ?? ''), true];
        }

        foreach ($tasks as $index => $task) {
            if (!\is_array($task) || (string)($task['task_key'] ?? '') !== $taskKey) {
                continue;
            }
            if ($action === 'delete') {
                unset($tasks[$index]);
                return [\array_values($tasks), $taskKey, true];
            }
            $tasks[$index] = $this->applyStageTwoTaskMutationPatch($task, $taskPatch, $action, $instruction, $operationId, $updatedAt);
            return [\array_values($tasks), $taskKey, true];
        }

        throw new \RuntimeException('Stage-2 task not found: ' . $taskKey);
    }

    /**
     * @param array<string, mixed> $taskPatch
     * @return array<string, mixed>
     */
    private function buildStageTwoTaskPlanMutationTask(string $scopeKey, array $taskPatch, string $instruction, string $operationId): array
    {
        $isShared = $scopeKey === 'shared';
        $pageType = $isShared ? '' : $scopeKey;
        $label = \trim((string)($taskPatch['label'] ?? $taskPatch['title'] ?? $instruction ?? ''));
        if ($label === '') {
            $label = $isShared ? 'Custom shared task' : 'Custom page task';
        }
        $sectionCode = \trim((string)($taskPatch['section_code'] ?? $taskPatch['block_key'] ?? ''));
        if ($sectionCode === '') {
            $sectionCode = 'custom/' . $this->slugifyTaskPlanMutationPart($label !== '' ? $label : $operationId);
        }
        $taskKey = \trim((string)($taskPatch['task_key'] ?? ''));
        if ($taskKey === '') {
            $taskKey = $isShared ? ('shared:' . $sectionCode) : ('page:' . $pageType . ':' . $sectionCode);
        }

        $sortOrder = (int)($taskPatch['sort_order'] ?? 0);
        $blockTask = \is_array($taskPatch['block_task'] ?? null) ? $taskPatch['block_task'] : [];
        if ($blockTask === []) {
            $blockTask = [
                'schema_version' => 'stage2-block-task-v1',
                'task_goal' => $instruction !== '' ? $instruction : $label,
                'meta_fields' => [],
                'content_plan' => [
                    'story_goal' => $instruction !== '' ? $instruction : $label,
                    'content_fill_rule' => 'Use the confirmed stage-2 task requirements for this block.',
                ],
                'style_plan' => [],
                'planning_reason' => 'Manual stage-2 task created from the task-plan workbench.',
            ];
        }
        if ($sortOrder > 0) {
            $blockTask['sort_order'] = $sortOrder;
        }

        $task = \array_replace_recursive([
            'task_key' => $taskKey,
            'group_key' => $isShared ? 'shared' : $pageType,
            'page_type' => $pageType,
            'section_code' => $sectionCode,
            'label' => $label,
            'sort_order' => $sortOrder,
            'status' => 'todo',
            'plan_context' => [
                'page_goal' => $isShared ? 'Shared site task' : ('Task for ' . $pageType),
                'block_goal' => $instruction !== '' ? $instruction : $label,
            ],
            'task_script' => [
                'story_goal' => $instruction !== '' ? $instruction : $label,
                'content_fill_rule' => 'Follow the manually created stage-2 task requirements.',
                'stage3_directive' => 'Implement this task from the structured stage-2 task plan.',
            ],
            'implementation_contract' => [
                'acceptance' => ['Structured stage-2 task exists and is synchronized into build artifacts.'],
            ],
            'block_task' => $blockTask,
            'operation_log' => [],
        ], $taskPatch);
        $task['task_key'] = $taskKey;
        $task['group_key'] = $isShared ? 'shared' : $pageType;
        $task['page_type'] = $pageType;
        $task['section_code'] = $sectionCode;
        $task['operation_log'] = \is_array($task['operation_log'] ?? null) ? $task['operation_log'] : [];
        $task['operation_log'][] = [
            'operation_id' => $operationId,
            'action' => 'create',
            'instruction' => $instruction,
            'updated_at' => \date('Y-m-d H:i:s'),
        ];

        return $task;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $taskPatch
     * @return array<string, mixed>
     */
    private function applyStageTwoTaskMutationPatch(array $task, array $taskPatch, string $action, string $instruction, string $operationId, string $updatedAt): array
    {
        unset($taskPatch['task_key'], $taskPatch['group_key'], $taskPatch['page_type']);
        $updated = \array_replace_recursive($task, $taskPatch);
        if ($action === 'rebuild') {
            $updated['status'] = (string)($taskPatch['status'] ?? 'todo');
            $updated['rebuild_requested'] = 1;
        }
        if ($instruction !== '') {
            $updated['last_instruction'] = $instruction;
            $updated['plan_context'] = \array_replace(
                \is_array($updated['plan_context'] ?? null) ? $updated['plan_context'] : [],
                ['block_goal' => $instruction]
            );
            if (\is_array($updated['block_task'] ?? null)) {
                $updated['block_task']['task_goal'] = $instruction;
            }
        }
        $updated['updated_at'] = $updatedAt;
        $updated['last_operation'] = $action;
        $updated['operation_log'] = \is_array($updated['operation_log'] ?? null) ? $updated['operation_log'] : [];
        $updated['operation_log'][] = [
            'operation_id' => $operationId,
            'action' => $action,
            'instruction' => $instruction,
            'updated_at' => $updatedAt,
        ];

        return $updated;
    }

    private function slugifyTaskPlanMutationPart(string $value): string
    {
        $slug = \strtolower(\trim($value));
        $slug = (string)\preg_replace('/[^a-z0-9]+/i', '-', $slug);
        $slug = \trim($slug, '-');
        return $slug !== '' ? $slug : ('task-' . \substr(\sha1($value), 0, 8));
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $orderedTaskKeys
     * @return array{
     *     markdown:string,
     *     structured:array<string, mixed>,
     *     virtual_theme_plan:array<string, mixed>,
     *     reorder_summary:array<string, mixed>
     * }
     */
    public function reorderDraftTaskPlanTasks(array $scope, string $bucket, array $orderedTaskKeys, string $pageType = ''): array
    {
        $normalizedBucket = \strtolower(\trim($bucket)) === 'shared' ? 'shared' : 'page';
        $pageType = \trim($pageType);

        $structured = \is_array($scope['task_plan_structured'] ?? null)
            ? $scope['task_plan_structured']
            : (\is_array($scope['virtual_theme_plan']['draft'] ?? null) ? $scope['virtual_theme_plan']['draft'] : []);
        if ($structured === []) {
            throw new \RuntimeException('Stage-2 task plan draft is empty.');
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan']['draft'] ?? null)
            ? $scope['virtual_theme_plan']['draft']
            : $structured;
        $sharedTasks = \is_array($structured['shared_tasks'] ?? null)
            ? $structured['shared_tasks']
            : (\is_array($virtualThemePlan['shared_tasks'] ?? null) ? $virtualThemePlan['shared_tasks'] : []);
        $pageTasks = \is_array($structured['page_tasks'] ?? null)
            ? $structured['page_tasks']
            : (\is_array($virtualThemePlan['page_tasks'] ?? null) ? $virtualThemePlan['page_tasks'] : []);

        $originalOrder = [];
        if ($normalizedBucket === 'shared') {
            $originalOrder = \array_values(\array_map(
                static fn(array $task): string => (string)($task['task_key'] ?? ''),
                \array_values(\array_filter($sharedTasks, static fn($task): bool => \is_array($task)))
            ));
            $sharedTasks = $this->reorderStageTwoTaskList($sharedTasks, $orderedTaskKeys);
        } else {
            if ($pageType === '') {
                throw new \RuntimeException('Page type is required when reordering page tasks.');
            }
            $pageTaskList = \is_array($pageTasks[$pageType] ?? null) ? $pageTasks[$pageType] : [];
            if ($pageTaskList === []) {
                throw new \RuntimeException('Stage-2 page task list is empty for page type: ' . $pageType);
            }
            $originalOrder = \array_values(\array_map(
                static fn(array $task): string => (string)($task['task_key'] ?? ''),
                \array_values(\array_filter($pageTaskList, static fn($task): bool => \is_array($task)))
            ));
            $pageTasks[$pageType] = $this->reorderStageTwoTaskList($pageTaskList, $orderedTaskKeys);
        }

        $pageTypes = $this->resolveDraftTaskPlanPageTypes($scope, $structured, $pageTasks);
        $sharedTasks = $this->normalizeStageTwoTaskSortOrderList($sharedTasks, 10);

        $orderedPageTasks = [];
        $pageBase = 100;
        foreach ($pageTypes as $resolvedPageType) {
            $tasks = \is_array($pageTasks[$resolvedPageType] ?? null) ? $pageTasks[$resolvedPageType] : [];
            if ($tasks === []) {
                continue;
            }
            $orderedPageTasks[$resolvedPageType] = $this->normalizeStageTwoTaskSortOrderList($tasks, $pageBase);
            $pageBase += 100;
        }
        foreach ($pageTasks as $resolvedPageType => $tasks) {
            if (isset($orderedPageTasks[$resolvedPageType]) || !\is_array($tasks) || $tasks === []) {
                continue;
            }
            $orderedPageTasks[(string)$resolvedPageType] = $this->normalizeStageTwoTaskSortOrderList($tasks, $pageBase);
            $pageBase += 100;
        }

        $structured = $this->rebuildDraftTaskPlanStructure($structured, $sharedTasks, $orderedPageTasks, $pageTypes);
        $virtualThemePlan = \array_replace_recursive($virtualThemePlan, [
            'shared_tasks' => $structured['shared_tasks'],
            'page_tasks' => $structured['page_tasks'],
            'shared_block_tasks' => $structured['shared_block_tasks'] ?? [],
            'page_block_tasks' => $structured['page_block_tasks'] ?? [],
            'task_tree' => $structured['task_tree'],
            'virtual_theme_build_tree' => $structured['virtual_theme_build_tree'] ?? [],
            'execution_blueprint' => $structured['execution_blueprint'],
            'execution_order' => $structured['execution_order'],
            'task_directory_tree' => $structured['task_directory_tree'] ?? [],
        ]);
        $virtualThemePlan['signature'] = (string)($structured['signature'] ?? $this->buildSignature($structured));

        $markdown = $this->buildMarkdown($pageTypes, $sharedTasks, $orderedPageTasks, $structured);

        return [
            'markdown' => $markdown,
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
            'reorder_summary' => [
                'bucket' => $normalizedBucket,
                'page_type' => $pageType,
                'original_order' => $originalOrder,
                'ordered_task_keys' => \array_values(\array_filter(\array_map('strval', $orderedTaskKeys), static fn(string $taskKey): bool => \trim($taskKey) !== '')),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @param list<string> $orderedTaskKeys
     * @return list<array<string, mixed>>
     */
    private function reorderStageTwoTaskList(array $tasks, array $orderedTaskKeys): array
    {
        $orderMap = [];
        foreach ($orderedTaskKeys as $position => $taskKey) {
            $taskKey = \trim((string)$taskKey);
            if ($taskKey === '' || isset($orderMap[$taskKey])) {
                continue;
            }
            $orderMap[$taskKey] = $position;
        }

        $wrapped = [];
        foreach ($tasks as $index => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            $wrapped[] = [
                'index' => $index,
                'position' => $orderMap[$taskKey] ?? \PHP_INT_MAX,
                'task' => $task,
            ];
        }

        \usort($wrapped, static function (array $left, array $right): int {
            $positionCompare = ((int)$left['position']) <=> ((int)$right['position']);
            if ($positionCompare !== 0) {
                return $positionCompare;
            }
            return ((int)$left['index']) <=> ((int)$right['index']);
        });

        return \array_values(\array_map(static fn(array $row): array => $row['task'], $wrapped));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function normalizeStageTwoTaskSortOrderList(array $tasks, int $baseSortOrder): array
    {
        $normalized = [];
        foreach ($tasks as $index => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $sortOrder = $baseSortOrder + ($index * 10);
            if (\is_array($task['block_task'] ?? null)) {
                $task['block_task']['sort_order'] = $sortOrder;
            }
            $normalized[] = \array_replace($task, [
                'sort_order' => $sortOrder,
            ]);
        }
        return $normalized;
    }

    /**
     * Keep the stage-2 task ordering aliases and virtual-theme build seed in lockstep.
     *
     * @param array<string, mixed> $structured
     * @param list<string>|null $pageTypes
     * @return array<string, mixed>
     */
    private function syncStageTwoTaskSortArtifacts(array $structured, ?array $pageTypes = null): array
    {
        $sharedTasks = \array_values(\array_filter(
            \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [],
            static fn($task): bool => \is_array($task)
        ));
        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        $pageTypes = $pageTypes === null ? \array_keys($pageTasks) : $pageTypes;
        $pageTypes = \array_values(\array_filter(\array_map('strval', $pageTypes), static fn(string $pageType): bool => \trim($pageType) !== ''));

        $sharedBlockTasks = [];
        $jobSortOrders = [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $sharedBlockTasks[] = $this->buildStageTwoBlockTaskAlias($task, 'shared');
        }

        $pageBlockTasks = [];
        $buildTreePages = [];
        foreach ($pageTypes as $pageType) {
            $tasks = \array_values(\array_filter(
                \is_array($pageTasks[$pageType] ?? null) ? $pageTasks[$pageType] : [],
                static fn($task): bool => \is_array($task)
            ));
            if ($tasks === []) {
                continue;
            }
            \usort($tasks, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
            $pageTasks[$pageType] = $tasks;

            $pageBlocks = [];
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $alias = $this->buildStageTwoBlockTaskAlias($task, $pageType);
                $pageBlockTasks[] = $alias;
                $fanoutJobKey = $this->firstNonEmptyString([
                    $task['fanout_job_key'] ?? null,
                    \is_array($task['runtime_context'] ?? null) ? ($task['runtime_context']['fanout_job_key'] ?? null) : null,
                ]);
                if ($fanoutJobKey !== '') {
                    $jobSortOrders[$fanoutJobKey] = (int)($alias['sort_order'] ?? 0);
                }
                $pageBlocks[] = [
                    'node_key' => (string)($alias['task_key'] ?? ''),
                    'node_type' => 'block',
                    'task_key' => (string)($alias['task_key'] ?? ''),
                    'page_type' => $pageType,
                    'page_key' => $pageType,
                    'block_key' => (string)($alias['block_key'] ?? ''),
                    'label' => (string)($alias['label'] ?? ''),
                    'sort_order' => (int)($alias['sort_order'] ?? 0),
                    'task_status' => (string)($alias['task_status'] ?? 'pending'),
                ];
            }

            $buildTreePages[$pageType] = [
                'node_key' => 'page:' . $pageType,
                'node_type' => 'page',
                'page_type' => $pageType,
                'page_key' => $pageType,
                'blocks' => $pageBlocks,
            ];
        }

        $structured['shared_tasks'] = $sharedTasks;
        $structured['page_tasks'] = $pageTasks;
        $structured['shared_block_tasks'] = $sharedBlockTasks;
        $structured['page_block_tasks'] = $pageBlockTasks;
        $structured['virtual_theme_build_tree'] = [
            'node_key' => 'site',
            'node_type' => 'site',
            'source' => 'stage2_task_plan',
            'shared' => [
                'node_key' => 'shared',
                'node_type' => 'shared',
                'blocks' => \array_values(\array_map(static fn(array $task): array => [
                    'node_key' => (string)($task['task_key'] ?? ''),
                    'node_type' => 'shared_block',
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'block_key' => (string)($task['block_key'] ?? ''),
                    'label' => (string)($task['label'] ?? ''),
                    'sort_order' => (int)($task['sort_order'] ?? 0),
                    'task_status' => (string)($task['task_status'] ?? 'pending'),
                ], $sharedBlockTasks)),
            ],
            'pages' => $buildTreePages,
        ];

        if ($jobSortOrders !== [] && \is_array($structured['stage2_queue']['jobs'] ?? null)) {
            foreach ($structured['stage2_queue']['jobs'] as $jobKey => $job) {
                if (!\is_array($job) || !isset($jobSortOrders[(string)$jobKey])) {
                    continue;
                }
                $structured['stage2_queue']['jobs'][$jobKey]['sort_order'] = $jobSortOrders[(string)$jobKey];
            }
            $sequence = \is_array($structured['stage2_queue']['sequence'] ?? null)
                ? \array_values(\array_filter(\array_map('strval', $structured['stage2_queue']['sequence'])))
                : [];
            if ($sequence !== []) {
                \usort($sequence, static function (string $left, string $right) use ($jobSortOrders): int {
                    return ($jobSortOrders[$left] ?? \PHP_INT_MAX) <=> ($jobSortOrders[$right] ?? \PHP_INT_MAX);
                });
                $structured['stage2_queue']['sequence'] = $sequence;
            }
        }

        return $structured;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function buildStageTwoBlockTaskAlias(array $task, string $pageType): array
    {
        $sortOrder = (int)($task['sort_order'] ?? 0);
        $blockTask = \is_array($task['block_task'] ?? null) ? $task['block_task'] : [];
        if ($blockTask !== []) {
            $blockTask['sort_order'] = $sortOrder;
        }

        return [
            'task_key' => (string)($task['task_key'] ?? ''),
            'page_key' => $pageType,
            'page_type' => $pageType === 'shared' ? '' : $pageType,
            'block_key' => $this->resolveTaskBlockCode($task),
            'label' => (string)($task['label'] ?? $task['task_key'] ?? ''),
            'sort_order' => $sortOrder,
            'task_status' => (string)($task['status'] ?? 'pending'),
            'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
            'block_task' => $blockTask,
            'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
            'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @return list<string>
     */
    private function resolveDraftTaskPlanPageTypes(array $scope, array $structured, array $pageTasks): array
    {
        $pageTypes = \array_values(\array_filter(\array_map(
            'strval',
            \is_array($scope['execution_blueprint']['page_types'] ?? null)
                ? $scope['execution_blueprint']['page_types']
                : (\is_array($structured['responsive_rules']['page_types'] ?? null) ? $structured['responsive_rules']['page_types'] : [])
        ), static fn(string $pageType): bool => \trim($pageType) !== ''));

        foreach (\array_keys($pageTasks) as $pageType) {
            $pageType = (string)$pageType;
            if ($pageType === '' || \in_array($pageType, $pageTypes, true)) {
                continue;
            }
            $pageTypes[] = $pageType;
        }

        return $pageTypes;
    }

    /**
     * @param array<string, mixed> $structured
     * @param list<array<string, mixed>> $sharedTasks
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function rebuildDraftTaskPlanStructure(array $structured, array $sharedTasks, array $pageTasks, array $pageTypes): array
    {
        $executionOrder = [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $executionOrder[] = [
                'task_key' => (string)($task['task_key'] ?? ''),
                'group_key' => (string)($task['group_key'] ?? 'shared'),
                'page_type' => (string)($task['page_type'] ?? ''),
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
            ];
        }
        foreach ($pageTypes as $pageType) {
            foreach (\is_array($pageTasks[$pageType] ?? null) ? $pageTasks[$pageType] : [] as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $executionOrder[] = [
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'group_key' => (string)($task['group_key'] ?? $pageType),
                    'page_type' => (string)($task['page_type'] ?? $pageType),
                    'sort_order' => (int)($task['sort_order'] ?? 0),
                    'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                ];
            }
        }

        $existingTaskTree = \is_array($structured['task_tree'] ?? null) ? $structured['task_tree'] : [];
        $taskTree = [
            'root' => \array_replace([
                'node_key' => 'root',
                'node_type' => 'site',
                'task_key' => 'site:virtual_theme',
                'status' => 'pending',
                'completion_rule' => 'first-stage confirmed plan fully decomposed into stage-2 execution tasks',
            ], \is_array($existingTaskTree['root'] ?? null) ? $existingTaskTree['root'] : []),
            'shared' => [],
            'pages' => [],
        ];

        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = (string)($task['task_key'] ?? 'shared:task');
            $taskTree['shared'][] = [
                'node_key' => $taskKey,
                'parent_key' => 'root',
                'node_type' => 'shared',
                'task_key' => $taskKey,
                'status' => (string)($task['status'] ?? 'pending'),
                'goal' => (string)($task['label'] ?? $taskKey),
                'reason' => (string)($task['reason'] ?? ''),
                'inputs' => ['task_key' => $taskKey, 'page_type' => ''],
                'outputs' => ['result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : []],
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                'completion_rule' => (string)($task['completion_rule'] ?? 'shared task complete when its output can be reused globally'),
                'resource_plan' => [
                    'field_plan' => \is_array($task['field_plan'] ?? null) ? $task['field_plan'] : [],
                    'content_brief' => \is_array($task['content_brief'] ?? null) ? $task['content_brief'] : [],
                ],
                'parallel_group' => 'shared',
                'children' => [],
            ];
        }

        foreach ($pageTypes as $pageType) {
            foreach (\is_array($pageTasks[$pageType] ?? null) ? $pageTasks[$pageType] : [] as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = (string)($task['task_key'] ?? ($pageType . ':task'));
                $taskTree['pages'][$pageType][] = [
                    'node_key' => $taskKey,
                    'parent_key' => 'shared',
                    'node_type' => 'page_task',
                    'task_key' => $taskKey,
                    'page_type' => $pageType,
                    'status' => (string)($task['status'] ?? 'pending'),
                    'goal' => (string)($task['label'] ?? $taskKey),
                    'reason' => (string)($task['plan_context']['block_goal'] ?? $task['reason'] ?? ''),
                    'inputs' => ['task_key' => $taskKey, 'page_type' => $pageType],
                    'outputs' => ['result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : []],
                    'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                    'completion_rule' => (string)($task['completion_rule'] ?? 'page task complete when the page can be materialized and edited'),
                    'resource_plan' => [
                        'field_plan' => \is_array($task['field_plan'] ?? null) ? $task['field_plan'] : [],
                        'content_brief' => \is_array($task['content_brief'] ?? null) ? $task['content_brief'] : [],
                        'seo_brief' => \is_array($task['seo_brief'] ?? null) ? $task['seo_brief'] : [],
                    ],
                    'parallel_group' => 'page:' . $pageType,
                    'children' => [],
                ];
            }
        }

        $executionBlueprintTasks = [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $executionBlueprintTasks[] = \array_replace($task, [
                'task_key' => (string)($task['task_key'] ?? ''),
                'from_node_key' => (string)($task['task_key'] ?? ''),
                'group_key' => (string)($task['group_key'] ?? 'shared'),
                'task_group' => 'shared',
                'page_type' => '',
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'parent_task_key' => 'root',
                'can_parallel' => (bool)($task['can_parallel'] ?? true),
                'materialize_after_done' => (bool)($task['materialize_after_done'] ?? false),
                'materialize_policy' => (string)($task['materialize_policy'] ?? 'none'),
                'prompt_template_key' => (string)($task['prompt_template_key'] ?? 'stage2_task_execute'),
                'prompt_variables' => \is_array($task['prompt_variables'] ?? null) ? $task['prompt_variables'] : [
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'page_type' => '',
                ],
            ]);
        }
        foreach ($pageTypes as $pageType) {
            foreach (\is_array($pageTasks[$pageType] ?? null) ? $pageTasks[$pageType] : [] as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $executionBlueprintTasks[] = \array_replace($task, [
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'from_node_key' => (string)($task['task_key'] ?? ''),
                    'group_key' => (string)($task['group_key'] ?? $pageType),
                    'task_group' => $pageType === 'home_page' ? 'home' : 'other',
                    'page_type' => $pageType,
                    'sort_order' => (int)($task['sort_order'] ?? 0),
                    'parent_task_key' => (string)($task['parent_task_key'] ?? 'shared'),
                    'can_parallel' => (bool)($task['can_parallel'] ?? true),
                    'materialize_after_done' => (bool)($task['materialize_after_done'] ?? true),
                    'materialize_policy' => (string)($task['materialize_policy'] ?? 'page'),
                    'prompt_template_key' => (string)($task['prompt_template_key'] ?? 'stage2_task_execute'),
                    'prompt_variables' => \is_array($task['prompt_variables'] ?? null) ? $task['prompt_variables'] : [
                        'task_key' => (string)($task['task_key'] ?? ''),
                        'page_type' => $pageType,
                    ],
                ]);
            }
        }
        \usort($executionBlueprintTasks, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
        $executionBlueprintTasks = $this->normalizeExecutionBlueprintTasks($executionBlueprintTasks);

        $existingExecutionBlueprint = \is_array($structured['execution_blueprint'] ?? null) ? $structured['execution_blueprint'] : [];
        $executionBlueprintPlan = \array_replace($existingExecutionBlueprint, [
            'signature' => (string)($existingExecutionBlueprint['signature'] ?? ''),
            'tasks' => \array_values($executionBlueprintTasks),
            'task_count' => \count($executionBlueprintTasks),
            'task_groups' => [
                'shared' => \array_values(\array_map(static fn(array $task): array => [
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'status' => (string)($task['status'] ?? 'pending'),
                    'can_parallel' => (bool)($task['can_parallel'] ?? true),
                    'materialize_after_done' => (bool)($task['materialize_after_done'] ?? false),
                    'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
                ], $sharedTasks)),
                'pages' => [],
            ],
        ]);
        foreach ($pageTypes as $pageType) {
            $executionBlueprintPlan['task_groups']['pages'][$pageType] = \array_values(\array_map(static fn(array $task): array => [
                'task_key' => (string)($task['task_key'] ?? ''),
                'status' => (string)($task['status'] ?? 'pending'),
                'can_parallel' => (bool)($task['can_parallel'] ?? true),
                'materialize_after_done' => (bool)($task['materialize_after_done'] ?? true),
                'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
            ], \is_array($pageTasks[$pageType] ?? null) ? $pageTasks[$pageType] : []));
        }

        $structured['shared_tasks'] = \array_values($sharedTasks);
        $structured['page_tasks'] = $pageTasks;
        $structured['task_tree'] = $taskTree;
        $structured['execution_blueprint'] = $executionBlueprintPlan;
        $structured['execution_order'] = $executionOrder;
        unset($structured['task_directory_tree']);
        $structured = $this->ensureTaskDirectoryHierarchy($structured);
        $structured = $this->syncStageTwoTaskSortArtifacts($structured, $pageTypes);
        $structured['signature'] = $this->buildSignature($structured);

        return $structured;
    }

    private function getAiService(): ?AiService
    {
        if ($this->aiService instanceof AiService) {
            return $this->aiService;
        }
        $candidate = ObjectManager::getInstance(AiService::class);
        return $candidate instanceof AiService ? $candidate : null;
    }
}
