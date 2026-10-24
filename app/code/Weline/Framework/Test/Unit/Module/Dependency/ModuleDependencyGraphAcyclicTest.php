<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Module\Dependency;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Module\Manifest\ModuleManifestReader;
use Weline\Framework\Register\Register;

/**
 * 首装按 register.php 依赖 ∪ etc/module.php requires 拓扑注册模块（Register::getOriginModulesData）。
 * 声明成环时排序只能任选一边先注册，Handle::register 随后对另一边抛「依赖未安装」——
 * 已装环境无感，全新安装必炸。这里锁住整张声明图无环。
 */
final class ModuleDependencyGraphAcyclicTest extends TestCase
{
    public function testDeclaredModuleDependenciesHaveNoCycle(): void
    {
        $codeRoot = \dirname(__DIR__, 6);
        $registers = \glob($codeRoot . '/*/*/register.php') ?: [];
        self::assertNotEmpty($registers, 'no module register.php found under ' . $codeRoot);

        $graph = [];
        foreach ($registers as $registerFile) {
            $args = Register::parserRegisterFunctionParams($registerFile);
            $type = \trim((string)($args['type'] ?? ''), '\'"');
            $module = \trim((string)($args['module_name'] ?? ''), '\'"');
            if ($module === '' || ($type !== '' && \stripos($type, 'MODULE') === false && $type !== 'module')) {
                continue;
            }
            $dependencies = \array_map(
                static fn(string $dependency): string => \trim($dependency, '\'"'),
                (array)($args['dependencies'] ?? [])
            );
            $basePath = \dirname($registerFile) . DIRECTORY_SEPARATOR;
            if (\is_file($basePath . 'etc' . DIRECTORY_SEPARATOR . 'module.php')) {
                $dependencies = \array_merge($dependencies, \array_keys((new ModuleManifestReader())->read($basePath)->requires));
            }
            $graph[$module] = \array_values(\array_unique(\array_filter($dependencies, 'is_string')));
        }

        $cycles = [];
        $state = [];
        $stack = [];
        $visit = function (string $node) use (&$visit, &$graph, &$state, &$stack, &$cycles): void {
            $state[$node] = 1;
            $stack[] = $node;
            foreach ($graph[$node] ?? [] as $next) {
                if (!isset($graph[$next])) {
                    continue;
                }
                if (($state[$next] ?? 0) === 1) {
                    $cycles[] = \implode(' -> ', \array_merge(\array_slice($stack, (int)\array_search($next, $stack, true)), [$next]));
                } elseif (($state[$next] ?? 0) === 0) {
                    $visit($next);
                }
            }
            \array_pop($stack);
            $state[$node] = 2;
        };
        foreach (\array_keys($graph) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $visit($node);
            }
        }

        self::assertSame([], $cycles, "Declared module dependency cycles break fresh install ordering:\n" . \implode("\n", $cycles));
    }
}
