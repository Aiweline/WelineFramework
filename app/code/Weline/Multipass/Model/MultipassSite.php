<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Multipass\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
/** Multipass 站点模型 - 管理支持 Multipass 登录的站点配置 */
#[Table(comment: 'Multipass站点表')]
class MultipassSite extends Model
{
    public const schema_table = 'multipass_site';
    public const schema_primary_key = 'site_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '站点ID')]
    public const schema_fields_ID = 'site_id';
    #[Col('varchar', 255, nullable: false, comment: '站点名称')]
    public const schema_fields_SITE_NAME = 'site_name';
    #[Col('varchar', 500, nullable: false, comment: '站点URL')]
    public const schema_fields_SITE_URL = 'site_url';
    #[Col('varchar', 64, nullable: false, comment: '加密密钥')]
    public const schema_fields_SECRET_KEY = 'secret_key';
    #[Col('varchar', 20, default: 'frontend', comment: '用户类型')]
    public const schema_fields_USER_TYPE = 'user_type';
    #[Col('int', 1, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
/**
     * 获取站点名称
     */
    public function getSiteName(): string
    {
        return $this->getData(self::schema_fields_SITE_NAME) ?? '';
    }
    /**
     * 设置站点名称
     */
    public function setSiteName(string $siteName): static
    {
        return $this->setData(self::schema_fields_SITE_NAME, $siteName);
    }
    /**
     * 获取站点URL
     */
    public function getSiteUrl(): string
    {
        return $this->getData(self::schema_fields_SITE_URL) ?? '';
    }
    /**
     * 设置站点URL
     */
    public function setSiteUrl(string $siteUrl): static
    {
        return $this->setData(self::schema_fields_SITE_URL, $siteUrl);
    }
    /**
     * 获取密钥
     */
    public function getSecretKey(): string
    {
        return $this->getData(self::schema_fields_SECRET_KEY) ?? '';
    }
    /**
     * 设置密钥
     */
    public function setSecretKey(string $secretKey): static
    {
        return $this->setData(self::schema_fields_SECRET_KEY, $secretKey);
    }
    /**
     * 获取用户类型
     */
    public function getUserType(): string
    {
        return $this->getData(self::schema_fields_USER_TYPE) ?? 'frontend';
    }
    /**
     * 设置用户类型
     */
    public function setUserType(string $userType): static
    {
        return $this->setData(self::schema_fields_USER_TYPE, $userType);
    }
    /**
     * 是否启用
     */
    public function getIsEnabled(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ENABLED);
    }
    /**
     * 设置是否启用
     */
    public function setIsEnabled(bool $isEnabled): static
    {
        return $this->setData(self::schema_fields_IS_ENABLED, $isEnabled ? 1 : 0);
    }
}
