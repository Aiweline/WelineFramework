<?php

declare(strict_types=1);

namespace WeShop\Customer\Api;

use WeShop\Customer\Model\Customer as CustomerProfile;
use Weline\Customer\Model\Customer as AuthCustomer;

interface CustomerContextInterface
{
    public function getAuthUser(): ?AuthCustomer;

    public function getProfile(): ?CustomerProfile;

    public function getUserId(): ?int;

    public function getEmail(): ?string;
}
