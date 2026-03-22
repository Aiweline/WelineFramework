<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\WebsiteThemeSourceInterface;
use Weline\Websites\Api\WebsiteThemeSourceRegistryInterface;

class ThemeSourceRegistry implements WebsiteThemeSourceRegistryInterface
{
    private ?array $cachedSources = null;

    private ?int $cachedExtendsMtime = null;

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly ExtensionPointReader $extensionPointReader,
    ) {
    }

    public function getSources(bool $onlyEnabled = true, bool $forceReload = false): array
    {
        $sources = $this->loadSources($forceReload);

        if (!$onlyEnabled) {
            return $sources;
        }

        return array_filter(
            $sources,
            static fn (WebsiteThemeSourceInterface $source): bool => $source->isEnabled()
        );
    }

    public function getSource(string $sourceCode, bool $forceReload = false): ?WebsiteThemeSourceInterface
    {
        $sources = $this->getSources(false, $forceReload);
        return $sources[$sourceCode] ?? null;
    }

    public function clearCache(): void
    {
        $this->cachedSources = null;
        $this->cachedExtendsMtime = null;
    }

    /**
     * @return array<string, WebsiteThemeSourceInterface>
     */
    private function loadSources(bool $forceReload = false): array
    {
        if (!$forceReload && $this->cachedSources !== null) {
            $currentMtime = $this->extensionPointReader->getRegistryFileMtime();
            if ($currentMtime === $this->cachedExtendsMtime) {
                return $this->cachedSources;
            }
        }

        $sources = [];

        foreach ($this->extensionPointReader->getExtensionEntries('Weline_Websites', 'WebsiteThemeSource', $forceReload) as $extension) {
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

            if (!$instance instanceof WebsiteThemeSourceInterface) {
                continue;
            }

            $sources[$instance->getCode()] = $instance;
        }

        uasort($sources, [$this, 'sortSources']);

        $this->cachedSources = $sources;
        $this->cachedExtendsMtime = $this->extensionPointReader->getRegistryFileMtime();

        return $sources;
    }

    private function sortSources(WebsiteThemeSourceInterface $left, WebsiteThemeSourceInterface $right): int
    {
        return [$left->getSortOrder(), $left->getCode()] <=> [$right->getSortOrder(), $right->getCode()];
    }
}
