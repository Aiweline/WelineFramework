<?php

declare(strict_types=1);

namespace Weline\FileManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\Data\StorageObjectReference;

#[Table(comment: '统一文件资源')]
#[Index(name: 'uk_file_asset_object_hash', columns: ['object_identity_hash'], type: 'UNIQUE', comment: '规范磁盘对象身份唯一')]
#[Index(name: 'idx_file_asset_disk', columns: ['disk_code'], type: 'KEY', comment: '磁盘资源检索')]
#[Index(name: 'idx_file_asset_sha_mime', columns: ['sha256', 'mime_type'], type: 'KEY', comment: '内容检索')]
#[Index(name: 'idx_file_asset_deleted', columns: ['deleted_at'], type: 'KEY', comment: '软删除检索')]
class FileAsset extends Model
{
    public const schema_table = 'weline_file_asset';
    public const schema_primary_key = 'asset_id';

    public const STATE_DRAFT = 'draft';
    public const STATE_READY = 'ready';
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_PRIVATE = 'private';

    #[Col(type: 'varchar', length: 36, primaryKey: true, nullable: false, comment: '资源 UUID')]
    public const schema_fields_ID = 'asset_id';
    #[Col(type: 'varchar', length: 190, nullable: false, comment: '规范三段式磁盘代码')]
    public const schema_fields_DISK_CODE = 'disk_code';
    #[Col(type: 'varchar', length: 768, nullable: false, comment: '磁盘内对象键')]
    public const schema_fields_OBJECT_KEY = 'object_key';
    #[Col(type: 'char', length: 64, nullable: false, comment: 'disk_code + object_key 的 SHA-256')]
    public const schema_fields_OBJECT_IDENTITY_HASH = 'object_identity_hash';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '原文件名')]
    public const schema_fields_ORIGINAL_NAME = 'original_name';
    #[Col(type: 'varchar', length: 190, nullable: false, default: 'application/octet-stream', comment: 'MIME')]
    public const schema_fields_MIME_TYPE = 'mime_type';
    #[Col(type: 'bigint', length: 20, nullable: false, default: 0, comment: '字节数')]
    public const schema_fields_BYTES = 'bytes';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'SHA-256')]
    public const schema_fields_SHA256 = 'sha256';
    #[Col(type: 'int', length: 11, nullable: true, comment: '图片宽度')]
    public const schema_fields_WIDTH = 'width';
    #[Col(type: 'int', length: 11, nullable: true, comment: '图片高度')]
    public const schema_fields_HEIGHT = 'height';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: '默认语言')]
    public const schema_fields_DEFAULT_LOCALE = 'default_locale';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::VISIBILITY_PUBLIC, comment: '可见性')]
    public const schema_fields_VISIBILITY = 'visibility';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATE_DRAFT, comment: '资源生命周期')]
    public const schema_fields_LIFECYCLE_STATE = 'lifecycle_state';
    #[Col(type: 'int', length: 11, nullable: false, default: 1, comment: '资源修订号')]
    public const schema_fields_ASSET_REVISION = 'asset_revision';
    #[Col(type: 'text', nullable: true, comment: '扩展 metadata JSON')]
    public const schema_fields_METADATA = 'metadata';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col(type: 'datetime', nullable: true, comment: '软删除时间')]
    public const schema_fields_DELETED_AT = 'deleted_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_DISK_CODE, self::schema_fields_MIME_TYPE];

    public function getAssetId(): string { return (string)($this->getData(self::schema_fields_ID) ?: ''); }
    public function getDiskCode(): string { return (string)($this->getData(self::schema_fields_DISK_CODE) ?: ''); }
    public function getObjectKey(): string { return (string)($this->getData(self::schema_fields_OBJECT_KEY) ?: ''); }
    public function getMimeType(): string { return (string)($this->getData(self::schema_fields_MIME_TYPE) ?: 'application/octet-stream'); }
    public function getDefaultLocale(): string { return (string)($this->getData(self::schema_fields_DEFAULT_LOCALE) ?: ''); }
    public function getVisibility(): string { return (string)($this->getData(self::schema_fields_VISIBILITY) ?: self::VISIBILITY_PUBLIC); }
    public function isReady(): bool { return $this->getData(self::schema_fields_LIFECYCLE_STATE) === self::STATE_READY; }
    public function isDeleted(): bool { return trim((string)$this->getData(self::schema_fields_DELETED_AT)) !== ''; }

    public function save_before(): void
    {
        parent::save_before();
        $isNew = $this->getAssetId() === '';
        if ($isNew) {
            $this->setData(self::schema_fields_ID, self::uuidV4());
        }
        $diskCode = (string)$this->getData(self::schema_fields_DISK_CODE);
        $objectKey = (string)$this->getData(self::schema_fields_OBJECT_KEY);
        StorageDiskCode::parse($diskCode);
        StorageObjectReference::assertObjectKey($objectKey);
        $this->setData(self::schema_fields_OBJECT_IDENTITY_HASH, self::objectIdentityHash($diskCode, $objectKey));
        $originalName = trim((string)$this->getData(self::schema_fields_ORIGINAL_NAME));
        $mimeType = strtolower(trim((string)$this->getData(self::schema_fields_MIME_TYPE)));
        $sha256 = strtolower(trim((string)$this->getData(self::schema_fields_SHA256)));
        $defaultLocale = trim((string)$this->getData(self::schema_fields_DEFAULT_LOCALE));
        $width = $this->getData(self::schema_fields_WIDTH);
        $height = $this->getData(self::schema_fields_HEIGHT);
        $originalNameLength = function_exists('mb_strlen')
            ? mb_strlen($originalName, 'UTF-8')
            : strlen($originalName);
        if (
            $originalName === ''
            || preg_match('//u', $originalName) !== 1
            || $originalNameLength > 255
            || preg_match('/[\x00-\x1F\x7F]/', $originalName) === 1
            || preg_match('#^[a-z0-9][a-z0-9.+-]{0,94}/[a-z0-9][a-z0-9.+-]{0,94}$#', $mimeType) !== 1
            || (int)$this->getData(self::schema_fields_BYTES) < 0
            || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1
            || preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/', $defaultLocale) !== 1
            || ($width !== null && ((int)$width < 1 || (int)$width > 100000))
            || ($height !== null && ((int)$height < 1 || (int)$height > 100000))
        ) {
            throw new \InvalidArgumentException((string)__('文件资源基础元数据无效。'));
        }
        $this->setData(self::schema_fields_ORIGINAL_NAME, $originalName);
        $this->setData(self::schema_fields_MIME_TYPE, $mimeType);
        $this->setData(self::schema_fields_SHA256, $sha256);
        $this->setData(self::schema_fields_DEFAULT_LOCALE, $defaultLocale);
        $visibility = (string)$this->getData(self::schema_fields_VISIBILITY);
        if (!in_array($visibility, [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE], true)) {
            throw new \InvalidArgumentException((string)__('文件资源可见性无效。'));
        }
        $lifecycle = (string)$this->getData(self::schema_fields_LIFECYCLE_STATE);
        if (!in_array($lifecycle, [self::STATE_DRAFT, self::STATE_READY], true)) {
            throw new \InvalidArgumentException((string)__('文件资源生命周期无效。'));
        }
        $metadata = trim((string)$this->getData(self::schema_fields_METADATA));
        if (strlen($metadata) > 65535) {
            throw new \InvalidArgumentException((string)__('文件资源扩展元数据超过长度限制。'));
        }
        if ($metadata !== '') {
            try {
                $decoded = json_decode($metadata, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \InvalidArgumentException((string)__('文件资源扩展元数据 JSON 无效。'));
            }
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException((string)__('文件资源扩展元数据 JSON 无效。'));
            }
        }
        $now = date('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        $currentRevision = (int)$this->getData(self::schema_fields_ASSET_REVISION);
        $this->setData(
            self::schema_fields_ASSET_REVISION,
            $isNew ? 1 : max(1, $currentRevision + 1),
        );
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function objectIdentityHash(string $diskCode, string $objectKey): string
    {
        StorageDiskCode::parse($diskCode);
        StorageObjectReference::assertObjectKey($objectKey);
        return hash('sha256', $diskCode . "\0" . $objectKey);
    }
}
