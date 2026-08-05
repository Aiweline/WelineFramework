<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

/** Provider-neutral scenario image boundary published by Weline_Ai. */
interface ScenarioImageGenerationInterface
{
    /** @param list<string> $requiredCapabilities @return array<string,mixed> */
    public function inspectReadiness(string $scenarioCode, array $requiredCapabilities = []): array;

    /**
     * @param array<string,mixed> $semanticPayload
     * @param array<string,mixed> $mediaContract
     * @param array<string,mixed> $runtimeContext
     * @return array<string,mixed>
     */
    public function generate(
        string $scenarioCode,
        array $semanticPayload,
        ?string $locale = null,
        array $mediaContract = [],
        array $runtimeContext = [],
    ): array;
}
