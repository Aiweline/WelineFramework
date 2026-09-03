<?php

declare(strict_types=1);

namespace Weline\Customer\Observer;

use Weline\Customer\Model\Customer;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;

/**
 * Guests converted from checkout must set a real password before using account pages.
 */
final class MustSetPasswordGate implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $path = strtolower(trim((string)$request->getUrlPath(), '/'));
            if ($path === '' ) {
                $path = strtolower(trim((string)($request->getServer('REQUEST_URI') ?? ''), '/'));
                if (($q = strpos($path, '?')) !== false) {
                    $path = substr($path, 0, $q);
                }
            }
            if ($this->isExemptPath($path)) {
                return;
            }

            $session = ObjectManager::getInstance(SessionFactory::class)->createFrontendSession();
            $user = $session->getUser();
            if (!$session->isLoggedIn() || !$user instanceof Customer || !$user->getId()) {
                return;
            }
            // Reload flag from DB in case the session user is stale.
            $fresh = ObjectManager::getInstance(Customer::class)
                ->reset()
                ->load($user->getId());
            if (!$fresh instanceof Customer || !$fresh->getId() || !$fresh->mustSetPassword()) {
                return;
            }

            $request->getResponse()->redirect('/customer/account/set-password');
        } catch (\Throwable) {
            // Fail open for unrelated front controllers.
        }
    }

    private function isExemptPath(string $path): bool
    {
        $exemptions = [
            'customer/account/set-password',
            'customer/account/login',
            'customer/account/logout',
            'customer/account/register',
            'customer/account/forgot-password',
            'customer/account/challenge',
            'checkout/success',
            'api/framework/query-bin',
        ];
        foreach ($exemptions as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
