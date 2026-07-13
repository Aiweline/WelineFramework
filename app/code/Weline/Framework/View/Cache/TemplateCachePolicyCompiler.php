<?php

declare(strict_types=1);

namespace Weline\Framework\View\Cache;

use Weline\Framework\Compilation\CompiledPhpArrayWriter;

final class TemplateCachePolicyCompiler
{
    public const FORMAT_VERSION = 1;
    public const CAPABILITY_PREFIX = 'template_cache_policy.';
    private const CONTEXTS = [
        'account_sidebar' => true,
        'body_end' => true,
        'frontend_auth' => true,
        'header_action' => true,
        'i18n' => true,
        'static' => true,
    ];

    public function __construct(
        private readonly CompiledPhpArrayWriter $writer = new CompiledPhpArrayWriter(),
    ) {
    }

    /**
     * @param array<string, mixed> $moduleRegistry
     * @return array<string, mixed>
     */
    public function compile(array $moduleRegistry, string $target, string $hookRegistryFile): array
    {
        $providers = [];
        $requestHooks = [];
        $diagnosticHooks = [];
        $aggregateHooks = [];
        $outputFiles = [];

        foreach ((array)($moduleRegistry['order'] ?? []) as $moduleName) {
            $module = $moduleRegistry['modules'][$moduleName] ?? null;
            if (!\is_array($module)) {
                continue;
            }
            foreach ((array)($module['provides'] ?? []) as $capability => $className) {
                $capability = (string)$capability;
                $className = \trim((string)$className);
                if (!\str_starts_with($capability, self::CAPABILITY_PREFIX) || $className === '') {
                    continue;
                }
                $provider = $this->instantiateProvider($className);
                $policies = $provider->policies();
                $providers[$capability] = [
                    'module' => (string)$moduleName,
                    'class_name' => $className,
                ];
                $this->mergeList($requestHooks, $policies['request_hooks'] ?? [], $className);
                $this->mergeList($diagnosticHooks, $policies['diagnostic_hooks'] ?? [], $className);
                $this->mergeMap($aggregateHooks, $policies['aggregate_hooks'] ?? [], $className, true);
                $this->mergeMap($outputFiles, $policies['output_files'] ?? [], $className, false);
            }
        }

        \ksort($providers, \SORT_STRING);
        \ksort($requestHooks, \SORT_STRING);
        \ksort($diagnosticHooks, \SORT_STRING);
        \ksort($aggregateHooks, \SORT_STRING);
        \ksort($outputFiles, \SORT_STRING);

        $hookRegistry = $this->readHookRegistry($hookRegistryFile);
        $aggregateManifests = $this->compileAggregateManifests($aggregateHooks, $outputFiles, $hookRegistry);
        $policyPayload = [
            'providers' => $providers,
            'request_hooks' => \array_keys($requestHooks),
            'diagnostic_hooks' => \array_keys($diagnosticHooks),
            'aggregate_hooks' => $aggregateHooks,
            'output_files' => $outputFiles,
            'aggregate_manifests' => $aggregateManifests,
            'hook_manifest_digest' => $this->digest($hookRegistry),
        ];
        $registry = [
            'format' => self::FORMAT_VERSION,
            'digest' => $this->digest($policyPayload),
        ] + $policyPayload;

        $this->writer->write($target, $registry);
        return $registry;
    }

    private function instantiateProvider(string $className): TemplateCachePolicyProviderInterface
    {
        if (!\class_exists($className)) {
            throw new \RuntimeException("Template cache policy provider class does not exist: {$className}");
        }
        if (!\is_subclass_of($className, TemplateCachePolicyProviderInterface::class)) {
            throw new \RuntimeException(
                "Template cache policy provider {$className} must implement "
                . TemplateCachePolicyProviderInterface::class,
            );
        }
        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new \RuntimeException(
                "Template cache policy provider {$className} must have a zero-argument constructor.",
            );
        }

        /** @var TemplateCachePolicyProviderInterface $provider */
        $provider = $reflection->newInstance();
        return $provider;
    }

    /**
     * @param array<string, true> $target
     */
    private function mergeList(array &$target, mixed $values, string $provider): void
    {
        if (!\is_array($values)) {
            throw new \RuntimeException("Template cache policy provider {$provider} returned a non-list policy.");
        }
        foreach ($values as $value) {
            if (!\is_string($value) || \trim($value) === '') {
                throw new \RuntimeException("Template cache policy provider {$provider} returned an invalid hook name.");
            }
            $target[\trim($value)] = true;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $target
     */
    private function mergeMap(
        array &$target,
        mixed $values,
        string $provider,
        bool $aggregate,
    ): void {
        if (!\is_array($values)) {
            throw new \RuntimeException("Template cache policy provider {$provider} returned a non-map policy.");
        }
        foreach ($values as $key => $descriptor) {
            if (!\is_string($key) || \trim($key) === '' || !\is_array($descriptor)) {
                throw new \RuntimeException("Template cache policy provider {$provider} returned an invalid descriptor.");
            }
            $key = \trim($key);
            $descriptor = $this->normalizeDescriptor($descriptor, $provider, $aggregate);
            if (isset($target[$key]) && $target[$key] !== $descriptor) {
                throw new \RuntimeException("Conflicting template cache policy for {$key}.");
            }
            $target[$key] = $descriptor;
        }
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function normalizeDescriptor(array $descriptor, string $provider, bool $aggregate): array
    {
        if (!$this->isDescriptorData($descriptor)) {
            throw new \RuntimeException("Template cache policy provider {$provider} returned executable descriptor data.");
        }
        $context = \trim((string)($descriptor['context'] ?? ''));
        if (!isset(self::CONTEXTS[$context])) {
            throw new \RuntimeException("Template cache policy provider {$provider} returned unknown context {$context}.");
        }
        $descriptor['context'] = $context;
        $descriptor['allow_async_refresh'] = (bool)($descriptor['allow_async_refresh'] ?? $context === 'static');
        if ($aggregate) {
            $descriptor['require_all_outputs_cacheable'] = (bool)($descriptor['require_all_outputs_cacheable'] ?? true);
        }
        if (isset($descriptor['render_once_group'])) {
            $group = \trim((string)$descriptor['render_once_group']);
            if ($group === '' || \preg_match('/^[A-Za-z0-9_.:-]+$/', $group) !== 1) {
                throw new \RuntimeException("Template cache policy provider {$provider} returned invalid render_once_group.");
            }
            $descriptor['render_once_group'] = $group;
        }
        \ksort($descriptor, \SORT_STRING);
        return $descriptor;
    }

    private function isDescriptorData(array $descriptor): bool
    {
        foreach ($descriptor as $value) {
            if (\is_object($value) || \is_resource($value)) {
                return false;
            }
            if (\is_array($value) && !$this->isDescriptorData($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function readHookRegistry(string $file): array
    {
        if (!\is_file($file)) {
            return [];
        }
        $registry = require $file;
        return \is_array($registry) ? $registry : [];
    }

    /**
     * @param array<string, array<string, mixed>> $aggregateHooks
     * @param array<string, array<string, mixed>> $outputFiles
     * @param array<string, mixed> $hookRegistry
     * @return array<string, array{complete:bool,digest:string,outputs:list<string>}>
     */
    private function compileAggregateManifests(
        array $aggregateHooks,
        array $outputFiles,
        array $hookRegistry,
    ): array {
        $manifests = [];
        foreach ($aggregateHooks as $hook => $_descriptor) {
            $implementations = $hookRegistry['hooks'][$hook]['implementations'] ?? [];
            $implementations = \is_array($implementations) ? $implementations : [];
            $outputs = [];
            foreach ($implementations as $module => $implementation) {
                if (!\is_string($module) || !\is_array($implementation)) {
                    continue;
                }
                $file = \trim((string)($implementation['file'] ?? ''));
                if ($file === '') {
                    continue;
                }
                $outputs[] = \str_contains($file, '::')
                    ? $file
                    : $module . '::hooks/' . \ltrim($file, '/\\');
            }
            $complete = $outputs !== [];
            foreach ($outputs as $output) {
                if (!isset($outputFiles[$output])) {
                    $complete = false;
                    break;
                }
            }
            $manifests[$hook] = [
                'complete' => $complete,
                'digest' => $this->digest($implementations),
                'outputs' => $outputs,
            ];
        }
        \ksort($manifests, \SORT_STRING);
        return $manifests;
    }

    private function digest(array $data): string
    {
        return \hash('sha256', (string)\json_encode(
            $data,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        ));
    }
}
