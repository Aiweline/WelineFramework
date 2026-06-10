<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Interface\SocialPlatformProviderInterface;

class SocialPlatformRegistry
{
    private const CORE_PLATFORM_FILE = BP . '/app/code/Weline/Social/etc/social_platforms.php';
    private const EXTENDS_REGISTRY_FILE = 'extends/module/weline_social/platforms.php';

    /** @var array<string, SocialPlatformProviderInterface>|null */
    private ?array $providers = null;
    /** @var array<int, string> */
    private array $warnings = [];

    public function __construct(
        private readonly ?ObjectManager $objectManager = null
    ) {
    }

    /**
     * @return array<string, SocialPlatformProviderInterface>
     */
    public function getProviders(bool $forceReload = false): array
    {
        if (!$forceReload && $this->providers !== null) {
            return $this->providers;
        }

        $this->warnings = [];
        $providers = [];
        foreach ($this->loadProviderClasses($forceReload) as $className) {
            $provider = $this->instantiateProvider($className);
            if (!$provider instanceof SocialPlatformProviderInterface) {
                continue;
            }
            $code = \strtolower(\trim($provider->getPlatformCode()));
            if ($code === '') {
                $this->warnings[] = (string)__('Social platform provider with empty code was skipped: %{1}', [$className]);
                continue;
            }
            if (isset($providers[$code])) {
                throw new \RuntimeException((string)__('Duplicate social platform provider code: %{1}', [$code]));
            }
            $this->validateDefinition($provider);
            $providers[$code] = $provider;
        }

        \uasort($providers, static function (SocialPlatformProviderInterface $a, SocialPlatformProviderInterface $b): int {
            $ad = $a->getDefinition();
            $bd = $b->getDefinition();
            return [
                (int)($ad['sort_order'] ?? 1000),
                (string)($ad['family'] ?? ''),
                (string)($ad['title'] ?? $a->getPlatformCode()),
            ] <=> [
                (int)($bd['sort_order'] ?? 1000),
                (string)($bd['family'] ?? ''),
                (string)($bd['title'] ?? $b->getPlatformCode()),
            ];
        });

        $this->providers = $providers;
        return $providers;
    }

    public function getProvider(string $platformCode): ?SocialPlatformProviderInterface
    {
        $platformCode = \strtolower(\trim($platformCode));
        return $platformCode !== '' ? ($this->getProviders()[$platformCode] ?? null) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDefinitions(bool $forceReload = false): array
    {
        $definitions = [];
        foreach ($this->getProviders($forceReload) as $provider) {
            $definitions[] = $provider->getDefinition();
        }

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<int, string>
     */
    private function loadProviderClasses(bool $forceReload): array
    {
        $classes = [];
        if (\is_file(self::CORE_PLATFORM_FILE)) {
            $core = require self::CORE_PLATFORM_FILE;
            if (\is_array($core)) {
                $classes = \array_merge($classes, \array_values(\array_filter($core, 'is_string')));
            }
        }

        foreach (ExtendsData::getExtendedBy('Weline_Social', $forceReload) as $extensions) {
            foreach ($extensions as $extension) {
                $relativePath = \strtolower(\str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if ($relativePath !== self::EXTENDS_REGISTRY_FILE) {
                    continue;
                }
                $sourceFile = (string)($extension['source_file'] ?? '');
                if ($sourceFile === '' || !\is_file($sourceFile)) {
                    continue;
                }
                $defined = require $sourceFile;
                if (\is_array($defined)) {
                    $classes = \array_merge($classes, \array_values(\array_filter($defined, 'is_string')));
                }
            }
        }

        return \array_values(\array_unique($classes));
    }

    private function instantiateProvider(string $className): ?SocialPlatformProviderInterface
    {
        if (!\class_exists($className)) {
            $this->warnings[] = (string)__('Social platform provider class not found: %{1}', [$className]);
            return null;
        }

        try {
            $provider = ($this->objectManager ?? ObjectManager::getInstance())->getInstance($className);
        } catch (\Throwable $throwable) {
            $this->warnings[] = (string)__('Social platform provider load failed: %{1} - %{2}', [$className, $throwable->getMessage()]);
            return null;
        }

        if (!$provider instanceof SocialPlatformProviderInterface) {
            $this->warnings[] = (string)__('Social platform provider skipped because it does not implement the provider interface: %{1}', [$className]);
            return null;
        }

        return $provider;
    }

    private function validateDefinition(SocialPlatformProviderInterface $provider): void
    {
        $definition = $provider->getDefinition();
        foreach (['code', 'title', 'family', 'auth_modes', 'capabilities', 'docs'] as $requiredKey) {
            if (!\array_key_exists($requiredKey, $definition) || $definition[$requiredKey] === [] || $definition[$requiredKey] === '') {
                throw new \RuntimeException((string)__('Social platform definition missing %{1}: %{2}', [$requiredKey, $provider::class]));
            }
        }
    }
}

