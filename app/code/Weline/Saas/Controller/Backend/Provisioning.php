<?php

declare(strict_types=1);

namespace Weline\Saas\Controller\Backend;

use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Websites\Controller\Backend\Provisioning as WebsitesProvisioning;

#[AclAttribute('Weline_Websites::provisioning', '配置订单', 'mdi-format-list-bulleted', '一站式配置订单', 'Weline_Websites::website_service')]
class Provisioning extends WebsitesProvisioning
{
    #[AclAttribute('Weline_Websites::provisioning_list', '查看配置订单', 'mdi-view-list', '查看配置订单列表')]
    public function index(): string
    {
        return parent::index();
    }
}
