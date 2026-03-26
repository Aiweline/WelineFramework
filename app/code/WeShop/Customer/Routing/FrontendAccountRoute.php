<?php

declare(strict_types=1);

namespace WeShop\Customer\Routing;

final class FrontendAccountRoute
{
    public const ACCOUNT = 'weshop_customer/frontend/account';
    public const LOGIN = self::ACCOUNT . '/login';
    public const REGISTER = self::ACCOUNT . '/register';
    public const FORGOT_PASSWORD = self::ACCOUNT . '/forgot-password';
    public const CHALLENGE = self::ACCOUNT . '/challenge';
    public const LOGOUT = self::ACCOUNT . '/logout';

    private function __construct()
    {
    }
}
