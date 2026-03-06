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

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
        $this->pgsqlDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'pgsql';
        $this->dataDir = $this->pgsqlDir . DIRECTORY_SEPARATOR . 'data';
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

    /**
     * 若 extend/server/pgsql/data 已初始化，确保集群正在运行。
     * 以当前用户运行，无需 postgres 系统用户或 sudo。
     */
    public function ensure(): bool
    {
        $pgVersion = $this->dataDir . DIRECTORY_SEPARATOR . 'PG_VERSION';
        if (!is_file($pgVersion)) {
            $initdb = $this->findInitdb();
            if ($initdb === null) {
                return true; // 未初始化且无 initdb，跳过（或由 install.sh 处理）
            }
            echo "Step 5a: Initializing PostgreSQL data at {$this->dataDir}...\n";
            if (!is_dir($this->dataDir)) {
                @mkdir($this->dataDir, 0755, true);
            }
            $pgBindir = dirname($initdb);
            $pathEnv = $pgBindir . ':' . (getenv('PATH') ?: '/usr/bin:/bin');
            $cmd = 'env PATH=' . escapeshellarg($pathEnv) . ' ' . escapeshellarg($initdb)
                . ' -D ' . escapeshellarg($this->dataDir) . ' -E UTF8 -U postgres';
            $out = [];
            exec($cmd . ' 2>&1', $out, $code);
            if ($code !== 0) {
                echo "  initdb failed: " . implode("\n", $out) . "\n";
                return false;
            }
        }

        $pgCtl = $this->findPgCtl();
        if ($pgCtl === null) {
            return true;
        }

        $logFile = $this->dataDir . DIRECTORY_SEPARATOR . 'logfile';
        $pgBindir = dirname($pgCtl);
        $pathEnv = $pgBindir . ':' . (getenv('PATH') ?: '/usr/bin:/bin');
        $statusCmd = 'env PATH=' . escapeshellarg($pathEnv) . ' ' . escapeshellarg($pgCtl)
            . ' -D ' . escapeshellarg($this->dataDir) . ' status 2>&1';
        $statusOut = [];
        exec($statusCmd, $statusOut);
        $statusStr = implode(' ', $statusOut);
        if (strpos($statusStr, 'running') !== false) {
            return true;
        }

        echo "Step 5a: Starting PostgreSQL at {$this->dataDir}...\n";
        $socketOpt = PHP_OS_FAMILY === 'Linux' ? ' -o ' . escapeshellarg('-k ' . $this->dataDir) : '';
        $startCmd = 'env PATH=' . escapeshellarg($pathEnv) . ' ' . escapeshellarg($pgCtl)
            . ' -D ' . escapeshellarg($this->dataDir)
            . ' -l ' . escapeshellarg($logFile) . ' start' . $socketOpt;
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
            echo "  常见原因：端口 5432 已被占用，可执行 ss -tlnp | grep 5432 或 lsof -i :5432 检查。\n";
            return false;
        }
        sleep(1); // 等待 postgres 就绪
        return true;
    }
}
