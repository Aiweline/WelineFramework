<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Policy;

use Weline\Framework\Compilation\CompiledPhpArrayWriter;

final class RuntimePolicyProviderCompiler
{
    public const FORMAT_VERSION = 1;
    public const CAPABILITY_PREFIX = RuntimePolicyProviderInterface::CAPABILITY_PREFIX;

    public function __construct(
        private readonly CompiledPhpArrayWriter $writer = new CompiledPhpArrayWriter(),
    ) {
    }

    /**
     * @param array<string, mixed> $moduleRegistry
     * @return array{format:int,providers:array<string,array{module:string,class_name:string}>,descriptors:list<array<string,mixed>>}
     */
    public function compile(array $moduleRegistry, string $target): array
    {
        $providers = [];
        $descriptors = [];
        $descriptorOwners = [];
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
                if (!\class_exists($className)) {
                    throw new \RuntimeException("Runtime policy provider class does not exist: {$className}");
                }
                if (!\is_subclass_of($className, RuntimePolicyProviderInterface::class)) {
                    throw new \RuntimeException(
                        "Runtime policy provider {$className} must implement " . RuntimePolicyProviderInterface::class,
                    );
                }
                $reflection = new \ReflectionClass($className);
                $constructor = $reflection->getConstructor();
                if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                    throw new \RuntimeException(
                        "Runtime policy provider {$className} must have a zero-argument constructor for compile-time loading.",
                    );
                }
                /** @var RuntimePolicyProviderInterface $provider */
                $provider = $reflection->newInstance();
                $providers[$capability] = ['module' => (string)$moduleName, 'class_name' => $className];
                foreach ($provider->policies() as $descriptor) {
                    if (\is_array($descriptor)) {
                        $descriptor = RuntimePolicyDescriptor::fromArray($descriptor);
                    }
                    if (!$descriptor instanceof RuntimePolicyDescriptor) {
                        throw new \RuntimeException("Runtime policy provider {$className} returned an invalid descriptor.");
                    }
                    if (isset($descriptorOwners[$descriptor->id])) {
                        throw new \RuntimeException(
                            "Duplicate runtime policy {$descriptor->id} from {$className}; already provided by "
                            . $descriptorOwners[$descriptor->id],
                        );
                    }
                    $descriptorOwners[$descriptor->id] = $className;
                    $descriptors[] = $descriptor->toArray() + [
                        'provider' => $className,
                        'module' => (string)$moduleName,
                    ];
                }
            }
        }
        \ksort($providers, \SORT_STRING);
        \usort($descriptors, static fn(array $left, array $right): int => [
            PolicyStage::from((string)$left['stage'])->order(),
            (int)$left['priority'],
            (string)$left['id'],
        ] <=> [
            PolicyStage::from((string)$right['stage'])->order(),
            (int)$right['priority'],
            (string)$right['id'],
        ]);

        $registry = [
            'format' => self::FORMAT_VERSION,
            'providers' => $providers,
            'descriptors' => $descriptors,
        ];
        $this->writer->write($target, $registry);
        return $registry;
    }
}
