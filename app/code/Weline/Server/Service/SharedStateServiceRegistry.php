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

    public function tryWithRoleLock(string $role, callable $callback, mixed $fallback = null): mixed
    {
        $role = $this->normalizeRole($role);
        $lockFile = $this->getRoleLockFile($role);
        $dir = \dirname($lockFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $fp = @\fopen($lockFile, 'c');
        if ($fp === false) {
            return $fallback;
        }

        try {
            if (!\flock($fp, LOCK_EX | LOCK_NB)) {
                return $fallback;
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

        ServerInstanceManager::updateJsonFileAtomically($file, static function (array $data) use ($role, $record): array {
            $services = \is_array($data['services'] ?? null) ? $data['services'] : [];
            $services[$role] = $record;
            $data['services'] = $services;
            $data['updated_at'] = \date('c');

            return $data;
        });
    }

    /**
     * @param callable(array<string, mixed>):array<string, mixed> $updater
     * @return array<string, mixed>
     */
    public function updateRecord(string $role, callable $updater): array
    {
        $role = $this->normalizeRole($role);
        $file = $this->getRegistryFile();
        $updatedRecord = [];

        ServerInstanceManager::updateJsonFileAtomically($file, static function (array $data) use ($role, $updater, &$updatedRecord): array {
            $services = \is_array($data['services'] ?? null) ? $data['services'] : [];
            $record = \is_array($services[$role] ?? null) ? $services[$role] : [];
            $nextRecord = $updater($record);
            $updatedRecord = \is_array($nextRecord) ? $nextRecord : [];
            $services[$role] = $updatedRecord;
            $data['services'] = $services;
            $data['updated_at'] = \date('c');

            return $data;
        });

        return $updatedRecord;
    }

    public function removeRecord(string $role): void
    {
        $role = $this->normalizeRole($role);
        $file = $this->getRegistryFile();

        ServerInstanceManager::updateJsonFileAtomically($file, static function (array $data) use ($role): array {
            $services = \is_array($data['services'] ?? null) ? $data['services'] : [];
            unset($services[$role]);
            $data['services'] = $services;
            $data['updated_at'] = \date('c');

            return $data;
        });
    }

    public function touchConsumer(string $role, string $instanceName): void
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return;
        }

        $this->upsertConsumer($role, $instanceName, [
            'consumer_code' => $instanceName,
            'owner_type' => 'instance',
            'last_ensured_at' => \date('c'),
        ]);
    }

    public function releaseConsumer(string $role, string $instanceName): void
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return;
        }

        $this->removeConsumer($role, $instanceName);
    }

    /**
     * @param array<string, mixed> $consumer
     * @return array<string, mixed>
     */
    public function upsertConsumer(string $role, string $consumerCode, array $consumer = []): array
    {
        $role = $this->normalizeRole($role);
        $consumerCode = \trim($consumerCode);
        if ($consumerCode === '') {
            return $this->getRecord($role);
        }

        $now = \date('c');

        return $this->updateRecord($role, static function (array $record) use ($consumerCode, $consumer, $now): array {
            $consumers = self::normalizeConsumersArray($record['consumers'] ?? []);
            $existing = \is_array($consumers[$consumerCode] ?? null) ? $consumers[$consumerCode] : [];

            $payload = \array_merge($existing, $consumer);
            $payload['consumer_code'] = $consumerCode;
            $payload['owner_type'] = \trim((string) ($payload['owner_type'] ?? 'instance')) ?: 'instance';
            $payload['last_seen_at'] = (string) ($payload['last_seen_at'] ?? $payload['last_ensured_at'] ?? $now);

            if (!\array_key_exists('lease_expires_at', $payload)) {
                $payload['lease_expires_at'] = null;
            }

            $consumers[$consumerCode] = $payload;
            $record['consumers'] = $consumers;
            $record['last_ensured_by_instance'] = $consumerCode;
            $record['last_ensured_at'] = $now;
            unset($record['shutdown_due_at']);
            unset($record['shutdown_requested_at']);

            return $record;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function removeConsumer(string $role, string $consumerCode): array
    {
        $role = $this->normalizeRole($role);
        $consumerCode = \trim($consumerCode);
        if ($consumerCode === '') {
            return $this->getRecord($role);
        }

        return $this->updateRecord($role, static function (array $record) use ($consumerCode): array {
            $consumers = self::normalizeConsumersArray($record['consumers'] ?? []);
            unset($consumers[$consumerCode]);
            $record['consumers'] = $consumers;

            return $record;
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getConsumers(string $role): array
    {
        $record = $this->getRecord($role);

        return self::normalizeConsumersArray($record['consumers'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function setShutdownDueAt(string $role, ?string $dueAt): array
    {
        $role = $this->normalizeRole($role);

        return $this->updateRecord($role, static function (array $record) use ($dueAt): array {
            if ($dueAt === null || \trim($dueAt) === '') {
                unset($record['shutdown_due_at']);
                unset($record['shutdown_requested_at']);
            } else {
                $record['shutdown_due_at'] = $dueAt;
                $record['shutdown_requested_at'] = \date('c');
            }

            return $record;
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

    /**
     * @param mixed $consumers
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeConsumersArray(mixed $consumers): array
    {
        if (!\is_array($consumers)) {
            return [];
        }

        $normalized = [];
        foreach ($consumers as $consumerCode => $consumer) {
            $code = \trim((string) $consumerCode);
            if ($code === '') {
                continue;
            }

            if (!\is_array($consumer)) {
                $consumer = [];
            }

            $normalized[$code] = \array_merge(
                [
                    'consumer_code' => $code,
                    'owner_type' => 'instance',
                    'last_seen_at' => (string) ($consumer['last_ensured_at'] ?? \date('c')),
                    'lease_expires_at' => null,
                ],
                $consumer,
            );

            $normalized[$code]['consumer_code'] = $code;
            $normalized[$code]['owner_type'] = \trim((string) ($normalized[$code]['owner_type'] ?? 'instance')) ?: 'instance';
        }

        return $normalized;
    }
}
