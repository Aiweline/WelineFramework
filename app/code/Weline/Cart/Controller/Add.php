<?php

declare(strict_types=1);

namespace Weline\Cart\Controller;

use Weline\Cart\Service\CartService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;

class Add extends FrontendController
{
    private const SOURCE_CONTEXT_STRING_LIMITS = [
        'source_app' => 80,
        'source_module' => 100,
        'business_module' => 100,
        'business_code' => 100,
        'business_name' => 160,
        'product_type' => 80,
    ];

    public function index(): string
    {
        if (\strtoupper((string) $this->request->getMethod()) !== 'POST') {
            $this->getMessageManager()->addError(__('请求方式不允许。'));
            return $this->redirect('/cart');
        }

        try {
            $data = $this->cartService()->add([
                'product_id' => (int) $this->request->getPost('product_id', 0),
                'qty' => (int) $this->request->getPost('qty', 1),
                'selected_options' => $this->request->getPost('selected_options', []),
            ] + $this->sourceContextFromPost());

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

    /**
     * @return array<string, string>
     */
    private function sourceContextFromPost(): array
    {
        $context = [];
        foreach (self::SOURCE_CONTEXT_STRING_LIMITS as $key => $limit) {
            $rawValue = $this->request->getPost($key, '');
            if (!\is_scalar($rawValue)) {
                continue;
            }
            $value = $this->limitString((string) $rawValue, $limit);
            if ($value !== '') {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    private function resolveRedirectUrl(): string
    {
        $redirect = \trim((string) $this->request->getPost('redirect', ''));
        if ($redirect === '' || \str_contains($redirect, 'weshop/cart')) {
            return '/cart';
        }

        return $redirect;
    }

    private function limitString(string $value, int $length): string
    {
        $value = \trim($value);
        if (\strlen($value) <= $length) {
            return $value;
        }

        return \substr($value, 0, $length);
    }
}
