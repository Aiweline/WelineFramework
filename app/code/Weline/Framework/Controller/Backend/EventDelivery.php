<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl(
    'Weline_Framework::event_delivery_view',
    '异步事件死信运维',
    'mdi mdi-email-alert-outline',
    '查看当前网站范围内的异步事件 Delivery',
    'Weline_Framework::system_service_group',
    accessMode: Acl::ACCESS_MODE_READ,
)]
final class EventDelivery extends BackendController
{
    public function getIndex(): string
    {
        $this->assign('title', __('异步事件死信运维'));
        $this->assign('current_website_id', w_env_website_id());
        return $this->fetch();
    }
}
