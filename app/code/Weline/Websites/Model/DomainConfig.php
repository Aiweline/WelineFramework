<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名管理配置模型
 *
 * 存储域名管理相关的配置项（自动解析开关、服务器 IP 等）
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class DomainConfig extends Model
{
    public const fields_ID = 'config_id';
    public const fields_KEY = 'config_key';
    public const fields_VALUE = 'config_value';
    public const fields_UPDATED_AT = 'updated_at';

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
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
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

        $setup->createTable('域名管理配置表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '配置ID')
            ->addColumn(self::fields_KEY, TableInterface::column_type_VARCHAR, 100, 'not null', '配置键')
            ->addColumn(self::fields_VALUE, TableInterface::column_type_TEXT, 0, '', '配置值')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, '', '更新时间')
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_config_key', self::fields_KEY)
            ->create();
    }

    // =============== 业务方法 ===============

    /**
     * 获取配置值
     */
    public function getValue(string $key, ?string $default = null): string
    {
        $this->clearQuery()
            ->where(self::fields_KEY, $key)
            ->find()
            ->fetch();

        if ($this->getData(self::fields_ID)) {
            return (string) $this->getData(self::fields_VALUE);
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
            ->where(self::fields_KEY, $key)
            ->find()
            ->fetch();

        $model->setData(self::fields_KEY, $key);
        $model->setData(self::fields_VALUE, $value);
        $model->setData(self::fields_UPDATED_AT, \date('Y-m-d H:i:s'));
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
            $config[$row[self::fields_KEY]] = $row[self::fields_VALUE];
        }

        return $config;
    }

    /**
     * 删除配置项
     */
    public function deleteKey(string $key): bool
    {
        $this->clearQuery()
            ->where(self::fields_KEY, $key)
            ->find()
            ->fetch();

        if ($this->getData(self::fields_ID)) {
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
        return \array_filter(\array_map('trim', \explode(',', $value)));
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
