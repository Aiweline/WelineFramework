<?php

declare(strict_types=1);

namespace Weline\FileManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '文件资源派生引用索引')]
#[Index(name: 'uk_file_asset_reference_hash', columns: ['reference_identity_hash'], type: 'UNIQUE', comment: '资源占用规范身份唯一')]
#[Index(name: 'idx_file_asset_reference_asset', columns: ['asset_id'], type: 'KEY', comment: '资源占用检索')]
#[Index(name: 'idx_file_asset_reference_owner', columns: ['owner_type', 'owner_id'], type: 'KEY', comment: '业务对象资源检索')]
class FileAssetReference extends Model
{
    public const schema_table = 'weline_file_asset_reference';
    public const schema_primary_key = 'reference_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '引用 ID')]
    public const schema_fields_ID = 'reference_id';
    #[Col(type: 'varchar', length: 36, nullable: false, comment: '资源 UUID')]
    public const schema_fields_ASSET_ID = 'asset_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '业务对象类型')]
    public const schema_fields_OWNER_TYPE = 'owner_type';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '业务对象 ID')]
    public const schema_fields_OWNER_ID = 'owner_id';
    #[Col(type: 'varchar', length: 512, nullable: false, default: '', comment: '规范 Scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';
    #[Col(type: 'varchar', length: 16, nullable: false, default: '', comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    #[Col(type: 'varchar', length: 512, nullable: false, comment: '字段路径')]
    public const schema_fields_FIELD_PATH = 'field_path';
    #[Col(type: 'int', length: 11, nullable: false, default: 1, comment: '业务版本')]
    public const schema_fields_OWNER_VERSION = 'owner_version';
    #[Col(type: 'char', length: 64, nullable: false, comment: '完整引用身份 SHA-256')]
    public const schema_fields_IDENTITY_HASH = 'reference_identity_hash';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ASSET_ID, self::schema_fields_OWNER_TYPE, self::schema_fields_OWNER_ID];

    public function save_before(): void
    {
        parent::save_before();
        $assetId = trim((string)$this->getData(self::schema_fields_ASSET_ID));
        $ownerType = trim((string)$this->getData(self::schema_fields_OWNER_TYPE));
        $ownerId = trim((string)$this->getData(self::schema_fields_OWNER_ID));
        $scopeKey = trim((string)$this->getData(self::schema_fields_SCOPE_KEY));
        $localeCode = trim((string)$this->getData(self::schema_fields_LOCALE_CODE));
        $fieldPath = trim((string)$this->getData(self::schema_fields_FIELD_PATH));
        $ownerVersion = (int)$this->getData(self::schema_fields_OWNER_VERSION);
        if (
            preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $assetId) !== 1
            || $ownerType === '' || strlen($ownerType) > 64 || preg_match('/[\x00-\x1F\x7F]/', $ownerType) === 1
            || $ownerId === '' || strlen($ownerId) > 128 || preg_match('/[\x00-\x1F\x7F]/', $ownerId) === 1
            || $scopeKey === '' || strlen($scopeKey) > 512 || preg_match('/[\x00-\x1F\x7F]/', $scopeKey) === 1
            || strlen($localeCode) > 16
            || ($localeCode !== '' && preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/D', $localeCode) !== 1)
            || $fieldPath === '' || strlen($fieldPath) > 512 || preg_match('/[\x00-\x1F\x7F]/', $fieldPath) === 1
            || $ownerVersion < 1
        ) {
            throw new \InvalidArgumentException((string)__('文件资源引用数据无效。'));
        }
        $this->setData(self::schema_fields_ASSET_ID, $assetId);
        $this->setData(self::schema_fields_OWNER_TYPE, $ownerType);
        $this->setData(self::schema_fields_OWNER_ID, $ownerId);
        $this->setData(self::schema_fields_SCOPE_KEY, $scopeKey);
        $this->setData(self::schema_fields_LOCALE_CODE, $localeCode);
        $this->setData(self::schema_fields_FIELD_PATH, $fieldPath);
        $this->setData(self::schema_fields_OWNER_VERSION, $ownerVersion);
        $this->setData(self::schema_fields_IDENTITY_HASH, hash('sha256', implode("\0", [
            $assetId,
            $ownerType,
            $ownerId,
            $scopeKey,
            $localeCode,
            $fieldPath,
            (string)$ownerVersion,
        ])));
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
    }
}
