<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\Pgsql\Table;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Connection\Api\Sql\AbstractTable;
use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;
use Weline\Framework\Database\Helper\Standar;

class Create extends AbstractTable implements CreateInterface
{
    public array $index_outs = [];
    public const init_vars = [
        self::table_TABLE => '',
        self::table_COMMENT => '',
        self::table_FIELDS => [],
        self::table_ALERT_FIELDS => [],
        self::table_DELETE_FIELDS => [],
        self::table_INDEXS => [],
        self::table_FOREIGN_KEYS => [],
        self::table_CONSTRAINTS => '',
        self::table_ADDITIONAL => ';',
    ];

    public function reset(): void
    {
        $this->fields = [];
        $this->indexes = [];
        $this->foreign_keys = [];
        $this->constraints = '';
        $this->additional = ';';
        $this->primary_key = '';
        $this->comment = '';
        $this->new_table_name = '';
        $this->index_outs = [];
        foreach (self::init_vars as $key => $init_var) {
            $this->$key = $init_var;
        }
    }

    public function createTable(string $table, string $comment = ''): CreateInterface
    {
        # 开始表操作
        $this->reset();
        # 表名格式化由 formatTableName() 统一处理，这里直接传递原始表名
        $this->startTable($table, $comment);
        return $this;
    }

    public function addColumn(string $field_name, string $type, int|string|null $length, string $options, string $comment): CreateInterface
    {
        // PostgreSQL 类型映射
        $type = strtolower($type);
        $pgType = $this->mapTypeToPostgres($type, $length);
        
        // 处理 AUTO_INCREMENT (PostgreSQL 使用 SERIAL)
        if (str_contains(strtolower($options), 'auto_increment')) {
            $options = preg_replace('/\bauto_increment\b/i', '', $options);
            $options = trim($options);
            if ($type === TableInterface::column_type_INTEGER || $type === TableInterface::column_type_SMALLINT || $type === TableInterface::column_type_BIGINT) {
                if ($type === TableInterface::column_type_BIGINT) {
                    $pgType = 'BIGSERIAL';
                } else {
                    $pgType = 'SERIAL';
                }
                $options = preg_replace('/\bprimary\s+key\b/i', '', $options);
                $options = trim($options);
                if (!preg_match('/\bprimary\s+key\b/i', $options)) {
                    $options = ($options ? $options . ' ' : '') . 'PRIMARY KEY';
                }
            }
        }
        
        // 处理 ON UPDATE CURRENT_TIMESTAMP (PostgreSQL 不支持，需要移除)
        $options = preg_replace('/\bon\s+update\s+current_timestamp\b/i', '', $options);
        $options = trim($options);
        
        // 处理 UNSIGNED (PostgreSQL 不支持，需要移除)
        $options = preg_replace('/\bunsigned\b/i', '', $options);
        $options = trim($options);
        
        // PostgreSQL 中字符串默认值必须使用单引号，而不是双引号
        // 将所有的 default "..." 转换为 default '...'
        $options = preg_replace('/default\s+"([^"]*)"/i', "default '$1'", $options);
        
        // PostgreSQL 中 BOOLEAN 类型的默认值必须是 false/true，不能是 0/1
        // 将 BOOLEAN 类型的 default 0 转换为 default false，default 1 转换为 default true
        if (strtolower($type) === 'boolean' || strtolower($pgType) === 'boolean') {
            // 处理 default 0 -> default false
            $options = preg_replace('/\bdefault\s+0\b/i', 'default false', $options);
            // 处理 default 1 -> default true
            $options = preg_replace('/\bdefault\s+1\b/i', 'default true', $options);
        }
        
        // PostgreSQL 使用双引号
        $field_name = str_replace('`', '', $field_name);
        $field_name = str_replace('"', '', $field_name);
        
        // 注释使用 COMMENT ON COLUMN
        $commentSql = '';
        if ($comment) {
            $commentSql = "COMMENT ON COLUMN {$this->table}.\"{$field_name}\" IS '{$comment}';";
        }
        
        // PostgreSQL 中，只有某些类型支持长度参数
        // INTEGER, SMALLINT, BIGINT, REAL, DOUBLE PRECISION, TEXT, BYTEA, DATE, TIME, TIMESTAMP, JSONB 不支持长度
        // VARCHAR, CHAR, NUMERIC, DECIMAL 支持长度
        $typesWithoutLength = ['INTEGER', 'SMALLINT', 'BIGINT', 'REAL', 'DOUBLE PRECISION', 'TEXT', 'BYTEA', 'DATE', 'TIME', 'TIMESTAMP', 'JSONB', 'SERIAL', 'BIGSERIAL'];
        $type_length = $pgType;
        if ($length && !in_array(strtoupper($pgType), $typesWithoutLength)) {
            $type_length = "{$pgType}({$length})";
        }
        
        $this->fields[$field_name] = [
            'definition' => "\"{$field_name}\" {$type_length} {$options}",
            'comment' => $commentSql
        ];

        return $this;
    }

    /**
     * 映射 MySQL 类型到 PostgreSQL 类型
     */
    private function mapTypeToPostgres(string $type, int|string|null $length): string
    {
        $type = strtolower($type);
        $mapping = [
            'tinyint' => 'SMALLINT',
            'smallint' => 'SMALLINT',
            'mediumint' => 'INTEGER',
            'int' => 'INTEGER',
            'integer' => 'INTEGER',
            'bigint' => 'BIGINT',
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'decimal' => 'NUMERIC',
            'numeric' => 'NUMERIC',
            'char' => 'CHAR',
            'varchar' => 'VARCHAR',
            'text' => 'TEXT',
            'tinytext' => 'TEXT',
            'mediumtext' => 'TEXT',
            'longtext' => 'TEXT',
            'blob' => 'BYTEA',
            'tinyblob' => 'BYTEA',
            'mediumblob' => 'BYTEA',
            'longblob' => 'BYTEA',
            'date' => 'DATE',
            'time' => 'TIME',
            'datetime' => 'TIMESTAMP',
            'timestamp' => 'TIMESTAMP',
            'year' => 'INTEGER',
            'json' => 'JSONB',
            'enum' => 'VARCHAR',
            'set' => 'VARCHAR',
        ];
        
        return $mapping[$type] ?? strtoupper($type);
    }
    
    /**
     * 在 SQL 执行前转换 MySQL 类型为 PostgreSQL 兼容类型
     * 处理 SQL 字符串中直接包含的 MySQL 类型（如 TINYINT(1)）
     * 
     * @param string $sql SQL 语句
     * @return string 转换后的 SQL
     */
    private function convertMysqlTypesToPostgres(string $sql): string
    {
        // MySQL 类型到 PostgreSQL 类型的映射
        $typeMappings = [
            // 整数类型
            '/\bTINYINT\s*\(\s*\d+\s*\)/i' => 'SMALLINT',
            '/\bTINYINT\b/i' => 'SMALLINT',
            '/\bSMALLINT\s*\(\s*\d+\s*\)/i' => 'SMALLINT',
            '/\bMEDIUMINT\s*\(\s*\d+\s*\)/i' => 'INTEGER',
            '/\bMEDIUMINT\b/i' => 'INTEGER',
            '/\bINT\s*\(\s*\d+\s*\)/i' => 'INTEGER',
            '/\bINTEGER\s*\(\s*\d+\s*\)/i' => 'INTEGER',
            '/\bBIGINT\s*\(\s*\d+\s*\)/i' => 'BIGINT',
            
            // 浮点类型
            '/\bFLOAT\s*\(\s*\d+\s*(?:,\s*\d+)?\s*\)/i' => 'REAL',
            '/\bDOUBLE\s*\(\s*\d+\s*(?:,\s*\d+)?\s*\)/i' => 'DOUBLE PRECISION',
            
            // 文本类型
            '/\bTINYTEXT\b/i' => 'TEXT',
            '/\bMEDIUMTEXT\b/i' => 'TEXT',
            '/\bLONGTEXT\b/i' => 'TEXT',
            
            // 二进制类型
            '/\bTINYBLOB\b/i' => 'BYTEA',
            '/\bMEDIUMBLOB\b/i' => 'BYTEA',
            '/\bLONGBLOB\b/i' => 'BYTEA',
            '/\bBLOB\b/i' => 'BYTEA',
            
            // 日期时间类型
            '/\bDATETIME\b/i' => 'TIMESTAMP',
            '/\bYEAR\s*\(\s*\d+\s*\)/i' => 'INTEGER',
            '/\bYEAR\b/i' => 'INTEGER',
            
            // JSON 类型
            '/\bJSON\b/i' => 'JSONB',
            
            // ENUM 和 SET 类型（需要特殊处理，这里先转换为 VARCHAR）
            // 注意：ENUM 和 SET 的完整转换需要解析值列表，这里只做基本转换
        ];
        
        // 应用类型转换
        foreach ($typeMappings as $pattern => $replacement) {
            $sql = preg_replace($pattern, $replacement, $sql);
        }
        
        // 移除 SMALLINT、INTEGER、BIGINT 等类型的长度参数（PostgreSQL 不支持）
        // 匹配模式：SMALLINT(数字) -> SMALLINT
        $sql = preg_replace('/\b(SMALLINT|INTEGER|BIGINT|REAL|DOUBLE PRECISION|TEXT|BYTEA|DATE|TIME|TIMESTAMP|JSONB|SERIAL|BIGSERIAL)\s*\(\s*\d+\s*\)/i', '$1', $sql);
        
        return $sql;
    }

    public function addIndex(string $type, string $name, array|string $column, string $comment = '', string $index_method = ''): CreateInterface
    {
        $name = Standar::getIndexName($this->table, $name);
        // 确保索引名称去除反引号，使用双引号
        $name = trim(str_replace(['`', '"'], '', $name));
        
        // PostgreSQL 标识符长度限制为 63 字符，超过则截断并使用哈希确保唯一性
        if (strlen($name) > 63) {
            $originalName = $name;
            // 保留前 50 个字符，加上 8 位哈希值（共 58 字符，留出余量）
            $hash = substr(md5($originalName), 0, 8);
            $name = substr($name, 0, 55) . '_' . $hash;
        }
        
        $type = strtoupper($type);
        $index_method = $index_method ? "USING {$index_method}" : '';
        
        if (is_string($column)) {
            $column = explode(',', $column);
        }
        
        // 处理字段名，去除反引号，添加双引号
        $column = array_map(function($item) {
            $item = trim(str_replace(['`', '"'], '', $item));
            return "\"{$item}\"";
        }, $column);
        $column_str = implode(',', $column);
        
        switch ($type) {
            case self::index_type_DEFAULT:
            case self::index_type_KEY:
            case 'INDEX': // 兼容旧的调用方式
                $this->indexes[] = "CREATE INDEX IF NOT EXISTS \"{$name}\" ON {$this->table} ({$column_str}) {$index_method};";
                break;
            case self::index_type_UNIQUE:
                $this->indexes[] = "CREATE UNIQUE INDEX IF NOT EXISTS \"{$name}\" ON {$this->table} ({$column_str}) {$index_method};";
                break;
            case self::index_type_FULLTEXT:
                // PostgreSQL 使用 GIN 索引实现全文搜索
                $this->indexes[] = "CREATE INDEX IF NOT EXISTS \"{$name}\" ON {$this->table} USING GIN (to_tsvector('english', {$column_str}));";
                break;
            case self::index_type_SPATIAL:
                // PostgreSQL 使用 GIST 索引实现空间搜索
                $this->indexes[] = "CREATE INDEX IF NOT EXISTS \"{$name}\" ON {$this->table} USING GIST ({$column_str});";
                break;
            case self::index_type_MULTI:
                if (!is_array($column)) {
                    throw new Exception(self::index_type_MULTI . __('：此索引的column需要array类型'));
                }
                $this->indexes[] = "CREATE INDEX IF NOT EXISTS \"{$name}\" ON {$this->table} ({$column_str}) {$index_method};";
                break;
            default:
                throw new Exception(__('未知的索引类型：') . $type);
        }

        return $this;
    }

    public function addAdditional(string $additional_sql = ''): CreateInterface
    {
        // PostgreSQL 不需要 ENGINE 等选项
        $this->additional = $additional_sql ?: ';';
        return $this;
    }

    public function addConstraints(string $constraints = ''): CreateInterface
    {
        $this->constraints = $constraints;
        return $this;
    }

    public function addForeignKey(string $FK_Name, string $FK_Field, string $references_table, string $references_field, bool $on_delete = false, bool $on_update = false): CreateInterface
    {
        $on_delete_str = $on_delete ? 'ON DELETE CASCADE' : '';
        $on_update_str = $on_update ? 'ON UPDATE CASCADE' : '';
        
        // PostgreSQL 使用双引号
        $FK_Field = str_replace(['`', '"'], '', $FK_Field);
        $references_table = str_replace(['`', '"'], '', $references_table);
        $references_field = str_replace(['`', '"'], '', $references_field);
        
        // 处理表名：移除数据库名（如果存在），使用 public schema
        $dbName = $this->connector->getConfigProvider()->getDatabase();
        $schema = 'public';
        
        if (str_contains($references_table, '.')) {
            $parts = explode('.', $references_table);
            $firstPart = trim($parts[0]);
            
            // 如果第一部分是数据库名，移除它，使用 public schema
            if ($firstPart === $dbName) {
                $tableName = trim($parts[1] ?? $parts[0]);
                $references_table = "{$schema}.\"{$tableName}\"";
            } else {
                // 第一部分是 schema 名
                $schema = $firstPart;
                $tableName = trim($parts[1] ?? $parts[0]);
                $references_table = "{$schema}.\"{$tableName}\"";
            }
        } else {
            // 单个表名，使用 public schema
            $references_table = "{$schema}.\"{$references_table}\"";
        }
        
        $this->foreign_keys[] = "CONSTRAINT \"{$FK_Name}\" FOREIGN KEY (\"{$FK_Field}\") REFERENCES {$references_table} (\"{$references_field}\") {$on_delete_str} {$on_update_str}";
        return $this;
    }

    public function create(): mixed
    {
        // 字段
        $fieldDefinitions = [];
        $fieldComments = [];
        
        foreach ($this->fields as $fieldName => $fieldData) {
            if (is_array($fieldData)) {
                $fieldDefinitions[] = $fieldData['definition'];
                if (!empty($fieldData['comment'])) {
                    $fieldComments[] = $fieldData['comment'];
                }
            } else {
                $fieldDefinitions[] = $fieldData;
            }
        }
        
        // 如果没有 create_time 和 update_time，添加它们
        $hasCreateTime = false;
        $hasUpdateTime = false;
        foreach ($fieldDefinitions as $def) {
            if (str_contains(strtolower($def), 'create_time')) {
                $hasCreateTime = true;
            }
            if (str_contains(strtolower($def), 'update_time')) {
                $hasUpdateTime = true;
            }
        }
        
        if (!$hasCreateTime) {
            $create_time_comment_words = __('创建时间');
            // PostgreSQL 使用 NOW() 或 CURRENT_TIMESTAMP
            $fieldDefinitions[] = "\"create_time\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";
            $fieldComments[] = "COMMENT ON COLUMN {$this->table}.\"create_time\" IS '{$create_time_comment_words}';";
        }
        if (!$hasUpdateTime) {
            $update_time_comment_words = __('更新时间');
            // PostgreSQL 不支持 ON UPDATE CURRENT_TIMESTAMP，需要使用触发器
            // 这里只设置默认值，更新需要使用触发器
            $fieldDefinitions[] = "\"update_time\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";
            $fieldComments[] = "COMMENT ON COLUMN {$this->table}.\"update_time\" IS '{$update_time_comment_words}';";
        }
        
        $fields_str = implode(',' . PHP_EOL . '    ', $fieldDefinitions);
        
        // 外键 - 先全部收集，稍后检查引用的表是否存在
        $foreign_key_str = '';
        $delayed_foreign_keys = [];
        if (!empty($this->foreign_keys)) {
            // 暂时先不包含外键，稍后在创建表后检查并添加
            $delayed_foreign_keys = $this->foreign_keys;
        }
        
        // 约束
        $constraints_str = '';
        if (!empty($this->constraints)) {
            $constraints_str = ',' . PHP_EOL . '    ' . $this->constraints;
        }
        
        // 表注释
        $table_comment = '';
        if ($this->comment) {
            $table_comment = "COMMENT ON TABLE {$this->table} IS '{$this->comment}';";
        }
        
        // PostgreSQL 的 PDO 不支持在一个 prepared statement 中执行多个命令
        // 需要分开执行每个 SQL 语句
        $allSqls = [];
        
        // 1. CREATE TABLE 语句（只包含存在的外键）
        $createTableSql = "CREATE TABLE {$this->table}(" . PHP_EOL . '    ' . $fields_str . ($foreign_key_str ? ',' . PHP_EOL . '    ' . $foreign_key_str : '') . $constraints_str . PHP_EOL . ");";
        $allSqls[] = $createTableSql;
        
        // 2. COMMENT ON TABLE 语句
        if ($this->comment) {
            $allSqls[] = $table_comment;
        }
        
        // 3. COMMENT ON COLUMN 语句
        if (!empty($fieldComments)) {
            $allSqls = array_merge($allSqls, $fieldComments);
        }
        
        // 4. CREATE INDEX 语句
        if (!empty($this->indexes)) {
            $allSqls = array_merge($allSqls, $this->indexes);
        }
        
        // 组合所有 SQL 用于错误信息显示
        $fullSql = implode(PHP_EOL, $allSqls);
        
        try {
            // 获取 PDO 连接
            /** @var \Weline\Framework\Database\Connection\Adapter\Pgsql\Connector $connector */
            $connector = $this->connector;
            $pdo = $connector->getLink();
            
            // 检查表是否已存在
            // 从表名中提取 schema 和表名（去除双引号）
            $schemaName = 'public';
            $tableName = '';
            if (str_contains($this->table, '.')) {
                $parts = explode('.', $this->table);
                $schemaName = trim($parts[0], '"');
                $tableName = trim($parts[1] ?? '', '"');
            } else {
                $tableName = trim($this->table, '"');
            }
            
            // 检查表是否已存在（使用原始表名格式，让 tableExist 自己处理）
            // 构造检查用的表名：schema.table（不带引号，让 tableExist 处理）
            $checkTableName = "{$schemaName}.{$tableName}";
            if ($connector->tableExist($checkTableName)) {
                // 表已存在，跳过创建
                return true;
            }
            
            // 检查并创建 schema（如果不存在）
            if ($schemaName !== 'public') {
                $checkSchemaSql = "SELECT EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name = '{$schemaName}')";
                $schemaExists = $pdo->query($checkSchemaSql)->fetchColumn();
                if (!$schemaExists) {
                    $pdo->exec("CREATE SCHEMA IF NOT EXISTS \"{$schemaName}\"");
                }
            } else {
                // 对于 public schema，检查用户是否有创建权限
                // 如果没有权限，尝试授予权限（如果用户有 GRANT 权限）
                try {
                    $checkPermissionSql = "SELECT has_schema_privilege(current_user, 'public', 'CREATE')";
                    $hasPermissionResult = @$pdo->query($checkPermissionSql)->fetchColumn();
                    // PostgreSQL 返回 't' 或 'f' 字符串，转换为布尔值
                    $hasPermission = ($hasPermissionResult === 't' || $hasPermissionResult === true);
                    if (!$hasPermission) {
                        // 尝试授予权限（如果当前用户有权限）
                        try {
                            $currentUser = @$pdo->query("SELECT current_user")->fetchColumn();
                            if ($currentUser) {
                                @$pdo->exec("GRANT CREATE ON SCHEMA public TO " . $currentUser);
                            }
                        } catch (\PDOException $grantException) {
                            // 如果无法授予权限，继续执行，让后续的错误处理提供更详细的提示
                        }
                    }
                } catch (\PDOException $checkException) {
                    // 权限检查失败，继续执行，让后续的错误处理提供更详细的提示
                }
            }
            
            // 逐个执行 SQL 语句
            foreach ($allSqls as $sql) {
                $sql = trim($sql);
                if (empty($sql)) {
                    continue;
                }
                // 在执行前转换 MySQL 类型为 PostgreSQL 兼容类型
                $sql = $this->convertMysqlTypesToPostgres($sql);
                try {
                    // 使用 @ 抑制可能的 Warning
                    @$pdo->exec($sql);
                } catch (\PDOException $e) {
                    // 如果是索引已存在的错误，忽略（因为使用了 IF NOT EXISTS）
                    // 如果是其他错误，继续抛出
                    $errorCode = $e->getCode();
                    $errorMessage = $e->getMessage();
                    
                    // 检查是否是权限错误（42501）
                    if ($errorCode === '42501' || str_contains($errorMessage, 'permission denied')) {
                        // 权限错误：提供详细的解决方案提示
                        try {
                            $currentUser = @$pdo->query("SELECT current_user")->fetchColumn() ?: 'your_user';
                            $dbName = @$pdo->query("SELECT current_database()")->fetchColumn() ?: 'your_database';
                        } catch (\Exception $userException) {
                            $currentUser = 'your_user';
                            $dbName = 'your_database';
                        }
                        
                        // 检查 PostgreSQL 版本
                        $pgVersion = '';
                        try {
                            $versionResult = @$pdo->query("SELECT version()")->fetchColumn();
                            if (preg_match('/PostgreSQL (\d+)/', $versionResult, $matches)) {
                                $pgVersion = $matches[1];
                            }
                        } catch (\Exception $versionException) {
                            // 忽略版本检查错误
                        }
                        
                        $hint = PHP_EOL . PHP_EOL . __('权限错误说明：') . PHP_EOL;
                        if ($pgVersion && (int)$pgVersion >= 15) {
                            $hint .= __('PostgreSQL 15+ 中，普通用户默认没有 public schema 的 CREATE 权限（安全改进）。') . PHP_EOL;
                            $hint .= __('只有数据库所有者（owner）或已显式授权的用户才能创建表。') . PHP_EOL;
                        } else {
                            $hint .= __('当前数据库用户没有在 public schema 中创建表的权限。') . PHP_EOL;
                        }
                        $hint .= PHP_EOL . __('解决方案：') . PHP_EOL;
                        $hint .= __('1. 使用数据库超级用户（如 postgres）连接到数据库 "%{1}"：', [$dbName]) . PHP_EOL;
                        $hint .= __('   psql -U postgres -d %{1}', [$dbName]) . PHP_EOL;
                        $hint .= PHP_EOL;
                        $hint .= __('2. 执行以下命令授予权限：') . PHP_EOL;
                        $hint .= __('   GRANT USAGE, CREATE ON SCHEMA public TO %{1};', [$currentUser]) . PHP_EOL;
                        $hint .= __('   GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO %{1};', [$currentUser]) . PHP_EOL;
                        $hint .= __('   GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO %{1};', [$currentUser]) . PHP_EOL;
                        $hint .= __('   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO %{1};', [$currentUser]) . PHP_EOL;
                        $hint .= __('   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO %{1};', [$currentUser]) . PHP_EOL;
                        $hint .= PHP_EOL;
                        $hint .= __('3. 或者修改配置文件，使用数据库超级用户连接数据库。') . PHP_EOL;
                        $hint .= __('   编辑 app/etc/env.php，将数据库用户名改为 postgres 或其他超级用户。') . PHP_EOL;
                        throw new \PDOException($errorMessage . $hint, (int)$errorCode, $e);
                    }
                    
                    // PostgreSQL 错误代码：42P07 = duplicate_table, 42710 = duplicate_object
                    // 如果是索引或表已存在的错误，且使用了 IF NOT EXISTS，则忽略
                    if (str_contains($sql, 'IF NOT EXISTS') && 
                        (str_contains($errorMessage, 'already exists') || 
                         $errorCode === '42P07' || 
                         $errorCode === '42710')) {
                        // 索引或表已存在，忽略错误
                        continue;
                    }
                    
                    // 其他错误继续抛出
                    throw $e;
                }
            }
            
            // 5. 延迟创建外键（如果引用的表现在存在了）
            if (!empty($delayed_foreign_keys)) {
                foreach ($delayed_foreign_keys as $fk) {
                    // 从外键定义中提取引用的表名
                    if (preg_match('/REFERENCES\s+([^\s(]+)/i', $fk, $matches)) {
                        $refTable = trim($matches[1], '"');
                        // 再次检查表是否存在
                        if ($connector->tableExist($refTable)) {
                            // 将 CONSTRAINT 转换为 ALTER TABLE ADD CONSTRAINT
                            $alterFk = str_replace('CONSTRAINT', "ALTER TABLE {$this->table} ADD CONSTRAINT", $fk);
                            try {
                                $pdo->exec($alterFk);
                            } catch (\Exception $e) {
                                // 外键创建失败，记录但不中断流程
                                // 可能是表已存在但外键已存在，或其他原因
                            }
                        }
                    }
                }
            }
            
            // 返回成功结果
            $result = true;
        } catch (\Exception $exception) {
            throw new Exception(__('创建表失败，' . PHP_EOL . PHP_EOL . 'SQL：%{1} ' . PHP_EOL . PHP_EOL . 'ERROR：%{2}', [$fullSql, $exception->getMessage()]));
        }
        return $result;
    }
}

