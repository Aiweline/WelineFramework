<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

use Weline\Framework\Architecture\Module\ModuleGraphValidator;
use Weline\Framework\Module\Manifest\ModuleManifest;
use Weline\Framework\Module\Manifest\ModuleManifestReader;

final class ModuleRegistryCompiler
{
    public const FORMAT_VERSION = 1;

    public function __construct(
        private readonly ModuleManifestReader $manifestReader = new ModuleManifestReader(),
        private readonly ModuleGraphValidator $graphValidator = new ModuleGraphValidator(),
        private readonly CompiledPhpArrayWriter $writer = new CompiledPhpArrayWriter(),
    ) {
    }

    /**
     * @return array{format: int, modules: array<string, array<string, mixed>>, order: list<string>}
     */
    public function compile(string $modulesRoot, string $target): array
    {
        $manifests = $this->manifestReader->readAll($modulesRoot);
        $errors = $this->graphValidator->validate($manifests);
        if ($errors !== []) {
            throw new \RuntimeException("Module registry compilation failed:\n- " . implode("\n- ", $errors));
        }

        $modules = [];
        foreach ($manifests as $name => $manifest) {
            $modules[$name] = $manifest->toArray() + [
                'path' => $manifest->path,
            ];
        }

        $registry = [
            'format' => self::FORMAT_VERSION,
            'modules' => $modules,
            'order' => $this->topologicalOrder($manifests),
        ];
        $this->writer->write($target, $registry);

        return $registry;
    }

    /**
     * @param array<string, ModuleManifest> $manifests
     * @return list<string>
     */
    private function topologicalOrder(array $manifests): array
    {
        $visited = [];
        $order = [];
        $visit = function (string $module) use (&$visit, &$visited, &$order, $manifests): void {
            if (isset($visited[$module])) {
                return;
            }
            $visited[$module] = true;
            foreach (array_keys($manifests[$module]->requires) as $dependency) {
                if (isset($manifests[$dependency])) {
                    $visit($dependency);
                }
            }
            $order[] = $module;
        };
        foreach (array_keys($manifests) as $module) {
            $visit($module);
        }

        return $order;
    }
}
