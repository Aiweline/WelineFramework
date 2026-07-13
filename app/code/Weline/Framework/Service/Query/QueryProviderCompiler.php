<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Compilation\CompiledPhpArrayWriter;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\DefaultCrudProvider;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

final class QueryProviderCompiler
{
    public const FORMAT_VERSION = 2;

    private const EXTERNAL_AREAS = ['frontend', 'backend'];

    public function __construct(
        private readonly CompiledPhpArrayWriter $writer = new CompiledPhpArrayWriter(),
        private readonly BinQueryDescriptorAttributeResolver $descriptorResolver = new BinQueryDescriptorAttributeResolver(),
    ) {
    }

    /**
     * Compile both execution definitions and the final immutable descriptor
     * indexes. Runtime descriptor reads must never instantiate a Provider or
     * reflect its attributes in PROD/WLS.
     *
     * @return array{
     *     format:int,
     *     providers:array<string, array{class_name:string, source_file:string}>,
     *     deferred:list<array{class_name:string, source_file:string}>,
     *     descriptors:array<string, array<string, mixed>>,
     *     operations:array<string, array<string, array<string, mixed>>>,
     *     external_areas:array<string, array{
     *         providers:array<string, array<string, mixed>>,
     *         operations:array<string, array<string, array<string, mixed>>>,
     *         summaries:list<array<string, mixed>>
     *     }>
     * }
     */
    public function compile(string $target): array
    {
        $definitions = [[
            'class_name' => DefaultCrudProvider::class,
            'source_file' => __DIR__ . DS . 'Provider' . DS . 'DefaultCrudProvider.php',
            'provider_hint' => 'crud',
        ]];
        $queryPathPrefix = 'extends/module/weline_framework/query/';

        foreach (ExtendsData::getExtendedBy('Weline_Framework') as $extensions) {
            foreach ($extensions as $extension) {
                $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
                if (!str_starts_with(strtolower($relativePath), $queryPathPrefix)) {
                    continue;
                }

                $sourceFile = (string)($extension['source_file'] ?? '');
                $className = trim((string)($extension['class_name'] ?? ''));
                if ($className === '') {
                    $className = $this->resolveClassName($sourceFile) ?? '';
                }
                if ($className === '') {
                    continue;
                }

                $definitions[] = [
                    'class_name' => $className,
                    'source_file' => $sourceFile,
                    'provider_hint' => $this->resolveLiteralProviderName($sourceFile),
                ];
            }
        }

        $providers = [];
        $descriptors = [];
        $operations = [];
        $externalAreas = [];
        foreach (self::EXTERNAL_AREAS as $area) {
            $externalAreas[$area] = [
                'providers' => [],
                'operations' => [],
                'summaries' => [],
            ];
        }

        foreach ($definitions as $definition) {
            $compiled = $this->compileDefinition($definition);
            $providerName = $compiled['provider_name'];
            if (isset($providers[$providerName])) {
                throw new \RuntimeException("Duplicate QueryProvider name: {$providerName}.");
            }

            $providers[$providerName] = [
                'class_name' => $definition['class_name'],
                'source_file' => $definition['source_file'],
            ];
            $descriptors[$providerName] = $compiled['descriptor'];
            $operations[$providerName] = $compiled['operations'];

            foreach (self::EXTERNAL_AREAS as $area) {
                $areaOperations = [];
                foreach ($compiled['operations'] as $operationName => $operationDescriptor) {
                    if (($operationDescriptor['external'] ?? false) !== true
                        || ($operationDescriptor[$area] ?? false) !== true
                    ) {
                        continue;
                    }
                    $areaOperations[$operationName] = $operationDescriptor;
                }
                if ($areaOperations === []) {
                    continue;
                }

                $areaDescriptor = $compiled['descriptor'];
                $areaDescriptor['operations'] = \array_values($areaOperations);
                $areaDescriptor['operation_count'] = \count($areaOperations);
                $externalAreas[$area]['providers'][$providerName] = $areaDescriptor;
                $externalAreas[$area]['operations'][$providerName] = $areaOperations;
                $externalAreas[$area]['summaries'][] = $this->summarizeDescriptor($areaDescriptor);
            }
        }

        ksort($providers);
        ksort($descriptors);
        ksort($operations);
        foreach ($externalAreas as &$areaIndex) {
            ksort($areaIndex['providers']);
            ksort($areaIndex['operations']);
            usort(
                $areaIndex['summaries'],
                static fn(array $left, array $right): int => ((string)$left['provider']) <=> ((string)$right['provider']),
            );
        }
        unset($areaIndex);

        $registry = [
            'format' => self::FORMAT_VERSION,
            'providers' => $providers,
            'deferred' => [],
            'descriptors' => $descriptors,
            'operations' => $operations,
            'external_areas' => $externalAreas,
        ];
        $this->writer->write($target, $registry);

        return $registry;
    }

    /**
     * @param array{class_name:string, source_file:string, provider_hint:?string} $definition
     * @return array{
     *     provider_name:string,
     *     descriptor:array<string, mixed>,
     *     operations:array<string, array<string, mixed>>
     * }
     */
    private function compileDefinition(array $definition): array
    {
        $className = \trim($definition['class_name']);
        $sourceFile = $definition['source_file'];
        if (!\class_exists($className, false) && $sourceFile !== '' && \is_file($sourceFile)) {
            require_once $sourceFile;
        }
        if (!\class_exists($className)) {
            throw new \RuntimeException("QueryProvider class cannot be loaded: {$className}.");
        }

        $provider = ObjectManager::getInstance($className);
        if (!$provider instanceof QueryProviderInterface) {
            throw new \RuntimeException("QueryProvider {$className} violates QueryProviderInterface.");
        }

        $providerName = \trim($provider->getProviderName());
        if ($providerName === '') {
            throw new \RuntimeException("QueryProvider {$className} returned an empty provider name.");
        }
        $providerHint = \trim((string)($definition['provider_hint'] ?? ''));
        if ($providerHint !== '' && $providerHint !== $providerName) {
            throw new \RuntimeException(
                "QueryProvider {$className} name mismatch: source={$providerHint}, runtime={$providerName}.",
            );
        }

        $descriptor = $this->descriptorResolver->merge($provider, $provider->getDescriptor());
        $descriptorProvider = \trim((string)($descriptor['provider'] ?? ''));
        if ($descriptorProvider !== '' && $descriptorProvider !== $providerName) {
            throw new \RuntimeException(
                "QueryProvider {$className} descriptor name mismatch: {$descriptorProvider} != {$providerName}.",
            );
        }
        $descriptor['provider'] = $providerName;

        $operationIndex = [];
        $normalizedOperations = [];
        foreach (($descriptor['operations'] ?? []) as $operationDescriptor) {
            if (!\is_array($operationDescriptor)) {
                throw new \RuntimeException("QueryProvider {$providerName} contains a non-array operation descriptor.");
            }
            $operationName = \trim((string)($operationDescriptor['name'] ?? ''));
            if ($operationName === '') {
                throw new \RuntimeException("QueryProvider {$providerName} contains an unnamed operation.");
            }
            if (isset($operationIndex[$operationName])) {
                throw new \RuntimeException(
                    "QueryProvider {$providerName} contains duplicate operation {$operationName}.",
                );
            }
            $operationDescriptor['name'] = $operationName;
            if (($operationDescriptor['external'] ?? false) === true) {
                $mode = \strtolower(\trim((string)($operationDescriptor['mode'] ?? '')));
                if (!\in_array($mode, ['read', 'write'], true)) {
                    throw new \RuntimeException(
                        "External QueryProvider operation {$providerName}.{$operationName} must declare mode=read|write.",
                    );
                }
                $operationDescriptor['mode'] = $mode;
            }
            $this->assertImmutableValue($operationDescriptor, "{$providerName}.{$operationName}");
            $operationIndex[$operationName] = $operationDescriptor;
            $normalizedOperations[] = $operationDescriptor;
        }
        $descriptor['operations'] = $normalizedOperations;
        $this->assertImmutableValue($descriptor, $providerName);

        return [
            'provider_name' => $providerName,
            'descriptor' => $descriptor,
            'operations' => $operationIndex,
        ];
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function summarizeDescriptor(array $descriptor): array
    {
        return [
            'provider' => (string)($descriptor['provider'] ?? ''),
            'name' => (string)($descriptor['name'] ?? ''),
            'description' => (string)($descriptor['description'] ?? ''),
            'module' => (string)($descriptor['module'] ?? ''),
            'operation_count' => \count($descriptor['operations'] ?? []),
        ];
    }

    private function assertImmutableValue(mixed $value, string $path, int $depth = 0): void
    {
        if ($depth > 64) {
            throw new \RuntimeException("QueryProvider descriptor nesting exceeds 64 levels at {$path}.");
        }
        if ($value === null || \is_string($value) || \is_int($value) || \is_bool($value)) {
            return;
        }
        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new \RuntimeException("QueryProvider descriptor contains a non-finite float at {$path}.");
            }
            return;
        }
        if (!\is_array($value)) {
            throw new \RuntimeException(
                'QueryProvider descriptor must contain only immutable scalar arrays; invalid '
                . \get_debug_type($value) . " at {$path}.",
            );
        }
        foreach ($value as $key => $item) {
            if (!\is_int($key) && !\is_string($key)) {
                throw new \RuntimeException("QueryProvider descriptor contains an invalid key at {$path}.");
            }
            $this->assertImmutableValue($item, $path . '.' . (string)$key, $depth + 1);
        }
    }

    private function resolveClassName(string $sourceFile): ?string
    {
        if ($sourceFile === '' || !is_file($sourceFile)) {
            return null;
        }
        $source = file_get_contents($sourceFile);
        if (!is_string($source)) {
            return null;
        }

        $namespace = '';
        $class = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAME_QUALIFIED && $namespace === '') {
                $namespace = $token[1];
                continue;
            }
            if ($token[0] === T_CLASS) {
                $class = '__next__';
                continue;
            }
            if ($class === '__next__' && $token[0] === T_STRING) {
                $class = $token[1];
                break;
            }
        }

        return $namespace !== '' && $class !== '' && $class !== '__next__'
            ? $namespace . '\\' . $class
            : null;
    }

    private function resolveLiteralProviderName(string $sourceFile): ?string
    {
        if ($sourceFile === '' || !is_file($sourceFile)) {
            return null;
        }
        $source = file_get_contents($sourceFile);
        if (!is_string($source)) {
            return null;
        }

        $tokens = token_get_all($source);
        $insideTargetMethod = false;
        $waitingForName = false;
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_FUNCTION) {
                $waitingForName = true;
                $insideTargetMethod = false;
                continue;
            }
            if ($waitingForName && $token[0] === T_STRING) {
                $insideTargetMethod = $token[1] === 'getProviderName';
                $waitingForName = false;
                continue;
            }
            if (!$insideTargetMethod || $token[0] !== T_RETURN) {
                continue;
            }
            for ($next = $index + 1, $count = count($tokens); $next < $count; ++$next) {
                $candidate = $tokens[$next];
                if ($candidate === ';') {
                    return null;
                }
                if (is_array($candidate) && $candidate[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $value = trim(stripcslashes(substr($candidate[1], 1, -1)));
                    return $value !== '' ? $value : null;
                }
                if (is_array($candidate) && !in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    return null;
                }
            }
        }

        return null;
    }
}
