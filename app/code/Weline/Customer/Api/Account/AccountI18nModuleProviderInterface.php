<?php

declare(strict_types=1);

namespace Weline\Customer\Api\Account;

/** Optional account-page translation module contribution. */
interface AccountI18nModuleProviderInterface
{
    /** @return list<string> */
    public function modules(): array;
}
