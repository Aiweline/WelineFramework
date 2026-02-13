<?php

declare(strict_types=1);

/**
 * PostgreSQL 安装完成后：根据 weline.env 的 DB_* 检测/创建数据库，并更新 app/etc/env.php。
 * - 使用超管（默认 postgres）连接，创建 weline.env 指定的 DB_USERNAME/DB_DATABASE/DB_PASSWORD。
 * - 若在 weline.env 配置了 PGSQL_INIT_USER/PGSQL_INIT_PASSWORD，则初始化 PostgreSQL 实例（initdb、设置 postgres 密码）时须使用相同信息，以便本处可连接。
 */
final class SetupPgsqlDatabase
{
    private string $projectRoot;
    private array $env;

    public function __construct(string $projectRoot, array $env)
    {
        $this->projectRoot = $projectRoot;
        $this->env = $env;
    }

    /**
     * 每次运行安装脚本都会执行：用 weline.env 的 DB_* 检测连接，视情况建库并同步 app/etc/env.php。
     * 超管默认 postgres；未配置 PGSQL_INIT_PASSWORD 时会依次尝试空密码、postgres、weline。
     */
    public function run(): bool
    {
        $host = trim($this->env['DB_HOST'] ?? '127.0.0.1');
        $port = trim($this->env['DB_PORT'] ?? '5432');
        $database = trim($this->env['DB_DATABASE'] ?? 'weline');
        $username = trim($this->env['DB_USERNAME'] ?? 'weline');
        $password = trim($this->env['DB_PASSWORD'] ?? 'weline');

        // 超管：仅用于连接并创建 weline 用户/库，不用于应用运行时
        // Mac (Homebrew) 默认只创建当前系统用户为超管、无 postgres 用户，故需回退尝试当前用户+空密码
        $initCreds = $this->resolveInitUserAndPassword($host, $port);
        if ($initCreds === null) {
            $this->echoPgsqlInitRequired(trim($this->env['PGSQL_INIT_USER'] ?? 'postgres'));
            return false;
        }
        [$initUser, $initPass] = $initCreds;

        // 优先用 PDO 路径（不依赖 psql，与 create_pgsql_user.php 一致）
        if (extension_loaded('pdo_pgsql')) {
            $pdoInit = $this->connectPdo($host, $port, 'postgres', $initUser, $initPass);
            if ($pdoInit !== null) {
                echo "  Can connect to PostgreSQL (as $initUser).\n";
                $dbExists = $this->databaseExistsViaPdo($pdoInit, $database);
                echo "  Database exists: " . ($dbExists ? 'true' : 'false') . ".\n";
                if ($dbExists) {
                    echo "  Database \"$database\" already exists. Skipping init.\n";
                    if (!$this->tryConnectPdo($host, $port, $database, $username, $password)) {
                        echo "  (Connection check failed. Fix env.php or DB service.)\n";
                        return false;
                    }
                    $this->updateEnvPhp($host, $port, $database, $username, $password);
                    return true;
                }
                echo "  Database \"$database\" not found. Creating user and database (PDO)...\n";
                if ($this->createUserAndDatabaseViaPdo($host, $port, $initUser, $initPass, $database, $username, $password)) {
                    if (!$this->tryConnectPdo($host, $port, $database, $username, $password)) {
                        echo "  (Created but connection check failed. Fix credentials or service and re-run.)\n";
                        return false;
                    }
                    echo "  Connection check OK.\n";
                    $this->updateEnvPhp($host, $port, $database, $username, $password);
                    return true;
                }
                return false;
            }
        }

        // 回退：使用 psql 命令行
        $psqlExe = $this->getPsqlPath();
        if ($psqlExe === null) {
            echo "  (psql not found and PDO init failed; will only update env.php)\n";
            $this->updateEnvPhp($host, $port, $database, $username, $password);
            return true;
        }

        $envForPsql = array_merge(
            getenv() ?: [],
            ['PGPASSWORD' => $initPass]
        );

        if (!$this->canConnect($psqlExe, $host, $port, $initUser, $envForPsql)) {
            $this->echoPgsqlInitRequired($initUser);
            return false;
        }
        echo "  Can connect to PostgreSQL.\n";
        $dbExists = $this->databaseExists($psqlExe, $host, $port, $initUser, $database, $envForPsql);
        echo "  Database exists: " . ($dbExists ? 'true' : 'false') . ".\n";
        if ($dbExists) {
            echo "  Database \"$database\" already exists. Skipping init.\n";
            if (!$this->tryConnectPdo($host, $port, $database, $username, $password)) {
                echo "  (Connection check failed. Fix env.php or DB service.)\n";
                return false;
            }
            $this->updateEnvPhp($host, $port, $database, $username, $password);
            return true;
        }

        echo "  Database \"$database\" not found. Creating (psql)...\n";
        if (!$this->createUserAndDatabase($psqlExe, $host, $port, $initUser, $database, $username, $password, $envForPsql)) {
            return false;
        }
        if (!$this->tryConnectPdo($host, $port, $database, $username, $password)) {
            echo "  (Created but connection check failed. Fix credentials or service and re-run.)\n";
            return false;
        }
        echo "  Connection check OK.\n";
        $this->updateEnvPhp($host, $port, $database, $username, $password);
        return true;
    }

    /**
     * 解析超管用户名与密码，返回 [user, password] 或 null。
     * 若 weline.env 已配置 PGSQL_INIT_USER/PGSQL_INIT_PASSWORD 则用之；否则先试 postgres + 空/postgres/weline。
     * Mac (Homebrew) 默认只创建当前系统用户为超管、无 postgres 用户，故 postgres 连不上时回退尝试当前 OS 用户 + 空密码。
     */
    private function resolveInitUserAndPassword(string $host, string $port): ?array
    {
        $initUser = trim($this->env['PGSQL_INIT_USER'] ?? 'postgres');
        $configured = trim($this->env['PGSQL_INIT_PASSWORD'] ?? '');
        if (!extension_loaded('pdo_pgsql')) {
            return $configured !== '' ? [$initUser, $configured] : [$initUser, ''];
        }
        if ($configured !== '') {
            if ($this->connectPdo($host, $port, 'postgres', $initUser, $configured) !== null) {
                return [$initUser, $configured];
            }
            return null;
        }
        foreach (['', 'postgres', 'weline'] as $try) {
            if ($this->connectPdo($host, $port, 'postgres', $initUser, $try) !== null) {
                return [$initUser, $try];
            }
        }
        // Mac (Homebrew)：默认只有当前系统用户为超管，trust 认证无需密码
        if (PHP_OS_FAMILY === 'Darwin') {
            $currentUser = getenv('USER') ?: (function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? '')
                : '');
            if ($currentUser !== '' && $this->connectPdo($host, $port, 'postgres', $currentUser, '') !== null) {
                echo "  Using current macOS user as PostgreSQL superuser: $currentUser (Homebrew default).\n";
                return [$currentUser, ''];
            }
        }
        return null;
    }

    /**
     * 醒目提示：PostgreSQL 初始化需要什么（连不上 postgres 时输出）
     */
    private function echoPgsqlInitRequired(string $initUser): void
    {
        $isMac = (PHP_OS_FAMILY === 'Darwin');
        echo "\n";
        echo "========================================\n";
        echo "  需要配置 weline.env 才能完成数据库初始化\n";
        echo "========================================\n";
        echo "  在项目根目录的 weline.env 中设置：\n";
        echo "  - PGSQL_INIT_USER=" . $initUser . "   （超管用户名，默认 postgres）\n";
        echo "  - PGSQL_INIT_PASSWORD=你的postgres密码  （必填，与 PostgreSQL 里 postgres 用户密码一致）\n";
        echo "\n";
        if ($isMac) {
            echo "  Mac (Homebrew) 若从未设过 postgres 密码，可先执行：\n";
            echo "    psql postgres -c \"ALTER USER postgres WITH PASSWORD '你的密码';\"\n";
            echo "  再把相同密码填入 weline.env 的 PGSQL_INIT_PASSWORD。\n";
            echo "  并确认服务已启动：brew services start postgresql@16\n";
            echo "\n";
        }
        echo "  修改后重新执行：php setup/server_installer/run.php  或  bin/install.sh\n";
        echo "========================================\n\n";
    }

    /**
     * PDO 连接，失败返回 null。
     */
    private function connectPdo(string $host, string $port, string $dbname, string $user, string $pass): ?\PDO
    {
        try {
            $dsn = "pgsql:host=" . $host . ";port=" . $port . ";dbname=" . $dbname;
            $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            return $pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function databaseExistsViaPdo(\PDO $pdo, string $dbname): bool
    {
        $safe = str_replace("'", "''", $dbname);
        $stmt = $pdo->query("SELECT 1 FROM pg_database WHERE datname = '$safe'");
        return $stmt !== false && $stmt->rowCount() > 0;
    }

    /**
     * 使用 PDO 以 init 用户创建/更新应用用户和数据库，并授予权限（与 dev/create_pgsql_user.php 一致）。
     */
    private function createUserAndDatabaseViaPdo(
        string $host,
        string $port,
        string $initUser,
        string $initPass,
        string $database,
        string $username,
        string $password
    ): bool {
        try {
            $pdo = $this->connectPdo($host, $port, 'postgres', $initUser, $initPass);
            if ($pdo === null) {
                echo "  Init connection failed (check PGSQL_INIT_USER/PGSQL_INIT_PASSWORD in weline.env).\n";
                return false;
            }
            $safeUser = str_replace("'", "''", $username);
            $safePass = str_replace("'", "''", $password);
            $quotedUser = '"' . str_replace('"', '""', $username) . '"';

            // 1. 用户：不存在则创建，存在则更新密码
            $stmt = $pdo->query("SELECT 1 FROM pg_roles WHERE rolname = '$safeUser'");
            if ($stmt !== false && $stmt->rowCount() > 0) {
                $pdo->exec("ALTER USER $quotedUser WITH PASSWORD '$safePass'");
                echo "  Updated password for user \"$username\".\n";
            } else {
                $pdo->exec("CREATE USER $quotedUser WITH PASSWORD '$safePass' CREATEDB");
                echo "  Created user \"$username\".\n";
            }

            // 2. 数据库：不存在则创建
            $safeDb = str_replace("'", "''", $database);
            $quotedDb = '"' . str_replace('"', '""', $database) . '"';
            $stmtDb = $pdo->query("SELECT 1 FROM pg_database WHERE datname = '$safeDb'");
            if ($stmtDb !== false && $stmtDb->rowCount() > 0) {
                echo "  Database \"$database\" already exists.\n";
            } else {
                $pdo->exec("CREATE DATABASE $quotedDb OWNER $quotedUser ENCODING 'UTF8'");
                echo "  Created database \"$database\".\n";
            }

            // 3. GRANT ALL PRIVILEGES ON DATABASE
            $pdo->exec("GRANT ALL PRIVILEGES ON DATABASE $quotedDb TO $quotedUser");

            // 4. 连到新库授予 schema 权限
            $pdoNew = $this->connectPdo($host, $port, $database, $initUser, $initPass);
            if ($pdoNew !== null) {
                $pdoNew->exec("GRANT ALL ON SCHEMA public TO $quotedUser");
                $pdoNew->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO $quotedUser");
                $pdoNew->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO $quotedUser");
            }

            return true;
        } catch (\Throwable $e) {
            echo "  PDO create failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function getPsqlPath(): ?string
    {
        $pgsqlDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'pgsql';
        $psql = (DIRECTORY_SEPARATOR === '\\')
            ? $pgsqlDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'psql.exe'
            : $pgsqlDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'psql';
        if (is_file($psql)) {
            return $psql;
        }
        return null;
    }

    private function runPsql(string $psqlExe, string $host, string $port, string $user, string $db, string $sql, array $env): bool
    {
        [$ok, ] = $this->runPsqlCapture($psqlExe, $host, $port, $user, $db, $sql, $env);
        return $ok;
    }

    /**
     * 执行 psql，返回 [是否成功, stderr 内容]。失败时便于输出具体错误。
     */
    private function runPsqlCapture(string $psqlExe, string $host, string $port, string $user, string $db, string $sql, array $env): array
    {
        $cmd = escapeshellarg($psqlExe) . ' -h ' . escapeshellarg($host) . ' -p ' . escapeshellarg($port)
            . ' -U ' . escapeshellarg($user) . ' -d ' . escapeshellarg($db)
            . ' -t -A -c ' . escapeshellarg($sql);
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open($cmd, $spec, $pipes, $this->projectRoot, $env);
        if (!is_resource($p)) {
            return [false, 'proc_open failed'];
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $stderr = is_string($stderr) ? trim($stderr) : '';
        $code = proc_close($p);
        return [$code === 0, $stderr];
    }

    private function runPsqlGetOutput(string $psqlExe, string $host, string $port, string $user, string $db, string $sql, array $env): ?string
    {
        $cmd = escapeshellarg($psqlExe) . ' -h ' . escapeshellarg($host) . ' -p ' . escapeshellarg($port)
            . ' -U ' . escapeshellarg($user) . ' -d ' . escapeshellarg($db)
            . ' -t -A -c ' . escapeshellarg($sql);
        if (DIRECTORY_SEPARATOR === '\\') {
            $cmd .= ' 2>nul';
        } else {
            $cmd .= ' 2>/dev/null';
        }
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open($cmd, $spec, $pipes, $this->projectRoot, $env);
        if (!is_resource($p)) {
            return null;
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        return $out !== false ? trim($out) : null;
    }

    private function canConnect(string $psqlExe, string $host, string $port, string $user, array $env): bool
    {
        return $this->runPsql($psqlExe, $host, $port, $user, 'postgres', 'SELECT 1', $env);
    }

    private function databaseExists(string $psqlExe, string $host, string $port, string $user, string $dbname, array $env): bool
    {
        $out = $this->runPsqlGetOutput(
            $psqlExe, $host, $port, $user, 'postgres',
            "SELECT 1 FROM pg_database WHERE datname = " . $this->quoteIdentifier($dbname),
            $env
        );
        return $out === '1';
    }

    private function quoteIdentifier(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }

    /**
     * 用新账户尝试 PDO 连接，连接成功才算创建有效。
     * Windows 上 pdo_pgsql 依赖 libpq.dll，需将 extend/server/pgsql/bin 加入 PATH。
     */
    private function tryConnectPdo(string $host, string $port, string $database, string $username, string $password): bool
    {
        if (!extension_loaded('pdo_pgsql')) {
            $pgsqlBin = $this->projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server'
                . DIRECTORY_SEPARATOR . 'pgsql' . DIRECTORY_SEPARATOR . 'bin';
            $libpq = $pgsqlBin . DIRECTORY_SEPARATOR . 'libpq.dll';
            if (DIRECTORY_SEPARATOR === '\\' && is_file($libpq)) {
                echo "  pdo_pgsql 未加载：需 libpq.dll。请将 extend\\server\\pgsql\\bin 加入 PATH 后重新运行安装脚本。\n";
            } else {
                echo "  (pdo_pgsql 未加载，无法校验连接。请在 php.ini 中启用 extension=pdo_pgsql)\n";
            }
            return false;
        }
        try {
            $dsn = "pgsql:host=" . $host . ";port=" . $port . ";dbname=" . $database;
            new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            return true;
        } catch (\Throwable $e) {
            echo "  PDO connection error: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * 创建或更新用户并建库。失败时输出 psql stderr 并返回 false。
     * 参考：使用 initUser（如 postgres）连 postgres 库执行 CREATE USER / ALTER USER / CREATE DATABASE。
     */
    private function createUserAndDatabase(
        string $psqlExe,
        string $host,
        string $port,
        string $initUser,
        string $database,
        string $username,
        string $password,
        array $env
    ): bool {
        $safeUser = str_replace("'", "''", $username);
        $safePass = str_replace("'", "''", $password);
        $quotedUser = '"' . str_replace('"', '""', $username) . '"';

        $createUser = "SELECT 1 FROM pg_roles WHERE rolname = '$safeUser'";
        $out = $this->runPsqlGetOutput($psqlExe, $host, $port, $initUser, 'postgres', $createUser, $env);
        if ($out !== '1') {
            $sql = "CREATE USER $quotedUser WITH PASSWORD '$safePass' CREATEDB";
            [$ok, $err] = $this->runPsqlCapture($psqlExe, $host, $port, $initUser, 'postgres', $sql, $env);
            if (!$ok) {
                echo "  Create user failed: " . ($err ?: 'unknown') . "\n";
                return false;
            }
            echo "  Created user \"$username\".\n";
        } else {
            $sql = "ALTER USER $quotedUser WITH PASSWORD '$safePass'";
            [$ok, $err] = $this->runPsqlCapture($psqlExe, $host, $port, $initUser, 'postgres', $sql, $env);
            if (!$ok) {
                echo "  Update password failed: " . ($err ?: 'unknown') . "\n";
                return false;
            }
            echo "  Updated password for user \"$username\".\n";
        }

        $quotedDb = '"' . str_replace('"', '""', $database) . '"';
        $sql = "CREATE DATABASE $quotedDb OWNER $quotedUser ENCODING 'UTF8'";
        [$ok, $err] = $this->runPsqlCapture($psqlExe, $host, $port, $initUser, 'postgres', $sql, $env);
        if (!$ok) {
            if ($err !== '' && strpos($err, 'already exists') !== false) {
                echo "  Database \"$database\" already exists.\n";
                return true;
            }
            echo "  Create database failed: " . ($err ?: 'unknown') . "\n";
            return false;
        }
        echo "  Created database \"$database\".\n";
        return true;
    }

    private function updateEnvPhp(string $host, string $port, string $database, string $username, string $password): void
    {
        $envPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        $dir = dirname($envPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $dbConfig = [
            'default' => 'master',
            'master' => [
                'hostname' => $host,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'type' => 'pgsql',
                'hostport' => $port,
                'prefix' => 'm_',
                'charset' => 'UTF8',
                'collate' => '',
                'persistent' => true,
                'pool_size' => 10,
                'timeout' => 30,
            ],
            'slaves' => [],
        ];

        if (is_file($envPath)) {
            $config = @include $envPath;
            if (!is_array($config)) {
                $config = [];
            }
        } else {
            $config = $this->getMinimalEnvConfig();
        }

        $config['db'] = $dbConfig;
        $sandboxMaster = $dbConfig['master'];
        $sandboxMaster['pool_size'] = 5;
        if (!isset($config['sandbox_db']) || !is_array($config['sandbox_db'])) {
            $config['sandbox_db'] = ['default' => 'master', 'master' => $sandboxMaster, 'slaves' => []];
        } else {
            $config['sandbox_db']['master'] = array_merge($config['sandbox_db']['master'] ?? [], $sandboxMaster);
        }

        $php = '<?php return ' . var_export($config, true) . ';';
        if (file_put_contents($envPath, $php) !== false) {
            echo "  Updated app/etc/env.php (db).\n";
        }
    }

    private function isInteractive(): bool
    {
        if (function_exists('stream_isatty') && defined('STDIN') && is_resource(STDIN)) {
            return stream_isatty(STDIN);
        }
        return false;
    }

    private function getMinimalEnvConfig(): array
    {
        return [
            'env' => 'local',
            'db' => [],
            'cache' => ['default' => 'file', 'drivers' => ['file' => ['path' => 'var/cache/']], 'status' => []],
            'session' => ['default' => 'file', 'drivers' => []],
            'log' => ['error' => 'var/log/error.log', 'exception' => 'var/log/exception.log'],
        ];
    }
}
