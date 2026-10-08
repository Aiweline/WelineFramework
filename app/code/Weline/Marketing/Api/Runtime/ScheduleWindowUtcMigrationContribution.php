<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Runtime;

use Weline\Framework\DateTime\ScheduleWindowUtcMigrationContributionInterface;
use Weline\Marketing\Model\Campaign\Campaign;
use Weline\Marketing\Model\Coupon\Coupon;
use Weline\Marketing\Model\Rule\Rule;

final class ScheduleWindowUtcMigrationContribution implements ScheduleWindowUtcMigrationContributionInterface
{
    public function targets(string $timezone): array
    {
        return [
            [
                'id' => 'marketing_campaign',
                'model' => Campaign::class,
                'columns' => ['start_date', 'end_date'],
            ],
            [
                'id' => 'marketing_rule',
                'model' => Rule::class,
                'columns' => ['start_date', 'end_date'],
            ],
            [
                'id' => 'marketing_coupon',
                'model' => Coupon::class,
                'columns' => ['start_date', 'end_date'],
            ],
        ];
    }
}
