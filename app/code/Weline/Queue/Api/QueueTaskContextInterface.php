<?php

declare(strict_types=1);

namespace Weline\Queue\Api;

use Weline\Framework\Async\TaskContextInterface;

/**
 * Persistence-neutral task context passed to queue consumers.
 *
 * The public contract deliberately exposes queue behavior rather than the
 * internal ORM model. Implementations are responsible for persisting their
 * own state when persist() is called.
 */
interface QueueTaskContextInterface extends TaskContextInterface
{
    public function getId(mixed $default = 0);

    public function getTypeId(): int;

    public function getPid(): int;

    public function getName(): string;

    public function getStatus(): string;

    public function getContent(): string;

    public function getResult(): string;

    public function getProcess(bool $format = false, bool $isHtml = false);

    public function getAuto(): bool;

    public function getModule(): string;

    public function getBizKey(): string;

    public function setPid(int $processId): static;

    public function setStatus(string $status = QueueStatus::PENDING): static;

    public function setContent(string $content): static;

    public function setProcess(string $process): static;

    public function setResult(string $result): static;

    public function setFinished(bool $finished): static;

    public function isFinished(): bool;

    public function isRunning(): bool;

    public function isPending(): bool;

    public function isError(): bool;

    public function isDone(): bool;

    /**
     * Return task-local data without exposing an ORM base type.
     */
    public function taskData(string $key = '', mixed $index = null): mixed;

    /**
     * @return array<int, mixed>
     */
    public function taskAttributes(array $options = []): array;

    public function validateTaskAttribute(mixed $attribute): bool|string;

    public function resetTaskProgress(): void;

    /**
     * @param array<string, mixed> $args
     */
    public function setExecutionArgs(array $args): void;

    public function persist(): void;
}
