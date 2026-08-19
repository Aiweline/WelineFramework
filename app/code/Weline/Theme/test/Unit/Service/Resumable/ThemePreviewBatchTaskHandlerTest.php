<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Resumable;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Resumable\ResumableTaskContextInterface;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEffectReservation;
use Weline\Framework\Runtime\Resumable\TaskEffectState;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Resumable\ThemePreviewBatchTaskHandler;
use Weline\Theme\Service\Resumable\ThemePreviewTaskProcessor;

final class ThemePreviewBatchTaskHandlerTest extends TestCase
{
    public function testExecutePreservesTheFrozenCaptureBaseUrl(): void
    {
        $processor = new class(new WelineTheme()) extends ThemePreviewTaskProcessor {
            /** @var list<array<string,mixed>> */
            public array $receivedTargets = [];

            public function runTarget(array $target, ?callable $heartbeat = null): array
            {
                $this->receivedTargets[] = $target;
                $heartbeat?->__invoke();

                return [
                    'key' => (string)$target['key'],
                    'theme_id' => (int)$target['theme_id'],
                    'area' => (string)$target['area'],
                    'success' => true,
                    'image_path' => 'theme_previews/theme_1_frontend.png',
                    'image_url' => '/theme_previews/theme_1_frontend.png',
                ];
            }
        };
        $handler = new ThemePreviewBatchTaskHandler($processor);
        $context = new InMemoryThemePreviewContext();

        $result = $handler->execute($context, [
            'targets' => [[
                'key' => 'theme_1_frontend',
                'theme_id' => 1,
                'area' => 'frontend',
                'force' => true,
                'capture_base_url' => 'https://p05113ef3.weline.test:9555',
            ]],
        ], null);

        self::assertSame('completed', $result->status->value);
        self::assertCount(1, $processor->receivedTargets);
        self::assertSame(
            'https://p05113ef3.weline.test:9555',
            $processor->receivedTargets[0]['capture_base_url'] ?? null,
        );
    }
}

final class InMemoryThemePreviewContext implements ResumableTaskContextInterface
{
    private ?TaskCheckpoint $current = null;
    private int $version = 0;

    public function taskId(): string
    {
        return 'theme-preview-task-1';
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
        return 1;
    }

    public function reserveEffect(string $effectKey): TaskEffectReservation
    {
        return new TaskEffectReservation(
            $this->taskId(),
            $effectKey,
            TaskEffectState::RESERVED,
        );
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
