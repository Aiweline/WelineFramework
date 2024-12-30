<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\SqLite;

trait SqlTrait
{
    use \Weline\Framework\Database\Connection\Api\Sql\SqlTrait;

    /**
     * @param string $sql
     * @return string|string[]
     */
    protected static function formatSql(string $sql): string|array
    {

        return self::convertMySQLToSQLite($sql);
        # 正则提取sql中所有单引号之间的内容，转义后的不管

        $sql = self::parserFiled($sql);
        $sql = strtolower($sql);
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
        # order关键字段处理
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

    public static function convertMySQLToSQLite($mysqlSql)
    {
        $statements = array_filter(array_map('trim', explode(';', $mysqlSql)));
        $convertedStatements = [];

        foreach ($statements as $statement) {
            // 转换 CREATE TABLE 语句
            $statement = preg_replace('/`([^`]*)`/', '`$1`', $statement); // 替换反引号为双引号
            $statement = preg_replace('/\bTINYINT\b/i', 'INTEGER', $statement);
            $statement = preg_replace('/\bSMALLINT\b/i', 'INTEGER', $statement);
            $statement = preg_replace('/\bMEDIUMINT\b/i', 'INTEGER', $statement);
            $statement = preg_replace('/\bINT\b/i', 'INTEGER', $statement);
            $statement = preg_replace('/\bBIGINT\b/i', 'INTEGER', $statement);
            $statement = preg_replace('/\bUNSIGNED\b/i', '', $statement);
            $statement = preg_replace('/\bVARCHAR\((\d+)\)/i', 'TEXT', $statement);
            $statement = preg_replace('/\bCHAR\((\d+)\)/i', 'TEXT', $statement);
            $statement = preg_replace('/\bTEXT\b/i', 'TEXT', $statement);
            $statement = preg_replace('/\bDATETIME\b/i', 'TEXT', $statement);
            $statement = preg_replace('/\bTIMESTAMP\b/i', 'TEXT', $statement);
            $statement = preg_replace('/\bAUTO_INCREMENT\b/i', 'AUTOINCREMENT', $statement);
            $statement = preg_replace('/\bZEROFILL\b/i', '', $statement);
            $statement = preg_replace('/ENGINE=\w+/i', '', $statement);
            $statement = preg_replace('/DEFAULT\s+CURRENT_TIMESTAMP/i', "DEFAULT (datetime('now'))", $statement);
            $statement = preg_replace('/\bENUM\([^)]+\)/i', 'TEXT', $statement);
            $statement = preg_replace('/\bSET\([^)]+\)/i', 'TEXT', $statement);
            $statement = preg_replace('/LOCK\s+TABLES\s+.*;/i', '', $statement);
            $statement = preg_replace('/UNLOCK\s+TABLES;/i', '', $statement);

            // 转换 NOW(), CURDATE(), TO_DAYS()
            $statement = preg_replace('/\bNOW\(\)/i', "datetime('now')", $statement);
            $statement = preg_replace('/\bCURDATE\(\)/i', "date('now')", $statement);
            $statement = preg_replace('/\bCURDATE\(\)\s*-\s*1\b/i', "date('now', '-1 day')", $statement);
            $statement = preg_replace('/\bTO_DAYS\(([^)]+)\)/i', "julianday($1)", $statement);

            // 转换 INSERT IGNORE
            $statement = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $statement);

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

            // 检查 SQL 语句中是否包含 COMMENT 语法
            $statement = preg_replace('/COMMENT\s+\'[^\']*\'/i', '', $statement);

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

        // 返回转换后的 SQL
        return implode(";\n", array_filter($convertedStatements)) . ';';
    }


}
