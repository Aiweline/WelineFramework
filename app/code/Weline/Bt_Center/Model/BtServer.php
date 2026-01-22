<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Bt_Center\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 宝塔服务器模型
 *
 * @package Weline_Bt_Center
 */
class BtServer extends Model
{
    public const table = 'weline_bt_server';

    /**
     * Primary key
     */
    public string $_primary_key = 'server_id';

    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['server_id'];

    /**
     * 字段常量
     */
    public const fields_SERVER_ID = 'server_id';
    public const fields_PLATFORM = 'platform';
    public const fields_NAME = 'name';
    public const fields_EXTERNAL_URL = 'external_url';
    public const fields_INTERNAL_URL = 'internal_url';
    public const fields_USERNAME = 'username';
    public const fields_PASSWORD = 'password';
    public const fields_PORT = 'port';
    public const fields_DESCRIPTION = 'description';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 平台常量
     */
    public const PLATFORM_ALIYUN = 'aliyun';
    public const PLATFORM_AWS = 'aws';
    public const PLATFORM_AZURE = 'azure';
    public const PLATFORM_TENCENT = 'tencent';
    public const PLATFORM_HUAWEI = 'huawei';
    public const PLATFORM_OTHER = 'other';

    /**
     * 获取所有平台选项
     */
    public static function getPlatformOptions(): array
    {
        return [
            self::PLATFORM_ALIYUN => __('阿里云'),
            self::PLATFORM_AWS => __('AWS'),
            self::PLATFORM_AZURE => __('微软Azure'),
            self::PLATFORM_TENCENT => __('腾讯云'),
            self::PLATFORM_HUAWEI => __('华为云'),
            self::PLATFORM_OTHER => __('其他'),
        ];
    }

    /**
     * 获取平台名称
     */
    public function getPlatformName(): string
    {
        $platform = (string)$this->getData(self::fields_PLATFORM);
        $options = self::getPlatformOptions();
        return $options[$platform] ?? $platform;
    }

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::fields_SERVER_ID;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('宝塔服务器表')
                ->addColumn(
                    self::fields_SERVER_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '服务器ID'
                )
                ->addColumn(
                    self::fields_PLATFORM,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '云平台：aliyun(阿里云)、aws、azure(微软Azure)、tencent(腾讯云)、huawei(华为云)、other(其他)'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '服务器名称/标识'
                )
                ->addColumn(
                    self::fields_EXTERNAL_URL,
                    TableInterface::column_type_VARCHAR,
                    500,
                    'not null',
                    '外网IPv4面板地址'
                )
                ->addColumn(
                    self::fields_INTERNAL_URL,
                    TableInterface::column_type_VARCHAR,
                    500,
                    '',
                    '内网面板地址'
                )
                ->addColumn(
                    self::fields_USERNAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '用户名'
                )
                ->addColumn(
                    self::fields_PASSWORD,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '密码'
                )
                ->addColumn(
                    self::fields_PORT,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 8888',
                    '端口号'
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '备注描述'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_platform',
                    [self::fields_PLATFORM],
                    '平台索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_name',
                    [self::fields_NAME],
                    '名称索引'
                )
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            return;
        }

        // 预留未来字段升级逻辑
    }

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
    }
}
