<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration\Service;

/**
 * 数据库连接指纹与隔离守卫（TEST-MIG-FOUNDATION-01）。
 *
 * 默认 fail-closed：未 allowlist 的指纹一律拒绝写入路径。
 * 共享/canonical/prod 库名命中 denylist 时，即使误配 allowlist 也硬拒绝。
 */
final class DatabaseFingerprintGuard
{
    /** @var list<string> */
    private const DENY_DB_NAMES = [
        'weline',
        'production',
        'prod',
        'canonical',
        'main',
        'live',
    ];

    /**
     * @param list<string> $allowlistFingerprints
     * @param list<string> $denylistFingerprints
     */
    public function __construct(
        private readonly array $allowlistFingerprints = [],
        private readonly array $denylistFingerprints = [],
    ) {
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,type?:string} $config
     */
    public function fingerprint(array $config): string
    {
        $payload = [
            'type' => (string)($config['type'] ?? 'pgsql'),
            'hostname' => (string)($config['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($config['hostport'] ?? '5432'),
            'database' => (string)($config['database'] ?? ''),
            'username' => (string)($config['username'] ?? ''),
        ];
        \ksort($payload);

        return \hash('sha256', \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,type?:string} $config
     */
    public function assertIsolatedDatabase(array $config): string
    {
        $database = \strtolower(\trim((string)($config['database'] ?? '')));
        $fp = $this->fingerprint($config);

        if ($database === '' || \in_array($database, self::DENY_DB_NAMES, true)) {
            throw new \RuntimeException(
                'migration_db_denied: shared/canonical/prod database fingerprint rejected before write ('
                . $database . ')'
            );
        }

        // 隔离 clone 必须以明确前缀命名，禁止凭库名猜环境
        if (!\preg_match('/^(mig_clone_|weline_mig_|test_mig_)/', $database)) {
            throw new \RuntimeException(
                'migration_db_denied: database name must prove isolation (mig_clone_*|weline_mig_*|test_mig_*), got '
                . $database
            );
        }

        if ($this->denylistFingerprints !== [] && \in_array($fp, $this->denylistFingerprints, true)) {
            throw new \RuntimeException('migration_db_denied: fingerprint on denylist');
        }

        if ($this->allowlistFingerprints !== [] && !\in_array($fp, $this->allowlistFingerprints, true)) {
            throw new \RuntimeException('migration_db_denied: fingerprint not on test allowlist');
        }

        return $fp;
    }

    public function isDeniedDatabaseName(string $database): bool
    {
        $database = \strtolower(\trim($database));

        return $database === '' || \in_array($database, self::DENY_DB_NAMES, true);
    }
}
