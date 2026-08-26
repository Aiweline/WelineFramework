<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration\Service;

use Weline\Framework\Database\Migration\MigrationCloneHandle;

/**
 * 一次性隔离 DB clone：创建 → 登记 allowlist → 销毁（TEST-MIG-FOUNDATION-01）。
 *
 * - 默认 mode=schema（schema-only），避免在共享源库上抢 TEMPLATE 锁；
 * - 源库名若命中 denylist 只作只读 dump 源，绝不把源库当 apply 目标；
 * - destroy 仅允许 mig_clone_/weline_mig_/test_mig_ 前缀。
 */
final class MigrationCloneService
{
    public const MODE_SCHEMA = 'schema';
    public const MODE_FULL = 'full';

    public function __construct(
        private readonly MigrationCloneRegistry $registry,
        private readonly DatabaseFingerprintGuard $fingerprintGuard,
    ) {
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string} $source
     */
    public function create(
        array $source,
        string $mode = self::MODE_SCHEMA,
        string $purpose = 'foundation',
        string $owner = 'codex',
    ): MigrationCloneHandle {
        if (!\in_array($mode, [self::MODE_SCHEMA, self::MODE_FULL], true)) {
            throw new \InvalidArgumentException('migration_clone_mode_invalid:' . $mode);
        }
        $sourceDb = \strtolower(\trim((string)($source['database'] ?? '')));
        if ($sourceDb === '') {
            throw new \InvalidArgumentException('migration_clone_source_database_missing');
        }

        $purpose = \preg_replace('/[^a-z0-9_]/', '', \strtolower($purpose)) ?: 'foundation';
        $cloneId = 'mig_' . $purpose . '_' . \gmdate('YmdHis') . '_' . \substr(\bin2hex(\random_bytes(3)), 0, 6);
        $database = 'mig_clone_' . $purpose . '_' . \gmdate('YmdHis') . '_' . \substr(\bin2hex(\random_bytes(2)), 0, 4);

        $cloneConfig = [
            'type' => (string)($source['type'] ?? 'pgsql'),
            'hostname' => (string)($source['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($source['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string)($source['username'] ?? 'weline'),
        ];
        // 创建前只做命名/denylist 校验；allowlist 在 register 之后才对 apply 生效。
        (new DatabaseFingerprintGuard())->assertIsolatedDatabase($cloneConfig);

        $password = (string)($source['password'] ?? '');
        $createCmd = \sprintf(
            'createdb ... %s && pg_dump ... %s %s | psql ... %s',
            $database,
            $sourceDb,
            $mode === self::MODE_SCHEMA ? '--schema-only' : '--data-and-schema',
            $database,
        );
        $destroyCmd = \sprintf('dropdb --if-exists %s', $database);

        try {
            $this->runCreate($source, $cloneConfig, $mode, $password);
        } catch (\Throwable $e) {
            $this->safeDrop($cloneConfig, $password);
            throw $e;
        }

        $fp = $this->fingerprintGuard->fingerprint($cloneConfig);
        $handle = new MigrationCloneHandle(
            cloneId: $cloneId,
            database: $database,
            fingerprint: $fp,
            mode: $mode,
            sourceDatabase: $sourceDb,
            createdAt: \gmdate('c'),
            owner: $owner,
            config: $cloneConfig,
            createCommand: $createCmd,
            destroyCommand: $destroyCmd,
        );
        $this->registry->register($handle);

        return $handle;
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string} $source
     */
    public function destroy(string $database, array $source = []): void
    {
        $database = \strtolower(\trim($database));
        if ($database === '' || !\preg_match('/^(mig_clone_|weline_mig_|test_mig_)/', $database)) {
            throw new \RuntimeException('migration_clone_destroy_refused:' . $database);
        }
        if ($this->fingerprintGuard->isDeniedDatabaseName($database)) {
            throw new \RuntimeException('migration_clone_destroy_denied_name:' . $database);
        }

        $handle = $this->registry->get($database);
        $config = $handle?->config ?? [
            'type' => (string)($source['type'] ?? 'pgsql'),
            'hostname' => (string)($source['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($source['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string)($source['username'] ?? 'weline'),
        ];
        $config['database'] = $database;
        $password = (string)($source['password'] ?? '');
        // 显式销毁必须是强一致操作：先确认 PostgreSQL 已实际删除，再移除登记簿。
        // 测试 WLS 即使已经停止，也可能残留一个 idle 连接，因此这里允许 dropdb
        // 主动断开目标克隆库连接；任何失败都必须向调用方透出，禁止“假成功”。
        $this->safeDrop($config, $password, true);
        $this->registry->forget($database);
    }

    /**
     * @return list<MigrationCloneHandle>
     */
    public function list(): array
    {
        return $this->registry->list();
    }

    /**
     * 带登记簿 allowlist 的守卫（apply 路径应使用）。
     */
    public function guardedFingerprint(): DatabaseFingerprintGuard
    {
        return new DatabaseFingerprintGuard(
            allowlistFingerprints: $this->registry->allowlistFingerprints(),
        );
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string} $source
     * @param array{hostname:string,hostport:string,database:string,username:string,type:string} $cloneConfig
     */
    private function runCreate(array $source, array $cloneConfig, string $mode, string $password): void
    {
        $env = $this->pgEnv($password);
        $host = $cloneConfig['hostname'];
        $port = (string)$cloneConfig['hostport'];
        $user = $cloneConfig['username'];
        $database = $cloneConfig['database'];
        $sourceDb = (string)$source['database'];

        $this->execOrFail(
            ['createdb', '-h', $host, '-p', $port, '-U', $user, $database],
            $env,
            'createdb_failed',
        );

        // Full clones can be hundreds of megabytes. Let pg_dump stream directly
        // into a temporary file instead of retaining the dump in PHP memory.
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'mig_dump_');
        if ($tmpFile === false) {
            throw new \RuntimeException('psql_restore_failed:tempnam');
        }
        try {
            $dumpArgs = [
                'pg_dump',
                '-h', $host,
                '-p', $port,
                '-U', $user,
                '-d', $sourceDb,
                '--no-owner',
                '--no-acl',
                '--file', $tmpFile,
            ];
            if ($mode === self::MODE_SCHEMA) {
                $dumpArgs[] = '--schema-only';
            }
            $this->execOrFail($dumpArgs, $env, 'pg_dump_failed');
            $this->execOrFail(
                [
                    'psql',
                    '-h', $host,
                    '-p', $port,
                    '-U', $user,
                    '-d', $database,
                    '-v', 'ON_ERROR_STOP=1',
                    '-f', $tmpFile,
                ],
                $env,
                'psql_restore_failed',
            );
        } finally {
            @\unlink($tmpFile);
        }
    }

    /**
     * @param array{hostname?:string,hostport?:string,database?:string,username?:string} $config
     */
    private function safeDrop(array $config, string $password, bool $strict = false): void
    {
        $database = (string)($config['database'] ?? '');
        if ($database === '' || !\preg_match('/^(mig_clone_|weline_mig_|test_mig_)/', $database)) {
            return;
        }
        $env = $this->pgEnv($password);
        try {
            $this->execOrFail(
                [
                    'dropdb',
                    '-h', (string)($config['hostname'] ?? '127.0.0.1'),
                    '-p', (string)($config['hostport'] ?? '5432'),
                    '-U', (string)($config['username'] ?? 'weline'),
                    '--force',
                    '--if-exists',
                    $database,
                ],
                $env,
                'dropdb_failed',
            );
        } catch (\Throwable $e) {
            // create 失败后的补偿清理保持 best-effort；显式 destroy 则必须失败闭合，
            // 让调用方保留登记信息并人工处理，不能报告一个并未发生的销毁。
            if ($strict) {
                throw new \RuntimeException('migration_clone_destroy_failed:' . $database, 0, $e);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function pgEnv(string $password): array
    {
        $env = $_ENV + $_SERVER;
        $out = [];
        foreach ($env as $k => $v) {
            if (\is_string($k) && \is_scalar($v)) {
                $out[$k] = (string)$v;
            }
        }
        if ($password !== '') {
            $out['PGPASSWORD'] = $password;
        }

        return $out;
    }

    /**
     * @param list<string> $cmd
     * @param array<string, string> $env
     */
    private function execOrFail(array $cmd, array $env, string $errorCode): void
    {
        $this->execCapture($cmd, $env, $errorCode);
    }

    /**
     * @param list<string> $cmd
     * @param array<string, string> $env
     */
    private function execCapture(array $cmd, array $env, string $errorCode): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!\is_resource($proc)) {
            throw new \RuntimeException($errorCode . ':proc_open');
        }
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]) ?: '';
        $stderr = \stream_get_contents($pipes[2]) ?: '';
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($proc);
        if ($code !== 0) {
            throw new \RuntimeException($errorCode . ':' . \trim($stderr !== '' ? $stderr : $stdout));
        }

        return $stdout;
    }

    /**
     * @param list<string> $cmd
     * @param array<string, string> $env
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function execWithStdin(array $cmd, array $env, string $stdin, string $errorCode): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!\is_resource($proc)) {
            throw new \RuntimeException($errorCode . ':proc_open');
        }

        // Pump stdin while draining stdout/stderr to avoid pipe-buffer deadlock
        // (classic when dump size > PIPE_BUF and child emits notices).
        foreach ([0, 1, 2] as $fd) {
            \stream_set_blocking($pipes[$fd], false);
        }

        $offset = 0;
        $length = \strlen($stdin);
        $stdout = '';
        $stderr = '';
        $stdinOpen = true;

        while ($stdinOpen || isset($pipes[1]) || isset($pipes[2])) {
            $read = [];
            if (isset($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (isset($pipes[2])) {
                $read[] = $pipes[2];
            }
            $write = [];
            if ($stdinOpen) {
                $write[] = $pipes[0];
            }
            $except = null;
            if ($read === [] && $write === []) {
                break;
            }
            $selected = @\stream_select($read, $write, $except, 1);
            if ($selected === false) {
                break;
            }

            if ($stdinOpen && $write !== [] && \in_array($pipes[0], $write, true)) {
                if ($offset >= $length) {
                    \fclose($pipes[0]);
                    $stdinOpen = false;
                } else {
                    $chunk = \substr($stdin, $offset, 65536);
                    $written = \fwrite($pipes[0], $chunk);
                    if ($written === false) {
                        \fclose($pipes[0]);
                        $stdinOpen = false;
                    } else {
                        $offset += $written;
                        if ($offset >= $length) {
                            \fclose($pipes[0]);
                            $stdinOpen = false;
                        }
                    }
                }
            }

            foreach ($read as $stream) {
                $data = \fread($stream, 8192);
                if ($data === false || $data === '') {
                    if (\feof($stream)) {
                        if ($stream === $pipes[1]) {
                            \fclose($pipes[1]);
                            unset($pipes[1]);
                        } elseif ($stream === $pipes[2]) {
                            \fclose($pipes[2]);
                            unset($pipes[2]);
                        }
                    }
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $stdout .= $data;
                } elseif ($stream === $pipes[2]) {
                    $stderr .= $data;
                }
            }

            $status = \proc_get_status($proc);
            if (!$status['running'] && !$stdinOpen) {
                // Final drain after child exit.
                if (isset($pipes[1])) {
                    $stdout .= \stream_get_contents($pipes[1]) ?: '';
                    \fclose($pipes[1]);
                    unset($pipes[1]);
                }
                if (isset($pipes[2])) {
                    $stderr .= \stream_get_contents($pipes[2]) ?: '';
                    \fclose($pipes[2]);
                    unset($pipes[2]);
                }
            }
        }

        if ($stdinOpen && \is_resource($pipes[0])) {
            \fclose($pipes[0]);
        }
        $code = \proc_close($proc);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
