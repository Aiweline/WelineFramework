<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

/**
 * Legacy PayPal-named routes — thin aliases to shell Connect entry.
 * Prefer payment/backend/connect/*?method_code=paypal.
 */
#[Acl('Weline_Payment::payment_method', '支付方式管理', 'edit', '支付方式管理', 'Weline_Backend::payment_group')]
final class PayPal extends BackendController
{
    #[Acl('Weline_Payment::paypal_authorize', 'PayPal 一键授权', 'circle', 'PayPal 沙箱/正式 OAuth 授权（兼容别名）')]
    public function authorize(): string
    {
        return $this->redirect($this->aliasUrl('authorize'));
    }

    #[Acl('Weline_Payment::paypal_callback', 'PayPal OAuth 回调', 'login', 'PayPal OAuth 回调（已废弃，请用统一 callback/return）')]
    public function callback(): string
    {
        $params = $this->request->getParams();
        if (!\is_array($params)) {
            $params = [];
        }

        return $this->redirect($this->getUrl('payment/frontend/callback/return', $params));
    }

    #[Acl('Weline_Payment::paypal_test', 'PayPal 连接测试', 'link', 'PayPal 连接测试（兼容别名）')]
    public function test(): string
    {
        return $this->redirect($this->aliasUrl('test'));
    }

    #[Acl('Weline_Payment::paypal_revoke', '撤销 PayPal 授权', 'link', '撤销 PayPal OAuth 授权（兼容别名）')]
    public function revoke(): string
    {
        return $this->redirect($this->aliasUrl('revoke'));
    }

    private function aliasUrl(string $action): string
    {
        $query = [
            'method_code' => 'paypal',
            'environment' => trim((string) $this->request->getGet('environment', 'sandbox')),
        ];
        foreach (['scope', 'website_code', 'store_code', 'channel_code', 'locale'] as $key) {
            $value = trim((string) $this->request->getGet($key, ''));
            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        return $this->getUrl('payment/backend/connect/' . $action, $query);
    }
}
