<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Resumable;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;
use Weline\Ai\Service\Resumable\ChatGenerationTaskHandler;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEffectReservation;
use Weline\Framework\Runtime\Resumable\TaskEffectState;
use Weline\Framework\Runtime\Resumable\TaskOwner;

final class ChatGenerationTaskHandlerTestAiService extends AiService
{
    public int $calls = 0;

    /** @var list<array{prompt:string,model_code:?string,scenario_code:?string,locale:?string,params:array<string,mixed>}> */
    public array $requests = [];

    /** @var list<string> */
    public array $chunks = ['Hello', ' world'];

    public function __construct()
    {
    }

    public function generateStream(
        string $prompt,
        callable $callback,
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        ?string $locale = null,
        array $params = [],
    ): void {
        $this->calls++;
        $this->requests[] = [
            'prompt' => $prompt,
            'model_code' => $modelCode,
            'scenario_code' => $scenarioCode,
            'locale' => $locale,
            'params' => $params,
        ];
        foreach ($this->chunks as $chunk) {
            $callback($chunk);
        }
    }
}

final class ChatGenerationTaskHandlerTestContext implements ResumableTaskContextInterface
{
    /** @var list<array{cursor:string,state:array<string|int,mixed>}> */
    public array $savedCheckpoints = [];

    /** @var list<array{event:string,payload:array<string|int,mixed>,coalesce_key:?string}> */
    public array $events = [];

    /** @var array<string,array<string|int,mixed>> */
    public array $completedEffects = [];

    /** @var list<string> */
    public array $unknownEffects = [];

    public int $heartbeats = 0;
    public bool $stopRequested = false;

    public function __construct(
        private TaskEffectReservation $effect,
        private readonly int $currentAttempt = 1,
        private ?TaskCheckpoint $currentCheckpoint = null,
    ) {
    }

    public function taskId(): string
    {
        return 'task-chat-0001';
    }

    public function attempt(): int
    {
        return $this->currentAttempt;
    }

    public function checkpoint(): ?TaskCheckpoint
    {
        return $this->currentCheckpoint;
    }

    public function saveCheckpoint(string $cursor, array $state, int $schemaVersion = 1): TaskCheckpoint
    {
        $this->savedCheckpoints[] = ['cursor' => $cursor, 'state' => $state];
        $this->currentCheckpoint = new TaskCheckpoint(
            taskId: $this->taskId(),
            version: count($this->savedCheckpoints),
            cursor: $cursor,
            state: $state,
            schemaVersion: $schemaVersion,
            attempt: $this->currentAttempt,
            fencingGeneration: 1,
            createdAt: 1_700_000_000 + count($this->savedCheckpoints),
        );
        return $this->currentCheckpoint;
    }

    public function emit(string $event, array $payload, ?string $coalesceKey = null): int
    {
        $this->events[] = [
            'event' => $event,
            'payload' => $payload,
            'coalesce_key' => $coalesceKey,
        ];
        return count($this->events);
    }

    public function reserveEffect(string $effectKey): TaskEffectReservation
    {
        return $this->effect;
    }

    public function completeEffect(string $effectKey, array $result = []): void
    {
        $this->completedEffects[$effectKey] = $result;
    }

    public function markEffectUnknown(string $effectKey): void
    {
        $this->unknownEffects[] = $effectKey;
    }

    public function isStopRequested(): bool
    {
        return $this->stopRequested;
    }

    public function throwIfStopRequested(): void
    {
        if ($this->stopRequested) {
            throw new \RuntimeException('Stop requested.');
        }
    }

    public function heartbeat(): void
    {
        $this->heartbeats++;
    }
}

final class ChatGenerationTaskHandlerTest extends TestCase
{
    public function testPrepareStartFreezesTrustedInputAndOwnerScopedBusinessKey(): void
    {
        $handler = new ChatGenerationTaskHandler(new ChatGenerationTaskHandlerTestAiService());
        $start = $handler->prepareStart(
            new TaskOwner('frontend', 'frontend:42', 'session-42', 0),
            [
                'message' => '  Hello  ',
                'request_id' => 'request-0001',
                'model_code' => 'chat-model',
                'scenario_code' => 'support',
                'locale' => 'zh_CN',
                'untrusted' => 'must not persist',
            ],
        );

        self::assertSame(
            'ai.chat:' . hash('sha256', "frontend:42\0request-0001"),
            $start->businessKey,
        );
        self::assertLessThanOrEqual(191, strlen($start->businessKey));
        self::assertSame([
            'message' => 'Hello',
            'request_id' => 'request-0001',
            'model_code' => 'chat-model',
            'scenario_code' => 'support',
            'locale' => 'zh_CN',
        ], $start->input);
    }

    public function testPrepareStartRejectsNonFrontendOwner(): void
    {
        $handler = new ChatGenerationTaskHandler(new ChatGenerationTaskHandlerTestAiService());

        $this->expectException(ResumableTaskAccessDeniedException::class);
        $handler->prepareStart(
            new TaskOwner('backend', 'admin:7'),
            ['message' => 'Hello', 'request_id' => 'request-0001'],
        );
    }

    public function testFirstAttemptWritesCheckpointsEventsAndEffectLedger(): void
    {
        $ai = new ChatGenerationTaskHandlerTestAiService();
        $context = new ChatGenerationTaskHandlerTestContext(new TaskEffectReservation(
            taskId: 'task-chat-0001',
            effectKey: 'chat_generation',
            state: TaskEffectState::RESERVED,
        ));
        $result = (new ChatGenerationTaskHandler($ai))->execute(
            $context,
            $this->input(),
            null,
        );

        self::assertSame(ResumableTaskStatus::COMPLETED, $result->status);
        self::assertSame('Hello world', $result->data['message']);
        self::assertSame(['before_generation', 'generated'], array_column($context->savedCheckpoints, 'cursor'));
        self::assertSame(['start', 'chunk', 'message_completed'], array_column($context->events, 'event'));
        self::assertSame('Hello world', $context->completedEffects['chat_generation']['response']);
        self::assertSame(2, $context->heartbeats);
        self::assertSame('task-chat-0001:chat_generation', $ai->requests[0]['params']['idempotency_key']);
        self::assertSame('task-chat-0001', $ai->requests[0]['params']['resumable_task_id']);
        self::assertTrue($ai->requests[0]['params']['allow_zero_balance_provider']);
    }

    public function testRecoveryWithUnconfirmedEffectRefusesToDuplicateProviderCall(): void
    {
        $ai = new ChatGenerationTaskHandlerTestAiService();
        $checkpoint = new TaskCheckpoint(
            taskId: 'task-chat-0001',
            version: 1,
            cursor: 'before_generation',
            state: ['prompt_hash' => hash('sha256', 'Hello')],
            attempt: 1,
            fencingGeneration: 1,
            createdAt: 1_700_000_001,
        );
        $context = new ChatGenerationTaskHandlerTestContext(
            new TaskEffectReservation(
                taskId: 'task-chat-0001',
                effectKey: 'chat_generation',
                state: TaskEffectState::RESERVED,
                alreadyExisted: true,
            ),
            currentAttempt: 2,
            currentCheckpoint: $checkpoint,
        );

        $result = (new ChatGenerationTaskHandler($ai))->execute($context, $this->input(), $checkpoint);

        self::assertSame(ResumableTaskStatus::RECOVERY_UNSAFE, $result->status);
        self::assertSame(['chat_generation'], $context->unknownEffects);
        self::assertSame(0, $ai->calls);
    }

    public function testRecoveryUsesAppliedEffectWithoutCallingProviderAgain(): void
    {
        $ai = new ChatGenerationTaskHandlerTestAiService();
        $context = new ChatGenerationTaskHandlerTestContext(
            new TaskEffectReservation(
                taskId: 'task-chat-0001',
                effectKey: 'chat_generation',
                state: TaskEffectState::APPLIED,
                alreadyExisted: true,
                result: ['response' => 'Previously confirmed'],
            ),
            currentAttempt: 2,
        );

        $result = (new ChatGenerationTaskHandler($ai))->execute($context, $this->input(), null);

        self::assertSame(ResumableTaskStatus::COMPLETED, $result->status);
        self::assertTrue((bool)$result->data['recovered_from_effect_ledger']);
        self::assertSame('Previously confirmed', $result->data['message']);
        self::assertSame(0, $ai->calls);
        self::assertSame('generated', $context->savedCheckpoints[0]['cursor']);
    }

    /** @return array<string,string> */
    private function input(): array
    {
        return [
            'message' => 'Hello',
            'request_id' => 'request-0001',
            'model_code' => 'chat-model',
            'scenario_code' => 'support',
            'locale' => 'zh_CN',
        ];
    }
}
