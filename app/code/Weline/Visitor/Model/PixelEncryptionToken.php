<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 像素加密Token模型
 * 
 * 用于管理基于Deploy版本号的加密token
 */
class PixelEncryptionToken extends Model
{
    public const fields_ID = 'token_id';
    public const fields_VERSION = 'version';
    public const fields_ENCRYPTION_TOKEN = 'encryption_token';
    public const fields_CREATED_AT = 'created_at';
    public const fields_EXPIRES_AT = 'expires_at';
    public const fields_IS_DELETED = 'is_deleted';
    public const fields_DELETED_AT = 'deleted_at';

    /**
     * 表名（框架会自动添加前缀）
     */
    public string $table = 'w_pixel_encryption_token';

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
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑：如果表已存在，检查是否需要添加字段
        if ($setup->tableExist()) {
            // 如果缺少is_deleted字段，添加它
            if (!$setup->hasField(self::fields_IS_DELETED)) {
                $setup->alterTable()
                    ->addColumn(
                        self::fields_IS_DELETED,
                        self::fields_EXPIRES_AT,
                        TableInterface::column_type_SMALLINT,
                        1,
                        'default 0',
                        '是否已删除'
                    )
                    ->alter();
            }
            // 如果缺少deleted_at字段，添加它
            if (!$setup->hasField(self::fields_DELETED_AT)) {
                $setup->alterTable()
                    ->addColumn(
                        self::fields_DELETED_AT,
                        self::fields_IS_DELETED,
                        TableInterface::column_type_DATETIME,
                        null,
                        '',
                        '删除时间'
                    )
                    ->alter();
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('weline 像素加密Token')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_BIGINT,
                0,
                'primary key auto_increment',
                'ID'
            )
            ->addColumn(
                self::fields_VERSION,
                TableInterface::column_type_VARCHAR,
                100,
                'not null',
                '部署版本号（格式：版本号-日期，如：1.0.0-20250101）'
            )
            ->addColumn(
                self::fields_ENCRYPTION_TOKEN,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '加密token'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                null,
                'not null',
                '创建时间'
            )
            ->addColumn(
                self::fields_EXPIRES_AT,
                TableInterface::column_type_DATETIME,
                null,
                'not null',
                '过期时间（创建时间+90天）'
            )
            ->addColumn(
                self::fields_IS_DELETED,
                TableInterface::column_type_SMALLINT,
                1,
                'default 0',
                '是否已删除（0=未删除，1=已删除）'
            )
            ->addColumn(
                self::fields_DELETED_AT,
                TableInterface::column_type_DATETIME,
                null,
                '',
                '删除时间'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'idx_version',
                self::fields_VERSION,
                '版本号唯一索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_expires_at',
                self::fields_EXPIRES_AT,
                '过期时间索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_is_deleted',
                self::fields_IS_DELETED,
                '删除状态索引'
            )
            ->create();
    }

    /**
     * 获取Token ID
     */
    public function getTokenId(): int
    {
        return (int)$this->getData(self::fields_ID);
    }

    /**
     * 获取版本号
     */
    public function getVersion(): string
    {
        return (string)$this->getData(self::fields_VERSION);
    }

    /**
     * 获取加密token
     */
    public function getEncryptionToken(): string
    {
        return (string)$this->getData(self::fields_ENCRYPTION_TOKEN);
    }

    /**
     * 获取创建时间
     */
    public function getCreatedAt(): string
    {
        return (string)$this->getData(self::fields_CREATED_AT);
    }

    /**
     * 获取过期时间
     */
    public function getExpiresAt(): string
    {
        return (string)$this->getData(self::fields_EXPIRES_AT);
    }

    /**
     * 是否已删除
     */
    public function getIsDeleted(): bool
    {
        return (bool)$this->getData(self::fields_IS_DELETED);
    }

    /**
     * 获取删除时间
     */
    public function getDeletedAt(): ?string
    {
        $deletedAt = $this->getData(self::fields_DELETED_AT);
        return $deletedAt ? (string)$deletedAt : null;
    }

    /**
     * 设置Token ID
     */
    public function setTokenId(int $token_id): static
    {
        return $this->setData(self::fields_ID, $token_id);
    }

    /**
     * 设置版本号
     */
    public function setVersion(string $version): static
    {
        return $this->setData(self::fields_VERSION, $version);
    }

    /**
     * 设置加密token
     */
    public function setEncryptionToken(string $encryption_token): static
    {
        return $this->setData(self::fields_ENCRYPTION_TOKEN, $encryption_token);
    }

    /**
     * 设置创建时间
     */
    public function setCreatedAt(string $created_at): static
    {
        return $this->setData(self::fields_CREATED_AT, $created_at);
    }

    /**
     * 设置过期时间
     */
    public function setExpiresAt(string $expires_at): static
    {
        return $this->setData(self::fields_EXPIRES_AT, $expires_at);
    }

    /**
     * 设置是否已删除
     */
    public function setIsDeleted(bool $is_deleted): static
    {
        return $this->setData(self::fields_IS_DELETED, $is_deleted ? 1 : 0);
    }

    /**
     * 设置删除时间
     */
    public function setDeletedAt(?string $deleted_at): static
    {
        return $this->setData(self::fields_DELETED_AT, $deleted_at);
    }

    /**
     * 根据版本号查找token
     */
    public function findByVersion(string $version): ?self
    {
        return $this->reset()
            ->where(self::fields_VERSION, $version)
            ->where(self::fields_IS_DELETED, 0)
            ->find()
            ->fetch();
    }

    /**
     * 获取所有有效的token（未过期且未删除）
     */
    public function getValidTokens(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->reset()
            ->where(self::fields_IS_DELETED, 0)
            ->where(self::fields_EXPIRES_AT, $now, '>=')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取当前版本号的token
     */
    public function getCurrentVersionToken(): ?self
    {
        // 获取最新的未删除token
        return $this->reset()
            ->where(self::fields_IS_DELETED, 0)
            ->order('created_at', 'DESC')
            ->find()
            ->fetch();
    }

    /**
     * 标记90天前的旧token为已删除
     */
    public function markOldTokensAsDeleted(): int
    {
        $ninetyDaysAgo = date('Y-m-d H:i:s', strtotime('-90 days'));
        $now = date('Y-m-d H:i:s');
        
        // 查找90天前创建的且未删除的token
        $oldTokens = $this->reset()
            ->where(self::fields_IS_DELETED, 0)
            ->where(self::fields_CREATED_AT, $ninetyDaysAgo, '<=')
            ->select()
            ->fetchArray();
        
        $count = 0;
        foreach ($oldTokens as $tokenData) {
            $token = clone $this;
            $token->setData($tokenData);
            $token->setIsDeleted(true)
                ->setDeletedAt($now)
                ->save();
            $count++;
        }
        
        return $count;
    }
}

