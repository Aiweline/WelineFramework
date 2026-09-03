<?php

declare(strict_types=1);

namespace Weline\Cart\Controller;

use Weline\Cart\Service\CartService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;

/**
 * Legacy form POST entry. Prefer Weline.Api.resource('cart').add(...).
 */
class Add extends FrontendController
{
    public function index(): string
    {
        if (\strtoupper((string) $this->request->getMethod()) !== 'POST') {
            $this->getMessageManager()->addError(__('请求方式不允许。'));
            return $this->redirect('/cart');
        }

        try {
            $data = $this->cartService()->addFromParams([
                'provider_code' => (string) $this->request->getPost('provider_code', 'product'),
                'global_offer_uuid' => (string) $this->request->getPost(
                    'global_offer_uuid',
                    $this->request->getPost('offer_uuid', ''),
                ),
                'legacy_product_id' => (int) $this->request->getPost('product_id', 0),
                'qty' => (int) $this->request->getPost('qty', 1),
                'selection' => $this->request->getPost('selection', $this->request->getPost('selected_options', [])),
                'guest_token' => (string) $this->request->getPost('guest_token', ''),
            ]);

            $success = (bool) ($data['success'] ?? false);
            $message = (string) ($data['message'] ?? '');

            if ($success) {
                $this->getMessageManager()->addSuccess($message !== '' ? $message : __('已加入购物车。'));
            } else {
                $this->getMessageManager()->addError($message !== '' ? $message : __('加入购物车失败。'));
            }
        } catch (ResponseTerminateException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('加入购物车失败：%{1}', $exception->getMessage()));
        }

        return $this->redirect($this->resolveRedirectUrl());
    }

    private function cartService(): CartService
    {
        return ObjectManager::getInstance(CartService::class);
    }

    private function resolveRedirectUrl(): string
    {
        $redirect = trim((string) $this->request->getPost('redirect', ''));
        if ($redirect !== '' && str_starts_with($redirect, '/')) {
            return $redirect;
        }

        return '/cart';
    }
}
