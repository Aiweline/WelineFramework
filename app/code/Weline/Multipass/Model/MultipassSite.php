<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Multipass\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Multipass 站点模型
 * 管理支持 Multipass 登录的站点配置
 */
class MultipassSite extends Model
{
    public const table = 'multipass_site';
    public const primary_key = 'site_id';
    
    public const fields_ID = 'site_id';
    public const fields_SITE_NAME = 'site_name';
    public const fields_SITE_URL = 'site_url';
    public const fields_SECRET_KEY = 'secret_key';
    public const fields_USER_TYPE = 'user_type'; // 'frontend' 或 'backend'
    public const fields_IS_ENABLED = 'is_enabled';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

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
        // 可以在这里添加升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('Multipass 站点表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '站点ID')
                ->addColumn(self::fields_SITE_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '站点名称')
                ->addColumn(self::fields_SITE_URL, TableInterface::column_type_VARCHAR, 500, 'not null', '站点URL')
                ->addColumn(self::fields_SECRET_KEY, TableInterface::column_type_VARCHAR, 64, 'not null', '加密密钥')
                ->addColumn(self::fields_USER_TYPE, TableInterface::column_type_VARCHAR, 20, "default 'frontend'", '用户类型：frontend 或 backend')
                ->addColumn(self::fields_IS_ENABLED, TableInterface::column_type_INTEGER, 1, 'default 1', '是否启用')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, "default CURRENT_TIMESTAMP", '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, "default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", '更新时间')
                ->create();
        }
    }

    /**
     * 获取站点名称
     */
    public function getSiteName(): string
    {
        return $this->getData(self::fields_SITE_NAME) ?? '';
    }

    /**
     * 设置站点名称
     */
    public function setSiteName(string $siteName): static
    {
        return $this->setData(self::fields_SITE_NAME, $siteName);
    }

    /**
     * 获取站点URL
     */
    public function getSiteUrl(): string
    {
        return $this->getData(self::fields_SITE_URL) ?? '';
    }

    /**
     * 设置站点URL
     */
    public function setSiteUrl(string $siteUrl): static
    {
        return $this->setData(self::fields_SITE_URL, $siteUrl);
    }

    /**
     * 获取密钥
     */
    public function getSecretKey(): string
    {
        return $this->getData(self::fields_SECRET_KEY) ?? '';
    }

    /**
     * 设置密钥
     */
    public function setSecretKey(string $secretKey): static
    {
        return $this->setData(self::fields_SECRET_KEY, $secretKey);
    }

    /**
     * 获取用户类型
     */
    public function getUserType(): string
    {
        return $this->getData(self::fields_USER_TYPE) ?? 'frontend';
    }

    /**
     * 设置用户类型
     */
    public function setUserType(string $userType): static
    {
        return $this->setData(self::fields_USER_TYPE, $userType);
    }

    /**
     * 是否启用
     */
    public function getIsEnabled(): bool
    {
        return (bool)$this->getData(self::fields_IS_ENABLED);
    }

    /**
     * 设置是否启用
     */
    public function setIsEnabled(bool $isEnabled): static
    {
        return $this->setData(self::fields_IS_ENABLED, $isEnabled ? 1 : 0);
    }
}

