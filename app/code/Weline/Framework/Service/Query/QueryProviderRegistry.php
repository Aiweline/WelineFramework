<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\DefaultCrudProvider;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class QueryProviderRegistry
{
    /** @var array<string, QueryProviderInterface> */
    private array $providers = [];

    /** @var array<string, array{class_name:string, source_file:string}> */
    private array $providerDefinitions = [];

    /** @var array<int, array{class_name:string, source_file:string}> */
    private array $deferredDefinitions = [];

    private bool $definitionsLoaded = false;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $descriptorCache = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $operationDescriptorCache = [];

    private function loadDefinitions(): void
    {
        if ($this->definitionsLoaded) {
            return;
        }
        $this->definitionsLoaded = true;

        /** @var DefaultCrudProvider $crud */
        $crud = ObjectManager::getInstance(DefaultCrudProvider::class);
        $this->providers[$crud->getProviderName()] = $crud;

        $queryPathPrefix = 'extends/module/weline_framework/query/';
        $extendedBy = ExtendsData::getExtendedBy('Weline_Framework');
        foreach ($extendedBy as $extensions) {
            foreach ($extensions as $extension) {
                $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
                if (!str_starts_with(strtolower($relativePath), $queryPathPrefix)) {
                    continue;
                }

                $sourceFile = (string)($extension['source_file'] ?? '');
                $className = $this->resolveClassName($extension);
                if ($className === null) {
                    continue;
                }

                $providerName = $this->resolveProviderNameFromSourceFile($sourceFile);
                $definition = [
                    'class_name' => $className,
                    'source_file' => $sourceFile,
                ];

                if ($providerName === null || $providerName === '') {
                    $this->deferredDefinitions[] = $definition;
                    continue;
                }

                $this->providerDefinitions[$providerName] = $definition;
            }
        }
    }

    private function resolveClassName(array $extension): ?string
    {
        $fromScan = trim((string)($extension['class_name'] ?? ''));
        if ($fromScan !== '') {
            return $fromScan;
        }

        $sourceFile = (string)($extension['source_file'] ?? '');
        if ($sourceFile === '' || !file_exists($sourceFile)) {
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

        if ($namespace !== null && $class !== null) {
            return $namespace . '\\' . $class;
        }

        return null;
    }

    private function resolveProviderNameFromSourceFile(string $sourceFile): ?string
    {
        if ($sourceFile === '' || !file_exists($sourceFile)) {
            return null;
        }

        $content = file_get_contents($sourceFile);
        if ($content === false) {
            return null;
        }

        if (preg_match('/function\s+getProviderName\s*\(\)\s*:\s*string\s*\{.*?return\s+[\'\"]([^\'\"]+)[\'\"]\s*;/si', $content, $matches)) {
            $providerName = trim((string)($matches[1] ?? ''));
            return $providerName !== '' ? $providerName : null;
        }

        return null;
    }

    private function instantiateProviderDefinition(array $definition): ?QueryProviderInterface
    {
        $className = $definition['class_name'];
        $sourceFile = $definition['source_file'];

        if (!class_exists($className, false) && $sourceFile !== '' && file_exists($sourceFile)) {
            require_once $sourceFile;
        }

        if (!class_exists($className)) {
            return null;
        }

        $this->registerRequestModuleFromSourceFile($sourceFile);

        try {
            $instance = ObjectManager::getInstance($className);
            return $instance instanceof QueryProviderInterface ? $instance : null;
        } catch (\Throwable $e) {
            $this->logLoadFailure($className, $sourceFile, $e);
            return null;
        }
    }

    private function registerRequestModuleFromSourceFile(string $sourceFile): void
    {
        $moduleName = $this->resolveModuleNameFromSourceFile($sourceFile);
        if ($moduleName === '') {
            return;
        }

        try {
            ObjectManager::getInstance(Request::class)->addModule($moduleName);
        } catch (\Throwable) {
        }
    }

    private function resolveModuleNameFromSourceFile(string $sourceFile): string
    {
        $path = \str_replace('\\', '/', $sourceFile);
        if (!\preg_match('~/app/code/([^/]+)/([^/]+)/~i', $path, $matches)) {
            return '';
        }

        $vendor = \trim((string)($matches[1] ?? ''));
        $module = \trim((string)($matches[2] ?? ''));
        if ($vendor === '' || $module === '') {
            return '';
        }

        return $vendor . '_' . $module;
    }

    private function logLoadFailure(string $className, string $sourceFile, \Throwable $e): void
    {
        if (!function_exists('w_log_warning')) {
            return;
        }

        w_log_warning(
            (string)__('QueryProvider load failed and was skipped: %{1} - %{2}', [$className, $e->getMessage()]),
            ['class' => $className, 'file' => $sourceFile],
            'query_provider'
        );
    }

    private function instantiateNamedProvider(string $providerName): ?QueryProviderInterface
    {
        if (isset($this->providers[$providerName])) {
            return $this->providers[$providerName];
        }

        $definition = $this->providerDefinitions[$providerName] ?? null;
        if ($definition === null) {
            return $this->resolveDeferredProvider($providerName);
        }

        unset($this->providerDefinitions[$providerName]);

        $instance = $this->instantiateProviderDefinition($definition);
        if ($instance === null) {
            return $this->resolveDeferredProvider($providerName);
        }

        $resolvedName = trim($instance->getProviderName());
        if ($resolvedName !== '') {
            $this->providers[$resolvedName] = $instance;
        }

        return $this->providers[$providerName] ?? null;
    }

    private function resolveDeferredProvider(string $providerName): ?QueryProviderInterface
    {
        if (empty($this->deferredDefinitions)) {
            return null;
        }

        $remaining = [];
        foreach ($this->deferredDefinitions as $definition) {
            $instance = $this->instantiateProviderDefinition($definition);
            if ($instance === null) {
                continue;
            }

            $resolvedName = trim($instance->getProviderName());
            if ($resolvedName === '') {
                continue;
            }

            $this->providers[$resolvedName] = $instance;
            if ($resolvedName !== $providerName) {
                continue;
            }
        }

        $this->deferredDefinitions = $remaining;
        return $this->providers[$providerName] ?? null;
    }

    private function instantiateRemainingProviders(): void
    {
        foreach (array_keys($this->providerDefinitions) as $providerName) {
            $this->instantiateNamedProvider($providerName);
        }

        if (empty($this->deferredDefinitions)) {
            return;
        }

        $definitions = $this->deferredDefinitions;
        $this->deferredDefinitions = [];

        foreach ($definitions as $definition) {
            $instance = $this->instantiateProviderDefinition($definition);
            if ($instance === null) {
                continue;
            }

            $providerName = trim($instance->getProviderName());
            if ($providerName !== '') {
                $this->providers[$providerName] = $instance;
            }
        }
    }

    public function getProvider(string $providerName): ?QueryProviderInterface
    {
        $this->loadDefinitions();
        return $this->instantiateNamedProvider($providerName);
    }

    /**
     * @return array<string, QueryProviderInterface>
     */
    public function getAllProviders(): array
    {
        $this->loadDefinitions();
        $this->instantiateRemainingProviders();
        return $this->providers;
    }

    public function getAllDescriptors(): array
    {
        $scope = $this->descriptorCacheScope();
        if (isset(self::$descriptorCache[$scope])) {
            return self::$descriptorCache[$scope];
        }

        $descriptors = [];
        $operationIndex = [];
        foreach ($this->getAllProviders() as $provider) {
            $descriptor = $provider->getDescriptor();
            if (!\is_array($descriptor)) {
                continue;
            }

            $descriptors[] = $descriptor;
            $providerName = (string)($descriptor['provider'] ?? $provider->getProviderName());
            if ($providerName === '') {
                continue;
            }

            foreach (($descriptor['operations'] ?? []) as $operationDescriptor) {
                if (!\is_array($operationDescriptor)) {
                    continue;
                }
                $operationName = (string)($operationDescriptor['name'] ?? '');
                if ($operationName === '') {
                    continue;
                }
                $operationIndex[$providerName][$operationName] = $operationDescriptor;
            }
        }

        self::$descriptorCache[$scope] = $descriptors;
        self::$operationDescriptorCache[$scope] = $operationIndex;

        return $descriptors;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOperationDescriptor(string $providerName, string $operationName): ?array
    {
        if ($providerName === '' || $operationName === '') {
            return null;
        }

        $scope = $this->descriptorCacheScope();
        $cached = self::$operationDescriptorCache[$scope][$providerName][$operationName] ?? null;
        if (\is_array($cached)) {
            return $cached;
        }

        $provider = $this->getProvider($providerName);
        if (!$provider instanceof QueryProviderInterface) {
            return null;
        }

        $descriptor = $provider->getDescriptor();
        if (!\is_array($descriptor)) {
            self::$operationDescriptorCache[$scope][$providerName] = [];
            return null;
        }

        $resolvedProviderName = (string)($descriptor['provider'] ?? $provider->getProviderName());
        if ($resolvedProviderName === '') {
            $resolvedProviderName = $providerName;
        }

        $operationIndex = [];
        foreach (($descriptor['operations'] ?? []) as $operationDescriptor) {
            if (!\is_array($operationDescriptor)) {
                continue;
            }
            $name = (string)($operationDescriptor['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $operationIndex[$name] = $operationDescriptor;
        }

        self::$operationDescriptorCache[$scope][$resolvedProviderName] = $operationIndex;
        if ($resolvedProviderName !== $providerName) {
            self::$operationDescriptorCache[$scope][$providerName] = $operationIndex;
        }

        $descriptor = self::$operationDescriptorCache[$scope][$providerName][$operationName] ?? null;
        return \is_array($descriptor) ? $descriptor : null;
    }

    public static function resetDescriptorCaches(): void
    {
        self::$descriptorCache = [];
        self::$operationDescriptorCache = [];
    }

    private function descriptorCacheScope(): string
    {
        $language = \function_exists('w_env') ? (string)\w_env('user.lang', '') : '';
        if ($language === '') {
            $language = (string)($_COOKIE['WELINE_LANGUAGE'] ?? $_COOKIE['language'] ?? 'default');
        }

        return $language !== '' ? $language : 'default';
    }
}
