<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentBrowserReturnDispatcher;

/**
 * Legacy PayPal-named frontend routes. Prefer payment/frontend/callback/return.
 */
final class PayPal extends FrontendController
{
    public function return()
    {
        $params = $this->request->getParams();
        if (!\is_array($params)) {
            $params = [];
        }
        /** @var PaymentBrowserReturnDispatcher $dispatcher */
        $dispatcher = ObjectManager::getInstance(PaymentBrowserReturnDispatcher::class);
        $dispatched = $dispatcher->dispatch($params);
        $path = (string) ($dispatched['redirect_path'] ?? 'payment/frontend/checkout/return');
        $redirectParams = \is_array($dispatched['redirect_params'] ?? null)
            ? $dispatched['redirect_params']
            : [];

        if (!empty($dispatched['absolute']) && (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))) {
            return $this->redirect($path);
        }

        return $this->redirect($this->getUrl($path, $redirectParams));
    }

    public function cancel()
    {
        $this->getMessageManager()->addWarning(__('PayPal payment cancelled.'));

        return $this->redirect($this->getUrl('payment/frontend/checkout/return'));
    }

    /**
     * @deprecated Use payment/frontend/callback/return
     */
    public function oauthCallback()
    {
        $params = $this->request->getParams();
        if (!\is_array($params)) {
            $params = [];
        }

        return $this->redirect($this->getUrl('payment/frontend/callback/return', $params));
    }
}
