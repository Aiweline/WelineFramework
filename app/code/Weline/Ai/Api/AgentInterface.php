<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

/** Public contract for module-provided AI agents. */
interface AgentInterface
{
    public function getCode(): string;

    public function getName(): string;

    public function getDescription(): string;

    public function getVersion(): string;

    /** @return list<string> */
    public function getScenarios(): array;

    /** @return list<ToolInterface> */
    public function getTools(): array;

    /** @param array<string,mixed> $context */
    public function getSystemPrompt(array $context = []): string;

    /**
     * @param array<string,mixed> $params
     * @param callable(string,array<string,mixed>):mixed|null $streamCallback
     */
    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null,
    ): AgentResult;

    public function supportsModel(string $modelCode): bool;

    public function getMaxIterations(): int;
}
