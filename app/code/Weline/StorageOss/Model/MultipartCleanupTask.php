<?php

declare(strict_types=1);

namespace Weline\StorageOss\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\StorageOss\Service\OssMultipartCleanupSnapshotCodec;

#[Table(comment: 'OSS multipart 中止清理债务')]
#[Index(name: 'idx_oss_multipart_cleanup_due', columns: ['status', 'next_attempt_at', 'cleanup_task_id'], type: 'KEY')]
final class MultipartCleanupTask extends Model
{
    public const schema_table = 'weline_storage_oss_multipart_cleanup';
    public const schema_primary_key = 'cleanup_task_id';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DEAD = 'dead';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'cleanup_task_id';
    #[Col(type: 'varchar', length: 190, nullable: false, comment: '三段式磁盘代码')]
    public const schema_fields_DISK_CODE = 'disk_code';
    #[Col(type: 'int', length: 11, nullable: false, default: 1, comment: '发生债务时配置版本')]
    public const schema_fields_CONFIG_REVISION = 'config_revision';
    #[Col(type: 'text', nullable: false, comment: '加密的请求级 OSS 配置快照')]
    public const schema_fields_CONFIG_SNAPSHOT_REF = 'config_snapshot_ref';
    #[Col(type: 'varchar', length: 768, nullable: false, comment: '对象键')]
    public const schema_fields_OBJECT_KEY = 'object_key';
    #[Col(type: 'varchar', length: 512, nullable: false, comment: 'OSS multipart upload id')]
    public const schema_fields_UPLOAD_ID = 'upload_id';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATUS_PENDING)]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '处理租约令牌')]
    public const schema_fields_CLAIM_TOKEN = 'claim_token';
    #[Col(type: 'int', length: 11, nullable: false, default: 0)]
    public const schema_fields_ATTEMPTS = 'attempts';
    #[Col(type: 'datetime', nullable: true)]
    public const schema_fields_NEXT_ATTEMPT_AT = 'next_attempt_at';
    #[Col(type: 'varchar', length: 96, nullable: true, comment: '只存错误类型，不存异常原文')]
    public const schema_fields_LAST_ERROR_CODE = 'last_error_code';
    #[Col(type: 'datetime', nullable: false)]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false)]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col(type: 'datetime', nullable: true)]
    public const schema_fields_RESOLVED_AT = 'resolved_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_STATUS];

    public function save_before(): void
    {
        parent::save_before();
        StorageDiskCode::parse((string)$this->getData(self::schema_fields_DISK_CODE));
        StorageObjectReference::assertObjectKey((string)$this->getData(self::schema_fields_OBJECT_KEY));
        $uploadId = trim((string)$this->getData(self::schema_fields_UPLOAD_ID));
        $status = (string)$this->getData(self::schema_fields_STATUS);
        $snapshotRef = (string)$this->getData(self::schema_fields_CONFIG_SNAPSHOT_REF);
        $claimToken = trim((string)$this->getData(self::schema_fields_CLAIM_TOKEN));
        $errorCode = trim((string)$this->getData(self::schema_fields_LAST_ERROR_CODE));
        if (
            $uploadId === ''
            || strlen($uploadId) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $uploadId) === 1
            || !in_array($status, [
                self::STATUS_PENDING,
                self::STATUS_PROCESSING,
                self::STATUS_RESOLVED,
                self::STATUS_DEAD,
            ], true)
            || (int)$this->getData(self::schema_fields_CONFIG_REVISION) < 1
            || !\Weline\Framework\Http\Security\SecretRefCipher::isRef($snapshotRef)
            || strlen($snapshotRef) > OssMultipartCleanupSnapshotCodec::MAX_SEALED_REF_BYTES
            || ($status === self::STATUS_PROCESSING
                ? preg_match('/^[a-f0-9]{64}$/D', $claimToken) !== 1
                : $claimToken !== '')
            || (int)$this->getData(self::schema_fields_ATTEMPTS) < 0
            || strlen($errorCode) > 96
            || ($errorCode !== '' && preg_match('/^[A-Za-z0-9_.-]+$/D', $errorCode) !== 1)
        ) {
            throw new \InvalidArgumentException((string)__('OSS multipart 清理任务无效。'));
        }
        $this->setData(self::schema_fields_UPLOAD_ID, $uploadId);
        $this->setData(self::schema_fields_CLAIM_TOKEN, $claimToken !== '' ? $claimToken : null);
        $this->setData(self::schema_fields_LAST_ERROR_CODE, $errorCode !== '' ? $errorCode : null);
    }
}
