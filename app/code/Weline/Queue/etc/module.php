<?php

return [
    "name" => 'Weline_Queue',
    "version" => '1.2.2',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Cron' => '*',
        'Weline_Eav' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Framework\Api\Event\AsyncEventTransportInterface::class
            => \Weline\Queue\Service\AsyncEvent\QueueAsyncEventTransport::class,
    ],
];
