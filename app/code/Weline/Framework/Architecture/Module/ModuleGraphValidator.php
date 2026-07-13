<?php

declare(strict_types=1);

namespace Weline\Framework\Architecture\Module;

use Weline\Framework\Module\Manifest\ModuleManifest;

final class ModuleGraphValidator
{
    /**
     * @param array<string, ModuleManifest> $manifests
     * @return list<string>
     */
    public function validate(array $manifests): array
    {
        $errors = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest->requires as $dependency => $constraint) {
                if (!isset($manifests[$dependency])) {
                    $errors[] = "{$manifest->name} requires missing module {$dependency}.";
                    continue;
                }
                if (!$this->matchesConstraint($manifests[$dependency]->version, $constraint)) {
                    $actual = $manifests[$dependency]->version;
                    $errors[] = "{$manifest->name} requires {$dependency} {$constraint}, installed version is {$actual}.";
                }
            }
        }

        foreach ($this->findCycles($manifests) as $cycle) {
            $errors[] = 'Required module dependency cycle: ' . implode(' -> ', $cycle) . '.';
        }

        sort($errors);
        return $errors;
    }

    /**
     * @param array<string, ModuleManifest> $manifests
     * @return list<list<string>>
     */
    public function findCycles(array $manifests): array
    {
        $state = [];
        $stack = [];
        $cycles = [];
        $signatures = [];

        $visit = function (string $module) use (&$visit, &$state, &$stack, &$cycles, &$signatures, $manifests): void {
            $state[$module] = 1;
            $stack[] = $module;
            foreach (array_keys($manifests[$module]->requires ?? []) as $dependency) {
                if (!isset($manifests[$dependency])) {
                    continue;
                }
                if (($state[$dependency] ?? 0) === 0) {
                    $visit($dependency);
                    continue;
                }
                if (($state[$dependency] ?? 0) !== 1) {
                    continue;
                }
                $offset = array_search($dependency, $stack, true);
                if ($offset === false) {
                    continue;
                }
                $cycle = array_slice($stack, $offset);
                $cycle[] = $dependency;
                $key = $this->cycleSignature($cycle);
                if (!isset($signatures[$key])) {
                    $signatures[$key] = true;
                    $cycles[] = $cycle;
                }
            }
            array_pop($stack);
            $state[$module] = 2;
        };

        foreach (array_keys($manifests) as $module) {
            if (($state[$module] ?? 0) === 0) {
                $visit($module);
            }
        }

        return $cycles;
    }

    private function matchesConstraint(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        if ($constraint[0] === '^') {
            $required = substr($constraint, 1);
            $requiredParts = array_map('intval', explode('.', $required));
            $actualParts = array_map('intval', explode('.', $version));
            if (($actualParts[0] ?? 0) !== ($requiredParts[0] ?? 0)) {
                return false;
            }
            return version_compare($version, $required, '>=');
        }
        if ($constraint[0] === '~') {
            $required = substr($constraint, 1);
            $requiredParts = array_map('intval', explode('.', $required));
            $actualParts = array_map('intval', explode('.', $version));
            if (($actualParts[0] ?? 0) !== ($requiredParts[0] ?? 0)
                || ($actualParts[1] ?? 0) !== ($requiredParts[1] ?? 0)) {
                return false;
            }
            return version_compare($version, $required, '>=');
        }

        return version_compare($version, ltrim($constraint, '='), '=');
    }

    /**
     * @param list<string> $cycle
     */
    private function cycleSignature(array $cycle): string
    {
        array_pop($cycle);
        $rotations = [];
        foreach ($cycle as $offset => $_) {
            $rotated = array_merge(array_slice($cycle, $offset), array_slice($cycle, 0, $offset));
            $rotations[] = implode('>', $rotated);
        }
        sort($rotations);
        return $rotations[0] ?? '';
    }
}
