<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

/**
 * Websites 模块内使用的配置订单模型；与 {@see \Weline\Saas\Model\ProvisioningOrder} 共用表 saas_provisioning_order。
 * 此前仅存在 Saas 侧实现，此处子类供 DI 与模块内类型解析。
 */
class ProvisioningOrder extends \Weline\Saas\Model\ProvisioningOrder
{
}
