<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Configuration;

use Weline\Ai\Api\AiModel;
use Weline\Ai\Api\ScenarioAdapterInterface;

interface ScenarioConfigurationInterface
{
    public function scenario(string $code, bool $scan = false): ?ScenarioRecord;

    /** @return list<AiModel> */
    public function activeModels(string $primaryModality): array;

    public function model(
        string $modelCode,
        bool $activeOnly = false,
        ?string $primaryModality = null,
    ): ?AiModel;

    public function bindModel(string $scenarioCode, string $primaryModality, ?string $modelCode): bool;

    public function scanAdapters(): int;

    public function adapter(string $code, bool $scan = false): ?ScenarioAdapterInterface;

    public function providerAvailability(string $modelCode): ProviderAvailability;

    public function usageByRequestPrefix(string $requestPrefix): ?UsageSummary;
}
