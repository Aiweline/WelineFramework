<?php
declare(strict_types=1);
/*
 * 备份记录模型
 * 
 * @author Weline Framework
 * @package Weline\Maintenance\Model
 */
namespace Weline\Maintenance\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '维护模式备份记录表')]
#[Index(name: 'idx_type', columns: ['backup_type'], comment: '备份类型索引')]
#[Index(name: 'idx_created', columns: ['created_at'], comment: '创建时间索引')]
class Backup extends Model
{
    public const schema_table = 'weline_maintenance_backup';
    public const schema_primary_key = 'backup_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '备份ID')]
    public const schema_fields_backup_id = 'backup_id';
    #[Col('varchar', 20, nullable: false, comment: '备份类型')]
    public const schema_fields_backup_type = 'backup_type';
    #[Col('varchar', 255, nullable: false, comment: '备份文件名')]
    public const schema_fields_backup_name = 'backup_name';
    #[Col('varchar', 500, nullable: false, comment: '备份文件路径')]
    public const schema_fields_file_path = 'file_path';
    #[Col('bigint', default: 0, comment: '文件大小(字节)')]
    public const schema_fields_file_size = 'file_size';
    #[Col('varchar', 20, default: 'completed', comment: '状态')]
    public const schema_fields_status = 'status';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col('int', default: 0, comment: '创建人ID')]
    public const schema_fields_created_by = 'created_by';
/**
     * 获取备份类型
     * 
     * @return string
     */
    public function getBackupType(): string
    {
        return $this->getData(self::schema_fields_backup_type) ?? '';
    }
    /**
     * 设置备份类型
     * 
     * @param string $type
     * @return $this
     */
    public function setBackupType(string $type): static
    {
        return $this->setData(self::schema_fields_backup_type, $type);
    }
    /**
     * 获取备份名称
     * 
     * @return string
     */
    public function getBackupName(): string
    {
        return $this->getData(self::schema_fields_backup_name) ?? '';
    }
    /**
     * 设置备份名称
     * 
     * @param string $name
     * @return $this
     */
    public function setBackupName(string $name): static
    {
        return $this->setData(self::schema_fields_backup_name, $name);
    }
    /**
     * 获取文件路径
     * 
     * @return string
     */
    public function getFilePath(): string
    {
        return $this->getData(self::schema_fields_file_path) ?? '';
    }
    /**
     * 设置文件路径
     * 
     * @param string $path
     * @return $this
     */
    public function setFilePath(string $path): static
    {
        return $this->setData(self::schema_fields_file_path, $path);
    }
    /**
     * 获取文件大小
     * 
     * @return int
     */
    public function getFileSize(): int
    {
        return (int)($this->getData(self::schema_fields_file_size) ?? 0);
    }
    /**
     * 设置文件大小
     * 
     * @param int $size
     * @return $this
     */
    public function setFileSize(int $size): static
    {
        return $this->setData(self::schema_fields_file_size, $size);
    }
    /**
     * 获取状态
     * 
     * @return string
     */
    public function getStatus(): string
    {
        return $this->getData(self::schema_fields_status) ?? 'pending';
    }
    /**
     * 设置状态
     * 
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): static
    {
        return $this->setData(self::schema_fields_status, $status);
    }
}
