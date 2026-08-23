<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_Checkout::order_manage', '订单管理', 'cart', '订单管理', 'Weline_Backend::order_group')]
class Order extends BackendController
{
    /**
     * 订单列表
     * 
     * @return string
     */
    #[Acl('Weline_Checkout::order_list', '查看订单列表', 'list', '查看订单列表')]
    public function index(): string
    {
        // Compatibility route only: Weline_Order owns the canonical order workspace.
        return $this->redirect('weline_order/backend/order/index');
    }

    /**
     * 订单详情
     * 
     * @return string
     */
    #[Acl('Weline_Checkout::order_view', '查看订单详情', 'eye', '查看订单详情')]
    public function view(): string
    {
        // Compatibility route only: preserve legacy links while delegating ownership.
        $legacyOrderId = (int)$this->request->getParam('order_id');
        if ($legacyOrderId > 0) {
            return $this->redirect('weline_order/backend/order/view?id=' . $legacyOrderId);
        }
        $legacyOrderNumber = trim((string)$this->request->getParam('order_number', ''));
        return $this->redirect('weline_order/backend/order/index'
            . ($legacyOrderNumber !== '' ? '?order_number=' . rawurlencode($legacyOrderNumber) : ''));
    }

    /**
     * 更新订单状态（AJAX）
     * 
     * @return string
     */
    #[Acl('Weline_Checkout::order_update_status', '更新订单状态', 'edit', '更新订单状态')]
    public function updateStatus(): string
    {
        // Mutations are owned by Weline_Order; this legacy writer is deliberately retired.
        $this->request->getResponse()->setCode(410);
        return $this->fetchJson([
            'success' => false,
            'message' => __('该兼容入口已停用，请从订单管理更新状态')
        ]);
    }
}
