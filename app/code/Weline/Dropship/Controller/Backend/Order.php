<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Model\DropshipFulfillment;
use Weline\Dropship\Model\DropshipPushOutbox;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Dropship::commerce:dropship:orders', '履约订单', 'list', '货源履约订单', 'Weline_Dropship::commerce:dropship:group')]
class Order extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:orders_index', '查看履约订单', 'list', '查看履约订单')]
    public function index(): string
    {
        /** @var DropshipFulfillment $f */
        $f = ObjectManager::getInstance(DropshipFulfillment::class);
        /** @var DropshipPushOutbox $o */
        $o = ObjectManager::getInstance(DropshipPushOutbox::class);
        $this->assign('page_title', __('履约订单'));
        $this->assign('fulfillments', $f->clear()->order(DropshipFulfillment::schema_fields_ID, 'DESC')->limit(100)->select()->fetchArray() ?: []);
        $this->assign('outbox', $o->clear()->order(DropshipPushOutbox::schema_fields_ID, 'DESC')->limit(100)->select()->fetchArray() ?: []);

        return $this->fetch();
    }
}
