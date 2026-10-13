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
/** Meta 本地化模型 - 存储 Meta 的多语言翻译数据（含 scope / version_id） */
#[Table(comment: 'Meta本地化翻译表')]
/**
 * Declared natural key includes scope+version_id.
 * Legacy DBs only have UNIQUE(meta_identify,locale_code,config_key); SchemaDiff promotes via
 * subset-UNIQUE path (DROP 3-col UNIQUE + ADD 5-col PRIMARY).
 */
#[Index(name: 'uk_meta_local_identify_locale_key_scope_version', columns: ['meta_identify', 'locale_code', 'config_key', 'scope', 'version_id'], type: 'UNIQUE')]
#[Index(name: 'idx_meta_id', columns: ['meta_id'])]
#[Index(name: 'idx_meta_identify', columns: ['meta_identify'])]
#[Index(name: 'idx_locale_code', columns: ['locale_code'])]
#[Index(name: 'idx_scope', columns: ['scope'])]
#[Index(name: 'idx_version_id', columns: ['version_id'])]
class MetaLocal extends AbstractModel
{
    public const schema_table = 'w_meta_local';
    public const schema_primary_keys = ['meta_identify', 'locale_code', 'config_key', 'scope', 'version_id'];
    public const DEFAULT_SCOPE = 'default';
    public const DEFAULT_VERSION_ID = 0;

    #[Col('int', nullable: false, comment: 'Meta ID')]
    public const schema_fields_META_ID = 'meta_id';
    #[Col('varchar', 255, nullable: false, comment: 'Meta标识')]
    public const schema_fields_META_IDENTIFY = 'meta_identify';
    #[Col('varchar', 20, nullable: false, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    #[Col('varchar', 255, nullable: false, comment: '配置键')]
    public const schema_fields_CONFIG_KEY = 'config_key';
    #[Col('text', comment: '配置值')]
    public const schema_fields_CONFIG_VALUE = 'config_value';
    #[Col('varchar', 100, nullable: false, default: self::DEFAULT_SCOPE, comment: '作用域')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('int', nullable: false, default: self::DEFAULT_VERSION_ID, comment: '主题版本ID，0=非版本绑定')]
    public const schema_fields_VERSION_ID = 'version_id';

    public array $_unit_primary_keys = ['meta_identify', 'locale_code', 'config_key', 'scope', 'version_id'];
    public array $_index_sort_keys = ['meta_identify', 'locale_code', 'config_key', 'scope', 'version_id'];

    /**
     * Natural key matching schema_primary_keys / physical PRIMARY after promote.
     * Prefer this over meta_id triples so version_id=0 and scope are never dropped.
     */
    public function whereNaturalKey(
        string $metaIdentify,
        string $localeCode,
        string $configKey,
        string $scope = self::DEFAULT_SCOPE,
        int $versionId = self::DEFAULT_VERSION_ID,
    ): static {
        $scope = trim($scope);
        if ($scope === '') {
            $scope = self::DEFAULT_SCOPE;
        }
        $this->where(self::schema_fields_META_IDENTIFY, $metaIdentify)
            ->where(self::schema_fields_LOCALE_CODE, $localeCode)
            ->where(self::schema_fields_CONFIG_KEY, $configKey)
            ->where(self::schema_fields_SCOPE, $scope)
            ->where(self::schema_fields_VERSION_ID, max(0, $versionId));

        return $this;
    }

    public function getMetaId(): ?int
    {
        return $this->getData(self::schema_fields_META_ID);
    }

    public function setMetaId(int $metaId): static
    {
        return $this->setData(self::schema_fields_META_ID, $metaId);
    }

    public function getMetaIdentify(): ?string
    {
        return $this->getData(self::schema_fields_META_IDENTIFY);
    }

    public function setMetaIdentify(string $metaIdentify): static
    {
        return $this->setData(self::schema_fields_META_IDENTIFY, $metaIdentify);
    }

    public function getLocaleCode(): ?string
    {
        return $this->getData(self::schema_fields_LOCALE_CODE);
    }

    public function setLocaleCode(string $localeCode): static
    {
        return $this->setData(self::schema_fields_LOCALE_CODE, $localeCode);
    }

    public function getConfigKey(): ?string
    {
        return $this->getData(self::schema_fields_CONFIG_KEY);
    }

    public function setConfigKey(string $configKey): static
    {
        return $this->setData(self::schema_fields_CONFIG_KEY, $configKey);
    }

    public function getConfigValue(): ?string
    {
        return $this->getData(self::schema_fields_CONFIG_VALUE);
    }

    public function setConfigValue(string $configValue): static
    {
        return $this->setData(self::schema_fields_CONFIG_VALUE, $configValue);
    }

    public function getScope(): string
    {
        $scope = (string)($this->getData(self::schema_fields_SCOPE) ?? '');
        return $scope !== '' ? $scope : self::DEFAULT_SCOPE;
    }

    public function setScope(string $scope): static
    {
        $scope = trim($scope);
        return $this->setData(self::schema_fields_SCOPE, $scope !== '' ? $scope : self::DEFAULT_SCOPE);
    }

    public function getVersionId(): int
    {
        return (int)($this->getData(self::schema_fields_VERSION_ID) ?? self::DEFAULT_VERSION_ID);
    }

    public function setVersionId(int $versionId): static
    {
        return $this->setData(self::schema_fields_VERSION_ID, max(0, $versionId));
    }
}
