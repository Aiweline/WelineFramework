<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\AiSiteBuilderProviderInterface;
use Weline\Websites\Api\AiSiteBuilderProviderRegistryInterface;

class ProviderRegistry implements AiSiteBuilderProviderRegistryInterface
{
    private ?array $cachedProviders = null;

    private ?int $cachedExtendsMtime = null;

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly ExtensionPointReader $extensionPointReader,
    ) {
    }

    public function getProviders(bool $onlyEnabled = true, bool $forceReload = false): array
    {
        $providers = $this->loadProviders($forceReload);

        if (!$onlyEnabled) {
            return $providers;
        }

        return array_filter(
            $providers,
            static fn (AiSiteBuilderProviderInterface $provider): bool => $provider->isEnabled()
        );
    }

    public function getProvider(string $providerCode, bool $forceReload = false): ?AiSiteBuilderProviderInterface
    {
        $providers = $this->getProviders(false, $forceReload);
        return $providers[$providerCode] ?? null;
    }

    public function clearCache(): void
    {
        $this->cachedProviders = null;
        $this->cachedExtendsMtime = null;
    }

    /**
     * @return array<string, AiSiteBuilderProviderInterface>
     */
    private function loadProviders(bool $forceReload = false): array
    {
        if (!$forceReload && $this->cachedProviders !== null) {
            $currentMtime = $this->extensionPointReader->getRegistryFileMtime();
            if ($currentMtime === $this->cachedExtendsMtime) {
                return $this->cachedProviders;
            }
        }

        $providers = [];

        foreach ($this->extensionPointReader->getExtensionEntries('Weline_Websites', 'AiSiteBuilderProvider', $forceReload) as $extension) {
            $className = $this->extensionPointReader->resolveClassName($extension);
            if ($className === null) {
                continue;
            }

            $sourceFile = (string)($extension['source_file'] ?? '');
            if (!class_exists($className, false) && $sourceFile !== '' && is_file($sourceFile)) {
                require_once $sourceFile;
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $instance = $this->objectManager->getInstance($className);
            } catch (\Throwable) {
                continue;
            }

            if (!$instance instanceof AiSiteBuilderProviderInterface) {
                continue;
            }

            $providers[$instance->getCode()] = $instance;
        }

        uasort($providers, [$this, 'sortProviders']);

        $this->cachedProviders = $providers;
        $this->cachedExtendsMtime = $this->extensionPointReader->getRegistryFileMtime();

        return $providers;
    }

    private function sortProviders(AiSiteBuilderProviderInterface $left, AiSiteBuilderProviderInterface $right): int
    {
        return [$left->getSortOrder(), $left->getCode()] <=> [$right->getSortOrder(), $right->getCode()];
    }
}
