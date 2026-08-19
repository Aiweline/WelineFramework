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

    public function testStoredUsernameIsUsedAsTheEditableDisplayName(): void
    {
        $customer = new class extends Customer {
            public function __construct()
            {
            }
        };

        $customer->setData(Customer::schema_fields_email, 'dealer@example.test');
        $customer->setData(Customer::schema_fields_username, 'FCDC Dealer');

        $this->assertSame('FCDC Dealer', $customer->getUsername());
        $this->assertSame('dealer@example.test', $customer->getEmail());
    }

    public function testChangingDisplayUsernameDoesNotOverwriteAnExistingEmail(): void
    {
        $customer = new class extends Customer {
            public function __construct()
            {
            }
        };

        $customer->setEmail('dealer@example.test');
        $customer->setUsername('FCDC Dealer');

        $this->assertSame('FCDC Dealer', $customer->getData(Customer::schema_fields_username));
        $this->assertSame('dealer@example.test', $customer->getEmail());
    }
}
