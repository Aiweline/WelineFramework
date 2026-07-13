<?php

declare(strict_types=1);

namespace Weline\Framework\Module\Manifest;

final class ModuleManifestReader
{
    /**
     * @return array<string, ModuleManifest>
     */
    public function readAll(string $modulesRoot, bool $allowLegacy = false): array
    {
        if (!is_dir($modulesRoot)) {
            throw new \InvalidArgumentException("Modules root does not exist: {$modulesRoot}");
        }

        $manifests = [];
        $iterator = new \DirectoryIterator($modulesRoot);
        foreach ($iterator as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $modulePath = $entry->getPathname();
            if (!is_file($modulePath . '/register.php') && !is_file($modulePath . '/etc/module.php')) {
                continue;
            }

            $manifest = $this->read($modulePath, $allowLegacy);
            if (isset($manifests[$manifest->name])) {
                throw new \RuntimeException("Duplicate module name {$manifest->name} in {$manifest->path}.");
            }
            $manifests[$manifest->name] = $manifest;
        }
        ksort($manifests);

        return $manifests;
    }

    public function read(string $modulePath, bool $allowLegacy = false): ModuleManifest
    {
        $manifestPath = rtrim($modulePath, '/\\') . '/etc/module.php';
        if (is_file($manifestPath)) {
            $data = require $manifestPath;
            if (!is_array($data)) {
                throw new \RuntimeException("Module manifest must return an array: {$manifestPath}");
            }

            return ModuleManifest::fromArray($data, $modulePath);
        }

        if (!$allowLegacy) {
            throw new \RuntimeException("Authoritative module manifest is missing: {$manifestPath}");
        }

        return $this->readLegacyRegister($modulePath);
    }

    private function readLegacyRegister(string $modulePath): ModuleManifest
    {
        $registerPath = rtrim($modulePath, '/\\') . '/register.php';
        $source = @file_get_contents($registerPath);
        if (!is_string($source) || $source === '') {
            throw new \RuntimeException("Legacy module registration is missing: {$registerPath}");
        }

        $callPosition = strpos($source, 'Register::register');
        if ($callPosition === false) {
            throw new \RuntimeException("Register::register call is missing: {$registerPath}");
        }
        $call = substr($source, $callPosition);
        preg_match_all('/([\'\"])((?:\\\\.|(?!\\1).)*)\\1/s', $call, $matches);
        $values = array_map(
            static fn(string $value): string => stripcslashes($value),
            $matches[2] ?? [],
        );

        $name = '';
        $version = '0.0.0';
        $requires = [];
        foreach ($values as $value) {
            if ($name === '' && preg_match('/^[A-Za-z][A-Za-z0-9]*_[A-Za-z0-9_]+$/', $value) === 1) {
                $name = $value;
                continue;
            }
            if ($name !== '' && $version === '0.0.0' && preg_match('/^\\d+\\.\\d+\\.\\d+(?:[-+][A-Za-z0-9.-]+)?$/', $value) === 1) {
                $version = $value;
                continue;
            }
            if ($name !== '' && $value !== $name && preg_match('/^[A-Za-z][A-Za-z0-9]*_[A-Za-z0-9_]+$/', $value) === 1) {
                $requires[$value] = '*';
            }
        }

        if ($name === '') {
            throw new \RuntimeException("Unable to parse module name from {$registerPath}");
        }

        return ModuleManifest::fromArray([
            'name' => $name,
            'version' => $version,
            'requires' => $requires,
            'optional' => [],
            'provides' => [],
        ], $modulePath, false);
    }
}
