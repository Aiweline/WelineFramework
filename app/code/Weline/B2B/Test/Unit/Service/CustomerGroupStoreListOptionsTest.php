<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Service\CustomerGroupStore;

require_once dirname(__DIR__) . '/bootstrap.php';

final class CustomerGroupStoreListOptionsTest extends TestCase
{
    public function testListActiveOptionsOmitsDisabledAndSorts(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->put(new CustomerGroup('g-z', 0, 'zebra', CustomerGroup::STATUS_ACTIVE));
        $store->put(new CustomerGroup('g-a', 0, 'alpha', CustomerGroup::STATUS_ACTIVE));
        $store->put(new CustomerGroup('g-off', 0, 'off', CustomerGroup::STATUS_DISABLED));

        $options = $store->listActiveOptions();

        self::assertCount(2, $options);
        self::assertSame(['g-a', 'g-z'], array_column($options, 'value'));
        self::assertSame('alpha', $options[0]['label']);
        self::assertSame(0, $options[0]['website_id']);
        self::assertSame('alpha', $options[0]['code']);
        self::assertSame('alpha', $options[0]['name']);
    }
}
