<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

class WlsDatabaseConnectionProbeService
{
    public const HEALTH_PROBE_PHRASE = 'CHECK_DB_HEALTH';

    /**
     * @param array<string, mixed> $config
     * @return array{success:bool,message:string,duration_ms:int,driver:string,checked_at:string}
     */
    public function probe(array $config): array
    {
        $startedAt = \microtime(true);
        $normalized = $this->normalizeConnectionConfig($config);
        $driver = (string)$normalized['type'];

        try {
            $missing = $this->missingRequiredFields($normalized);
            if ($missing !== []) {
                throw new \InvalidArgumentException((string)__('Database profile is incomplete: %{1}', [\implode(', ', $missing)]));
            }

            $driverStatus = $this->driverStatus($driver);
            if (!$driverStatus['ready']) {
                throw new \RuntimeException((string)__('%{1} is not available for this runtime.', [$driverStatus['extension']]));
            }

            $pdo = $this->openPdo($normalized);
            $statement = $pdo->query('SELECT 1');
            if ($statement === false) {
                throw new \RuntimeException((string)__('Database connection test failed.'));
            }
            $statement->fetchColumn();
            $pdo = null;

            return [
                'success' => true,
                'message' => (string)__('Database health probe passed.'),
                'duration_ms' => $this->durationMs($startedAt),
                'driver' => $driver,
                'checked_at' => \date('c'),
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $this->sanitizeConnectionError($throwable->getMessage(), $normalized),
                'duration_ms' => $this->durationMs($startedAt),
                'driver' => $driver,
                'checked_at' => \date('c'),
            ];
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConnectionConfig(array $config): array
    {
        $type = \strtolower(\trim((string)($config['type'] ?? $config['driver'] ?? '')));
        if ($type === '') {
            $type = \trim((string)($config['path'] ?? '')) !== '' ? 'sqlite' : 'mysql';
        }

        return [
            'type' => $type,
            'hostname' => \trim((string)($config['hostname'] ?? $config['host'] ?? '')),
            'hostport' => \trim((string)($config['hostport'] ?? $config['port'] ?? $this->defaultPort($type))),
            'database' => \trim((string)($config['database'] ?? $config['dbname'] ?? $config['name'] ?? '')),
            'path' => \trim((string)($config['path'] ?? '')),
            'username' => \trim((string)($config['username'] ?? $config['user'] ?? '')),
            'password' => (string)($config['password'] ?? ''),
            'charset' => \trim((string)($config['charset'] ?? ($type === 'mysql' ? 'utf8mb4' : ''))),
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<int, string>
     */
    private function missingRequiredFields(array $normalized): array
    {
        $type = (string)$normalized['type'];
        if ($type === 'sqlite') {
            return \trim((string)$normalized['path']) === '' ? ['path'] : [];
        }

        $missing = [];
        foreach (['hostname', 'database', 'username'] as $field) {
            if (\trim((string)($normalized[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @return array{ready: bool, extension: string}
     */
    private function driverStatus(string $type): array
    {
        $extension = match ($type) {
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            default => 'pdo',
        };

        $ready = \extension_loaded('pdo') && \extension_loaded($extension);
        if ($type !== 'mysql' && $type !== 'pgsql' && $type !== 'sqlite') {
            $ready = false;
        }

        return [
            'ready' => $ready,
            'extension' => $extension,
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function openPdo(array $normalized): \PDO
    {
        $type = (string)$normalized['type'];
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 3,
        ];

        if ($type === 'sqlite') {
            return new \PDO('sqlite:' . (string)$normalized['path'], null, null, $options);
        }

        if ($type === 'pgsql') {
            $dsn = \sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                (string)$normalized['hostname'],
                (string)$normalized['hostport'],
                (string)$normalized['database']
            );
            return new \PDO($dsn, (string)$normalized['username'], (string)$normalized['password'], $options);
        }

        $dsn = \sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            (string)$normalized['hostname'],
            (string)$normalized['hostport'],
            (string)$normalized['database'],
            (string)$normalized['charset']
        );

        return new \PDO($dsn, (string)$normalized['username'], (string)$normalized['password'], $options);
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function sanitizeConnectionError(string $message, array $normalized): string
    {
        $message = \trim($message);
        foreach (['password', 'username'] as $field) {
            $value = (string)($normalized[$field] ?? '');
            if ($value !== '') {
                $message = \str_replace($value, '[' . $field . ']', $message);
            }
        }

        if ($message === '') {
            $message = (string)__('Database connection test failed.');
        }

        return \mb_substr($message, 0, 220);
    }

    private function defaultPort(string $type): string
    {
        return match ($type) {
            'mysql' => '3306',
            'pgsql' => '5432',
            default => '',
        };
    }

    private function durationMs(float $startedAt): int
    {
        return (int)\max(0, \round((\microtime(true) - $startedAt) * 1000));
    }
}
