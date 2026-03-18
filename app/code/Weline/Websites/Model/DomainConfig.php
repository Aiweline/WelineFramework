<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名管理配置模型
 *
 * 存储域名管理相关的配置项（自动解析开关、服务器 IP 等）
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Websites\Service\DnsSiteHostRules;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '域名管理配置表')]
#[Index(name: 'uk_config_key', columns: ['config_key'], type: 'UNIQUE')]
class DomainConfig extends Model
{
    public const schema_table = 'weline_websites_domain_config';
    public const schema_primary_key = 'config_id';


    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '配置ID')]
    public const schema_fields_ID = 'config_id';
    #[Col('varchar', 100, nullable: false, comment: '配置键')]
    public const schema_fields_KEY = 'config_key';
    #[Col('text', nullable: true, comment: '配置值')]
    public const schema_fields_VALUE = 'config_value';
    #[Col('datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // 配置键常量
    public const CONFIG_AUTO_RESOLVE_ENABLED = 'auto_resolve_enabled';
    public const CONFIG_AUTO_RESOLVE_RECORD_TYPE = 'auto_resolve_record_type';
    public const CONFIG_AUTO_RESOLVE_SUBDOMAINS = 'auto_resolve_subdomains';
    public const CONFIG_SERVER_PUBLIC_IP = 'server_public_ip';
    public const CONFIG_SERVER_PUBLIC_IPV6 = 'server_public_ipv6';
    public const CONFIG_RESOLVE_CHECK_INTERVAL = 'resolve_check_interval';
    public const CONFIG_CERT_AUTO_REQUEST = 'cert_auto_request';

    // 默认值
    private const DEFAULTS = [
        self::CONFIG_AUTO_RESOLVE_ENABLED => '0',
        self::CONFIG_AUTO_RESOLVE_RECORD_TYPE => 'A',
        self::CONFIG_AUTO_RESOLVE_SUBDOMAINS => '@,www',
        self::CONFIG_SERVER_PUBLIC_IP => '',
        self::CONFIG_SERVER_PUBLIC_IPV6 => '',
        self::CONFIG_RESOLVE_CHECK_INTERVAL => '600',
        self::CONFIG_CERT_AUTO_REQUEST => '0',
    ];

    // =============== 业务方法 ===============

    /**
     * 获取配置值
     */
    public function getValue(string $key, ?string $default = null): string
    {
        $this->clearQuery()
            ->where(self::schema_fields_KEY, $key)
            ->find()
            ->fetch();

        if ($this->getData(self::schema_fields_ID)) {
            return (string) $this->getData(self::schema_fields_VALUE);
        }

        return $default ?? (self::DEFAULTS[$key] ?? '');
    }

    /**
     * 设置配置值
     */
    public function setValue(string $key, string $value): self
    {
        $model = clone $this;
        $model->clearQuery()
            ->where(self::schema_fields_KEY, $key)
            ->find()
            ->fetch();

        $model->setData(self::schema_fields_KEY, $key);
        $model->setData(self::schema_fields_VALUE, $value);
        $model->setData(self::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'));
        $model->save();

        return $this;
    }

    /**
     * 批量获取配置
     */
    public function getValues(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getValue($key);
        }
        return $result;
    }

    /**
     * 批量设置配置
     */
    public function setValues(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->setValue($key, (string) $value);
        }
        return $this;
    }

    /**
     * 获取所有配置
     */
    public function getAllConfig(): array
    {
        $rows = $this->clearQuery()
            ->select()
            ->fetchArray();

        $config = self::DEFAULTS;
        foreach ($rows as $row) {
            $config[$row[self::schema_fields_KEY]] = $row[self::schema_fields_VALUE];
        }

        return $config;
    }

    /**
     * 删除配置项
     */
    public function deleteKey(string $key): bool
    {
        $this->clearQuery()
            ->where(self::schema_fields_KEY, $key)
            ->find()
            ->fetch();

        if ($this->getData(self::schema_fields_ID)) {
            $this->delete()->fetch();
            return true;
        }

        return false;
    }

    // =============== 快捷方法 ===============

    /**
     * 自动解析是否开启
     */
    public function isAutoResolveEnabled(): bool
    {
        return $this->getValue(self::CONFIG_AUTO_RESOLVE_ENABLED) === '1';
    }

    /**
     * 获取自动解析记录类型
     */
    public function getAutoResolveRecordType(): string
    {
        return $this->getValue(self::CONFIG_AUTO_RESOLVE_RECORD_TYPE, 'A');
    }

    /**
     * 获取自动解析子域列表
     */
    public function getAutoResolveSubdomains(): array
    {
        $value = $this->getValue(self::CONFIG_AUTO_RESOLVE_SUBDOMAINS, '@,www');
        $parts = \array_values(\array_filter(\array_map('trim', \explode(',', $value))));
        $parts = \array_values(\array_filter(
            $parts,
            static fn(string $s): bool => $s !== '' && !DnsSiteHostRules::isUnderscoreTechnicalDnsHost($s)
        ));

        return $parts !== [] ? $parts : ['@', 'www'];
    }

    /**
     * 获取服务器公网 IPv4
     */
    public function getServerPublicIp(): string
    {
        return $this->getValue(self::CONFIG_SERVER_PUBLIC_IP);
    }

    /**
     * 获取服务器公网 IPv6
     */
    public function getServerPublicIpv6(): string
    {
        return $this->getValue(self::CONFIG_SERVER_PUBLIC_IPV6);
    }

    /**
     * 证书自动申请是否开启
     */
    public function isCertAutoRequestEnabled(): bool
    {
        return $this->getValue(self::CONFIG_CERT_AUTO_REQUEST) === '1';
    }
}

