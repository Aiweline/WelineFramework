<?php
declare(strict_types=1);

/**
 * 域名商适配器聚合接口
 *
 * 继承四个核心能力接口，所有域名商适配器实现此接口即拥有完整能力。
 * 第三方模块可通过 extends 机制扩展新的域名商适配器。
 *
 * 能力拆分：
 * - ProviderInfoInterface    — 供应商元数据（code、name、config、连接测试）
 * - DomainPurchaseInterface  — 域名购买（check、purchase、list、detail）
 * - DnsManagementInterface   — DNS 记录管理（CRUD、批量）
 * - DnsCdnZoneRecordsProviderInterface — 按账户拉取 zone 权威解析（绑定 DNS/CDN 账户必用）
 * - NameserverSwitchInterface — NS 切换（updateNameservers、getProviderNameservers）
 *
 * 可选能力（按需额外 implements）：
 * - AccountInfoInterface     — 账户余额、TLD 价格、联系人模板
 * - ZoneManagementInterface  — Zone 管理（Cloudflare 类需先创建 Zone）
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Api;

interface DomainRegistrarInterface extends
    ProviderInfoInterface,
    DomainPurchaseInterface,
    DnsManagementInterface,
    DnsCdnZoneRecordsProviderInterface,
    NameserverSwitchInterface
{
}
