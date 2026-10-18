<?php

declare(strict_types=1);

/**
 * MySQL/MariaDB first-install: create project DB/user and write app/etc/env.php db.master.
 * Prefer Unix socket as root (Debian/MariaDB peer). Falls back to TCP root with DB_ROOT_PASSWORD.
 */
final class SetupMysqlDatabase
{
    private string $projectRoot;
    private array $env;

    public function __construct(string $projectRoot, array $env)
    {
        $this->projectRoot = $projectRoot;
        $this->env = $env;
    }

    public function run(): bool
    {
        if (!\extension_loaded('pdo_mysql') && !\extension_loaded('mysqli')) {
            echo "  ERROR: pdo_mysql/mysqli not loaded; cannot init MySQL.\n";
            return false;
        }

        $db = $this->resolveDatabaseSettings();
        $host = $db['host'];
        $port = $db['port'];
        $database = $db['database'];
        $username = $db['username'];
        $password = $db['password'];
        if ($db['generated']) {
            echo "  DB_* not fully configured. Using project database \"{$database}\" (user \"{$username}\").\n";
        }

        $admin = $this->connectAsAdmin($host, $port);
        if ($admin === null) {
            echo "  PDO admin connect failed; trying sudo mysql/mariadb peer (Linux)...\n";
            if (!$this->provisionViaSudoMysqlCli($database, $username, $password)) {
                echo "  ERROR: cannot connect to MySQL/MariaDB as admin (socket root / DB_ROOT_* / sudo mysql).\n";
                echo "  Hint: ensure mariadb/mysql is running; as root: mysql -e '...' or sudo mysql\n";
                return false;
            }
            echo "  Provisioned via sudo mysql CLI.\n";
        } else {
            echo "  Can connect to MySQL/MariaDB as admin.\n";

            try {
                $admin->exec('CREATE DATABASE IF NOT EXISTS `' . \str_replace('`', '``', $database) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $userEsc = \str_replace("'", "''", $username);
                $passEsc = \str_replace("'", "''", $password);
                $admin->exec("CREATE USER IF NOT EXISTS '{$userEsc}'@'localhost' IDENTIFIED BY '{$passEsc}'");
                $admin->exec("CREATE USER IF NOT EXISTS '{$userEsc}'@'%' IDENTIFIED BY '{$passEsc}'");
                // Older MariaDB may lack CREATE USER IF NOT EXISTS — fall back.
            } catch (\Throwable $e) {
                // Retry without IF NOT EXISTS for older servers.
                try {
                    $admin->exec('CREATE DATABASE IF NOT EXISTS `' . \str_replace('`', '``', $database) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                    $this->ensureMysqlUser($admin, $username, $password);
                } catch (\Throwable $e2) {
                    echo '  Create database/user failed: ' . $e2->getMessage() . "\n";
                    return false;
                }
            }

            try {
                $this->ensureMysqlUser($admin, $username, $password);
                $dbEsc = '`' . \str_replace('`', '``', $database) . '`';
                $userEsc = \str_replace("'", "''", $username);
                $admin->exec("GRANT ALL PRIVILEGES ON {$dbEsc}.* TO '{$userEsc}'@'localhost'");
                $admin->exec("GRANT ALL PRIVILEGES ON {$dbEsc}.* TO '{$userEsc}'@'%'");
                $admin->exec('FLUSH PRIVILEGES');
            } catch (\Throwable $e) {
                echo '  GRANT failed: ' . $e->getMessage() . "\n";
                return false;
            }
        }

        if (!$this->tryConnectApp($host, $port, $database, $username, $password)) {
            echo "  Created but app user connection check failed.\n";
            return false;
        }
        echo "  Connection check OK.\n";
        $this->updateEnvPhp($host, $port, $database, $username, $password);
        return true;
    }

    /**
     * @return array{host:string,port:string,database:string,username:string,password:string,generated:bool}
     */
    private function resolveDatabaseSettings(): array
    {
        $host = \trim((string)($this->env['DB_HOST'] ?? $this->env['DB_HOSTNAME'] ?? '127.0.0.1'));
        $port = \trim((string)($this->env['DB_PORT'] ?? $this->env['DB_HOSTPORT'] ?? '3306'));
        if ($port === '' || $port === '58550') {
            // 58550 is the project-local pgsql default; force MySQL default.
            $port = '3306';
        }
        $database = \trim((string)($this->env['DB_DATABASE'] ?? $this->env['DB_NAME'] ?? ''));
        $username = \trim((string)($this->env['DB_USERNAME'] ?? $this->env['DB_USER'] ?? ''));
        $password = (string)($this->env['DB_PASSWORD'] ?? '');
        $generated = false;
        if ($database === '' || $username === '' || $password === '') {
            $generated = true;
            $slug = $this->projectSlug();
            if ($database === '') {
                $database = $slug;
            }
            if ($username === '') {
                $username = $slug;
            }
            if ($password === '') {
                try {
                    $password = \bin2hex(\random_bytes(12));
                } catch (\Throwable) {
                    $password = 'weline_' . \substr(\md5($this->projectRoot . \microtime(true)), 0, 16);
                }
            }
        }
        return [
            'host' => $host !== '' ? $host : '127.0.0.1',
            'port' => $port !== '' ? $port : '3306',
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'generated' => $generated,
        ];
    }

    private function projectSlug(): string
    {
        $base = \basename($this->projectRoot);
        $base = \preg_replace('/[^a-zA-Z0-9_]+/', '_', $base) ?: 'weline';
        $base = \strtolower(\trim($base, '_'));
        if ($base === '') {
            $base = 'weline';
        }
        // MySQL user length limit 32
        return \substr('weline_' . $base, 0, 32);
    }

    private function connectAsAdmin(string $host, string $port): ?\PDO
    {
        $rootPass = \trim((string)($this->env['DB_ROOT_PASSWORD'] ?? $this->env['MYSQL_ROOT_PASSWORD'] ?? ''));
        $rootUser = \trim((string)($this->env['DB_ROOT_USER'] ?? 'root'));
        $candidates = [];
        if (PHP_OS_FAMILY === 'Linux') {
            $candidates[] = ['dsn' => 'mysql:unix_socket=/run/mysqld/mysqld.sock', 'user' => 'root', 'pass' => ''];
            $candidates[] = ['dsn' => 'mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'user' => 'root', 'pass' => ''];
        }
        $h = \in_array($host, ['', 'localhost'], true) ? '127.0.0.1' : $host;
        $candidates[] = ['dsn' => "mysql:host={$h};port={$port}", 'user' => $rootUser, 'pass' => $rootPass];
        if ($rootPass === '') {
            $candidates[] = ['dsn' => "mysql:host={$h};port={$port}", 'user' => 'root', 'pass' => ''];
        }
        foreach ($candidates as $c) {
            try {
                $pdo = new \PDO($c['dsn'], $c['user'], $c['pass'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
                return $pdo;
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    /**
     * Debian/MariaDB: unix_socket root only works for OS root. Deploy user (weline) uses sudo -n mysql.
     */
    private function provisionViaSudoMysqlCli(string $database, string $username, string $password): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }
        $cli = null;
        foreach (['mariadb', 'mysql'] as $bin) {
            $path = \trim((string)\shell_exec('command -v ' . \escapeshellarg($bin) . ' 2>/dev/null'));
            if ($path !== '') {
                $cli = $path;
                break;
            }
        }
        if ($cli === null) {
            return false;
        }
        $dbEsc = \str_replace('`', '``', $database);
        $userEsc = \str_replace("'", "''", $username);
        $passEsc = \str_replace("'", "''", $password);
        $sql = "CREATE DATABASE IF NOT EXISTS `{$dbEsc}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
            . "CREATE USER IF NOT EXISTS '{$userEsc}'@'localhost' IDENTIFIED BY '{$passEsc}';"
            . "CREATE USER IF NOT EXISTS '{$userEsc}'@'%' IDENTIFIED BY '{$passEsc}';"
            . "GRANT ALL PRIVILEGES ON `{$dbEsc}`.* TO '{$userEsc}'@'localhost';"
            . "GRANT ALL PRIVILEGES ON `{$dbEsc}`.* TO '{$userEsc}'@'%';"
            . 'FLUSH PRIVILEGES;';
        $cmd = 'sudo -n ' . \escapeshellarg($cli) . ' -e ' . \escapeshellarg($sql) . ' 2>&1';
        $out = [];
        $code = -1;
        @\exec($cmd, $out, $code);
        if ($code !== 0) {
            // Older MariaDB without CREATE USER IF NOT EXISTS
            $sql2 = "CREATE DATABASE IF NOT EXISTS `{$dbEsc}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
            @\exec('sudo -n ' . \escapeshellarg($cli) . ' -e ' . \escapeshellarg($sql2) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                echo '  sudo mysql failed: ' . \implode("\n", $out) . "\n";
                return false;
            }
            foreach (['localhost', '%'] as $h) {
                $create = "CREATE USER '{$userEsc}'@'{$h}' IDENTIFIED BY '{$passEsc}';";
                @\exec('sudo -n ' . \escapeshellarg($cli) . ' -e ' . \escapeshellarg($create) . ' 2>&1', $out, $code);
                $alter = "ALTER USER '{$userEsc}'@'{$h}' IDENTIFIED BY '{$passEsc}';";
                @\exec('sudo -n ' . \escapeshellarg($cli) . ' -e ' . \escapeshellarg($alter) . ' 2>&1', $out, $code);
                $grant = "GRANT ALL PRIVILEGES ON `{$dbEsc}`.* TO '{$userEsc}'@'{$h}';";
                @\exec('sudo -n ' . \escapeshellarg($cli) . ' -e ' . \escapeshellarg($grant) . ' 2>&1', $out, $code);
            }
            @\exec('sudo -n ' . \escapeshellarg($cli) . ' -e ' . \escapeshellarg('FLUSH PRIVILEGES;') . ' 2>&1', $out, $code);
        }
        return true;
    }

    private function ensureMysqlUser(\PDO $admin, string $username, string $password): void
    {
        $userEsc = \str_replace("'", "''", $username);
        $passEsc = \str_replace("'", "''", $password);
        foreach (['localhost', '%'] as $host) {
            try {
                $admin->exec("CREATE USER '{$userEsc}'@'{$host}' IDENTIFIED BY '{$passEsc}'");
            } catch (\Throwable) {
                try {
                    $admin->exec("ALTER USER '{$userEsc}'@'{$host}' IDENTIFIED BY '{$passEsc}'");
                } catch (\Throwable) {
                    // ignore if user already correct
                }
            }
        }
    }

    private function tryConnectApp(string $host, string $port, string $database, string $username, string $password): bool
    {
        $h = \in_array($host, ['', 'localhost'], true) ? '127.0.0.1' : $host;
        try {
            new \PDO(
                "mysql:host={$h};port={$port};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            return true;
        } catch (\Throwable $e) {
            echo '  App connect error: ' . $e->getMessage() . "\n";
            return false;
        }
    }

    private function updateEnvPhp(string $host, string $port, string $database, string $username, string $password): void
    {
        $envPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        $dir = \dirname($envPath);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        $dbConfig = [
            'default' => 'master',
            'master' => [
                'hostname' => $host,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'type' => 'mysql',
                'hostport' => $port,
                'prefix' => 'w_',
                'charset' => 'utf8mb4',
                'collate' => 'utf8mb4_unicode_ci',
                'persistent' => true,
                'pool_size' => 10,
                'timeout' => 30,
            ],
            'slaves' => [],
        ];

        $config = [];
        if (\is_file($envPath)) {
            $loaded = @include $envPath;
            if (\is_array($loaded)) {
                $config = $loaded;
            }
        }
        $config['db'] = $dbConfig;
        $sandboxMaster = $dbConfig['master'];
        $sandboxMaster['pool_size'] = 5;
        if (!isset($config['sandbox_db']) || !\is_array($config['sandbox_db'])) {
            $config['sandbox_db'] = ['default' => 'master', 'master' => $sandboxMaster, 'slaves' => []];
        } else {
            $config['sandbox_db']['master'] = \array_merge($config['sandbox_db']['master'] ?? [], $sandboxMaster);
        }

        $php = '<?php return ' . \var_export($config, true) . ';';
        $tmp = $envPath . '.tmp.' . \getmypid();
        if (@\file_put_contents($tmp, $php, \LOCK_EX) === false) {
            echo "  Failed to write env.php\n";
            return;
        }
        @\chmod($tmp, 0600);
        if (!@\rename($tmp, $envPath)) {
            @\unlink($tmp);
            echo "  Failed to replace env.php\n";
            return;
        }
        echo "  Updated app/etc/env.php (db mysql).\n";
    }
}
