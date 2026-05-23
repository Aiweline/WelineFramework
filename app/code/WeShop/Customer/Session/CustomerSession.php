<?php

declare(strict_types=1);

namespace WeShop\Customer\Session;

use WeShop\Customer\Model\Customer as CustomerProfile;
use Weline\Customer\Model\Customer as AuthCustomer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

class CustomerSession implements AuthenticatedSessionInterface
{
    private ?AuthenticatedSessionInterface $frontendSession = null;

    public function __construct(
        private readonly ?SessionFactory $sessionFactory = null
    ) {
    }

    public function login(AuthenticableInterface $user): void
    {
        $this->getFrontendSession()->login($user);
    }

    public function logout(): void
    {
        $this->getFrontendSession()->logout();
    }

    public function isLoggedIn(): bool
    {
        return $this->getFrontendSession()->isLoggedIn();
    }

    public function getUser(): ?AuthenticableInterface
    {
        return $this->getFrontendSession()->getUser();
    }

    public function getCustomer(): ?AuthenticableInterface
    {
        return $this->getFrontendSession()->getCustomer();
    }

    public function getUserId(): int|string|null
    {
        return $this->getFrontendSession()->getUserId();
    }

    public function getUsername(): ?string
    {
        return $this->getFrontendSession()->getUsername();
    }

    public function getSession(): SessionInterface
    {
        return $this->getFrontendSession()->getSession();
    }

    public function get(string $key): mixed
    {
        return $this->getFrontendSession()->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->getFrontendSession()->set($key, $value);
    }

    public function delete(string $key): void
    {
        $this->getFrontendSession()->delete($key);
    }

    public function getId(): string
    {
        return $this->getFrontendSession()->getId();
    }

    public function start(?string $sessionId = null): void
    {
        $this->getFrontendSession()->start($sessionId);
    }

    public function destroy(): void
    {
        $this->getFrontendSession()->destroy();
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->getFrontendSession()->regenerate($deleteOldSession);
    }

    public function isStarted(): bool
    {
        return $this->getFrontendSession()->isStarted();
    }

    public function getArea(): string
    {
        return $this->getFrontendSession()->getArea();
    }

    public function isLogin(): bool
    {
        return $this->isLoggedIn();
    }

    public function getLoginUser(string $model = ''): ?AuthenticableInterface
    {
        return $this->getUser();
    }

    public function getLoginUsername(): ?string
    {
        return $this->getUsername();
    }

    public function getLoginUserId(): int|string|null
    {
        return $this->getUserId();
    }

    public function getSessionId(): string
    {
        return $this->getFrontendSession()->getSession()->getId();
    }

    public function getData(string $name = ''): mixed
    {
        $frontendSession = $this->getFrontendSession();
        if (\method_exists($frontendSession, 'getData')) {
            return $frontendSession->getData($name);
        }

        if ($name === '') {
            return $frontendSession->getSession()->all();
        }

        return $frontendSession->getSession()->get($name);
    }

    public function setData(string $name, mixed $value): static
    {
        $this->getFrontendSession()->set($name, $value);
        return $this;
    }

    public function setCustomer(AuthenticableInterface $customer): static
    {
        if ($customer instanceof AuthCustomer) {
            $this->login($customer);
            return $this;
        }

        if ($customer instanceof CustomerProfile) {
            $userId = (int) ($customer->getData(CustomerProfile::schema_fields_USER_ID) ?? 0);
            if ($userId <= 0) {
                throw new \InvalidArgumentException((string) __('Customer profile is not linked to an auth user.'));
            }

            /** @var AuthCustomer $authCustomer */
            $authCustomer = ObjectManager::getInstance(AuthCustomer::class);
            $authCustomer->load($userId);
            if (!$authCustomer->getId()) {
                throw new \RuntimeException((string) __('Unable to locate the auth user for this customer profile.'));
            }

            $this->login($authCustomer);
            return $this;
        }

        $this->login($customer);
        return $this;
    }

    private function getFrontendSession(): AuthenticatedSessionInterface
    {
        if ($this->frontendSession instanceof AuthenticatedSessionInterface) {
            return $this->frontendSession;
        }

        $this->frontendSession = ($this->sessionFactory ?? SessionFactory::getInstance())->createFrontendSession();
        return $this->frontendSession;
    }
}
