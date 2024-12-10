<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\SqLite\Table;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\AbstractTable;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;

class Alter extends AbstractTable implements AlterInterface
{
    public string $additional = ';';
    public const init_vars = [
        self::table_TABLE         => '',
        self::table_COMMENT       => '',
        self::table_FIELDS        => [],
        self::table_ALERT_FIELDS  => [],
        self::table_DELETE_FIELDS => [],
        self::table_INDEXS        => [],
        self::table_FOREIGN_KEYS  => [],
        self::table_CONSTRAINTS   => '',
        self::table_ADDITIONAL    => ';',
    ];

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
     * @param string $type 字段类型
     * @param int|null $length 长度
     * @param string $options 配置
     * @param string $comment 字段注释
     *
     * @return AlterInterface
     */
    public function addColumn(string $field_name, string $after_column, string $type, ?int $length, string $options, string $comment): AlterInterface
    {
        $type_length    = $length ? "{$type}({$length})" : $type;
        $this->fields[] = "ADD COLUMN `{$field_name}` {$type_length} {$options} COMMENT '{$comment}' " . (empty($after_column) ? 'FIRST' : "AFTER `{$after_column}`");
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
                $column          = implode(',', $column);
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

    public function alterColumn(string $old_field, string $field_name, string $after_field = '', string $type = null, string|int|null $length = null, string $options = null, string $comment = null): AlterInterface
    {
        $not_need_length_types = [
            'text', 'mediumtext', 'longtext','tinytext','mediumblob','longblob','tinyblob','blob',
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
        # --如果存在删除数组中则先删除字段
        foreach ($this->delete_fields as $delete_field) {
            $sql = "ALTER TABLE {$this->table} DROP `{$delete_field}`";
            if ($dump_sql) {
                $dump_sqls[] = $sql;
            } else {
                try {
                    $this->query->query($sql)->fetch();
                } catch (\Exception $exception) {
                    exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%1', $sql) . PHP_EOL);
                }
            }
        }
        # --如果存在要新增的字段
        if ($this->fields) {
            $fields = join(',', $this->fields);
            $sql    = "ALTER TABLE {$this->table} $fields";
            if ($dump_sql) {
                $dump_sqls[] = $sql;
            } else {
                try {
                    $this->query->query($sql);
                } catch (\Exception $exception) {
                    exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%1', $sql) . PHP_EOL);
                }
            }
        }
        try {
            # 检测更新表注释
            $ddl               = $this->getCreateTableSql();
            $ddl_comment_array = explode('COMMENT=', $ddl);
            $comment           = str_replace('"', '', array_pop($ddl_comment_array));
            $comment           = str_replace('\'', '', $comment);
            # --检测存在评论，并且评论不相同时，更新表评论
            if ($this->comment && $comment !== $this->comment) {
                $sql = "ALTER TABLE {$this->table} COMMENT='{$this->comment}'";
                if ($dump_sql) {
                    $dump_sqls[] = $sql;
                } else {
                    try {
                        $this->query->query($sql)->fetch();
                    } catch (\Exception $exception) {
                        exit(__('更新表注释错误：%1', $exception->getMessage()) . PHP_EOL);
                    }
                }
            }
            $table_fields = $this->getTableColumns();
            # 字段编辑
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
                        exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%1', $sql) . PHP_EOL);
                    }
                }
            }

            # 是否修改表名
            if ($this->new_table_name) {
                $sql = "ALTER TABLE {$this->table} RENAME TO {$this->new_table_name}";
            }
            try {
                if ($dump_sql) {
                    $dump_sqls[] = $sql;
                } else {
                    $this->query->query($sql)->fetch();
                }
            } catch (\Exception $exception) {
                exit($exception->getMessage() . PHP_EOL . __('数据库SQL:%1', $sql) . PHP_EOL);
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
        $on_delete_str        = $on_delete ? 'on delete cascade' : '';
        $on_update_str        = $on_update ? 'on update cascade' : '';
        $this->foreign_keys[] = "constraint {$FK_Name} foreign key ({$FK_Field}) references {$references_table}({$references_field}) {$on_delete_str} {$on_update_str}";
        return $this;
    }
}
