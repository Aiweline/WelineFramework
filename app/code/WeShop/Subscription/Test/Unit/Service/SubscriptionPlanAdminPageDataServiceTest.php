<?php

declare(strict_types=1);

namespace WeShop\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\AbstractModel;
use WeShop\Subscription\Model\SubscriptionPlan;
use WeShop\Subscription\Service\SubscriptionPlanAdminPageDataService;

class SubscriptionPlanAdminPageDataServiceTest extends TestCase
{
    public function testGetListDataReturnsPlanRows(): void
    {
        $plan = new class extends SubscriptionPlan {
            public array $rows = [['plan_id' => 3]];
            public string $paginationData = '<nav>page-1</nav>';
            public int $totalCount = 5;

            public function clear(bool $with_query = true): static
            {
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

        $service = new SubscriptionPlanAdminPageDataService($plan);
        $result = $service->getListData(1, 20);

        $this->assertSame(5, $result['total']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('<nav>page-1</nav>', $result['pagination']);
        $this->assertArrayHasKey(SubscriptionPlan::CYCLE_MONTH, $result['billing_cycles']);
    }

    public function testGetEditDataReturnsLoadedPlanWhenIdExists(): void
    {
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

            public function getId(mixed $default = 0)
            {
                return $this->loadedId;
            }
        };

        $service = new SubscriptionPlanAdminPageDataService($plan);
        $result = $service->getEditData(8);

        $this->assertSame($plan, $result['plan']);
        $this->assertArrayHasKey(SubscriptionPlan::CYCLE_YEAR, $result['billing_cycles']);
        $this->assertSame(8, $plan->loadedId);
    }
}
