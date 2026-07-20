<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

final class ServiceProviderRegistry
{
    private const DEFAULT_REGISTRY_FILE = BP . 'generated' . DS . 'framework' . DS . 'modules.php';

    /** @var array<string, string>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly ?string $registryFile = null,
    ) {
    }

    public function implementationFor(string $contract): ?string
    {
        $this->load();
        return $this->providers[$contract] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $this->load();
        return $this->providers;
    }

    /**
     * @return array<string, string>
     */
    public function implementationsWithPrefix(string $capabilityPrefix): array
    {
        return array_filter(
            $this->all(),
            static fn(string $implementation, string $capability): bool => str_starts_with($capability, $capabilityPrefix),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function load(): void
    {
        if ($this->providers !== null) {
            return;
        }

        $file = $this->registryFile ?? self::DEFAULT_REGISTRY_FILE;
        if (!is_file($file)) {
            // First framework:compile / cold bootstrap: allow Factory fallbacks.
            // Explicit staging registries still throw via constructor path below.
            if ($this->registryFile !== null) {
                throw new \RuntimeException(
                    'Compiled module provider registry is missing. Run: php bin/w framework:compile',
                );
            }
            $this->providers = [];
            return;
        }
        $registry = require $file;
        if (!is_array($registry) || ($registry['format'] ?? null) !== ModuleRegistryCompiler::FORMAT_VERSION) {
            throw new \RuntimeException(
                'Compiled module provider registry is invalid. Re-run: php bin/w framework:compile',
            );
        }

        $providers = [];
        foreach (($registry['order'] ?? []) as $moduleName) {
            $module = $registry['modules'][$moduleName] ?? null;
            if (!is_array($module)) {
                continue;
            }
            foreach (($module['provides'] ?? []) as $contract => $implementation) {
                if (!is_string($contract) || !is_string($implementation) || $contract === '' || $implementation === '') {
                    continue;
                }
                if (isset($providers[$contract]) && $providers[$contract] !== $implementation) {
                    throw new \RuntimeException(
                        "Multiple implementations provide {$contract}: {$providers[$contract]} and {$implementation}.",
                    );
                }
                $providers[$contract] = $implementation;
            }
        }
        ksort($providers);
        $this->providers = $providers;
    }
}
