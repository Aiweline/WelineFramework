<?php
declare(strict_types=1);

namespace Weline\Trash\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '通用回收站记录表')]
#[Index(name: 'idx_trash_code_status_deleted', columns: ['trash_code', 'status', 'deleted_at'], type: 'KEY', comment: '类型状态查询索引')]
#[Index(name: 'idx_trash_code_entity_status', columns: ['trash_code', 'entity_key', 'status'], type: 'KEY', comment: '业务实体重复入箱检测索引')]
#[Index(name: 'idx_trash_status_deleted', columns: ['status', 'deleted_at'], type: 'KEY', comment: '回收站列表查询索引')]
class TrashItem extends Model
{
    public const schema_table = 'weline_trash_item';
    public const schema_primary_key = 'trash_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESTORE_FAILED = 'restore_failed';
    public const STATUS_PURGE_FAILED = 'purge_failed';
    public const STATUS_RESTORED = 'restored';
    public const STATUS_PURGED = 'purged';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_TRASH_CODE,
        self::schema_fields_ENTITY_KEY,
        self::schema_fields_STATUS,
    ];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '回收站ID')]
    public const schema_fields_ID = 'trash_id';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Trash provider code')]
    public const schema_fields_TRASH_CODE = 'trash_code';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Provider 显示名称')]
    public const schema_fields_PROVIDER_LABEL = 'provider_label';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '业务实体ID')]
    public const schema_fields_ENTITY_ID = 'entity_id';
    #[Col(type: 'varchar', length: 190, nullable: false, default: '', comment: '业务实体唯一键')]
    public const schema_fields_ENTITY_KEY = 'entity_key';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '回收站条目标题')]
    public const schema_fields_LABEL = 'label';
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_ACTIVE, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'longtext', nullable: true, comment: '摘要 JSON')]
    public const schema_fields_SUMMARY_JSON = 'summary_json';
    #[Col(type: 'longtext', nullable: true, comment: '业务原始数据 JSON')]
    public const schema_fields_RAW_DATA_JSON = 'raw_data_json';
    #[Col(type: 'longtext', nullable: true, comment: '作用域 JSON')]
    public const schema_fields_SCOPE_JSON = 'scope_json';
    #[Col(type: 'longtext', nullable: true, comment: '删除上下文 JSON')]
    public const schema_fields_CONTEXT_JSON = 'context_json';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Provider 数据版本')]
    public const schema_fields_PROVIDER_VERSION = 'provider_version';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '删除操作者')]
    public const schema_fields_DELETED_BY = 'deleted_by';
    #[Col(type: 'datetime', nullable: true, comment: '删除时间')]
    public const schema_fields_DELETED_AT = 'deleted_at';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '恢复操作者')]
    public const schema_fields_RESTORED_BY = 'restored_by';
    #[Col(type: 'datetime', nullable: true, comment: '恢复时间')]
    public const schema_fields_RESTORED_AT = 'restored_at';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '永久清理操作者')]
    public const schema_fields_PURGED_BY = 'purged_by';
    #[Col(type: 'datetime', nullable: true, comment: '永久清理时间')]
    public const schema_fields_PURGED_AT = 'purged_at';
    #[Col(type: 'text', nullable: true, comment: '最后错误信息')]
    public const schema_fields_LAST_ERROR = 'last_error';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '最后错误代码')]
    public const schema_fields_LAST_ERROR_CODE = 'last_error_code';
    #[Col(type: 'longtext', nullable: true, comment: '最后错误调试信息 JSON')]
    public const schema_fields_LAST_ERROR_DETAIL_JSON = 'last_error_detail_json';
    #[Col(type: 'datetime', nullable: true, comment: '最后恢复尝试时间')]
    public const schema_fields_LAST_RESTORE_ATTEMPT_AT = 'last_restore_attempt_at';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '最后恢复尝试操作者')]
    public const schema_fields_LAST_RESTORE_ATTEMPT_BY = 'last_restore_attempt_by';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getTrashId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function getTrashCode(): string
    {
        return (string)($this->getData(self::schema_fields_TRASH_CODE) ?: '');
    }

    public function getEntityKey(): string
    {
        return (string)($this->getData(self::schema_fields_ENTITY_KEY) ?: '');
    }

    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?: self::STATUS_ACTIVE);
    }

    public function isOpen(): bool
    {
        return in_array($this->getStatus(), [self::STATUS_ACTIVE, self::STATUS_RESTORE_FAILED, self::STATUS_PURGE_FAILED], true);
    }

    public function getSummary(): mixed
    {
        return $this->decodeJsonField(self::schema_fields_SUMMARY_JSON);
    }

    public function getRawData(): mixed
    {
        return $this->decodeJsonField(self::schema_fields_RAW_DATA_JSON);
    }

    public function getScopeData(): mixed
    {
        return $this->decodeJsonField(self::schema_fields_SCOPE_JSON);
    }

    public function getContextData(): mixed
    {
        return $this->decodeJsonField(self::schema_fields_CONTEXT_JSON);
    }

    public function getLastErrorDetail(): mixed
    {
        return $this->decodeJsonField(self::schema_fields_LAST_ERROR_DETAIL_JSON);
    }

    /**
     * @return array<string,mixed>
     */
    public function toApiArray(): array
    {
        return [
            'trash_id' => $this->getTrashId(),
            'trash_code' => $this->getTrashCode(),
            'provider_label' => (string)($this->getData(self::schema_fields_PROVIDER_LABEL) ?: ''),
            'entity_id' => (string)($this->getData(self::schema_fields_ENTITY_ID) ?: ''),
            'entity_key' => $this->getEntityKey(),
            'label' => (string)($this->getData(self::schema_fields_LABEL) ?: ''),
            'status' => $this->getStatus(),
            'summary' => $this->getSummary(),
            'raw_data' => $this->getRawData(),
            'scope' => $this->getScopeData(),
            'context' => $this->getContextData(),
            'provider_version' => (string)($this->getData(self::schema_fields_PROVIDER_VERSION) ?: ''),
            'deleted_by' => (string)($this->getData(self::schema_fields_DELETED_BY) ?: ''),
            'deleted_at' => (string)($this->getData(self::schema_fields_DELETED_AT) ?: ''),
            'restored_by' => (string)($this->getData(self::schema_fields_RESTORED_BY) ?: ''),
            'restored_at' => (string)($this->getData(self::schema_fields_RESTORED_AT) ?: ''),
            'purged_by' => (string)($this->getData(self::schema_fields_PURGED_BY) ?: ''),
            'purged_at' => (string)($this->getData(self::schema_fields_PURGED_AT) ?: ''),
            'last_error' => (string)($this->getData(self::schema_fields_LAST_ERROR) ?: ''),
            'last_error_code' => (string)($this->getData(self::schema_fields_LAST_ERROR_CODE) ?: ''),
            'last_error_detail' => $this->getLastErrorDetail(),
            'last_restore_attempt_at' => (string)($this->getData(self::schema_fields_LAST_RESTORE_ATTEMPT_AT) ?: ''),
            'last_restore_attempt_by' => (string)($this->getData(self::schema_fields_LAST_RESTORE_ATTEMPT_BY) ?: ''),
            'created_at' => (string)($this->getData(self::schema_fields_CREATED_AT) ?: ''),
            'updated_at' => (string)($this->getData(self::schema_fields_UPDATED_AT) ?: ''),
        ];
    }

    public function save_before(): void
    {
        parent::save_before();

        $now = date('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }

    private function decodeJsonField(string $field): mixed
    {
        $json = (string)($this->getData($field) ?: '');
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $json;
    }
}
