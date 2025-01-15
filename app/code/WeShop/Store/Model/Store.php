<?php

namespace WeShop\Store\Model;

use WeShop\Store\Model\Store\LocalDescription;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Store extends Model
{
    public const fields_ID = 'store_id';
    public const fields_NAME = 'name';
    public const fields_CODE = 'code';
    public const fields_STATUS = 'status';
    public const fields_ADDRESS = 'address';
    public const fields_PHONE = 'phone';
    public const fields_EMAIL = 'email';
    public const fields_WEBSITE = 'website';
    public const fields_OPENING_HOURS = 'opening_hours';
    public const fields_CLOSING_HOURS = 'closing_hours';
    public const fields_DESCRIPTION = 'description';
    public const fields_IMAGE = 'image';
    public const fields_LATITUDE = 'latitude';
    public const fields_LONGITUDE = 'longitude';
    public const fields_LOCAL = 'local';

    public function addLocalDescription(): static
    {
        $lang = Cookie::getLang();
        $idField = $this::fields_ID;
        $this->joinModel(
            LocalDescription::class,
            'local',
            "main_table.{$idField}=local.{$idField} and local.local_code='$lang'",
            'left'
        );
        return $this;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('店铺表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '店铺ID'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'unique',
                    '店铺名称'
                )
                ->addColumn(
                    self::fields_CODE,
                    TableInterface::column_type_VARCHAR,
                    64,
                    'unique',
                    '店铺代码'
                )
                ->addColumn(
                    self::fields_OPENING_HOURS,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '营业时间',
                )
                ->addColumn(
                    self::fields_LATITUDE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '纬度',
                )
                ->addColumn(
                    self::fields_LONGITUDE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '经度',
                )
                ->addColumn(
                    self::fields_ADDRESS,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '地址',
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '描述',
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '状态',
                )
                ->addColumn(
                    self::fields_PHONE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '电话',
                )
                ->addColumn(
                    self::fields_EMAIL,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '邮箱',
                )
                ->addColumn(
                    self::fields_CLOSING_HOURS,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '关闭时间',
                )
                ->addColumn(
                    self::fields_WEBSITE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'unique',
                    '网站',
                )
                ->addColumn(
                    self::fields_IMAGE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '图片',
                )
                ->addColumn(
                    self::fields_LOCAL,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '默认区域码',
                )
                ->create();
        }
    }
}
