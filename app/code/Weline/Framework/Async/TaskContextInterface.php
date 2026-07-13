<?php

declare(strict_types=1);

namespace Weline\Framework\Async;

/**
 * Persistence-neutral context exposed to asynchronous task consumers.
 */
interface TaskContextInterface
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

    public function setStatus(string $status = TaskStatus::PENDING): static;

    public function setContent(string $content): static;

    public function setProcess(string $process): static;

    public function setResult(string $result): static;

    public function setFinished(bool $finished): static;

    public function isFinished(): bool;

    public function isRunning(): bool;

    public function isPending(): bool;

    public function isError(): bool;

    public function isDone(): bool;

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
