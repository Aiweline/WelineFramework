<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Account;

use WeShop\Frontend\Controller\BaseController;

/**
 * 账户订单列表片段 HTTP 入口已废弃，请使用 Weline.Api.resource('order').accountListFragment()。
 */
class Orders extends BaseController
{
    public function getListFragment(): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(410);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');

        return $this->fetchJson([
            'success' => false,
            'message' => (string) __('该接口已停用，请通过 Weline.Api 调用 order.accountListFragment。'),
            'deprecated' => true,
            'browser_direct' => false,
            'replacement' => "Weline.Api.resource('order').accountListFragment() /* returns JSON, not HTML */",
        ]);
    }
}
