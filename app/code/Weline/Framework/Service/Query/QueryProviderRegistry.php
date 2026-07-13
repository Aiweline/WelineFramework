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
    public const COMPILED_REGISTRY_FILE = BP . 'generated' . DS . 'framework' . DS . 'query_providers.php';

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

    /** @var array<string, array{providers:array, operations:array, summaries:array}> */
    private static array $externalAreaCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $compiledDescriptors = [];

    /** @var list<array<string, mixed>> */
    private array $compiledDescriptorList = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $compiledOperations = [];

    /** @var array<string, array{providers:array, operations:array, summaries:array}> */
    private array $compiledExternalAreas = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $compiledExternalDescriptorLists = [];

    private bool $compiledDescriptorIndexLoaded = false;

    private ?BinQueryDescriptorAttributeResolver $binQueryAttributeResolver = null;

    private function loadDefinitions(): void
    {
        if ($this->definitionsLoaded) {
            return;
        }

        $compiled = $this->loadCompiledDefinitions();
        if ($compiled !== null) {
            $this->providerDefinitions = $compiled['providers'];
            $this->deferredDefinitions = $compiled['deferred'];
            $this->compiledDescriptors = $compiled['descriptors'];
            $this->compiledDescriptorList = \array_values($compiled['descriptors']);
            $this->compiledOperations = $compiled['operations'];
            $this->compiledExternalAreas = $compiled['external_areas'];
            foreach ($compiled['external_areas'] as $area => $areaIndex) {
                $this->compiledExternalDescriptorLists[$area] = \array_values($areaIndex['providers']);
            }
            $this->compiledDescriptorIndexLoaded = true;
            $this->definitionsLoaded = true;
            return;
        }
        if ($this->compiledRegistryRequired()) {
            throw new \RuntimeException(
                'Compiled QueryProvider registry is required. Run: php bin/w framework:compile',
            );
        }

        // Development/migration bridge only. Compiled registries include this
        // definition, so PROD/WLS descriptor reads never instantiate it.
        $this->providerDefinitions['crud'] = [
            'class_name' => DefaultCrudProvider::class,
            'source_file' => __DIR__ . DS . 'Provider' . DS . 'DefaultCrudProvider.php',
        ];

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

        $this->definitionsLoaded = true;
    }

    /**
     * @return array{
     *     providers:array<string, array{class_name:string, source_file:string}>,
     *     deferred:list<array{class_name:string, source_file:string}>,
     *     descriptors:array<string, array<string, mixed>>,
     *     operations:array<string, array<string, array<string, mixed>>>,
     *     external_areas:array<string, array{providers:array, operations:array, summaries:array}>
     * }|null
     */
    private function loadCompiledDefinitions(): ?array
    {
        if (!is_file(self::COMPILED_REGISTRY_FILE)) {
            return null;
        }
        $registry = require self::COMPILED_REGISTRY_FILE;
        $valid = is_array($registry)
            && ($registry['format'] ?? null) === QueryProviderCompiler::FORMAT_VERSION
            && is_array($registry['providers'] ?? null)
            && is_array($registry['deferred'] ?? null)
            && is_array($registry['descriptors'] ?? null)
            && is_array($registry['operations'] ?? null)
            && is_array($registry['external_areas'] ?? null);
        if (!$valid) {
            if (!$this->compiledRegistryRequired()) {
                return null;
            }
            throw new \RuntimeException(
                'Compiled QueryProvider registry is invalid. Re-run: php bin/w framework:compile',
            );
        }

        foreach (['frontend', 'backend'] as $area) {
            $areaIndex = $registry['external_areas'][$area] ?? null;
            if (!is_array($areaIndex)
                || !is_array($areaIndex['providers'] ?? null)
                || !is_array($areaIndex['operations'] ?? null)
                || !is_array($areaIndex['summaries'] ?? null)
            ) {
                if (!$this->compiledRegistryRequired()) {
                    return null;
                }
                throw new \RuntimeException(
                    'Compiled QueryProvider area index is invalid. Re-run: php bin/w framework:compile',
                );
            }
        }

        return [
            'providers' => $registry['providers'],
            'deferred' => array_values($registry['deferred']),
            'descriptors' => $registry['descriptors'],
            'operations' => $registry['operations'],
            'external_areas' => $registry['external_areas'],
        ];
    }

    private function compiledRegistryRequired(): bool
    {
        if (defined('WLS_MODE') && WLS_MODE) {
            return true;
        }
        if (defined('PROD') && PROD) {
            return true;
        }
        return defined('DEV') && !DEV;
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
        $this->loadDefinitions();
        if ($this->compiledDescriptorIndexLoaded) {
            return $this->compiledDescriptorList;
        }

        $scope = $this->descriptorCacheScope();
        if (isset(self::$descriptorCache[$scope])) {
            return self::$descriptorCache[$scope];
        }

        $descriptors = [];
        $operationIndex = [];
        foreach ($this->getAllProviders() as $provider) {
            $descriptor = $this->mergeBinQueryAttributes($provider, $provider->getDescriptor());
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

        $this->loadDefinitions();
        if ($this->compiledDescriptorIndexLoaded) {
            $descriptor = $this->compiledOperations[$providerName][$operationName] ?? null;
            return \is_array($descriptor) ? $descriptor : null;
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

        $descriptor = $this->mergeBinQueryAttributes($provider, $provider->getDescriptor());
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

    /**
     * Descriptor lookup used by help/introspection paths. In PROD/WLS this is
     * an immutable hash lookup; the linear fallback exists only for DEV
     * migration before framework:compile has been run.
     *
     * @return array<string, mixed>|null
     */
    public function getProviderDescriptor(string $providerName): ?array
    {
        if ($providerName === '') {
            return null;
        }

        $this->loadDefinitions();
        if ($this->compiledDescriptorIndexLoaded) {
            $descriptor = $this->compiledDescriptors[$providerName] ?? null;
            return \is_array($descriptor) ? $descriptor : null;
        }

        foreach ($this->getAllDescriptors() as $descriptor) {
            if (\is_array($descriptor) && (string)($descriptor['provider'] ?? '') === $providerName) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getExternalDescriptorsForArea(string $area): array
    {
        $area = \strtolower(\trim($area));
        $this->loadDefinitions();
        if ($this->compiledDescriptorIndexLoaded) {
            return $this->compiledExternalDescriptorLists[$area] ?? [];
        }
        $index = $this->externalAreaIndex($area);
        return \array_values($index['providers']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getExternalProviderSummariesForArea(string $area): array
    {
        return $this->externalAreaIndex($area)['summaries'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExternalProviderDescriptorForArea(string $area, string $providerName): ?array
    {
        if ($providerName === '') {
            return null;
        }
        $descriptor = $this->externalAreaIndex($area)['providers'][$providerName] ?? null;
        return \is_array($descriptor) ? $descriptor : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExternalOperationDescriptorForArea(
        string $area,
        string $providerName,
        string $operationName,
    ): ?array {
        if ($providerName === '' || $operationName === '') {
            return null;
        }
        $descriptor = $this->externalAreaIndex($area)['operations'][$providerName][$operationName] ?? null;
        return \is_array($descriptor) ? $descriptor : null;
    }

    /**
     * @return array{providers:array, operations:array, summaries:array}
     */
    private function externalAreaIndex(string $area): array
    {
        $area = \strtolower(\trim($area));
        if (!\in_array($area, ['frontend', 'backend'], true)) {
            return ['providers' => [], 'operations' => [], 'summaries' => []];
        }

        $this->loadDefinitions();
        if ($this->compiledDescriptorIndexLoaded) {
            return $this->compiledExternalAreas[$area]
                ?? ['providers' => [], 'operations' => [], 'summaries' => []];
        }

        $scope = $this->descriptorCacheScope() . "\0" . $area;
        if (isset(self::$externalAreaCache[$scope])) {
            return self::$externalAreaCache[$scope];
        }

        $providers = [];
        $operations = [];
        $summaries = [];
        foreach ($this->getAllDescriptors() as $descriptor) {
            if (!\is_array($descriptor)) {
                continue;
            }
            $providerName = (string)($descriptor['provider'] ?? '');
            if ($providerName === '') {
                continue;
            }

            $areaOperations = [];
            foreach (($descriptor['operations'] ?? []) as $operationDescriptor) {
                if (!\is_array($operationDescriptor)
                    || ($operationDescriptor['external'] ?? false) !== true
                    || ($operationDescriptor[$area] ?? false) !== true
                ) {
                    continue;
                }
                $operationName = (string)($operationDescriptor['name'] ?? '');
                if ($operationName !== '') {
                    $areaOperations[$operationName] = $operationDescriptor;
                }
            }
            if ($areaOperations === []) {
                continue;
            }

            $areaDescriptor = $descriptor;
            $areaDescriptor['operations'] = \array_values($areaOperations);
            $areaDescriptor['operation_count'] = \count($areaOperations);
            $providers[$providerName] = $areaDescriptor;
            $operations[$providerName] = $areaOperations;
            $summaries[] = [
                'provider' => $providerName,
                'name' => (string)($areaDescriptor['name'] ?? ''),
                'description' => (string)($areaDescriptor['description'] ?? ''),
                'module' => (string)($areaDescriptor['module'] ?? ''),
                'operation_count' => \count($areaOperations),
            ];
        }

        return self::$externalAreaCache[$scope] = [
            'providers' => $providers,
            'operations' => $operations,
            'summaries' => $summaries,
        ];
    }

    public static function resetDescriptorCaches(): void
    {
        self::$descriptorCache = [];
        self::$operationDescriptorCache = [];
        self::$externalAreaCache = [];
    }

    private function descriptorCacheScope(): string
    {
        $language = \function_exists('w_env') ? (string)\w_env('user.lang', '') : '';
        if ($language === '') {
            $language = (string)($_COOKIE['WELINE_LANGUAGE'] ?? $_COOKIE['language'] ?? 'default');
        }

        return $language !== '' ? $language : 'default';
    }

    /**
     * @param mixed $descriptor
     * @return mixed
     */
    private function mergeBinQueryAttributes(QueryProviderInterface $provider, mixed $descriptor): mixed
    {
        if (!\is_array($descriptor)) {
            return $descriptor;
        }

        if ($this->binQueryAttributeResolver === null) {
            $this->binQueryAttributeResolver = new BinQueryDescriptorAttributeResolver();
        }

        return $this->binQueryAttributeResolver->merge($provider, $descriptor);
    }
}
