<?php

declare(strict_types=1);

namespace Weline\Agent\Service\Resumable;

use Weline\Agent\Service\AgentRuntimeService;
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

/**
 * 通用智能体对话回合（detached, replayable）
 *
 * 承载 Weline_Agent 角色会话：session/role/model/project 解析、
 * 项目标记注入、工具循环与统一事件流（agent_info/chunk/tool_call/
 * tool_result/done）全部由 AgentRuntimeService + AgentEngine 完成。
 *
 * 与 ai.chat_generation 的差异：本任务面向「角色化智能体会话」，
 * 消息持久化在 weline_agent_chat_message，前端经事件流增量渲染。
 */
final class AgentChatTurnTaskHandler implements ResumableTaskStartHandlerInterface
{
    private const TYPE_CODE = 'agent.chat_turn';
    private const MAX_MESSAGE_BYTES = 16_384;
    private const MAX_RESPONSE_BYTES = 1_048_576;
    private const EFFECT_KEY = 'agent_chat_turn';

    public function __construct(private readonly AgentRuntimeService $runtime)
    {
    }

    public function typeCode(): string
    {
        return self::TYPE_CODE;
    }

    public function prepareStart(TaskOwner $owner, array $input): TaskStartRequest
    {
        if (!$this->isChatOwner($owner)) {
            throw new ResumableTaskAccessDeniedException('Agent chat turn requires a valid browser owner.');
        }

        $message = $this->requiredString($input, 'message', self::MAX_MESSAGE_BYTES);
        $requestId = $this->requiredIdentifier($input, 'request_id');

        $turn = [
            'prompt' => $message,
            'session_id' => $this->optionalInt($input, 'session_id'),
            'role_id' => $this->optionalInt($input, 'role_id'),
            'role_code' => $this->optionalString($input, 'role_code', 64),
            'model_code' => $this->optionalString($input, 'model_code', 128),
            'project_id' => $this->optionalInt($input, 'project_id'),
            'agent_code' => $this->optionalString($input, 'agent_code', 64),
            'system_prompt_override' => $this->optionalString($input, 'system_prompt_override', 8192),
            'channel' => $this->optionalString($input, 'channel', 32) ?? 'web',
            'context_id' => $this->optionalString($input, 'context_id', 128),
        ];

        return new TaskStartRequest(
            input: [
                'turn' => $turn,
                'request_id' => $requestId,
            ],
            businessKey: 'agent.chat:' . hash('sha256', $owner->principal . "\0" . $requestId),
            policy: TaskPolicy::defaults(),
        );
    }

    /** 与 ai.chat_generation 同一浏览器 owner 口径：frontend 会话或 backend 登录主体 */
    private function isChatOwner(TaskOwner $owner): bool
    {
        if ($owner->area === 'backend') {
            return str_starts_with($owner->principal, 'backend:')
                && $owner->sessionId !== null
                && $owner->sessionId !== '';
        }

        if ($owner->area !== 'frontend') {
            return false;
        }

        if (str_starts_with($owner->principal, 'frontend:')) {
            return true;
        }

        $sessionId = $owner->sessionId;
        return $sessionId !== null
            && $sessionId !== ''
            && hash_equals('session:' . $sessionId, $owner->principal);
    }

    public function execute(
        ResumableTaskContextInterface $context,
        array $input,
        ?TaskCheckpoint $checkpoint,
    ): TaskResult {
        $turn = is_array($input['turn'] ?? null) ? $input['turn'] : [];
        $promptHash = hash('sha256', (string)($turn['prompt'] ?? ''));

        if ($checkpoint?->cursor === 'generated') {
            $response = (string)($checkpoint->state['response'] ?? '');
            if ($response !== '') {
                return TaskResult::completed([
                    'message' => $response,
                    'recovered_from_checkpoint' => true,
                ]);
            }
        }

        $effect = $context->reserveEffect(self::EFFECT_KEY);
        if ($effect->alreadyExisted) {
            if ($effect->state === TaskEffectState::APPLIED) {
                $response = (string)($effect->result['response'] ?? '');
                if ($response !== '') {
                    $context->saveCheckpoint('generated', [
                        'prompt_hash' => $promptHash,
                        'response' => $response,
                        'effect_key' => self::EFFECT_KEY,
                    ]);
                    return TaskResult::completed([
                        'message' => $response,
                        'recovered_from_effect_ledger' => true,
                    ]);
                }
            }

            $context->markEffectUnknown(self::EFFECT_KEY);
            return new TaskResult(
                ResumableTaskStatus::RECOVERY_UNSAFE,
                [
                    'attempt' => $context->attempt(),
                    'effect_key' => self::EFFECT_KEY,
                    'prompt_hash' => $promptHash,
                ],
                'external_effect_unknown',
                (string)__('智能体回合在确认结果前中断，无法安全重试。'),
            );
        }

        $context->saveCheckpoint('before_turn', [
            'prompt_hash' => $promptHash,
            'effect_key' => self::EFFECT_KEY,
            'request_id' => (string)($input['request_id'] ?? ''),
        ]);

        if ($checkpoint !== null) {
            $context->emit('attempt_reset', [
                'attempt' => $context->attempt(),
                'reason' => 'runner_recovered',
            ]);
        }

        $response = '';
        $resolvedSessionId = 0;
        $turn['on_event'] = function (string $type, array $data = []) use ($context, &$resolvedSessionId): void {
            $context->throwIfStopRequested();
            $context->heartbeat();
            if ($type === 'agent_info' && isset($data['session_id'])) {
                $resolvedSessionId = (int)$data['session_id'];
            }
            // 统一事件协议：type 直接作为事件名，attempt 随包携带
            $context->emit($type, ['attempt' => $context->attempt()] + $data);
        };
        $turn['stream_callback'] = function (string $content) use (&$response, $context): void {
            $context->throwIfStopRequested();
            $context->heartbeat();
            if ($content === '') {
                return;
            }
            if (strlen($response) + strlen($content) > self::MAX_RESPONSE_BYTES) {
                throw new \RuntimeException('Agent chat turn exceeds the resumable runtime response limit.');
            }
            $response .= $content;
            $context->emit('chunk', [
                'attempt' => $context->attempt(),
                'chunk' => $content,
            ]);
        };

        try {
            $result = $this->runtime->runTurn($turn);
        } catch (\Throwable $e) {
            return TaskResult::failed(
                'agent_turn_failed',
                (string)__('智能体回合执行失败：') . $e->getMessage(),
            );
        }

        $context->throwIfStopRequested();

        if (!$result->success) {
            return TaskResult::failed(
                'agent_turn_failed',
                (string)($result->error ?: 'Agent turn failed'),
                ['agent_code' => $result->agentCode ?? ''],
            );
        }

        $response = (string)$result->content;
        $context->completeEffect(self::EFFECT_KEY, ['response' => $response]);
        $context->saveCheckpoint('generated', [
            'prompt_hash' => $promptHash,
            'effect_key' => self::EFFECT_KEY,
            'response' => $response,
        ]);
        $context->emit('done', [
            'attempt' => $context->attempt(),
            'response_bytes' => strlen($response),
        ]);

        return TaskResult::completed([
            'message' => $response,
            'session_id' => $resolvedSessionId > 0 ? $resolvedSessionId : $this->extractSessionId($turn),
            'agent_code' => $result->agentCode ?? '',
            'model_code' => $result->modelCode ?? '',
            'attempt' => $context->attempt(),
        ]);
    }

    /** runTurn 内部会解析/创建会话；从 turn 输入尽力回填 session_id 供前端关联 */
    private function extractSessionId(array $turn): int
    {
        return (int)($turn['session_id'] ?? 0);
    }

    /** @param array<string|int,mixed> $input */
    private function requiredString(array $input, string $key, int $maxBytes): string
    {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '' || strlen($value) > $maxBytes) {
            throw new \InvalidArgumentException('Invalid agent chat turn input: ' . $key);
        }
        return $value;
    }

    /** @param array<string|int,mixed> $input */
    private function requiredIdentifier(array $input, string $key): string
    {
        $value = trim((string)($input[$key] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid agent chat turn input: ' . $key);
        }
        return $value;
    }

    /** @param array<string|int,mixed> $input */
    private function optionalString(array $input, string $key, int $maxBytes): ?string
    {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Invalid agent chat turn input: ' . $key);
        }
        return $value;
    }

    /** @param array<string|int,mixed> $input */
    private function optionalInt(array $input, string $key): int
    {
        $value = $input[$key] ?? 0;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int)$value;
        }
        return 0;
    }
}
