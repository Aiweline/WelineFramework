<?php

return [
    "name" => 'Weline_Cart',
    "version" => '1.2.0',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
        'Weline_Customer' => '*',
    ],
    "provides" => [
        \Weline\Cart\Api\CheckoutCartSnapshotInterface::class
            => \Weline\Cart\Service\CheckoutCartSnapshotService::class,
    ],
];
