<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Runtime;

use Weline\Framework\DateTime\ScheduleWindowUtcMigrationContributionInterface;
use Weline\Theme\Model\ThemeLayoutSchedule;

final class ScheduleWindowUtcMigrationContribution implements ScheduleWindowUtcMigrationContributionInterface
{
    public function targets(string $timezone): array
    {
        return [
            [
                'id' => 'theme_layout_schedule',
                'model' => ThemeLayoutSchedule::class,
                'columns' => ['starts_at', 'ends_at'],
                'extra' => ['timezone' => $timezone],
            ],
        ];
    }
}
