<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

use Weline\Framework\Container\ContainerServiceCatalog;
use Weline\Framework\Manager\ContainerInterface;
use Weline\Framework\Manager\ServiceScope;

/**
 * Compiles an explicit service catalog into deterministic constructor
 * closures. Reflection is restricted to this control-plane command.
 */
final class ContainerCompiler
{
    public const FORMAT_VERSION = 1;

    public function __construct(
        private readonly ContainerServiceCatalog $catalog = new ContainerServiceCatalog(),
        private readonly AtomicCompiledFilePublisher $publisher = new AtomicCompiledFilePublisher(),
    ) {
    }

    /**
     * @return array{format:int, services:array<string, array{class:string, scope:string, init:bool, dependencies:list<string>}>}
     */
    public function compile(string $target): array
    {
        $definitions = $this->catalog->definitions();
        \ksort($definitions);

        $compiled = [];
        $factorySources = [];
        foreach ($definitions as $id => $definition) {
            if (!\is_string($id) || $id === '') {
                throw new \RuntimeException('Compiled container service id must be a non-empty string.');
            }
            $class = (string)($definition['class'] ?? '');
            $scope = $definition['scope'] ?? null;
            if ($class === '' || !$scope instanceof ServiceScope) {
                throw new \RuntimeException("Compiled container definition {$id} is invalid.");
            }

            $factory = $this->compileFactory($id, $class, $definitions);
            $compiled[$id] = [
                'class' => $class,
                'scope' => $scope->value,
                'init' => $factory['init'],
                'dependencies' => $factory['dependencies'],
            ];
            $factorySources[$id] = $factory['source'];
        }

        $this->assertAcyclic($compiled);
        $this->writeRegistry($target, $compiled, $factorySources);

        return [
            'format' => self::FORMAT_VERSION,
            'services' => $compiled,
        ];
    }

    /**
     * @param array<string, array{class:class-string, scope:ServiceScope}> $definitions
     * @return array{source:string, init:bool, dependencies:list<string>}
     */
    private function compileFactory(string $id, string $class, array $definitions): array
    {
        if (!\class_exists($class)) {
            throw new \RuntimeException("Compiled container class cannot be loaded: {$class}.");
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException("Compiled container class is not instantiable: {$class}.");
        }
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && !$constructor->isPublic()) {
            throw new \RuntimeException("Compiled container requires a public constructor: {$class}.");
        }
        $initializer = $reflection->hasMethod('__init')
            ? $reflection->getMethod('__init')
            : null;
        if ($initializer !== null && !$initializer->isPublic()) {
            throw new \RuntimeException("Compiled container requires a public __init method: {$class}.");
        }

        $arguments = [];
        $dependencies = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic()) {
                throw new \RuntimeException("Compiled container does not support variadic constructor {$class}.");
            }

            $nameLiteral = \var_export($parameter->getName(), true);
            $override = "\\array_key_exists({$nameLiteral}, \$arguments) ? \$arguments[{$nameLiteral}] : ";
            $dependency = $this->classDependency($parameter);
            if ($dependency !== null) {
                if (!isset($definitions[$dependency])) {
                    if (!$parameter->isDefaultValueAvailable()) {
                        throw new \RuntimeException(
                            "Compiled service {$id} depends on undeclared service {$dependency}.",
                        );
                    }
                    $default = $this->exportValue($parameter->getDefaultValue());
                    $arguments[] = "({$override}{$default})";
                    continue;
                }
                $dependencies[] = $dependency;
                $dependencyLiteral = \var_export($dependency, true);
                $arguments[] = "({$override}\$container->get({$dependencyLiteral}))";
                continue;
            }

            if (!$parameter->isDefaultValueAvailable()) {
                throw new \RuntimeException(
                    "Compiled service {$id} has unresolved scalar parameter \${$parameter->getName()}.",
                );
            }
            $default = $this->exportValue($parameter->getDefaultValue());
            $arguments[] = "({$override}{$default})";
        }

        $fqcn = '\\' . \ltrim($class, '\\');
        $constructorSource = $arguments === []
            ? "new {$fqcn}()"
            : "new {$fqcn}(\n                    " . \implode(",\n                    ", $arguments) . "\n                )";

        return [
            'source' => "static function (ContainerInterface \$container, array \$arguments = []): object {\n"
                . "                return {$constructorSource};\n"
                . '            }',
            'init' => $initializer !== null,
            'dependencies' => \array_values(\array_unique($dependencies)),
        ];
    }

    private function classDependency(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? null : $type->getName();
        }
        if ($type instanceof \ReflectionUnionType) {
            $classes = [];
            foreach ($type->getTypes() as $member) {
                if (!$member->isBuiltin()) {
                    $classes[] = $member->getName();
                }
            }
            $classes = \array_values(\array_unique($classes));
            return \count($classes) === 1 ? $classes[0] : null;
        }
        if ($type instanceof \ReflectionIntersectionType) {
            throw new \RuntimeException(
                'Compiled container does not support intersection constructor type for $' . $parameter->getName() . '.',
            );
        }

        return null;
    }

    private function exportValue(mixed $value): string
    {
        return $value === [] ? '[]' : \var_export($value, true);
    }

    /**
     * @param array<string, array{class:string, scope:string, init:bool, dependencies:list<string>}> $services
     */
    private function assertAcyclic(array $services): void
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $id) use (&$visit, &$visiting, &$visited, $services): void {
            if (isset($visited[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                throw new \RuntimeException("Compiled container dependency cycle detected at {$id}.");
            }
            $visiting[$id] = true;
            foreach ($services[$id]['dependencies'] ?? [] as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
        };

        foreach (\array_keys($services) as $id) {
            $visit($id);
        }
    }

    /**
     * @param array<string, array{class:string, scope:string, init:bool, dependencies:list<string>}> $services
     * @param array<string, string> $factorySources
     */
    private function writeRegistry(string $target, array $services, array $factorySources): void
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use ' . ContainerInterface::class . ';',
            '',
            'return [',
            "    'format' => " . self::FORMAT_VERSION . ',',
            "    'services' => [",
        ];
        foreach ($services as $id => $service) {
            $lines[] = '        ' . \var_export($id, true) . ' => [';
            $lines[] = "            'class' => " . \var_export($service['class'], true) . ',';
            $lines[] = "            'scope' => " . \var_export($service['scope'], true) . ',';
            $lines[] = "            'init' => " . \var_export($service['init'], true) . ',';
            $lines[] = "            'factory' => " . $factorySources[$id] . ',';
            $lines[] = '        ],';
        }
        $lines[] = '    ],';
        $lines[] = '];';
        $lines[] = '';

        $content = \implode("\n", $lines);
        $this->publisher->publish($target, $content);
    }
}
