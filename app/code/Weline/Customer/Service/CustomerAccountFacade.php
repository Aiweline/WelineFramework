<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Customer\Api\Auth\CustomerIdentity;
use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;

final class CustomerAccountFacade implements CustomerAccountFacadeInterface
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
    ) {
    }

    public function current(): ?CustomerIdentity
    {
        $session = SessionFactory::getInstance()->createFrontendSession();
        if (!$session->isLoggedIn()) {
            return null;
        }

        $user = $session->getUser();
        if ($user instanceof Customer && $user->getId()) {
            return $this->map($user);
        }

        return $this->find((int) ($session->getUserId() ?? 0));
    }

    public function find(int $customerId): ?CustomerIdentity
    {
        if ($customerId <= 0) {
            return null;
        }

        $customer = $this->newCustomerModel()->load($customerId);
        return $customer->getId() ? $this->map($customer) : null;
    }

    public function findByEmail(string $email): ?CustomerIdentity
    {
        $customer = $this->accounts->findByEmail($email);
        return $customer ? $this->map($customer) : null;
    }

    public function register(string $email, string $password, array $profileData = []): CustomerIdentity
    {
        $result = $this->accounts->register($email, $password, $profileData);
        $customer = $result['customer'] ?? null;
        if (!$customer instanceof Customer || !$customer->getId()) {
            throw new \RuntimeException((string) __('客户注册未返回有效账号'));
        }

        return $this->map($customer);
    }

    public function updateAvatar(CustomerIdentity $identity, string $avatar): CustomerIdentity
    {
        $customer = $this->newCustomerModel()->load($identity->getId());
        if (!$customer->getId()) {
            throw new \RuntimeException((string) __('客户账号不存在'));
        }

        $customer->setAvatar($avatar)->save();
        return $this->map($customer);
    }

    public function login(CustomerIdentity $identity): void
    {
        $customer = $this->newCustomerModel()->load($identity->getId());
        if (!$customer->getId()) {
            throw new \RuntimeException((string) __('客户账号不存在'));
        }

        $this->accounts->loginCustomer($customer);
    }

    public function issueRememberToken(CustomerIdentity $identity, int $rememberDuration): void
    {
        if ($rememberDuration <= 0) {
            return;
        }

        $token = CustomerToken::generateToken();
        $expireTime = time() + $rememberDuration;

        /** @var CustomerToken $userToken */
        $userToken = ObjectManager::getInstance(CustomerToken::class);
        $userToken->reset()
            ->where(CustomerToken::schema_fields_user_id, $identity->getId())
            ->where(CustomerToken::schema_fields_type, 'remember_me')
            ->delete();

        $userToken->reset()
            ->setUserId($identity->getId())
            ->setToken($token)
            ->setType('remember_me')
            ->setTokenExpireTime($expireTime)
            ->save();

        Cookie::set('w_ut', $token, $rememberDuration, ['path' => '/']);
    }

    private function newCustomerModel(): Customer
    {
        return ObjectManager::getInstance(Customer::class, [], false);
    }

    private function map(Customer $customer): CustomerIdentity
    {
        return new CustomerIdentity(
            (int) $customer->getId(),
            (string) ($customer->getUsername() ?? ''),
            $customer->getEmail(),
            (string) ($customer->getAvatar() ?? ''),
            $customer->isSandboxAccount(),
        );
    }
}
