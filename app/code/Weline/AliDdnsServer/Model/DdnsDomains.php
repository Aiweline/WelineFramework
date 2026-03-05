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
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'DDNS域名')]
class DdnsDomains extends Model
{
    public const schema_table = 'ddns_domains';
    public const schema_primary_key = 'ddns_domain_id';
    #[Col(type: 'integer', length: 11, nullable: false, primaryKey: true, autoIncrement: true, comment: 'DDNS域名ID')]
    public const schema_fields_ID        = 'ddns_domain_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '域名')]
    public const schema_fields_DOMAIN    = 'domain';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '主机名')]
    public const schema_fields_HOST_NAME = 'host_name';
    #[Col(type: 'integer', length: 11, nullable: true, comment: '更新间隔')]
    public const schema_fields_INTERVAL  = 'interval';
    #[Col(type: 'integer', length: 1, nullable: true, comment: '是否为IPv4地址解析 1为true 0为false')]
    public const schema_fields_ipv4_flag = 'ipv4_flag';
    #[Col(type: 'integer', length: 1, nullable: true, comment: '是否为IPv6地址解析 1为true 0为false')]
    public const schema_fields_ipv6_flag = 'ipv6_flag';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'IPv4地址')]
    public const schema_fields_ipv4      = 'ipv4';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'IPv6地址')]
    public const schema_fields_ipv6      = 'ipv6';
    #[Col(type: 'integer', length: 1, nullable: true, comment: '是否启用 1为true 0为false')]
    public const schema_fields_enable    = 'enable';
    
    public function getDdnsDomainID(): int
    {
        return $this->getData(self::schema_fields_ID);
    }
    public function getDomain(): string
    {
        return $this->getData(self::schema_fields_DOMAIN);
    }
    public function getHostName(): string
    {
        return $this->getData(self::schema_fields_HOST_NAME);
    }
    public function getInterval(): int
    {
        return $this->getData(self::schema_fields_INTERVAL);
    }
    public function getIpv4Flag(): bool
    {
        return (bool)$this->getData(self::schema_fields_ipv4_flag);
    }
    public function getIpv6Flag(): bool
    {
        return (bool)$this->getData(self::schema_fields_ipv6_flag);
    }
    public function getIpv4(): string
    {
        return $this->getData(self::schema_fields_ipv4);
    }
    public function getIpv6(): string
    {
        return $this->getData(self::schema_fields_ipv6);
    }
    public function setDdnsDomainID(int $ddns_domain_id): self
    {
        return $this->setData(self::schema_fields_ID, $ddns_domain_id);
    }
    public function setDomain(string $domain): self
    {
        return $this->setData(self::schema_fields_DOMAIN, $domain);
    }
    public function setHostName(string $host_name): self
    {
        return $this->setData(self::schema_fields_HOST_NAME, $host_name);
    }
    public function setInterval(int $interval): self
    {
        return $this->setData(self::schema_fields_INTERVAL, $interval);
    }
    public function setIpv4Flag(bool $ipv4_flag): self
    {
        return $this->setData(self::schema_fields_ipv4_flag, $ipv4_flag);
    }
    public function setIpv6Flag(bool $ipv6_flag): self
    {
        return $this->setData(self::schema_fields_ipv6_flag, $ipv6_flag);
    }
    public function setIpv4(string $ipv4): self
    {
        return $this->setData(self::schema_fields_ipv4, $ipv4);
    }
    public function setIpv6(string $ipv6): self
    {
        return $this->setData(self::schema_fields_ipv6, $ipv6);
    }
}
