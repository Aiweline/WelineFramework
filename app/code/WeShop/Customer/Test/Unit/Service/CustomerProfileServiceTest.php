<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Service\CustomerProfileService;
use Weline\Customer\Model\Customer as AuthCustomer;

class CustomerProfileServiceTest extends TestCase
{
    public function testGetOrCreateByAuthUserBackfillsLegacyPasswordColumnFromAuthUser(): void
    {
        $profile = new class extends CustomerProfile {
            public array $savedData = [];

            public function __construct()
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function where($field, $value = null, $operator = '='): static
            {
                return $this;
            }

            public function find(): static
            {
                return $this;
            }

            public function fetch(): static
            {
                return $this;
            }

            public function clearData($field = null): static
            {
                return $this;
            }

            public function save(string|array|bool|\Weline\Framework\Database\AbstractModel $data = [], string|array $sequence = ''): int|bool
            {
                $this->savedData = $this->getData();
                $this->setData(static::schema_fields_ID, 42);
                return 42;
            }
        };

        $authUser = new class extends AuthCustomer {
            public function __construct()
            {
            }

            public function getId(mixed $default = 0): mixed
            {
                return 42;
            }

            public function getEmail(): string
            {
                return 'ada@example.com';
            }

            public function getUsername(): ?string
            {
                return 'ada@example.com';
            }

            public function getPassword(): string
            {
                return '$2y$10$legacy-hash-value';
            }
        };

        $service = new CustomerProfileService($profile);
        $result = $service->getOrCreateByAuthUser($authUser, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);

        $this->assertSame(42, $result->getId());
        $this->assertSame('$2y$10$legacy-hash-value', $profile->savedData[CustomerProfile::schema_fields_PASSWORD] ?? null);
        $this->assertSame('ada@example.com', $profile->savedData[CustomerProfile::schema_fields_EMAIL] ?? null);
        $this->assertSame('Ada', $profile->savedData[CustomerProfile::schema_fields_FIRST_NAME] ?? null);
        $this->assertArrayHasKey(CustomerProfile::schema_fields_CREATED_AT, $profile->savedData);
        $this->assertNotEmpty($profile->savedData[CustomerProfile::schema_fields_CREATED_AT] ?? '');
    }
}
