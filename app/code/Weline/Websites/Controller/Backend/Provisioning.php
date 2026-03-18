<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Websites\Model\ProvisioningOrder;

/** Websites 一站式配置订单后台 */
#[AclAttribute('Weline_Websites::provisioning', '配置订单', 'mdi-format-list-bulleted', '一站式配置订单', 'Weline_Websites::website_service')]
class Provisioning extends BackendController
{
    public function __construct(
        private readonly ProvisioningOrder $orderModel
    ) {
    }

    #[AclAttribute('Weline_Websites::provisioning_list', '查看配置订单', 'mdi-view-list', '查看配置订单列表')]
    public function index(): string
    {
        $page = (int) $this->request->getGet('page', 1);
        $pageSize = 20;
        $search = trim((string) $this->request->getGet('search', ''));
        $status = trim((string) $this->request->getGet('status', ''));

        $query = $this->orderModel->reset()->order(ProvisioningOrder::schema_fields_ORDER_ID, 'DESC');

        if ($search !== '') {
            $query->where(ProvisioningOrder::schema_fields_DOMAIN, 'like', "%{$search}%");
        }
        if ($status !== '') {
            $query->where(ProvisioningOrder::schema_fields_STATUS, $status);
        }

        $total = $query->total();
        $items = $query->page($page, $pageSize)->select()->fetchArray();
        $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 1;

        $this->assign('items', $items);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('pageSize', $pageSize);
        $this->assign('totalPages', $totalPages);
        $this->assign('search', $search);
        $this->assign('status', $status);
        $this->assign('statusList', [
            '' => __('全部'),
            ProvisioningOrder::STATUS_PENDING => __('待处理'),
            ProvisioningOrder::STATUS_STEP_PURCHASE => __('购买中'),
            ProvisioningOrder::STATUS_STEP_DNS => __('DNS'),
            ProvisioningOrder::STATUS_STEP_CDN => __('CDN'),
            ProvisioningOrder::STATUS_STEP_SSL => __('SSL'),
            ProvisioningOrder::STATUS_COMPLETED => __('已完成'),
            ProvisioningOrder::STATUS_FAILED => __('失败'),
        ]);

        return $this->fetch();
    }
}
