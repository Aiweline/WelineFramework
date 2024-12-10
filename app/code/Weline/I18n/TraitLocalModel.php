<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/2 13:09:23
 */

namespace Weline\I18n;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

trait TraitLocalModel
{
    function __init()
    {
        parent::__init();
        if (!CLI) {
            $this->where(self::fields_local_code, Cookie::getLang(), '=', 'or')->where(self::fields_local_code . ' is null');
        }
    }

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
        if (!$setup->tableExist()) {
            $creatTable = $setup->createTable()
                ->addColumn(
                    $this::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    'ID'
                )
                ->addColumn(
                    self::fields_local_code,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'not null',
                    '当地码'
                )
                ->addColumn(
                    self::fields_name,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '当地名称'
                );
            # 其他翻译字段
            $not_in_fields = [
                $this::fields_ID,
                self::fields_local_code,
                self::fields_name,
                self::fields_CREATE_TIME,
                self::fields_UPDATE_TIME
            ];
            $modelFileds   = $this->getModelFields();
            foreach ($modelFileds as $key => $modelFiled) {
                if (!in_array($modelFiled, $not_in_fields)) {
                    $creatTable->addColumn(
                        $modelFiled,
                        TableInterface::column_type_TEXT,
                        200000,
                        '',
                        ''
                    );
                }
            }
            $creatTable->addConstraints('primary key (' . $this::fields_ID . ',' . self::fields_local_code . ')')
                ->create();
        }
    }

    public function getLocalCode()
    {
        return $this->getData(self::fields_local_code);
    }

    public function setLocalCode(string $local_code)
    {
        return $this->setData(self::fields_local_code, $local_code);
    }

    public function getName()
    {
        return $this->getData(self::fields_name);
    }

    public function setName(string $name)
    {
        return $this->setData(self::fields_name, $name);
    }
}