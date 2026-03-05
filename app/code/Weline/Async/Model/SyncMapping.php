<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Async\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 目录映射模型
 * @package Weline_Async
 */
#[Table(comment: '目录映射表')]
#[Index(name: 'idx_host_id', columns: ['host_id'], comment: '主机ID索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_local_path', columns: ['local_path'], comment: '本地路径索引')]
class SyncMapping extends Model
{
    public const schema_table = 'async_sync_mapping';
    public const schema_primary_key = 'mapping_id';
/**
     * Primary key (property for base class compatibility)
     */
    public string $_primary_key = 'mapping_id';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['mapping_id'];
    
    /**
     * Field name constants
     */
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '映射ID')]
    public const schema_fields_MAPPING_ID = 'mapping_id';
    #[Col(type: 'int', nullable: false, comment: '主机ID')]
    public const schema_fields_HOST_ID = 'host_id';
    #[Col(type: 'varchar', length: 1000, nullable: false, comment: '本地路径')]
    public const schema_fields_LOCAL_PATH = 'local_path';
    public const schema_fields_REMOTE_PATH = 'remote_path'; // 保留用于兼容，新数据使用 remote_paths
    public const schema_fields_REMOTE_PATHS = 'remote_paths'; // 多个远程路径（JSON数组）
    #[Col(type: 'text', nullable: true, comment: '排除模式（JSON数组）')]
    public const schema_fields_EXCLUDE_PATTERNS = 'exclude_patterns';
    #[Col(type: 'text', nullable: true, comment: '要同步的目录（JSON数组）')]
    public const schema_fields_INCLUDE_PATHS = 'include_paths';
    public const schema_fields_STATUS = 'status'; // 0=关闭, 1=开启
    #[Col(type: 'int', nullable: true, default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'int', nullable: true, default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    /**
     * 获取主键字段名
     * 
     * @return string
     */
    public function getIdFieldName(): string
    {
        return self::schema_fields_MAPPING_ID;
    }
    /**
     * 保存前处理
     * 
     * @return self
     */
    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        
        // 处理排除模式
        if ($this->hasData(self::schema_fields_EXCLUDE_PATTERNS)) {
            $patterns = $this->getData(self::schema_fields_EXCLUDE_PATTERNS);
            if (is_array($patterns)) {
                $this->setData(self::schema_fields_EXCLUDE_PATTERNS, json_encode($patterns, JSON_UNESCAPED_UNICODE));
            }
        }
        
        // 处理包含路径
        if ($this->hasData(self::schema_fields_INCLUDE_PATHS)) {
            $paths = $this->getData(self::schema_fields_INCLUDE_PATHS);
            if (is_array($paths)) {
                $this->setData(self::schema_fields_INCLUDE_PATHS, json_encode($paths, JSON_UNESCAPED_UNICODE));
            }
        }
        
        // 处理多个远程路径
        if ($this->hasData(self::schema_fields_REMOTE_PATHS)) {
            $remotePaths = $this->getData(self::schema_fields_REMOTE_PATHS);
            if (is_array($remotePaths)) {
                $this->setData(self::schema_fields_REMOTE_PATHS, json_encode($remotePaths, JSON_UNESCAPED_UNICODE));
                // 如果有多个远程路径，将第一个作为兼容的 remote_path
                if (!empty($remotePaths)) {
                    $this->setData(self::schema_fields_REMOTE_PATH, $remotePaths[0]);
                }
            }
        } elseif ($this->hasData(self::schema_fields_REMOTE_PATH) && !$this->hasData(self::schema_fields_REMOTE_PATHS)) {
            // 兼容旧数据：如果只有 remote_path，转换为 remote_paths
            $remotePath = $this->getData(self::schema_fields_REMOTE_PATH);
            if (!empty($remotePath)) {
                $this->setData(self::schema_fields_REMOTE_PATHS, json_encode([$remotePath], JSON_UNESCAPED_UNICODE));
            }
        }
        
        return parent::beforeSave();
    }
    /**
     * 获取排除模式数组
     * 
     * @return array
     */
    public function getExcludePatternsArray(): array
    {
        $patterns = $this->getData(self::schema_fields_EXCLUDE_PATTERNS);
        if (empty($patterns)) {
            return [];
        }
        if (is_string($patterns)) {
            $decoded = json_decode($patterns, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($patterns) ? $patterns : [];
    }
    /**
     * 设置排除模式数组
     * 
     * @param array $patterns
     * @return self
     */
    public function setExcludePatternsArray(array $patterns): self
    {
        $this->setData(self::schema_fields_EXCLUDE_PATTERNS, json_encode($patterns, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 获取包含路径数组
     * 
     * @return array
     */
    public function getIncludePathsArray(): array
    {
        $paths = $this->getData(self::schema_fields_INCLUDE_PATHS);
        if (empty($paths)) {
            return [];
        }
        if (is_string($paths)) {
            $decoded = json_decode($paths, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($paths) ? $paths : [];
    }
    /**
     * 设置包含路径数组
     * 
     * @param array $paths
     * @return self
     */
    public function setIncludePathsArray(array $paths): self
    {
        $this->setData(self::schema_fields_INCLUDE_PATHS, json_encode($paths, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 获取远程路径数组
     * 
     * @return array
     */
    public function getRemotePathsArray(): array
    {
        $paths = $this->getData(self::schema_fields_REMOTE_PATHS);
        if (!empty($paths)) {
            if (is_string($paths)) {
                $decoded = json_decode($paths, true);
                if (is_array($decoded) && !empty($decoded)) {
                    return $decoded;
                }
            } elseif (is_array($paths) && !empty($paths)) {
                return $paths;
            }
        }
        // 兼容旧数据：如果 remote_paths 为空，使用 remote_path
        $remotePath = $this->getData(self::schema_fields_REMOTE_PATH);
        if (!empty($remotePath)) {
            return [$remotePath];
        }
        return [];
    }
    /**
     * 设置远程路径数组
     * 
     * @param array $paths
     * @return self
     */
    public function setRemotePathsArray(array $paths): self
    {
        $this->setData(self::schema_fields_REMOTE_PATHS, json_encode($paths, JSON_UNESCAPED_UNICODE));
        // 同时设置第一个路径作为兼容的 remote_path
        if (!empty($paths)) {
            $this->setData(self::schema_fields_REMOTE_PATH, $paths[0]);
        }
        return $this;
    }
    /**
     * 检查是否开启
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (int)$this->getData(self::schema_fields_STATUS) === 1;
    }
    /**
     * 获取关联的主机
     * 
     * @return SyncHost|null
     */
    public function getHost(): ?SyncHost
    {
        $hostId = $this->getData(self::schema_fields_HOST_ID);
        if (empty($hostId)) {
            return null;
        }
        $hostModel = \Weline\Framework\Manager\ObjectManager::getInstance(SyncHost::class);
        return $hostModel->load($hostId);
    }
}
