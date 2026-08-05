<?php

declare(strict_types=1);

namespace Weline\Cart\Observer;

use Weline\Cart\Service\CartService;
use Weline\Cart\Service\CartScopeResolver;
use Weline\Cart\Service\CartV2Service;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;

/**
 * 登录后同 Scope 自动合车（TEST-P2E-02）。
 */
class LoginMergeGuestCart implements ObserverInterface
{
    private readonly CartScopeResolver $scopeResolver;

    public function __construct(
        private readonly CartService $cartService,
        ?CartScopeResolver $scopeResolver = null,
    ) {
        $this->scopeResolver = $scopeResolver ?? new CartScopeResolver();
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!$data instanceof DataObject) {
            return;
        }
        $user = $data->getData('user');
        if (!is_object($user) || !method_exists($user, 'getId')) {
            return;
        }
        $customerId = (int)$user->getId();
        if ($customerId <= 0) {
            return;
        }

        $guestToken = $this->resolveGuestToken($data->getData('request'));
        if ($guestToken === '') {
            return;
        }

        $v2 = $this->cartService->cartV2();
        if (!$v2 instanceof CartV2Service) {
            return;
        }

        try {
            $scope = $this->resolveScope($data->getData('request'));
            $v2->mergeGuestIntoCustomer($scope, $guestToken, $customerId);
            Cookie::set(CartV2Service::GUEST_TOKEN_COOKIE, '', -3600, [
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning('Cart V2 login mergeGuest failed: ' . $e->getMessage());
            }
        }
    }

    private function resolveGuestToken(mixed $request): string
    {
        $fromCookie = trim((string)Cookie::get(CartV2Service::GUEST_TOKEN_COOKIE));
        if ($fromCookie !== '') {
            return $fromCookie;
        }
        if ($request instanceof Request) {
            $fromParam = trim((string)($request->getParam('guest_token')
                ?? $request->getPost('guest_token')
                ?? ''));
            if ($fromParam !== '') {
                return $fromParam;
            }
        }

        return '';
    }

    private function resolveScope(mixed $request): \Weline\Framework\Runtime\ScopeIdentity
    {
        if ($request instanceof Request) {
            $params = [];
            foreach ([
                'website_id',
                'website_code',
                'store_code',
                'channel_code',
                'store_mode',
            ] as $key) {
                $value = $request->getParam($key);
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }
                $params[$key] = $value;
            }
            return $this->scopeResolver->fromParams($params);
        }

        return $this->scopeResolver->fromParams([]);
    }
}
