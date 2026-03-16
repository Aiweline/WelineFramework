<?php
declare(strict_types=1);

namespace Weline\AppStore\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AppStore 下载日志表')]
#[Index(name: 'idx_module_name', columns: ['module_name'], type: 'KEY', comment: '模块名索引')]
#[Index(name: 'idx_license_key', columns: ['license_key'], type: 'KEY', comment: '许可证密钥索引')]
#[Index(name: 'idx_status', columns: ['status'], type: 'KEY', comment: '状态索引')]
#[Index(name: 'idx_download_at', columns: ['download_at'], type: 'KEY', comment: '下载时间索引')]
class AppStoreDownloadLog extends Model
{
    public const schema_table = 'weline_appstore_download_log';
    public const schema_primary_key = 'log_id';

    // 下载状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_ID = 'log_id';

    #[Col(type: 'varchar', length: 100, nullable: false, comment: '模块名 (Vendor_Module)')]
    public const schema_fields_module_name = 'module_name';

    #[Col(type: 'varchar', length: 20, nullable: false, comment: '下载版本')]
    public const schema_fields_version = 'version';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: '许可证密钥')]
    public const schema_fields_license_key = 'license_key';

    #[Col(type: 'varchar', length: 20, nullable: false, default: self::STATUS_PENDING, comment: '下载状态')]
    public const schema_fields_status = 'status';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '文件路径')]
    public const schema_fields_file_path = 'file_path';

    #[Col(type: 'bigint', nullable: true, comment: '文件大小 (字节)')]
    public const schema_fields_file_size = 'file_size';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: '文件哈希')]
    public const schema_fields_file_hash = 'file_hash';

    #[Col(type: 'text', nullable: true, comment: '错误信息')]
    public const schema_fields_error_message = 'error_message';

    #[Col(type: 'varchar', length: 45, nullable: true, comment: '下载IP')]
    public const schema_fields_download_ip = 'download_ip';

    #[Col(type: 'datetime', nullable: true, comment: '下载时间')]
    public const schema_fields_download_at = 'download_at';

    #[Col(type: 'datetime', nullable: true, comment: '完成时间')]
    public const schema_fields_completed_at = 'completed_at';

    public function getLogId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setLogId(int $logId): static
    {
        $this->setData(self::schema_fields_ID, $logId);
        return $this;
    }

    public function getModuleName(): string
    {
        return $this->getData(self::schema_fields_module_name) ?? '';
    }

    public function setModuleName(string $moduleName): static
    {
        $this->setData(self::schema_fields_module_name, $moduleName);
        return $this;
    }

    public function getVersion(): string
    {
        return $this->getData(self::schema_fields_version) ?? '';
    }

    public function setVersion(string $version): static
    {
        $this->setData(self::schema_fields_version, $version);
        return $this;
    }

    public function getLicenseKey(): ?string
    {
        return $this->getData(self::schema_fields_license_key);
    }

    public function setLicenseKey(?string $licenseKey): static
    {
        $this->setData(self::schema_fields_license_key, $licenseKey);
        return $this;
    }

    public function getStatus(): string
    {
        return $this->getData(self::schema_fields_status) ?? self::STATUS_PENDING;
    }

    public function setStatus(string $status): static
    {
        $this->setData(self::schema_fields_status, $status);
        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->getData(self::schema_fields_file_path);
    }

    public function setFilePath(?string $filePath): static
    {
        $this->setData(self::schema_fields_file_path, $filePath);
        return $this;
    }

    public function getFileSize(): int
    {
        return (int)$this->getData(self::schema_fields_file_size);
    }

    public function setFileSize(int $fileSize): static
    {
        $this->setData(self::schema_fields_file_size, $fileSize);
        return $this;
    }

    public function getFileHash(): ?string
    {
        return $this->getData(self::schema_fields_file_hash);
    }

    public function setFileHash(?string $fileHash): static
    {
        $this->setData(self::schema_fields_file_hash, $fileHash);
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->getData(self::schema_fields_error_message);
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->setData(self::schema_fields_error_message, $errorMessage);
        return $this;
    }

    public function getDownloadIp(): ?string
    {
        return $this->getData(self::schema_fields_download_ip);
    }

    public function setDownloadIp(?string $ip): static
    {
        $this->setData(self::schema_fields_download_ip, $ip);
        return $this;
    }

    public function getDownloadAt(): ?string
    {
        return $this->getData(self::schema_fields_download_at);
    }

    public function setDownloadAt(?string $downloadAt): static
    {
        $this->setData(self::schema_fields_download_at, $downloadAt);
        return $this;
    }

    public function getCompletedAt(): ?string
    {
        return $this->getData(self::schema_fields_completed_at);
    }

    public function setCompletedAt(?string $completedAt): static
    {
        $this->setData(self::schema_fields_completed_at, $completedAt);
        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->getStatus() === self::STATUS_SUCCESS;
    }

    public function markAsSuccess(string $filePath, int $fileSize, string $fileHash): static
    {
        $this->setStatus(self::STATUS_SUCCESS);
        $this->setFilePath($filePath);
        $this->setFileSize($fileSize);
        $this->setFileHash($fileHash);
        $this->setCompletedAt(date('Y-m-d H:i:s'));
        return $this;
    }

    public function markAsFailed(string $errorMessage): static
    {
        $this->setStatus(self::STATUS_FAILED);
        $this->setErrorMessage($errorMessage);
        $this->setCompletedAt(date('Y-m-d H:i:s'));
        return $this;
    }
}
