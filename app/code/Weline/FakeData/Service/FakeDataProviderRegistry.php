<?php

declare(strict_types=1);

namespace Weline\FakeData\Service;

use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

class FakeDataProviderRegistry
{
    private const EXTENDS_PATH_PREFIX = 'extends/module/weline_fakedata/provider/';

    /** @var array<string, FakeDataProviderInterface>|null */
    private ?array $providers = null;
    /** @var array<int, string> */
    private array $warnings = [];

    public function __construct(
        private mixed $extendedBy = null,
    ) {
    }

    /**
     * @return array<string, FakeDataProviderInterface>
     */
    public function getProviders(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $this->providers = [];
        $this->warnings = [];
        $extendedBy = is_array($this->extendedBy) ? $this->extendedBy : ExtendsData::getExtendedBy('Weline_FakeData');
        foreach ($extendedBy as $extensions) {
            foreach ($extensions as $extension) {
                if (!$this->isProviderExtension($extension)) {
                    continue;
                }
                $provider = $this->instantiateProvider($extension);
                if (!$provider instanceof FakeDataProviderInterface) {
                    continue;
                }
                $code = strtolower(trim($provider->getCode()));
                if ($code === '') {
                    $this->warnings[] = (string)__('Fake data provider with empty code was skipped.');
                    continue;
                }
                if (isset($this->providers[$code])) {
                    throw new \RuntimeException((string)__('Duplicate fake data provider code: %{1}', [$code]));
                }
                $this->providers[$code] = $provider;
            }
        }

        uasort($this->providers, static function (FakeDataProviderInterface $a, FakeDataProviderInterface $b): int {
            return [$a->getSortOrder(), $a->getCode()] <=> [$b->getSortOrder(), $b->getCode()];
        });

        return $this->providers;
    }

    /**
     * @return array<int, string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function isProviderExtension(array $extension): bool
    {
        $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
        return str_starts_with(strtolower($relativePath), self::EXTENDS_PATH_PREFIX);
    }

    private function instantiateProvider(array $extension): ?FakeDataProviderInterface
    {
        $sourceFile = (string)($extension['source_file'] ?? '');
        $className = $this->resolveClassName($extension);
        if ($className === null) {
            $this->warnings[] = (string)__('Fake data provider class could not be resolved: %{1}', [$sourceFile]);
            return null;
        }

        if (!class_exists($className, false) && $sourceFile !== '' && is_file($sourceFile)) {
            require_once $sourceFile;
        }
        if (!class_exists($className)) {
            $this->warnings[] = (string)__('Fake data provider class not found: %{1}', [$className]);
            return null;
        }

        try {
            $instance = ObjectManager::getInstance($className);
        } catch (\Throwable $e) {
            $this->warnings[] = (string)__('Fake data provider load failed: %{1} - %{2}', [$className, $e->getMessage()]);
            return null;
        }

        if (!$instance instanceof FakeDataProviderInterface) {
            $this->warnings[] = (string)__('Fake data provider skipped because it does not implement FakeDataProviderInterface: %{1}', [$className]);
            return null;
        }

        return $instance;
    }

    private function resolveClassName(array $extension): ?string
    {
        $fromScan = trim((string)($extension['class_name'] ?? ''));
        if ($fromScan !== '') {
            return $fromScan;
        }

        $sourceFile = (string)($extension['source_file'] ?? '');
        if ($sourceFile === '' || !is_file($sourceFile)) {
            return null;
        }

        $content = file_get_contents($sourceFile);
        if ($content === false) {
            return null;
        }

        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim($matches[1]);
        }
        $class = null;
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $class = $matches[1];
        }

        return $namespace !== null && $class !== null ? $namespace . '\\' . $class : null;
    }
}
