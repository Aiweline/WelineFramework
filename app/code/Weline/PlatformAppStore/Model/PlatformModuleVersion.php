<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '平台模块版本表')]
#[Index(name: 'idx_module_id', columns: ['module_id'], type: 'KEY', comment: '模块ID索引')]
#[Index(name: 'idx_version', columns: ['module_id', 'version'], type: 'UNIQUE', comment: '模块版本唯一索引')]
#[Index(name: 'idx_status', columns: ['status'], type: 'KEY', comment: '状态索引')]
class PlatformModuleVersion extends Model
{
    public const schema_table = 'weline_platform_module_version';
    public const schema_primary_key = 'version_id';

    // 版本状态常量
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DEPRECATED = 'deprecated';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '版本ID')]
    public const schema_fields_ID = 'version_id';

    #[Col(type: 'int', nullable: false, comment: '模块ID')]
    public const schema_fields_module_id = 'module_id';

    #[Col(type: 'varchar', length: 20, nullable: false, comment: '版本号')]
    public const schema_fields_version = 'version';

    #[Col(type: 'text', nullable: true, comment: '变更日志')]
    public const schema_fields_changelog = 'changelog';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '文件存储路径')]
    public const schema_fields_file_path = 'file_path';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: '文件哈希 (SHA256)')]
    public const schema_fields_file_hash = 'file_hash';

    #[Col(type: 'bigint', nullable: true, comment: '文件大小 (字节)')]
    public const schema_fields_file_size = 'file_size';

    #[Col(type: 'varchar', length: 20, nullable: true, comment: '兼容框架最低版本')]
    public const schema_fields_min_framework_version = 'min_framework_version';

    #[Col(type: 'varchar', length: 20, nullable: true, comment: '兼容框架最高版本')]
    public const schema_fields_max_framework_version = 'max_framework_version';

    #[Col(type: 'text', nullable: true, comment: '依赖模块 (JSON)')]
    public const schema_fields_dependencies = 'dependencies';

    #[Col(type: 'varchar', length: 20, nullable: false, default: self::STATUS_DRAFT, comment: '状态')]
    public const schema_fields_status = 'status';

    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    public function getVersionId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setVersionId(int $versionId): static
    {
        $this->setData(self::schema_fields_ID, $versionId);
        return $this;
    }

    public function getModuleId(): int
    {
        return (int)$this->getData(self::schema_fields_module_id);
    }

    public function setModuleId(int $moduleId): static
    {
        $this->setData(self::schema_fields_module_id, $moduleId);
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

    public function getChangelog(): ?string
    {
        return $this->getData(self::schema_fields_changelog);
    }

    public function setChangelog(?string $changelog): static
    {
        $this->setData(self::schema_fields_changelog, $changelog);
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

    public function getFileHash(): ?string
    {
        return $this->getData(self::schema_fields_file_hash);
    }

    public function setFileHash(?string $fileHash): static
    {
        $this->setData(self::schema_fields_file_hash, $fileHash);
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

    public function getMinFrameworkVersion(): ?string
    {
        return $this->getData(self::schema_fields_min_framework_version);
    }

    public function setMinFrameworkVersion(?string $version): static
    {
        $this->setData(self::schema_fields_min_framework_version, $version);
        return $this;
    }

    public function getMaxFrameworkVersion(): ?string
    {
        return $this->getData(self::schema_fields_max_framework_version);
    }

    public function setMaxFrameworkVersion(?string $version): static
    {
        $this->setData(self::schema_fields_max_framework_version, $version);
        return $this;
    }

    public function getDependencies(): array
    {
        $deps = $this->getData(self::schema_fields_dependencies);
        return $deps ? json_decode($deps, true) : [];
    }

    public function setDependencies(array $dependencies): static
    {
        $this->setData(self::schema_fields_dependencies, json_encode($dependencies, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function getStatus(): string
    {
        return $this->getData(self::schema_fields_status) ?? self::STATUS_DRAFT;
    }

    public function setStatus(string $status): static
    {
        $this->setData(self::schema_fields_status, $status);
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->getStatus() === self::STATUS_PUBLISHED;
    }
}
