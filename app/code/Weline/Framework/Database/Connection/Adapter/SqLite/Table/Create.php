<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Connection\Adapter\SqLite\Table;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Connection\Api\Sql\AbstractTable;
use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;

use function PHPUnit\Framework\exactly;

class Create extends AbstractTable implements CreateInterface
{
    public string $additional_for_sqlite = ';';
    public array $index_outs = [];
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
    public function createTable(string $table, string $comment = ''): CreateInterface
    {
        # 开始表操作
        $this->startTable($table, $comment);
        return $this;
    }

    public function addColumn(string $field_name, string $type, int|string|null $length, string $options, string $comment): CreateInterface
    {
        if (str_contains(strtolower($options), 'auto_increment')) {
            $options = str_replace('auto_increment', '', strtolower($options));
            if (!str_contains(strtolower($options), 'primary key')) {
                $options .= ' PRIMARY KEY';
            }
            $options .= ' AUTOINCREMENT';
            $auto_increment_types = [
                'tinyint',
                'int',
                'smallint',
                'mediumint',
                'bigint',
            ];
            if (in_array($type, $auto_increment_types)) {
                $type = 'integer';
            }
            if (str_contains($options, 'not null')) {
                $options = str_replace('not null', '', $options);
            }
        }
        if ('integer' == strtolower($type)) {
            $type_length               = $type;
        } else {
            $type_length               = $length ? "{$type}({$length})" : $type;
        }
        $this->fields[$field_name] = "`{$field_name}` {$type_length} {$options}";
        return $this;
    }


    public function addIndex(string $type, string $name, array|string $column, string $comment = '', string $index_method = ''): CreateInterface
    {
        # sqlite 不支持索引引擎指定  $index_method = $index_method ? "USING {$index_method}" : '';
        $index_method = '';
        $type         = strtoupper($type);
        if (is_array($column)) {
            $column = implode('`,`', $column);
        } elseif (is_string($column)) {
            $column = explode(',', $column);
            $column = implode('`,`', $column);
        }
        switch ($type) {
            case self::index_type_UNIQUE:
                $this->indexes[] = "UNIQUE (`{$column}`) {$index_method}";
                break;
            case self::index_type_DEFAULT:
            case self::index_type_FULLTEXT:
            case self::index_type_SPATIAL:
            case self::index_type_KEY:
                $this->index_outs[] = [
                    'name'   => $name,
                    'column' => $column,
                    'type'   => $type,
                    'method' => $index_method
                ];

                break;
            case self::index_type_MULTI:
                $type_of_column = getType($column);
                if (!is_array($column)) {
                    new Exception(self::index_type_MULTI . __('：此索引的column需要array类型,当前类型') . "{$type_of_column}" . ' 例如：[ID,NAME(19),AGE]');
                }
                $column          = implode(',', $column);
                $this->index_outs[] = [
                    'name'   => $name,
                    'column' => $column,
                    'type'   => $type,
                    'method' => $index_method
                ];
                break;
            default:
                new Exception(__('未知的索引类型：') . $type);
        }

        return $this;
    }


    public function addAdditional(string $additional_for_sqlite_sql = ';'): CreateInterface
    {
        # sqlite 不支持表后约束
        $this->additional_for_sqlite = '';

        return $this;
    }

    public function addConstraints(string $constraints = ''): CreateInterface
    {
        # sqlite 不支持using语法
        $constraints = str_replace('  ', ' ', $constraints);
        if (str_contains(strtolower($constraints), 'using btree')) {
            $constraints = str_replace('using btree', '', strtolower($constraints));
        }
        $this->constraints = $constraints;

        return $this;
    }


    public function addForeignKey(string $FK_Name, string $FK_Field, string $references_table, string $references_field, bool $on_delete = false, bool $on_update = false): CreateInterface
    {
        $on_delete_str        = $on_delete ? 'on delete cascade' : '';
        $on_update_str        = $on_update ? 'on update cascade' : '';
        $this->foreign_keys[] = "constraint {$FK_Name} foreign key ({$FK_Field}) references {$references_table}({$references_field}) {$on_delete_str} {$on_update_str}";
        return $this;
    }

    public function create(): mixed
    {
        // 字段
        if (!array_key_exists('`create_time`', $this->fields) && !array_key_exists('create_time', $this->fields)) {
            $this->fields['`create_time`'] = "`create_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ";
        }
        if (!array_key_exists('`update_time`', $this->fields) && !array_key_exists('update_time', $this->fields)) {
            $this->fields['`update_time`'] = "`update_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ";
        }
        $fields_str = implode(',' . PHP_EOL, $this->fields);
        $fields_str = rtrim($fields_str, PHP_EOL);
        // 索引 sqlite不支持表内设置index需要移出来，放到最后
        $index_outs = [];
        $indexes_str = implode(',' . PHP_EOL, $this->indexes);
        $indexes_str = rtrim($indexes_str, PHP_EOL);
        // 外键
        $foreign_key_str = implode(',' . PHP_EOL, $this->foreign_keys);
        $foreign_key_str = rtrim($foreign_key_str, PHP_EOL);
        // 组装结尾逗号
        if ($this->indexes) {
            $fields_str .= ',';
        }
        if ($this->foreign_keys) {
            $indexes_str .= ',';
        }
        if ($this->constraints) {
            $foreign_key_str .= ',';
        }
        # 没有additional_for_sqlite时默认配置default charset utf8mb4 collate utf8mb4_general_ci
        if (!empty($this->additional_for_sqlite)) {
            $this->additional_for_sqlite = str_replace(';', '', $this->additional_for_sqlite);
            $this->additional_for_sqlite .= ';';
        } else {
            $this->additional_for_sqlite = ";";
        }

        # 外置索引
        $index_outs = '';
        if (!empty($this->index_outs)) {
            foreach ($this->index_outs as $key => $value) {
                $index_outs .= "CREATE INDEX {$value['name']} ON {$this->table} ({$value['column']}) {$value['method']};\n";
            }
        }

        $sql = <<<createSQL
CREATE TABLE {$this->table}(
 {$fields_str}
 {$indexes_str}
 {$foreign_key_str}
 {$this->constraints}                 
) {$this->additional_for_sqlite};
$index_outs
createSQL;
        try {
            $result = $this->query($sql)->fetch();
        } catch (\Exception $exception) {
            throw new Exception(__('创建表失败，' . PHP_EOL . PHP_EOL . 'SQL：%1 ' . PHP_EOL . PHP_EOL . 'ERROR：%2', [$sql, $exception->getMessage()]));
        }
        return $result;
    }
}
