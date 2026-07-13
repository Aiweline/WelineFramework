<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Integration\Customer;

use Weline\Customer\Api\Account\AccountI18nModuleProviderInterface;

final class AccountI18nModuleProvider implements AccountI18nModuleProviderInterface
{
    public function modules(): array
    {
        return ['Weline_TwoFactorAuth'];
    }
}
