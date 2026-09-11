<?php

declare(strict_types=1);

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Visitor\Service\PixelEventVendorScope;

/**
 * 像素第三方事件供应商（模块扫描 + 人工自定义）。
 */
#[Table(comment: '像素事件供应商')]
#[Index(name: 'uk_pixel_event_vendor_website_code', columns: ['website_id', 'code'], type: 'UNIQUE')]
#[Index(name: 'idx_pixel_event_vendor_source_enabled', columns: ['source', 'enabled', 'website_id'])]
class PixelEventVendor extends Model
{
    public const schema_table = 'pixel_event_vendor';
    public const schema_primary_key = 'pixel_event_vendor_id';

    public const SOURCE_MODULE = 'module';
    public const SOURCE_CUSTOM = 'custom';

    public const MODE_SANDBOX = 'sandbox';
    public const MODE_INJECT = 'inject';

    public const CODE_SYSTEM = 'weline';

    /** @var list<string> */
    public const SOURCES = [self::SOURCE_MODULE, self::SOURCE_CUSTOM];

    /** @var list<string> */
    public const MODES = [self::MODE_SANDBOX, self::MODE_INJECT];

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '供应商ID')]
    public const schema_fields_ID = 'pixel_event_vendor_id';

    #[Col('int', 0, nullable: false, default: 0, comment: '站点ID；0=系统默认站')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: '供应商代码 weline|ga4|gtm|custom_*')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 255, nullable: false, comment: '显示名')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 16, nullable: false, default: self::SOURCE_MODULE, comment: '来源 module|custom')]
    public const schema_fields_SOURCE = 'source';

    #[Col('varchar', 128, comment: '提供模块')]
    public const schema_fields_PROVIDER_MODULE = 'provider_module';

    #[Col('varchar', 255, comment: 'Provider 类名')]
    public const schema_fields_PROVIDER_CLASS = 'provider_class';

    #[Col('int', 0, nullable: false, default: 0, comment: '是否启用 1/0')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('varchar', 16, nullable: false, default: self::MODE_SANDBOX, comment: '当前模式 sandbox|inject')]
    public const schema_fields_MODE = 'mode';

    #[Col('varchar', 16, nullable: false, default: self::MODE_SANDBOX, comment: '默认模式')]
    public const schema_fields_DEFAULT_MODE = 'default_mode';

    #[Col('int', 0, nullable: false, default: 1, comment: '是否使用默认搭接 1/0')]
    public const schema_fields_USE_DEFAULT_MAP = 'use_default_map';

    #[Col('text', comment: '凭证 JSON')]
    public const schema_fields_CREDENTIALS_JSON = 'credentials_json';

    #[Col('text', comment: '事件搭接 JSON 对象')]
    public const schema_fields_EVENT_MAP_JSON = 'event_map_json';

    #[Col('text', comment: '范围 JSON：path_include/path_exclude/areas')]
    public const schema_fields_SCOPE_JSON = 'scope_json';

    #[Col('mediumtext', comment: '沙盒 JS')]
    public const schema_fields_SANDBOX_JS = 'sandbox_js';

    #[Col('mediumtext', comment: '注入 JS')]
    public const schema_fields_INJECT_JS = 'inject_js';

    #[Col('text', comment: 'config schema 快照 JSON')]
    public const schema_fields_CONFIG_SCHEMA_JSON = 'config_schema_json';

    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    /**
     * @return array<string, mixed>
     */
    public function getCredentials(): array
    {
        $raw = (string)$this->getData(self::schema_fields_CREDENTIALS_JSON);
        if ($raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function setCredentials(array $credentials): self
    {
        $this->setData(self::schema_fields_CREDENTIALS_JSON, \json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getEventMap(): array
    {
        $raw = (string)$this->getData(self::schema_fields_EVENT_MAP_JSON);
        if ($raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $key = \trim((string)$k);
            $val = \trim((string)$v);
            if ($key !== '' && $val !== '') {
                $out[$key] = $val;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $map
     */
    public function setEventMap(array $map): self
    {
        $this->setData(self::schema_fields_EVENT_MAP_JSON, \json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this;
    }

    /**
     * @return array{path_include:list<string>,path_exclude:list<string>,areas:list<string>}
     */
    public function getScope(): array
    {
        $raw = (string)$this->getData(self::schema_fields_SCOPE_JSON);
        if ($raw === '') {
            return PixelEventVendorScope::defaultScope();
        }
        $decoded = \json_decode($raw, true);

        return PixelEventVendorScope::normalize(\is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function setScope(array $scope): self
    {
        $normalized = PixelEventVendorScope::normalize($scope);
        $this->setData(
            self::schema_fields_SCOPE_JSON,
            \json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRuntimeVendor(): array
    {
        $credentials = $this->getCredentials();
        $summary = '';
        if ($credentials !== []) {
            $summary = \implode(' · ', \array_map('strval', \array_values($credentials)));
        }

        return [
            'id' => (int)$this->getData(self::schema_fields_ID),
            'website_id' => (int)$this->getData(self::schema_fields_WEBSITE_ID),
            'code' => (string)$this->getData(self::schema_fields_CODE),
            'name' => (string)$this->getData(self::schema_fields_NAME),
            'source' => (string)$this->getData(self::schema_fields_SOURCE),
            'provider_module' => (string)$this->getData(self::schema_fields_PROVIDER_MODULE),
            'provider_class' => (string)$this->getData(self::schema_fields_PROVIDER_CLASS),
            'enabled' => (int)$this->getData(self::schema_fields_ENABLED) === 1,
            'mode' => (string)$this->getData(self::schema_fields_MODE) ?: self::MODE_SANDBOX,
            'default_mode' => (string)$this->getData(self::schema_fields_DEFAULT_MODE) ?: self::MODE_SANDBOX,
            'use_default_map' => (int)$this->getData(self::schema_fields_USE_DEFAULT_MAP) === 1,
            'credentials' => $credentials,
            'credential_summary' => $summary,
            'event_map' => $this->getEventMap(),
            'scope' => $this->getScope(),
            'sandbox_js' => (string)$this->getData(self::schema_fields_SANDBOX_JS),
            'inject_js' => (string)$this->getData(self::schema_fields_INJECT_JS),
        ];
    }
}
