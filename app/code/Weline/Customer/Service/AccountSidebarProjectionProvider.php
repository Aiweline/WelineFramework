<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Customer\Api\View\AccountSidebarProjection;
use Weline\Customer\Api\View\AccountSidebarProjectionProviderInterface;
use Weline\Customer\Model\Customer;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionFactory;

final class AccountSidebarProjectionProvider implements AccountSidebarProjectionProviderInterface
{
    public function __construct(
        private ?CustomerAccountFacadeInterface $accounts = null,
    ) {
    }

    public function forSections(string ...$supportedSections): ?AccountSidebarProjection
    {
        $requestedSection = AccountSidebarContentGate::requestedSection();
        if ($requestedSection === '' || !in_array($requestedSection, $supportedSections, true)) {
            return null;
        }

        $customerId = $this->currentCustomerId();

        return new AccountSidebarProjection(
            $requestedSection,
            RequestContext::getWelineWebsiteId(),
            $customerId !== null && $customerId > 0 ? $customerId : null,
        );
    }

    private function currentCustomerId(): ?int
    {
        if ($this->accounts !== null) {
            $customerId = $this->accounts->current()?->getId();
            return $customerId !== null && $customerId > 0 ? $customerId : null;
        }

        try {
            $user = SessionFactory::getInstance()->createFrontendSession()->getUser();
            return $user instanceof Customer && $user->getId() ? (int) $user->getId() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
