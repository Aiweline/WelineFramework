<?php

declare(strict_types=1);

namespace Weline\Framework\Architecture;

use Weline\Framework\Module\Manifest\ModuleManifest;

final class ComposerMetadataValidator
{
    /**
     * @param array<string, ModuleManifest> $manifests
     * @return list<Finding>
     */
    public function validate(array $manifests, string $modulesRoot): array
    {
        $findings = [];
        $packageOwners = [];
        foreach ($manifests as $manifest) {
            $file = $manifest->path . '/composer.json';
            $relative = $this->relativePath($file, $modulesRoot);
            if (!is_file($file)) {
                $findings[] = new Finding('composer.missing', "{$manifest->name} must define composer.json.", $relative);
                continue;
            }

            try {
                $composer = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $exception) {
                $findings[] = new Finding('composer.invalid', $exception->getMessage(), $relative);
                continue;
            }
            if (!is_array($composer)) {
                $findings[] = new Finding('composer.invalid', 'composer.json must contain an object.', $relative);
                continue;
            }

            $expectedPackage = $this->packageForModule($manifest->name);
            $package = strtolower(trim((string)($composer['name'] ?? '')));
            if ($package !== $expectedPackage) {
                $findings[] = new Finding(
                    'composer.package_name',
                    "{$manifest->name} package must be {$expectedPackage}, got {$package}.",
                    $relative,
                );
            }
            if ($package !== '') {
                if (isset($packageOwners[$package])) {
                    $findings[] = new Finding(
                        'composer.duplicate_package',
                        "Composer package {$package} is shared by {$packageOwners[$package]} and {$manifest->name}.",
                        $relative,
                    );
                } else {
                    $packageOwners[$package] = $manifest->name;
                }
            }

            $requires = is_array($composer['require'] ?? null) ? $composer['require'] : [];
            if (($requires['php'] ?? null) !== '^8.4') {
                $findings[] = new Finding(
                    'composer.php_version',
                    "{$manifest->name} must require php ^8.4.",
                    $relative,
                );
            }
            foreach (array_keys($manifest->requires) as $dependency) {
                $dependencyPackage = $this->packageForModule($dependency);
                if (!array_key_exists($dependencyPackage, $requires)) {
                    $findings[] = new Finding(
                        'composer.missing_require',
                        "{$manifest->name} manifest requires {$dependency}, but Composer does not require {$dependencyPackage}.",
                        $relative,
                    );
                }
            }

            $suggests = is_array($composer['suggest'] ?? null) ? $composer['suggest'] : [];
            foreach (array_keys($manifest->optional) as $dependency) {
                $dependencyPackage = $this->packageForModule($dependency);
                if (!array_key_exists($dependencyPackage, $suggests)) {
                    $findings[] = new Finding(
                        'composer.missing_suggest',
                        "{$manifest->name} optional dependency {$dependency} must be Composer suggest {$dependencyPackage}.",
                        $relative,
                    );
                }
            }

            $modulePart = explode('_', $manifest->name, 2)[1] ?? '';
            $expectedNamespace = 'Weline\\' . $modulePart . '\\';
            $autoload = $composer['autoload']['psr-4'] ?? null;
            if (!is_array($autoload) || ($autoload[$expectedNamespace] ?? null) !== '') {
                $findings[] = new Finding(
                    'composer.autoload',
                    "{$manifest->name} must expose PSR-4 namespace {$expectedNamespace} from the module root.",
                    $relative,
                );
            }

            $declaredVersion = trim((string)($composer['version'] ?? ''));
            if ($declaredVersion !== '' && $declaredVersion !== $manifest->version) {
                $findings[] = new Finding(
                    'composer.version_mismatch',
                    "{$manifest->name} manifest version {$manifest->version} differs from Composer {$declaredVersion}.",
                    $relative,
                );
            }
        }

        return $findings;
    }

    public function packageForModule(string $module): string
    {
        [$vendor, $name] = array_pad(explode('_', $module, 2), 2, '');
        if ($module === 'Weline_Framework') {
            return 'weline/framework';
        }

        $name = (string)preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $name);
        $name = (string)preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name);
        return strtolower($vendor) . '/module-' . strtolower($name);
    }

    private function relativePath(string $path, string $modulesRoot): string
    {
        $root = rtrim(str_replace('\\', '/', realpath($modulesRoot) ?: $modulesRoot), '/');
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $root . '/') ? substr($normalized, strlen($root) + 1) : $normalized;
    }
}
