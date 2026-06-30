<?php

declare(strict_types=1);

namespace Weline\FakeData\Service;

use Weline\FakeData\Api\FakeDataProviderInterface;

class FakeDataProviderPlanner
{
    /**
     * @param array<string, FakeDataProviderInterface> $providers
     * @param array<int, string> $providerCodes
     * @param array<int, string> $moduleNames
     * @return array<string, FakeDataProviderInterface>
     */
    public function createPlan(array $providers, array $providerCodes = [], array $moduleNames = []): array
    {
        $normalizedProviders = [];
        foreach ($providers as $code => $provider) {
            $normalizedProviders[strtolower($code)] = $provider;
        }

        $rootCodes = $this->resolveRootCodes($normalizedProviders, $providerCodes, $moduleNames);
        $ordered = [];
        $visiting = [];
        $visited = [];
        foreach ($rootCodes as $code) {
            $this->visit($code, $normalizedProviders, $ordered, $visiting, $visited, []);
        }

        return $ordered;
    }

    /**
     * @param array<string, FakeDataProviderInterface> $providers
     * @param array<int, string> $providerCodes
     * @param array<int, string> $moduleNames
     * @return array<int, string>
     */
    private function resolveRootCodes(array $providers, array $providerCodes, array $moduleNames): array
    {
        if ($providerCodes === [] && $moduleNames === []) {
            return array_keys($providers);
        }

        $rootCodes = [];
        foreach ($providerCodes as $code) {
            $code = strtolower(trim($code));
            if ($code === '') {
                continue;
            }
            if (!isset($providers[$code])) {
                throw new \InvalidArgumentException((string)__('Unknown fake data provider: %{1}', [$code]));
            }
            $rootCodes[$code] = $code;
        }

        $moduleNames = array_values(array_filter(array_map('trim', $moduleNames)));
        if ($moduleNames !== []) {
            foreach ($providers as $code => $provider) {
                if (in_array($provider->getModuleName(), $moduleNames, true)) {
                    $rootCodes[$code] = $code;
                }
            }
            foreach ($moduleNames as $moduleName) {
                $matched = false;
                foreach ($providers as $provider) {
                    if ($provider->getModuleName() === $moduleName) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    throw new \InvalidArgumentException((string)__('No fake data provider found for module: %{1}', [$moduleName]));
                }
            }
        }

        return array_values($rootCodes);
    }

    /**
     * @param array<string, FakeDataProviderInterface> $providers
     * @param array<string, FakeDataProviderInterface> $ordered
     * @param array<string, bool> $visiting
     * @param array<string, bool> $visited
     * @param array<int, string> $path
     */
    private function visit(
        string $code,
        array $providers,
        array &$ordered,
        array &$visiting,
        array &$visited,
        array $path
    ): void {
        if (isset($visited[$code])) {
            return;
        }
        if (!isset($providers[$code])) {
            throw new \InvalidArgumentException((string)__('Missing fake data provider dependency: %{1}', [$code]));
        }
        if (isset($visiting[$code])) {
            $path[] = $code;
            throw new \RuntimeException((string)__('Circular fake data provider dependency: %{1}', [implode(' -> ', $path)]));
        }

        $visiting[$code] = true;
        $path[] = $code;
        $dependencies = $providers[$code]->getDependencies();
        sort($dependencies);
        foreach ($dependencies as $dependencyCode) {
            $this->visit(strtolower((string)$dependencyCode), $providers, $ordered, $visiting, $visited, $path);
        }
        unset($visiting[$code]);
        $visited[$code] = true;
        $ordered[$code] = $providers[$code];
    }
}

