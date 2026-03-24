<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Model\Customer;

class CustomerIdentityFallbackTest extends TestCase
{
    public function testUsernameFallsBackToEmailAndAuthUsernameUsesEmail(): void
    {
        $customer = new class extends Customer {
            public function __construct()
            {
            }
        };

        $customer->setData('email', 'ada@example.com');

        $this->assertSame('ada@example.com', $customer->getUsername());
        $this->assertSame('ada@example.com', $customer->getAuthUsername());
    }

    public function testSettingUsernameAlsoSeedsEmailWhenValueLooksLikeEmail(): void
    {
        $customer = new class extends Customer {
            public function __construct()
            {
            }
        };

        $customer->setUsername('ada@example.com');

        $this->assertSame('ada@example.com', $customer->getEmail());
    }
}
