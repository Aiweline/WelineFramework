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
    private AuthenticatedSessionInterface $session;

    public function __construct()
    {
        $this->session = SessionFactory::getInstance()->createFrontendSession();
    }

    public function login(AuthenticableInterface $user): void
    {
        $this->session->login($user);
    }

    public function logout(): void
    {
        $this->session->logout();
    }

    public function isLoggedIn(): bool
    {
        return $this->session->isLoggedIn();
    }

    public function getUser(): ?AuthenticableInterface
    {
        return $this->session->getUser();
    }

    public function getCustomer(): ?AuthenticableInterface
    {
        return $this->session->getCustomer();
    }

    public function getUserId(): int|string|null
    {
        return $this->session->getUserId();
    }

    public function getUsername(): ?string
    {
        return $this->session->getUsername();
    }

    public function getSession(): SessionInterface
    {
        return $this->session->getSession();
    }

    public function get(string $key): mixed
    {
        return $this->session->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    public function delete(string $key): void
    {
        $this->session->delete($key);
    }

    public function getId(): string
    {
        return $this->session->getId();
    }

    public function start(?string $sessionId = null): void
    {
        $this->session->start($sessionId);
    }

    public function destroy(): void
    {
        $this->session->destroy();
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->session->regenerate($deleteOldSession);
    }

    public function isStarted(): bool
    {
        return $this->session->isStarted();
    }

    public function getArea(): string
    {
        return $this->session->getArea();
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
        return $this->session->getSession()->getId();
    }

    public function getData(string $name = ''): mixed
    {
        if ($name === '') {
            return $this->session->getSession()->all();
        }

        return $this->session->getSession()->get($name);
    }

    public function setData(string $name, mixed $value): static
    {
        $this->session->set($name, $value);
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
}
