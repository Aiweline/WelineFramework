<?php
declare(strict_types=1);

namespace Weline\AppStore\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AppStore 已安装模块表')]
#[Index(name: 'idx_module_name', columns: ['module_name'], type: 'UNIQUE', comment: '模块名唯一索引')]
#[Index(name: 'idx_license_key', columns: ['license_key'], type: 'KEY', comment: '许可证密钥索引')]
#[Index(name: 'idx_primary_tag_code', columns: ['primary_tag_code'], type: 'KEY', comment: 'Marketplace primary tag index')]
class AppStoreInstalledModule extends Model
{
    public const schema_table = 'weline_appstore_installed_module';
    public const schema_primary_key = 'install_id';

    // 许可证状态常量
    public const LICENSE_STATUS_VALID = 'valid';
    public const LICENSE_STATUS_EXPIRED = 'expired';
    public const LICENSE_STATUS_REVOKED = 'revoked';
    public const LICENSE_STATUS_NOT_ACTIVATED = 'not_activated';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '安装ID')]
    public const schema_fields_ID = 'install_id';

    #[Col(type: 'varchar', length: 100, nullable: false, comment: '模块名 (Vendor_Module)')]
    public const schema_fields_module_name = 'module_name';

    #[Col(type: 'varchar', length: 20, nullable: false, comment: '安装版本')]
    public const schema_fields_version = 'version';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: '许可证密钥')]
    public const schema_fields_license_key = 'license_key';

    #[Col(type: 'varchar', length: 20, nullable: false, default: self::LICENSE_STATUS_NOT_ACTIVATED, comment: '许可证状态')]
    public const schema_fields_license_status = 'license_status';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '绑定域名')]
    public const schema_fields_bound_domain = 'bound_domain';

    #[Col(type: 'datetime', nullable: true, comment: '许可证过期时间')]
    public const schema_fields_license_expires_at = 'license_expires_at';

    #[Col(type: 'varchar', length: 150, nullable: true, comment: '模块显示名称')]
    public const schema_fields_display_name = 'display_name';

    #[Col(type: 'text', nullable: true, comment: '模块描述')]
    public const schema_fields_description = 'description';

    #[Col(type: 'text', nullable: true, comment: 'Marketplace Meta JSON')]
    public const schema_fields_marketplace_meta_json = 'marketplace_meta_json';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Marketplace Meta hash')]
    public const schema_fields_marketplace_meta_hash = 'marketplace_meta_hash';

    #[Col(type: 'varchar', length: 20, nullable: true, comment: 'Marketplace Meta locale')]
    public const schema_fields_marketplace_meta_locale = 'marketplace_meta_locale';

    #[Col(type: 'varchar', length: 120, nullable: true, comment: 'Marketplace primary tag code')]
    public const schema_fields_primary_tag_code = 'primary_tag_code';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Marketplace surface codes JSON')]
    public const schema_fields_surface_codes = 'surface_codes';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '模块图标')]
    public const schema_fields_icon = 'icon';

    #[Col(type: 'int', nullable: false, default: 0, comment: '平台模块ID')]
    public const schema_fields_platform_module_id = 'platform_module_id';

    #[Col(type: 'datetime', nullable: true, comment: '安装时间')]
    public const schema_fields_installed_at = 'installed_at';

    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function getInstallId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setInstallId(int $installId): static
    {
        $this->setData(self::schema_fields_ID, $installId);
        return $this;
    }

    public function getModuleName(): string
    {
        return $this->getData(self::schema_fields_module_name) ?? '';
    }

    public function setModuleName(string $moduleName): static
    {
        $this->setData(self::schema_fields_module_name, $moduleName);
        return $this;
    }

    public function getVersion(): string
    {
        return $this->getData(self::schema_fields_version) ?? '1.0.0';
    }

    public function setVersion(string $version): static
    {
        $this->setData(self::schema_fields_version, $version);
        return $this;
    }

    public function getLicenseKey(): ?string
    {
        return $this->getData(self::schema_fields_license_key);
    }

    public function setLicenseKey(?string $licenseKey): static
    {
        $this->setData(self::schema_fields_license_key, $licenseKey);
        return $this;
    }

    public function getLicenseStatus(): string
    {
        return $this->getData(self::schema_fields_license_status) ?? self::LICENSE_STATUS_NOT_ACTIVATED;
    }

    public function setLicenseStatus(string $status): static
    {
        $this->setData(self::schema_fields_license_status, $status);
        return $this;
    }

    public function getBoundDomain(): ?string
    {
        return $this->getData(self::schema_fields_bound_domain);
    }

    public function setBoundDomain(?string $domain): static
    {
        $this->setData(self::schema_fields_bound_domain, $domain);
        return $this;
    }

    public function getLicenseExpiresAt(): ?string
    {
        return $this->getData(self::schema_fields_license_expires_at);
    }

    public function setLicenseExpiresAt(?string $expiresAt): static
    {
        $this->setData(self::schema_fields_license_expires_at, $expiresAt);
        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->getData(self::schema_fields_display_name);
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->setData(self::schema_fields_display_name, $displayName);
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->getData(self::schema_fields_description);
    }

    public function setDescription(?string $description): static
    {
        $this->setData(self::schema_fields_description, $description);
        return $this;
    }

    public function getMarketplaceMetaJson(): array
    {
        $value = $this->getData(self::schema_fields_marketplace_meta_json);
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setMarketplaceMetaJson(array|string|null $meta): static
    {
        if (is_array($meta)) {
            $meta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        $this->setData(self::schema_fields_marketplace_meta_json, $meta ?: null);
        return $this;
    }

    public function getMarketplaceMetaHash(): string
    {
        return (string)($this->getData(self::schema_fields_marketplace_meta_hash) ?? '');
    }

    public function setMarketplaceMetaHash(?string $hash): static
    {
        $this->setData(self::schema_fields_marketplace_meta_hash, $hash !== null && $hash !== '' ? $hash : null);
        return $this;
    }

    public function getMarketplaceMetaLocale(): string
    {
        return (string)($this->getData(self::schema_fields_marketplace_meta_locale) ?? '');
    }

    public function setMarketplaceMetaLocale(?string $locale): static
    {
        $this->setData(self::schema_fields_marketplace_meta_locale, $locale !== null && $locale !== '' ? $locale : null);
        return $this;
    }

    public function getPrimaryTagCode(): string
    {
        return (string)($this->getData(self::schema_fields_primary_tag_code) ?? '');
    }

    public function setPrimaryTagCode(?string $code): static
    {
        $this->setData(self::schema_fields_primary_tag_code, $code !== null && $code !== '' ? $code : null);
        return $this;
    }

    public function getSurfaceCodes(): array
    {
        $value = $this->getData(self::schema_fields_surface_codes);
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    /**
     * @param string[] $codes
     */
    public function setSurfaceCodes(array $codes): static
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        $this->setData(
            self::schema_fields_surface_codes,
            $codes !== [] ? json_encode($codes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
        );
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->getData(self::schema_fields_icon);
    }

    public function setIcon(?string $icon): static
    {
        $this->setData(self::schema_fields_icon, $icon);
        return $this;
    }

    public function getPlatformModuleId(): int
    {
        return (int)$this->getData(self::schema_fields_platform_module_id);
    }

    public function setPlatformModuleId(int $platformModuleId): static
    {
        $this->setData(self::schema_fields_platform_module_id, $platformModuleId);
        return $this;
    }

    public function getInstalledAt(): ?string
    {
        return $this->getData(self::schema_fields_installed_at);
    }

    public function setInstalledAt(?string $installedAt): static
    {
        $this->setData(self::schema_fields_installed_at, $installedAt);
        return $this;
    }

    public function isLicenseValid(): bool
    {
        if ($this->getLicenseStatus() !== self::LICENSE_STATUS_VALID) {
            return false;
        }

        $expiresAt = $this->getLicenseExpiresAt();
        if ($expiresAt && strtotime($expiresAt) < time()) {
            return false;
        }

        return true;
    }

    public function isLicenseExpired(): bool
    {
        $expiresAt = $this->getLicenseExpiresAt();
        return $expiresAt && strtotime($expiresAt) < time();
    }
}
