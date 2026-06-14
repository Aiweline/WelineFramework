<?php

declare(strict_types=1);

/**
 * 确保 PostgreSQL 数据目录在 extend/server/pgsql/data 并已初始化、运行。
 * Linux: install.sh 负责 init；本类在 run.php 中用于 Windows（及 Linux 冷启动/reboot 后补齐启动）。
 */
final class EnsurePgsqlData
{
    private string $projectRoot;
    private string $pgsqlDir;
    private string $dataDir;
    private bool $syncWelineEnv;

    public function __construct(string $projectRoot, bool $syncWelineEnv = true)
    {
        $this->projectRoot = $projectRoot;
        $this->pgsqlDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'pgsql';
        $this->dataDir = $this->pgsqlDir . DIRECTORY_SEPARATOR . 'data';
        $this->syncWelineEnv = $syncWelineEnv;
    }

    public function getDataDir(): string
    {
        return $this->dataDir;
    }

    public function hasProjectPgCtl(): bool
    {
        $binDir = $this->pgsqlDir . DIRECTORY_SEPARATOR . 'bin';
        $pgCtl = (DIRECTORY_SEPARATOR === '\\')
            ? $binDir . DIRECTORY_SEPARATOR . 'pg_ctl.exe'
            : $binDir . DIRECTORY_SEPARATOR . 'pg_ctl';
        return is_file($pgCtl) && is_executable($pgCtl);
    }

    public function hasData(): bool
    {
        return is_file($this->dataDir . DIRECTORY_SEPARATOR . 'PG_VERSION');
    }

    public function getDataMajorVersion(): ?string
    {
        $path = $this->dataDir . DIRECTORY_SEPARATOR . 'PG_VERSION';
        if (!is_file($path)) {
            return null;
        }
        $version = trim((string)@file_get_contents($path));
        if (preg_match('/^([0-9]+)/', $version, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    public function getConfiguredPort(): ?int
    {
        return $this->readConfPort();
    }

    /** 查找 pg_ctl 路径（Linux extend/bin 常为 /usr/bin 软链，不含 pg_ctl） */
    private function findPgCtl(): ?string
    {
        $binDir = $this->pgsqlDir . DIRECTORY_SEPARATOR . 'bin';
        $sep = DIRECTORY_SEPARATOR;
        $pgCtl = (DIRECTORY_SEPARATOR === '\\') ? $binDir . $sep . 'pg_ctl.exe' : $binDir . $sep . 'pg_ctl';
        if (is_file($pgCtl) && is_executable($pgCtl)) {
            return $pgCtl;
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }
        if (is_dir('/usr/lib/postgresql')) {
            foreach (glob('/usr/lib/postgresql/*/bin') ?: [] as $d) {
                $c = $d . $sep . 'pg_ctl';
                if (is_file($c) && is_executable($c)) {
                    return $c;
                }
            }
        }
        foreach (['/usr/pgsql-18/bin', '/usr/pgsql-16/bin', '/usr/pgsql-15/bin'] as $d) {
            $c = $d . $sep . 'pg_ctl';
            if (is_file($c) && is_executable($c)) {
                return $c;
            }
        }
        return null;
    }

    /** 查找 initdb 路径 */
    private function findInitdb(): ?string
    {
        $binDir = $this->pgsqlDir . DIRECTORY_SEPARATOR . 'bin';
        $sep = DIRECTORY_SEPARATOR;
        $initdb = (DIRECTORY_SEPARATOR === '\\') ? $binDir . $sep . 'initdb.exe' : $binDir . $sep . 'initdb';
        if (is_file($initdb) && is_executable($initdb)) {
            return $initdb;
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }
        $pgCtl = $this->findPgCtl();
        if ($pgCtl !== null) {
            $base = dirname($pgCtl);
            $i = $base . $sep . 'initdb';
            if (is_file($i) && is_executable($i)) {
                return $i;
            }
        }
        return null;
    }

    private function resolveEnvFilePath(): string
    {
        $configured = getenv('WELINE_ENV_FILE');
        $path = is_string($configured) && trim($configured) !== '' ? trim($configured) : 'weline.env';
        if (str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\')
        ) {
            return $path;
        }
        return $this->projectRoot . DIRECTORY_SEPARATOR . $path;
    }

    private function readEnvPort(): ?int
    {
        $fromProcess = getenv('DB_PORT');
        if (is_string($fromProcess) && preg_match('/^[0-9]+$/', trim($fromProcess)) === 1) {
            $port = (int) trim($fromProcess);
            if ($this->isValidPort($port)) {
                return $port;
            }
        }

        return $this->readEnvFilePort();
    }

    private function readEnvFilePort(): ?int
    {
        $envFile = $this->resolveEnvFilePath();
        if (!is_file($envFile)) {
            return null;
        }
        $lines = @file($envFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }
        foreach ($lines as $line) {
            if (preg_match('/^\s*DB_PORT\s*=\s*([0-9]+)\s*$/', $line, $m) === 1) {
                $port = (int) $m[1];
                return $this->isValidPort($port) ? $port : null;
            }
        }
        return null;
    }

    private function findPostgresBinary(?string $pgCtl = null): ?string
    {
        $sep = DIRECTORY_SEPARATOR;
        $binDir = $pgCtl !== null ? dirname($pgCtl) : $this->pgsqlDir . $sep . 'bin';
        $postgres = (DIRECTORY_SEPARATOR === '\\') ? $binDir . $sep . 'postgres.exe' : $binDir . $sep . 'postgres';
        return is_file($postgres) && is_executable($postgres) ? $postgres : null;
    }

    private function readBinaryMajorVersion(?string $pgCtl = null): ?string
    {
        $postgres = $this->findPostgresBinary($pgCtl);
        if ($postgres === null) {
            return null;
        }
        $cmd = escapeshellarg($postgres) . ' --version 2>&1';
        $out = [];
        @exec($cmd, $out, $code);
        if ($code !== 0) {
            return null;
        }
        $line = implode(' ', $out);
        if (preg_match('/\b([0-9]+)(?:\.[0-9]+)?\b/', $line, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private function buildPathEnv(string $pgBindir): string
    {
        $currentPath = getenv('PATH') ?: '';
        if (DIRECTORY_SEPARATOR !== '\\') {
            return $pgBindir . ':' . ($currentPath !== '' ? $currentPath : '/usr/bin:/bin');
        }

        $parts = [$pgBindir];
        if ($currentPath !== '') {
            $parts[] = $currentPath;
        }
        $systemRoot = getenv('SystemRoot') ?: getenv('WINDIR') ?: 'C:\\Windows';
        $parts[] = $systemRoot . '\\System32';
        $parts[] = $systemRoot;
        return implode(';', array_unique(array_filter($parts, static fn(string $path): bool => $path !== '')));
    }

    private function commandWithPath(string $binary, string $pathEnv, string $args): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $safePath = str_replace('"', '', $pathEnv);
            return 'set "PATH=' . $safePath . '" && ' . escapeshellarg($binary) . $args;
        }
        return 'env PATH=' . escapeshellarg($pathEnv) . ' ' . escapeshellarg($binary) . $args;
    }

    private function readConfPort(): ?int
    {
        $conf = $this->dataDir . DIRECTORY_SEPARATOR . 'postgresql.conf';
        if (!is_file($conf)) {
            return null;
        }
        $lines = @file($conf, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }
        foreach ($lines as $line) {
            if (preg_match('/^\s*port\s*=\s*([0-9]+)\b/', $line, $m) === 1) {
                $port = (int) $m[1];
                return $this->isValidPort($port) ? $port : null;
            }
        }
        return null;
    }

    private function isValidPort(int $port): bool
    {
        return $port > 0 && $port <= 65535;
    }

    private function isPortInUse(int $port): bool
    {
        foreach (['127.0.0.1', '::1'] as $host) {
            $target = str_contains($host, ':') ? "tcp://[{$host}]:{$port}" : "tcp://{$host}:{$port}";
            $errno = 0;
            $errstr = '';
            $conn = @stream_socket_client($target, $errno, $errstr, 0.2);
            if (is_resource($conn)) {
                fclose($conn);
                return true;
            }
        }
        return false;
    }

    private function findAvailablePort(int $preferred): int
    {
        $port = $this->isValidPort($preferred) ? $preferred : 5432;
        while ($this->isPortInUse($port)) {
            $port++;
            if ($port > 65535) {
                return 5432;
            }
        }
        return $port;
    }

    private function resolveDesiredPort(?int $preferredPort = null): int
    {
        if ($preferredPort !== null && $this->isValidPort($preferredPort)) {
            return $preferredPort;
        }
        return $this->readEnvPort() ?? $this->readConfPort() ?? 5432;
    }

    private function syncPortConfig(int $port): void
    {
        $this->writePgPortConf($port);
        if ($this->syncWelineEnv) {
            $this->writeEnvPort($port);
        }
        putenv('DB_PORT=' . $port);
    }

    private function writePgPortConf(int $port): void
    {
        $conf = $this->dataDir . DIRECTORY_SEPARATOR . 'postgresql.conf';
        if (!is_file($conf)) {
            return;
        }
        $content = @file_get_contents($conf);
        if ($content === false) {
            return;
        }
        $newLine = 'port = ' . $port;
        if (preg_match('/^\s*#?\s*port\s*=\s*[0-9]+\b/m', $content) === 1) {
            $content = preg_replace('/^\s*#?\s*port\s*=\s*[0-9]+\b/m', $newLine, $content, 1) ?? $content;
        } else {
            $content = rtrim($content) . PHP_EOL . $newLine . PHP_EOL;
        }
        @file_put_contents($conf, $content);
    }

    private function writeEnvPort(int $port): void
    {
        $envFile = $this->resolveEnvFilePath();
        $existingPort = $this->readEnvFilePort();
        if ($existingPort === $port) {
            return;
        }
        $dir = dirname($envFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $content = is_file($envFile) ? @file_get_contents($envFile) : '';
        if ($content === false) {
            return;
        }
        $line = 'DB_PORT=' . $port;
        if (preg_match('/^\s*DB_PORT\s*=.*$/m', $content) === 1) {
            $content = preg_replace('/^\s*DB_PORT\s*=.*$/m', $line, $content, 1) ?? $content;
        } else {
            $content = rtrim($content) . ($content === '' ? '' : PHP_EOL) . $line . PHP_EOL;
        }
        @file_put_contents($envFile, $content);
        echo "  PostgreSQL port synced to " . basename($envFile) . ": {$port}\n";
    }

    /**
     * 若 extend/server/pgsql/data 已初始化，确保集群正在运行。
     * 以当前用户运行，无需 postgres 系统用户或 sudo。
     */
    public function ensure(?int $preferredPort = null): bool
    {
        $pgVersion = $this->dataDir . DIRECTORY_SEPARATOR . 'PG_VERSION';
        if (!is_file($pgVersion)) {
            $initdb = $this->findInitdb();
            if ($initdb === null) {
                return true; // 未初始化且无 initdb，跳过（或由 install.sh 处理）
            }
            $desiredPort = $this->resolveDesiredPort($preferredPort);
            $port = $this->findAvailablePort($desiredPort);
            if ($port !== $desiredPort) {
                echo "  PostgreSQL port {$desiredPort} is in use; using project port {$port}.\n";
            }
            echo "Step 5a: Initializing PostgreSQL data at {$this->dataDir}...\n";
            if (!is_dir($this->dataDir)) {
                @mkdir($this->dataDir, 0755, true);
            }
            $pgBindir = dirname($initdb);
            $pathEnv = $this->buildPathEnv($pgBindir);
            $cmd = $this->commandWithPath(
                $initdb,
                $pathEnv,
                ' -D ' . escapeshellarg($this->dataDir) . ' -E UTF8 -U postgres'
            );
            $out = [];
            exec($cmd . ' 2>&1', $out, $code);
            if ($code !== 0) {
                echo "  initdb failed: " . implode("\n", $out) . "\n";
                return false;
            }
            $this->syncPortConfig($port);
        }

        $pgCtl = $this->findPgCtl();
        if ($pgCtl === null) {
            echo "  PostgreSQL data exists but pg_ctl was not found. Install/link the matching PostgreSQL major version before continuing.\n";
            return false;
        }

        $dataMajor = $this->getDataMajorVersion();
        $binaryMajor = $this->readBinaryMajorVersion($pgCtl);
        if ($dataMajor !== null && $binaryMajor !== null && $dataMajor !== $binaryMajor) {
            echo "  PostgreSQL major version mismatch: data={$dataMajor}, binary={$binaryMajor}. Use matching PostgreSQL or upgrade data manually.\n";
            return false;
        }

        $logFile = $this->dataDir . DIRECTORY_SEPARATOR . 'logfile';
        $pgBindir = dirname($pgCtl);
        $pathEnv = $this->buildPathEnv($pgBindir);
        $statusCmd = $this->commandWithPath(
            $pgCtl,
            $pathEnv,
            ' -D ' . escapeshellarg($this->dataDir) . ' status 2>&1'
        );
        $statusOut = [];
        $statusCode = -1;
        exec($statusCmd, $statusOut, $statusCode);
        $statusStr = implode(' ', $statusOut);
        if ($statusCode === 0 || strpos($statusStr, 'running') !== false) {
            $port = $this->readConfPort();
            if ($port !== null) {
                if ($this->syncWelineEnv) {
                    $this->writeEnvPort($port);
                }
                putenv('DB_PORT=' . $port);
            }
            return true;
        }

        $desiredPort = $this->resolveDesiredPort($preferredPort);
        $port = $this->findAvailablePort($desiredPort);
        if ($port !== $desiredPort) {
            echo "  PostgreSQL port {$desiredPort} is in use; using project port {$port}.\n";
        }
        $this->syncPortConfig($port);

        echo "Step 5a: Starting PostgreSQL at {$this->dataDir}...\n";
        $socketArgs = PHP_OS_FAMILY === 'Linux' ? '-k ' . $this->dataDir . ' -p ' . $port : '-p ' . $port;
        $socketOpt = ' -o ' . escapeshellarg($socketArgs);
        $startCmd = $this->commandWithPath(
            $pgCtl,
            $pathEnv,
            ' -D ' . escapeshellarg($this->dataDir)
                . ' -l ' . escapeshellarg($logFile) . ' start' . $socketOpt
        );
        $startCode = -1;
        if (function_exists('passthru')) {
            passthru($startCmd . ' 2>&1', $startCode);
        } else {
            exec($startCmd . ' 2>&1', $startOut, $startCode);
            if ($startCode !== 0) {
                echo "  pg_ctl start failed: " . implode("\n", $startOut) . "\n";
            }
        }
        if ($startCode !== 0) {
            echo "  pg_ctl start 失败，请检查下方日志：\n";
            if (is_file($logFile)) {
                $log = @file_get_contents($logFile);
                echo "  --- " . $logFile . " ---\n";
                echo $log !== false ? $log : "(无法读取)\n";
            }
            echo "  常见原因：端口 {$port} 已被占用，可执行 ss -tlnp | grep {$port} 或 lsof -i :{$port} 检查。\n";
            return false;
        }
        sleep(1); // 等待 postgres 就绪
        return true;
    }
}
