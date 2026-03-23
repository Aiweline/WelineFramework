<?php

declare(strict_types=1);

namespace Weline\Bt\Center\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'BT server table')]
#[Index(name: 'idx_platform', columns: ['platform'], comment: 'Platform index')]
#[Index(name: 'idx_name', columns: ['name'], comment: 'Name index')]
#[Index(name: 'idx_is_enabled', columns: ['is_enabled'], comment: 'Monitoring enabled index')]
#[Index(name: 'idx_last_check_status', columns: ['last_check_status'], comment: 'Last check status index')]
class BtServer extends Model
{
    public const schema_table = 'weline_bt_server';
    public const schema_primary_key = 'server_id';

    public const PLATFORM_ALIYUN = 'aliyun';
    public const PLATFORM_AWS = 'aws';
    public const PLATFORM_AZURE = 'azure';
    public const PLATFORM_TENCENT = 'tencent';
    public const PLATFORM_HUAWEI = 'huawei';
    public const PLATFORM_OTHER = 'other';

    public const CHECK_STATUS_UNKNOWN = 'unknown';
    public const CHECK_STATUS_UP = 'up';
    public const CHECK_STATUS_DOWN = 'down';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Server ID')]
    public const schema_fields_SERVER_ID = 'server_id';

    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'Cloud platform')]
    public const schema_fields_PLATFORM = 'platform';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Server name')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'varchar', length: 500, nullable: false, comment: 'External panel URL')]
    public const schema_fields_EXTERNAL_URL = 'external_url';

    #[Col(type: 'varchar', length: 500, nullable: true, comment: 'Internal panel URL')]
    public const schema_fields_INTERNAL_URL = 'internal_url';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Username')]
    public const schema_fields_USERNAME = 'username';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Password')]
    public const schema_fields_PASSWORD = 'password';

    #[Col(type: 'int', nullable: true, default: 8888, comment: 'Panel port')]
    public const schema_fields_PORT = 'port';

    #[Col(type: 'text', nullable: true, comment: 'Description')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: 'Whether health monitoring is enabled')]
    public const schema_fields_IS_ENABLED = 'is_enabled';

    #[Col(type: 'datetime', nullable: true, comment: 'Last check time')]
    public const schema_fields_LAST_CHECK_AT = 'last_check_at';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::CHECK_STATUS_UNKNOWN, comment: 'Last check status')]
    public const schema_fields_LAST_CHECK_STATUS = 'last_check_status';

    #[Col(type: 'int', nullable: true, comment: 'Last HTTP status code')]
    public const schema_fields_LAST_HTTP_CODE = 'last_http_code';

    #[Col(type: 'int', nullable: true, comment: 'Last response time in ms')]
    public const schema_fields_LAST_RESPONSE_TIME_MS = 'last_response_time_ms';

    #[Col(type: 'text', nullable: true, comment: 'Last error message')]
    public const schema_fields_LAST_ERROR_MESSAGE = 'last_error_message';

    #[Col(type: 'datetime', nullable: true, comment: 'Last state changed at')]
    public const schema_fields_LAST_STATE_CHANGED_AT = 'last_state_changed_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public string $_primary_key = self::schema_fields_SERVER_ID;
    public array $_unit_primary_keys = [self::schema_fields_SERVER_ID];

    public static function getPlatformOptions(): array
    {
        return [
            self::PLATFORM_ALIYUN => __('阿里云'),
            self::PLATFORM_AWS => __('AWS'),
            self::PLATFORM_AZURE => __('微软 Azure'),
            self::PLATFORM_TENCENT => __('腾讯云'),
            self::PLATFORM_HUAWEI => __('华为云'),
            self::PLATFORM_OTHER => __('其他'),
        ];
    }

    public static function getHealthStatusOptions(): array
    {
        return [
            self::CHECK_STATUS_UNKNOWN => __('未检测'),
            self::CHECK_STATUS_UP => __('可访问'),
            self::CHECK_STATUS_DOWN => __('不可访问'),
        ];
    }

    public static function getHealthStatusLabel(string $status): string
    {
        $options = self::getHealthStatusOptions();
        return $options[$status] ?? $status;
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

        if ($this->getData(self::schema_fields_LAST_CHECK_STATUS) === null || $this->getData(self::schema_fields_LAST_CHECK_STATUS) === '') {
            $this->setData(self::schema_fields_LAST_CHECK_STATUS, self::CHECK_STATUS_UNKNOWN);
        }

        if ($this->getData(self::schema_fields_IS_ENABLED) === null || $this->getData(self::schema_fields_IS_ENABLED) === '') {
            $this->setData(self::schema_fields_IS_ENABLED, 1);
        }

        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
