<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/6/17 10:35:03
 */

namespace Weline\Index\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Contact extends \Weline\Framework\Database\Model
{
    public const fields_ID      = 'contact_id';
    public const fields_EMAIL   = 'email';
    public const fields_NAME    = 'name';
    public const fields_PHONE   = 'phone';
    public const fields_OBJECT  = 'object';
    public const fields_MESSAGE = 'message';
    public const indexer = 'weline_indexer';

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
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                  ->addColumn(
                      self::fields_ID,
                      TableInterface::column_type_INTEGER,
                      0,
                      'auto_increment primary key',
                      'ID'
                  )
                  ->addColumn(
                      self::fields_EMAIL,
                      TableInterface::column_type_VARCHAR,
                      255,
                      'not null unique',
                      '邮箱'
                  )
                  ->addColumn(
                      self::fields_NAME,
                      TableInterface::column_type_VARCHAR,
                      255,
                      'not null',
                      '称呼'
                  )
                  ->addColumn(
                      self::fields_PHONE,
                      TableInterface::column_type_VARCHAR,
                      32,
                      'not null',
                      '电话号码'
                  )
                  ->addColumn(
                      self::fields_OBJECT,
                      TableInterface::column_type_VARCHAR,
                      255,
                      'not null',
                      '主题'
                  )
                  ->addColumn(
                      self::fields_MESSAGE,
                      TableInterface::column_type_TEXT,
                      0,
                      'not null',
                      '内容'
                  )
                ->addIndex(
                    TableInterface::index_type_DEFAULT,
                    self::fields_PHONE,
                    'phone',
                    '电话唯一索引'
                )
                  ->create();
        }
    }
}
