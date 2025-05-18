<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\Sqlite;

use Weline\Framework\Database\Helper\Tool;

trait SqlTrait
{
    use \Weline\Framework\Database\Connection\Api\Sql\SqlTrait;

    /**
     * @param string $sql
     * @return string|string[]
     */
    protected static function formatSql(string $sql): string|array
    {

        $sql = self::convertMySQLToSQLite($sql);
        if (str_contains($sql, 'truncate')) {
            $truncate_check_sqls = explode($sql, ';');
            foreach ($truncate_check_sqls as $truncate_check_sql_key => $truncate_check_sql) {
                if (str_contains($truncate_check_sql, 'truncate')) {
                    # 修改成sqlite支持的delete形式
                    $sql = str_replace('truncate', ' delete from ', $sql);
                    $truncate_check_sqls[$truncate_check_sql_key] = $sql;
                }
            }
            $sql = implode(';', $truncate_check_sqls);
        }
        if (str_contains($sql, 'curdate()-1')) {
            $sql = str_replace('curdate()-1', '\'now\', \'-1 day\'', $sql);
        }
        if (str_contains($sql, 'to_days')) {
            $sql = str_replace('to_days', 'DATE', $sql);
        }
        if (str_contains($sql, 'now()')) {
            $sql = str_replace('now()', '\'now\'', $sql);
        }
        if (str_contains($sql, 'order by order')) {
            $sql = str_replace('order by order', 'order by `order`', $sql);
        }
        if (str_contains($sql, 'order') and str_contains($sql, 'create')) {
            $sql = str_replace('order', '`order`', $sql);
            $sql = str_replace('``order``', '`order`', $sql);
        }
        if (str_contains($sql, '`order` by')) {
            $sql = str_replace('`order` by', 'order by', $sql);
        }

        if (str_contains($sql, 'set names utf8mb4;')) {
            $sql = str_replace('set names utf8mb4;', '', $sql);
        }
        if (str_contains($sql, 'set foreign_key_checks = 0;')) {
            $sql = str_replace('set foreign_key_checks = 0;', '', $sql);
        }
        if (str_contains($sql, ' set on ')) {
            $sql = str_replace(' set on ', ' `set` on ', $sql);
        }
        # 查询字段列表转化sqlite支持的模式
        if (str_contains($sql, 'show full columns from ')) {
            if (str_contains($sql, ';')) {
                $sql_arr = explode(';', $sql);
                foreach ($sql_arr as &$item) {
                    if (str_contains($item, 'show full columns from ')) {
                        $item = str_replace('show full columns from ', 'PRAGMA table_info(', $item) . ')';
                    }
                }
                $sql = implode(';', $sql_arr);
            } else {
                $sql = str_replace('show full columns from ', 'PRAGMA table_info(', $sql) . ');';
            }
        }
        # 开发环境记录sql日志文件，方便调试查看执行结果
        if (DEV) {
            $dev_log_base_dir = BP . '/var/log/dev/sql/';
            if (!is_dir($dev_log_base_dir)) {
                mkdir($dev_log_base_dir, 775, true);
            }
            file_put_contents($dev_log_base_dir . 'sql_last.sql', $sql);
            file_put_contents($dev_log_base_dir . 'sql_all.sql', $sql . PHP_EOL, FILE_APPEND);
        }
        return $sql;
    }

    public static function extractCreateTableStatements($sql)
    {
        // 正则表达式匹配以 CREATE TABLE 开头，直到分号结束的语句
        $pattern = '/CREATE\s+TABLE\s+`?(\w+)`?\s*\((.*?)\)?;/is';

        // 使用 preg_match_all 提取所有匹配项
        if (preg_match_all($pattern, $sql, $matches)) {
            // 返回表名和建表语句映射关系
            return array_combine($matches[1], $matches[0]);
        }

        // 如果没有找到匹配项，返回一个空数组
        return [];
    }

    public static function convertMySQLToSQLite($mysqlSql): string
    {
        $createTables = self::extractCreateTableStatements($mysqlSql);
        // 如果有创建表语句
        if ($createTables) {
            foreach ($createTables as $tableName => $createTable) {
                $deal_createTable = $createTable;
                // 更新字段处理
                if (str_contains($deal_createTable, '`update_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP')) {
                    $deal_createTable = str_replace('`update_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', '`update_time` INTEGER DEFAULT (strftime(\'%s\',\'now\'))', $deal_createTable);
                }
                // 更新字段处理
                if (str_contains($deal_createTable, '`create_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP')) {
                    $deal_createTable = str_replace('`create_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', '`create_time` INTEGER DEFAULT (strftime(\'%s\',\'now\'))', $deal_createTable);
                }
                // 处理主键声明 - SQLite对INTEGER PRIMARY KEY有特殊处理
                if (preg_match('/`?(\w+)`?\s+INTEGER\s+PRIMARY\s+KEY/i', $deal_createTable, $pkMatches)) {
                    $pkField = $pkMatches[1];
                    // 移除后续的PRIMARY KEY声明
                    $deal_createTable = preg_replace('/,\s*PRIMARY\s+KEY\s*\([^)]+\)/i', '', $deal_createTable);
                    // 确保字段定义和主键声明之间有逗号
                    $deal_createTable = preg_replace(
                        '/([^,]\s+)`?' . $pkField . '`?\s+INTEGER\s+PRIMARY\s+KEY\s+(AUTOINCREMENT)?/i',
                        '$1`' . $pkField . '` INTEGER PRIMARY KEY AUTOINCREMENT',
                        $deal_createTable
                    );
                } elseif (str_contains($deal_createTable, 'PRIMARY KEY AUTOINCREMENT')) {
                    // 处理非INTEGER类型的主键
                    $deal_createTable = str_replace('PRIMARY KEY AUTOINCREMENT', 'PRIMARY KEY', $deal_createTable);
                    // 确保主键声明前有逗号
                    $deal_createTable = preg_replace(
                        '/([^,]\s+)PRIMARY\s+KEY\s*\(([^)]+)\)/i',
                        '$1, PRIMARY KEY($2)',
                        $deal_createTable
                    );
                }
                // 删除类似 ROW_FORMAT = Dynamic
                $deal_createTable = preg_replace('/ROW_FORMAT\s*=\s*\w+/i', '', $deal_createTable);
                // 删除类似 COMMENT = '目录'
                $deal_createTable = preg_replace('/COMMENT\s*=\s*\'[^\']+\'/i', '', $deal_createTable);
                // 删除类似 COMMENT '目录'
                $deal_createTable = preg_replace('/COMMENT\s+\'[^\']+\'/i', '', $deal_createTable);
                // 删除类似 ENGINE = InnoDB
                $deal_createTable = preg_replace('/ENGINE\s*=\s*\w+/i', '', $deal_createTable);
                // 去除CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
                $deal_createTable = preg_replace('/CHARACTER\s+SET\s+\w+/i', '', $deal_createTable);
                // 去除CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci; 类似的语句，大小写不敏感
                $deal_createTable = preg_replace('/CHARACTER\s+SET\s*=\s*\w+\s+COLLATE\s*=\s*\w+/i', '', $deal_createTable);
                // 去除类似AUTO_INCREMENT = 14
                $deal_createTable = preg_replace('/AUTO_INCREMENT\s*=\s*\d+/i', '', $deal_createTable);
                // 移除COLLATE utf8mb4_general_ci
                $deal_createTable = preg_replace('/COLLATE\s+\w+/i', '', $deal_createTable);
                // AUTO_INCREMENT 转化成 PRIMARY KEY AUTOINCREMENT
                // 处理AUTO_INCREMENT - 只有当字段是INTEGER时才转换为AUTOINCREMENT
                if (preg_match('/`?(\w+)`?\s+INTEGER\s+/i', $deal_createTable)) {
                    $deal_createTable = str_replace('AUTO_INCREMENT', 'PRIMARY KEY AUTOINCREMENT', $deal_createTable);
                } else {
                    $deal_createTable = str_replace('AUTO_INCREMENT', '', $deal_createTable);
                }
                // 类似smallint(0)转化为INTEGER
                $deal_createTable = preg_replace('/\bSMALLINT\s*\(\s*0\s*\)/i', 'INTEGER', $deal_createTable);
                // SQLite使用单引号字符串，不需要转义
                $deal_createTable = preg_replace('/CURRENT_TIMESTAMP\(0\)/i', "(datetime('now'))", $deal_createTable);
                $deal_createTable = preg_replace('/CURRENT_TIMESTAMP/i', "(datetime('now'))", $deal_createTable);

                $deal_createTable = preg_replace('/ON UPDATE \(datetime\(\'now\'\)\)/i', "", $deal_createTable);
                // USING BTREE转化为USING INDEX
                $deal_createTable = preg_replace('/USING\s+BTREE/i', '', $deal_createTable);
                // 转换 CREATE TABLE 语句
                $deal_createTable = preg_replace('/`([^`]*)`/', '`$1`', $deal_createTable); // 替换反引号为双引号
                // 整数类型转换 - SQLite只有INTEGER类型
                $deal_createTable = preg_replace('/\bTINYINT\((\d+)\)\b/i', 'INTEGER', $deal_createTable);
                $deal_createTable = preg_replace('/\bSMALLINT\((\d+)\)\b/i', 'INTEGER', $deal_createTable);
                $deal_createTable = preg_replace('/\bMEDIUMINT\((\d+)\)\b/i', 'INTEGER', $deal_createTable);
                $deal_createTable = preg_replace('/\bINT\b/i', 'INTEGER', $deal_createTable);
                $deal_createTable = preg_replace('/\bBIGINT\b/i', 'INTEGER', $deal_createTable);
                // 处理无符号标记
                $deal_createTable = preg_replace('/\bINTEGER\s+UNSIGNED\b/i', 'INTEGER', $deal_createTable);
                $deal_createTable = preg_replace('/\bUNSIGNED\b/i', '', $deal_createTable);
                $deal_createTable = preg_replace('/\bVARCHAR\((\d+)\)/i', 'TEXT', $deal_createTable);
                $deal_createTable = preg_replace('/\bCHAR\((\d+)\)/i', 'TEXT', $deal_createTable);
                $deal_createTable = preg_replace('/\bTEXT\b/i', 'TEXT', $deal_createTable);
                $deal_createTable = preg_replace('/\bLONGTEXT\b/i', 'TEXT', $deal_createTable);
                $deal_createTable = preg_replace('/\bMEDIUMTEXT\b/i', 'TEXT', $deal_createTable);
                $deal_createTable = preg_replace('/\bTINYTEXT\b/i', 'TEXT', $deal_createTable);
                $deal_createTable = preg_replace('/\bDOUBLE\([^)]+\)/i', 'REAL', $deal_createTable);
                $deal_createTable = preg_replace('/\bFLOAT\([^)]+\)/i', 'REAL', $deal_createTable);
                $deal_createTable = preg_replace('/\bDECIMAL\([^)]+\)/i', 'REAL', $deal_createTable);
//              $deal_createTable = preg_replace('/\bAUTO_INCREMENT\b/i', 'AUTOINCREMENT', $deal_createTable);
                $deal_createTable = preg_replace('/\bZEROFILL\b/i', '', $deal_createTable);
                $deal_createTable = preg_replace('/ENGINE=\w+/i', '', $deal_createTable);
                $deal_createTable = preg_replace('/\bENUM\([^)]+\)/i', 'TEXT', $deal_createTable);

                // 提取索引定义并转换为单独的CREATE INDEX语句
                $indexStatements = [];
                if (preg_match_all('/,\s*(UNIQUE\s+(?:KEY|INDEX)\s+|KEY\s+|INDEX\s+)(`?\w+`?)\s*\(([^)]+)\)/i', $deal_createTable, $indexMatches)) {
                    foreach ($indexMatches[2] as $i => $indexName) {
                        $isUnique = str_contains($indexMatches[1][$i], 'UNIQUE');
                        $columns = $indexMatches[3][$i];
                        $indexName = trim($indexMatches[2][$i], '`');
                        // 规范化索引名称，避免特殊字符
                        $normalizedIndexName = preg_replace('/[^a-zA-Z0-9_]/', '_', $indexName);
                        $indexStatements[] = ($isUnique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') .
                            "`idx_{$tableName}_{$normalizedIndexName}` ON `{$tableName}` ({$columns}) /* 由MySQL KEY `{$indexName}` 转换 */";
                    }
                    // 从建表语句中完全移除索引定义
                    $deal_createTable = preg_replace('/,\s*(UNIQUE\s+)?(KEY|INDEX)\s+`?(\w+)`?\s*\(([^)]+)\)/i', '', $deal_createTable);
                }
                // 处理主键索引(非自增主键)
                if (preg_match('/,\s*PRIMARY\s+KEY\s*\(([^)]+)\)/i', $deal_createTable, $pkMatch) &&
                    !preg_match('/PRIMARY\s+KEY\s+AUTOINCREMENT/i', $deal_createTable)) {
                    $columns = $pkMatch[1];
                    $indexStatements[] = "CREATE UNIQUE INDEX `pk_{$tableName}` ON `{$tableName}` ({$columns}) /* 由MySQL PRIMARY KEY 转换 */";
                    // 从建表语句中完全移除主键定义
                    $deal_createTable = preg_replace('/,\s*PRIMARY\s+KEY\s*\(([^)]+)\)/i', '', $deal_createTable);
                }

                // 添加视图创建语句的转换
                if (preg_match('/CREATE\s+(OR\s+REPLACE\s+)?VIEW\s+`?(\w+)`?\s+AS/i', $mysqlSql)) {
                    $mysqlSql = preg_replace('/CREATE\s+(OR\s+REPLACE\s+)?VIEW/i', 'CREATE VIEW', $mysqlSql);
                }
                // 替换 CREATE TABLE 语句中的原始内容为处理后的内容，并立即附加索引语句
                $replacement = rtrim($deal_createTable, ';');
                if (!empty($indexStatements)) {
                    $replacement .= ";\n" . implode(";\n", $indexStatements);
                }
                // 精确匹配并替换建表语句，确保索引语句紧跟在后面
                $mysqlSql = preg_replace(
                    '/' . preg_quote($createTable, '/') . '(?![^;]*CREATE)/',
                    $replacement . ';',
                    $mysqlSql,
                    1
                );
            }
        }

        // 约束语句转化 SET FOREIGN_KEY_CHECKS = 0; 转化成 PRAGMA foreign_keys = OFF; 大小写不敏感
        $mysqlSql = preg_replace('/SET\s+FOREIGN_KEY_CHECKS\s*=\s*0;/i', 'PRAGMA foreign_keys = OFF;', $mysqlSql);
        // 约束语句转化 SET FOREIGN_KEY_CHECKS = 1; 转化成 PRAGMA foreign_keys = ON;大小写不敏感
        $mysqlSql = preg_replace('/SET\s+FOREIGN_KEY_CHECKS\s*=\s*1;/i', 'PRAGMA foreign_keys = ON;', $mysqlSql);

        // 去除SET NAMES utf8mb4;
        $mysqlSql = preg_replace('/SET\s+NAMES\s+\w+/i', '', $mysqlSql);

        // 将sql分割成一条一条的语句
//        if (str_contains($mysqlSql, 'developer_workspace_document')) {
//            // 如何正确提取每条sql语句，注意值中的分号不能当作结束标志
//            d($mysqlSql);
//            dd(Tool::extract_sql_statements($mysqlSql));
//        }


        $statements = Tool::extract_sql_statements($mysqlSql);
        $convertedStatements = [];

        foreach ($statements as $statement) {

            $statement = preg_replace('/LOCK\s+TABLES\s+.*;/i', '', $statement);
            $statement = preg_replace('/UNLOCK\s+TABLES;/i', '', $statement);
            // 转换 MySQL 函数到 SQLite 等效函数
            // SQLite使用单引号字符串，不需要转义
            $statement = preg_replace('/\bNOW\(\)/i', "datetime('now')", $statement);
            $statement = preg_replace('/\bCURDATE\(\)/i', "date('now')", $statement);
            $statement = preg_replace('/\bCURDATE\(\)\s*-\s*1\b/i', "date('now', '-1 day')", $statement);
            $statement = preg_replace('/\bTO_DAYS\(([^)]+)\)/i', "julianday($1)", $statement);
            // SQLite的group_concat默认使用逗号分隔，与MySQL一致，但需要确保已加载group_concat扩展
            $statement = preg_replace('/\bGROUP_CONCAT\(([^)]+)\)/i', "group_concat($1)", $statement);
            $statement = preg_replace('/\bGROUP_CONCAT\(([^)]+)\s+SEPARATOR\s+([^)]+)\)/i',
                "group_concat($1, $2)", $statement);
            $statement = preg_replace('/\bIFNULL\(([^)]+),\s*([^)]+)\)/i', "ifnull($1, $2)", $statement);
            $statement = preg_replace('/\bCOALESCE\(([^)]+)\)/i', "coalesce($1)", $statement);
            $statement = preg_replace('/\bDATE_FORMAT\(([^)]+),\s*([^)]+)\)/i', "strftime($2, $1)", $statement);
            $statement = preg_replace('/\bUNIX_TIMESTAMP\(([^)]+)\)/i', "strftime('%s', $1)", $statement);
            $statement = preg_replace('/\bFROM_UNIXTIME\(([^)]+)\)/i', "datetime($1, 'unixepoch')", $statement);

            // 转换 INSERT 语句变体
            $statement = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $statement);
            $statement = preg_replace('/\bREPLACE\s+INTO\b/i', 'INSERT OR REPLACE INTO', $statement);

            // 处理ON DUPLICATE KEY UPDATE语法
            if (preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.*)/i', $statement, $matches)) {
                $statement = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*/i', '', $statement);
                $update_part = preg_replace('/`([^`]*)`/', '"$1"', $matches[1]); // 转换反引号为双引号
                $statement .= ' ON CONFLICT DO UPDATE SET ' . $update_part;
            }

            // 处理LIMIT子句差异
            if (preg_match('/LIMIT\s+(\d+)\s*,\s*(\d+)/i', $statement, $matches)) {
                $statement = preg_replace('/LIMIT\s+\d+\s*,\s*\d+/i', "LIMIT {$matches[2]} OFFSET {$matches[1]}", $statement);
            }

            // 优化外键约束处理
            $statement = preg_replace('/CONSTRAINT\s+`?(\w+)`?\s+FOREIGN\s+KEY/i', 'FOREIGN KEY', $statement);
            $statement = preg_replace('/REFERENCES\s+`?(\w+)`?\s*\([^)]+\)\s*(ON\s+DELETE\s+(CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION))?/i',
                'REFERENCES $1 $2', $statement);

            // 转换 TRUNCATE 语句，支持带反引号和不带反引号的表名，并保持反引号
            if (preg_match('/^TRUNCATE\s+TABLE\s+`?(\w+)`?/i', $statement, $matches)) {
                // 将 TRUNCATE TABLE `menu` 或 TRUNCATE TABLE menu 转换为 DELETE FROM `menu`
                $statement = 'DELETE FROM `' . $matches[1] . '`';
            }

            // 转换 ORDER BY 中的关键字 order
            $statement = preg_replace('/ORDER\s+BY\s+(order)\b/i', 'ORDER BY `$1`', $statement);

            // 转换 SET 表名的情况
            if (preg_match('/FROM\s+SET\b/i', $statement)) {
                $statement = preg_replace('/FROM\s+(SET)\b/i', 'FROM `$1`', $statement);
            }
            if (preg_match('/JOIN\s+SET\b/i', $statement)) {
                $statement = preg_replace('/JOIN\s+(SET)\b/i', 'JOIN `$1`', $statement);
            }

            // 忽略 SET NAMES
            if (preg_match('/^SET\s+NAMES\s+\w+/i', $statement)) {
                $statement = ''; // 忽略 SET NAMES
            }

            // 兼容SHOW FULL COLUMNS FROM
            if (preg_match('/SHOW\s+FULL\s+COLUMNS\s+FROM/i', $statement)) {
                $statement = preg_replace('/SHOW\s+FULL\s+COLUMNS\s+FROM/i', 'PRAGMA table_info(', $statement) . ')';
            }

            // 过滤掉 AFTER 语法
            $statement = preg_replace('/AFTER\s+[^,;]+/i', '', $statement);

            // 过滤掉 AFTER 语法
            $statement = str_replace('`*`', '*', $statement);

            // 其他常见替换规则
            $convertedStatements[] = $statement;
        }

        // 处理所有需要ON UPDATE的字段
        $triggers = [];
        foreach ($createTables as $tableName => $createTable) {
            // 查找所有需要ON UPDATE的字段
            if (preg_match_all('/`(\w+)`\s+TIMESTAMP\s+.*?\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', $createTable, $matches)) {
                foreach ($matches[1] as $fieldName) {
                    $triggerName = "update_{$tableName}_{$fieldName}";
                    $triggerSql = "CREATE TRIGGER IF NOT EXISTS {$triggerName} AFTER UPDATE ON `{$tableName}` " .
                        "FOR EACH ROW BEGIN " .
                        "UPDATE `{$tableName}` SET `{$fieldName}` = strftime('%s','now') " .
                        "WHERE rowid = NEW.rowid; END;";
                    $triggers[] = $triggerSql;
                }
            }
        }

        // 返回转换后的 SQL 加上触发器
        $result = implode("\n", array_filter($convertedStatements));
        if (!empty($triggers)) {
            $result .= "\n" . implode("\n", $triggers);
        }
        // 确保最后只有一个分号
        $result = rtrim($result, ';') . ';';
        // 移除多余的空行
        $result = preg_replace('/;\s+;/', ';', $result);

        // 处理字符串值中的单引号转义
//        $result = str_replace("\'", "\''", $result);
        $result = str_replace("\\'", "''", $result);

        return $result;
    }

}
