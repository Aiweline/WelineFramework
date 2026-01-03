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
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * 同步主机模型
 * 
 * @package Weline_Async
 */
class SyncHost extends Model
{
    public const table = 'async_sync_host';
    
    /**
     * Primary key
     */
    public string $_primary_key = 'host_id';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['host_id'];
    
    /**
     * Field name constants
     */
    public const fields_HOST_ID = 'host_id';
    public const fields_NAME = 'name';
    public const fields_HOST = 'host';
    public const fields_PORT = 'port';
    public const fields_USER = 'user';
    public const fields_PASSWORD = 'password';
    public const fields_KEY_PATH = 'key_path'; // 保留用于临时读取文件
    public const fields_KEY_CONTENT = 'key_content'; // SSH密钥内容（加密存储）
    public const fields_DESCRIPTION = 'description';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

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
        return self::fields_HOST_ID;
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('同步主机表')
                ->addColumn(self::fields_HOST_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '主机ID')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '主机名称')
                ->addColumn(self::fields_HOST, TableInterface::column_type_VARCHAR, 255, 'not null', '主机地址')
                ->addColumn(self::fields_PORT, TableInterface::column_type_INTEGER, null, 'default 22', 'SSH端口')
                ->addColumn(self::fields_USER, TableInterface::column_type_VARCHAR, 100, 'not null', 'SSH用户名')
                ->addColumn(self::fields_PASSWORD, TableInterface::column_type_VARCHAR, 500, 'null', 'SSH密码（加密存储）')
                ->addColumn(self::fields_KEY_PATH, TableInterface::column_type_VARCHAR, 500, 'null', 'SSH密钥路径（临时读取用）')
                ->addColumn(self::fields_KEY_CONTENT, TableInterface::column_type_TEXT, null, 'null', 'SSH密钥内容（加密存储）')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, null, 'null', '描述')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_host', self::fields_HOST, '主机地址索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_name', self::fields_NAME, '主机名称索引')
                ->create();
        }
    }

    /**
     * 升级表结构
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 添加 key_content 字段（如果不存在）
        if ($setup->tableExist() && !$setup->hasField(self::fields_KEY_CONTENT)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_KEY_CONTENT,
                    self::fields_KEY_PATH, // 在 key_path 字段之后
                    TableInterface::column_type_TEXT,
                    null,
                    'null',
                    'SSH密钥内容（加密存储）'
                )
                ->alter();
        }
    }

    /**
     * 保存前处理
     * 
     * @return self
     */
    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getData(self::fields_CREATED_AT)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
        
        // 如果提供了密码，进行加密存储
        if ($this->hasData(self::fields_PASSWORD) && !empty($this->getData(self::fields_PASSWORD))) {
            $password = $this->getData(self::fields_PASSWORD);
            // 如果密码不是已加密的格式，则加密
            if (!preg_match('/^encrypted:/', $password)) {
                $this->setData(self::fields_PASSWORD, 'encrypted:' . base64_encode($password));
            }
        }
        
        // 如果提供了密钥内容，进行加密存储
        if ($this->hasData(self::fields_KEY_CONTENT) && !empty($this->getData(self::fields_KEY_CONTENT))) {
            $keyContent = $this->getData(self::fields_KEY_CONTENT);
            // 如果密钥内容不是已加密的格式，则加密
            if (!preg_match('/^encrypted:/', $keyContent)) {
                $this->setData(self::fields_KEY_CONTENT, 'encrypted:' . base64_encode($keyContent));
            }
        }
        
        // 保存后清除临时密钥路径（如果存在）
        if ($this->hasData(self::fields_KEY_PATH)) {
            $this->unsetData(self::fields_KEY_PATH);
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
        $password = $this->getData(self::fields_PASSWORD);
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
        $keyContent = $this->getData(self::fields_KEY_CONTENT);
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
        return $mappingModel->where(\Weline\Async\Model\SyncMapping::fields_HOST_ID, $this->getId())
            ->fetch()
            ->getItems();
    }
}
