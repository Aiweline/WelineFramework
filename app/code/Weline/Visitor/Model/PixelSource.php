<?php

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class PixelSource extends Model
{
    public const fields_ID = 'pixel_source_id';
    public const fields_NAME = 'name';
    public const fields_CODE = 'code';
    public const fields_referer_domain_contains = 'referer_domain_contains'; # referer来源域名包含关键词，使用英语‘,’逗号隔开
    public const fields_DESCRIPTION = 'description';

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
        if ($setup->tableExist()) {
            return;
        }
        $setup->createTable('来源映射信息')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '来源映射ID'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '来源映射名称'
            )
            ->addColumn(
                self::fields_CODE,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '来源映射代码'
            )
            ->addColumn(
                self::fields_referer_domain_contains,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                'referer来源域名包含关键词，使用英语‘,’逗号隔开'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '描述'
            )
            // name 索引
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_pixel_source_name',
                self::fields_NAME,
                '名称索引'
            )
            // code 索引
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_pixel_source_code',
                self::fields_CODE,
                '代码索引'
            )
            ->create();

        # 默认数据
        $map = [
            'facebook' => [
                'name' => 'Facebook',
                'code' => 'facebook',
                'referer_domain_contains' => 'facebook',
                'description' => '来自Facebook的访客',
            ],
            'google' => [
                'name' => 'Google',
                'code' => 'google',
                'referer_domain_contains' => 'google',
                'description' => '来自Google的访客',
            ],
            'twitter' => [
                'name' => 'Twitter',
                'code' => 'twitter',
                'referer_domain_contains' => 'twitter',
                'description' => '来自Twitter的访客',
            ],
            'pinterest' => [
                'name' => 'Pinterest',
                'code' => 'pinterest',
                'referer_domain_contains' => 'pinterest',
                'description' => '来自Pinterest的访客',
            ],
            'instagram' => [
                'name' => 'Instagram',
                'code' => 'instagram',
                'referer_domain_contains' => 'instagram',
                'description' => '来自Instagram的访客',
            ],
            'linkedin' => [
                'name' => 'LinkedIn',
                'code' => 'linkedin',
                'referer_domain_contains' => 'linkedin',
                'description' => '来自LinkedIn的访客',
            ],
            'youtube' => [
                'name' => 'YouTube',
                'code' => 'youtube',
                'referer_domain_contains' => 'youtube',
                'description' => '来自YouTube的访客',
            ],
            'twitch' => [
                'name' => 'Twitch',
                'code' => 'twitch',
                'referer_domain_contains' => 'twitch',
                'description' => '来自Twitch的访客',
            ],
            'snapchat' => [
                'name' => 'Snapchat',
                'code' => 'snapchat',
                'referer_domain_contains' => 'snapchat',
                'description' => '来自Snapchat的访客',
            ],
            'tiktok' => [
                'name' => 'TikTok',
                'code' => 'tiktok',
                'referer_domain_contains' => 'tiktok',
                'description' => '来自TikTok的访客',
            ],
            'reddit' => [
                'name' => 'Reddit',
                'code' => 'reddit',
                'referer_domain_contains' => 'reddit',
                'description' => '来自Reddit的访客',
            ],
            'quora' => [
                'name' => 'Quora',
                'code' => 'quora',
                'referer_domain_contains' => 'quora',
                'description' => '来自Quora的访客',
            ],
            'medium' => [
                'name' => 'Medium',
                'code' => 'medium',
                'referer_domain_contains' => 'medium',
                'description' => '来自Medium的访客',
            ],
        ];
        $this->insert(array_values($map), 'name,code')->fetch();
    }
}