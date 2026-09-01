<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\Resumable;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\I18n\Service\Resumable\TaglibLocalBulkTranslationTaskHandler;
use Weline\I18n\Service\Resumable\TaglibLocalBulkTranslationTaskProcessor;

final class TaglibLocalBulkTranslationTaskHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('__')) {
            eval('function __(string $text, array $args = []): string { return $text; }');
        }
    }

    public function testPrepareStartBuildsOwnerScopedBusinessKey(): void
    {
        $processor = $this->createMock(TaglibLocalBulkTranslationTaskProcessor::class);
        $processor->expects(self::once())
            ->method('freezeInput')
            ->willReturn([
                'model' => 'Weline\\Demo\\Model\\Local',
                'record_id' => 3,
                'retranslate_all' => false,
                'request_id' => 'demo-local-bulk-1',
                'steps' => [
                    ['field' => 'title', 'label' => 'Title', 'value' => 'Hello', 'status' => 'pending'],
                ],
            ]);
        $handler = new TaglibLocalBulkTranslationTaskHandler($processor);
        $owner = new TaskOwner('backend', 'backend:9', websiteId: 0);

        $request = $handler->prepareStart($owner, [
            'model' => 'Weline\\Demo\\Model\\Local',
            'record_id' => 3,
            'request_id' => 'demo-local-bulk-1',
            'fields' => [
                ['field' => 'title', 'label' => 'Title', 'value' => 'Hello'],
            ],
        ]);

        self::assertSame(
            'i18n.taglib_local_bulk:backend:9:3:demo-local-bulk-1',
            $request->businessKey,
        );
        self::assertSame('Weline\\Demo\\Model\\Local', $request->input['model']);
        self::assertSame(3, $request->input['record_id']);
    }

    public function testPrepareStartRejectsFrontendOwner(): void
    {
        $this->expectException(ResumableTaskAccessDeniedException::class);

        $processor = $this->createMock(TaglibLocalBulkTranslationTaskProcessor::class);
        (new TaglibLocalBulkTranslationTaskHandler($processor))
            ->prepareStart(
                new TaskOwner('frontend', 'frontend:9', websiteId: 0),
                ['model' => 'Weline\\Demo\\Model\\Local', 'record_id' => 1, 'request_id' => 'x', 'fields' => []],
            );
    }

    public function testExecuteEmitsProgressPerFieldAndCompletes(): void
    {
        $processor = $this->createMock(TaglibLocalBulkTranslationTaskProcessor::class);
        $processor->expects(self::once())
            ->method('translateStep')
            ->willReturn([
                'success' => true,
                'message' => 'ok',
                'data' => ['translated' => 2, 'skipped' => 0],
            ]);

        $handler = new TaglibLocalBulkTranslationTaskHandler($processor);
        $input = [
            'model' => 'Weline\\Demo\\Model\\Local',
            'record_id' => 3,
            'retranslate_all' => false,
            'request_id' => 'demo-local-bulk-2',
            'steps' => [
                ['field' => 'title', 'label' => 'Title', 'value' => 'Hello', 'status' => 'pending'],
                ['field' => 'summary', 'label' => 'Summary', 'value' => '', 'status' => 'skipped_empty'],
            ],
        ];
        $context = new InMemoryTaglibLocalBulkContext();

        $result = $handler->execute($context, $input, null);

        self::assertSame('completed', $result->status->value);
        self::assertSame('bulk_completed', $context->checkpoint()?->cursor);
        self::assertContains('start', array_column($context->events, 'event'));
        self::assertContains('progress', array_column($context->events, 'event'));
        self::assertContains('completed', array_column($context->events, 'event'));
    }
}

final class InMemoryTaglibLocalBulkContext implements ResumableTaskContextInterface
{
    private ?TaskCheckpoint $current = null;
    private int $version = 0;
    /** @var list<array{event:string,payload:array<string|int,mixed>}> */
    public array $events = [];

    public function taskId(): string
    {
        return 'i18n-local-bulk-task-1';
    }

    public function attempt(): int
    {
        return 1;
    }

    public function checkpoint(): ?TaskCheckpoint
    {
        return $this->current;
    }

    public function saveCheckpoint(string $cursor, array $state, int $schemaVersion = 1): TaskCheckpoint
    {
        return $this->current = new TaskCheckpoint(
            $this->taskId(),
            ++$this->version,
            $cursor,
            $state,
            $schemaVersion,
        );
    }

    public function emit(string $event, array $payload, ?string $coalesceKey = null): int
    {
        $this->events[] = ['event' => $event, 'payload' => $payload];
        return count($this->events);
    }

    public function reserveEffect(string $effectKey): never
    {
        throw new \BadMethodCallException('Not used in this unit test.');
    }

    public function completeEffect(string $effectKey, array $result = []): void
    {
    }

    public function isStopRequested(): bool
    {
        return false;
    }

    public function throwIfStopRequested(): void
    {
    }

    public function heartbeat(): void
    {
    }
}
