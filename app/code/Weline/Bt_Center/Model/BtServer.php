<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Bt_Center\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 宝塔服务器模型
 * @package Weline_Bt_Center
 */
#[Table(comment: '宝塔服务器表')]
#[Index(name: 'idx_platform', columns: ['platform'], comment: '平台索引')]
#[Index(name: 'idx_name', columns: ['name'], comment: '名称索引')]
class BtServer extends Model
{
    public const schema_table = 'weline_bt_server';
    public const schema_primary_key = 'server_id';
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
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '服务器ID')]
    public const schema_fields_SERVER_ID = 'server_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '云平台：aliyun(阿里云)、aws、azure(微软Azure)、tencent(腾讯云)、huawei(华为云)、other(其他)')]
    public const schema_fields_PLATFORM = 'platform';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '服务器名称/标识')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 500, nullable: false, comment: '外网IPv4面板地址')]
    public const schema_fields_EXTERNAL_URL = 'external_url';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '内网面板地址')]
    public const schema_fields_INTERNAL_URL = 'internal_url';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '用户名')]
    public const schema_fields_USERNAME = 'username';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '密码')]
    public const schema_fields_PASSWORD = 'password';
    #[Col(type: 'int', nullable: true, default: 8888, comment: '端口号')]
    public const schema_fields_PORT = 'port';
    #[Col(type: 'text', nullable: true, comment: '备注描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
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
        $platform = (string)$this->getData(self::schema_fields_PLATFORM);
        $options = self::getPlatformOptions();
        return $options[$platform] ?? $platform;
    }
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    public function getIdFieldName(): string
    {
        return self::schema_fields_SERVER_ID;
    }
    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
