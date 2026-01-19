<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2025/01/XX
 * 时间：11:45
 * 描述：PostgreSQL 数据库连接适配器
 */

namespace Weline\Framework\Database\Connection\Adapter\Pgsql;

use PDO;
use PDOException;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect\PgsqlDialectAdapter;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect\PgsqlIdentifierFormatter;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect\PgsqlTableNameStrategy;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Alter;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Create;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Database\Helper\Standar;
use Weline\Framework\Manager\ObjectManager;

final class Connector extends Query implements ConnectorInterface
{
    public function __construct(
        private readonly ?ConfigProviderInterface $configProvider
    ) {
        $identifierFormatter = new PgsqlIdentifierFormatter();
        $tableStrategy = new PgsqlTableNameStrategy(
            $identifierFormatter,
            $this->configProvider->getPrefix() ?: '',
            'public'
        );
        parent::__construct(
            $identifierFormatter,
            $tableStrategy,
            new PgsqlDialectAdapter()
        );
        $this->db_name = $this->configProvider->getDatabase() ?: 'public';
    }

    protected ?PDO $link = null;
    protected ?Query $query = null;
    protected bool $fromPool = false; // 标记连接是否来自连接池

    public function create(): static
    {
        if ($this->link !== null) {
            return $this;
        }

        $db_type = $this->configProvider->getDbType();
        if (!in_array($db_type, PDO::getAvailableDrivers())) {
            throw new LinkException(__('驱动不存在：%{1},可用驱动列表：%{2}，更多驱动配置请转到php.ini中开启。', [$db_type, implode(',', PDO::getAvailableDrivers())]));
        }

        // 从连接池获取连接
        $this->link = ConnectionPool::getConnection(
            $this->configProvider,
            function () {
                // PostgreSQL DSN 格式: pgsql:host=hostname;port=5432;dbname=database;user=username;password=password
                $dsn = "pgsql:host={$this->configProvider->getHostName()};port={$this->configProvider->getHostPort()};dbname={$this->configProvider->getDatabase()}";
                if ($this->configProvider->getCharset()) {
                    $dsn .= ";options='--client_encoding={$this->configProvider->getCharset()}'";
                }
                
                try {
                    $connection = new PDO($dsn, $this->configProvider->getUsername(), $this->configProvider->getPassword(), $this->configProvider->getOptions());
                    // 确保错误模式已设置（如果选项中没有设置）
                    if (!$connection->getAttribute(PDO::ATTR_ERRMODE)) {
                        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    }
                    if ($this->configProvider->getPreSql()) {
                        $connection->exec($this->configProvider->getPreSql());
                    }
                    // 设置字符集
                    if ($this->configProvider->getCharset()) {
                        $connection->exec("SET NAMES '{$this->configProvider->getCharset()}'");
                    }
                    return $connection;
                } catch (PDOException $e) {
                    throw new LinkException($e->getMessage());
                }
            }
        );
        $this->fromPool = true;
        return $this;
    }

    public function close(): void
    {
        // 如果连接来自连接池，归还到池中；否则直接释放
        if ($this->link !== null) {
            if ($this->fromPool) {
                ConnectionPool::releaseConnection($this->link, $this->configProvider);
            }
            $this->link = null;
            $this->fromPool = false;
        }
    }

    /**
     * 析构函数：确保连接在使用后被归还到连接池
     */
    public function __destruct()
    {
        $this->close();
    }

    public function getLink(): PDO
    {
        if ($this->link === null) {
            throw new LinkException(__('数据库连接未初始化'));
        }
        
        // 返回包装的 PDO 对象，在所有 SQL 执行前自动将反引号替换为双引号
        // 并包装 PDOStatement 以支持 nextRowset()（PostgreSQL 不支持多结果集，总是返回 false）
        $dbName = $this->configProvider->getDatabase();
        return new class($this->link, $dbName) extends PDO {
            private PDO $pdo;
            private string $dbName;
            
            public function __construct(PDO $pdo, string $dbName) {
                $this->pdo = $pdo;
                $this->dbName = $dbName;
            }
            
            /**
             * 统一处理 SQL：转换反引号为双引号，并将数据库名转换为 public schema
             */
            private function normalizeSql(string $sql): string {
                // 1. 转换反引号为双引号
                $sql = \Weline\Framework\Database\Connection\Adapter\Pgsql\Query::convertBackticksToDoubleQuotes($sql);
                
                // 2. 处理表名中的数据库名，转换为 public schema
                // 替换 "database"."table" 为 public."table"
                $sql = preg_replace('/"' . preg_quote($this->dbName, '/') . '"\."/', 'public."', $sql);
                // 替换 database."table" 为 public."table"（无引号的数据库名）
                $sql = preg_replace('/\b' . preg_quote($this->dbName, '/') . '\."/', 'public."', $sql);
                // 替换 database.table 为 public."table"（无引号的情况）
                $sql = preg_replace('/\b' . preg_quote($this->dbName, '/') . '\.([a-zA-Z_][a-zA-Z0-9_]*)\b/', 'public."$1"', $sql);
                
                // 3. 转换 MySQL 日期函数为 PostgreSQL 兼容函数
                $sql = $this->convertMysqlFunctionsToPostgresql($sql);
                
                return $sql;
            }
            
            /**
             * 转换 MySQL 日期/时间函数为 PostgreSQL 兼容函数
             */
            private function convertMysqlFunctionsToPostgresql(string $sql): string {
                // CURDATE() -> CURRENT_DATE
                $sql = preg_replace('/\bCURDATE\s*\(\s*\)/i', 'CURRENT_DATE', $sql);
                
                // DATE(CURDATE()-1) -> (CURRENT_DATE - INTERVAL '1 day')
                // DATE(field) = DATE(CURDATE()-N) -> DATE(field) = (CURRENT_DATE - INTERVAL 'N day')
                $sql = preg_replace('/DATE\s*\(\s*CURRENT_DATE\s*-\s*(\d+)\s*\)/i', "(CURRENT_DATE - INTERVAL '$1 day')", $sql);
                
                // DATE_SUB(CURDATE(), INTERVAL N DAY) -> (CURRENT_DATE - INTERVAL 'N day')
                $sql = preg_replace('/DATE_SUB\s*\(\s*CURRENT_DATE\s*,\s*INTERVAL\s+(\d+)\s+DAY\s*\)/i', "(CURRENT_DATE - INTERVAL '$1 day')", $sql);
                
                // DATE_SUB(NOW(), INTERVAL N DAY) -> (NOW() - INTERVAL 'N day')
                $sql = preg_replace('/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+DAY\s*\)/i', "(NOW() - INTERVAL '$1 day')", $sql);
                
                // DATE_SUB(NOW(), INTERVAL N WEEK) -> (NOW() - INTERVAL 'N week')
                $sql = preg_replace('/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+WEEK\s*\)/i', "(NOW() - INTERVAL '$1 week')", $sql);
                
                // DATE_SUB(NOW(), INTERVAL N MONTH) -> (NOW() - INTERVAL 'N month')
                $sql = preg_replace('/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+MONTH\s*\)/i', "(NOW() - INTERVAL '$1 month')", $sql);
                
                // DATE_SUB(NOW(), INTERVAL N QUARTER) -> (NOW() - INTERVAL 'N*3 month')
                $sql = preg_replace_callback('/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+QUARTER\s*\)/i', function($matches) {
                    $months = intval($matches[1]) * 3;
                    return "(NOW() - INTERVAL '{$months} month')";
                }, $sql);
                
                // DATE_SUB(NOW(), INTERVAL N YEAR) -> (NOW() - INTERVAL 'N year')
                $sql = preg_replace('/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+YEAR\s*\)/i', "(NOW() - INTERVAL '$1 year')", $sql);
                
                // TO_DAYS(field)=TO_DAYS(NOW()) -> DATE(field) = CURRENT_DATE
                $sql = preg_replace('/TO_DAYS\s*\(\s*([^)]+)\s*\)\s*=\s*TO_DAYS\s*\(\s*NOW\s*\(\s*\)\s*\)/i', 'DATE($1) = CURRENT_DATE', $sql);
                
                // YEAR(field) -> EXTRACT(YEAR FROM field)
                $sql = preg_replace('/\bYEAR\s*\(\s*([^)]+)\s*\)/i', 'EXTRACT(YEAR FROM $1)', $sql);
                
                // QUARTER(field) -> EXTRACT(QUARTER FROM field)
                $sql = preg_replace('/\bQUARTER\s*\(\s*([^)]+)\s*\)/i', 'EXTRACT(QUARTER FROM $1)', $sql);
                
                // DATE_FORMAT(field, '%Y%m') -> TO_CHAR(field, 'YYYYMM')
                $sql = preg_replace('/DATE_FORMAT\s*\(\s*([^,]+)\s*,\s*[\'"]%Y%m[\'"]\s*\)/i', "TO_CHAR($1, 'YYYYMM')", $sql);
                
                // DATE_FORMAT(field, '%Y-%m-%d') -> TO_CHAR(field, 'YYYY-MM-DD')
                $sql = preg_replace('/DATE_FORMAT\s*\(\s*([^,]+)\s*,\s*[\'"]%Y-%m-%d[\'"]\s*\)/i', "TO_CHAR($1, 'YYYY-MM-DD')", $sql);
                
                // YEARWEEK(DATE_FORMAT(field,'%Y-%m-%d')) = YEARWEEK(NOW()) -> TO_CHAR(field, 'IYYY-IW') = TO_CHAR(NOW(), 'IYYY-IW')
                $sql = preg_replace('/YEARWEEK\s*\(\s*TO_CHAR\s*\(\s*([^,]+)\s*,\s*[\'"]YYYY-MM-DD[\'"]\s*\)\s*\)\s*=\s*YEARWEEK\s*\(\s*NOW\s*\(\s*\)\s*\)/i', 
                    "TO_CHAR($1, 'IYYY-IW') = TO_CHAR(NOW(), 'IYYY-IW')", $sql);
                
                // YEARWEEK(DATE_FORMAT(field,'%Y-%m-%d')) = YEARWEEK(NOW())-N -> TO_CHAR(field, 'IYYY-IW') = TO_CHAR(NOW() - INTERVAL 'N week', 'IYYY-IW')
                $sql = preg_replace('/YEARWEEK\s*\(\s*TO_CHAR\s*\(\s*([^,]+)\s*,\s*[\'"]YYYY-MM-DD[\'"]\s*\)\s*\)\s*=\s*YEARWEEK\s*\(\s*NOW\s*\(\s*\)\s*\)\s*-\s*(\d+)/i', 
                    "TO_CHAR($1, 'IYYY-IW') = TO_CHAR(NOW() - INTERVAL '$2 week', 'IYYY-IW')", $sql);
                
                // PERIOD_DIFF(DATE_FORMAT(NOW(),'%Y%m'),DATE_FORMAT(field,'%Y%m')) = N 
                // -> (EXTRACT(YEAR FROM NOW()) * 12 + EXTRACT(MONTH FROM NOW())) - (EXTRACT(YEAR FROM field) * 12 + EXTRACT(MONTH FROM field)) = N
                // 简化处理：转换为 TO_CHAR 比较
                $sql = preg_replace('/PERIOD_DIFF\s*\(\s*TO_CHAR\s*\(\s*NOW\s*\(\s*\)\s*,\s*[\'"]YYYYMM[\'"]\s*\)\s*,\s*TO_CHAR\s*\(\s*([^,]+)\s*,\s*[\'"]YYYYMM[\'"]\s*\)\s*\)\s*=\s*(\d+)/i', 
                    "TO_CHAR($1, 'YYYY-MM') = TO_CHAR(NOW() - INTERVAL '$2 month', 'YYYY-MM')", $sql);
                
                return $sql;
            }
            
            public function prepare(string $query, array $options = []): \PDOStatement|false {
                $query = $this->normalizeSql($query);
                
                // PostgreSQL 要求参数名必须以字母开头
                // 转换 SQL 中的参数名：如果以数字开头，添加 'p' 前缀
                // MD5 哈希值是 32 个十六进制字符，如果第一个字符是数字，需要添加前缀
                $query = preg_replace_callback('/:([0-9a-f]{32})\b/', function($matches) {
                    // 检查是否以数字开头
                    if (preg_match('/^[0-9]/', $matches[1])) {
                        return ':p' . $matches[1];
                    }
                    return ':' . $matches[1];
                }, $query);
                
                // 尝试 prepare，如果失败则检查是否是多个命令的错误
                $stmt = @$this->pdo->prepare($query, $options);
                if ($stmt === false) {
                    $errorInfo = $this->pdo->errorInfo();
                    $errorCode = $errorInfo[0] ?? '';
                    $errorMessage = $errorInfo[2] ?? '';
                    
                    // 检查是否是"不能插入多个命令"的错误
                    if ($errorCode === '42601' && 
                        (str_contains($errorMessage, 'cannot insert multiple commands') || 
                         str_contains($errorMessage, 'multiple commands'))) {
                        throw new \PDOException(
                            "PostgreSQL prepared statements cannot contain multiple SQL commands. " .
                            "Use exec() for multiple statements or split them into separate calls. " .
                            "SQL preview: " . substr($query, 0, 200),
                            (int)$errorCode
                        );
                    }
                    return false;
                }
                
                // 返回包装的 PDOStatement，实现 nextRowset() 方法
                return $this->wrapPDOStatement($stmt, $query);
            }
            
            /**
             * 计算 SQL 语句的数量（排除字符串中的分号和末尾的分号）
             */
            private function countSqlStatements(string $sql): int {
                // 移除注释
                $sql = preg_replace('/--.*$/m', '', $sql);
                $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
                
                // 移除首尾空白
                $sql = trim($sql);
                
                // 移除末尾的分号（单条语句末尾的分号是允许的）
                $sql = rtrim($sql, ';');
                
                // 如果移除末尾分号后为空，说明只有一条语句
                if (empty(trim($sql))) {
                    return 1;
                }
                
                // 匹配不在引号内的分号（这些是真正的语句分隔符）
                // 使用更精确的正则表达式：匹配分号，但排除在单引号或双引号内的分号
                // 同时排除在注释中的分号
                $pattern = '/;(?=(?:[^\'"]*+(?:(?:\'[^\']*+\')|(?:"[^"]*+"))*+)*+$)/';
                $matches = preg_match_all($pattern, $sql);
                
                // 如果找到分号，说明有多个语句（分号数量 + 1）
                // 如果没有分号，只有一条语句
                return $matches > 0 ? $matches + 1 : 1;
            }
            
            public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): \PDOStatement|false {
                $query = $this->normalizeSql($query);
                
                $stmt = $this->pdo->query($query, $fetchMode, ...$fetchModeArgs);
                if ($stmt === false) {
                    return false;
                }
                // 返回包装的 PDOStatement，实现 nextRowset() 方法
                return $this->wrapPDOStatement($stmt);
            }
            
            public function exec(string $statement): int|false {
                $statement = $this->normalizeSql($statement);
                return $this->pdo->exec($statement);
            }
            
            /**
             * 包装 PDOStatement，添加 nextRowset() 支持
             * PostgreSQL 不支持多结果集，所以 nextRowset() 总是返回 false
             */
            private function wrapPDOStatement(\PDOStatement $stmt, string $originalQuery = ''): \PDOStatement {
                return new class($stmt, $originalQuery) extends \PDOStatement {
                    private \PDOStatement $stmt;
                    private string $originalQuery;
                    
                    public function __construct(\PDOStatement $stmt, string $originalQuery = '') {
                        $this->stmt = $stmt;
                        $this->originalQuery = $originalQuery;
                    }
                    
                    /**
                     * PostgreSQL 不支持多结果集，所以 nextRowset() 总是返回 false
                     */
                    public function nextRowset(): bool {
                        // 第一次调用返回 false（表示没有下一个结果集）
                        // 后续调用也返回 false
                        return false;
                    }
                    
                    // 代理所有其他 PDOStatement 方法
                    public function __call(string $name, array $arguments) {
                        return $this->stmt->$name(...$arguments);
                    }
                    
                    // 实现 PDOStatement 接口的必要方法
                    public function execute(?array $params = null): bool {
                        // PostgreSQL 要求参数名必须以字母开头
                        // 如果参数名以数字开头，需要转换（与 prepare() 方法中的转换逻辑保持一致）
                        if ($params !== null) {
                            $convertedParams = [];
                            foreach ($params as $paramName => $value) {
                                // 注意：不再统一将空字符串转换为 NULL
                                // 原因：
                                // 1. 字符串类型字段允许空字符串，且有 NOT NULL 约束时，空字符串是合法的
                                // 2. 整数类型字段的空字符串会在 PostgreSQL 类型检查时失败，但这种情况应该在数据层面处理
                                // 3. 统一转换会导致有 NOT NULL 约束的字符串字段插入失败
                                
                                // 确保参数名以 ':' 开头
                                $normalizedParamName = $paramName;
                                if (!str_starts_with($paramName, ':')) {
                                    $normalizedParamName = ':' . $paramName;
                                }
                                
                                // 如果参数名是 32 位 MD5 哈希且以数字开头，添加 'p' 前缀
                                // 这与 prepare() 方法中的转换逻辑完全一致
                                if (preg_match('/^:([0-9a-f]{32})$/', $normalizedParamName, $matches)) {
                                    if (preg_match('/^[0-9]/', $matches[1])) {
                                        // 参数名以数字开头，添加 'p' 前缀（与 prepare 中的转换一致）
                                        $newParamName = ':p' . $matches[1];
                                        $convertedParams[$newParamName] = $value;
                                    } else {
                                        // 参数名以字母开头，不需要转换
                                        $convertedParams[$normalizedParamName] = $value;
                                    }
                                } else {
                                    // 不是 32 位 MD5 格式的参数名，直接使用
                                    $convertedParams[$normalizedParamName] = $value;
                                }
                            }
                            
                            try {
                                return $this->stmt->execute($convertedParams);
                            } catch (\PDOException $e) {
                                // 如果执行失败，提供更详细的错误信息
                                $errorInfo = $this->stmt->errorInfo();
                                $errorMsg = $e->getMessage();
                                $errorCode = $errorInfo[0] ?? $e->getCode();
                                if (!empty($errorInfo[2])) {
                                    $errorMsg .= ' | ' . $errorInfo[2];
                                }
                                
                                // 检查是否是"不能插入多个命令"的错误（可能在 execute 时检测到）
                                if (($errorCode === '42601' || str_contains((string)$errorCode, '42601')) && 
                                    (str_contains($errorMsg, 'cannot insert multiple commands') || 
                                     str_contains($errorMsg, 'multiple commands'))) {
                                    throw new \PDOException(
                                        "PostgreSQL prepared statements cannot contain multiple SQL commands. " .
                                        "Use exec() for multiple statements or split them into separate calls. " .
                                        "SQL preview: " . substr($this->originalQuery, 0, 200),
                                        (int)$errorCode,
                                        $e
                                    );
                                }
                                
                                // 检查是否是参数不匹配的错误
                                $isParamError = str_contains($errorMsg, 'parameter') || 
                                               str_contains($errorMsg, 'bound parameter') ||
                                               str_contains($errorMsg, 'invalid parameter');
                                
                                if ($isParamError) {
                                    // 参数错误：提供参数映射信息
                                    $paramInfo = [];
                                    foreach ($params as $orig => $val) {
                                        $normalized = str_starts_with($orig, ':') ? $orig : ':' . $orig;
                                        if (preg_match('/^:([0-9a-f]{32})$/', $normalized, $m) && preg_match('/^[0-9]/', $m[1])) {
                                            $paramInfo[] = "{$orig} -> :p{$m[1]}";
                                        } else {
                                            $paramInfo[] = "{$orig} -> {$normalized}";
                                        }
                                    }
                                    throw new \PDOException(
                                        "PostgreSQL parameter binding error: {$errorMsg}. " .
                                        "Parameter mapping: " . implode(', ', array_slice($paramInfo, 0, 5)) . 
                                        (count($paramInfo) > 5 ? '...' : '') . ". " .
                                        "SQL preview: " . substr($this->originalQuery, 0, 150),
                                        (int)$errorCode,
                                        $e
                                    );
                                } else {
                                    // 其他错误：提供基本错误信息
                                    throw new \PDOException(
                                        "PostgreSQL execute error: {$errorMsg}. " .
                                        "SQL preview: " . substr($this->originalQuery, 0, 150),
                                        (int)$errorCode,
                                        $e
                                    );
                                }
                            }
                        }
                        return $this->stmt->execute($params);
                    }
                    
                    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
                        return $this->stmt->fetch($mode, $cursorOrientation, $cursorOffset);
                    }
                    
                    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array {
                        return $this->stmt->fetchAll($mode, ...$args);
                    }
                    
                    public function fetchColumn(int $column = 0): mixed {
                        return $this->stmt->fetchColumn($column);
                    }
                    
                    public function bindParam(string|int $param, mixed &$var, int $type = \PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool {
                        return $this->stmt->bindParam($param, $var, $type, $maxLength, $driverOptions);
                    }
                    
                    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool {
                        return $this->stmt->bindValue($param, $value, $type);
                    }
                    
                    public function bindColumn(string|int $column, mixed &$var, int $type = \PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool {
                        return $this->stmt->bindColumn($column, $var, $type, $maxLength, $driverOptions);
                    }
                    
                    public function rowCount(): int {
                        return $this->stmt->rowCount();
                    }
                    
                    public function errorCode(): ?string {
                        return $this->stmt->errorCode();
                    }
                    
                    public function errorInfo(): array {
                        return $this->stmt->errorInfo();
                    }
                    
                    public function setAttribute(int $attribute, mixed $value): bool {
                        return $this->stmt->setAttribute($attribute, $value);
                    }
                    
                    public function getAttribute(int $name): mixed {
                        return $this->stmt->getAttribute($name);
                    }
                    
                    public function columnCount(): int {
                        return $this->stmt->columnCount();
                    }
                    
                    public function getColumnMeta(int $column): array|false {
                        return $this->stmt->getColumnMeta($column);
                    }
                    
                    public function setFetchMode(int $mode, mixed ...$args): true {
                        return $this->stmt->setFetchMode($mode, ...$args);
                    }
                    
                    public function fetchObject(?string $class = "stdClass", array $constructorArgs = []): object|false {
                        return $this->stmt->fetchObject($class, $constructorArgs);
                    }
                    
                    public function closeCursor(): bool {
                        return $this->stmt->closeCursor();
                    }
                    
                    public function debugDumpParams(): ?bool {
                        return $this->stmt->debugDumpParams();
                    }
                };
            }
            
            // 代理所有其他 PDO 方法
            public function __call(string $name, array $arguments) {
                return $this->pdo->$name(...$arguments);
            }
            
            // 实现 PDO 接口的其他必要方法
            public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
            public function commit(): bool { return $this->pdo->commit(); }
            public function rollBack(): bool { return $this->pdo->rollBack(); }
            public function quote(string $string, int $type = \PDO::PARAM_STR): string|false { return $this->pdo->quote($string, $type); }
            public function lastInsertId(?string $name = null): string|false { 
                try {
                    return $this->pdo->lastInsertId($name);
                } catch (\PDOException $e) {
                    // PostgreSQL 中，如果手动指定了 ID 值，lastval() 可能未定义
                    // 返回 false 让调用者从 RETURNING 结果中获取 ID
                    if (str_contains($e->getMessage(), 'lastval is not yet defined')) {
                        return false;
                    }
                    throw $e;
                }
            }
            public function errorCode(): ?string { return $this->pdo->errorCode(); }
            public function errorInfo(): array { return $this->pdo->errorInfo(); }
            public function getAttribute(int $attribute): mixed { return $this->pdo->getAttribute($attribute); }
            public function setAttribute(int $attribute, mixed $value): bool { return $this->pdo->setAttribute($attribute, $value); }
        };
    }

    public function reindex(string $table): bool
    {
        $table = str_replace(['`', '"'], '', $table);
        if (str_contains($table, '.')) {
            list($schema, $table) = explode('.', $table);
        }
        if (empty($schema)) {
            $schema = 'public';
        }
        
        // PostgreSQL 重建索引
        $sql = "REINDEX TABLE \"{$schema}\".\"{$table}\"";
        try {
            $this->query($sql)->fetch();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getIndexFields(string $table): array
    {
        $table = str_replace(['`', '"'], '', $table);
        $schema = 'public';
        if (str_contains($table, '.')) {
            list($schema, $table) = explode('.', $table);
        }
        
        // PostgreSQL 查询索引信息
        $sql = <<<SQL
SELECT 
    i.relname AS "Key_name",
    a.attname AS "Column_name",
    ix.indisunique AS "Non_unique",
    a.attnum AS "Seq_in_index",
    CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS "Non_unique"
FROM 
    pg_class t,
    pg_class i,
    pg_index ix,
    pg_attribute a,
    pg_namespace n
WHERE 
    t.oid = ix.indrelid
    AND i.oid = ix.indexrelid
    AND a.attrelid = t.oid
    AND a.attnum = ANY(ix.indkey)
    AND t.relkind = 'r'
    AND n.oid = t.relnamespace
    AND n.nspname = '{$schema}'
    AND t.relname = '{$table}'
ORDER BY 
    i.relname, a.attnum
SQL;
        
        $result = $this->query($sql)->fetchArray();
        return $result ?? [];
    }

    /**
     * @DESC          # 读取创建表SQL
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 22:08
     * 参数区：
     *
     * @param string $table_name
     *
     * @return mixed
     */
    public function getCreateTableSql(string $table_name): string
    {
        $table_name = str_replace(['`', '"'], '', $table_name);
        $schema = 'public';
        if (str_contains($table_name, '.')) {
            list($schema, $table_name) = explode('.', $table_name);
        }
        
        // PostgreSQL 查询建表语句
        $sql = <<<SQL
SELECT 
    'CREATE TABLE ' || quote_ident(n.nspname) || '.' || quote_ident(c.relname) || E' (\n' ||
    string_agg(
        '    ' || quote_ident(a.attname) || ' ' || 
        pg_catalog.format_type(a.atttypid, a.atttypmod) ||
        CASE 
            WHEN a.attnotnull THEN ' NOT NULL'
            ELSE ''
        END,
        E',\n'
        ORDER BY a.attnum
    ) || E'\n);'
FROM 
    pg_catalog.pg_class c
    JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
    JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
WHERE 
    n.nspname = '{$schema}'
    AND c.relname = '{$table_name}'
    AND a.attnum > 0
    AND NOT a.attisdropped
GROUP BY 
    n.nspname, c.relname
SQL;
        
        $result = $this->query($sql)->fetch();
        return $result[0]['?column?'] ?? '';
    }

    public function getConfigProvider(): ConfigProviderInterface
    {
        return $this->configProvider;
    }

    public function createTable(): Sql\Table\CreateInterface
    {
        return ObjectManager::getInstance(Create::class)->setConnection($this);
    }

    public function alterTable(): Sql\Table\AlterInterface
    {
        return ObjectManager::getInstance(Alter::class)->setConnection($this);
    }

    public function tableExist(string $table_name): bool
    {
        try {
            $table_name = str_replace(['`', '"'], '', $table_name);
            $dbName = $this->configProvider->getDatabase();
            $schema = 'public';
            
            if (str_contains($table_name, '.')) {
                $parts = explode('.', $table_name);
                $firstPart = $parts[0];
                
                // 如果第一部分是数据库名，移除它，使用 public schema
                if ($firstPart === $dbName) {
                    $table_name = $parts[1] ?? $parts[0];
                    $schema = 'public';
                } else {
                    // 第一部分是 schema 名
                    $schema = $firstPart;
                    $table_name = $parts[1] ?? $parts[0];
                }
            }
            
            $sql = "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = '{$schema}' AND table_name = '{$table_name}')";
            $stmt = $this->getLink()->query($sql);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return (bool)($result['exists'] ?? false);
        } catch (\Exception $exception) {
            return false;
        }
    }

    public function getVersion(): string
    {
        // 查询数据库版本号
        $query = 'SELECT version() AS version';
        $stmt = $this->link->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['version'] ?? '';
    }

    public function hasField(string $table, string $field): bool
    {
        $table = str_replace(['`', '"'], '', $table);
        $field = str_replace(['`', '"'], '', $field);
        $dbName = $this->configProvider->getDatabase();
        $schema = 'public';
        
        if (str_contains($table, '.')) {
            $parts = explode('.', $table);
            $firstPart = $parts[0];
            
            // 如果第一部分是数据库名，移除它，使用 public schema
            if ($firstPart === $dbName) {
                $table = $parts[1] ?? $parts[0];
                $schema = 'public';
            } else {
                // 第一部分是 schema 名
                $schema = $firstPart;
                $table = $parts[1] ?? $parts[0];
            }
        }
        
        $sql = "SELECT EXISTS (SELECT FROM information_schema.columns WHERE table_schema = '{$schema}' AND table_name = '{$table}' AND column_name = '{$field}')";
        $stmt = $this->getLink()->query($sql);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (bool)($result['exists'] ?? false);
    }

    public function hasIndex(string $table, string $idx_name): bool
    {
        $table = str_replace(['`', '"'], '', $table);
        $idx_name = Standar::getIndexName($table, $idx_name);
        $schema = 'public';
        if (str_contains($table, '.')) {
            list($schema, $table) = explode('.', $table);
        }
        
        $sql = "SELECT EXISTS (SELECT FROM pg_indexes WHERE schemaname = '{$schema}' AND tablename = '{$table}' AND indexname = '{$idx_name}')";
        $result = $this->query($sql)->fetch();
        return (bool)($result[0]['exists'] ?? false);
    }

    public function query(string $sql): QueryInterface
    {
        if (!$this->link) {
            $this->create();
        }
        return parent::query($sql);
    }

    public function getQuery(): QueryInterface
    {
        return $this;
    }
}

