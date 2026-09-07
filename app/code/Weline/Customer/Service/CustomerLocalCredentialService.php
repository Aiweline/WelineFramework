<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;

/**
 * Shared storefront credential lookup/verify used by form login and social bind.
 */
final class CustomerLocalCredentialService
{
    public function findByLogin(string $usernameOrEmail): ?Customer
    {
        $login = trim($usernameOrEmail);
        if ($login === '') {
            return null;
        }

        /** @var Customer $user */
        $user = ObjectManager::getInstance(Customer::class, [], false);

        $email = strtolower($login);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user->where(Customer::schema_fields_email, $email)->find()->fetch();
            if ($user->getId()) {
                return $user;
            }
        }

        $user->reset();
        $user->where(Customer::schema_fields_username, $login)->find()->fetch();

        return $user->getId() ? $user : null;
    }

    /**
     * @throws \RuntimeException when credentials are invalid
     */
    public function authenticate(string $usernameOrEmail, string $password): Customer
    {
        $login = trim($usernameOrEmail);
        $password = (string) $password;
        if ($login === '' || $password === '') {
            throw new \InvalidArgumentException((string) __('请输入用户名/邮箱和密码'));
        }

        $user = $this->findByLogin($login);
        if ($user === null) {
            throw new \RuntimeException((string) __('本站没有该用户名/邮箱的账户。请填写本站已注册账户；若尚未注册，请使用「新建账户」。'));
        }

        if ($user->getAttemptTimes() > 5) {
            throw new \RuntimeException((string) __('登录尝试次数过多，请稍后再试.'));
        }

        if (!password_verify($password, $user->getPassword())) {
            $user->addAttemptTimes()->save();
            throw new \RuntimeException((string) __('密码错误，请填写本站账户密码（不是 Google 密码）。'));
        }

        return $user;
    }
}
