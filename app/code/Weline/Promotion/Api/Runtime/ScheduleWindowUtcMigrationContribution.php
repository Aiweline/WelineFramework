<?php

declare(strict_types=1);

namespace Weline\Promotion\Api\Runtime;

use Weline\Framework\DateTime\ScheduleWindowUtcMigrationContributionInterface;
use Weline\Promotion\Model\PromotionActivityTheme;

final class ScheduleWindowUtcMigrationContribution implements ScheduleWindowUtcMigrationContributionInterface
{
    public function targets(string $timezone): array
    {
        return [
            [
                'id' => 'promotion_activity_theme',
                'model' => PromotionActivityTheme::class,
                'columns' => ['starts_at', 'ends_at'],
            ],
        ];
    }
}
