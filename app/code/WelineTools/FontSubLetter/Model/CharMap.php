<?php

namespace WelineTools\FontSubLetter\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class CharMap extends Model
{
    public const table = 'weline_font_sub_letter_char_maps';
    public const fields_ID = 'id';
    public const fields_RECORD_ID = 'record_id';
    public const fields_CHAR_CODE = 'char_code';
    public const fields_CHAR_VALUE = 'char_value';
    public const fields_IS_INCLUDED = 'is_included';
    public const fields_CREATED_AT = 'created_at';

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 检查并添加缺失的字段
        $this->addMissingColumns($setup);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('字体字符映射')
                ->addColumn(self::fields_ID, TableInterface::column_type_BIGINT, 0, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_RECORD_ID, TableInterface::column_type_BIGINT, 0, 'not null', '记录ID')
                ->addColumn(self::fields_CHAR_CODE, TableInterface::column_type_INTEGER, 0, 'not null', '字符编码')
                ->addColumn(self::fields_CHAR_VALUE, TableInterface::column_type_VARCHAR, 10, 'not null', '字符值')
                ->addColumn(self::fields_IS_INCLUDED, TableInterface::column_type_INTEGER, 1, "default 1", '是否包含')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_INTEGER, 0, "default 0", '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_record_id', self::fields_RECORD_ID, '记录ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_char_code', self::fields_CHAR_CODE, '字符编码索引')
                ->create();
        } else {
            // 如果表已存在，添加缺失的字段
            $this->addMissingColumns($setup);
        }
    }

    private function addMissingColumns(ModelSetup $setup): void
    {
        // 暂时简化，通过setup:upgrade命令来添加字段
        // 这些字段将在下次setup:upgrade时自动添加
    }
}
