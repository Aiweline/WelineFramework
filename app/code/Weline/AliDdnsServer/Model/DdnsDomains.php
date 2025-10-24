<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Weline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/5/10 22:40:03
 */

namespace Weline\AliDdnsServer\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class DdnsDomains extends \Weline\Framework\Database\Model
{
    public const fields_ID        = 'ddns_domain_id';
    public const fields_DOMAIN    = 'domain';
    public const fields_HOST_NAME = 'host_name';
    public const fields_INTERVAL  = 'interval';
    public const fields_ipv4_flag = 'ipv4_flag';
    public const fields_ipv6_flag = 'ipv6_flag';
    public const fields_ipv4      = 'ipv4';
    public const fields_ipv6      = 'ipv6';
    public const fields_enable    = 'enable';

    public function getDdnsDomainID(): int
    {
        return $this->getData(self::fields_ID);
    }

    public function getDomain(): string
    {
        return $this->getData(self::fields_DOMAIN);
    }

    public function getHostName(): string
    {
        return $this->getData(self::fields_HOST_NAME);
    }

    public function getInterval(): int
    {
        return $this->getData(self::fields_INTERVAL);
    }

    public function getIpv4Flag(): bool
    {
        return (bool)$this->getData(self::fields_ipv4_flag);
    }

    public function getIpv6Flag(): bool
    {
        return (bool)$this->getData(self::fields_ipv6_flag);
    }

    public function getIpv4(): string
    {
        return $this->getData(self::fields_ipv4);
    }

    public function getIpv6(): string
    {
        return $this->getData(self::fields_ipv6);
    }

    public function setDdnsDomainID(int $ddns_domain_id): self
    {
        return $this->setData(self::fields_ID, $ddns_domain_id);
    }

    public function setDomain(string $domain): self
    {
        return $this->setData(self::fields_DOMAIN, $domain);
    }

    public function setHostName(string $host_name): self
    {
        return $this->setData(self::fields_HOST_NAME, $host_name);
    }

    public function setInterval(int $interval): self
    {
        return $this->setData(self::fields_INTERVAL, $interval);
    }

    public function setIpv4Flag(bool $ipv4_flag): self
    {
        return $this->setData(self::fields_ipv4_flag, $ipv4_flag);
    }

    public function setIpv6Flag(bool $ipv6_flag): self
    {
        return $this->setData(self::fields_ipv6_flag, $ipv6_flag);
    }

    public function setIpv4(string $ipv4): self
    {
        return $this->setData(self::fields_ipv4, $ipv4);
    }

    public function setIpv6(string $ipv6): self
    {
        return $this->setData(self::fields_ipv6, $ipv6);
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
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                  ->addColumn(
                      self::fields_ID,
                      TableInterface::column_type_INTEGER,
                      0, 'primary key auto_increment', 'ddns域名ID')
                  ->addColumn(
                      self::fields_DOMAIN,
                      TableInterface::column_type_VARCHAR,
                      255, 'not null', '域名')
                  ->addColumn(
                      self::fields_HOST_NAME,
                      TableInterface::column_type_VARCHAR,
                      255, 'not null', '主机名')
                  ->addColumn(
                      self::fields_ipv4,
                      TableInterface::column_type_VARCHAR,
                      50, 'null', 'IPv4地址')
                  ->addColumn(
                      self::fields_ipv6,
                      TableInterface::column_type_VARCHAR,
                      50, 'null', 'IPv6地址')
                  ->addColumn(
                      self::fields_INTERVAL,
                      TableInterface::column_type_INTEGER,
                      50, 'null', '过期时间间隔（单位：秒）')
                  ->addColumn(
                      self::fields_ipv4_flag,
                      TableInterface::column_type_INTEGER,
                      1, 'null', '是否为IPv4地址解析 1为true 0为false')
                  ->addColumn(
                      self::fields_ipv6_flag,
                      TableInterface::column_type_INTEGER,
                      1, 'null', '是否为IPv6地址解析 1为true 0为false')
                  ->addColumn(
                      self::fields_enable,
                      TableInterface::column_type_INTEGER,
                      1, 'null', '是否启用 1为true 0为false')
                  ->create();
        }
    }
}