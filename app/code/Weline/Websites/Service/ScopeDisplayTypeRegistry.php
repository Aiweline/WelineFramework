<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\ScopeDisplayTypeProviderInterface;
use Weline\Websites\Service\AiWorkbench\ExtensionPointReader;

/**
 * Collects ScopeDisplayType Extends providers (identity codes only).
 */
final class ScopeDisplayTypeRegistry
{
    /** @var array<string, ScopeDisplayTypeProviderInterface>|null */
    private ?array $cachedProviders = null;

    private ?int $cachedExtendsMtime = null;

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly ExtensionPointReader $extensionPointReader,
    ) {
    }

    /**
     * @return array<string, ScopeDisplayTypeProviderInterface>
     */
    public function getProviders(bool $forceReload = false): array
    {
        return $this->loadProviders($forceReload);
    }

    public function getProvider(string $code, bool $forceReload = false): ?ScopeDisplayTypeProviderInterface
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return null;
        }

        return $this->getProviders($forceReload)[$code] ?? null;
    }

    public function isKnownCode(string $code, bool $forceReload = false): bool
    {
        return $this->getProvider($code, $forceReload) !== null;
    }

    /**
     * @return list<array{code:string,label:string,module:string}>
     */
    public function listOptions(bool $forceReload = false): array
    {
        $options = [];
        foreach ($this->getProviders($forceReload) as $provider) {
            $options[] = [
                'code' => $provider->getCode(),
                'label' => $provider->getLabel(),
                'module' => $provider->getModule(),
            ];
        }

        return $options;
    }

    public function clearCache(): void
    {
        $this->cachedProviders = null;
        $this->cachedExtendsMtime = null;
    }

    /**
     * @return array<string, ScopeDisplayTypeProviderInterface>
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
        foreach ($this->extensionPointReader->getExtensionEntries(
            'Weline_Websites',
            'ScopeDisplayType',
            $forceReload,
        ) as $extension) {
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
            if (!$instance instanceof ScopeDisplayTypeProviderInterface) {
                continue;
            }

            $code = strtolower(trim($instance->getCode()));
            if ($code === '' || strlen($code) > 64 || !preg_match('/^[a-z][a-z0-9_-]*$/', $code)) {
                continue;
            }
            $providers[$code] = $instance;
        }

        uasort(
            $providers,
            static fn (
                ScopeDisplayTypeProviderInterface $left,
                ScopeDisplayTypeProviderInterface $right,
            ): int => [$left->getSortOrder(), $left->getCode()]
                <=> [$right->getSortOrder(), $right->getCode()],
        );

        $this->cachedProviders = $providers;
        $this->cachedExtendsMtime = $this->extensionPointReader->getRegistryFileMtime();

        return $providers;
    }
}
