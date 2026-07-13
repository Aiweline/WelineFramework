<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

/**
 * Stable cross-module entry point for text generation and agent execution.
 */
interface AiRuntimeInterface
{
    public function generate(
        string $prompt,
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        ?string $locale = null,
        array $params = [],
        ?int $userId = null,
        bool $isBackend = false
    ): string;

    public function generateStream(
        string $prompt,
        callable $callback,
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        ?string $locale = null,
        array $params = []
    ): void;

    /**
     * The concrete result remains an object for v1 compatibility with the
     * existing public success/content/error properties.
     */
    public function executeAgent(
        string $agentCode,
        string $prompt,
        ?string $modelCode = null,
        array $params = [],
        ?callable $streamCallback = null
    ): object;

    /** @return list<array<string,mixed>> */
    public function getAgentsForScenario(string $scenarioCode): array;
}
