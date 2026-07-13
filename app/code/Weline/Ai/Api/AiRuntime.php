<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

use Weline\Ai\Service\AiService;

/**
 * Thin public facade that keeps optional modules off the internal service type.
 */
final class AiRuntime implements AiRuntimeInterface
{
    public function __construct(
        private readonly AiService $service,
    ) {
    }

    public function generate(
        string $prompt,
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        ?string $locale = null,
        array $params = [],
        ?int $userId = null,
        bool $isBackend = false
    ): string {
        return $this->service->generate(
            $prompt,
            $modelCode,
            $scenarioCode,
            $locale,
            $params,
            $userId,
            $isBackend
        );
    }

    public function generateStream(
        string $prompt,
        callable $callback,
        ?string $modelCode = null,
        ?string $scenarioCode = null,
        ?string $locale = null,
        array $params = []
    ): void {
        $this->service->generateStream($prompt, $callback, $modelCode, $scenarioCode, $locale, $params);
    }

    public function executeAgent(
        string $agentCode,
        string $prompt,
        ?string $modelCode = null,
        array $params = [],
        ?callable $streamCallback = null
    ): AgentResult {
        return $this->service->executeAgent($agentCode, $prompt, $modelCode, $params, $streamCallback);
    }

    public function getAgentsForScenario(string $scenarioCode): array
    {
        return $this->service->getAgentsForScenario($scenarioCode);
    }
}
