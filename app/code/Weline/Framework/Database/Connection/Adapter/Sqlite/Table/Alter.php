<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\Sqlite\Table;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\AbstractTable;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;

class Alter extends AbstractTable implements AlterInterface
{
    public string $additional = ';';
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

    /** @var array<int, array<string>> 需要重建表以调整列顺序的 SQL 列表 */
    private array $rebuilds = [];

    public function forTable(string $table_name, string $primary_key, string $comment = '', string $new_table_name = ''): AlterInterface
    {
        # 开始表操作
        $this->startTable($table_name, $comment, $primary_key, $new_table_name);
        return $this;
    }

    /**
     * @DESC          # 添加字段
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/26 21:31
     * 参数区：
     *
     * @param string $field_name 字段名
     * @param string $after_column
     * @param string $type 字段类型
     * @param string|int $length 长度
     * @param string $options 配置
     * @param string $comment 字段注释
     *
     * @return AlterInterface
     */
    public function addColumn(string $field_name, string $after_column, string $type, string|int $length, string $options, string $comment): AlterInterface
    {
        $type_length = $length ? "{$type}({$length})" : $type;

        // 如果指定了 after_column，SQLite 不支持直接指定位置，需要通过重建表来实现列顺序调整
        if (!empty($after_column)) {
            // 读取现有列名
            $tableFields = $this->getTableColumns();
            $colNames = [];
            foreach ($tableFields as $col) {
                $colName = $col['name'] ?? ($col['Field'] ?? null);
                if ($colName) $colNames[] = $colName;
            }

            $originTable = str_replace('`', '', $this->table);
            // 如果 after_column 不存在，则直接追加
            $pos = array_search($after_column, $colNames, true);
            if ($pos === false) {
                $selectParts = array_map(fn($c) => "`{$c}`", $colNames);
                $selectParts[] = "0 AS `{$field_name}`";
            } else {
                $selectParts = [];
                foreach ($colNames as $i => $c) {
                    $selectParts[] = "`{$c}`";
                    if ($i === $pos) {
                        $selectParts[] = "0 AS `{$field_name}`";
                    }
                }
            }

            $selectList = implode(',', $selectParts);
            $tmpTable = $originTable . '_tmp_' . uniqid();
            $sqls = [];
            $sqls[] = "PRAGMA foreign_keys=OFF;";
            $sqls[] = "BEGIN TRANSACTION;";
            $sqls[] = "CREATE TABLE {$tmpTable} AS SELECT {$selectList} FROM {$originTable};";
            $sqls[] = "DROP TABLE {$originTable};";
            $sqls[] = "ALTER TABLE {$tmpTable} RENAME TO {$originTable};";
            $sqls[] = "COMMIT;";
            $sqls[] = "PRAGMA foreign_keys=ON;";

            $this->rebuilds[] = $sqls;
            return $this;
        }

        // 常规新增列（SQLite 追加到末尾）
        $this->fields[] = "ADD COLUMN `{$field_name}` {$type_length} {$options}";
        return $this;
    }

    /**
     * @DESC          # 删除字段
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 16:09
     * 参数区：
     *
     * @param string $field_name
     *
     * @return AlterInterface
     */
    public function deleteColumn(string $field_name): AlterInterface
    {
        $this->delete_fields[$field_name] = $field_name;
        return $this;
    }

    /**
     * @DESC         |添加索引
     *
     * 参数区：
     *
     * @param string $type
     * @param string $name
     * @param array|string $column
     *
     * @return AlterInterface
     */
    public function addIndex(string $type, string $name, array|string $column, string $comment = '', string $index_method = 'BTREE'): AlterInterface
    {
        $comment = $comment ? "comment '{$comment}'" : "";
        switch ($type) {
            case self::index_type_DEFAULT:
                $this->indexes[] = "INDEX {$name}(`{$column}`) USING {$index_method} {$comment}," . PHP_EOL;

                break;
            case self::index_type_FULLTEXT:
                $this->indexes[] = "FULLTEXT INDEX {$name}(`{$column}`) USING {$index_method} {$comment}," . PHP_EOL;

                break;
            case self::index_type_UNIQUE:
                $this->indexes[] = "UNIQUE INDEX {$name}(`{$column}`) USING {$index_method} {$comment}," . PHP_EOL;

                break;
            case self::index_type_SPATIAL:
                $this->indexes[] = "SPATIAL INDEX {$name}(`{$column}`) USING {$index_method} {$comment}," . PHP_EOL;

                break;
            case self::index_type_KEY:
                $this->indexes[] = "KEY IDX {$name}(`{$column}`) USING {$index_method} {$comment}," . PHP_EOL;

                break;
            case self::index_type_MULTI:
                $type_of_column = getType($column);
                if (!is_array($column)) {
                    new Exception(self::index_type_MULTI . __('：此索引的column需要array类型,当前类型') . "{$type_of_column}" . ' 例如：[ID,NAME(19),AGE]');
                }
                $column = implode(',', $column);
                $this->indexes[] = "INDEX {$name}(`$column`) USING {$index_method} {$comment}," . PHP_EOL;

                break;
            default:
                new Exception(__('未知的索引类型：') . $type);
        }

        return $this;
    }

    /**
     * @DESC         |建表附加
     *
     * 参数区：
     *
     * @param string $additional_sql
     *
     * @return AlterInterface
     */
    public function addAdditional(string $additional_sql = 'ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;'): AlterInterface
    {
        $this->additional = $additional_sql;

        return $this;
    }

    /**
     * @DESC         |表约束
     *
     * 参数区：
     *
     * @param string $constraints
     *
     * @return AlterInterface
     */
    public function addConstraints(string $constraints = ''): AlterInterface
    {
        $this->constraints = $constraints;

        return $this;
    }

    public function alterColumn(string $old_field, string $field_name, string $after_field = '', string $type = '', string|int $length = '', string $options = '', string $comment = ''): AlterInterface
    {
        $not_need_length_types = [
            'text', 'mediumtext', 'longtext', 'tinytext', 'mediumblob', 'longblob', 'tinyblob', 'blob',
        ];
        if (in_array(strtolower($type), $not_need_length_types)) {
            $type_length = $type;
        } else {
            $type_length = $length ? "{$type}({$length})" : $type;
        }
        $this->alter_fields[$old_field] = ['field_name' => $field_name, 'after_field' => $after_field, 'type_length' => $type_length, 'options' => $options, 'comment' => $comment,];

        return $this;
    }

    public function alter(bool $dump_sql = false): bool
    {
        if ($dump_sql) {
            $dump_sqls = [];
        }
        // 如果存在需要通过重建表实现的列顺序调整，先执行这些 SQL 序列
        if (!empty($this->rebuilds)) {
            foreach ($this->rebuilds as $sqls) {
                foreach ($sqls as $sql) {
                    if ($dump_sql) {
                        $dump_sqls[] = $sql;
                        continue;
                    }
                    try {
                        $this->query($sql)->fetch();
                    } catch (\Exception $exception) {
                        exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                    }
                }
            }
            // 执行完成后清空重建列表
            $this->rebuilds = [];
        }
        # --如果存在删除数组中则先删除字段
        foreach ($this->delete_fields as $delete_field) {
            $sql = "ALTER TABLE {$this->table} DROP `{$delete_field}`";
            if ($dump_sql) {
                $dump_sqls[] = $sql;
            } else {
                try {
                    $this->query($sql)->fetch();
                } catch (\Exception $exception) {
                    exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                }
            }
        }
        # --如果存在要新增的字段
        if ($this->fields) {
            $fields = join(',', $this->fields);
            $sql = "ALTER TABLE {$this->table} $fields";
            if ($dump_sql) {
                $dump_sqls[] = $sql;
            } else {
                try {
                    $this->query($sql)->fetch();
                } catch (\Exception $exception) {
                    exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                }
            }
        }
        try {
            $table_fields = $this->getTableColumns();

            // 如果存在 alter_fields，需要走 SQLite 兼容的重建表流程（替代 CHANGE/MODIFY）
            if (!empty($this->alter_fields)) {
                $originTable = str_replace('`', '', $this->table);
                // 现有列顺序与名称
                $existingCols = [];
                foreach ($table_fields as $tf) {
                    $existingCols[] = $tf['Field'] ?? ($tf['name'] ?? '');
                }

                // 构造插入列与选择列
                $insertCols = [];
                $selectCols = [];
                // 对于已有列：若被重命名则 SELECT old AS new，INSERT 目标使用 new
                foreach ($existingCols as $col) {
                    if (isset($this->alter_fields[$col])) {
                        $newName = $this->alter_fields[$col]['field_name'];
                        $insertCols[] = "`{$newName}`";
                        $selectCols[] = "`{$col}`";
                    } else {
                        $insertCols[] = "`{$col}`";
                        $selectCols[] = "`{$col}`";
                    }
                }
                // 检查有没有 alter_fields 指向新增列（不在已有列中），这些需在 SELECT 中用 NULL 占位
                foreach ($this->alter_fields as $old => $spec) {
                    $newName = $spec['field_name'];
                    if (!in_array($newName, $existingCols, true)) {
                        $insertCols[] = "`{$newName}`";
                        $selectCols[] = "NULL";
                    }
                }

                // 构造新的列定义（用于创建临时表，尝试保留主键自增等要求）
                $newColumnDefs = [];
                $existingColsMap = [];
                foreach ($table_fields as $tf) {
                    $fname = $tf['Field'] ?? ($tf['name'] ?? '');
                    $existingColsMap[$fname] = $tf;
                }

                // Helper: 清洗默认值，返回完整的 DEFAULT xxx 或空字符串
                $sanitizeDefault = function ($defaultRaw, bool $isTimestampLike) {
                    if ($defaultRaw === null || $defaultRaw === '') return '';
                    $d = trim((string)$defaultRaw);
                    // 去掉外层单/双引号
                    if ((str_starts_with($d, "'") && str_ends_with($d, "'")) || (str_starts_with($d, '"') && str_ends_with($d, '"'))) {
                        $d = substr($d, 1, -1);
                    }
                    $lower = strtolower($d);
                    if ($d === '' || $lower === 'null') return 'DEFAULT NULL';

                    // 常见时间函数识别
                    if ($isTimestampLike) {
                        if (str_contains($lower, 'current_timestamp') || str_contains($lower, "datetime('now')") || str_contains($lower, 'strftime(')) {
                            return "DEFAULT (strftime('%s','now'))";
                        }
                        // 如果是纯数字则直接使用数字
                        if (is_numeric($d)) return "DEFAULT {$d}";
                        // 其它当作常量字符串
                        $escaped = str_replace("'", "''", $d);
                        return "DEFAULT '{$escaped}'";
                    }

                    // 如果看起来像函数调用或表达式（包含括号或空格），则不加引号
                    if (str_contains($d, '(') || str_contains($lower, 'current_timestamp') || str_contains($lower, 'strftime')) {
                        return "DEFAULT {$d}";
                    }
                    // 数字不加引号
                    if (is_numeric($d)) return "DEFAULT {$d}";
                    // 其它作为字符串，转义单引号
                    $escaped = str_replace("'", "''", $d);
                    return "DEFAULT '{$escaped}'";
                };

                // 类型规范化函数：对 sqlite 不支持的类型格式做兼容处理（如 INTEGER(0) -> INTEGER）
                $normalizeType = function (string $rawType): string {
                    $t = trim($rawType);
                    if ($t === '') return 'TEXT';
                    $lower = strtolower($t);
                    // 去掉字段类型后面的长度，但保留 VARCHAR(60) 等可接受类型；对 integer 系列强制为 INTEGER
                    if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)\b/i', $t)) {
                        return 'INTEGER';
                    }
                    // timestamp/datetime 由上层判断并处理为 INTEGER
                    return $t;
                };

                // 对已有列，优先使用 alter_fields 的目标定义（如果指定为 primary key auto_increment，则强制使用 INTEGER PRIMARY KEY AUTOINCREMENT）
                $processedNewNames = [];
                foreach ($table_fields as $tf) {
                    $fname = $tf['Field'] ?? ($tf['name'] ?? '');

                    // 原始类型与判断
                    $rawType = $tf['Type'] ?? 'TEXT';
                    $typeLower = strtolower((string)$rawType);
                    $isTimestampLike = str_contains($typeLower, 'timestamp') || str_contains($typeLower, 'datetime');

                    // 是否允许空
                    $null = ($tf['Null'] === 'YES') ? '' : 'NOT NULL';

                    // 原始默认值处理（优先使用清洗函数）
                    $defaultRaw = $tf['Default'] ?? null;
                    $defaultSql = $sanitizeDefault($defaultRaw, $isTimestampLike);

                    if (isset($this->alter_fields[$fname])) {
                        $spec = $this->alter_fields[$fname];
                        $newName = $spec['field_name'];
                        $processedNewNames[] = $newName;
                        $opts = strtolower($spec['options'] ?? '');
                        if (str_contains($opts, 'primary key') && str_contains($opts, 'auto_increment')) {
                            // 强制把该列声明为 sqlite 的主键自增
                            $newColumnDefs[] = "`{$newName}` INTEGER PRIMARY KEY AUTOINCREMENT";
                            continue;
                        } else {
                            // spec 中可能指定了类型为 timestamp/datetime，优先做兼容转换
                            $specType = $spec['type_length'] ?: $rawType;
                            $specTypeLower = strtolower((string)$specType);
                            if (str_contains($specTypeLower, 'timestamp') || str_contains($specTypeLower, 'datetime')) {
                                $specTypeSql = 'INTEGER';
                                // 对时间类型使用清洗后的默认值（如果是函数会被转换为 strftime 表达式）
                                $newColumnDefs[] = "`{$newName}` {$specTypeSql} {$null} {$defaultSql}";
                            } else {
                                // 规范化类型（去掉 integer(0) 等）
                                $norm = $normalizeType($specType);
                                $newColumnDefs[] = "`{$newName}` {$norm} {$null} {$defaultSql}";
                            }
                            continue;
                        }
                    } else {
                        // 常规已有列
                        // 若列类型为 timestamp/datetime，则使用 INTEGER 存 unix ts
                        if ($isTimestampLike) {
                            $colTypeSql = 'INTEGER';
                        } else {
                            $colTypeSql = $normalizeType((string)$rawType);
                        }
                        $newColumnDefs[] = "`{$fname}` {$colTypeSql} {$null} {$defaultSql}";
                        $processedNewNames[] = $fname;
                    }
                }
                // 对于 alter_fields 中新增的目标列（原来不在表中的），追加默认类型（使用传入的 type_length 或 TEXT）
                foreach ($this->alter_fields as $old => $spec) {
                    $newName = $spec['field_name'];
                    if (!in_array($newName, $processedNewNames, true)) {
                        $typeDef = $spec['type_length'] ?: 'TEXT';
                        $typeDefLower = strtolower((string)$typeDef);
                        if (str_contains($typeDefLower, 'timestamp') || str_contains($typeDefLower, 'datetime')) {
                            $typeDefSql = 'INTEGER';
                        } else {
                            $typeDefSql = $normalizeType((string)$typeDef);
                        }
                        // 未指定 NULL/DEFAULT 的，允许 NULL
                        $newColumnDefs[] = "`{$newName}` {$typeDefSql}";
                        $processedNewNames[] = $newName;
                    }
                }

                // 生成并执行重建表 SQL 序列
                $tmpTable = $originTable . '_tmp_' . uniqid();
                $selectColsSql = implode(',', $selectCols);

                $sqls = [];
                $sqls[] = "PRAGMA foreign_keys=OFF;";
                $sqls[] = "BEGIN TRANSACTION;";
                // 使用 CREATE TABLE AS SELECT 直接基于映射创建临时表，避免复杂的列定义语法
                $sqls[] = "CREATE TABLE `{$tmpTable}` AS SELECT {$selectColsSql} FROM `{$originTable}`;";
                $sqls[] = "DROP TABLE `{$originTable}`;";
                $sqls[] = "ALTER TABLE `{$tmpTable}` RENAME TO `{$originTable}`;";
                $sqls[] = "COMMIT;";
                $sqls[] = "PRAGMA foreign_keys=ON;";

                foreach ($sqls as $sql) {
                    if ($dump_sql) {
                        $dump_sqls[] = $sql;
                        continue;
                    }
                    try {
                        $this->query($sql)->fetch();
                    } catch (\Exception $exception) {
                        exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                    }
                }

                // 执行后清空 alter_fields，刷新表结构
                $this->alter_fields = [];
                $table_fields = $this->getTableColumns();
            }

            # 字段编辑：对于剩余（如果有）alter_fields不再执行 CHANGE/MODIFY（已处理）
            foreach ($table_fields as $table_field) {
                # --如果存在修改数组中则修改 暂不删除字段，以免修改字段异常，先修改后删除
                if (isset($this->alter_fields[$table_field['Field']]) && $alter_field = $this->alter_fields[$table_field['Field']]) {
                    if ($table_field['Field'] !== $alter_field['field_name']) {
                        $field_action = "CHANGE `{$table_field['Field']}` `{$alter_field['field_name']}`";
                    } else {
                        $field_action = "MODIFY COLUMN `{$table_field['Field']}`";
                    }
                    # --与数据库中的字段类型 比较
                    $type_length = $table_field['Type'];
                    if (!is_int(strpos($table_field['Type'], $alter_field['type_length']))) {
                        $type_length = $alter_field['type_length'];
                    }
                    # --与数据库中的字段评论 比较
                    $comment = $table_field['Comment'];
                    if ($alter_field['comment'] && ($table_field['Comment'] !== $alter_field['comment'])) {
                        $comment = $alter_field['comment'];
                    }
                    # --与数据库中的字段其他参数 比较
                    $options = '';
                    if ($alter_options = $alter_field['options']) {
                        $options = $alter_options;
                    } else {
                        # --是否允许空
                        if ('YES' === $table_field['Null']) {
                            $options .= ' NULL ';
                        } else {
                            $options .= ' NOT NULL ';
                        }
                        # --默认值
                        if ($table_field['Default']) {
                            $options .= " DEFAULT '{$table_field['Default']}' ";
                        }
                        # --列索引键
                        if ($key = $table_field['Key']) {
                            $options .= match ($key) {
                                'PRI' => ' PRIMARY KEY ',
                                'UNI' => ' UNIQUE ',
                                'MUL' => ' ',
                            };
                        }
                        # --Extra额外参数
                        if ($Extra = $table_field['Extra']) {
                            $options .= $Extra;
                        }
                    }
                    # --检查字段排序
                    if ($this->primary_key === $alter_field['field_name']) {
                        $field_sort = 'FIRST';
                    } else {
                        $field_sort = $alter_field['after_field'] ? "AFTER `{$alter_field['after_field']}`" : '';
                    }
                    # --检测是更新字段名还是修改字段属性

                    $sql = "ALTER TABLE {$this->table} {$field_action} {$type_length} {$options} COMMENT '{$comment}' {$field_sort}";
                    try {
                        if ($dump_sql) {
                            $dump_sqls[] = $sql;
                        } else {
                            $this->query($sql)->fetch();
                        }
                    } catch (\Exception $exception) {
                        exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                    }
                }
            }

            # 是否修改表名
            if ($this->new_table_name) {
                $sql = "ALTER TABLE {$this->table} RENAME TO {$this->new_table_name}";
                try {
                    if ($dump_sql) {
                        $dump_sqls[] = $sql;
                    } else {
                        $this->query($sql)->fetch();
                    }
                } catch (\Exception $exception) {
                    exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%{1}', $sql) . PHP_EOL);
                }
            }

        } catch (\Exception $exception) {
            exit($exception->getMessage());
        }
        if ($dump_sql) {
            dd($dump_sqls);
        }

        return true;
    }

    public function addForeignKey(string $FK_Name, string $FK_Field, string $references_table, string $references_field, bool $on_delete = false, bool $on_update = false): AlterInterface
    {
        $on_delete_str = $on_delete ? 'on delete cascade' : '';
        $on_update_str = $on_update ? 'on update cascade' : '';
        $this->foreign_keys[] = "constraint {$FK_Name} foreign key ({$FK_Field}) references {$references_table}({$references_field}) {$on_delete_str} {$on_update_str}";
        return $this;
    }
}