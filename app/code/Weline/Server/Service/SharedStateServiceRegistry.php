<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;

class SharedStateServiceRegistry
{
    private const REGISTRY_FILE = 'server' . DIRECTORY_SEPARATOR . 'shared-services' . DIRECTORY_SEPARATOR . 'registry.json';
    private const LOCK_DIR = 'server' . DIRECTORY_SEPARATOR . 'shared-services' . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR;

    public function withRoleLock(string $role, callable $callback): mixed
    {
        $role = $this->normalizeRole($role);
        $lockFile = $this->getRoleLockFile($role);
        $dir = \dirname($lockFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $fp = @\fopen($lockFile, 'c');
        if ($fp === false) {
            throw new \RuntimeException("Unable to open shared-service lock file: {$lockFile}");
        }

        try {
            if (!\flock($fp, LOCK_EX)) {
                throw new \RuntimeException("Unable to acquire shared-service lock for role {$role}");
            }

            return $callback();
        } finally {
            @\flock($fp, LOCK_UN);
            @\fclose($fp);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getRecord(string $role): array
    {
        $role = $this->normalizeRole($role);
        $data = $this->readData();
        $record = $data['services'][$role] ?? [];

        return \is_array($record) ? $record : [];
    }

    /**
     * @param array<string, mixed> $record
     */
    public function putRecord(string $role, array $record): void
    {
        $role = $this->normalizeRole($role);
        $file = $this->getRegistryFile();

        ServerInstanceManager::atomicUpdateJsonStatic($file, static function (array $data) use ($role, $record): array {
            $services = \is_array($data['services'] ?? null) ? $data['services'] : [];
            $services[$role] = $record;
            $data['services'] = $services;
            $data['updated_at'] = \date('c');

            return $data;
        });
    }

    public function removeRecord(string $role): void
    {
        $role = $this->normalizeRole($role);
        $file = $this->getRegistryFile();

        ServerInstanceManager::atomicUpdateJsonStatic($file, static function (array $data) use ($role): array {
            $services = \is_array($data['services'] ?? null) ? $data['services'] : [];
            unset($services[$role]);
            $data['services'] = $services;
            $data['updated_at'] = \date('c');

            return $data;
        });
    }

    public function touchConsumer(string $role, string $instanceName): void
    {
        $role = $this->normalizeRole($role);
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return;
        }

        $file = $this->getRegistryFile();
        ServerInstanceManager::atomicUpdateJsonStatic($file, static function (array $data) use ($role, $instanceName): array {
            $services = \is_array($data['services'] ?? null) ? $data['services'] : [];
            $record = \is_array($services[$role] ?? null) ? $services[$role] : [];
            $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
            $consumers[$instanceName] = [
                'last_ensured_at' => \date('c'),
            ];
            $record['consumers'] = $consumers;
            $record['last_ensured_by_instance'] = $instanceName;
            $record['last_ensured_at'] = \date('c');
            $services[$role] = $record;
            $data['services'] = $services;
            $data['updated_at'] = \date('c');

            return $data;
        });
    }

    public function getRegistryFile(): string
    {
        return Env::VAR_DIR . self::REGISTRY_FILE;
    }

    /**
     * @return array{services: array<string, array<string, mixed>>}
     */
    private function readData(): array
    {
        $file = $this->getRegistryFile();
        if (!\is_file($file)) {
            return ['services' => []];
        }

        $raw = @\file_get_contents($file);
        if ($raw === false || $raw === '') {
            return ['services' => []];
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            return ['services' => []];
        }

        $services = \is_array($data['services'] ?? null) ? $data['services'] : [];

        return ['services' => $services];
    }

    private function getRoleLockFile(string $role): string
    {
        return Env::VAR_DIR . self::LOCK_DIR . $role . '.lock';
    }

    private function normalizeRole(string $role): string
    {
        $role = \trim($role);
        if ($role === '') {
            throw new \InvalidArgumentException('Shared-service role cannot be empty.');
        }

        return $role;
    }
}
