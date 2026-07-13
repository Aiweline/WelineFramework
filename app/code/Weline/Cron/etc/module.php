<?php

return [
    "name" => 'Weline_Cron',
    "version" => '1.0.1',
    "requires" => [
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Cron\Api\Process\ProcessControlInterface::class => \Weline\Cron\Service\ProcessControl::class,
        \Weline\Cron\Api\Task\CronTaskCatalogInterface::class => \Weline\Cron\Api\Task\CronTaskCatalog::class,
    ],
];
