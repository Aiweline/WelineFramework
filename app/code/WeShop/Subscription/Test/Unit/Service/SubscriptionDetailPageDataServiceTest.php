<?php

declare(strict_types=1);

namespace WeShop\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\AbstractModel;
use WeShop\Subscription\Model\Subscription;
use WeShop\Subscription\Model\SubscriptionHistory;
use WeShop\Subscription\Model\SubscriptionPlan;
use WeShop\Subscription\Service\SubscriptionDetailPageDataService;
use WeShop\Subscription\Service\SubscriptionService;

class SubscriptionDetailPageDataServiceTest extends TestCase
{
    public function testBuildLoadsOwnedSubscriptionPlanAndHistory(): void
    {
        $subscription = new class extends Subscription {
            public int $loadedId = 0;
            public array $data = [
                Subscription::schema_fields_ID => 21,
                Subscription::schema_fields_CUSTOMER_ID => 9,
                Subscription::schema_fields_PLAN_ID => 5,
                Subscription::schema_fields_STATUS => Subscription::STATUS_ACTIVE,
                Subscription::schema_fields_PRICE => '39.90',
                Subscription::schema_fields_CURRENCY => 'USD',
                Subscription::schema_fields_BILLING_INTERVAL => 1,
                Subscription::schema_fields_BILLING_CYCLE => SubscriptionPlan::CYCLE_MONTH,
                Subscription::schema_fields_CURRENT_PERIOD_START => '2026-03-01 00:00:00',
                Subscription::schema_fields_CURRENT_PERIOD_END => '2026-03-31 23:59:59',
                Subscription::schema_fields_NEXT_BILLING_AT => '2026-04-01 00:00:00',
                Subscription::schema_fields_TRIAL_ENDS_AT => '',
                Subscription::schema_fields_RENEWAL_COUNT => 3,
                Subscription::schema_fields_CREATED_AT => '2026-01-01 00:00:00',
            ];

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function load(int|string $field_or_pk_value, $value = null): AbstractModel
            {
                $this->loadedId = (int) $field_or_pk_value;

                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return $this->loadedId;
            }

            public function getData(string $key = '', $index = null): mixed
            {
                return $key === '' ? $this->data : ($this->data[$key] ?? null);
            }
        };

        $plan = new class extends SubscriptionPlan {
            public int $loadedId = 0;
            public array $data = [
                SubscriptionPlan::schema_fields_ID => 5,
                SubscriptionPlan::schema_fields_NAME => 'Monthly Box',
                SubscriptionPlan::schema_fields_DESCRIPTION => 'Curated monthly goods',
                SubscriptionPlan::schema_fields_TRIAL_DAYS => 7,
            ];

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function load(int|string $field_or_pk_value, $value = null): AbstractModel
            {
                $this->loadedId = (int) $field_or_pk_value;

                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return $this->loadedId;
            }

            public function getData(string $key = '', $index = null): mixed
            {
                return $key === '' ? $this->data : ($this->data[$key] ?? null);
            }
        };

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects($this->once())
            ->method('getSubscriptionHistory')
            ->with(21, 1, 20)
            ->willReturn([
                'items' => [[
                    SubscriptionHistory::schema_fields_ID => 1,
                    SubscriptionHistory::schema_fields_ACTION => SubscriptionHistory::ACTION_CREATED,
                    SubscriptionHistory::schema_fields_AMOUNT => '39.90',
                    SubscriptionHistory::schema_fields_ORDER_ID => 1001,
                    SubscriptionHistory::schema_fields_NOTE => 'Created',
                    SubscriptionHistory::schema_fields_OPERATOR => 'system',
                    SubscriptionHistory::schema_fields_CREATED_AT => '2026-01-01 00:00:00',
                ]],
                'total' => 1,
            ]);

        $service = new SubscriptionDetailPageDataService($subscription, $plan, $subscriptionService);
        $result = $service->build(9, 21);

        $this->assertSame(21, $result['subscription']['subscription_id']);
        $this->assertSame('Monthly Box', $result['plan']['name']);
        $this->assertSame(1, $result['history_total']);
        $this->assertCount(1, $result['history']);
    }
}
