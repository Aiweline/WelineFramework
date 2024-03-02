<?php

namespace Aiweline\Index\Model\Backend;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Setting extends Model
{
    public const fields_ID = 'settings_id';

    public const fields_NAME = 'name';
    public const fields_KEY = 'key';

    public const fields_VALUE = 'value';
    public const fields_POSITION = 'position';


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
//        $setup->dropTable();
        if ($setup->tableExist()) {
            return;
        }
        $setup->createTable('设置表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'auto_increment primary key',
                '设置ID'
            )
            ->addColumn(
                self::fields_KEY,
                TableInterface::column_type_VARCHAR,
                255,
                'not null unique',
                '键'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null unique',
                '配置名'
            )
            ->addColumn(
                self::fields_VALUE,
                TableInterface::column_type_VARCHAR,
                255,
                'not null default ""',
                '值'
            )
            ->addColumn(
                self::fields_POSITION,
                TableInterface::column_type_VARCHAR,
                255,
                'not null default "header"',
                '位置'
            )
            ->create();
        // 默认配置
        $settings = [];
        // 全局
        $global = [
            [
                self::fields_NAME => '站点名称',
                self::fields_KEY => 'name',
                self::fields_VALUE => '成都阿玛云科技有限公司'
            ],
            [
                self::fields_NAME => '网站地址',
                self::fields_KEY => 'url',
                self::fields_VALUE => 'https://www.amayum.com'
            ],
            [
                self::fields_NAME => 'Logo',
                self::fields_KEY => 'logo',
                self::fields_VALUE => '/images/logo.png'
            ]
        ];
        $settings['global'] = $global;

        // 头部
        $header = [
            [
                self::fields_NAME => '背景',
                self::fields_KEY => 'background',
                self::fields_VALUE => '#f5f5f5'
            ],
            [
                self::fields_NAME => '字体颜色',
                self::fields_KEY => 'color',
                self::fields_VALUE => '#333'
            ],
            [
                self::fields_NAME => '字体大小',
                self::fields_KEY => 'front_size',
                self::fields_VALUE => '14px'
            ],
        ];
        $settings['header'] = $header;
        // 底部
        $footer = [
            [
                self::fields_NAME => '版权',
                self::fields_KEY => 'copyright',
                self::fields_VALUE => 'Copyright © 2021 成都阿玛云科技有限公司'
            ],
            [
                self::fields_NAME => '版权链接',
                self::fields_KEY => 'copyright_url',
                self::fields_VALUE => 'https://www.amayum.com'
            ]
        ];
        $settings['footer'] = $footer;

        // 写入默认配置
        foreach ($settings as $settings_group => $settings_list) {
            foreach ($settings_list as &$item) {
                $item[self::fields_POSITION] = $settings_group;
            }
            $this->insert($settings_list)->fetch();
        }
    }
}