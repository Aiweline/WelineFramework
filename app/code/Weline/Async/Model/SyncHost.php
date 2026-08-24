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
 * 同步主机模型
 * @package Weline_Async
 */
#[Table(comment: '同步主机表')]
#[Index(name: 'idx_host', columns: ['host'], comment: '主机地址索引')]
#[Index(name: 'idx_name', columns: ['name'], comment: '主机名称索引')]
class SyncHost extends Model
{
    public const schema_table = 'async_sync_host';
    public const schema_primary_key = 'host_id';
/**
     * Primary key (property for base class compatibility)
     */
    public string $_primary_key = 'host_id';

    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['host_id'];

    /**
     * Field name constants
     */
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主机ID')]
    public const schema_fields_HOST_ID = 'host_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '主机名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '主机地址')]
    public const schema_fields_HOST = 'host';
    #[Col(type: 'int', nullable: true, default: 22, comment: 'SSH端口')]
    public const schema_fields_PORT = 'port';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: 'SSH用户名')]
    public const schema_fields_USER = 'user';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: 'SSH密码（加密存储）')]
    public const schema_fields_PASSWORD = 'password';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: 'SSH密钥路径（临时读取用）')]
    public const schema_fields_KEY_PATH = 'key_path'; // 保留用于临时读取文件
    #[Col(type: 'text', nullable: true, comment: 'SSH密钥内容（加密存储）')]
    public const schema_fields_KEY_CONTENT = 'key_content'; // SSH密钥内容（加密存储）
    #[Col(type: 'text', nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
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
        return self::schema_fields_HOST_ID;
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
        
        // 如果提供了密码，进行加密存储
        if ($this->hasData(self::schema_fields_PASSWORD) && !empty($this->getData(self::schema_fields_PASSWORD))) {
            $password = $this->getData(self::schema_fields_PASSWORD);
            // 如果密码不是已加密的格式，则加密
            if (!preg_match('/^encrypted:/', $password)) {
                $this->setData(self::schema_fields_PASSWORD, 'encrypted:' . base64_encode($password));
            }
        }
        
        // 如果提供了密钥内容，进行加密存储
        if ($this->hasData(self::schema_fields_KEY_CONTENT) && !empty($this->getData(self::schema_fields_KEY_CONTENT))) {
            $keyContent = $this->getData(self::schema_fields_KEY_CONTENT);
            // 如果密钥内容不是已加密的格式，则加密
            if (!preg_match('/^encrypted:/', $keyContent)) {
                $this->setData(self::schema_fields_KEY_CONTENT, 'encrypted:' . base64_encode($keyContent));
            }
        }
        
        // 保存后清除临时密钥路径（如果存在）
        if ($this->hasData(self::schema_fields_KEY_PATH)) {
            $this->unsetData(self::schema_fields_KEY_PATH);
        }
        
        return parent::beforeSave();
    }

    /**
     * 获取解密后的密码
     * 
     * @return string
     */
    public function getDecryptedPassword(): string
    {
        $password = $this->getData(self::schema_fields_PASSWORD);
        if (empty($password)) {
            return '';
        }
        if (preg_match('/^encrypted:(.+)$/', $password, $matches)) {
            return base64_decode($matches[1]);
        }
        return $password;
    }

    /**
     * 获取解密后的密钥内容
     * 
     * @return string
     */
    public function getDecryptedKeyContent(): string
    {
        $keyContent = $this->getData(self::schema_fields_KEY_CONTENT);
        if (empty($keyContent)) {
            return '';
        }
        if (preg_match('/^encrypted:(.+)$/', $keyContent, $matches)) {
            return base64_decode($matches[1]);
        }
        return $keyContent;
    }

    /**
     * 获取该主机下的所有映射
     * 
     * @return \Weline\Async\Model\SyncMapping[]
     */
    public function getMappings(): array
    {
        $mappingModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Async\Model\SyncMapping::class);
        return $mappingModel->where(\Weline\Async\Model\SyncMapping::schema_fields_HOST_ID, $this->getId())
            ->fetch()
            ->getItems();
    }
}
