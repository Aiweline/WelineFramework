<?php

declare(strict_types=1);

namespace WeShop\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\AbstractModel;
use WeShop\Subscription\Model\Subscription;
use WeShop\Subscription\Model\SubscriptionPlan;
use WeShop\Subscription\Service\SubscriptionAdminPageDataService;
use WeShop\Subscription\Service\SubscriptionService;

class SubscriptionAdminPageDataServiceTest extends TestCase
{
    public function testGetListDataReturnsStatisticsAndNormalizedFilters(): void
    {
        $subscription = new class extends Subscription {
            public array $rows = [['subscription_id' => 11, 'status' => Subscription::STATUS_ACTIVE]];
            public string $paginationData = '<nav>page-2</nav>';
            public int $totalCount = 18;
            public array $whereCalls = [];

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                $this->whereCalls[] = [$field, $value, $condition];

                return $this;
            }

            public function order(string $field = '', string $sort = 'DESC'): static
            {
                return $this;
            }

            public function pagination(int $page = 0, int $pageSize = 0, array $params = [], int $max_limit = 1000, int $total = 0): AbstractModel|static
            {
                return $this;
            }

            public function select(string $fields = ''): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                return $this->rows;
            }

            public function getPagination(string $pagination_style = 'pagination-rounded', string $url_path = ''): string
            {
                return $this->paginationData;
            }

            public function getTotalCount(): int
            {
                return $this->totalCount;
            }
        };

        $plan = new class extends SubscriptionPlan {};

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects($this->once())->method('getStatistics')->willReturn(['total' => 18, 'active' => 9]);

        $service = new SubscriptionAdminPageDataService($subscription, $plan, $subscriptionService);
        $result = $service->getListData(2, 30, ['status' => Subscription::STATUS_ACTIVE]);

        $this->assertSame(18, $result['total']);
        $this->assertSame(Subscription::STATUS_ACTIVE, $result['current_status']);
        $this->assertSame('<nav>page-2</nav>', $result['pagination']);
        $this->assertSame(['total' => 18, 'active' => 9], $result['statistics']);
        $this->assertCount(1, $result['items']);
        $this->assertSame([
            [Subscription::schema_fields_STATUS, Subscription::STATUS_ACTIVE, '='],
        ], $subscription->whereCalls);
    }

    public function testGetDetailDataLoadsSubscriptionPlanAndHistory(): void
    {
        $subscription = new class extends Subscription {
            public int $loadedId = 0;

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
                if ($key === Subscription::schema_fields_PLAN_ID) {
                    return 4;
                }

                return null;
            }
        };

        $plan = new class extends SubscriptionPlan {
            public int $loadedId = 0;

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function load(int|string $field_or_pk_value, $value = null): AbstractModel
            {
                $this->loadedId = (int) $field_or_pk_value;

                return $this;
            }
        };

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects($this->once())
            ->method('getSubscriptionHistory')
            ->with(15, 1, 50)
            ->willReturn(['items' => [['history_id' => 1]], 'total' => 1]);

        $service = new SubscriptionAdminPageDataService($subscription, $plan, $subscriptionService);
        $result = $service->getDetailData(15);

        $this->assertSame($subscription, $result['subscription']);
        $this->assertSame($plan, $result['plan']);
        $this->assertSame(1, $result['history']['total']);
        $this->assertSame(4, $plan->loadedId);
    }
}
