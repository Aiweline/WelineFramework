<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Meta\Model;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** Meta 本地化模型 - 存储 Meta 的多语言翻译数据 */
#[Table(comment: 'Meta本地化翻译表')]
#[Index(name: 'uk_meta_identify_locale_key', columns: ['meta_identify', 'locale_code', 'config_key'], type: 'UNIQUE')]
#[Index(name: 'idx_meta_id', columns: ['meta_id'])]
#[Index(name: 'idx_meta_identify', columns: ['meta_identify'])]
#[Index(name: 'idx_locale_code', columns: ['locale_code'])]
class MetaLocal extends AbstractModel
{
    public const schema_table = 'w_meta_local';
    public const schema_primary_keys = ['meta_id', 'locale_code', 'config_key'];
    #[Col('int', nullable: false, comment: 'Meta ID')]
    public const schema_fields_META_ID = 'meta_id';
    #[Col('varchar', 255, nullable: false, comment: 'Meta标识')]
    public const schema_fields_META_IDENTIFY = 'meta_identify';
    #[Col('varchar', 20, nullable: false, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    #[Col('varchar', 128, nullable: false, comment: '配置键')]
    public const schema_fields_CONFIG_KEY = 'config_key';
    #[Col('text', comment: '配置值')]
    public const schema_fields_CONFIG_VALUE = 'config_value';
    public array $_unit_primary_keys = ['meta_id', 'locale_code', 'config_key'];
    public array $_index_sort_keys = ['meta_id', 'locale_code', 'config_key'];
/**
     * 获取 Meta ID
     */
    public function getMetaId(): ?int
    {
        return $this->getData(self::schema_fields_META_ID);
    }
    /**
     * 设置 Meta ID
     */
    public function setMetaId(int $metaId): static
    {
        return $this->setData(self::schema_fields_META_ID, $metaId);
    }
    /**
     * 获取 Meta 标识
     */
    public function getMetaIdentify(): ?string
    {
        return $this->getData(self::schema_fields_META_IDENTIFY);
    }
    /**
     * 设置 Meta 标识
     */
    public function setMetaIdentify(string $metaIdentify): static
    {
        return $this->setData(self::schema_fields_META_IDENTIFY, $metaIdentify);
    }
    /**
     * 获取语言代码
     */
    public function getLocaleCode(): ?string
    {
        return $this->getData(self::schema_fields_LOCALE_CODE);
    }
    /**
     * 设置语言代码
     */
    public function setLocaleCode(string $localeCode): static
    {
        return $this->setData(self::schema_fields_LOCALE_CODE, $localeCode);
    }
    /**
     * 获取配置键
     */
    public function getConfigKey(): ?string
    {
        return $this->getData(self::schema_fields_CONFIG_KEY);
    }
    /**
     * 设置配置键
     */
    public function setConfigKey(string $configKey): static
    {
        return $this->setData(self::schema_fields_CONFIG_KEY, $configKey);
    }
    /**
     * 获取配置值
     */
    public function getConfigValue(): ?string
    {
        return $this->getData(self::schema_fields_CONFIG_VALUE);
    }
    /**
     * 设置配置值
     */
    public function setConfigValue(string $configValue): static
    {
        return $this->setData(self::schema_fields_CONFIG_VALUE, $configValue);
    }
}
