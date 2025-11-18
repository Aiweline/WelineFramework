<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/30 16:53:17
 */

namespace Weline\Acl\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class WhiteAclSource extends \Weline\Framework\Database\Model
{
    public const fields_ID   = 'path';
    public const fields_PATH = 'path';
    public const fields_TYPE = 'type';
    
    public const type_PC = 'pc';
    public const type_API = 'api';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 添加type字段（如果不存在）
        if ($setup->tableExist() && !$setup->hasField(self::fields_TYPE)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_TYPE,
                    self::fields_PATH,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'not null default \'pc\'',
                    '类型：pc或api'
                )
                ->alter();
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                  ->addColumn(
                      self::fields_ID,
                      'varchar',
                      255,
                      'primary key',
                      '白名单链接路径')
                  ->addColumn(
                      self::fields_TYPE,
                      'varchar',
                      10,
                      'not null default \'pc\'',
                      '类型：pc或api')
                  ->create();
        } else {
            // 如果表已存在，检查是否需要添加type字段
            $this->upgrade($setup, $context);
        }
    }
    
    /**
     * 获取类型
     */
    public function getType(): string
    {
        return (string)($this->getData(self::fields_TYPE) ?? self::type_PC);
    }
    
    /**
     * 设置类型
     */
    public function setType(string $type): self
    {
        return $this->setData(self::fields_TYPE, $type);
    }
}