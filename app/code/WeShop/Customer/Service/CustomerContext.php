<?php

declare(strict_types=1);

namespace WeShop\Customer\Service;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Session\CustomerSession;
use Weline\Customer\Model\Customer as AuthCustomer;
use Weline\Framework\Http\Request;

class CustomerContext implements CustomerContextInterface
{
    private ?bool $shouldReadFrontendSession = null;

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CustomerProfileService $customerProfileService,
        private readonly Request $request
    ) {
    }

    public function getAuthUser(): ?AuthCustomer
    {
        if ($this->shouldReadFrontendSession()) {
            $user = $this->customerSession->getUser();
            if ($user instanceof AuthCustomer) {
                return $user;
            }
        }

        $requestUser = $this->request->getData('weshop_auth_user');
        return $requestUser instanceof AuthCustomer ? $requestUser : null;
    }

    public function getProfile(): ?CustomerProfile
    {
        $authUser = $this->getAuthUser();
        if (!$authUser) {
            return null;
        }

        return $this->customerProfileService->getByUserId((int) $authUser->getId());
    }

    public function getUserId(): ?int
    {
        if ($this->shouldReadFrontendSession()) {
            $sessionUserId = $this->customerSession->getUserId();
            if ($sessionUserId !== null && $sessionUserId !== '') {
                return (int) $sessionUserId;
            }
        }

        $authUser = $this->getAuthUser();
        return $authUser ? (int) $authUser->getId() : null;
    }

    public function getEmail(): ?string
    {
        $authUser = $this->getAuthUser();
        return $authUser ? (string) $authUser->getEmail() : null;
    }

    private function shouldReadFrontendSession(): bool
    {
        if ($this->shouldReadFrontendSession !== null) {
            return $this->shouldReadFrontendSession;
        }

        if (!$this->hasWelineSessionCookie()) {
            return $this->shouldReadFrontendSession = false;
        }

        try {
            $coordinator = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Router\FullPageCacheCoordinator::class
            );
            if ($coordinator instanceof \Weline\Framework\Router\FullPageCacheCoordinator
                && !$coordinator->hasLoggedInFrontendSessionForCache()
            ) {
                return $this->shouldReadFrontendSession = false;
            }
        } catch (\Throwable) {
            return $this->shouldReadFrontendSession = true;
        }

        return $this->shouldReadFrontendSession = true;
    }

    private function hasWelineSessionCookie(): bool
    {
        if (isset($_COOKIE['WELINE_SESSID']) && \trim((string)$_COOKIE['WELINE_SESSID']) !== '') {
            return true;
        }

        $cookieHeader = '';
        if (\class_exists(\Weline\Framework\Env\WelineEnv::class, false)) {
            $cookieHeader = (string)(
                \Weline\Framework\Env\WelineEnv::server('HTTP_COOKIE', '')
                ?: \Weline\Framework\Env\WelineEnv::get('server.http_cookie', '')
            );
        }

        return $cookieHeader !== '' && \stripos($cookieHeader, 'WELINE_SESSID=') !== false;
    }
}
