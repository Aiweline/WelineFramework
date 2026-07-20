<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Resumable;

use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Framework\Runtime\Resumable\CheckpointCodec;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStartHandlerInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEffectState;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskPolicy;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\TaskStartRequest;
use Weline\Theme\Service\ThemeAiDraftService;
use Weline\Theme\Service\ThemeAiPayloadValidator;

/**
 * Shared durable implementation for Theme component creation and refinement.
 *
 * The saved state is deliberately application data only: prompt hashes, the
 * confirmed agent result, validation data, and a saved draft version ID. PHP
 * Fibers, callbacks, provider sockets, Request/Session instances, and ORM
 * objects never cross a checkpoint boundary.
 */
abstract class AbstractThemeComponentTaskHandler implements ResumableTaskStartHandlerInterface
{
    private const MAX_AGENT_RESPONSE_BYTES = 1_048_576;
    private const EFFECT_GENERATION = 'ai_generation';
    private const EFFECT_DRAFT = 'draft_persist';

    public function __construct(
        private readonly AiRuntimeInterface $aiRuntime,
        private readonly ThemeAiDraftService $draftService,
        private readonly ThemeAiPayloadValidator $payloadValidator,
    ) {
    }

    abstract protected function isRefine(): bool;

    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest
    {
        $this->assertBackendOwner($owner);
        $trustedInput = $this->normalizeInput($input);

        return new TaskStartRequest(
            input: $trustedInput,
            businessKey: 'theme.component.' . ($this->isRefine() ? 'refine' : 'generate')
                . ':' . $owner->principal . ':' . $trustedInput['request_id'],
            policy: TaskPolicy::defaults(),
        );
    }

    public function execute(
        ResumableTaskContextInterface $context,
        array $input,
        ?TaskCheckpoint $checkpoint,
    ): TaskResult {
        $input = $this->normalizeInput($input);
        $state = is_array($checkpoint?->state) ? $checkpoint->state : [];
        $inputHash = hash('sha256', CheckpointCodec::encode($input));

        if ($checkpoint?->cursor === 'draft_events_emitted'
            && is_array($state['result'] ?? null)) {
            return TaskResult::completed($state['result']);
        }

        $draftVersionId = (int)($state['draft_version_id'] ?? 0);
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : null;
        if ($draftVersionId > 0 && $payload !== null) {
            return $this->completeDraft($context, $input, $inputHash, $draftVersionId, $payload);
        }

        $agent = is_array($state['agent'] ?? null) ? $state['agent'] : null;
        if ($agent === null || trim((string)($agent['content'] ?? '')) === '') {
            $agent = $this->generateAgentResult($context, $input, $inputHash);
            if ($agent instanceof TaskResult) {
                return $agent;
            }
        }

        $content = trim((string)($agent['content'] ?? ''));
        $payload = $this->payloadValidator->extractPayload($content);
        if ($payload === null) {
            $context->saveCheckpoint('validation_failed', [
                'input_hash' => $inputHash,
                'agent' => $agent,
                'validation_errors' => ['AI 输出不是有效 JSON'],
            ]);
            $context->emit('validation', [
                'valid' => false,
                'errors' => ['AI 输出不是有效 JSON'],
            ]);
            return TaskResult::failed(
                'validation_failed',
                (string)__('AI 输出不是有效 JSON'),
                ['attempt' => $context->attempt()],
            );
        }

        $validation = $this->payloadValidator->validatePayload($payload);
        $context->saveCheckpoint('validated', [
            'input_hash' => $inputHash,
            'agent' => $agent,
            'payload' => $validation['payload'],
            'validation' => $validation,
        ]);
        $context->emit('validation', [
            'valid' => (bool)$validation['valid'],
            'errors' => $validation['errors'],
        ]);
        if (!$validation['valid']) {
            return TaskResult::failed(
                'validation_failed',
                (string)__('虚拟部件校验未通过'),
                ['attempt' => $context->attempt(), 'errors' => $validation['errors']],
            );
        }

        $payload = $validation['payload'];
        $draftVersionId = (int)($state['draft_version_id'] ?? 0);
        if ($draftVersionId > 0) {
            return $this->completeDraft($context, $input, $inputHash, $draftVersionId, $payload);
        }

        $context->saveCheckpoint('before_draft_persist', [
            'input_hash' => $inputHash,
            'agent' => $agent,
            'payload' => $payload,
            'validation' => $validation,
            'effect_key' => self::EFFECT_DRAFT,
        ]);
        $effect = $context->reserveEffect(self::EFFECT_DRAFT);
        if ($effect->alreadyExisted) {
            if ($effect->state === TaskEffectState::APPLIED) {
                $draftVersionId = (int)($effect->result['draft_version_id'] ?? 0);
                if ($draftVersionId > 0) {
                    $context->saveCheckpoint('draft_saved', [
                        'input_hash' => $inputHash,
                        'draft_version_id' => $draftVersionId,
                        'payload' => $payload,
                        'recovered_from_effect_ledger' => true,
                    ]);
                    return $this->completeDraft($context, $input, $inputHash, $draftVersionId, $payload);
                }
            }

            return $this->recoveryUnsafe($context, self::EFFECT_DRAFT, $inputHash);
        }

        $componentData = array_merge($payload, [
            'theme_id' => $input['theme_id'],
            'area' => $input['area'],
            'agent_code' => (string)($agent['agent_code'] ?? $input['agent_code']),
            'model_code' => (string)($agent['model_code'] ?? $input['model_code'] ?? ''),
            'is_ai_generated' => true,
        ]);
        if ($this->isRefine()) {
            $current = $this->draftService->buildDefinitionForVersion((int)$input['draft_version_id']);
            if ($current === null) {
                return TaskResult::failed('draft_not_found', (string)__('草稿版本不存在'));
            }
            $componentData['component_code'] = $current->code;
            $componentData['category'] = $current->category;
        }

        $prompt = $this->buildPrompt($input);
        $draft = $this->draftService->saveDraft($componentData, [
            'template_content' => $payload['template_content'],
            'config_schema' => $payload['config_schema_json'],
            'default_config' => $payload['default_config_json'],
            'generation_meta' => [
                'mode' => $this->isRefine() ? 'refine' : 'create',
                'runtime_task_id' => $context->taskId(),
                'input_hash' => $inputHash,
            ],
            'prompt' => $prompt,
            'agent_code' => (string)($agent['agent_code'] ?? $input['agent_code']),
            'model_code' => (string)($agent['model_code'] ?? $input['model_code'] ?? ''),
            'validation' => $validation,
        ]);
        $draftVersionId = (int)$draft->getId();
        if ($draftVersionId <= 0) {
            throw new \RuntimeException('Theme AI draft persistence did not return a version ID.');
        }

        $context->completeEffect(self::EFFECT_DRAFT, [
            'draft_version_id' => $draftVersionId,
            'component_id' => (int)$draft->getComponentId(),
        ]);
        $context->saveCheckpoint('draft_saved', [
            'input_hash' => $inputHash,
            'draft_version_id' => $draftVersionId,
            'payload' => $payload,
        ]);

        return $this->completeDraft($context, $input, $inputHash, $draftVersionId, $payload);
    }

    /**
     * @return array<string,mixed>|TaskResult
     */
    private function generateAgentResult(
        ResumableTaskContextInterface $context,
        array $input,
        string $inputHash,
    ): array|TaskResult {
        $context->saveCheckpoint('before_generation', [
            'input_hash' => $inputHash,
            'mode' => $this->isRefine() ? 'refine' : 'create',
            'effect_key' => self::EFFECT_GENERATION,
        ]);
        $effect = $context->reserveEffect(self::EFFECT_GENERATION);
        if ($effect->alreadyExisted) {
            if ($effect->state === TaskEffectState::APPLIED) {
                $agent = $this->agentFromEffect($effect->result);
                if ($agent !== null) {
                    $context->saveCheckpoint('agent_completed', [
                        'input_hash' => $inputHash,
                        'agent' => $agent,
                        'recovered_from_effect_ledger' => true,
                    ]);
                    return $agent;
                }
            }
            return $this->recoveryUnsafe($context, self::EFFECT_GENERATION, $inputHash);
        }

        $context->emit('start', [
            'attempt' => $context->attempt(),
            'message' => $this->isRefine()
                ? (string)__('开始微调虚拟部件草稿')
                : (string)__('开始生成虚拟部件草稿'),
            'theme_id' => $input['theme_id'],
            'area' => $input['area'],
            'agent_code' => $input['agent_code'],
        ]);

        $lastCheckpointAt = microtime(true);
        $result = $this->aiRuntime->executeAgent(
            $input['agent_code'],
            $this->buildPrompt($input),
            $input['model_code'],
            [
                'theme_id' => $input['theme_id'],
                'area' => $input['area'],
                'category' => $input['category'],
                'component_code' => $input['component_code'],
                'reference_code' => $input['reference_component_code'],
                'language' => $input['locale'],
                'max_tokens' => 16_000,
                'timeout' => 180,
                'resumable_task_id' => $context->taskId(),
                'idempotency_key' => $effect->externalIdempotencyKey(),
            ],
            function (string $eventType, array $data) use ($context, $inputHash, &$lastCheckpointAt): void {
                $context->throwIfStopRequested();
                $context->heartbeat();
                $now = microtime(true);
                if (($now - $lastCheckpointAt) >= TaskPolicy::DEFAULT_CHECKPOINT_MAX_INTERVAL_SECONDS) {
                    $context->saveCheckpoint('generating', [
                        'input_hash' => $inputHash,
                        'effect_key' => self::EFFECT_GENERATION,
                    ]);
                    $lastCheckpointAt = $now;
                }

                if ($eventType === 'heartbeat') {
                    return;
                }
                if ($eventType === 'iteration') {
                    $context->emit('agent_status', [
                        'status' => 'iteration',
                        'message' => (string)__('智能体进入新一轮思考'),
                        'iteration' => (int)($data['iteration'] ?? 0),
                        'max' => (int)($data['max'] ?? 0),
                    ]);
                    return;
                }

                $event = in_array($eventType, ['thinking', 'tool_call', 'tool_result', 'chunk', 'agent_status'], true)
                    ? $eventType
                    : 'agent_status';
                $context->emit($event, $data, $event === 'chunk'
                    ? 'theme_component_chunk:' . $context->attempt()
                    : null);
            },
        );

        if (!(bool)($result->success ?? false)) {
            $message = trim((string)($result->error ?? '')) ?: (string)__('智能体执行失败');
            $context->saveCheckpoint('agent_failed', [
                'input_hash' => $inputHash,
                'effect_key' => self::EFFECT_GENERATION,
                'error' => $message,
            ]);
            return TaskResult::failed('ai_generation_failed', $message, [
                'attempt' => $context->attempt(),
                'iterations' => (int)($result->iterations ?? 0),
            ]);
        }

        $content = (string)($result->content ?? '');
        if ($content === '' || strlen($content) > self::MAX_AGENT_RESPONSE_BYTES) {
            return TaskResult::failed(
                'ai_generation_invalid',
                (string)__('智能体响应为空或超过可恢复任务限制'),
                ['attempt' => $context->attempt()],
            );
        }

        $agent = [
            'content' => $content,
            'agent_code' => (string)($result->agentCode ?? $input['agent_code']),
            'model_code' => (string)($result->modelCode ?? $input['model_code'] ?? ''),
            'iterations' => (int)($result->iterations ?? 0),
        ];
        $context->completeEffect(self::EFFECT_GENERATION, $agent);
        $context->saveCheckpoint('agent_completed', [
            'input_hash' => $inputHash,
            'agent' => $agent,
        ]);

        return $agent;
    }

    /**
     * @param array<string|int,mixed> $result
     * @return array<string,mixed>|null
     */
    private function agentFromEffect(array $result): ?array
    {
        $content = (string)($result['content'] ?? '');
        if ($content === '' || strlen($content) > self::MAX_AGENT_RESPONSE_BYTES) {
            return null;
        }
        return [
            'content' => $content,
            'agent_code' => (string)($result['agent_code'] ?? ''),
            'model_code' => (string)($result['model_code'] ?? ''),
            'iterations' => (int)($result['iterations'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function completeDraft(
        ResumableTaskContextInterface $context,
        array $input,
        string $inputHash,
        int $draftVersionId,
        array $payload,
    ): TaskResult {
        $previewHtml = '';
        try {
            $previewHtml = $this->draftService->renderPreview($draftVersionId, $input['preview_config']);
        } catch (\Throwable) {
            // The draft is already durable. Preview rendering can be retried by
            // the UI without creating another draft version.
        }
        $definition = $this->draftService->buildDefinitionForVersion($draftVersionId);
        $version = $this->draftService->loadVersion($draftVersionId);
        $result = [
            'success' => true,
            'draft_version_id' => $draftVersionId,
            'component_id' => $definition?->componentId,
            'component_code' => $definition?->code,
            'version_no' => $version?->getVersionNo(),
            'preview_html' => $previewHtml,
            'payload' => $payload,
            'attempt' => $context->attempt(),
        ];

        $context->emit('preview_ready', [
            'draft_version_id' => $draftVersionId,
            'preview_html' => $previewHtml,
        ]);
        $context->emit('draft_saved', [
            'draft_version_id' => $draftVersionId,
            'component_id' => $definition?->componentId,
            'component_code' => $definition?->code,
            'version_no' => $version?->getVersionNo(),
        ]);
        $context->saveCheckpoint('draft_events_emitted', [
            'input_hash' => $inputHash,
            'draft_version_id' => $draftVersionId,
            'payload' => $payload,
            'result' => $result,
        ]);

        return TaskResult::completed($result);
    }

    private function recoveryUnsafe(
        ResumableTaskContextInterface $context,
        string $effectKey,
        string $inputHash,
    ): TaskResult {
        $context->markEffectUnknown($effectKey);
        return new TaskResult(
            ResumableTaskStatus::RECOVERY_UNSAFE,
            [
                'effect_key' => $effectKey,
                'input_hash' => $inputHash,
                'attempt' => $context->attempt(),
            ],
            'external_effect_unknown',
            (string)__('主题 AI 副作用在确认结果前中断，无法安全恢复。'),
        );
    }

    /** @return array<string,mixed> */
    private function normalizeInput(array $input): array
    {
        $trusted = [
            'request_id' => $this->requiredIdentifier($input, 'request_id'),
            'theme_id' => $this->requiredPositiveInt($input, 'theme_id'),
            'area' => $this->area($input),
            // The task type selects the only trusted Theme component agent.
            // Browser input cannot choose an arbitrary agent implementation.
            'agent_code' => 'theme_component_builder',
            'model_code' => $this->optionalCode($input, 'model_code', 128),
            'locale' => $this->optionalCode($input, 'locale', 32) ?? 'zh_Hans_CN',
            'category' => $this->optionalCode($input, 'category', 64) ?? 'basic',
            'component_code' => $this->optionalCode($input, 'component_code', 160) ?? '',
            'reference_component_code' => $this->optionalCode($input, 'reference_component_code', 160) ?? '',
            'slot_id' => $this->optionalCode($input, 'slot_id', 160) ?? '',
            'name' => $this->optionalText($input, 'name', 512),
            'description' => $this->optionalText($input, 'description', 8_192),
            'requirements' => $this->optionalText($input, 'requirements', 16_384),
            'instructions' => $this->optionalText($input, 'instructions', 16_384),
            'preview_config' => $this->previewConfig($input),
        ];
        if ($this->isRefine()) {
            $trusted['draft_version_id'] = $this->requiredPositiveInt($input, 'draft_version_id');
        }
        return $trusted;
    }

    private function buildPrompt(array $input): string
    {
        if ($this->isRefine()) {
            $definition = $this->draftService->buildDefinitionForVersion((int)$input['draft_version_id']);
            if ($definition === null) {
                throw new \InvalidArgumentException((string)__('草稿版本不存在'));
            }
            $currentPayload = [
                'name' => $definition->name,
                'description' => $definition->description,
                'category' => $definition->category,
                'component_code' => $definition->code,
                'template_content' => $definition->templateContent,
                'config_schema_json' => $definition->configSchema,
                'default_config_json' => $definition->defaultConfig,
                'meta_json' => $definition->meta,
            ];
            return "Refine the existing Weline Theme virtual component.\n"
                . "Current payload:\n"
                . json_encode($currentPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                . "\nRefine instructions:\n"
                . ($input['instructions'] !== '' ? $input['instructions'] : $input['description'])
                . "\nReturn only one JSON object.\n";
        }

        $prompt = "Generate a Weline Theme virtual component JSON.\n";
        $prompt .= "name: {$input['name']}\n";
        $prompt .= "description: {$input['description']}\n";
        $prompt .= "category: {$input['category']}\n";
        if ($input['component_code'] !== '') {
            $prompt .= "component_code: {$input['component_code']}\n";
        }
        if ($input['slot_id'] !== '') {
            $prompt .= "preferred_slot: {$input['slot_id']}\n";
        }
        if ($input['reference_component_code'] !== '') {
            $prompt .= "reference_component_code: {$input['reference_component_code']}\n";
            $prompt .= "Please inspect the reference component before final output.\n";
        }
        if ($input['requirements'] !== '') {
            $prompt .= "extra_requirements: {$input['requirements']}\n";
        }
        return $prompt . "Return only one JSON object.\n";
    }

    private function assertBackendOwner(TaskOwner $owner): void
    {
        if ($owner->area !== 'backend' || !str_starts_with($owner->principal, 'backend:')) {
            throw new ResumableTaskAccessDeniedException('Theme component tasks require a backend owner.');
        }
    }

    private function requiredIdentifier(array $input, string $key): string
    {
        $value = trim((string)($input[$key] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid Theme component input: ' . $key);
        }
        return $value;
    }

    private function requiredPositiveInt(array $input, string $key): int
    {
        $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new \InvalidArgumentException('Invalid Theme component input: ' . $key);
        }
        return (int)$value;
    }

    private function area(array $input): string
    {
        $area = strtolower(trim((string)($input['area'] ?? 'frontend')));
        if (!in_array($area, ['frontend', 'backend'], true)) {
            throw new \InvalidArgumentException('Invalid Theme component input: area');
        }
        return $area;
    }

    private function optionalCode(array $input, string $key, int $maxBytes): ?string
    {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maxBytes || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid Theme component input: ' . $key);
        }
        return $value;
    }

    private function optionalText(array $input, string $key, int $maxBytes): string
    {
        $value = trim((string)($input[$key] ?? ''));
        if (strlen($value) > $maxBytes || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Invalid Theme component input: ' . $key);
        }
        return $value;
    }

    /** @return array<string|int,mixed> */
    private function previewConfig(array $input): array
    {
        $config = $input['preview_config'] ?? [];
        if ($config === null) {
            return [];
        }
        if (!is_array($config)) {
            throw new \InvalidArgumentException('Invalid Theme component input: preview_config');
        }
        $encoded = CheckpointCodec::encode(['preview_config' => $config]);
        if (strlen($encoded) > 65_536) {
            throw new \InvalidArgumentException('Theme component preview config exceeds its size limit.');
        }
        return $config;
    }
}
