<?php

declare(strict_types=1);

namespace Weline\FakeData\Service;

use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;

class FakeDataImportService
{
    public function __construct(
        private readonly FakeDataProviderRegistry $registry,
        private readonly FakeDataProviderPlanner $planner,
        private readonly FakeDataRecordService $recordService,
    ) {
    }

    /**
     * @return array{dry_run:bool,seed:string,reset:bool,providers:array<int,array<string,mixed>>,results:array<string,array<string,mixed>>,warnings:array<int,string>}
     */
    public function execute(array $args): array
    {
        $seed = (string)($args['seed'] ?? date('YmdHis'));
        $reset = isset($args['reset']);
        $dryRun = isset($args['dry-run']) || isset($args['dry_run']);
        $limit = isset($args['limit']) ? max(0, (int)$args['limit']) : null;
        $providerCodes = $this->parseList($args['provider'] ?? $args['p'] ?? []);
        $moduleNames = $this->parseList($args['module'] ?? $args['m'] ?? []);

        $providers = $this->registry->getProviders();
        $plan = $this->planner->createPlan($providers, $providerCodes, $moduleNames);
        $providerSummary = $this->summarizeProviders($plan);
        $results = [];

        if ($dryRun) {
            return [
                'dry_run' => true,
                'seed' => $seed,
                'reset' => $reset,
                'providers' => $providerSummary,
                'results' => [],
                'warnings' => $this->registry->getWarnings(),
            ];
        }

        $context = new FakeDataContext($args, $seed, $reset, false, $limit, $this->recordService);

        if ($reset) {
            foreach (array_reverse($plan, true) as $code => $provider) {
                $result = $this->runProviderCleanup($provider, $context);
                $results[$code]['cleanup'] = $result->toArray();
                if ($result->hasErrors()) {
                    return $this->buildReport($seed, $reset, $providerSummary, $results);
                }
            }
        }

        foreach ($plan as $code => $provider) {
            $result = $this->runProviderSeed($provider, $context);
            $results[$code]['seed'] = $result->toArray();
            if ($result->hasErrors()) {
                break;
            }
        }

        return $this->buildReport($seed, $reset, $providerSummary, $results);
    }

    /**
     * @param array<string, FakeDataProviderInterface> $plan
     * @return array<int, array<string,mixed>>
     */
    private function summarizeProviders(array $plan): array
    {
        $summary = [];
        foreach ($plan as $provider) {
            $summary[] = [
                'code' => $provider->getCode(),
                'module' => $provider->getModuleName(),
                'label' => $provider->getLabel(),
                'sort_order' => $provider->getSortOrder(),
                'dependencies' => $provider->getDependencies(),
                'description' => $provider->describe(),
            ];
        }
        return $summary;
    }

    private function runProviderCleanup(FakeDataProviderInterface $provider, FakeDataContext $context): FakeDataResult
    {
        try {
            return $provider->cleanup($context);
        } catch (\Throwable $e) {
            return FakeDataResult::error((string)__('Cleanup failed for %{1}: %{2}', [$provider->getCode(), $e->getMessage()]));
        }
    }

    private function runProviderSeed(FakeDataProviderInterface $provider, FakeDataContext $context): FakeDataResult
    {
        try {
            return $provider->seed($context);
        } catch (\Throwable $e) {
            return FakeDataResult::error((string)__('Seed failed for %{1}: %{2}', [$provider->getCode(), $e->getMessage()]));
        }
    }

    /**
     * @param array<string, array<string,mixed>> $results
     * @return array{dry_run:bool,seed:string,reset:bool,providers:array<int,array<string,mixed>>,results:array<string,array<string,mixed>>,warnings:array<int,string>}
     */
    private function buildReport(string $seed, bool $reset, array $providerSummary, array $results): array
    {
        return [
            'dry_run' => false,
            'seed' => $seed,
            'reset' => $reset,
            'providers' => $providerSummary,
            'results' => $results,
            'warnings' => $this->registry->getWarnings(),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function parseList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            $items = preg_split('/[, ]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            $items = [];
        }
        return array_values(array_filter(array_map('trim', array_map('strval', $items))));
    }
}

